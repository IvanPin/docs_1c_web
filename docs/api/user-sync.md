## API интеграции пользователя Сайт ↔ 1С

Цель: единый метод синхронизации данных пользователя между сайтом (1C‑Битрикс) и 1С.

- **Один метод**: принимает массив `users[]` и в зависимости от состава полей выполняет поиск/регистрацию/обновление.
- **Идентификатор пользователя**: номер телефона в формате `+7XXXXXXXXXX`.
- **Единый формат ответа**: `{ "status": 1|0, "error": string|null, "result": object|null }`.
- **Безопасность**: заголовок `ApiKey`.
- **Лояльность**: поддерживаются код карты, сумма до следующей скидки, накопленные суммы и процент скидки.


### Эндпоинт

- **URL (1С)**: `POST https://<1c-host>/api/v1/user/sync`
- **URL (сайт)**: `POST https://<site-host>/api/v1/user/sync`
- **Метод**: `POST`
- **Заголовки**:
  - `Content-Type: application/json; charset=utf-8`
  - `ApiKey: <секретный_ключ>` (например, `b248000c29f889b211ee5072583e1e2e` — тестовый)

### Поведение

#### Сайт → 1С

1. **Триггеры**:
   - `OnAfterUserAdd` — полная карточка пользователя.
   - `OnAfterUserAuthorize` — только `phone` (запрашиваем актуальные данные из 1С после логина).
   - Агент `\App\Integration\UserSyncService::syncAllUsersAgent()` — пакетная синхронизация по расписанию (по 100 записей за прогон).
2. **Авторизация/регистрация/редактирование**: в зависимости от наполнения объекта из Битрикс формируется payload (см. описание полей). 1С выполняет upsert и возвращает актуальную карточку.

#### 1С → Сайт

- 1С отправляет массив `users[]` на REST-эндпоинт сайта для обновления профилей.
- Сайт выполняет upsert по телефону (или иному ключу, договорённость ниже) и возвращает актуальные данные с сайта для каждого пользователя.

Правило выбора операции (актуально для обеих сторон):
- Если в `users[i]` передан только `phone` → режим «поиск/регистрация по телефону».
- Если в `users[i]` переданы дополнительные поля → режим «upsert по телефону».

### Конфигурация

| Параметр | Где задаётся | Назначение |
| --- | --- | --- |
| `USER_SYNC_API_KEY` | `Option::set('main', 'user_sync_api_key', '...')` или переменная окружения | Секрет для входящих запросов 1С → сайт |
| `user_sync.endpoint` | `bitrix/.settings.php` | URL REST-метода в 1С для исходящих запросов |
| `user_sync.api_key` | `bitrix/.settings.php` | Секрет для запросов сайт → 1С |
| `user_sync.timeout` | `bitrix/.settings.php` | Таймаут HTTP-клиента (сек.) |

### Схемы JSON

Запрос:

```json
{
  "users": [
    {
      "phone": "+79991234567",
      "email": "user@example.com",
      "lastName": "Иванов",
      "firstName": "Иван",
      "middleName": "Иванович",
      "birthday": "1990-01-31",
      "gender": "M",
      "externalId": "123",
      "loyaltyCard": "CARD001122",
      "loyaltySumToNextDiscount": "1500",
      "loyaltyTotalAmount": "25000.50",
      "loyaltyDiscountPercent": "7.5"
    }
  ]
}
```

Поля объектов `users[]`:
- `phone` (string, required): формат `+7` и 11 цифр. Ключ.
- `email` (string, optional): валидный e-mail.
- `lastName` (string, optional): фамилия.
- `firstName` (string, optional): имя.
- `middleName` (string, optional): отчество.
- `birthday` (string, optional): `YYYY-MM-DD`.
- `gender` (string, optional): `M` | `F` | `U` (неизвестно).
- `externalId` (string, optional): внутренний ID сайта (например, Bitrix `ID`). Не ключ в 1С, передаётся как справочная ссылка.
- `loyaltyCard` (string, optional): код карты лояльности.
- `loyaltySumToNextDiscount` (number|string, optional): сумма до следующей скидки.
- `loyaltyTotalAmount` (number|string, optional): накопленная сумма покупок.
- `loyaltyDiscountPercent` (number|string, optional): текущий процент скидки.
- Массив `users` может содержать от 1 до N записей; текущие сценарии используют одну запись.

