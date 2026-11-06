<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!registrationRequiresConfirmation()) {
    unset($_SESSION['registration_grant']);
    redirectTo('register.php');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Diese Aktion ist nicht verfügbar.');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'start') {
        $token = $_POST['token'] ?? null;
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            $_SESSION['registration_grant'] = ['hash' => hash('sha256', $token), 'started_at' => time()];
            unset($token, $_POST['token']);
            redirectTo('confirm.php');
        }
        http_response_code(400);
        $error = 'Der Bestätigungslink ist ungültig. Bitte einen neuen Link anfordern.';
    } elseif ($action !== 'confirm') {
        http_response_code(400);
        $error = 'Diese Aktion ist nicht verfügbar.';
    } else {
        $grant = $_SESSION['registration_grant'] ?? null;
        $password = $_POST['password'] ?? null;
        $confirmation = $_POST['confirm_password'] ?? null;
        unset($_POST['password'], $_POST['confirm_password']);
        if (!is_array($grant) || time() - ($grant['started_at'] ?? 0) >= 1800) {
            http_response_code(400);
            unset($_SESSION['registration_grant']);
            $error = 'Der Bestätigungslink ist abgelaufen. Bitte einen neuen Link anfordern.';
        } elseif (!is_string($password) || !validNewPasswordInput($password)
            || !is_string($confirmation) || $password !== $confirmation) {
            http_response_code(422);
            $error = 'Bitte mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes verwenden. Die Passwortwiederholung muss übereinstimmen.';
        } else {
            try {
                $store = userStore();
                if (!$store->allowAttempt('registration-confirm:' . $grant['hash'], (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                    http_response_code(429);
                    $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.';
                } elseif ($store->confirmRegistration($grant['hash'], password_hash($password, PASSWORD_DEFAULT))) {
                    unset($password, $confirmation);
                    destroyLocalSession();
                    redirectTo('login.php?registered=1');
                } else {
                    http_response_code(400);
                    unset($_SESSION['registration_grant']);
                    $error = 'Der Bestätigungslink ist ungültig, abgelaufen oder bereits verwendet. Bitte einen neuen Link anfordern.';
                }
            } catch (RuntimeException) {
                http_response_code(503);
                $error = 'Die Bestätigung konnte nicht abgeschlossen werden. Bitte später erneut versuchen. Falls das Konto bereits erstellt wurde, kannst du dich mit deinem gewählten Passwort anmelden.';
            }
        }
        unset($password, $confirmation);
    }
}
$hasGrant = isset($_SESSION['registration_grant']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail bestätigen · PHP und PostgreSQL</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/confirmation.js" defer></script>
</head>
<body>
<main>
    <p class="eyebrow">Dein Benutzerkonto</p>
    <h1>E-Mail bestätigen</h1>
    <form id="confirmation-start" method="post" action="confirm.php" hidden>
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="start">
        <input id="confirmation-token" type="hidden" name="token" value="">
    </form>
    <?php if ($error !== null): ?><p role="alert" class="error"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($hasGrant): ?>
        <section aria-labelledby="password-title">
            <h2 id="password-title">Dein Passwort festlegen</h2>
            <p id="password-help">Mindestens 12 Zeichen, höchstens 72 UTF-8-Bytes. Umlaute und Emojis benötigen mehrere Bytes.</p>
            <form method="post" action="confirm.php">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="confirm">
                <label for="password">Passwort</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="password-help" required>
                <label for="confirm-password">Passwort wiederholen</label>
                <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <button type="submit">Konto erstellen</button>
            </form>
        </section>
    <?php else: ?>
        <p id="confirmation-status" role="status">Öffne den Bestätigungslink aus deiner E-Mail, um dein Passwort festzulegen.</p>
    <?php endif; ?>
    <noscript><p>Bitte aktiviere JavaScript, um den Bestätigungslink zu öffnen.</p></noscript>
    <p><a href="register.php">Neuen Bestätigungslink anfordern</a> · <a href="login.php">Zur Anmeldung</a></p>
</main>
</body>
</html>
