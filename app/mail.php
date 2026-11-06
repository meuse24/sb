<?php
declare(strict_types=1);

function registrationRequiresConfirmation(): bool
{
    $path = dirname(__DIR__) . '/config/config.local.php';
    $config = is_file($path) ? require $path : [];
    return ($config['registration_require_email_confirmation'] ?? false) === true;
}

function mailConfig(): array
{
    $path = dirname(__DIR__) . '/config/mail.local.php';
    $config = is_file($path) ? require $path : [];
    return is_array($config) ? $config : [];
}

function registrationMailReady(): bool
{
    $config = mailConfig();
    $base = rtrim((string) ($config['base_url'] ?? ''), '/');
    foreach (['host', 'username', 'password', 'from_address'] as $field) {
        if (!is_string($config[$field] ?? null) || $config[$field] === ''
            || str_contains($config[$field], 'YOUR_') || $config[$field] === 'REPLACE_LOCALLY') {
            return false;
        }
    }
    return extension_loaded('openssl') && is_file(__DIR__ . '/vendor/autoload.php')
        && is_int($config['port'] ?? null) && $config['port'] > 0 && $config['port'] <= 65535
        && in_array($config['encryption'] ?? '', ['starttls', 'smtps'], true)
        && filter_var($config['from_address'], FILTER_VALIDATE_EMAIL) !== false
        && filter_var($base, FILTER_VALIDATE_URL) !== false && str_starts_with($base, 'https://')
        && !isset(parse_url($base)['query']) && !isset(parse_url($base)['fragment'])
        && !isset(parse_url($base)['user']) && !isset(parse_url($base)['pass']);
}

function sendRegistrationMail(string $email, string $token): void
{
    if (!registrationMailReady()) {
        throw new RuntimeException('Mailversand nicht eingerichtet.');
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $config = mailConfig();
    // The fragment is sent to the browser, not to web servers or access logs.
    $url = rtrim($config['base_url'], '/') . '/confirm.php#token=' . $token;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = $config['encryption'] === 'smtps'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = false;
        $mail->SMTPDebug = 0;
        $mail->Timeout = 10;
        $mail->getSMTPInstance()->Timelimit = 15;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['from_address'], (string) ($config['from_name'] ?? 'PHP Lernprojekt'));
        $mail->addAddress($email);
        $mail->Subject = 'Bestätige deine E-Mail-Adresse';
        $mail->Body = "Du hast eine Registrierung für unser PHP-Lernprojekt angefordert.\n\n"
            . "Öffne diesen Link und lege dort dein Passwort fest:\n" . $url . "\n\n"
            . "Der Link ist 30 Minuten gültig und einmal verwendbar.\n"
            . "Falls du das nicht angefordert hast, ignoriere diese E-Mail.\n";
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception) {
        // Never expose SMTP errors, credentials, recipients or confirmation links.
        throw new RuntimeException('Bestätigungsmail konnte nicht gesendet werden.');
    }
}
