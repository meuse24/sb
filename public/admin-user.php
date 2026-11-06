<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
requireAdminPageMethods(['GET', 'POST']);
$actor = requireAdmin();
$id = $_GET['id'] ?? '';
if ($id !== '' && !validUserId($id)) {
    http_response_code(400);
    exit('Ungültige Benutzer-ID.');
}
$id = strtolower($id);
$isNew = $id === '';
$isSelf = !$isNew && $id === $actor['id'];
$record = ['email' => '', 'display_name' => '', 'is_active' => true, 'is_admin' => false, 'auth_version' => 1];
if (!$isNew) {
    try {
        $record = adminStore()->adminGet($actor['id'], $_SESSION['auth']['version'], $id);
    } catch (RuntimeException $exception) {
        http_response_code(adminErrorStatus($exception));
        exit(escape(adminErrorMessage($exception)));
    }
}
$values = $record;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $emailInput = $_POST['email'] ?? null;
    $nameInput = $_POST['display_name'] ?? null;
    $password = $_POST['password'] ?? '';
    $confirmation = $_POST['confirm_password'] ?? '';
    unset($_POST['password'], $_POST['confirm_password']);
    $values['email'] = is_string($emailInput) ? normalizeEmail($emailInput) : '';
    $values['display_name'] = is_string($nameInput) && strlen($nameInput) <= 400 ? trim($nameInput) : '';
    $values['is_active'] = ($_POST['is_active'] ?? '') === '1';
    $values['is_admin'] = ($_POST['is_admin'] ?? '') === '1';
    $expectedVersion = filter_var($_POST['expected_version'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    $validPassword = is_string($password) && is_string($confirmation)
        && (($password === '' && $confirmation === '' && !$isNew)
            || (validNewPasswordInput($password) && $password === $confirmation));
    if (!is_string($emailInput) || strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)
        || !is_string($nameInput) || strlen($nameInput) > 400 || preg_match('/\A[^\p{Cc}]{0,100}\z/uD', $values['display_name']) !== 1
        || (isset($_POST['is_active']) && $_POST['is_active'] !== '1')
        || (isset($_POST['is_admin']) && $_POST['is_admin'] !== '1')
        || !$validPassword || (!$isNew && $expectedVersion === false)) {
        http_response_code(422);
        $error = 'Bitte E-Mail, Anzeigename und Passwort prüfen. Neue Passwörter benötigen mindestens 12 Zeichen, höchstens 72 UTF-8-Bytes und eine passende Wiederholung.';
    } else {
        try {
            $input = [
                'p_email' => $values['email'], 'p_display_name' => $values['display_name'],
                'p_is_active' => $values['is_active'], 'p_is_admin' => $values['is_admin'],
                'p_password_hash' => $password === '' ? null : password_hash($password, PASSWORD_DEFAULT),
            ];
            unset($password, $confirmation);
            if ($isNew) {
                adminStore()->adminCreate($actor['id'], $_SESSION['auth']['version'], $input);
            } else {
                adminStore()->adminUpdate($actor['id'], $_SESSION['auth']['version'], $id, $expectedVersion, $input);
            }
            if ($isSelf) {
                destroyLocalSession();
                redirectTo('login.php?account_updated=1');
            }
            $_SESSION['admin_message'] = $isNew ? 'Benutzer wurde angelegt.' : 'Benutzer wurde gespeichert. Bisherige Sitzungen dieses Kontos wurden beendet.';
            redirectTo('admin.php');
        } catch (RuntimeException $exception) {
            http_response_code(adminErrorStatus($exception));
            if ($exception instanceof AdminOperationException && $exception->reason === 'forbidden') {
                exit(escape($exception->getMessage()));
            }
            $error = adminErrorMessage($exception);
            if ($exception instanceof AdminOperationException && $exception->reason === 'conflict') $values = $record;
        }
    }
    unset($password, $confirmation, $input);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'Benutzer anlegen' : 'Benutzer bearbeiten' ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <p class="eyebrow">Administration</p>
    <h1><?= $isNew ? 'Benutzer anlegen' : 'Benutzer bearbeiten' ?></h1>
    <section aria-labelledby="edit-title">
        <h2 id="edit-title">Kontodaten</h2>
        <?php if ($error !== null): ?><p role="alert" class="error"><?= escape($error) ?></p><?php endif; ?>
        <?php if (!$isNew): ?>
            <p>Benutzer-ID: <code><?= escape($id) ?></code></p>
            <p>E-Mail bestätigt: <?= $record['email_confirmed_at'] === null ? 'Nein' : 'Ja' ?>. Eine Änderung der E-Mail hebt eine vorhandene Bestätigung auf.</p>
            <p>Speichern beendet bestehende Sitzungen dieses Kontos. Nach Änderungen am eigenen Konto meldest du dich erneut an.</p>
        <?php endif; ?>
        <form method="post" action="admin-user.php<?= !$isNew ? '?id=' . escape($id) : '' ?>">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <?php if (!$isNew): ?><input type="hidden" name="expected_version" value="<?= (int) $record['auth_version'] ?>"><?php endif; ?>
            <label for="email">E-Mail-Adresse</label>
            <input id="email" name="email" type="email" autocomplete="off" maxlength="254" required value="<?= escape($values['email']) ?>">
            <label for="display-name">Anzeigename</label>
            <input id="display-name" name="display_name" type="text" value="<?= escape($values['display_name']) ?>">
            <?php if ($isSelf): ?>
                <input type="hidden" name="is_active" value="1"><input type="hidden" name="is_admin" value="1">
                <p>Dein eigenes Konto bleibt aktiv und behält seine Admin-Rechte.</p>
                <p><a href="password.php">Eigenes Passwort ändern</a></p>
            <?php else: ?>
                <label class="check-label"><input name="is_active" type="checkbox" value="1"<?= $values['is_active'] ? ' checked' : '' ?>> Konto aktiv</label>
                <label class="check-label"><input name="is_admin" type="checkbox" value="1"<?= $values['is_admin'] ? ' checked' : '' ?>> Admin-Rechte</label>
                <p><?= $isNew ? 'Passwort: mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes.' : 'Passwortfelder leer lassen, um das bisherige Passwort zu behalten. Ein neues Passwort benötigt mindestens 12 Zeichen und höchstens 72 UTF-8-Bytes.' ?></p>
                <label for="password"><?= $isNew ? 'Passwort' : 'Neues Passwort (optional)' ?></label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72"<?= $isNew ? ' required' : '' ?>>
                <label for="confirm-password">Passwort wiederholen</label>
                <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="72"<?= $isNew ? ' required' : '' ?>>
            <?php endif; ?>
            <button type="submit"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
        </form>
    </section>
    <p><a href="admin.php">Zur Benutzerliste</a></p>
</main>
</body>
</html>
