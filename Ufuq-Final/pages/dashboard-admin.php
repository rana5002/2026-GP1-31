<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

$adminId = require_admin();

$tab = $_GET['tab'] ?? 'verification';
if (!in_array($tab, ['verification', 'opportunities'], true)) {
    $tab = 'verification';
}

/* ---------- Delete opportunity (admin-created only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();

    $st = $pdo->prepare(
        'DELETE FROM opportunity
         WHERE OpportunityID = ? AND CreatedByAdminID IS NOT NULL'
    );
    $st->execute([(int)($_POST['id'] ?? 0)]);

    header('Location: dashboard-admin.php?tab=opportunities&msg=deleted');
    exit;
}

$pending = $pdo->query("
    SELECT c.*, m.Email
    FROM company c
    JOIN member m ON m.MemberID = c.CompanyID
    WHERE c.Status = 'Pending'
    ORDER BY c.CompanyID
")->fetchAll();

$reviewed = $pdo->query("
    SELECT CompanyName, CrNumber, IndustrySector, Status
    FROM company
    WHERE Status <> 'Pending'
    ORDER BY CompanyID
")->fetchAll();

$opps = $pdo->query("
    SELECT OpportunityID, Title, OpportunityProvider, Type, Status
    FROM opportunity
    WHERE CreatedByAdminID IS NOT NULL
    ORDER BY OpportunityID DESC
")->fetchAll();

$adminName = trim($_SESSION['admin_name'] ?? 'Hatoon');

admin_header('Admin Dashboard', $tab);
?>

<style>
/* ===== Admin Dashboard ===== */

.admin-dashboard {
    --admin-purple: #39265f;
    --admin-purple-dark: #281a46;
    --admin-purple-mid: #49316f;
    --admin-lavender: #f0ebf8;
    --admin-gold: #e2a94b;
    --admin-text: #29243a;
    --admin-muted: #777184;

    color: var(--admin-text);
    padding-bottom: 50px;
}

.admin-dashboard * {
    box-sizing: border-box;
}

/* Welcome banner */
.admin-dashboard .admin-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    background: linear-gradient(
        125deg,
        #281a46 0%,
        #39265f 55%,
        #49316f 100%
    );
    color: #fff;
}

.admin-dashboard .admin-hero::before,
.admin-dashboard .admin-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.admin-dashboard .admin-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.admin-dashboard .admin-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.admin-dashboard .admin-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.admin-dashboard .welcome-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px;
    background: rgba(255,255,255,.08);
    font-size: .84rem;
    letter-spacing: .2px;
}

.admin-dashboard .admin-hero h2 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.admin-dashboard .admin-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .98rem;
}

.admin-dashboard .hero-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 78px;
    height: 78px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 24px;
    background: rgba(255,255,255,.08);
    box-shadow: 0 10px 28px rgba(15,8,30,.16);
    font-size: 2rem;
    animation: adminFloat 4s ease-in-out infinite;
}

/* Content entrance */
.admin-dashboard .admin-content {
    animation: adminFadeUp .55s ease both;
}

/* Notification */
.admin-dashboard .admin-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 16px;
    margin-bottom: 24px;
    border: 1px solid #e4dced;
    border-radius: 13px;
    background: #f7f4fb;
    color: var(--admin-purple);
    box-shadow: 0 4px 15px rgba(40,26,70,.04);
    animation: adminFadeUp .35s ease both;
}

/* Section headings */
.admin-dashboard .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 16px;
    color: var(--admin-text);
    font-size: 1.18rem;
    font-weight: 750;
}

.admin-dashboard .section-heading::before {
    content: "";
    display: block;
    width: 5px;
    height: 23px;
    border-radius: 5px;
    background: var(--admin-gold);
}

/* Company cards */
.admin-dashboard .company-review-card {
    position: relative;
    overflow: hidden;
    padding: 20px;
    border: 1px solid #ece7f2;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: transform .22s ease, box-shadow .22s ease,
                border-color .22s ease;
    animation: adminFadeUp .45s ease both;
}

.admin-dashboard .company-review-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.10);
}

.admin-dashboard .company-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    flex-shrink: 0;
    border-radius: 15px;
    background: var(--admin-lavender);
}

