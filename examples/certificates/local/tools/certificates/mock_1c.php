<?php
declare(strict_types=1);

// Локальный клиент готового API. Ключ только из окружения, не из аргументов или журналов.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/MockOneC.php';
$options = getopt('', ['base-url:', 'scenario::', 'journal::']);
$base = rtrim((string)($options['base-url'] ?? ''), '/');
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['localhost', '127.0.0.1', '[::1]', 'api.plameneya.local'], true)
    || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
    fwrite(STDERR, "Use --base-url with a local fixture server only\n"); exit(2);
}
$token = getenv('CERTIFICATE_API_KEY');
if (!$token) { fwrite(STDERR, "Set CERTIFICATE_API_KEY in the environment\n"); exit(2); }
$journalPath = (string)($options['journal'] ?? sys_get_temp_dir() . '/certificate-mock-1c.json');
$journal = is_file($journalPath) ? json_decode(file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR) : [];
$mock = new \App\Certificates\Testing\MockOneC($journal);
$http = static function (string $method, array $query, ?array $body) use ($base, $token): array {
    $url = $base . '/api/1c/certificates/index.php' . ($query ? '?' . http_build_query($query) : '');
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $token]]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($raw === false) throw new RuntimeException('Mock HTTP request failed');
    unset($curl);
    return ['httpStatus' => $status, 'body' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
};
try {
    $report = $mock->run($http, (string)($options['scenario'] ?? 'success'));
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($report['errors'] ? 1 : 0);
} finally {
    file_put_contents($journalPath, json_encode($mock->journal(), JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($journalPath, 0600);
}
