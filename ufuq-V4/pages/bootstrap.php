<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$pdo = new PDO(
    'mysql:host=localhost;port=3306;dbname=ufuq;charset=utf8mb4',
    'root',
    'root',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

const OPPORTUNITY_TYPES = ['Training', 'Course'];
const CITIES = ['Riyadh','Jeddah','Mecca','Medina','Dammam','Khobar','Dhahran','Taif','Abha','Tabuk','Online','Other'];

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Expects login.php to set $_SESSION['member_id'] and $_SESSION['role'] ('Admin'). */
function require_admin(): int {
    if (($_SESSION['role'] ?? '') !== 'Admin'
        || !isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    return (int)$_SESSION['user_id'];
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid request.'); }
}

/* Uses PHP mail(); configure SMTP in php.ini, or swap the body for PHPMailer. */
function send_mail(string $to, string $subject, string $body): bool {
    $headers = "From: Ufuq <no-reply@ufuq.sa>\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, $subject, $body, $headers);
}

function admin_header(string $title, string $active): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> &mdash; Ufuq Admin</title>
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">
</head>
<body>
<header class="site-header">
  <div class="container">
    <a href="../index.php" class="brand" style="text-decoration:none;">
      <img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent;">
      Ufuq
    </a>
    <nav class="nav-links">
      <a href="dashboard-admin.php?tab=verification" class="<?= $active === 'verification' ? 'active' : '' ?>">Company Verification</a>
      <a href="dashboard-admin.php?tab=opportunities" class="<?= $active === 'opportunities' ? 'active' : '' ?>">Manage Opportunities</a>
    </nav>
    <div class="nav-actions"><a href="logout.php" class="btn btn-ghost btn-sm">Log Out</a></div>
  </div>
</header>
<main>
<?php }

function admin_footer(): void { ?>
</main>
<footer class="site-footer">
  <div class="container">
    <div class="footer-bottom">
      <span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span>
    </div>
  </div>
</footer>
</body>
</html>
<?php }

function icon(string $name, int $size = 18, string $extra = ''): string {
    $p = "../images-website/icons/$name.png";
    return "<span class=\"icon $extra\" style=\"width:{$size}px;height:{$size}px;-webkit-mask-image:url('$p');mask-image:url('$p');\"></span>";
}