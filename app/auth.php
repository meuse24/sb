<?php
declare(strict_types=1);

const AUTH_IDLE_SECONDS = 1800;
const AUTH_MAX_SECONDS = 28800;

function redirectTo(string $page): never
{
    header('Location: ' . $page, true, 303);
    exit;
}

function destroyLocalSession(): void
{
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
    session_destroy();
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function validPasswordInput(string $password): bool
{
    // PASSWORD_DEFAULT currently uses bcrypt: never truncate at 72 bytes.
    return $password !== '' && strlen($password) <= 72
        && !str_contains($password, "\0") && preg_match('//u', $password) === 1;
}

function validNewPasswordInput(string $password): bool
{
    return validPasswordInput($password) && preg_match('/\A.{12,}\z/usD', $password) === 1;
}

function activeUser(array $user): bool
{
    return in_array($user['is_active'] ?? null, [true, 1, '1', 't'], true);
}

/** Password checks and PHP sessions are independent of the storage provider. */
function attemptLogin(string $email, string $password, string $ip): array
{
    try {
        $store = userStore();
        if (!$store->allowAttempt($email, $ip)) {
            return ['status' => 429, 'message' => 'Zu viele Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.'];
        }
        $user = $store->findByEmail($email);
        // Do an expensive hash check for unknown accounts as well.
        $hash = $user['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $matches = password_verify($password, $hash);
        if (!$matches || $user === null || !activeUser($user)) {
            return ['status' => 401, 'message' => 'Anmeldung fehlgeschlagen. Bitte E-Mail-Adresse und Passwort prüfen.'];
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $store->rehash($user['id'], $hash, password_hash($password, PASSWORD_DEFAULT));
        }
        if (!session_regenerate_id(true)) {
            destroyLocalSession();
            http_response_code(503);
            exit('Die Anmeldung konnte nicht sicher abgeschlossen werden.');
        }
        $_SESSION = [
            'csrf' => bin2hex(random_bytes(32)),
            'auth' => [
                'user_id' => $user['id'], 'version' => (int) $user['auth_version'],
                'created_at' => time(), 'last_activity' => time(),
            ],
        ];
        return ['status' => 200, 'message' => 'Anmeldung erfolgreich.'];
    } catch (RuntimeException) {
        return ['status' => 503, 'message' => 'Die Anmeldung ist momentan nicht verfügbar. Bitte später erneut versuchen.'];
    }
}

function currentUser(): ?array
{
    $auth = $_SESSION['auth'] ?? null;
    if (!is_array($auth)) {
        return null;
    }
    if (!is_string($auth['user_id'] ?? null) || !is_int($auth['version'] ?? null)
        || !is_int($auth['last_activity'] ?? null) || !is_int($auth['created_at'] ?? null)
        || time() - $auth['last_activity'] >= AUTH_IDLE_SECONDS
        || time() - $auth['created_at'] >= AUTH_MAX_SECONDS) {
        destroyLocalSession();
        redirectTo('login.php?expired=1');
    }
    try {
        $user = userStore()->findById($auth['user_id']);
    } catch (RuntimeException) {
        http_response_code(503);
        exit('Die Anmeldung kann gerade nicht geprüft werden. Bitte später erneut versuchen.');
    }
    if ($user === null || !activeUser($user) || (int) $user['auth_version'] !== $auth['version']) {
        destroyLocalSession();
        redirectTo('login.php?expired=1');
    }
    $_SESSION['auth']['last_activity'] = time();
    return ['id' => $user['id'], 'email' => $user['email'], 'display_name' => $user['display_name'], 'is_admin' => $user['is_admin']];
}

function requireUser(): array
{
    $user = currentUser();
    if ($user === null) {
        redirectTo('login.php');
    }
    return $user;
}
