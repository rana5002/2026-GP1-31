<?php


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/confirm-modal.php';

require_login('Administrator');
$adminId = (int)$_SESSION['user_id'];

/* Tabs: dashboard (overview numbers), verification (companies), opportunities (admin-added). */
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, ['dashboard', 'verification', 'opportunities'], true)) {
    $tab = 'dashboard';
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
    SELECT CompanyID, CompanyName, CrNumber, IndustrySector, Status
    FROM company
    WHERE Status <> 'Pending'
    ORDER BY CompanyID
")->fetchAll();

/* Admin-added opportunities, with views (ViewCount) and how many users saved them (favorite). */
$opps = $pdo->query("
    SELECT o.OpportunityID, o.Title, o.OpportunityProvider, o.Type, o.Status,
           o.ViewCount AS ViewsCount,
           (SELECT COUNT(*) FROM favorite f WHERE f.OpportunityID = o.OpportunityID) AS FavouriteCount
    FROM opportunity o
    WHERE o.CreatedByAdminID IS NOT NULL
    ORDER BY o.OpportunityID DESC
")->fetchAll();

/* Platform-wide numbers (company and admin opportunities). */
$platform = $pdo->query("
    SELECT SUM(Status = 'Open') AS OpenCount,
           COALESCE(SUM(ViewCount), 0) AS TotalViews
    FROM opportunity
")->fetch();

/* ---------- Overview numbers ---------- */
$pendingCount  = count($pending);
$verifiedCount = 0;
$rejectedCount = 0;
foreach ($reviewed as $r) {
    if ($r['Status'] === 'Verified') $verifiedCount++;
    elseif ($r['Status'] === 'Rejected') $rejectedCount++;
}
$oppCount = count($opps);
$openCount = (int)($platform['OpenCount'] ?? 0);
$totalViews = (int)($platform['TotalViews'] ?? 0);

/* Name of the logged-in administrator, from the administrator table. */
$st = $pdo->prepare('SELECT FirstName FROM administrator WHERE AdminID = ?');
$st->execute([$adminId]);
$adminName = trim((string)($st->fetchColumn() ?: '')) ?: 'Admin';

/* Dashboard panels: most viewed opportunities on the platform, and the newest companies waiting for review. */
$topOpportunities = $pdo->query("
    SELECT o.OpportunityID, o.Title, o.OpportunityProvider, o.ViewCount
    FROM opportunity o
    ORDER BY o.ViewCount DESC, o.OpportunityID DESC
    LIMIT 10
")->fetchAll();
/* The panel shows the top 3; the triangle opens the rest (up to 10). */
$rankVisible = 3;
$maxViews = max(1, (int)($topOpportunities[0]['ViewCount'] ?? 0));


$extraCss = '../css/dashboard-admin.css';
admin_header('Admin Dashboard', $tab);
?>


<div class="admin-dashboard">

    <!-- Welcome Banner -->
    <section class="admin-hero">
        <div class="container admin-hero-inner">

            <div>
                <div class="welcome-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Administration Space</span>                </div>

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
            'added'    => 'Opportunity added successfully.',
            'updated'  => 'Opportunity updated successfully.',
            'deleted'  => 'Opportunity deleted successfully.',
            'saved'    => 'Opportunity saved successfully.',
            'approved' => 'Company approved and notified by email.',
            'rejected' => 'Company rejected and notified by email.'
        ];
        $notice = $messages[$_GET['msg'] ?? ''] ?? '';
        ?>

        <?php if ($notice !== ''): ?>
            <div class="admin-toast" id="adminToast" role="status" aria-live="polite">
                <span class="admin-toast-icon" aria-hidden="true">✓</span>
                <span><?= e($notice) ?></span>
                <button type="button" class="admin-toast-close" aria-label="Close">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>

        <!-- Dashboard: overview -->
        <div class="card-heading-row">
            <div>
                <h2 class="section-heading" style="margin-bottom:7px;">Overview</h2>
                <p class="section-description" style="margin:0;">
                    A quick look at companies and opportunities on the platform.
                </p>
            </div>
        </div>

        <!-- Overview cards: Pending Review highlighted, the rest as small cards -->
        <section class="highlight-grid" aria-label="Dashboard overview">

            <a href="dashboard-admin.php?tab=verification" class="highlight-card">
                <div>
                    <span class="highlight-title">Pending Review</span>
                    <span class="highlight-number"><?= $pendingCount ?></span>
                    <span class="highlight-note">
                        <?= $pendingCount === 0
                            ? 'No companies are waiting for your decision.'
                            : ($pendingCount === 1 ? 'Company waiting for your decision.' : 'Companies waiting for your decision.') ?>
                    </span>
                </div>
                <span class="highlight-btn">Review companies &rarr;</span>
            </a>

            <div class="mini-grid">

                <a href="dashboard-admin.php?tab=verification" class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true">✓</span>
                    <div>
                        <span class="mini-number"><?= $verifiedCount ?></span>
                        <span class="mini-title">Verified Companies</span>
                    </div>
                </a>

                <a href="dashboard-admin.php?tab=verification" class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true">✕</span>
                    <div>
                        <span class="mini-number"><?= $rejectedCount ?></span>
                        <span class="mini-title">Rejected Companies</span>
                    </div>
                </a>

                <div class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true"><?= icon('training', 20) ?></span>
                    <div>
                        <span class="mini-number"><?= $openCount ?></span>
                        <span class="mini-title">Open Opportunities</span>
                    </div>
                </div>

                <div class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true"><?= icon('goal', 20) ?></span>
                    <div>
                        <span class="mini-number"><?= number_format($totalViews) ?></span>
                        <span class="mini-title">Total Views</span>
                    </div>
                </div>

                <a href="dashboard-admin.php?tab=opportunities" class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true"><?= icon('training', 20) ?></span>
                    <div>
                        <span class="mini-number"><?= $oppCount ?></span>
                        <span class="mini-title">Opportunities by Administrators</span>
                    </div>
                </a>

            </div>
        </section>

        <!-- Panels -->
        <div class="panel-grid">

            <section class="dashboard-card panel-card">
                <div class="panel-head">
                    <?php if (count($topOpportunities) > $rankVisible): ?>
                        <h3>
                            <button type="button" class="panel-toggle" id="rankToggle" aria-expanded="false" aria-controls="rankList">
                                Most Viewed Opportunities
                                <span class="panel-caret" aria-hidden="true"></span>
                                <span class="visually-hidden">Show more opportunities</span>
                            </button>
                        </h3>
                    <?php else: ?>
                        <h3>Most Viewed Opportunities</h3>
                    <?php endif; ?>
                    <span class="panel-hint">Whole platform</span>
                </div>

                <?php if (!$topOpportunities): ?>
                    <div class="panel-empty">
                        <span class="panel-empty-icon" aria-hidden="true">✦</span>
                        <div>
                            <strong>No opportunities yet</strong>
                            <p>Views will appear here once opportunities are published.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <ol class="rank-list" id="rankList">
                        <?php foreach ($topOpportunities as $i => $o): ?>
                            <li <?= $i >= $rankVisible ? 'class="rank-extra" hidden' : '' ?>>
                                <span class="rank-num"><?= $i + 1 ?></span>
                                <div class="rank-main">
                                    <div class="rank-top">
                                        <strong title="<?= e($o['Title']) ?>"><?= e($o['Title']) ?></strong>
                                        <span class="rank-views"><?= number_format((int)$o['ViewCount']) ?> <?= (int)$o['ViewCount'] === 1 ? 'view' : 'views' ?></span>
                                    </div>
                                    <div class="rank-bar" aria-hidden="true"><span style="width:<?= round((int)$o['ViewCount'] / $maxViews * 100, 2) ?>%"></span></div>
                                    <div class="rank-meta">
                                        <?= e($o['OpportunityProvider']) ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

        </div>

        <?php elseif ($tab === 'verification'): ?>

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
                                    <td>
                                        <a
                                            class="reviewed-company-link"
                                            href="admin-company-review.php?id=<?= (int)$c['CompanyID'] ?>"
                                        >
                                            <?= e($c['CompanyName']) ?>
                                        </a>
                                    </td>
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
                                    <th>Views</th>
                                    <th>Favorites</th>
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

                                        <td><strong><?= (int)$o['ViewsCount'] ?></strong></td>

                                        <td><strong><?= (int)$o['FavouriteCount'] ?></strong></td>

                                        <td>
                                            <div class="admin-action-buttons">

                                                <a href="admin-opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>"
                                                   class="btn btn-ghost btn-sm"
                                                   title="Edit"
                                                   aria-label="Edit opportunity">
                                                    <?= icon('edit') ?>
                                                </a>

                                                <button type="button"
                                                        class="btn btn-ghost btn-sm"
                                                        title="Delete"
                                                        aria-label="Delete opportunity"
                                                        onclick="openConfirmModal('deleteAdminOppModal', <?= (int)$o['OpportunityID'] ?>)">
                                                    <?= icon('delete', 18, 'icon-danger') ?>
                                                </button>

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

    <!-- Shared confirmation modal, matching the Company dashboard delete modal. -->
    <?php
    confirm_modal([
        'id'            => 'deleteAdminOppModal',
        'title'         => 'Delete Opportunity?',
        'message'       => 'This opportunity will be permanently deleted. This cannot be undone.',
        'confirm_label' => 'Yes, Delete',
        'icon'          => 'trash',
        'fields'        => ['action' => 'delete'],
        'id_field'      => 'id',
    ]);
    ?>
</div>

<script>
/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('adminToast');
    if (!toast) return;

    /* Move it to <body> so the page animations do not affect its fixed position. */
    document.body.appendChild(toast);

    /* Remove ?msg= from the address so a refresh does not show the message again. */
    const url = new URL(window.location.href);
    if (url.searchParams.has('msg')) {
        url.searchParams.delete('msg');
        history.replaceState(null, '', url.pathname + url.search + url.hash);
    }

    function hide() {
        toast.classList.remove('is-shown');
        setTimeout(function () { toast.remove(); }, 400);
    }

    requestAnimationFrame(function () { toast.classList.add('is-shown'); });
    const timer = setTimeout(hide, 3500);
    toast.querySelector('.admin-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();

/* Most Viewed Opportunities: the triangle shows / hides the rest of the list. */
(function () {
    const toggle = document.getElementById('rankToggle');
    if (!toggle) return;
    const extras = document.querySelectorAll('#rankList .rank-extra');
    const label = toggle.querySelector('.visually-hidden');
    toggle.addEventListener('click', function () {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        extras.forEach(function (li) { li.hidden = !open; });
        toggle.setAttribute('aria-expanded', String(open));
        label.textContent = open ? 'Show fewer opportunities' : 'Show more opportunities';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>