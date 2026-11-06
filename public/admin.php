<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
requireAdminPageMethods(['GET']);
$actor = requireAdmin();
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (strlen($search) > 400 || preg_match('/\A[^\p{Cc}]{0,100}\z/uD', $search) !== 1) {
    http_response_code(422);
    exit('Bitte höchstens 100 Zeichen für die Suche eingeben.');
}
$page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
if ($page === false) {
    http_response_code(400);
    exit('Ungültige Seitennummer.');
}
try {
    $result = adminStore()->adminList($actor['id'], $_SESSION['auth']['version'], $search, ($page - 1) * 25);
} catch (RuntimeException $exception) {
    http_response_code(adminErrorStatus($exception));
    exit(escape(adminErrorMessage($exception)));
}
$message = $_SESSION['admin_message'] ?? null;
unset($_SESSION['admin_message']);
$pages = max(1, (int) ceil($result['total'] / 25));
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzerverwaltung</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="admin-layout">
    <p class="eyebrow">Administration</p>
    <h1>Benutzerverwaltung</h1>
    <p><a href="admin-user.php">Neuen Benutzer anlegen</a> · <a href="profile.php">Zu deinem Profil</a></p>
    <?php if (is_string($message)): ?><p role="status" class="result success"><?= escape($message) ?></p><?php endif; ?>
    <section aria-labelledby="users-title">
        <h2 id="users-title">Alle Benutzer</h2>
        <form method="get" action="admin.php" role="search">
            <label for="search">E-Mail oder Anzeigename suchen</label>
            <input id="search" name="q" type="search" value="<?= escape($search) ?>">
            <button type="submit">Suchen</button>
        </form>
        <p><?= $result['total'] ?> Treffer · Seite <?= $page ?> von <?= $pages ?></p>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Benutzerliste">
            <table>
                <thead><tr><th scope="col">E-Mail</th><th scope="col">Anzeigename</th><th scope="col">Rolle</th><th scope="col">Status</th><th scope="col">Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($result['users'] as $entry): ?>
                    <tr>
                        <td><?= escape($entry['email']) ?><?= $entry['id'] === $actor['id'] ? ' (du)' : '' ?></td>
                        <td><?= escape($entry['display_name']) ?></td>
                        <td><?= $entry['is_admin'] ? 'Admin' : 'Benutzer' ?></td>
                        <td><?= $entry['is_active'] ? 'Aktiv' : 'Deaktiviert' ?></td>
                        <td class="row-actions"><a href="admin-user.php?id=<?= escape($entry['id']) ?>">Bearbeiten</a>
                            <?php if ($entry['id'] !== $actor['id']): ?>
                                <a href="admin-delete.php?id=<?= escape($entry['id']) ?>">Löschen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($result['users'] === []): ?><tr><td colspan="5">Keine Benutzer gefunden.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav aria-label="Seiten der Benutzerliste">
            <?php if ($page > 1): ?><a href="admin.php?<?= escape(http_build_query(['q' => $search, 'page' => $page - 1])) ?>">Vorherige Seite</a><?php endif; ?>
            <?php if ($page < $pages): ?><a href="admin.php?<?= escape(http_build_query(['q' => $search, 'page' => $page + 1])) ?>">Nächste Seite</a><?php endif; ?>
        </nav>
    </section>
</main>
</body>
</html>