.admin-dashboard .company-name {
    margin: 0 0 5px;
    color: var(--admin-text);
    font-size: 1.02rem;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.admin-dashboard .company-meta {
    color: var(--admin-muted);
    font-size: .87rem;
    line-height: 1.7;
}

/* Tables */
.admin-dashboard .admin-table-card {
    overflow: hidden;
    border: 1px solid #e9e4ef;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    animation: adminFadeUp .45s ease both;
}

.admin-dashboard .admin-table-card .data-table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

.admin-dashboard .admin-table-card .data-table thead {
    background: #f1edf7;
}

.admin-dashboard .admin-table-card .data-table th {
    padding: 15px 18px;
    color: var(--admin-purple);
    font-size: .87rem;
    font-weight: 750;
    text-align: start;
    white-space: nowrap;
    border-bottom: 1px solid #e6deef;
}

.admin-dashboard .admin-table-card .data-table td {
    padding: 15px 18px;
    border-bottom: 1px solid #f0edf4;
    color: #464052;
    font-size: .91rem;
    vertical-align: middle;
}

.admin-dashboard .admin-table-card .data-table tbody tr {
    transition: background .18s ease;
}

.admin-dashboard .admin-table-card .data-table tbody tr:hover {
    background: #faf8fc;
}

.admin-dashboard .admin-table-card .data-table tbody tr:last-child td {
    border-bottom: none;
}

/* Status labels */
.admin-dashboard .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 11px;
    border-radius: 30px;
    font-size: .79rem;
    font-weight: 700;
    white-space: nowrap;
}

/* Buttons */
.admin-dashboard .btn {
    transition: transform .18s ease, box-shadow .18s ease,
                background .18s ease, border-color .18s ease;
}

.admin-dashboard .btn:hover {
    transform: translateY(-1px);
}

.admin-dashboard .btn:active {
    transform: translateY(0);
}

.admin-dashboard .btn-primary {
    background: var(--admin-purple);
    border-color: var(--admin-purple);
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
}

.admin-dashboard .btn-primary:hover {
    background: var(--admin-purple-dark);
    border-color: var(--admin-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.23);
}

.admin-dashboard .btn-outline {
    border-radius: 9px;
}

.admin-dashboard .admin-action-buttons {
    display: flex;
    align-items: center;
    gap: 7px;
}

/* Empty state */
.admin-dashboard .empty-state {
    padding: 38px 20px;
    border: 1px dashed #d8cde7;
    border-radius: 16px;
    background: #fbf9fd;
    text-align: center;
    animation: adminFadeUp .4s ease both;
}

.admin-dashboard .empty-state .empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 70px;
    height: 70px;
    margin-bottom: 12px;
    border-radius: 22px;
    background: #f0ebf8;
}

.admin-dashboard .empty-state h3 {
    margin: 0;
    color: var(--admin-text);
    font-size: 1.05rem;
}

/* Divider */
.admin-dashboard .divider {
    height: 1px;
    border: 0;
    background: linear-gradient(
        90deg,
        transparent,
        #dcd2e9,
        transparent
    );
}

/* Responsive tables */
@media (max-width: 700px) {
    .admin-dashboard .admin-hero {
        padding: 30px 0;
    }

    .admin-dashboard .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .admin-dashboard .admin-table-card {
        overflow-x: auto;
    }

    .admin-dashboard .admin-table-card .data-table {
        min-width: 590px;
    }

    .admin-dashboard .company-review-card {
        align-items: flex-start !important;
    }

    .admin-dashboard .company-review-card > .flex {
        flex-wrap: wrap;
    }

    .admin-dashboard .admin-notice {
        align-items: flex-start;
    }
}

/* Motion */
@keyframes adminFadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes adminFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .admin-dashboard *,
    .admin-dashboard *::before,
    .admin-dashboard *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
</style>

