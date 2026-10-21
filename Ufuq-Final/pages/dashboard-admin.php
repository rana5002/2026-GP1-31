<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

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
    SELECT o.OpportunityID, o.Title, o.OpportunityProvider, o.ViewCount,
           (SELECT COUNT(*) FROM favorite f WHERE f.OpportunityID = o.OpportunityID) AS FavouriteCount,
           (SELECT COUNT(*) FROM application a WHERE a.OpportunityID = o.OpportunityID) AS ApplicationCount
    FROM opportunity o
    ORDER BY o.ViewCount DESC, o.OpportunityID DESC
    LIMIT 10
")->fetchAll();
/* The panel shows the top 3; the triangle opens the rest (up to 10). */
$rankVisible = 3;
$maxViews = max(1, (int)($topOpportunities[0]['ViewCount'] ?? 0));
$pendingPreview = array_slice($pending, 0, 3);
$companyTotal = $pendingCount + $verifiedCount + $rejectedCount;

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

/* Toast: success message that slides down from the top, then disappears */
.admin-toast {
    position: fixed;
    top: 18px;
    left: 50%;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 11px;
    width: max-content;
    max-width: calc(100% - 32px);
    padding: 13px 14px 13px 16px;
    border: 1px solid #bfe3cc;
    border-radius: 13px;
    background: #eaf7ef;
    color: #1f6b47;
    font-size: .92rem;
    font-weight: 700;
    line-height: 1.5;
    box-shadow: 0 12px 30px rgba(31,107,71,.16);
    transform: translate(-50%, -150%);
    opacity: 0;
    transition: transform .35s ease, opacity .35s ease;
}

.admin-toast.is-shown {
    transform: translate(-50%, 0);
    opacity: 1;
}

.admin-toast-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #2f8a5c;
    color: #fff;
    font-size: .85rem;
}

.admin-toast-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: none;
    color: #1f6b47;
    font-size: 1.2rem;
    line-height: 1;
    cursor: pointer;
}

.admin-toast-close:hover {
    background: rgba(31,107,71,.1);
}

@media (prefers-reduced-motion: reduce) {
    .admin-toast { transition: none; }
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
    /* 6 cards: two even rows of 3 */
    grid-template-columns: repeat(3, minmax(0, 1fr));
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

/* ===== Dashboard tab ===== */

/* Overview: highlighted card + small cards */
.admin-dashboard .highlight-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 2fr);
    gap: 16px;
    margin-bottom: 24px;
}

.admin-dashboard .highlight-card {
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
    padding: 24px;
    border-radius: 16px;
    background: linear-gradient(125deg, #281a46 0%, #39265f 55%, #49316f 100%);
    color: #fff;
    text-decoration: none;
    box-shadow: 0 10px 26px rgba(40,26,70,.18);
    transition: transform .22s ease, box-shadow .22s ease;
    animation: adminFadeUp .45s ease both;
}

.admin-dashboard .highlight-card::after {
    content: "";
    position: absolute;
    right: -60px;
    top: -60px;
    width: 170px;
    height: 170px;
    border: 26px solid rgba(255,255,255,.07);
    border-radius: 50%;
    pointer-events: none;
}

.admin-dashboard .highlight-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 32px rgba(40,26,70,.24);
}

.admin-dashboard .highlight-title {
    display: block;
    color: rgba(255,255,255,.85);
    font-size: .95rem;
    font-weight: 700;
}

.admin-dashboard .highlight-number {
    display: block;
    margin: 10px 0 4px;
    color: #fff;
    font-size: 3rem;
    font-weight: 800;
    line-height: 1.1;
}

.admin-dashboard .highlight-note {
    display: block;
    color: rgba(255,255,255,.75);
    font-size: .86rem;
    line-height: 1.6;
}

.admin-dashboard .highlight-btn {
    align-self: flex-start;
    padding: 9px 15px;
    border-radius: 9px;
    background: var(--admin-gold);
    color: #fff;
    font-size: .86rem;
    font-weight: 700;
    transition: background .18s ease;
}