> Для обратной совместимости сервер принимает те же значения внутри объекта `loyalty`, но в ответе всегда возвращаются только плоские поля.

Ответ (единый формат):

```json
{
  "status": 1,
  "error": null,
  "result": {
      "users": [
      {
          "oneCId": "8d4e5a86-...",
          "phone": "+79991234567",
          "email": "user@example.com",
          "lastName": "Иванов",
          "firstName": "Иван",
          "middleName": "Иванович",
          "birthday": "1990-01-31",
          "gender": "M",
          "loyaltyCard": "CARD001122",
          "loyaltySumToNextDiscount": 1500,
          "loyaltyTotalAmount": 25000.5,
          "loyaltyDiscountPercent": 7.5
        }
      ]
  }
}
```

При ошибке:

```json
{
  "status": 0,
  "error": "Пользователь не найден и авто‑регистрация отключена",
  "result": null
}
```

Замечания:
- Поле `result` — объект. При успехе содержит массив `users[]`. `status=0` → `result=null`.
- Ответ содержит только плоские поля лояльности; вложенный объект `loyalty` больше не используется. Детальная история и крупные выборки карт запрашиваются отдельными методами с пагинацией.

### Примеры

1) Сайт → 1С. Авторизация/регистрация (только телефон):

Запрос:

```http
POST /api/v1/user/sync HTTP/1.1
Host: <1c-host>
Content-Type: application/json
ApiKey: b248000c29f889b211ee5072583e1e2e

{
  "users": [ { "phone": "+79991234567" } ]
}
```

Успешный ответ:

```json
{
  "status": 1,
  "error": null,
  "result": {
    "users": [
      {
        "oneCId": "8d4e5a86-...",
        "phone": "+79991234567",
        "email": "user@example.com",
        "lastName": "Иванов",
        "firstName": "Иван",
        "middleName": "Иванович",
        "birthday": "1990-01-31",
        "gender": "M",
        "loyaltyCard": "CARD001122",
        "loyaltySumToNextDiscount": 1500,
        "loyaltyTotalAmount": 25000.5,
        "loyaltyDiscountPercent": 7.5
      }
    ]
  }
}
```

2) Сайт → 1С. Редактирование профиля (полный объект):

Запрос:

```http
POST /api/v1/user/sync HTTP/1.1
Host: <1c-host>
Content-Type: application/json
ApiKey: b248000c29f889b211ee5072583e1e2e

{
  "users": [
    {
      "phone": "+79991234567",
      "email": "new@example.com",
      "lastName": "Иванов",
      "firstName": "Иван",
      "birthday": "1991-02-15",
      "gender": "M",
      "externalId": "123"
    }
  ]
}
```

Ответ:

```json
{
  "status": 1,
  "error": null,
  "result": {
    "users": [
      {
        "oneCId": "8d4e5a86-...",
        "phone": "+79991234567",
        "email": "new@example.com",
        "lastName": "Иванов",
        "firstName": "Иван",
        "birthday": "1991-02-15",
        "gender": "M",
        "loyaltyCard": "CARD001122"
      }
    ]
  }
}
```

3) 1С → Сайт. Обновление профиля на сайте:

Запрос:

```http
POST /api/v1/user/sync HTTP/1.1
Host: <site-host>
Content-Type: application/json
ApiKey: <секретный_ключ_сайта>

{
  "users": [
    {
      "phone": "+79991234567",
      "email": "sync@example.com",
      "lastName": "Иванов",
      "firstName": "Иван",
      "middleName": "Иванович",
      "birthday": "1990-01-31",
      "gender": "M",
      "externalId": "123"
    }
  ]
}
```

