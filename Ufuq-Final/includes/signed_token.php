<?php
// Signed reset links: nothing is stored in the database.
//
// A link looks like  <payload>.<signature>
//   payload   = member id + expiry time + purpose (base64url JSON)
//   signature = HMAC of the payload, the secret APP_KEY and the member's CURRENT password hash
//
// - Forging or editing a link breaks the signature.
// - The expiry lives inside the signed payload, so it cannot be extended.
// - Once the password changes, the signature no longer matches, so a link works only once.

require_once __DIR__ . '/env.php';

function token_key(): string
{
    $key = (string) env('APP_KEY', '');
    if (strlen($key) < 32) {
        throw new RuntimeException('APP_KEY is missing or too short. Set it in .env.');
    }
    return $key;
}

function token_b64_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function token_b64_decode(string $data)
{
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function token_signature(string $payload, string $passwordHash): string
{
    return hash_hmac('sha256', $payload . '|' . hash('sha256', $passwordHash), token_key());
}

function token_ttl(): int
{
    return max(10, (int) env('RESET_TTL_SECONDS', 600));
}

function token_create(int $memberId, string $passwordHash, ?int $ttlSeconds = null): string
{
    $payload = token_b64_encode(json_encode([
        'm' => $memberId,
        'e' => time() + ($ttlSeconds ?? token_ttl()),
        'p' => 'reset',
    ]));

    return $payload . '.' . token_signature($payload, $passwordHash);
}

// Reads the member id from a link WITHOUT trusting it yet (needed to fetch the password hash).
function token_member_id(string $token): ?int
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $json = token_b64_decode($parts[0]);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    return (is_array($data) && isset($data['m']) && is_int($data['m'])) ? $data['m'] : null;
}

// True only if the signature matches, the purpose is "reset" and it has not expired.
function token_is_valid(string $token, string $passwordHash): bool
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$payload, $signature] = $parts;
    if (!hash_equals(token_signature($payload, $passwordHash), $signature)) {
        return false;
    }

    $json = token_b64_decode($payload);
    if ($json === false) {
        return false;
    }

    $data = json_decode($json, true);
    return is_array($data)
        && ($data['p'] ?? '') === 'reset'
        && isset($data['e']) && is_int($data['e'])
        && $data['e'] >= time();
}