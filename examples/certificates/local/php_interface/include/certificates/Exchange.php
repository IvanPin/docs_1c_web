<?php
declare(strict_types=1);

namespace App\Certificates;

use InvalidArgumentException;
use Throwable;

/** Один HTTP-контракт для production endpoint, мок-клиента и интеграционных проверок. */
final class Exchange
{
    public const VERSION = '1.0.0';

    /** @param ?list<string> $scopes null — ключ не опознан; права выдаёт серверный реестр, не тело запроса. */
    public static function handle(string $method, array $query, string $rawBody, ?array $scopes): array
    {
        if (!in_array($method, ['GET', 'POST'], true)) return self::error(405, 'METHOD_NOT_ALLOWED', 'Метод не поддерживается');
        if ($scopes === null) return self::error(401, 'UNAUTHORIZED', 'Неверный ключ API');
        $required = $method === 'GET' ? 'certificates:read' : 'certificates:issue';
        if (!in_array($required, $scopes, true)) return self::error(403, 'FORBIDDEN', 'Недостаточно прав: требуется scope ' . $required);
        try {
            if ($method === 'GET') {
                $afterId = self::integerQuery($query['afterId'] ?? '0', 0, PHP_INT_MAX);
                $limit = self::integerQuery($query['limit'] ?? '50', 1, 100);
                $items = Service::pending($afterId, $limit);
                return self::success(['items' => $items, 'nextAfterId' => $items ? end($items)['requestId'] : null]);
            }
            if (strlen($rawBody) > 16000) return self::error(413, 'PAYLOAD_TOO_LARGE', 'Слишком большой запрос');
            try { $body = json_decode($rawBody, false, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { return self::error(400, 'BAD_JSON', 'Некорректный JSON'); }
            if (!$body instanceof \stdClass) throw new InvalidArgumentException('Ожидается объект JSON');
            $body = (array)$body;
            $allowed = ['orderId', 'number', 'externalId', 'amount', 'currency', 'expiresOn'];
            if (array_diff(array_keys($body), $allowed)) throw new InvalidArgumentException('В запросе есть неизвестные поля');
            if (!is_int($body['orderId'] ?? null) || $body['orderId'] < 1) throw new InvalidArgumentException('orderId должен быть положительным целым числом');
            return self::success(Service::issue($body['orderId'], $body));
        } catch (InvalidArgumentException $e) {
            return self::error(422, 'VALIDATION_ERROR', $e->getMessage());
        } catch (Throwable $e) {
            $status = in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 500;
            return self::error($status, [404 => 'NOT_FOUND', 409 => 'CONFLICT', 500 => 'INTERNAL_ERROR'][$status],
                $status === 500 ? 'Ошибка обработки сертификата' : $e->getMessage());
        }
    }

    private static function integerQuery(mixed $value, int $minimum, int $maximum): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9]\d*)$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $minimum || (int)$value > $maximum) {
            throw new InvalidArgumentException('Некорректные параметры пагинации');
        }
        return (int)$value;
    }

    private static function success(array $result): array
    {
        return ['httpStatus' => 200, 'body' => ['status' => 1, 'code' => null, 'error' => null, 'result' => $result]];
    }

    public static function error(int $status, string $code, string $message): array
    {
        return ['httpStatus' => $status, 'body' => ['status' => 0, 'code' => $code, 'error' => $message, 'result' => null]];
    }
}
