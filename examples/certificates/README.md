# Локальный стенд обмена сертификатами, API 1.0.0

[Постановка для 1С](../../docs/tasks/certificates-1c.md) · [Контракт](../../docs/api/certificates.md) · [OpenAPI](../../docs/api/certificates-1c.openapi.json) · [JSON-примеры](examples/) · [Происхождение исходников и SHA-256](source-manifest.json).

Точные копии обработчика и тестовых инструментов backend на 05.11.2026. Стенд не требует Битрикса/БД, не выполняет платежи и не отправляет письма. Номера `MOCK-*`, адреса `@example.invalid` и открытый локальный ключ — тестовые значения. Доступ только через loopback; production-ключ не нужен.

Нужен PHP 8.2+ с `mbstring` и `curl`. Ниже команды выполняются из корня этого репозитория.

## Запустить сервер

```bash
cd examples/certificates
export CERTIFICATE_MOCK_STATE="$(mktemp)"
export CERTIFICATE_MOCK_KEY='local-certificate-mock-only'
php -S 127.0.0.1:18091 local/tools/certificates/mock_exchange_router.php
```

Оставить сервер в этом терминале. После проверки остановить его через Ctrl+C. Для каждого независимого сценария использовать новый файл состояния; пустой файл заполняется fixture автоматически. Заказы 101/102 оплачены, 103 не оплачен, 104 отменён.

## Прогнать клиента

В другом терминале, из корня репозитория:

```bash
cd examples/certificates
export CERTIFICATE_API_KEY='local-certificate-mock-only'
CERTIFICATE_MOCK_JOURNAL="$(mktemp)"
rm "$CERTIFICATE_MOCK_JOURNAL"
php local/tools/certificates/mock_1c.php \
  --base-url=http://127.0.0.1:18091 \
  --scenario=lost-response \
  --journal="$CERTIFICATE_MOCK_JOURNAL"
```

Первый результат: `seen: 2`, `accepted: 2`, `replayed: 2`, `errors: []`. Повтор той же команды с тем же состоянием сервера: `seen: 0`, `accepted: 0`.

`lost-response` проверяет повтор callback после успешного ответа. Он не имитирует фактический обрыв соединения или аварийное завершение процесса. Клиент записывает журнал в файл при завершении прохода; для рабочей 1С требуется устойчивое транзакционное сохранение **до первого POST** и защита от параллельного выпуска.

Другие сценарии: `success`, `unavailable`, `wrong-amount`. Последние два ожидаемо возвращают ненулевой код выхода и ошибки в отчёте. Они не подтверждают выпуск, заявка остаётся в очереди. Для нового сценария перезапустить сервер с новым файлом состояния и создать отдельный журнал.

## Ручные запросы

До запуска клиента, на свежем состоянии сервера, из `examples/certificates`:

```bash
export CERTIFICATE_API_KEY='local-certificate-mock-only'
curl -i -H "X-Api-Key: $CERTIFICATE_API_KEY" \
  'http://127.0.0.1:18091/api/1c/certificates/index.php?afterId=0&limit=1'

curl -i -X POST -H "X-Api-Key: $CERTIFICATE_API_KEY" \
  -H 'Content-Type: application/json' \
  --data @examples/confirm-issued.request.json \
  'http://127.0.0.1:18091/api/1c/certificates/index.php'
```

Ответы содержат `X-Certificate-Mock: true` и `X-Certificate-Contract-Version: 1.0.0`. На странице GET после последней непустой страницы нужно сделать следующий запрос, чтобы получить `nextAfterId: null`; затем новый проход начинается с 0.

## Проверить схемы и примеры

В репозиторий включён исходный `validate_contract.mjs`. Он требует Node.js и установленный `ajv` v8 в текущем Node-проекте. Из такого проекта передать абсолютный путь к скрипту и OpenAPI:

```bash
node /absolute/path/docs_1c_web/examples/certificates/local/tools/certificates/validate_contract.mjs \
  /absolute/path/docs_1c_web/docs/api/certificates-1c.openapi.json
```

Этот запуск проверяет схемы и встроенные примеры OpenAPI. Отдельные JSON-примеры лежат в `examples/`. Если вторым аргументом передать отчёт интеграции backend, скрипт дополнительно сверит его ответы; старый отчёт локального Битрикса не включён как доказательство новых проверок.

Результаты проверки перенесённого пакета: [verification.md](verification.md). Настоящая 1С, Робокасса и SMTP этим стендом не проверяются.
