<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (time() - (int) ($_SESSION['last_login_attempt'] ?? 0) < 3) {
        http_response_code(429);
        $error = 'Bitte vor dem nächsten Anmeldeversuch drei Sekunden warten.';
    } else {
        $_SESSION['last_login_attempt'] = time();
        $email = is_string($_POST['email'] ?? null) ? normalizeEmail($_POST['email']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !validPasswordInput($password)) {
            http_response_code(422);
            $error = 'Bitte eine gültige E-Mail-Adresse und dein Passwort eingeben.';
        } else {
            $result = attemptLogin($email, $password, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            unset($password, $_POST['password']);
            if ($result['status'] === 200) {
                redirectTo('profile.php');
            }
            http_response_code($result['status']);
            $error = $result['message'];
        }
        unset($password, $_POST['password']);
    }
} elseif (currentUser() !== null) {
    redirectTo('profile.php');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmelden · PHP und PostgreSQL</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">PHP + PostgreSQL · Eigene Benutzerverwaltung</p>
    <h1>Anmelden</h1>
    <p>Melde dich mit deinem Benutzerkonto für diese Website an.</p>
    <?php if (isset($_GET['account_updated'])): ?>
        <p role="status">Dein Konto wurde geändert. Bitte mit den aktuellen Zugangsdaten erneut anmelden.</p>
    <?php elseif (isset($_GET['registered'])): ?>
        <p role="status">Dein Konto wurde erstellt. Bitte anmelden.</p>
    <?php elseif (isset($_GET['password_changed'])): ?>
        <p role="status">Dein Passwort wurde geändert. Bitte mit dem neuen Passwort anmelden.</p>
    <?php elseif (isset($_GET['expired'])): ?>
        <p role="status">Deine Sitzung ist abgelaufen. Bitte erneut anmelden.</p>
    <?php elseif (isset($_GET['logout'])): ?>
        <p role="status">Du bist auf dieser Website abgemeldet.</p>
    <?php endif; ?>
    <section aria-labelledby="login-title">
        <h2 id="login-title">E-Mail und Passwort</h2>
        <?php if ($error !== null): ?>
            <p role="alert" class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="login.php">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <label for="email">E-Mail-Adresse</label>
            <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required value="<?= escape($email) ?>">
            <label for="password">Passwort</label>
            <input id="password" name="password" type="password" autocomplete="current-password" maxlength="72" required>
            <button type="submit">Anmelden</button>
        </form>
    </section>
    <p><a href="index.php">Zum Verbindungstest</a></p>
    <?php if (!registrationRequiresConfirmation() || registrationMailReady()): ?>
        <p><a href="register.php">Noch kein Konto? Registrieren</a></p>
    <?php endif; ?>
</main>
</body>
</html>
