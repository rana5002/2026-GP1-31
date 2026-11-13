<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/email_templates.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/confirm-modal.php';

$adminId = require_admin();

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare(
    'SELECT c.*, m.Email
     FROM company c
     JOIN member m ON m.MemberID = c.CompanyID
     WHERE c.CompanyID = ?'
);
$st->execute([$id]);
$c = $st->fetch();

if (!$c) {
    header('Location: dashboard-admin.php?tab=verification');
    exit;
}

$error = '';
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c['Status'] === 'Pending') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $update = $pdo->prepare(
            "UPDATE company
             SET Status='Verified',
                 RejectionReason=NULL,
                 VerifiedByAdminID=?
             WHERE CompanyID=? AND Status='Pending'"
        );
        $update->execute([$adminId, $id]);

        if ($update->rowCount() > 0) {
            // Use Ufuq's shared HTML + plain-text email template.
            $appUrl = function_exists('env')
                ? rtrim((string) env('APP_URL', ''), '/')
                : '';
            $loginUrl = ($appUrl !== '' ? $appUrl : 'http://localhost:8000')
                . '/pages/login.php';

            $email = email_company_approved(
                (string) $c['CompanyName'],
                $loginUrl
            );

            send_mail(
                $c['Email'],
                $email['subject'],
                $email['html'],
                $email['text']
            );

            header('Location: dashboard-admin.php?tab=verification&msg=approved');
            exit;
        }

        header('Location: dashboard-admin.php?tab=verification');
        exit;
    }

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');

        if ($reason === '') {
            $error = 'Please specify a reason for rejection.';
        } else {
            $update = $pdo->prepare(
                "UPDATE company
                 SET Status='Rejected',
                     RejectionReason=?,
                     VerifiedByAdminID=?
                 WHERE CompanyID=? AND Status='Pending'"
            );
            $update->execute([$reason, $adminId, $id]);

            if ($update->rowCount() > 0) {
                // The shared rejection template includes the admin's reason.
                $email = email_company_rejected(
                    (string) $c['CompanyName'],
                    $reason
                );

                send_mail(
                    $c['Email'],
                    $email['subject'],
                    $email['html'],
                    $email['text']
                );

                header('Location: dashboard-admin.php?tab=verification&msg=rejected');
                exit;
            }

            header('Location: dashboard-admin.php?tab=verification');
            exit;
        }
    }
}

$badge = [
    'Verified' => 'badge-success',
    'Rejected' => 'badge-danger',
    'Pending'  => 'badge-warning'
][$c['Status']] ?? 'badge-warning';

admin_header('Company Review', 'verification');
?>

<style>
:root {
    --review-purple: #39265f;
    --review-purple-dark: #281a46;
    --review-purple-mid: #4b3478;
    --review-purple-soft: #f5f1fb;
    --review-purple-border: #e4dced;
    --review-gold: #e2a94b;
    --review-text: #2c263b;
    --review-muted: #777186;
    --review-border: #e9e3f0;
    --review-surface: #ffffff;
    --review-bg: #fcfbfe;
    --review-danger: #c74750;
    --review-danger-bg: #fff3f3;
    --review-success: #287a54;
    --review-success-bg: #eaf7ef;
    --review-warning: #95671b;
    --review-warning-bg: #fff5df;
}

.company-review-page {
    width: 100%;
    max-width: 1000px !important;
    margin: 0 auto;
    padding: 28px 24px 45px;
    box-sizing: border-box;
    color: var(--review-text);
}

.company-review-page * {
    box-sizing: border-box;
}

.company-review-page .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 22px;
    color: var(--review-muted);
    font-size: .9rem;
    font-weight: 600;
    text-decoration: none;
    transition: color .2s ease;
}

.company-review-page .back-link:hover {
    color: var(--review-purple);
}

