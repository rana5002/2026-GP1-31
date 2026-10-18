<?php
// Everything about uploaded files lives here (one place):
//   check_file()            checks an uploaded file (type and size) before anything is saved
//   store_file()            saves it in the uploads folder and returns the path to keep in the database
//   delete_files()          deletes files that store_file() created
//   touch_files()           marks files as "still in use" (keeps them safe from the cleanup)
//   cleanup_orphan_uploads() deletes old files that no database row points to
// Used by signup.php and verification.php.

// The folders inside uploads/ used by the registration.
const UPLOAD_FOLDERS = [
    'user-profile' => 'profile-pictures',   // user profile pictures (the same folder the profile page uses)
    'logos'        => 'logos',          // company logos
    'docs'         => 'docs',           // company verification documents (blocked from direct web access)
];

/** returns 'none' | 'bad' | 'ok' */
function check_file(string $key, array $exts, array $mimes): string {
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) return 'none';
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return 'bad';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) return 'bad';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    return in_array($mime, $mimes, true) ? 'ok' : 'bad';
}
// Saves the uploaded file in the uploads folder and returns the path to store in the database,
// written with the "uploads/" start the other pages expect, e.g. "uploads/profile-pictures/ab12....png".
// FLAG-5: later, when the website goes online, uploads will move to cloud storage (only this function changes).
function store_file(string $key, string $sub): string {
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    $file = $_FILES[$key];
    $maxSize = 5 * 1024 * 1024; // 5 MB

    if ($file['size'] <= 0 || $file['size'] > $maxSize) {
        throw new RuntimeException('File exceeds the 5 MB limit or is empty.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = [
        'user-profile' => ['png', 'jpg', 'jpeg'],
        'docs'         => ['pdf', 'png', 'jpg', 'jpeg'],
        'logos'        => ['png', 'jpg', 'jpeg'],
    ];
    $allowedMimes = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'pdf'  => 'application/pdf',
    ];

    if (!isset(UPLOAD_FOLDERS[$sub]) || !isset($allowed[$sub]) || !in_array($ext, $allowed[$sub], true)) {
        throw new RuntimeException('Invalid file extension.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (($allowedMimes[$ext] ?? '') !== $mime) {
        throw new RuntimeException('File content does not match its extension.');
    }

    $uploadRoot = __DIR__ . '/../uploads';
    $folder = UPLOAD_FOLDERS[$sub];
    $dir = $uploadRoot . '/' . $folder;

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    // Apache/MAMP protection: disable directory listing and block script execution.
    $rootHtaccess = $uploadRoot . '/.htaccess';
    if (!is_file($rootHtaccess)) {
        $rules = <<<'HTACCESS'
Options -Indexes
<FilesMatch "\.(php[0-9]?|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
HTACCESS;
        $rules .= "\n";
        if (@file_put_contents($rootHtaccess, $rules, LOCK_EX) === false) {
            throw new RuntimeException('Could not secure upload directory.');
        }
    }

    // Company verification documents should not be directly accessible from the web.
    if ($sub === 'docs') {
        $docsHtaccess = $dir . '/.htaccess';
        if (!is_file($docsHtaccess) && @file_put_contents($docsHtaccess, "Require all denied\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not secure company documents directory.');
        }
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save uploaded file.');
    }

    return 'uploads/' . $folder . '/' . $name;
}
// The paths store_file() creates look exactly like this (32 random letters/digits as the file name).
// Files made by other pages (for example user_5_ab12....png) do not match, so they are never touched here.
function upload_path_ok(string $p): bool {
    return (bool) preg_match('#^uploads/(profile-pictures|logos|docs)/[a-f0-9]{32}\.(png|jpg|jpeg|pdf)$#', $p);
}

// Deletes files by the paths store_file() returned. Any other value is ignored.
function delete_files(array $paths): void {
    foreach ($paths as $p) {
        $p = (string) $p;
        if (upload_path_ok($p)) {
            @unlink(__DIR__ . '/../' . $p);
        }
    }
}

// Marks files as "just used" so the cleanup below never removes a registration that is still waiting.
function touch_files(array $paths): void {
    foreach ($paths as $p) {
        $p = (string) $p;
        if (upload_path_ok($p)) {
            @touch(__DIR__ . '/../' . $p);
        }
    }
}

// Orphan files = files in the uploads folders that no database row points to
// (for example someone uploaded a picture and never entered the code).
// Deletes the ones older than $maxAgeSeconds (default 2 hours). Returns how many were deleted.
function cleanup_orphan_uploads(PDO $pdo, int $maxAgeSeconds = 7200): int {
    $used = [];
    foreach (['SELECT ProfilePicture FROM `user`', 'SELECT Logo FROM company', 'SELECT VerificationDocument FROM company'] as $sql) {
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if ($value !== null && $value !== '') $used[basename((string) $value)] = true;
        }
    }

    $deleted = 0;
    foreach (UPLOAD_FOLDERS as $folder) {
        $dir = __DIR__ . '/../uploads/' . $folder;
        if (!is_dir($dir)) continue;
        foreach (scandir($dir) as $name) {
            if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|pdf)$/', $name)) continue; // skips .htaccess and other pages' files
            if (isset($used[$name])) continue;
            $path = $dir . '/' . $name;
            if (is_file($path) && (time() - filemtime($path)) > $maxAgeSeconds && @unlink($path)) $deleted++;
        }
    }
    return $deleted;
}