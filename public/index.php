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
        $result = checkSupabase();
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
    <title>Supabase lernen · Verbindungstest</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">PHP + Supabase · Schritt 2</p>
    <h1>Die Verbindung steht am Anfang.</h1>
    <p>Diese Testseite läuft bei World4You. Mit dem Button fragt PHP die Anmeldeeinstellungen deines Supabase-Projekts über HTTPS ab.</p>
    <section aria-labelledby="session-title">
        <h2 id="session-title">1. PHP-Session</h2>
        <p>Seitenaufrufe in dieser Sitzung: <strong><?= $visits ?></strong></p>
        <p>Lade die Seite erneut. Steigt die Zahl, erkennt PHP dein Session-Cookie wieder. Das ist noch keine Benutzeranmeldung.</p>
    </section>
    <section aria-labelledby="connection-title">
        <h2 id="connection-title">2. Supabase erreichen</h2>
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
                <?php if ($result['ok']): ?>
                    <ul>
                        <li>E-Mail-Login: <?= $result['email_enabled'] ? 'aktiviert' : 'deaktiviert' ?></li>
                        <li>E-Mail-Bestätigung: <?= $result['confirmation_required'] ? 'erforderlich' : 'nicht erforderlich' ?></li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <section aria-labelledby="learn-title">
        <h2 id="learn-title">Was passiert dabei?</h2>
        <ol>
            <li>Dein Browser sendet das Formular an PHP.</li>
            <li>PHP sendet eine verschlüsselte Anfrage an Supabase.</li>
            <li>Supabase antwortet mit JSON; PHP zeigt nur das Prüfergebnis an.</li>
        </ol>
        <p>Als Nächstes ergänzen wir einen Login und eine geschützte Profilseite.</p>
    </section>
</main>
</body>
</html>