.company-review-page .company-heading {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 25px;
    padding: 22px 24px;
    border: 1px solid var(--review-purple-border);
    border-radius: 17px;
    background: linear-gradient(120deg, #fff 0%, #f8f4fd 100%);
    box-shadow: 0 8px 24px rgba(40, 26, 70, .055);
}

.company-review-page .company-heading .company-icon {
    display: flex;
    flex: 0 0 56px;
    width: 56px;
    height: 56px;
    align-items: center;
    justify-content: center;
    border-radius: 15px;
    background: var(--review-purple-soft);
    color: var(--review-purple);
}

.company-review-page .company-heading h2 {
    margin: 0 0 8px;
    color: var(--review-purple-dark);
    font-size: clamp(1.3rem, 2.5vw, 1.8rem);
    font-weight: 800;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.company-review-page .company-heading .heading-details {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 9px;
}

.company-review-page .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 11px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 750;
    line-height: 1.4;
}

.company-review-page .status-badge.badge-success {
    background: var(--review-success-bg);
    color: var(--review-success);
}

.company-review-page .status-badge.badge-danger {
    background: #fdebec;
    color: var(--review-danger);
}

.company-review-page .status-badge.badge-warning {
    background: var(--review-warning-bg);
    color: var(--review-warning);
}

.company-review-page .review-card {
    margin-bottom: 20px;
    padding: clamp(20px, 3vw, 29px);
    border: 1px solid var(--review-border);
    border-radius: 16px;
    background: var(--review-surface);
    box-shadow: 0 8px 24px rgba(40, 26, 70, .055);
}

.company-review-page .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 22px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee8f4;
    color: var(--review-purple-dark);
    font-size: 1.08rem;
    font-weight: 800;
}

.company-review-page .section-heading .section-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    border-radius: 10px;
    background: var(--review-purple-soft);
    color: var(--review-purple);
}

.company-review-page .company-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.company-review-page .field-view {
    min-width: 0;
    padding: 14px 15px;
    border: 1px solid #eee8f4;
    border-radius: 11px;
    background: var(--review-bg);
    overflow-wrap: anywhere;
}

.company-review-page .field-label {
    margin-bottom: 7px;
    color: var(--review-muted);
    font-size: .82rem;
    font-weight: 650;
}

.company-review-page .field-value {
    color: var(--review-text);
    font-size: .94rem;
    font-weight: 600;
    line-height: 1.65;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.company-review-page .description-field {
    margin-top: 18px;
}

.company-review-page .description-field .field-value {
    font-weight: 400;
}

.company-review-page .document-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 17px;
    border: 1px solid var(--review-purple-border);
    border-radius: 13px;
    background: linear-gradient(120deg, #fff 0%, #faf7fe 100%);
}

.company-review-page .document-icon {
    display: flex;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: var(--review-purple-soft);
    color: var(--review-purple);
}

.company-review-page .document-info {
    flex: 1;
    min-width: 0;
}

.company-review-page .document-name {
    color: var(--review-purple-dark);
    font-size: .92rem;
    font-weight: 750;
    overflow-wrap: anywhere;
}

.company-review-page .document-caption {
    margin-top: 5px;
    color: var(--review-muted);
    font-size: .82rem;
    line-height: 1.5;
}

.company-review-page .review-card.rejection-notice {
    border-color: #f0c9cc;
    background: var(--review-danger-bg);
    box-shadow: none;
}

.company-review-page .rejection-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 10px;
    color: var(--review-danger);
    font-weight: 800;
}

.company-review-page .rejection-text {
    color: #71373b;
    line-height: 1.7;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.company-review-page .decision-description {
    margin: -8px 0 20px;
    color: var(--review-muted);
    font-size: .9rem;
    line-height: 1.65;
}

.company-review-page .decision-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.company-review-page .review-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 44px;
    padding: 11px 19px;
    border: 1px solid transparent;
    border-radius: 10px;
    font-family: inherit;
    font-size: .9rem;
    font-weight: 750;
    line-height: 1.4;
    text-decoration: none;
    cursor: pointer;
    transition: background .2s ease, border-color .2s ease,
                box-shadow .2s ease, transform .2s ease;
}

.company-review-page .review-btn:hover {
    transform: translateY(-1px);
}

.company-review-page .review-btn-primary {
    background: var(--review-purple);
    border-color: var(--review-purple);
    color: #fff;
    box-shadow: 0 5px 12px rgba(57, 38, 95, .15);
}

