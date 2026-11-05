## Обмен сертификатами (Сайт ↔ 1С)

Оба метода принимают только POST с JSON-телом. GET возвращает 405. Версия ответа: `X-Certificate-Contract-Version: 2.0.0`.

1С получает оплаченные заявки сайта, выпускает сертификаты и передаёт их реквизиты обратно. Номер, номинал, остаток и срок действия хранятся в 1С. Письмо получателю отправляет сайт.

## Получение заявок на выпуск (Сайт → 1С)

- **Метод**: `POST`
- **Путь**: `/api/1c/certificates/list.php`
- **Заголовки**: `Content-Type: application/json; charset=utf-8`, `X-Api-Key: <ключ клиента>`.
- **Scope**: `certificates:read`.

### Запрос

```json
{
  "afterId": 0,
  "limit": 50
}
```

| Поле | Тип | Описание |
| --- | --- | --- |
| `afterId` | integer, optional | Курсор страницы, по умолчанию 0. |
| `limit` | integer, optional | Размер страницы: 1–100, по умолчанию 50. |

### Ответ

```json
{
  "status": 1,
  "code": null,
  "error": null,
  "result": {
    "items": [
      {
        "requestId": 1101,
        "orderId": 101,
        "amount": 5000,
        "currency": "RUB",
        "paid": true,
        "buyerName": "Покупатель",
        "buyerEmail": "buyer@example.com",
        "recipientName": "Получатель",
        "recipientEmail": "recipient@example.com"
      }
    ],
    "nextAfterId": 1101
  }
}
```

- `requestId` — ID заявки, используется для страниц; `orderId` — ID заказа сайта, ключ выпуска.
- `amount` — номинал в целых рублях; `currency` — `RUB`.
- В выдаче только оплаченные неотменённые заказы без подтверждённого выпуска.
- Следующую страницу запрашивать с `afterId = nextAfterId` до `nextAfterId: null`. Новый цикл начинать с 0.
- Чтение не удаляет заявки. После принятого подтверждения заявка исчезает из выдачи.

## Подтверждение выпуска (1С → сайт)

- **Метод**: `POST`
- **Путь**: `/api/1c/certificates/index.php`
- **Заголовки**: `Content-Type: application/json; charset=utf-8`, `X-Api-Key: <ключ клиента>`.
- **Scope**: `certificates:issue`.

### Запрос

```json
{
  "orderId": 101,
  "number": "PL-000101",
  "externalId": "certificate-guid-from-1c",
  "amount": 5000,
  "currency": "RUB",
  "expiresOn": "2030-12-31"
}
```

| Поле | Тип | Описание |
| --- | --- | --- |
| `orderId` | integer, required | Положительный ID заказа из заявки. |
| `number` | string, required | Номер выпущенного и активного сертификата, до 128 символов. |
| `externalId` | string, required | Постоянный уникальный ID сертификата в 1С, до 128 символов. |
| `amount` | integer, required | Номинал в рублях, от 1000; должен совпадать с заявкой. |
| `currency` | string, required | `RUB`. |
| `expiresOn` | string, optional | Дата `YYYY-MM-DD` или пустая строка. Отсутствие поля равнозначно пустой строке. |

Неизвестные поля отклоняются; размер JSON — до 16 000 байт. Срок действия в примере условный.

### Ответ

```json
{
  "status": 1,
  "code": null,
  "error": null,
  "result": { "orderId": 101, "accepted": true }
}
```

### Повторы и ошибки

- В 1С выпускать один сертификат на ключ `сайт/окружение + orderId`, включая параллельную обработку и обычный обмен заказов.
- Сохранять реквизиты в 1С до POST. При timeout/500 повторять тот же POST из журнала, даже если заявка уже исчезла из выдачи.
- Идентичный повтор успешен, пока заказ оплачен и не отменён. Смена реквизитов, занятый номер/ID и несовпадающий номинал дают 409.
- HTTP 200 подтверждает сохранение выпуска; письмо обрабатывается отдельно.
- Ошибка: `{"status":0,"code":"CONFLICT","error":"Заказ не оплачен или отменён","result":null}`.
- Коды: 400 `BAD_JSON`, 401 `UNAUTHORIZED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 405 `METHOD_NOT_ALLOWED`, 409 `CONFLICT`, 413 `PAYLOAD_TOO_LARGE`, 422 `VALIDATION_ERROR`, 500 `INTERNAL_ERROR`.

Применение сертификата к заказу — [расчёт скидок](discounts-calc.md#применение-сертификата).