Ответ сайта:

```json
{
  "status": 1,
  "error": null,
  "result": {
    "users": [
      {
        "externalId": "123",
        "phone": "+79991234567",
        "email": "sync@example.com",
        "lastName": "Иванов",
        "firstName": "Иван",
        "middleName": "Иванович",
        "birthday": "1990-01-31",
        "gender": "M",
        "loyaltyCard": "CARD001122",
        "loyaltySumToNextDiscount": 1500,
        "loyaltyTotalAmount": 25000.5,
        "loyaltyDiscountPercent": 7.5
      }
    ]
  }
}
```

### Валидация и нормализация

- Телефон нормализуется к `+7XXXXXXXXXX`. Допустимы входы вида `8XXXXXXXXXX` — конвертировать в `+7`.
- При отсутствии пользователя и включённой авто‑регистрации — создавать запись.
- `birthday` должен соответствовать `YYYY-MM-DD`; иначе игнорировать поле и вернуть предупреждение в `error` или детальный массив ошибок в будущем `warnings`.
- Поля лояльности (`loyaltySumToNextDiscount`, `loyaltyTotalAmount`, `loyaltyDiscountPercent`) принимаются как числа или строки, приводятся к числовому формату. Пустые значения игнорируются.

### Ошибки и коды

Единый признак ошибки — `status=0` и текст в `error`.

Рекомендуемые тексты:
- `Неверный ApiKey`
- `Неверный формат телефона`
- `Внутренняя ошибка сервиса 1С`
- `Пользователь не найден и авто‑регистрация отключена`

HTTP-коды (рекомендуется):
- `200` — бизнес‑успех (`status` может быть `1` или `0`, т.к. бизнес‑ошибка возвращается в теле);
- `401` — ошибка авторизации (неверный `ApiKey`);
- `429` — лимит запросов превышен;
- `500` — техническая ошибка сервера 1С.

### Безопасность

- Обязателен заголовок `ApiKey`. Ключ вращать и хранить в секретах окружения.
- Для входящих запросов 1С → сайт используется `Option::get('main', 'user_sync_api_key')` или переменная окружения `USER_SYNC_API_KEY`.
- Для исходящих запросов сайт → 1С секрет задаётся в `bitrix/.settings.php` (`user_sync.api_key`).


### Лояльность и большие данные

- В `result.users[]` возвращаются плоские поля `loyaltyCard`, `loyaltySumToNextDiscount`, `loyaltyTotalAmount`, `loyaltyDiscountPercent`.
- Агрегированные значения во вложенных объектах не используются.
- История покупок, начислений/списаний баллов и крупные выборки карт — отдельные методы с пагинацией.


### Соответствие полям Битрикс

- `LOGIN` ↔ `phone`
- `EMAIL` ↔ `email`
- `LAST_NAME` ↔ `lastName`
- `NAME` ↔ `firstName`
- `SECOND_NAME` ↔ `middleName`
- `PERSONAL_BIRTHDAY` ↔ `birthday`
- `PERSONAL_GENDER` ↔ `gender`
- `ID` (Bitrix) ↔ `externalId` (передаётся информативно)
- `UF_KODKARTY` ↔ `loyaltyCard`
- `UF_SUM_TO_NEXT_DISCOUNT` (или `UF_LOYALTY_SUM_TO_NEXT`, `UF_SUM_TO_NEXT`) ↔ `loyaltySumToNextDiscount`
- `UF_LOYALTY_TOTAL_AMOUNT` (или `UF_SUM_ACCUMULATED`, `UF_SUMMA_NAKOPLENIY`) ↔ `loyaltyTotalAmount`
- `UF_LOYALTY_DISCOUNT_PERCENT` (или `UF_DISCOUNT_PERCENT`, `UF_PROCENT_SKIDKI`) ↔ `loyaltyDiscountPercent`
