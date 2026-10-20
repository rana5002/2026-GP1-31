<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

require_login('Administrator');
$adminId = (int)$_SESSION['user_id'];

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

/* ---------- Overview numbers ---------- */
$pendingCount  = count($pending);
$verifiedCount = 0;
$rejectedCount = 0;
foreach ($reviewed as $r) {
    if ($r['Status'] === 'Verified') $verifiedCount++;
    elseif ($r['Status'] === 'Rejected') $rejectedCount++;
}
$oppCount = count($opps);

$adminName = trim($_SESSION['admin_name'] ?? 'Hatoon');

admin_header('Admin Dashboard', $tab);
?>

<style>
/* ===== Ufuq Admin Dashboard (same design as the user Home page) ===== */

.admin-dashboard {
    --admin-purple: #39265f;
    --admin-purple-dark: #281a46;
    --admin-purple-mid: #49316f;
    --admin-lavender: #f0ebf8;
    --admin-gold: #e2a94b;
    --admin-bg: #faf6ef;
    --admin-text: #29243a;
    --admin-muted: #777184;
    --admin-border: #e9e3ed;

    color: var(--admin-text);
    padding-bottom: 48px;
    background: var(--admin-bg);
    min-height: 70vh;
}

.admin-dashboard *,
.admin-dashboard *::before,
.admin-dashboard *::after {
    box-sizing: border-box;
}

.admin-dashboard a {
    text-underline-offset: 3px;
}

/* Welcome banner */
.admin-dashboard .admin-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    background: linear-gradient(125deg, #281a46 0%, #39265f 55%, #49316f 100%);
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

.admin-dashboard .admin-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.admin-dashboard .admin-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.admin-dashboard .hero-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 78px;
    height: 78px;
    flex-shrink: 0;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 24px;
    background: rgba(255,255,255,.08);
    box-shadow: 0 10px 28px rgba(15,8,30,.16);
    font-size: 2rem;
    animation: adminFloat 4s ease-in-out infinite;
}

.admin-dashboard .admin-content {
    animation: adminFadeUp .5s ease both;
}

/* Notice */
.admin-dashboard .dashboard-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 16px;
    margin-bottom: 22px;
    border: 1px solid #e4dced;
    border-radius: 13px;
    background: #f7f4fb;
    color: var(--admin-purple);
    font-size: .9rem;
    line-height: 1.6;
    animation: adminFadeUp .35s ease both;
}

/* Headings */
.admin-dashboard .dashboard-section {
    margin-bottom: 24px;
}

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
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--admin-gold);
}

.admin-dashboard .section-description {
    margin: -7px 0 16px;
    color: var(--admin-muted);
    font-size: .9rem;
    line-height: 1.7;
}

.admin-dashboard .card-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

/* Cards */
.admin-dashboard .dashboard-card {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--admin-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.admin-dashboard .dashboard-card:hover {
    border-color: #d5c8e4;
    box-shadow: 0 10px 26px rgba(40,26,70,.075);
}

/* Overview stat cards */
.admin-dashboard .stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.admin-dashboard .stat-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 150px;
    color: var(--admin-text);
    text-decoration: none;
    animation: adminFadeUp .45s ease both;
}

.admin-dashboard .stat-card:hover {
    transform: translateY(-3px);
}

.admin-dashboard .stat-card.is-active {
    border-color: var(--admin-purple);
}

.admin-dashboard .stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.admin-dashboard .stat-title {
    font-size: .93rem;
    font-weight: 700;
}

.admin-dashboard .stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    border-radius: 13px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
}

.admin-dashboard .stat-number {
    display: block;
    margin-bottom: 5px;
    color: var(--admin-purple);
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.3;
}

.admin-dashboard .stat-note {
    color: var(--admin-muted);
    font-size: .83rem;
    line-height: 1.6;
}

.admin-dashboard .dashboard-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--admin-purple);
    font-size: .86rem;
    font-weight: 700;
    text-decoration: none;
}

