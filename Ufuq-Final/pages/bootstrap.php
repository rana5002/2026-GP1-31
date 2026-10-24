<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// One shared database connection ($pdo). Settings come from .env, nothing is hardcoded.
require_once __DIR__ . '/../config/db.php';

// Email sending through Gmail: send_mail($to, $subject, $htmlBody)
require_once __DIR__ . '/../includes/mail.php';

/* Applications close automatically once the application deadline has passed,
   even if the company did not close the opportunity by hand (User Story 28). */
function close_expired_opportunities(PDO $pdo): void {
    try {
        $pdo->prepare("UPDATE opportunity SET Status = 'Closed'
                       WHERE Status = 'Open' AND ApplicationDeadLine IS NOT NULL AND ApplicationDeadLine < ?")
            ->execute([date('Y-m-d')]);
    } catch (Throwable $ex) {
        error_log('Auto-close failed: ' . $ex->getMessage());
    }
}
close_expired_opportunities($pdo);

/* Name of the reset password page (used in the "password changed" email). Change it if your page has another name. */
const RESET_PASSWORD_PAGE = 'reset-password.php';

/* Full link to a page of the site, used inside emails. */
function app_url(string $page): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/' . ltrim($page, '/');
}

/* After a password change: email the member that the password was changed,
   with a link to reset it if they did not make the change. */