.company-review-page .review-btn-primary:hover {
    background: var(--review-purple-dark);
    border-color: var(--review-purple-dark);
    color: #fff;
    box-shadow: 0 7px 16px rgba(40, 26, 70, .2);
}

.company-review-page .review-btn-danger {
    background: #fff;
    border-color: #e8b9bd;
    color: var(--review-danger);
}

.company-review-page .review-btn-danger:hover {
    background: #fff0f1;
    border-color: var(--review-danger);
}

.company-review-page .review-btn-outline {
    flex-shrink: 0;
    min-height: 38px;
    padding: 9px 13px;
    border-color: var(--review-purple-border);
    background: #fff;
    color: var(--review-purple);
    font-size: .82rem;
}

.company-review-page .review-btn-outline:hover {
    background: var(--review-purple-soft);
    border-color: var(--review-purple);
}

.company-review-page .review-btn-ghost {
    background: #fff;
    border-color: #ded7e8;
    color: var(--review-muted);
}

.company-review-page .review-btn-ghost:hover {
    background: #f6f3fa;
    color: var(--review-purple);
}

.company-review-page .rejection-form {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee8f4;
}

.company-review-page .form-label {
    display: block;
    margin-bottom: 9px;
    color: var(--review-purple-dark);
    font-size: .9rem;
    font-weight: 750;
}

.company-review-page .form-control {
    display: block;
    width: 100%;
    min-height: 45px;
    padding: 11px 13px;
    border: 1px solid #ded8e8;
    border-radius: 10px;
    outline: none;
    background: #fcfbfe;
    color: var(--review-text);
    font: inherit;
    font-size: .92rem;
    transition: border-color .2s ease, box-shadow .2s ease;
}

.company-review-page textarea.form-control {
    min-height: 120px;
    resize: vertical;
    line-height: 1.65;
}

.company-review-page .form-control::placeholder {
    color: #a49caf;
}

.company-review-page .form-control:focus {
    border-color: var(--review-purple);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(57, 38, 95, .12);
}

.company-review-page .form-control.invalid {
    border-color: #e5646a;
    background: #fff8f8;
    box-shadow: 0 0 0 2px rgba(229, 100, 106, .08);
}

.company-review-page .form-error {
    display: block;
    margin-top: 8px;
    padding: 10px 12px;
    border: 1px solid #f1c5c8;
    border-radius: 9px;
    background: #fff0f0;
    color: #a6373e;
    font-size: .86rem;
    line-height: 1.5;
}

.company-review-page .form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 13px;
}

.company-review-page .hidden {
    display: none !important;
}

/* Button icons: white tick on Approve, red icon on Reject, so they are clear */
.company-review-page .review-btn-primary .icon {
    background-color: #fff !important;
    opacity: 1;
}