.admin-dashboard .dashboard-link:hover {
    color: var(--admin-purple-dark);
    text-decoration: underline;
}

/* Company review cards */
.admin-dashboard .company-review-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding: 17px;
    border: 1px solid #ece7f2;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.04);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    animation: adminFadeUp .4s ease both;
}

.admin-dashboard .company-review-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.09);
}

.admin-dashboard .company-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.admin-dashboard .company-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    border-radius: 14px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
}

.admin-dashboard .company-name {
    margin: 0 0 4px;
    color: var(--admin-text);
    font-size: .98rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.admin-dashboard .company-meta {
    color: var(--admin-muted);
    font-size: .82rem;
    line-height: 1.6;
}

.admin-dashboard .company-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.admin-dashboard .review-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* Tables */
.admin-dashboard .admin-table-card {
    overflow: hidden;
    border: 1px solid var(--admin-border);
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

/* Badges */
.admin-dashboard .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 30px;
    font-size: .76rem;
    font-weight: 750;
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
    box-shadow: 0 7px 17px rgba(40,26,70,.20);
}

.admin-dashboard .btn-outline {
    border-radius: 9px;
}

.admin-dashboard .admin-action-buttons {
    display: flex;
    align-items: center;
    gap: 7px;
}

.admin-dashboard .text-muted {
    color: var(--admin-muted);
}

/* Empty state */
.admin-dashboard .empty-state {
    padding: 30px 18px;
    border: 1px dashed #d8cde7;
    border-radius: 14px;
    background: #fbf9fd;
    text-align: center;
}

.admin-dashboard .empty-state .empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    margin-bottom: 11px;
    border-radius: 19px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
}

.admin-dashboard .empty-state h3 {
    margin: 0;
    color: var(--admin-text);
    font-size: 1rem;
    font-weight: 750;
}

.admin-dashboard .empty-state p {
    margin: 7px 0 0;
    color: var(--admin-muted);
    font-size: .87rem;
    line-height: 1.7;
}

