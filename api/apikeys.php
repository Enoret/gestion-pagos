<?php
/**
 * API keys para agentes IA (servidor MCP).
 *
 * - Formato: gp_<43 caracteres base64url>. Solo se muestra completa al crearla.
 * - Se guarda únicamente su hash SHA-256 en data/api_keys.json (nunca la key en claro).
 * - Permisos: "read" (consultar) o "write" (consultar + registrar/corregir/eliminar).
 * - Revocables: una key revocada deja de funcionar al instante.
 */

const API_KEY_PREFIX = 'gp_';
const API_KEYS_FILE  = __DIR__ . '/../data/api_keys.json';

function apikeys_load(): array {
    if (!file_exists(API_KEYS_FILE)) return [];
    $data = json_decode((string) file_get_contents(API_KEYS_FILE), true);
    return is_array($data) ? $data : [];
}

function apikeys_save(array $keys): bool {
    $dir = dirname(API_KEYS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ok = file_put_contents(
        API_KEYS_FILE,
        json_encode(array_values($keys), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
    if ($ok) @chmod(API_KEYS_FILE, 0600);
    return $ok;
}

/** Datos públicos de una key (sin hash). */
function apikeys_public(array $k): array {
    return [
        'id'           => $k['id'],
        'name'         => $k['name'],
        'scope'        => $k['scope'],
        'hint'         => API_KEY_PREFIX . '…' . $k['hint'],
        'created_at'   => $k['created_at'],
        'last_used_at' => $k['last_used_at'] ?? null,
        'revoked_at'   => $k['revoked_at'] ?? null,
        'active'       => empty($k['revoked_at']),
    ];
}

function apikeys_list(): array {
    return array_map('apikeys_public', apikeys_load());
}

/** Crea una key. Devuelve ['key' => <en claro, solo esta vez>, 'info' => datos públicos]. */
function apikeys_create(string $name, string $scope): array {
    $name = trim(mb_substr($name, 0, 60));
    if ($name === '') throw new InvalidArgumentException('El nombre de la key es obligatorio');
    if (!in_array($scope, ['read', 'write'], true)) throw new InvalidArgumentException('Permiso no válido');

    $raw = API_KEY_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $rec = [
        'id'           => bin2hex(random_bytes(6)),
        'name'         => $name,
        'scope'        => $scope,
        'hash'         => hash('sha256', $raw),
        'hint'         => substr($raw, -4),
        'created_at'   => date('c'),
        'last_used_at' => null,
        'revoked_at'   => null,
    ];
    $keys = apikeys_load();
    $keys[] = $rec;
    if (!apikeys_save($keys)) throw new RuntimeException('No se pudo guardar la key');
    return ['key' => $raw, 'info' => apikeys_public($rec)];
}

function apikeys_revoke(string $id): bool {
    $keys = apikeys_load();
    foreach ($keys as &$k) {
        if ($k['id'] === $id && empty($k['revoked_at'])) {
            $k['revoked_at'] = date('c');
            return apikeys_save($keys);
        }
    }
    return false;
}

/** Elimina definitivamente una key ya revocada (limpieza de la lista). */
function apikeys_delete(string $id): bool {
    $keys = apikeys_load();
    $new = array_filter($keys, fn($k) => !($k['id'] === $id && !empty($k['revoked_at'])));
    return count($new) !== count($keys) && apikeys_save($new);
}

/** Extrae la key de X-API-Key o Authorization: Bearer. */
function apikeys_from_request(): ?string {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($key === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) $key = trim(substr($auth, 7));
    }
    return ($key !== '' && str_starts_with($key, API_KEY_PREFIX)) ? $key : null;
}

/** Valida la key; devuelve sus datos públicos o null. Actualiza last_used_at (máx. 1 vez/min). */
function apikeys_verify(?string $raw): ?array {
    if (!$raw) return null;
    $hash = hash('sha256', $raw);
    $keys = apikeys_load();
    foreach ($keys as &$k) {
        if (hash_equals($k['hash'], $hash)) {
            if (!empty($k['revoked_at'])) return null;
            $last = $k['last_used_at'] ? strtotime($k['last_used_at']) : 0;
            if (time() - $last > 60) {
                $k['last_used_at'] = date('c');
                apikeys_save($keys);
            }
            return apikeys_public($k);
        }
    }
    return null;
}