.company-review-page .review-btn-primary svg { color: #fff; stroke: #fff; }
.company-review-page .review-btn-primary img { filter: brightness(0) invert(1); }

.company-review-page .review-btn-danger .icon {
    background-color: var(--review-danger) !important;
    opacity: 1;
}

.company-review-page .review-btn-danger svg { color: var(--review-danger); stroke: var(--review-danger); }

/* Page background: same beige as the public company profile */
body {
    background: #faf6ef;
}

/* Status badge with a dot and a light border (same as the rest of Ufuq) */
.company-review-page .status-badge {
    gap: 7px;
    min-width: 96px;
    justify-content: center;
    padding: 5px 12px;
    border: 1px solid transparent;
    border-radius: 30px;
    font-size: .78rem;
}

.company-review-page .status-badge::before {
    content: "";
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    border-radius: 50%;
    background: currentColor;
}

.company-review-page .status-badge.badge-warning { border-color: #f0dfb4; }
.company-review-page .status-badge.badge-success { border-color: #cfe8d9; }
.company-review-page .status-badge.badge-danger  { border-color: #f1d4d8; }

/* ---------- Company information: same design as the public company profile ---------- */
.company-review-page .profile-identity,
.company-review-page .profile-section {
    border: 1px solid #e9e3ed;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40, 26, 70, .045);
}

.company-review-page .profile-identity {
    display: flex;
    align-items: center;
    gap: 22px;
    padding: 25px;
    margin-bottom: 22px;
}

.company-review-page .profile-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 92px;
    width: 92px;
    height: 92px;
    overflow: hidden;
    border: 4px solid #f0ebf8;
    border-radius: 22px;
    background: #f0ebf8;
    color: var(--review-purple);
    font-size: 1.8rem;
    font-weight: 750;
}

.company-review-page .profile-avatar.has-logo { background: #fff; }
.company-review-page .profile-avatar img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
.company-review-page .profile-avatar img[hidden] { display: none !important; }
.company-review-page .profile-avatar .avatar-initial { display: none; }
.company-review-page .profile-avatar img[hidden] + .avatar-initial { display: block; }

.company-review-page .profile-identity-details { flex: 1; min-width: 0; }

.company-review-page .profile-identity-details h2 {
    margin: 0;
    color: #29243a;
    font-size: 1.4rem;
    overflow-wrap: anywhere;
}

.company-review-page .profile-subtitle {
    margin-top: 6px;
    color: #777184;
    font-size: .92rem;
}

.company-review-page .profile-identity .status-badge {
    flex-shrink: 0;
    min-width: 96px;
    justify-content: center;
}

.company-review-page .profile-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.company-review-page .profile-grid .is-wide { grid-column: 1 / -1; }

.company-review-page .profile-section { padding: 22px 24px; min-width: 0; }

.company-review-page .profile-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 15px;
    margin-bottom: 17px;
    border-bottom: 1px solid #e9e4ef;
}

.company-review-page .profile-section-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: #29243a;
    font-size: 1.05rem;
    font-weight: 750;
}

.company-review-page .profile-section-header h3::before {
    content: "";
    display: block;
    width: 5px;
    height: 22px;
    border-radius: 5px;
    background: var(--review-gold);
}

.company-review-page .profile-view-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 22px;
}

.company-review-page .profile-field-label { margin-bottom: 6px; color: #777184; font-size: .8rem; }

.company-review-page .profile-field-value {
    color: #29243a;
    font-size: .93rem;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.company-review-page .profile-field-value a { color: var(--review-purple); font-weight: 600; }

.company-review-page .profile-about {
    margin: 0;
    color: #29243a;
    font-size: .93rem;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.company-review-page .profile-empty {
    margin: 0;
    padding: 14px;
    border: 1px dashed #ded5ec;
    border-radius: 12px;
    background: #faf9fd;
    color: #777184;
    font-size: .87rem;
}

@media (max-width: 760px) {
    .company-review-page .profile-grid,
    .company-review-page .profile-view-grid { grid-template-columns: minmax(0, 1fr); }
    .company-review-page .profile-identity { flex-wrap: wrap; padding: 20px; gap: 16px; }
    .company-review-page .profile-avatar { flex-basis: 72px; width: 72px; height: 72px; }
}

@media (max-width: 700px) {
    .company-review-page {
        padding: 20px 14px 32px;
    }

    .company-review-page .company-heading {
        align-items: flex-start;
        gap: 12px;
        padding: 17px;
    }

    .company-review-page .company-heading .company-icon {
        flex-basis: 45px;
        width: 45px;
        height: 45px;
        border-radius: 12px;
    }

    .company-review-page .company-info-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 12px;
    }

    .company-review-page .review-card {
        padding: 19px 15px;
        border-radius: 13px;
    }

    .company-review-page .document-card {
        align-items: flex-start;
        flex-wrap: wrap;
        padding: 14px;
    }

    .company-review-page .document-info {
        width: calc(100% - 65px);
    }

    .company-review-page .document-card .review-btn {
        width: 100%;
    }

    .company-review-page .decision-actions .review-btn {
        flex: 1 1 140px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .company-review-page * {
        transition: none !important;

    }
    .company-heading .company-logo {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 10px;
}

.company-heading .company-icon {
    overflow: hidden;
}
}
</style>

<div class="page-content container company-review-page">

    <a href="dashboard-admin.php?tab=verification" class="back-link">
        &larr; Back to Company Verification
    </a>

    <!-- Identity (same design as the public company profile) -->
    <?php
    $reviewInitial = mb_strtoupper(mb_substr(trim((string)$c['CompanyName']) ?: 'C', 0, 1, 'UTF-8'), 'UTF-8');
    $reviewWebsite = trim((string)($c['WebsiteURL'] ?? ''));
    ?>
    <section class="profile-identity">
        <div class="profile-avatar <?= !empty($c['Logo']) ? 'has-logo' : '' ?>">
            <?php if (!empty($c['Logo'])): ?>
                <img
                    src="../uploads/logos/<?= e(basename($c['Logo'])) ?>"
                    alt="<?= e($c['CompanyName']) ?>"
                    onerror="this.hidden=true;this.parentNode.classList.remove('has-logo')"
                ><span class="avatar-initial"><?= e($reviewInitial) ?></span>
            <?php else: ?>
                <?= e($reviewInitial) ?>
            <?php endif; ?>
        </div>

        <div class="profile-identity-details">
            <h2><?= e($c['CompanyName']) ?></h2>
            <div class="profile-subtitle">
                <?= e($c['IndustrySector'] ?: 'Industry not specified') ?>
                <?php if (!empty($c['Location'])): ?> &middot; <?= e($c['Location']) ?><?php endif; ?>
            </div>
        </div>

        <span class="status-badge <?= e($badge) ?>"><?= e($c['Status']) ?></span>
    </section>

    <div class="profile-grid">

        <!-- Company information -->
        <section class="profile-section">
            <div class="profile-section-header"><h3>Company Information</h3></div>
            <div class="profile-view-grid">
                <div><div class="profile-field-label">Industry Sector</div><div class="profile-field-value"><?= e($c['IndustrySector'] ?: '—') ?></div></div>
                <div><div class="profile-field-label">Location</div><div class="profile-field-value"><?= e($c['Location'] ?: '—') ?></div></div>
                <div style="grid-column:1/-1"><div class="profile-field-label">CR Number</div><div class="profile-field-value"><?= e($c['CrNumber'] ?: '—') ?></div></div>
            </div>
        </section>

        <!-- Contact -->
        <section class="profile-section">
            <div class="profile-section-header"><h3>Contact Information</h3></div>
            <div class="profile-view-grid">
                <div><div class="profile-field-label">Email</div><div class="profile-field-value"><?= e($c['Email'] ?: '—') ?></div></div>
                <div><div class="profile-field-label">Phone</div><div class="profile-field-value"><?= e($c['Phone'] ?: '—') ?></div></div>
                <div style="grid-column:1/-1"><div class="profile-field-label">Website</div><div class="profile-field-value">
                    <?php if ($reviewWebsite !== ''): ?><a href="<?= e($reviewWebsite) ?>" target="_blank" rel="noopener noreferrer"><?= e($reviewWebsite) ?></a><?php else: ?>—<?php endif; ?>
                </div></div>
            </div>
        </section>

        <!-- About -->
        <section class="profile-section is-wide">
            <div class="profile-section-header"><h3>About the Company</h3></div>
            <?php if (trim((string)($c['Description'] ?? '')) === ''): ?>
                <p class="profile-empty">No company description has been added.</p>
            <?php else: ?>
                <p class="profile-about"><?= e($c['Description']) ?></p>
            <?php endif; ?>
        </section>

    </div>

    <div class="review-card">
        <h3 class="section-heading">
            <span class="section-icon">
                <?= icon('document', 19, 'icon-primary') ?>
            </span>
            Verification Document
        </h3>

        <div class="document-card">
            <div class="document-icon">
                <?= icon('document', 27, 'icon-primary') ?>
            </div>

            <div class="document-info">
              <div class="document-name">
    <?= !empty($c['VerificationDocument'])
        ? 'Company Verification Document'
        : 'No document uploaded' ?>
</div>

                <div class="document-caption">
                    Verification document on file
                </div>
            </div>

            <?php if (!empty($c['VerificationDocument'])): ?>
                <a
                    class="review-btn review-btn-outline"
                    target="_blank"
                    rel="noopener"
                    href="view-verification-document.php?id=<?= (int)$c['CompanyID'] ?>"
                >
                    <?= icon('document', 15) ?>
                    View Document
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($c['Status'] === 'Rejected' && !empty($c['RejectionReason'])): ?>
        <div class="review-card rejection-notice">
            <div class="rejection-title">
                <?= icon('error', 19) ?>
                Rejection reason
            </div>

            <div class="rejection-text">
                <?= e($c['RejectionReason']) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($c['Status'] === 'Pending'): ?>
        <div class="review-card">
            <h3 class="section-heading">
                <span class="section-icon">
                    <?= icon('done', 19, 'icon-primary') ?>
                </span>
                Decision
            </h3>

            <p class="decision-description">
                Review the company information and verification document before making a decision.
            </p>

            <form method="post" class="decision-actions">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                <!-- Approve opens a confirmation message first -->
                <button
                    type="button"
                    class="review-btn review-btn-primary"
                    onclick="openConfirmModal('approveCompanyModal')"
                >
                    Approve
                    <?= icon('done', 17) ?>
                </button>

                <button
                    type="button"
                    class="review-btn review-btn-danger"
                    id="rejectToggleBtn"
                >
                    Reject
                    <?= icon('error', 17) ?>
                </button>
            </form>

            <form
                method="post"
                id="rejectForm"
                novalidate
                class="rejection-form <?= $error ? '' : 'hidden' ?>"
            >
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reject">

                <label class="form-label" for="reason">
                    Reason for rejection
                </label>

                <textarea
                    class="form-control <?= $error ? 'invalid' : '' ?>"
                    rows="4"
                    id="reason"
                    name="reason"
                    placeholder="Explain why this company is being rejected"
                    required
                ><?= e($reason) ?></textarea>

                <div class="form-error <?= $error ? '' : 'hidden' ?>" id="reasonError">
                    <?= e($error ?: 'Please specify a reason for rejection.') ?>
                </div>

                <div class="form-actions">
                    <button type="submit" class="review-btn review-btn-danger">
                        Confirm Rejection
                        <?= icon('error', 16) ?>
                    </button>

                    <button
                        type="button"
                        class="review-btn review-btn-ghost"
                        id="rejectCancelBtn"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php if ($c['Status'] === 'Pending'): ?>
    <?php
    /* Confirmation messages in the middle of the screen (same as Delete Opportunity) */
    confirm_modal([
        'id'            => 'approveCompanyModal',
        'title'         => 'Approve Company?',
        'message'       => $c['CompanyName'] . ' will be verified and can start publishing opportunities. An approval email will be sent to the company.',
        'confirm_label' => 'Yes, Approve',
        'icon'          => 'check',
        'variant'       => 'primary',
        'fields'        => ['action' => 'approve'],
    ]);

    confirm_modal([
        'id'            => 'rejectCompanyModal',
        'title'         => 'Reject Company?',
        'message'       => $c['CompanyName'] . ' will be rejected and will receive an email with your reason. This cannot be undone.',
        'confirm_label' => 'Yes, Reject',
        'icon'          => 'alert',
        'fields'        => ['action' => 'reject'],
        'id_field'      => 'reason',
    ]);
    ?>
<?php endif; ?>

<script>
(function () {
    const rejectForm = document.getElementById('rejectForm');
    const rejectToggleBtn = document.getElementById('rejectToggleBtn');
    const rejectCancelBtn = document.getElementById('rejectCancelBtn');
    const reasonField = document.getElementById('reason');

    rejectToggleBtn?.addEventListener('click', function () {
        if (!rejectForm) return;

        rejectForm.classList.remove('hidden');
        reasonField?.focus();
    });

    rejectCancelBtn?.addEventListener('click', function () {
        if (!rejectForm) return;

        rejectForm.classList.add('hidden');
    });

    /* Confirm Rejection: check the reason, then ask for confirmation in the middle of the screen */
    const reasonError = document.getElementById('reasonError');

    rejectForm?.addEventListener('submit', function (ev) {
        ev.preventDefault();

        const reason = (reasonField?.value || '').trim();

        if (reason === '') {
            reasonField?.classList.add('invalid');
            reasonError?.classList.remove('hidden');
            reasonField?.focus();
            return;
        }

        reasonField?.classList.remove('invalid');
        reasonError?.classList.add('hidden');
        openConfirmModal('rejectCompanyModal', reason);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>