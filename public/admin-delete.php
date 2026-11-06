<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
requireAdminPageMethods(['GET', 'POST']);
$actor = requireAdmin();
$id = $_GET['id'] ?? null;
if (!validUserId($id)) {
    http_response_code(400);
    exit('Ungültige Benutzer-ID.');
}
$id = strtolower($id);
try {
    $target = adminStore()->adminGet($actor['id'], $_SESSION['auth']['version'], $id);
} catch (RuntimeException $exception) {
    http_response_code(adminErrorStatus($exception));
    exit(escape(adminErrorMessage($exception)));
}
$isSelf = $id === $actor['id'];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $version = filter_var($_POST['expected_version'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if (($_POST['confirm_delete'] ?? '') !== '1' || $version === false) {
        http_response_code(422);
        $error = 'Bitte das endgültige Löschen ausdrücklich bestätigen.';
    } else {
        try {
            adminStore()->adminDelete($actor['id'], $_SESSION['auth']['version'], $id, $version);
            $_SESSION['admin_message'] = 'Benutzer wurde gelöscht.';
            redirectTo('admin.php');
        } catch (RuntimeException $exception) {
            http_response_code(adminErrorStatus($exception));
            if ($exception instanceof AdminOperationException && $exception->reason === 'forbidden') exit(escape($exception->getMessage()));
            $error = adminErrorMessage($exception);
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzer löschen</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">Administration</p>
    <h1>Benutzer löschen</h1>
    <section aria-labelledby="delete-title">
        <h2 id="delete-title"><?= escape($target['email']) ?></h2>
        <p>Anzeigename: <?= escape($target['display_name']) ?></p>
        <p>Das Konto wird endgültig gelöscht und seine bestehenden Sitzungen verlieren den Zugang. Zum vorübergehenden Sperren kannst du es stattdessen bearbeiten und deaktivieren.</p>
        <?php if ($error !== null): ?><p role="alert" class="error"><?= escape($error) ?></p><?php endif; ?>
        <?php if ($isSelf): ?>
            <p>Dein eigenes Admin-Konto kannst du hier nicht löschen.</p>
        <?php else: ?>
            <form method="post" action="admin-delete.php?id=<?= escape($id) ?>">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="expected_version" value="<?= (int) $target['auth_version'] ?>">
                <label class="check-label"><input name="confirm_delete" type="checkbox" value="1" required> Diesen Benutzer endgültig löschen</label>
                <button type="submit" class="danger">Benutzer endgültig löschen</button>
            </form>
        <?php endif; ?>
    </section>
    <p><a href="admin.php">Abbrechen und zur Benutzerliste</a></p>
</main>
</body>
</html>
