<?php
declare(strict_types=1);

namespace App\Certificates\Testing;

use App\Certificates\Purchase;
use RuntimeException;

/** Автономный локальный стенд контракта для разработчика 1С; настоящий Service сюда не подключается. */
final class MockExchangeService
{
    private static function transaction(callable $callback): mixed
    {
        $path = getenv('CERTIFICATE_MOCK_STATE');
        if (!$path || !is_dir(dirname($path))) throw new RuntimeException('Set CERTIFICATE_MOCK_STATE to a private temporary file');
        $file = fopen($path, 'c+');
        if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Mock state lock failed');
        chmod($path, 0600);
        try {
            $raw = stream_get_contents($file);
            $state = $raw === '' ? self::fixtures() : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $result = $callback($state);
            rewind($file); ftruncate($file, 0);
            fwrite($file, json_encode($state, JSON_THROW_ON_ERROR)); fflush($file);
            return $result;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    private static function fixtures(): array
    {
        $state = [];
        foreach ([101, 102, 103, 104] as $id) {
            $state[$id] = ['requestId' => $id + 1000, 'orderId' => $id, 'amount' => 5000, 'currency' => 'RUB',
                'paid' => $id !== 103, 'canceled' => $id === 104, 'certificate' => null,
                'buyerName' => 'Тестовый покупатель', 'buyerEmail' => 'buyer@example.invalid',
                'recipientName' => 'Тестовый получатель', 'recipientEmail' => 'recipient@example.invalid'];
        }
        return $state;
    }

    public static function pending(int $afterId = 0, int $limit = 50): array
    {
        return self::transaction(static function (array &$state) use ($afterId, $limit): array {
            $items = [];
            foreach ($state as $row) {
                if ($row['requestId'] <= $afterId || !$row['paid'] || $row['canceled'] || $row['certificate']) continue;
                unset($row['canceled'], $row['certificate']);
                $items[] = $row;
                if (count($items) === $limit) break;
            }
            return $items;
        });
    }

    public static function issue(int $orderId, array $input): array
    {
        $certificate = Purchase::issuance($input);
        return self::transaction(static function (array &$state) use ($orderId, $certificate): array {
            if (!isset($state[$orderId])) throw new RuntimeException('Заявка не найдена', 404);
            $row = $state[$orderId];
            if (!$row['paid'] || $row['canceled']) throw new RuntimeException('Заказ не оплачен или отменён', 409);
            if ($row['amount'] !== $certificate['amount']) throw new RuntimeException('Номинал из 1С не совпадает с оплаченным', 409);
            if ($row['certificate'] && $row['certificate'] !== $certificate) throw new RuntimeException('Для заказа уже выпущен другой сертификат', 409);
            foreach ($state as $otherId => $other) {
                if ((int)$otherId !== $orderId && $other['certificate'] && ($other['certificate']['number'] === $certificate['number']
                    || $other['certificate']['externalId'] === $certificate['externalId'])) throw new RuntimeException('Номер или внешний ID уже используется', 409);
            }
            $state[$orderId]['certificate'] = $certificate;
            return ['orderId' => $orderId, 'accepted' => true];
        });
    }
}
