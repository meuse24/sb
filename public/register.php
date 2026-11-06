<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Diese Aktion ist nicht verfügbar.');
}
$requiresConfirmation = registrationRequiresConfirmation();
$available = !$requiresConfirmation || registrationMailReady();
$email = '';
$displayName = '';
$error = null;
$requested = $requiresConfirmation && ($_SESSION['registration_requested'] ?? false) === true;
unset($_SESSION['registration_requested']);
if (!$available) {
    http_response_code(503);
    $error = 'Die Registrierung wird gerade eingerichtet. Bitte später erneut versuchen.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if ($available) {
        $email = is_string($_POST['email'] ?? null) ? normalizeEmail($_POST['email']) : '';
        $nameInput = $_POST['display_name'] ?? '';
        $password = $_POST['password'] ?? null;
        $confirmation = $_POST['confirm_password'] ?? null;
        unset($_POST['password'], $_POST['confirm_password']);
        $displayName = is_string($nameInput) && strlen($nameInput) <= 400 ? trim($nameInput) : '';
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($nameInput) || strlen($nameInput) > 400
            || preg_match('/\A[^\p{Cc}]{0,100}\z/uD', $displayName) !== 1) {
            http_response_code(422);
            $error = 'Bitte eine gültige E-Mail-Adresse und höchstens 100 Zeichen für den Anzeigenamen eingeben.';
        } elseif (!$requiresConfirmation && (!is_string($password) || !validNewPasswordInput($password)
            || !is_string($confirmation) || $password !== $confirmation)) {
            http_response_code(422);
            $error = 'Bitte mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes für das Passwort verwenden. Die Wiederholung muss übereinstimmen.';
        } else {
            try {
                $store = userStore();
                if (!$store->allowAttempt('registration:' . $email, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                    http_response_code(429);
                    $error = 'Zu viele Anfragen. Bitte in 15 Minuten erneut versuchen.';
                } elseif (!$requiresConfirmation) {
                    $created = $store->registerWithoutConfirmation($email, $displayName, password_hash($password, PASSWORD_DEFAULT));
                    unset($password, $confirmation);
                    if ($created) {
                        destroyLocalSession();
                        redirectTo('login.php?registered=1');
                    }
                    http_response_code(409);
                    $error = 'Das Konto konnte nicht erstellt werden. Falls du bereits ein Konto hast, verwende bitte die Anmeldung.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    if ($store->prepareRegistration($email, $displayName, $tokenHash)) {
                        try {
                            sendRegistrationMail($email, $token);
                        } catch (RuntimeException) {
                            // A new retry must not be trapped by an undeliverable token.
                            try { $store->cancelRegistration($tokenHash); } catch (RuntimeException) {}
                            error_log('Registration delivery failed.');
                        }
                    }
                    unset($token, $tokenHash);
                    // Same public result for new, existing and recently requested emails.
                    $_SESSION['registration_requested'] = true;
                    redirectTo('register.php');
                }
            } catch (RuntimeException) {
                http_response_code(503);
                $error = 'Die Registrierung ist momentan nicht verfügbar. Bitte später erneut versuchen.';
            }
        }
        unset($password, $confirmation);
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrieren · PHP und PostgreSQL</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">Dein Benutzerkonto</p>
    <h1>Registrieren</h1>
    <p><?= $requiresConfirmation ? 'Zuerst bestätigen wir deine E-Mail-Adresse. Über den Link in der E-Mail legst du danach dein Passwort fest.' : 'Erstelle dein Konto und melde dich anschließend direkt an. Eine E-Mail-Bestätigung ist derzeit nicht erforderlich.' ?></p>
    <?php if ($requested): ?>
        <p role="status" class="success result">Wenn eine Registrierung möglich ist, erhältst du eine E-Mail mit einem Link, der 30 Minuten gültig ist. Prüfe auch den Spamordner. Falls nichts ankommt, versuche es später erneut.</p>
    <?php endif; ?>
    <?php if ($error !== null): ?><p role="alert" class="error"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($available): ?>
        <section aria-labelledby="register-title">
            <h2 id="register-title"><?= $requiresConfirmation ? 'Bestätigungslink anfordern' : 'Dein neues Konto' ?></h2>
            <form method="post" action="register.php">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <label for="email">E-Mail-Adresse</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= escape($email) ?>">
                <label for="display-name">Anzeigename (optional)</label>
                <input id="display-name" name="display_name" type="text" autocomplete="nickname" value="<?= escape($displayName) ?>">
                <?php if (!$requiresConfirmation): ?>
                    <p id="password-help">Mindestens 12 Zeichen, höchstens 72 UTF-8-Bytes. Umlaute und Emojis benötigen mehrere Bytes.</p>
                    <label for="password">Passwort</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="password-help" required>
                    <label for="confirm-password">Passwort wiederholen</label>
                    <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
                <?php endif; ?>
                <button type="submit"><?= $requiresConfirmation ? 'Bestätigungslink senden' : 'Konto erstellen' ?></button>
            </form>
        </section>
    <?php endif; ?>
    <p><a href="login.php">Zur Anmeldung</a></p>
</main>
</body>
</html>
