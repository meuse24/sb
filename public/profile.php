<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Diese Aktion ist nicht verfügbar.');
}
$user = requireUser();
$displayName = $user['display_name'];
$error = null;
$saved = ($_SESSION['profile_saved'] ?? false) === true;
unset($_SESSION['profile_saved']);
$passwordChanged = ($_SESSION['password_changed'] ?? false) === true;
unset($_SESSION['password_changed']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $input = $_POST['display_name'] ?? null;
    if (!is_string($input) || strlen($input) > 400
        || preg_match('/\A[^\p{Cc}]{0,100}\z/uD', trim($input)) !== 1) {
        http_response_code(422);
        $error = 'Bitte höchstens 100 Zeichen ohne Zeilenumbrüche oder Steuerzeichen eingeben.';
        if (is_string($input) && strlen($input) <= 400) {
            $displayName = $input;
        }
    } else {
        $displayName = trim($input);
        try {
            // No user ID or additional account fields are accepted from the form.
            if (!userStore()->updateDisplayName($user['id'], $_SESSION['auth']['version'], $displayName)) {
                destroyLocalSession();
                redirectTo('login.php?expired=1');
            }
            $_SESSION['profile_saved'] = true;
            redirectTo('profile.php');
        } catch (RuntimeException) {
            http_response_code(503);
            $error = 'Dein Anzeigename konnte nicht gespeichert werden. Bitte später erneut versuchen.';
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Geschützter Bereich · PHP und PostgreSQL</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">PHP + PostgreSQL · Eigene Benutzerverwaltung</p>
    <h1>Du bist angemeldet.</h1>
    <?php if ($passwordChanged): ?>
        <p role="status" class="success result">Dein Passwort wurde geändert. Andere bestehende Sitzungen sind abgemeldet.</p>
    <?php endif; ?>
    <?php if ($saved): ?>
        <p role="status" class="success result">Dein Anzeigename wurde gespeichert.</p>
    <?php endif; ?>
    <section aria-labelledby="account-title">
        <h2 id="account-title">Dein Benutzerkonto</h2>
        <p>Anzeigename: <strong><?= escape($user['display_name']) ?></strong></p>
        <p>E-Mail-Adresse: <strong><?= escape($user['email']) ?></strong></p>
        <p>Benutzer-ID: <code><?= escape($user['id']) ?></code></p>
        <p>Nach 30 Minuten ohne Aufruf des geschützten Bereichs oder spätestens nach acht Stunden endet deine Anmeldung.</p>
        <p><a href="password.php">Passwort ändern</a></p>
        <?php if ($user['is_admin']): ?>
            <p><a href="admin.php">Benutzerverwaltung öffnen</a></p>
        <?php endif; ?>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <button type="submit">Abmelden</button>
        </form>
    </section>
    <section aria-labelledby="profile-title">
        <h2 id="profile-title">Anzeigenamen bearbeiten</h2>
        <p id="profile-help">Du kannst bis zu 100 Zeichen verwenden oder den Namen leer lassen.</p>
        <?php if ($error !== null): ?>
            <p id="profile-error" role="alert" class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="post" action="profile.php">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <label for="display-name">Anzeigename</label>
            <input id="display-name" name="display_name" type="text" autocomplete="nickname" value="<?= escape($displayName) ?>" aria-describedby="profile-help<?= $error !== null ? ' profile-error' : '' ?>"<?= $error !== null ? ' aria-invalid="true"' : '' ?>>
            <button type="submit">Anzeigenamen speichern</button>
        </form>
    </section>
    <p><a href="index.php">Zum Verbindungstest</a></p>
</main>
</body>
</html>
