<?php

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

$extraCss = '../css/admin-company-review.css';
admin_header('Company Review', 'verification');
?>



<div class="page-content container company-review-page">

    <a href="dashboard-admin.php?tab=verification" class="back-link">
        &larr; Back to Company Verification
    </a>

    <!-- Identity (same design as the public company profile) -->
    <?php
    $reviewInitial = mb_strtoupper(mb_substr(trim((string)$c['CompanyName']) ?: 'C', 0, 1, 'UTF-8'), 'UTF-8');
    $reviewWebsite = safe_http_url($c['WebsiteURL'] ?? '');
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