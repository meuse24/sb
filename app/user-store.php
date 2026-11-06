<?php
declare(strict_types=1);

interface UserStore
{
    public function findByEmail(string $email): ?array;
    public function findById(string $id, bool $includeHash = false): ?array;
    public function rehash(string $id, string $oldHash, string $newHash): void;
    public function allowAttempt(string $email, string $ip): bool;
    public function check(): void;
    public function create(string $email, string $hash, string $displayName): void;
    public function updateDisplayName(string $id, int $authVersion, string $displayName): bool;
    public function changePassword(string $id, int $authVersion, string $oldHash, string $newHash): bool;
    public function prepareRegistration(string $email, string $displayName, string $tokenHash): bool;
    public function cancelRegistration(string $tokenHash): void;
    public function confirmRegistration(string $tokenHash, string $passwordHash): bool;
    public function registerWithoutConfirmation(string $email, string $displayName, string $passwordHash): bool;
}

function userStore(): UserStore
{
    static $store = null;
    if ($store === null) {
        $path = dirname(__DIR__) . '/config/config.local.php';
        $config = is_file($path) ? require $path : [];
        $store = new SupabaseHttpUserStore($config);
    }
    return $store;
}

function checkDatabase(): array
{
    try {
        userStore()->check();
        return ['ok' => true, 'message' => 'Datenbankverbindung und Anwendungstabellen sind bereit.'];
    } catch (RuntimeException) {
        return ['ok' => false, 'message' => 'Die Datenbank ist noch nicht bereit oder momentan nicht erreichbar.'];
    }
}