.admin-dashboard .highlight-card:hover .highlight-btn {
    background: #c48b2c;
}

.admin-dashboard .mini-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.admin-dashboard .mini-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    color: var(--admin-text);
    text-decoration: none;
    animation: adminFadeUp .45s ease both;
}

a.mini-card:hover {
    transform: translateY(-3px);
}

.admin-dashboard .mini-card .stat-icon {
    width: 38px;
    height: 38px;
    font-weight: 800;
}

.admin-dashboard .mini-number {
    display: block;
    color: var(--admin-purple);
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1.2;
}

.admin-dashboard .mini-title {
    display: block;
    margin-top: 2px;
    font-size: .84rem;
    font-weight: 700;
    line-height: 1.35;
}

/* Panels */
.admin-dashboard .panel-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr);
    align-items: start;
    gap: 18px;
    margin-top: 6px;
}

.admin-dashboard .panel-card {
    padding: 20px 22px;
    animation: adminFadeUp .5s ease both;
}

.admin-dashboard .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1px solid #eee8f4;
}

.admin-dashboard .panel-head h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    color: var(--admin-text);
    font-size: 1rem;
    font-weight: 750;
}

.admin-dashboard .panel-head h3::before {
    content: "";
    display: block;
    width: 5px;
    height: 19px;
    border-radius: 5px;
    background: var(--admin-gold);
}

.admin-dashboard .panel-hint {
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
    font-size: .74rem;
    font-weight: 700;
}

.admin-dashboard .panel-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.admin-dashboard .panel-list li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 12px;
    border: 1px solid #eee9f5;
    border-radius: 12px;
    background: #faf9fd;
}

.admin-dashboard .company-icon.small {
    width: 38px;
    height: 38px;
    border-radius: 11px;
}

.admin-dashboard .panel-list-main {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}

.admin-dashboard .panel-list-main strong {
    color: var(--admin-text);
    font-size: .92rem;
    overflow-wrap: anywhere;
}

.admin-dashboard .panel-list-main span {
    color: var(--admin-muted);
    font-size: .8rem;
}

.admin-dashboard .panel-empty {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px;
    border: 1px dashed #d8cde7;
    border-radius: 13px;
    background: #fbf9fd;
}

.admin-dashboard .panel-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    border-radius: 14px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
    font-size: 1.2rem;
    font-weight: 800;
}

.admin-dashboard .panel-empty strong {
    color: var(--admin-text);
    font-size: .95rem;
}

.admin-dashboard .panel-empty p {
    margin: 3px 0 0;
    color: var(--admin-muted);
    font-size: .84rem;
}

/* Triangle beside "Most Viewed Opportunities": opens / closes the rest of the list */
.admin-dashboard .panel-toggle {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 2px 4px 2px 0;
    border: 0;
    border-radius: 8px;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.admin-dashboard .panel-toggle {
    color: inherit;
}

.admin-dashboard .panel-toggle:hover {
    color: var(--admin-purple);
}

.admin-dashboard .panel-toggle:focus-visible {
    outline: 2px solid var(--admin-purple);
    outline-offset: 3px;
}

.admin-dashboard .panel-caret {
    flex-shrink: 0;
    width: 0;
    height: 0;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-top: 7px solid var(--admin-purple);
    transition: transform .2s ease;
}

.admin-dashboard .panel-toggle[aria-expanded="true"] .panel-caret {
    transform: rotate(180deg);
}

.admin-dashboard .rank-list li[hidden] {
    display: none;
}

.admin-dashboard .rank-list li.rank-extra:not([hidden]) {
    animation: adminFadeUp .25s ease both;
}

.admin-dashboard .visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

/* Most viewed ranking */
.admin-dashboard .rank-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.admin-dashboard .rank-list li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.admin-dashboard .rank-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    border-radius: 9px;
    background: var(--admin-lavender);
    color: var(--admin-purple);
    font-size: .82rem;
    font-weight: 800;
}