<div class="admin-dashboard">

    <!-- Welcome Banner -->
    <section class="admin-hero">
        <div class="container admin-hero-inner">

            <div>
                <div class="welcome-label">
                    <span>✦</span>
                    <span>Ufuq Administration</span>
                </div>

                <h2>Welcome back, <?= e($adminName) ?>!</h2>

                <p>
                    We're glad to have you back. Here's to another productive day.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">✦</div>

        </div>
    </section>

    <div class="container admin-content">

        <?php if (isset($_GET['msg'])): ?>
            <?php
            $messages = [
                'deleted'  => 'Opportunity deleted successfully.',
                'saved'    => 'Opportunity saved successfully.',
                'approved' => 'Company approved and notified by email.',
                'rejected' => 'Company rejected and notified by email.'
            ];

            $notice = $messages[$_GET['msg']] ?? '';
            ?>

            <?php if ($notice !== ''): ?>
                <div class="admin-notice" role="status">
                    <?= icon('company', 20, 'icon-primary') ?>
                    <span><?= e($notice) ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($tab === 'verification'): ?>

            <section class="tab-panel active">

                <h3 class="section-heading">Pending Review</h3>

                <?php if (!$pending): ?>

                    <div class="empty-state" style="margin-top:20px;">
                        <div class="empty-icon">
                            <?= icon('company', 40, 'icon-muted') ?>
                        </div>

                        <h3>No companies waiting for review</h3>

                        <p class="text-muted" style="margin:8px 0 0;">
                            New company registrations will appear here.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="grid" style="grid-template-columns:1fr;gap:14px;">

                        <?php foreach ($pending as $c): ?>

                            <div class="card company-review-card flex-between"
                                 style="align-items:center;flex-wrap:wrap;gap:16px;">

                                <div class="flex gap-3"
                                     style="align-items:center;min-width:0;">

                                    <div class="company-icon">
                                        <?= icon('company', 26, 'icon-primary') ?>
                                    </div>

                                    <div style="min-width:0;">
                                        <h3 class="company-name">
                                            <?= e($c['CompanyName']) ?>
                                        </h3>

                                        <div class="company-meta">
                                            <?= e($c['IndustrySector']) ?>
                                            &middot;
                                            <?= e($c['Location']) ?>
                                        </div>
                                    </div>

                                </div>

                                <div class="flex gap-3"
                                     style="align-items:center;flex-wrap:wrap;">

                                    <span class="badge badge-warning">Pending</span>

                                    <a href="admin-company-review.php?id=<?= (int)$c['CompanyID'] ?>"
                                       class="btn btn-outline btn-sm">
                                        <?= icon('search', 14) ?>
                                        Review
                                    </a>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

                <div class="divider" style="margin:32px 0;"></div>

                <h3 class="section-heading">Reviewed Companies</h3>

                <div class="admin-table-card">
                    <table class="data-table">

                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>CR Number</th>
                                <th>Industry</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($reviewed as $c): ?>
                                <tr>
                                    <td><?= e($c['CompanyName']) ?></td>
                                    <td><?= e($c['CrNumber']) ?></td>
                                    <td><?= e($c['IndustrySector']) ?></td>

                                    <td>
                                        <span class="badge <?= $c['Status'] === 'Verified' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= e($c['Status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$reviewed): ?>
                                <tr>
                                    <td colspan="4"
                                        class="text-muted"
                                        style="text-align:center;padding:25px;">
                                        No reviewed companies yet.
                                    </td>
                                </tr>
                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>

            </section>

        <?php else: ?>

            <section class="tab-panel active">

                <div class="flex-between"
                     style="margin-bottom:20px;gap:14px;flex-wrap:wrap;">

                    <div>
                        <h3 class="section-heading" style="margin-bottom:6px;">
                            Manage Opportunities
                        </h3>

                        <p class="text-muted" style="margin:0;line-height:1.7;">
                            Opportunities added by the admin on behalf of companies not on the platform.
                        </p>
                    </div>

                    <a href="admin-opportunity-form.php" class="btn btn-primary">
                        <?= icon('add') ?>
                        Add Opportunity
                    </a>

                </div>

                <?php if (!$opps): ?>

                    <div class="empty-state">
                        <div class="empty-icon">
                            <?= icon('training', 40, 'icon-muted') ?>
                        </div>

                        <h3>No admin-added opportunities yet</h3>

                        <p class="text-muted" style="margin:8px 0 0;">
                            Add an opportunity to display it here.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="admin-table-card">
                        <table class="data-table">

                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Company</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($opps as $o): ?>
                                    <tr>
                                        <td><?= e($o['Title']) ?></td>

                                        <td>
                                            <?= e($o['OpportunityProvider']) ?: '&mdash;' ?>
                                        </td>

                                        <td><?= e($o['Type']) ?></td>

                                        <td>
                                            <span class="badge <?= $o['Status'] === 'Open' ? 'badge-success' : 'badge-neutral' ?>">
                                                <?= e($o['Status']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="admin-action-buttons">

                                                <a href="admin-opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>"
                                                   class="btn btn-ghost btn-sm"
                                                   title="Edit"
                                                   aria-label="Edit opportunity">
                                                    <?= icon('edit') ?>
                                                </a>

                                                <form method="post"
                                                      onsubmit="return confirm('Delete this opportunity? This cannot be undone.');"
                                                      style="margin:0;">

                                                    <input type="hidden"
                                                           name="csrf"
                                                           value="<?= csrf_token() ?>">

                                                    <input type="hidden"
                                                           name="action"
                                                           value="delete">

                                                    <input type="hidden"
                                                           name="id"
                                                           value="<?= (int)$o['OpportunityID'] ?>">

                                                    <button type="submit"
                                                            class="btn btn-ghost btn-sm"
                                                            title="Delete"
                                                            aria-label="Delete opportunity">
                                                        <?= icon('delete', 18, 'icon-danger') ?>
                                                    </button>

                                                </form>

                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </div>
</div>

<?php admin_footer(); ?>