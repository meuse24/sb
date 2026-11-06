<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Diese Aktion ist nicht verfügbar.');
}
$user = requireUser();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $currentPassword = $_POST['current_password'] ?? null;
    $newPassword = $_POST['new_password'] ?? null;
    $confirmation = $_POST['confirm_password'] ?? null;
    unset($_POST['current_password'], $_POST['new_password'], $_POST['confirm_password']);
    if (!is_string($currentPassword) || !validPasswordInput($currentPassword)
        || !is_string($newPassword) || !validNewPasswordInput($newPassword)
        || !is_string($confirmation) || $newPassword !== $confirmation) {
        http_response_code(422);
        $error = 'Bitte das bisherige Passwort und ein neues Passwort mit mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes eingeben. Die Wiederholung muss übereinstimmen.';
    } else {
        try {
            $store = userStore();
            if (!$store->allowAttempt('password-change:' . $user['id'], (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                http_response_code(429);
                $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.';
            } else {
                $account = $store->findById($user['id'], true);
                if ($account === null || !activeUser($account)
                    || $account['auth_version'] !== $_SESSION['auth']['version']) {
                    destroyLocalSession();
                    redirectTo('login.php?expired=1');
                }
                if (!password_verify($currentPassword, $account['password_hash'])) {
                    http_response_code(403);
                    $error = 'Das bisherige Passwort stimmt nicht.';
                } elseif (password_verify($newPassword, $account['password_hash'])) {
                    http_response_code(422);
                    $error = 'Bitte ein anderes neues Passwort wählen.';
                } else {
                    $version = $account['auth_version'];
                    $changed = $store->changePassword($user['id'], $version, $account['password_hash'], password_hash($newPassword, PASSWORD_DEFAULT));
                    unset($currentPassword, $newPassword, $confirmation, $account);
                    if (!$changed) {
                        destroyLocalSession();
                        redirectTo('login.php?expired=1');
                    }
                    // Keep this device signed in; all other sessions retain the old version.
                    $auth = $_SESSION['auth'];
                    $auth['version'] = $version + 1;
                    $auth['last_activity'] = time();
                    if (!session_regenerate_id(true)) {
                        destroyLocalSession();
                        redirectTo('login.php?password_changed=1');
                    }
                    $_SESSION = ['auth' => $auth, 'csrf' => bin2hex(random_bytes(32)), 'password_changed' => true];
                    redirectTo('profile.php');
                }
            }
        } catch (RuntimeException) {
            http_response_code(503);
            $error = 'Die Passwortänderung konnte nicht bestätigt werden. Bitte später erneut anmelden und gegebenenfalls das neue Passwort verwenden.';
        }
    }
    unset($currentPassword, $newPassword, $confirmation, $account);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort ändern · PHP und PostgreSQL</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">Dein Benutzerkonto</p>
    <h1>Passwort ändern</h1>
    <p>Nach dem Speichern bleibst du hier angemeldet. Andere bestehende Sitzungen werden beim nächsten geschützten Zugriff abgemeldet.</p>
    <section aria-labelledby="password-title">
        <h2 id="password-title">Ein neues Passwort festlegen</h2>
        <p id="password-help">Mindestens 12 Zeichen, höchstens 72 UTF-8-Bytes. Umlaute und Emojis benötigen mehrere Bytes.</p>
        <?php if ($error !== null): ?>
            <p role="alert" class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="password.php">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <label for="current-password">Bisheriges Passwort</label>
            <input id="current-password" name="current_password" type="password" autocomplete="current-password" maxlength="72" required>
            <label for="new-password">Neues Passwort</label>
            <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="password-help" required>
            <label for="confirm-password">Neues Passwort wiederholen</label>
            <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <button type="submit">Passwort ändern</button>
        </form>
    </section>
    <p><a href="profile.php">Zurück zu deinem Profil</a></p>
</main>
</body>
</html>
