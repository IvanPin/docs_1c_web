# Код обмена сертификатами

API **1.0.0**. Снимки от **05.11.2026**; пути и строки указаны относительно backend. [SHA-256 исходников](../../examples/certificates/source-manifest.json).

[Контракт](certificates.md) · [Алгоритм 1С](../tasks/certificates-1c.md).

<a id="http-entrypoint"></a>

## HTTP endpoint, заголовки и авторизация

Источник: `api/1c/certificates/index.php`, строки 1–26.

```php
<?php
declare(strict_types=1);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/certificates/bootstrap.php';

use App\Api\ApiClient;
use App\Certificates\Exchange;

// Новый scope доступен только явно настроенному клиенту реестра clients.php, не legacy-ключу.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Certificate-Contract-Version: ' . Exchange::VERSION);
header('Allow: GET, POST, OPTIONS');
if ($method === 'OPTIONS') { http_response_code(204); exit; }
try {
    $client = ApiClient::resolve(ApiClient::extractKey());
    $response = Exchange::handle($method, $_GET, (string)file_get_contents('php://input', false, null, 0, 16001), $client?->scopes());
} catch (Throwable $e) {
    $response = Exchange::error(500, 'INTERNAL_ERROR', 'Ошибка обработки сертификата');
}
http_response_code($response['httpStatus']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

<a id="pending-queue"></a>

## Выбор оплаченных заявок

Источник: `local/php_interface/include/certificates/Service.php`, строки 198–215.

```php
    /** Проект обмена: 1С забирает только оплаченные заявки. Повторять до подтверждения выпуска. */
    public static function pending(int $afterId = 0, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $db = Application::getConnection();
        $rows = $db->query('SELECT p.* FROM wgp_certificate_purchase p INNER JOIN b_sale_order o ON o.ID=p.ORDER_ID'
            . " WHERE p.ID>" . max(0, $afterId) . " AND p.CERTIFICATE_NUMBER IS NULL AND o.PAYED='Y'"
            . " AND o.CANCELED='N' AND o.CURRENCY='RUB' AND o.PRICE=p.AMOUNT ORDER BY p.ID ASC LIMIT " . $limit);
        $items = [];
        while ($row = $rows->fetch()) {
            $payload = json_decode($row['PAYLOAD'], true, 512, JSON_THROW_ON_ERROR);
            $items[] = ['requestId' => (int)$row['ID'], 'orderId' => (int)$row['ORDER_ID'],
                'amount' => (int)$row['AMOUNT'], 'currency' => 'RUB', 'paid' => true,
                'buyerName' => $payload['buyerName'], 'buyerEmail' => $payload['buyerEmail'],
                'recipientName' => $payload['recipientName'], 'recipientEmail' => $payload['recipientEmail']];
        }
        return $items;
    }
```

<a id="confirm-issued"></a>

## Идемпотентное подтверждение выпуска

Источник: `local/php_interface/include/certificates/Service.php`, строки 217–253.

```php
    public static function issue(int $orderId, array $input): array
    {
        $certificate = Purchase::issuance($input);
        return self::locked('order:' . $orderId, static function () use ($orderId, $certificate): array {
            $row = self::find($orderId);
            if (!$row) throw new RuntimeException('Заявка не найдена', 404);
            self::paidOrder($row);
            if ($certificate['amount'] !== (int)$row['AMOUNT']) {
                throw new RuntimeException('Номинал из 1С не совпадает с оплаченным', 409);
            }
            if (!empty($row['CERTIFICATE_NUMBER'])) {
                if ($row['CERTIFICATE_NUMBER'] !== $certificate['number'] || $row['EXTERNAL_ID'] !== $certificate['externalId']
                    || (string)$row['EXPIRES_ON'] !== $certificate['expiresOn']) {
                    throw new RuntimeException('Для заказа уже выпущен другой сертификат', 409);
                }
            } else {
                $duplicate = PurchaseTable::getList(['filter' => ['LOGIC' => 'OR',
                    ['=CERTIFICATE_NUMBER' => $certificate['number']], ['=EXTERNAL_ID' => $certificate['externalId']]],
                    'select' => ['ID'], 'limit' => 1])->fetch();
                if ($duplicate) throw new RuntimeException('Номер или внешний ID сертификата уже используется другим заказом', 409);
                try {
                    self::checked(PurchaseTable::update($row['ID'], [
                        'CERTIFICATE_NUMBER' => $certificate['number'], 'EXTERNAL_ID' => $certificate['externalId'],
                        'EXPIRES_ON' => $certificate['expiresOn'], 'ISSUED_AT' => time(),
                    ]));
                } catch (\Bitrix\Main\DB\SqlQueryException $e) {
                    // Параллельный callback мог занять номер после SELECT; индекс остаётся последней защитой.
                    if (str_contains($e->getMessage(), 'ux_cert_number') || str_contains($e->getMessage(), 'ux_cert_external')) {
                        throw new RuntimeException('Номер или внешний ID сертификата уже используется другим заказом', 409);
                    }
                    throw $e;
                }
            }
            // Отправка отдельным кроном: ответ 1С не зависит от почтового сервиса.
            return ['orderId' => $orderId, 'accepted' => true];
        });
    }
```

<a id="paid-order"></a>

## Повторная проверка оплаты и отмены

Источник: `local/php_interface/include/certificates/Service.php`, строки 166–175.

```php
    public static function paidOrder(array $row): Order
    {
        self::modules();
        $order = Order::load((int)$row['ORDER_ID']);
        if (!$order || $order->isCanceled() || !$order->isPaid()
            || $order->getCurrency() !== 'RUB' || abs((float)$order->getPrice() - (int)$row['AMOUNT']) > 0.001) {
            throw new RuntimeException('Заказ не оплачен или отменён', 409);
        }
        return $order;
    }
```

## Общий обработчик и валидация

- [Exchange.php](../../examples/certificates/local/php_interface/include/certificates/Exchange.php) — версия 1.0.0, scopes, GET/POST, типы параметров, лимит JSON и ошибки.
- [Purchase.php](../../examples/certificates/local/php_interface/include/certificates/Purchase.php) — `issuance()`: валидация номера, externalId, целого номинала RUB и календарной даты.
- [MockOneC.php](../../examples/certificates/local/tools/certificates/MockOneC.php) — опрос и повтор callback; journal записывается в конце прохода.

`requestId` — курсор, `orderId` — ключ покупки. Повтор POST повторно проверяет оплату и отмену; отменённый заказ даёт 409. Почта обрабатывается отдельно.
