<?php
// Email verification code for registration (users and companies).
// Nothing is saved in the database until the code is confirmed: the form data waits in the PHP session.

require_once __DIR__ . '/uploads.php';   // delete_files(), touch_files()

const VERIFY_TTL_SECONDS = 600;  // a code is valid for 10 minutes
const VERIFY_MAX_TRIES   = 5;    // wrong guesses allowed per code
const VERIFY_RESEND_WAIT = 30;   // seconds before a new code can be requested
const VERIFY_MAX_RESENDS = 5;    // new codes allowed per registration, then the user must register again

// The waiting registration, or null if there is none.
function verification_pending(): ?array
{
    $p = $_SESSION['pending_signup'] ?? null;
    return is_array($p) ? $p : null;
}

function verification_new_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Keeps the form data in the session and returns a fresh 6-digit code to email.
function verification_start(array $payload): string
{
    verification_clear(true); // an older unfinished registration is thrown away (with its uploaded files)

    $code = verification_new_code();

    $_SESSION['pending_signup'] = [
        'payload' => $payload,
        'code'    => $code,
        'expires' => time() + VERIFY_TTL_SECONDS,
        'tries'   => 0,
        'resends' => 0,
        'sent_at' => time(),
    ];

    return $code;
}

// Replaces the code with a new one (the old one stops working).
// Returns ['code' => ?string, 'wait' => int, 'limit' => bool]
function verification_resend(): array
{
    $p = verification_pending();
    if (!$p) {
        return ['code' => null, 'wait' => 0, 'limit' => false];
    }

    if ((int) $p['resends'] >= VERIFY_MAX_RESENDS) {
        return ['code' => null, 'wait' => 0, 'limit' => true];
    }

    $wait = VERIFY_RESEND_WAIT - (time() - (int) $p['sent_at']);
    if ($wait > 0) {
        return ['code' => null, 'wait' => $wait, 'limit' => false];
    }

    $code = verification_new_code();

    $_SESSION['pending_signup']['code']    = $code;
    $_SESSION['pending_signup']['expires'] = time() + VERIFY_TTL_SECONDS;
    $_SESSION['pending_signup']['tries']   = 0;
    $_SESSION['pending_signup']['resends'] = (int) $p['resends'] + 1;
    $_SESSION['pending_signup']['sent_at'] = time();

    // the uploaded files are still in use: keep them safe from the orphan cleanup
    touch_files([$p['payload']['pic'] ?? '', $p['payload']['doc'] ?? '', $p['payload']['logo'] ?? '']);

    return ['code' => $code, 'wait' => 0, 'limit' => false];
}

// 'ok' | 'wrong' | 'format' | 'expired' | 'locked' | 'none'
// 'expired' and 'locked' keep the registration: the user can ask for a new code.
function verification_check(string $input): string
{
    $p = verification_pending();
    if (!$p) {
        return 'none';
    }

    if ((int) $p['tries'] >= VERIFY_MAX_TRIES) {
        return 'locked';
    }

    if (time() > (int) $p['expires']) {
        return 'expired';
    }

    $input = trim($input);
    if (!preg_match('/^\d{6}$/', $input)) {
        return 'format'; // not counted as a try
    }

    if (hash_equals((string) $p['code'], $input)) {
        return 'ok';
    }

    $_SESSION['pending_signup']['tries'] = (int) $p['tries'] + 1;

    return ((int) $_SESSION['pending_signup']['tries'] >= VERIFY_MAX_TRIES) ? 'locked' : 'wrong';
}

function verification_tries_left(): int
{
    $p = verification_pending();
    return $p ? max(0, VERIFY_MAX_TRIES - (int) $p['tries']) : 0;
}

// Forgets the waiting registration. $deleteFiles = true also deletes the files uploaded for it.
function verification_clear(bool $deleteFiles): void
{
    $p = verification_pending();

    if ($p && $deleteFiles) {
        // delete_files() only accepts paths that look exactly like the ones store_file() creates.
        delete_files([$p['payload']['pic'] ?? '', $p['payload']['doc'] ?? '', $p['payload']['logo'] ?? '']);
    }

    unset($_SESSION['pending_signup']);
}