# Стенд сертификатов · API 1.0.0

[Контракт](../../docs/api/certificates.md) · [OpenAPI](../../docs/api/certificates-1c.openapi.json) · [JSON-примеры](examples/) · [SHA-256 исходников](source-manifest.json).

PHP 8.2+, `mbstring`, `curl`. Без Битрикса и БД, только loopback. Заказы 101/102 оплачены, 103 не оплачен, 104 отменён. Номера `MOCK-*`, адреса `@example.invalid` и ключ ниже — тестовые.

## Сервер

Из корня репозитория:

```bash
cd examples/certificates
export CERTIFICATE_MOCK_STATE="$(mktemp)"
export CERTIFICATE_MOCK_KEY='local-certificate-mock-only'
php -S 127.0.0.1:18091 local/tools/certificates/mock_exchange_router.php
```

Новый сценарий — новый файл `CERTIFICATE_MOCK_STATE`. Остановка — Ctrl+C.

## Клиент

Другой терминал, из корня репозитория:

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

| Сценарий | Результат на свежем состоянии |
|---|---|
| `success` | `seen: 2`, `accepted: 2` |
| `lost-response` | `seen: 2`, `accepted: 2`, `replayed: 2` |
| `unavailable` | Две ошибки, выпуск не подтверждён, exit 1 |
| `wrong-amount` | Два конфликта 409, выпуск не подтверждён, exit 1 |

После успешного выпуска повторный проход: `seen: 0`. Для независимых сценариев использовать отдельные state и journal.

`lost-response` повторяет POST после HTTP 200; обрыв соединения не моделируется. Journal записывается в конце прохода. Рабочая 1С должна сохранять выпуск до первого POST.

## Ручные запросы

Из `examples/certificates`, на свежем состоянии сервера:

```bash
export CERTIFICATE_API_KEY='local-certificate-mock-only'
curl -i -H "X-Api-Key: $CERTIFICATE_API_KEY" \
  'http://127.0.0.1:18091/api/1c/certificates/index.php?afterId=0&limit=1'

curl -i -X POST -H "X-Api-Key: $CERTIFICATE_API_KEY" \
  -H 'Content-Type: application/json' \
  --data @examples/confirm-issued.request.json \
  'http://127.0.0.1:18091/api/1c/certificates/index.php'
```

Заголовки: `X-Certificate-Mock: true`, `X-Certificate-Contract-Version: 1.0.0`.

## Валидация OpenAPI

Node.js, `ajv` v8. Запуск из Node-проекта с установленным `ajv`:

```bash
node /absolute/path/docs_1c_web/examples/certificates/local/tools/certificates/validate_contract.mjs \
  /absolute/path/docs_1c_web/docs/api/certificates-1c.openapi.json
```

Второй аргумент — необязательный JSON-отчёт интеграции backend для сверки фактических ответов.