.admin-dashboard .rank-list li:first-child .rank-num {
    background: var(--admin-gold);
    color: #fff;
}

.admin-dashboard .rank-main {
    flex: 1;
    min-width: 0;
}

.admin-dashboard .rank-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
}

.admin-dashboard .rank-top strong {
    overflow: hidden;
    color: var(--admin-text);
    font-size: .9rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.admin-dashboard .rank-views {
    flex-shrink: 0;
    color: var(--admin-purple);
    font-size: .8rem;
    font-weight: 750;
}

.admin-dashboard .rank-bar {
    height: 6px;
    margin: 7px 0 6px;
    overflow: hidden;
    border-radius: 999px;
    background: #efeaf6;
}

.admin-dashboard .rank-bar span {
    display: block;
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #49316f, #7a5aa6);
}

.admin-dashboard .rank-meta {
    color: var(--admin-muted);
    font-size: .78rem;
    overflow-wrap: anywhere;
}

/* Clickable company names in Reviewed Companies */
.admin-dashboard .reviewed-company-link {
    color: var(--admin-purple);
    font-weight: 700;
    text-decoration: none;
    text-underline-offset: 3px;
}

.admin-dashboard .reviewed-company-link:hover,
.admin-dashboard .reviewed-company-link:focus-visible {
    color: var(--admin-purple-dark);
    text-decoration: underline;
}

@media (max-width: 900px) {
    .admin-dashboard .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .admin-dashboard .highlight-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .admin-dashboard .panel-grid {
        grid-template-columns: minmax(0, 1fr);
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

    .admin-dashboard .mini-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
                        <span class="mini-title">Verified</span>
                    </div>
                </a>

                <a href="dashboard-admin.php?tab=verification" class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true">✕</span>
                    <div>
                        <span class="mini-number"><?= $rejectedCount ?></span>
                        <span class="mini-title">Rejected</span>
                    </div>
                </a>

                <a href="dashboard-admin.php?tab=verification" class="dashboard-card mini-card">
                    <span class="stat-icon" aria-hidden="true"><?= icon('company', 20) ?></span>
                    <div>
                        <span class="mini-number"><?= $companyTotal ?></span>
                        <span class="mini-title">All Companies</span>
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
                        <span class="mini-title">My Opportunities</span>
                    </div>
                </a>

            </div>
        </section>

        <!-- Panels -->
        <div class="panel-grid">

            <section class="dashboard-card panel-card">
                <div class="panel-head">
                    <h3>Waiting for Review</h3>
                    <a href="dashboard-admin.php?tab=verification" class="dashboard-link">View all &rarr;</a>
                </div>

                <?php if (!$pendingPreview): ?>
                    <div class="panel-empty">
                        <span class="panel-empty-icon" aria-hidden="true">✓</span>
                        <div>
                            <strong>All caught up</strong>
                            <p>No companies are waiting for review.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <ul class="panel-list">
                        <?php foreach ($pendingPreview as $c): ?>
                            <li>
                                <span class="company-icon small" aria-hidden="true"><?= icon('company', 18, 'icon-primary') ?></span>
                                <div class="panel-list-main">
                                    <strong><?= e($c['CompanyName']) ?></strong>
                                    <span><?= e($c['IndustrySector']) ?> &middot; <?= e($c['Location']) ?></span>
                                </div>
                                <a href="admin-company-review.php?id=<?= (int)$c['CompanyID'] ?>" class="btn btn-outline btn-sm">Review</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

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
                                        &middot; <?= (int)$o['FavouriteCount'] ?> <?= (int)$o['FavouriteCount'] === 1 ? 'favorite' : 'favorites' ?>
                                        &middot; <?= (int)$o['ApplicationCount'] ?> <?= (int)$o['ApplicationCount'] === 1 ? 'application' : 'applications' ?>
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

<?php admin_footer(); ?>
