<?php
declare(strict_types=1);

namespace App\Certificates\Testing;

use RuntimeException;

/** Мок внешнего участника: работает с опубликованным GET/POST-контрактом, не с таблицами сайта. */
final class MockOneC
{
    /** @var array<int,array> Сохраняется до callback: потеря ответа сайта не должна выпускать второй сертификат. */
    private array $issued;

    public function __construct(array $issued = []) { $this->issued = $issued; }
    public function journal(): array { return $this->issued; }

    /** $http(method, query, ?body): array{httpStatus:int,body:array} */
    public function run(callable $http, string $scenario = 'success', int $limit = 50): array
    {
        if (!in_array($scenario, ['success', 'unavailable', 'wrong-amount', 'lost-response'], true)) {
            throw new RuntimeException('Unknown mock scenario');
        }
        $report = ['seen' => 0, 'accepted' => 0, 'replayed' => 0, 'errors' => []];
        $afterId = 0;
        do {
            $response = $http('GET', ['limit' => $limit, 'afterId' => $afterId], null);
            if ($response['httpStatus'] !== 200) throw new RuntimeException('Queue request failed: HTTP ' . $response['httpStatus']);
            $result = $response['body']['result'];
            foreach ($result['items'] as $item) {
                $report['seen']++;
                // Мок никогда не выпускает номера для настоящих покупателей.
                if (!str_ends_with($item['buyerEmail'], '@example.invalid')
                    || !str_ends_with($item['recipientEmail'], '@example.invalid')) {
                    $report['errors'][] = ['orderId' => $item['orderId'], 'code' => 'NOT_A_FIXTURE'];
                    continue;
                }
                if ($scenario === 'unavailable') {
                    $report['errors'][] = ['orderId' => $item['orderId'], 'code' => 'MOCK_1C_UNAVAILABLE'];
                    continue;
                }
                $id = $item['orderId'];
                $this->issued[$id] ??= ['orderId' => $id, 'number' => 'MOCK-PL-' . $id,
                    'externalId' => 'MOCK-1C-' . $id, 'amount' => $item['amount'], 'currency' => 'RUB', 'expiresOn' => '2030-12-31'];
                $body = $this->issued[$id];
                if ($scenario === 'wrong-amount') $body['amount']++;
                $response = $http('POST', [], $body);
                if ($response['httpStatus'] !== 200) {
                    $report['errors'][] = ['orderId' => $id, 'code' => $response['body']['code'] ?? 'ERROR'];
                    continue;
                }
                $report['accepted']++;
                if ($scenario === 'lost-response') {
                    $replay = $http('POST', [], $body);
                    if ($replay['httpStatus'] !== 200) throw new RuntimeException('Issuance replay failed');
                    $report['replayed']++;
                }
            }
            $next = $result['nextAfterId'];
            if ($next !== null && $next <= $afterId) throw new RuntimeException('Non-increasing queue cursor');
            $afterId = $next;
        } while ($afterId !== null);
        return $report;
    }
}
