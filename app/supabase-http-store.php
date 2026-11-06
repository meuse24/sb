<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';

/** Only this adapter knows Supabase/PostgREST. Auth uses the UserStore contract. */
final class SupabaseHttpUserStore implements UserStore, AdminUserStore
{
    public function __construct(private array $config) {}

    private function request(string $method, string $path, ?array $payload = null, bool $returnRepresentation = false): ?array
    {
        $url = rtrim((string) ($this->config['supabase_url'] ?? ''), '/');
        $key = (string) ($this->config['supabase_secret_key'] ?? '');
        if (!extension_loaded('curl')
            || !preg_match('~\Ahttps://[a-z0-9-]+\.supabase\.co\z~D', $url)
            || !preg_match('/\Asb_secret_[A-Za-z0-9_-]+\z/D', $key)) {
            throw new RuntimeException('Server-Konfiguration fehlt.');
        }
        $headers = ['Accept: application/json', 'apikey: ' . $key];
        if ($returnRepresentation) {
            $headers[] = 'Prefer: return=representation';
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $handle = curl_init($url . '/rest/v1/' . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Datenbankanfrage fehlgeschlagen.');
        }
        try {
            $data = $body === '' ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Ungültige Datenbankantwort.');
        }
        return is_array($data) ? $data : null;
    }

    private function find(array $query, bool $includeHash): ?array
    {
        $query['select'] = 'id,email,display_name,is_active,is_admin,auth_version' . ($includeHash ? ',password_hash' : '');
        $query['limit'] = 1;
        $rows = $this->request('GET', 'users?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Ungültige Benutzerantwort.');
        }
        if ($rows === []) {
            return null;
        }
        $user = $rows[0];
        if (!is_array($user) || !is_string($user['id'] ?? null)
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $user['id'])
            || !is_string($user['email'] ?? null) || !is_string($user['display_name'] ?? null)
            || !is_bool($user['is_active'] ?? null) || !is_bool($user['is_admin'] ?? null) || !is_int($user['auth_version'] ?? null)
            || $user['auth_version'] < 1 || ($includeHash && !is_string($user['password_hash'] ?? null))) {
            throw new RuntimeException('Ungültige Benutzerdaten.');
        }
        return $user;
    }

    public function findByEmail(string $email): ?array
    {
        return $this->find(['email' => 'eq.' . $email], true);
    }

    public function findById(string $id, bool $includeHash = false): ?array
    {
        return $this->find(['id' => 'eq.' . $id], $includeHash);
    }

    public function rehash(string $id, string $oldHash, string $newHash): void
    {
        $query = http_build_query(['id' => 'eq.' . $id, 'password_hash' => 'eq.' . $oldHash], '', '&', PHP_QUERY_RFC3986);
        $this->request('PATCH', 'users?' . $query, ['password_hash' => $newHash]);
    }

    public function allowAttempt(string $email, string $ip): bool
    {
        $result = $this->request('POST', 'rpc/consume_login_attempt', [
            'p_email_bucket' => hash('sha256', 'email:' . $email),
            'p_ip_bucket' => hash('sha256', 'ip:' . $ip),
        ]);
        if (!is_array($result) || !is_bool($result['allowed'] ?? null)) {
            throw new RuntimeException('Ungültige Anmeldebegrenzung.');
        }
        return $result['allowed'];
    }

    public function check(): void
    {
        // Check columns and grants without downloading hashes or user data.
        foreach (['users?select=id,email,password_hash,display_name,is_active,is_admin,auth_version,email_confirmed_at&limit=0',
            'login_attempts?select=bucket,attempts,window_started&limit=0',
            'registration_requests?select=token_hash,email,expires_at&limit=0'] as $path) {
            if ($this->request('GET', $path) !== []) {
                throw new RuntimeException('Unerwartete Tabellenantwort.');
            }
        }
    }

    public function create(string $email, string $hash, string $displayName): void
    {
        $this->request('POST', 'users', ['email' => $email, 'password_hash' => $hash, 'display_name' => $displayName]);
    }

    public function updateDisplayName(string $id, int $authVersion, string $displayName): bool
    {
        // Bind the update to the verified session, including concurrent revocation.
        $query = http_build_query([
            'id' => 'eq.' . $id, 'is_active' => 'eq.true',
            'auth_version' => 'eq.' . $authVersion, 'select' => 'id',
        ], '', '&', PHP_QUERY_RFC3986);
        $rows = $this->request('PATCH', 'users?' . $query, ['display_name' => $displayName], true);
        if ($rows === []) {
            return false;
        }
        if (!is_array($rows) || count($rows) !== 1 || ($rows[0]['id'] ?? null) !== $id) {
            throw new RuntimeException('Ungültige Speicherbestätigung.');
        }
        return true;
    }

    public function changePassword(string $id, int $authVersion, string $oldHash, string $newHash): bool
    {
        if ($authVersion < 1 || $authVersion >= 2147483647) {
            throw new RuntimeException('Ungültige Session-Version.');
        }
        // One atomic conditional update: concurrent changes cannot reuse the old hash.
        $query = http_build_query([
            'id' => 'eq.' . $id, 'is_active' => 'eq.true', 'auth_version' => 'eq.' . $authVersion,
            'password_hash' => 'eq.' . $oldHash, 'select' => 'id,auth_version',
        ], '', '&', PHP_QUERY_RFC3986);
        $rows = $this->request('PATCH', 'users?' . $query, [
            'password_hash' => $newHash, 'auth_version' => $authVersion + 1,
        ], true);
        if ($rows === []) {
            return false;
        }
        if (!is_array($rows) || count($rows) !== 1 || ($rows[0]['id'] ?? null) !== $id
            || ($rows[0]['auth_version'] ?? null) !== $authVersion + 1) {
            throw new RuntimeException('Ungültige Passwortbestätigung.');
        }
        return true;
    }

    public function prepareRegistration(string $email, string $displayName, string $tokenHash): bool
    {
        $result = $this->request('POST', 'rpc/prepare_registration', [
            'p_email' => $email, 'p_display_name' => $displayName, 'p_token_hash' => $tokenHash,
        ]);
        if (!is_bool($result['accepted'] ?? null)) {
            throw new RuntimeException('Ungültige Registrierungsantwort.');
        }
        return $result['accepted'];
    }

    public function cancelRegistration(string $tokenHash): void
    {
        $query = http_build_query(['token_hash' => 'eq.' . $tokenHash], '', '&', PHP_QUERY_RFC3986);
        $this->request('DELETE', 'registration_requests?' . $query);
    }

    public function confirmRegistration(string $tokenHash, string $passwordHash): bool
    {
        $result = $this->request('POST', 'rpc/confirm_registration', [
            'p_token_hash' => $tokenHash, 'p_password_hash' => $passwordHash,
        ]);
        if (!is_bool($result['confirmed'] ?? null)) {
            throw new RuntimeException('Ungültige Bestätigungsantwort.');
        }
        return $result['confirmed'];
    }

    public function registerWithoutConfirmation(string $email, string $displayName, string $passwordHash): bool
    {
        $result = $this->request('POST', 'rpc/register_user', [
            'p_email' => $email, 'p_display_name' => $displayName, 'p_password_hash' => $passwordHash,
        ]);
        if (!is_bool($result['created'] ?? null)) {
            throw new RuntimeException('Ungültige Registrierungsantwort.');
        }
        return $result['created'];
    }

    private function adminRequest(string $operation, string $actorId, int $actorVersion, array $input = []): array
    {
        $result = $this->request('POST', 'rpc/admin_' . $operation, [
            ...$input, 'p_actor_id' => $actorId, 'p_actor_version' => $actorVersion,
        ]);
        if (($result['ok'] ?? null) === false) {
            throw new AdminOperationException(is_string($result['error'] ?? null) ? $result['error'] : 'unavailable');
        }
        if (($result['ok'] ?? null) !== true) {
            throw new RuntimeException('Ungültige Admin-Antwort.');
        }
        return $result;
    }

    private function adminUser(mixed $user): array
    {
        if (!is_array($user) || !validUserId($user['id'] ?? null) || !is_string($user['email'] ?? null)
            || !is_string($user['display_name'] ?? null) || !is_bool($user['is_admin'] ?? null)
            || !is_bool($user['is_active'] ?? null) || !is_int($user['auth_version'] ?? null)
            || !is_string($user['created_at'] ?? null) || !array_key_exists('email_confirmed_at', $user)
            || ($user['email_confirmed_at'] !== null && !is_string($user['email_confirmed_at']))) {
            throw new RuntimeException('Ungültige Admin-Benutzerdaten.');
        }
        return $user;
    }

    public function adminList(string $actorId, int $actorVersion, string $search, int $offset): array
    {
        $result = $this->adminRequest('list_users', $actorId, $actorVersion, ['p_search' => $search, 'p_offset' => $offset]);
        if (!is_array($result['users'] ?? null) || !array_is_list($result['users']) || !is_int($result['total'] ?? null)) {
            throw new RuntimeException('Ungültige Benutzerliste.');
        }
        return ['users' => array_map($this->adminUser(...), $result['users']), 'total' => $result['total']];
    }

    public function adminGet(string $actorId, int $actorVersion, string $targetId): array
    {
        return $this->adminUser($this->adminRequest('get_user', $actorId, $actorVersion, ['p_target_id' => $targetId])['user'] ?? null);
    }

    public function adminCreate(string $actorId, int $actorVersion, array $input): array
    {
        return $this->adminUser($this->adminRequest('create_user', $actorId, $actorVersion, $input)['user'] ?? null);
    }

    public function adminUpdate(string $actorId, int $actorVersion, string $targetId, int $expectedVersion, array $input): array
    {
        return $this->adminUser($this->adminRequest('update_user', $actorId, $actorVersion, [
            ...$input, 'p_target_id' => $targetId, 'p_expected_version' => $expectedVersion,
        ])['user'] ?? null);
    }

    public function adminDelete(string $actorId, int $actorVersion, string $targetId, int $expectedVersion): void
    {
        $this->adminRequest('delete_user', $actorId, $actorVersion, ['p_target_id' => $targetId, 'p_expected_version' => $expectedVersion]);
    }
}
