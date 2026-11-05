<?php
declare(strict_types=1);

/** Only return deliberately selected, non-sensitive diagnostic values. */
function checkSupabase(): array
{
    $configPath = dirname(__DIR__) . '/config/config.local.php';
    if (!is_file($configPath)) {
        return ['ok' => false, 'message' => 'Die lokale Supabase-Konfiguration fehlt.'];
    }
    $config = require $configPath;
    $url = rtrim((string) ($config['supabase_url'] ?? ''), '/');
    $key = (string) ($config['supabase_publishable_key'] ?? '');
    if (!preg_match('~\Ahttps://[a-z0-9-]+\.supabase\.co\z~D', $url)
        || !preg_match('/\Asb_publishable_[A-Za-z0-9_-]+\z/D', $key)) {
        return ['ok' => false, 'message' => 'Die Supabase-Konfiguration ist unvollständig.'];
    }
    if (!extension_loaded('curl')) {
        return ['ok' => false, 'message' => 'Die PHP-Erweiterung cURL fehlt.'];
    }
    $handle = curl_init($url . '/auth/v1/settings');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'apikey: ' . $key],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false || $status !== 200) {
        return ['ok' => false, 'message' => 'Supabase ist momentan nicht erreichbar oder lehnt die Anfrage ab.'];
    }
    try {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'message' => 'Supabase hat keine gültige JSON-Antwort geliefert.'];
    }
    if (!is_array($data) || !isset($data['external']['email']) || !is_bool($data['external']['email'])
        || !isset($data['mailer_autoconfirm']) || !is_bool($data['mailer_autoconfirm'])) {
        return ['ok' => false, 'message' => 'Die Supabase-Antwort hat nicht die erwartete Struktur.'];
    }
    return [
        'ok' => true,
        'message' => 'HTTPS-Verbindung vom Webspace zu Supabase erfolgreich (HTTP 200).',
        'email_enabled' => $data['external']['email'],
        'confirmation_required' => !$data['mailer_autoconfirm'],
    ];
}
