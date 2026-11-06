<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403);
        $error = 'Das Formular ist abgelaufen. Bitte die Seite neu laden.';
    } elseif (time() - (int) ($_SESSION['last_check'] ?? 0) < 10) {
        http_response_code(429);
        $error = 'Bitte vor dem nächsten Verbindungstest zehn Sekunden warten.';
    } else {
        $_SESSION['last_check'] = time();
        $result = checkDatabase();
    }
}
$_SESSION['visits'] = (int) ($_SESSION['visits'] ?? 0) + 1;
$visits = $_SESSION['visits'];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHP und PostgreSQL · Verbindungstest</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">PHP + PostgreSQL · Verbindungstest</p>
    <h1>Die Verbindung steht am Anfang.</h1>
    <p>Mit dem Button prüft PHP die Verbindung zur Datenbank und die Tabellen unserer Benutzerverwaltung.</p>
    <p><a href="login.php">Anmelden und geschützten Bereich öffnen</a></p>
    <section aria-labelledby="session-title">
        <h2 id="session-title">1. PHP-Session</h2>
        <p>Seitenaufrufe in dieser Sitzung: <strong><?= $visits ?></strong></p>
        <p>Lade die Seite erneut. Steigt die Zahl, erkennt PHP dein Session-Cookie wieder. Das ist noch keine Benutzeranmeldung.</p>
    </section>
    <section aria-labelledby="connection-title">
        <h2 id="connection-title">2. Datenbank erreichen</h2>
        <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <button type="submit">Verbindung testen</button>
        </form>
        <?php if ($error !== null): ?>
            <p role="alert" class="error"><?= escape($error) ?></p>
        <?php endif; ?>
        <?php if ($result !== null): ?>
            <div role="status" class="result <?= $result['ok'] ? 'success' : 'error' ?>">
                <p><?= escape($result['message']) ?></p>
            </div>
        <?php endif; ?>
    </section>
    <section aria-labelledby="learn-title">
        <h2 id="learn-title">Was passiert dabei?</h2>
        <ol>
            <li>Dein Browser sendet das Formular an PHP.</li>
            <li>PHP fragt die Anwendungstabellen in PostgreSQL ab.</li>
            <li>PHP zeigt nur das Prüfergebnis an, keine Benutzerdaten oder Passwörter.</li>
        </ol>
        <p>Über die Anmeldung erreichst du deinen geschützten Bereich und kannst dort deinen Anzeigenamen bearbeiten.</p>
    </section>
</main>
</body>
</html>
