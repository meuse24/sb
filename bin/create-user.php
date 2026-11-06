<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
require dirname(__DIR__) . '/app/user-store.php';
require dirname(__DIR__) . '/app/admin.php';
require dirname(__DIR__) . '/app/supabase-http-store.php';
require dirname(__DIR__) . '/app/auth.php';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Aufruf: php bin/create-user.php EMAIL [ANZEIGENAME]\nPasswort ueber STDIN eingeben, nicht als Kommandozeilenargument.\n");
    exit(1);
}
$email = normalizeEmail($argv[1]);
$displayName = $argv[2] ?? '';
$password = rtrim((string) fgets(STDIN, 1024), "\r\n");
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !validNewPasswordInput($password)
    || preg_match('/\A.{0,100}\z/usD', $displayName) !== 1) {
    fwrite(STDERR, "Ungueltige Eingabe. Passwort: mindestens 12 Zeichen und hoechstens 72 UTF-8-Bytes.\n");
    exit(1);
}
try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password);
    userStore()->create($email, $hash, $displayName);
    fwrite(STDOUT, "Benutzer angelegt.\n");
} catch (RuntimeException) {
    fwrite(STDERR, "Benutzer konnte nicht angelegt werden. Konfiguration, Tabellen und eindeutige E-Mail pruefen.\n");
    exit(1);
}
