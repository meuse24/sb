<?php
declare(strict_types=1);

interface AdminUserStore
{
    public function adminList(string $actorId, int $actorVersion, string $search, int $offset): array;
    public function adminGet(string $actorId, int $actorVersion, string $targetId): array;
    public function adminCreate(string $actorId, int $actorVersion, array $input): array;
    public function adminUpdate(string $actorId, int $actorVersion, string $targetId, int $expectedVersion, array $input): array;
    public function adminDelete(string $actorId, int $actorVersion, string $targetId, int $expectedVersion): void;
}

final class AdminOperationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'forbidden' => 'Für diese Aktion fehlen dir die Admin-Rechte. Bitte erneut anmelden.',
            'not_found' => 'Dieser Benutzer wurde nicht gefunden.',
            'duplicate' => 'Diese E-Mail-Adresse wird bereits verwendet.',
            'conflict' => 'Das Konto wurde inzwischen geändert. Bitte die Seite neu laden.',
            'self_protection' => 'Du kannst dein eigenes Konto hier nicht löschen, deaktivieren oder herabstufen. Dein eigenes Passwort änderst du über „Passwort ändern“.',
            'last_admin' => 'Mindestens ein aktiver Admin muss erhalten bleiben.',
            'invalid' => 'Bitte die eingegebenen Benutzerdaten prüfen.',
            default => 'Die Benutzerverwaltung ist momentan nicht verfügbar.',
        });
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            'forbidden' => 403, 'not_found' => 404, 'invalid' => 422,
            'duplicate', 'conflict', 'self_protection', 'last_admin' => 409,
            default => 503,
        };
    }
}

function adminStore(): AdminUserStore
{
    $store = userStore();
    if (!$store instanceof AdminUserStore) {
        throw new RuntimeException('Admin-Datenzugriff fehlt.');
    }
    return $store;
}

function requireAdmin(): array
{
    $user = requireUser();
    if (!$user['is_admin']) {
        http_response_code(403);
        exit('Für die Benutzerverwaltung sind Admin-Rechte erforderlich.');
    }
    return $user;
}

function validUserId(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $value) === 1;
}

function requireAdminPageMethods(array $methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        http_response_code(405);
        exit('Diese Aktion ist nicht verfügbar.');
    }
}

function adminErrorStatus(RuntimeException $error): int
{
    return $error instanceof AdminOperationException ? $error->httpStatus() : 503;
}

function adminErrorMessage(RuntimeException $error): string
{
    return $error instanceof AdminOperationException ? $error->getMessage() : 'Die Benutzerverwaltung ist momentan nicht verfügbar. Bitte später erneut versuchen.';
}
