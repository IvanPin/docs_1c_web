<?php
declare(strict_types=1);

namespace App\Certificates;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Новая покупка Пламенеи. Номер и остаток принадлежат исключительно 1С. */
final class Purchase
{
    /** Robokassa ResultURL присылает до 6 знаков; номинал целый, дробная часть должна быть нулевой. */
    public static function paymentMatches(int $amount, string $outSum): bool
    {
        return preg_match('/^(\d{1,8})(?:\.(\d{1,6}))?$/D', $outSum, $parts) === 1
            && (int)$parts[1] === $amount && trim($parts[2] ?? '', '0') === '';
    }

    public static function validate(array $input, int $now, int $maximum = 100000): array
    {
        $text = static function (string $key, int $limit, bool $required = false) use ($input): string {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > $limit
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)) {
                throw new InvalidArgumentException('Некорректное поле: ' . $key);
            }
            $value = trim($value);
            if ($required && $value === '') {
                throw new InvalidArgumentException('Заполните поле: ' . $key);
            }
            return $value;
        };
        $key = $text('requestKey', 36, true);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $key)) {
            throw new InvalidArgumentException('Некорректный ключ покупки');
        }
        $amount = $input['amount'] ?? null;
        if (!is_int($amount) || $amount < 1000 || $amount > $maximum) {
            throw new InvalidArgumentException('Номинал должен быть целым числом от 1000 до ' . $maximum . ' ₽');
        }
        $design = $text('design', 10, true);
        if (!in_array($design, ['card1', 'card2', 'card3'], true)) {
            throw new InvalidArgumentException('Выберите дизайн сертификата');
        }
        $email = $text('recipientEmail', 254, true);
        $buyerEmail = $text('buyerEmail', 254, true);
        foreach ([$email, $buyerEmail] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || strpbrk($address, "\r\n") !== false) {
                throw new InvalidArgumentException('Укажите корректный email');
            }
        }
        if (($input['consent'] ?? null) !== true) {
            throw new InvalidArgumentException('Необходимо согласие на обработку персональных данных');
        }
        $mode = $text('deliveryMode', 5, true);
        $sendAt = 0;
        if ($mode === 'later') {
            $date = $text('date', 10, true);
            $time = $text('time', 5, true);
            $value = $date . ' ' . $time;
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new DateTimeZone('Europe/Moscow'));
            if (!$parsed || $parsed->format('Y-m-d H:i') !== $value || $parsed->getTimestamp() <= $now) {
                throw new InvalidArgumentException('Выберите будущие дату и время по Москве');
            }
            $sendAt = $parsed->getTimestamp();
        } elseif ($mode !== 'now') {
            throw new InvalidArgumentException('Выберите время отправки');
        }
        return [
            'requestKey' => strtolower($key), 'amount' => $amount, 'design' => $design,
            'recipientName' => $text('recipientName', 100, true), 'recipientEmail' => $email,
            'senderName' => $text('senderName', 100), 'wishes' => $text('wishes', 500),
            'buyerName' => $text('buyerName', 100, true), 'buyerEmail' => $buyerEmail,
            'deliveryMode' => $mode, 'sendAt' => $sendAt, 'consent' => true,
        ];
    }

    public static function issuance(array $input): array
    {
        if (!is_int($input['amount'] ?? null) || $input['amount'] < 1000 || ($input['currency'] ?? null) !== 'RUB') {
            throw new InvalidArgumentException('Необходимо подтвердить номинал сертификата в RUB');
        }
        $result = [];
        foreach (['number' => 128, 'externalId' => 128] as $field => $limit) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit
                || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                throw new InvalidArgumentException('Некорректное поле: ' . $field);
            }
            $result[$field] = trim($value);
        }
        $expires = $input['expiresOn'] ?? '';
        if (!is_string($expires)) {
            throw new InvalidArgumentException('Некорректная дата действия');
        }
        if ($expires !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (!$date || $date->format('Y-m-d') !== $expires) {
                throw new InvalidArgumentException('Некорректная дата действия');
            }
        }
        $result['expiresOn'] = $expires;
        $result['amount'] = $input['amount'];
        return $result;
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function status(array $row, bool $paid, bool $canceled, string $mailState, int $now): string
    {
        if ($canceled) return 'canceled';
        if (!$paid) return 'awaiting_payment';
        if (empty($row['CERTIFICATE_NUMBER'])) return 'awaiting_issue';
        if ($mailState === 'Y') return 'sent';
        if (in_array($mailState, ['F', 'P', 'E', '0'], true)) return 'email_failed';
        if (!empty($row['MAIL_EVENT_ID'])) return 'email_queued';
        return (int)$row['SEND_AT'] > $now ? 'scheduled' : 'ready_to_send';
    }
}