function send_password_changed_email(PDO $pdo, int $memberId): void {
    if (!function_exists('send_mail')) return;
    try {
        $stmt = $pdo->prepare("SELECT m.Email, COALESCE(u.FirstName, c.CompanyName, '') AS Name
                               FROM member m
                               LEFT JOIN `user` u ON u.UserID = m.MemberID
                               LEFT JOIN company c ON c.CompanyID = m.MemberID
                               WHERE m.MemberID = ?");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['Email'])) return;

        $resetUrl = app_url(RESET_PASSWORD_PAGE);
        $html = '<p>Hello ' . e($row['Name']) . ',</p>'
            . '<p>Your Ufuq password was changed successfully on ' . e(date('d M Y, g:i A')) . '.</p>'
            . '<p>If you did not make this change, please reset your password right away:</p>'
            . '<p><a href="' . e($resetUrl) . '">Reset Password</a></p>'
            . '<p>Ufuq</p>';
        send_mail($row['Email'], 'Ufuq: your password has been changed', $html);
    } catch (Throwable $ex) {
        error_log('Password changed email failed: ' . $ex->getMessage());
    }
}

/* User Story 37: one in-system notification for the given users (shown on their Notifications page).
   Emails are used only for company approval, account verification and password reset. */
function notify_users(PDO $pdo, array $userIds, string $type, int $opportunityId, string $message): void {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return;

    try {
        $pdo->prepare('INSERT INTO notification (Message, Type, OpportunityID) VALUES (?, ?, ?)')
            ->execute([mb_substr($message, 0, 255, 'UTF-8'), $type, $opportunityId]);
        $notificationId = (int)$pdo->lastInsertId();
        $link = $pdo->prepare('INSERT INTO usernotification (UserID, NotificationID, IsRead) VALUES (?, ?, 0)');
        foreach ($userIds as $userId) {
            $link->execute([$userId, $notificationId]);
        }
    } catch (Throwable $ex) {
        error_log('Notification failed: ' . $ex->getMessage());
        return;
    }
}

/* User Story 37: tell users who match a newly published opportunity
   (they have at least one of its required skills or qualifications). */
function notify_matching_users(PDO $pdo, int $opportunityId): void {
    try {
        $opp = $pdo->prepare("SELECT Title FROM opportunity WHERE OpportunityID = ? AND Status = 'Open'");
        $opp->execute([$opportunityId]);
        $title = $opp->fetchColumn();
        if ($title === false) return;

        $users = $pdo->prepare('SELECT us.UserID FROM userskill us
                                JOIN opportunityskill os ON os.SkillID = us.SkillID
                                WHERE os.OpportunityID = ?
                                UNION
                                SELECT uq.UserID FROM userqualification uq
                                JOIN opportunityqualification oq ON oq.QualificationID = uq.QualificationID
                                WHERE oq.OpportunityID = ?');
        $users->execute([$opportunityId, $opportunityId]);
        $userIds = $users->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $ex) {
        error_log('Matching users failed: ' . $ex->getMessage());
        return;
    }

    notify_users($pdo, $userIds, 'NewOpportunity', $opportunityId,
        'A new opportunity that matches your profile has been published: "' . $title . '".');
}

/* User Story 37: remind users about opportunities in their favorites when the
   application deadline is 48 hours away or less. Each user is reminded once per
   opportunity, and not when they have already applied. Runs on every page, like
   close_expired_opportunities(). */
function send_deadline_reminders(PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("SELECT f.UserID, o.OpportunityID, o.Title, o.ApplicationDeadLine
            FROM favorite f
            JOIN opportunity o ON o.OpportunityID = f.OpportunityID
            WHERE o.Status = 'Open'
              AND o.ApplicationDeadLine BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM usernotification un
                              JOIN notification n ON n.NotificationID = un.NotificationID
                              WHERE un.UserID = f.UserID AND n.OpportunityID = o.OpportunityID AND n.Type = 'Reminder')
              AND NOT EXISTS (SELECT 1 FROM application a
                              WHERE a.UserID = f.UserID AND a.OpportunityID = o.OpportunityID)");
        $stmt->execute([date('Y-m-d'), date('Y-m-d', time() + 48 * 3600)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        error_log('Deadline reminders failed: ' . $ex->getMessage());
        return;
    }

    $byOpportunity = [];
    foreach ($rows as $row) {
        $byOpportunity[(int)$row['OpportunityID']]['row'] = $row;
        $byOpportunity[(int)$row['OpportunityID']]['users'][] = (int)$row['UserID'];
    }

    foreach ($byOpportunity as $opportunityId => $group) {
        $deadline = date('d M Y', strtotime($group['row']['ApplicationDeadLine']));
        notify_users($pdo, $group['users'], 'Reminder', $opportunityId,
            'Reminder: the application deadline for "' . $group['row']['Title'] . '" is ' . $deadline . '. Apply before it closes.');
    }
}


/* A validation error that belongs to one form field: the page shows it under that field in red. */
class FieldError extends RuntimeException {
    public function __construct(public string $form, public string $field, string $message) {
        parent::__construct($message);
    }
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

send_deadline_reminders($pdo);

/* Expects login.php to set $_SESSION['user_id'] and $_SESSION['role'] ('Admin'). */
function require_admin(): int {
    if (($_SESSION['role'] ?? '') !== 'Administrator'
        || !isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    return (int) $_SESSION['user_id'];
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_check(): void {
    $expected = $_SESSION['csrf'] ?? '';
    $given    = $_POST['csrf'] ?? '';

    // An empty session token must never match an empty form token.
    if ($expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
        http_response_code(400);
        exit('Invalid request.');
    }
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
            <img
                src="../images-website/logo.png"
                alt="Ufuq"
                class="brand-mark"
                style="width:34px;height:34px;object-fit:contain;background:transparent;"
            >
            Ufuq
        </a>

        <nav class="nav-links" id="navLinks" aria-label="Admin navigation">
            <a
                href="dashboard-admin.php?tab=dashboard"
                class="<?= $active === 'dashboard' ? 'active' : '' ?>"
            >
                Dashboard
            </a>

            <a
                href="dashboard-admin.php?tab=verification"
                class="<?= $active === 'verification' ? 'active' : '' ?>"
            >
                Company Verification
            </a>

            <a
                href="dashboard-admin.php?tab=opportunities"
                class="<?= $active === 'opportunities' ? 'active' : '' ?>"
            >
                Manage Opportunities
            </a>
        </nav>

        <div class="nav-actions">
            <a href="logout.php" class="btn btn-ghost btn-sm">
                Log Out
            </a>

            <button
                class="nav-toggle"
                id="navToggle"
                type="button"
                aria-label="Toggle menu"
                aria-controls="navLinks"
                aria-expanded="false"
            >
                <?= icon('menu', 22) ?>
            </button>
        </div>

    </div>
</header>

<script>
(function () {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('navLinks');

    if (!toggle || !nav || toggle.dataset.initialized === 'true') {
        return;
    }

    toggle.dataset.initialized = 'true';

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(isOpen));
    });

    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    document.addEventListener('click', function (event) {
        if (!nav.contains(event.target) && !toggle.contains(event.target)) {
            nav.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>

<main>
<?php }



function icon(string $name, int $size = 18, string $extra = ''): string {
    $p = "../images-website/icons/$name.png";
    return "<span class=\"icon $extra\" style=\"width:{$size}px;height:{$size}px;-webkit-mask-image:url('$p');mask-image:url('$p');\"></span>";
}
