<?php
declare(strict_types=1);

/**
 * Serves a company's verification PDF only to authenticated administrators.
 * Place this file in Ufuq-Final/pages/view-verification-document.php.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

no_cache();

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'Administrator'
) {
    http_response_code(403);
    exit('Access denied.');
}

$companyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$companyId || $companyId < 1) {
    http_response_code(400);
    exit('Invalid company ID.');
}

$stmt = $pdo->prepare(
    'SELECT VerificationDocument FROM company WHERE CompanyID = ? LIMIT 1'
);
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company || empty($company['VerificationDocument'])) {
    http_response_code(404);
    exit('Verification document not found.');
}

/*
 * The database may contain just a filename or a relative path such as
 * uploads/docs/example.pdf. basename() prevents path traversal.
 */
$filename = basename(str_replace('\\', '/', (string)$company['VerificationDocument']));
//This endpoint intentionally supports PDF, PNG, and JPEG files only.
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg'];

if ($filename === '' || !in_array($extension, $allowedExtensions, true)) {
    http_response_code(415);
    exit('Unsupported verification document type.');
}


$docsDirectory = realpath(__DIR__ . '/../../private-uploads/docs');

if ($docsDirectory === false) {
    http_response_code(500);
    exit('Document storage is not configured.');
}

$filePath = realpath(
    $docsDirectory . DIRECTORY_SEPARATOR . $filename
);

if (
    $filePath === false ||
    !is_file($filePath) ||
    !str_starts_with(
        $filePath,
        $docsDirectory . DIRECTORY_SEPARATOR
    )
) {
    http_response_code(404);
    exit('Verification document not found.');
}


$mimeTypes = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
];

header('Content-Type: ' . $mimeTypes[$extension]);

/* Avoid MIME sniffing and force the browser to treat this as a PDF. */
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