/* Divider */
.admin-dashboard .dashboard-divider {
    height: 1px;
    margin: 28px 0;
    border: 0;
    background: linear-gradient(90deg, transparent, #dcd2e9, transparent);
}

@media (max-width: 900px) {
    .admin-dashboard .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .admin-dashboard .admin-hero {
        padding: 30px 0;
    }

    .admin-dashboard .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .admin-dashboard .stat-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .admin-dashboard .stat-card {
        min-height: 130px;
    }

    .admin-dashboard .dashboard-card {
        padding: 16px;
    }

    .admin-dashboard .admin-table-card {
        overflow-x: auto;
    }

    .admin-dashboard .admin-table-card .data-table {
        min-width: 590px;
    }
}

@keyframes adminFadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes adminFloat {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-5px); }
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
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Administration</span>
                </div>

                <h1>Welcome back, <?= e($adminName) ?>!</h1>

                <p>
                    Review companies, manage opportunities,
                    and keep the platform running smoothly.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">✦</div>

        </div>
    </section>

    <div class="container admin-content">

        <!-- Feedback Messages -->
        <?php
        $messages = [
            'deleted'  => 'Opportunity deleted successfully.',
            'saved'    => 'Opportunity saved successfully.',
            'approved' => 'Company approved and notified by email.',
            'rejected' => 'Company rejected and notified by email.'
        ];
        $notice = $messages[$_GET['msg'] ?? ''] ?? '';
        ?>

        <?php if ($notice !== ''): ?>
            <div class="dashboard-notice" role="status">
                <span aria-hidden="true">✓</span>
                <span><?= e($notice) ?></span>
            </div>
        <?php endif; ?>

        <!-- Overview -->
        <section class="stat-grid" aria-label="Dashboard overview">

            <a href="dashboard-admin.php?tab=verification"
               class="dashboard-card stat-card <?= $tab === 'verification' ? 'is-active' : '' ?>">
                <div class="stat-top">
                    <span class="stat-title">Pending Review</span>
                    <span class="stat-icon" aria-hidden="true"><?= icon('company', 22) ?></span>
                </div>
                <div>
                    <span class="stat-number"><?= $pendingCount ?></span>
                    <span class="stat-note">Companies waiting for verification.</span>
                </div>
            </a>

            <a href="dashboard-admin.php?tab=verification"
               class="dashboard-card stat-card">
                <div class="stat-top">
                    <span class="stat-title">Verified Companies</span>
                    <span class="stat-icon" aria-hidden="true">✓</span>
                </div>
                <div>
                    <span class="stat-number"><?= $verifiedCount ?></span>
                    <span class="stat-note">Approved to publish opportunities.</span>
                </div>
            </a>

            <a href="dashboard-admin.php?tab=verification"
               class="dashboard-card stat-card">
                <div class="stat-top">
                    <span class="stat-title">Rejected Companies</span>
                    <span class="stat-icon" aria-hidden="true">✕</span>
                </div>
                <div>
                    <span class="stat-number"><?= $rejectedCount ?></span>
                    <span class="stat-note">Registrations that were declined.</span>
                </div>
            </a>

            <a href="dashboard-admin.php?tab=opportunities"
               class="dashboard-card stat-card <?= $tab === 'opportunities' ? 'is-active' : '' ?>">
                <div class="stat-top">
                    <span class="stat-title">My Opportunities</span>
                    <span class="stat-icon" aria-hidden="true"><?= icon('training', 22) ?></span>
                </div>
                <div>
                    <span class="stat-number"><?= $oppCount ?></span>
                    <span class="stat-note">Opportunities added by the admin.</span>
                </div>
            </a>

        </section>

        <?php if ($tab === 'verification'): ?>

            <!-- Pending Review -->
            <section class="dashboard-section">

                <div class="card-heading-row">
                    <div>
                        <h2 class="section-heading" style="margin-bottom:7px;">Pending Review</h2>
                        <p class="section-description" style="margin:0;">
                            New company registrations waiting for your decision.
                        </p>
                    </div>
                </div>

                <?php if (!$pending): ?>

                    <div class="empty-state">
                        <div class="empty-icon" aria-hidden="true">
                            <?= icon('company', 30, 'icon-muted') ?>
                        </div>

                        <h3>No companies waiting for review</h3>

                        <p>New company registrations will appear here.</p>
                    </div>

                <?php else: ?>

                    <div class="review-list">

                        <?php foreach ($pending as $c): ?>

                            <div class="company-review-card">

                                <div class="company-info">
                                    <div class="company-icon" aria-hidden="true">
                                        <?= icon('company', 24, 'icon-primary') ?>
                                    </div>

                                    <div style="min-width:0;">
                                        <h3 class="company-name"><?= e($c['CompanyName']) ?></h3>

                                        <div class="company-meta">
                                            <?= e($c['IndustrySector']) ?>
                                            &middot;
                                            <?= e($c['Location']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="company-actions">
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

            </section>

            <hr class="dashboard-divider">

            <!-- Reviewed Companies -->
            <section class="dashboard-section">

                <div class="card-heading-row">
                    <div>
                        <h2 class="section-heading" style="margin-bottom:7px;">Reviewed Companies</h2>
                        <p class="section-description" style="margin:0;">
                            Companies that have already been approved or rejected.
                        </p>
                    </div>
                </div>

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

            <!-- Manage Opportunities -->
            <section class="dashboard-section">

                <div class="card-heading-row">
                    <div>
                        <h2 class="section-heading" style="margin-bottom:7px;">Manage Opportunities</h2>

                        <p class="section-description" style="margin:0;">
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
                        <div class="empty-icon" aria-hidden="true">
                            <?= icon('training', 30, 'icon-muted') ?>
                        </div>

                        <h3>No admin-added opportunities yet</h3>

                        <p>Add an opportunity to display it here.</p>
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
