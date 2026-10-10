
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';

/* ---------- Authentication ---------- */
$uid = require_user();

/* ---------- Load current user ---------- */
$userStmt = $pdo->prepare("
    SELECT u.*, m.Email
    FROM `user` u
    JOIN member m ON m.MemberID = u.UserID
    WHERE u.UserID = ?
    LIMIT 1
");
$userStmt->execute([$uid]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: login.php');
    exit;
}

/* ---------- Helper functions ---------- */
if (!function_exists('history_format_date')) {
    function history_format_date($date): string
    {
        if (empty($date)) {
            return '—';
        }

        try {
            return (new DateTime($date))->format('d M Y');
        } catch (Throwable $e) {
            return '—';
        }
    }
}

if (!function_exists('history_normalize_status')) {
    function history_normalize_status($status): string
    {
        return strtolower(trim((string)$status));
    }
}

if (!function_exists('history_status_class')) {
    function history_status_class($status): string
    {
        $status = history_normalize_status($status);

        if (in_array($status, ['accepted', 'approved'], true)) {
            return 'status-accepted';
        }

        if ($status === 'shortlisted') {
            return 'status-shortlisted';
        }

        if (in_array($status, ['rejected', 'declined'], true)) {
            return 'status-rejected';
        }

        if (in_array($status, ['under review', 'reviewing', 'in review'], true)) {
            return 'status-review';
        }

        if (in_array($status, ['withdrawn', 'cancelled', 'canceled'], true)) {
            return 'status-withdrawn';
        }

        return 'status-pending';
    }
}

if (!function_exists('history_display_status')) {
    function history_display_status($status): string
    {
        $status = trim((string)$status);

        if ($status === '') {
            return 'Pending';
        }

        return ucwords(str_replace(['_', '-'], ' ', $status));
    }
}

/* ---------- Withdraw application ---------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'withdraw_application'
) {
    csrf_check();

    $opportunityId = filter_input(
        INPUT_POST,
        'opportunity_id',
        FILTER_VALIDATE_INT
    );

    if (!$opportunityId || $opportunityId < 1) {
        header('Location: application-history.php?msg=invalid');
        exit;
    }

    /*
     * Users can withdraw only their own pending applications.
     */
    $deleteStmt = $pdo->prepare("
        DELETE FROM application
        WHERE UserID = ?
          AND OpportunityID = ?
          AND LOWER(TRIM(ApplicationStatus)) = 'pending'
    ");

    $deleteStmt->execute([$uid, $opportunityId]);

    if ($deleteStmt->rowCount() > 0) {
        header('Location: application-history.php?msg=withdrawn');
    } else {
        header('Location: application-history.php?msg=not_pending');
    }

    exit;
}

/* ---------- Load application history ---------- */
$appStmt = $pdo->prepare("
    SELECT
        a.UserID,
        a.OpportunityID,
        a.SubmissionDate,
        a.ApplicationStatus,
        o.Title AS OpportunityTitle,
        COALESCE(
            NULLIF(TRIM(o.OpportunityProvider), ''),
            'External Company'
        ) AS CompanyName,
        o.Type AS OpportunityType,
        o.Location AS OpportunityLocation
    FROM application a
    LEFT JOIN opportunity o
        ON a.OpportunityID = o.OpportunityID
    WHERE a.UserID = ?
    ORDER BY
        a.SubmissionDate DESC,
        a.OpportunityID DESC
");

$appStmt->execute([$uid]);
$applications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

$totalApplications = count($applications);

/* ---------- Feedback messages ---------- */
$notice = '';
$noticeType = 'warning';

switch ($_GET['msg'] ?? '') {
    case 'withdrawn':
        $notice = 'Your pending application has been withdrawn successfully.';
        $noticeType = 'success';
        break;

    case 'not_pending':
        $notice = 'This application could not be withdrawn. It may no longer be pending.';
        break;

    case 'invalid':
        $notice = 'Invalid application. Please try again.';
        break;
}

/* ---------- Page header ---------- */
user_header($me, 'Application History', 'history');

?>

<style>
/* =====================================================
   Ufuq Application History
   Matches the User Home dashboard theme
   ===================================================== */

.history-dashboard {
    --user-purple: #39265f;
    --user-purple-dark: #281a46;
    --user-purple-mid: #49316f;
    --user-lavender: #f0ebf8;
    --user-gold: #e2a94b;
    --user-bg: #faf6ef;
    --user-text: #29243a;
    --user-muted: #777184;
    --user-border: #e9e3ed;

    min-height: 70vh;
    padding-bottom: 48px;
    background: var(--user-bg);
    color: var(--user-text);
    font-family: inherit;
}

.history-dashboard *,
.history-dashboard *::before,
.history-dashboard *::after {
    box-sizing: border-box;
}

.history-dashboard a {
    text-underline-offset: 3px;
}

/* ---------- Hero ---------- */

.history-dashboard .dashboard-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    color: #fff;
    background: linear-gradient(
        125deg,
        #281a46 0%,
        #39265f 55%,
        #49316f 100%
    );
}

.history-dashboard .dashboard-hero::before,
.history-dashboard .dashboard-hero::after {
    position: absolute;
    content: "";
    border-radius: 50%;
    pointer-events: none;
}

.history-dashboard .dashboard-hero::before {
    width: 250px;
    height: 250px;
    top: -150px;
    right: 7%;
    border: 35px solid rgba(255,255,255,.07);
}

.history-dashboard .dashboard-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.history-dashboard .dashboard-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.history-dashboard .welcome-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px;
    background: rgba(255,255,255,.08);
    color: #fff;
    font-size: .84rem;
    letter-spacing: .2px;
}

.history-dashboard .welcome-label span:first-child {
    color: var(--user-gold);
}

.history-dashboard .dashboard-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.history-dashboard .dashboard-hero p {
    max-width: 600px;
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.history-dashboard .hero-icon {
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
    color: #fff;
}

.history-dashboard .hero-icon svg {
    width: 34px;
    height: 34px;
}

/* ---------- Content ---------- */

.history-dashboard .dashboard-content {
    padding-top: 0;
    animation: historyFadeUp .45s ease both;
}

.history-dashboard .dashboard-section {
    margin-bottom: 24px;
}

.history-dashboard .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 16px;
    color: var(--user-text);
    font-size: 1.18rem;
    font-weight: 750;
}

.history-dashboard .section-heading::before {
    display: block;
    width: 5px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--user-gold);
    content: "";
}

.history-dashboard .section-description {
    margin: -7px 0 18px;
    color: var(--user-muted);
    font-size: .9rem;
    line-height: 1.7;
}

/* ---------- Total applications ---------- */

.history-dashboard .total-applications-card {
    display: flex;
    align-items: center;
    gap: 16px;
    width: 100%;
    max-width: 360px;
    min-height: 130px;
    padding: 22px;
    border: 1px solid var(--user-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: box-shadow .22s ease, transform .22s ease;
}

.history-dashboard .total-applications-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 26px rgba(40,26,70,.075);
}

.history-dashboard .total-applications-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    flex-shrink: 0;
    border-radius: 15px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.history-dashboard .total-applications-icon svg {
    width: 27px;
    height: 27px;
}

.history-dashboard .total-applications-info {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 0;
}

.history-dashboard .total-applications-label {
    color: var(--user-muted);
    font-size: .9rem;
    font-weight: 700;
    line-height: 1.5;
}

.history-dashboard .total-applications-info strong {
    color: var(--user-purple);
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}

/* ---------- Main applications panel ---------- */

.history-dashboard .dashboard-card {
    min-width: 0;
    padding: 22px;
    border: 1px solid var(--user-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
}

.history-dashboard .application-list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.history-dashboard .application-list-heading h2 {
    margin: 0;
    color: var(--user-text);
    font-size: 1.18rem;
    font-weight: 750;
}

.history-dashboard .application-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 34px;
    padding: 0 11px;
    border-radius: 10px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: .85rem;
    font-weight: 800;
}

/* ---------- One application per row ---------- */

.history-dashboard .application-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
}

.history-dashboard .application-row {
    display: flex;
    align-items: stretch;
    gap: 20px;
    width: 100%;
    min-width: 0;
    padding: 21px;
    border: 1px solid #ece7f2;
    border-radius: 15px;
    background: #fff;
    transition:
        border-color .22s ease,
        box-shadow .22s ease,
        transform .22s ease;
    animation: historyFadeUp .35s ease both;
}

.history-dashboard .application-row:hover {
    transform: translateY(-2px);
    border-color: #d5c8e4;
    box-shadow: 0 9px 23px rgba(40,26,70,.065);
}

.history-dashboard .application-main {
    display: flex;
    flex: 1;
    align-items: flex-start;
    gap: 15px;
    min-width: 0;
}

.history-dashboard .company-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border-radius: 15px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.history-dashboard .company-logo svg {
    width: 25px;
    height: 25px;
}

.history-dashboard .application-details {
    flex: 1;
    min-width: 0;
}

.history-dashboard .company-name {
    margin-bottom: 5px;
    color: var(--user-muted);
    font-size: .82rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.history-dashboard .opportunity-title {
    margin: 0 0 12px;
    color: var(--user-text);
    font-size: 1.02rem;
    font-weight: 750;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.history-dashboard .opportunity-title a {
    color: inherit;
    text-decoration: none;
}

.history-dashboard .opportunity-title a:hover {
    color: var(--user-purple);
    text-decoration: underline;
}

.history-dashboard .application-badges {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.history-dashboard .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 6px 11px;
    border-radius: 30px;
    font-size: .77rem;
    font-weight: 750;
    line-height: 1.5;
}

.history-dashboard .badge-course {
    background: #e8f5ec;
    color: #24653c;
}

.history-dashboard .badge-program {
    background: var(--user-lavender);
    color: var(--user-purple);
}

.history-dashboard .status-pending {
    background: #fff4dc;
    color: #93630c;
}

.history-dashboard .status-accepted {
    background: #e5f5eb;
    color: #247244;
}

.history-dashboard .status-shortlisted {
    background: #e8f1ff;
    color: #2c5796;
}

.history-dashboard .status-rejected {
    background: #fdeaea;
    color: #a32e37;
}

.history-dashboard .status-review {
    background: #eaf0ff;
    color: #455b9b;
}

.history-dashboard .status-withdrawn {
    background: #f0edf2;
    color: #6f6878;
}

/* ---------- Submission details ---------- */

.history-dashboard .application-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 22px;
    margin-top: 17px;
}

.history-dashboard .meta-item {
    display: inline-flex;
    align-items: flex-start;
    gap: 8px;
    min-width: 0;
    color: var(--user-muted);
    font-size: .83rem;
    line-height: 1.65;
    overflow-wrap: anywhere;
}

.history-dashboard .meta-item svg {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
    margin-top: 2px;
    color: var(--user-purple-mid);
}

.history-dashboard .meta-item strong {
    color: var(--user-text);
    font-weight: 700;
}

/* ---------- Actions ---------- */

.history-dashboard .application-actions {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: center;
    gap: 10px;
    width: 145px;
    flex-shrink: 0;
    padding-left: 18px;
    border-left: 1px solid #f0edf4;
}

.history-dashboard .dashboard-link,
.history-dashboard .withdraw-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    min-height: 39px;
    padding: 9px 12px;
    border: 1px solid #ded4eb;
    border-radius: 10px;
    background: #fff;
    color: var(--user-purple);
    font-family: inherit;
    font-size: .82rem;
    font-weight: 750;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition:
        background .2s ease,
        border-color .2s ease,
        transform .2s ease;
}

.history-dashboard .dashboard-link:hover {
    border-color: var(--user-purple);
    background: var(--user-lavender);
}

.history-dashboard .withdraw-form {
    width: 100%;
    margin: 0;
}

.history-dashboard .withdraw-button {
    border-color: #f1cccc;
    color: #a32e37;
}

.history-dashboard .withdraw-button:hover {
    border-color: #dca3a7;
    background: #fff0f0;
}

.history-dashboard .dashboard-link:focus-visible,
.history-dashboard .withdraw-button:focus-visible {
    outline: 3px solid rgba(226,169,75,.65);
    outline-offset: 3px;
}

.history-dashboard .unavailable-label {
    color: var(--user-muted);
    font-size: .8rem;
    line-height: 1.6;
    text-align: center;
}

/* ---------- Feedback messages ---------- */

.history-dashboard .dashboard-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px;
    margin-bottom: 22px;
    border: 1px solid #e4dced;
    border-radius: 13px;
    background: #f7f4fb;
    color: var(--user-purple);
    font-size: .9rem;
    line-height: 1.7;
}

.history-dashboard .notice-success {
    border-color: #cde9d6;
    background: #eef9f1;
    color: #246b3c;
}

.history-dashboard .notice-warning {
    border-color: #f0dfb6;
    background: #fff8e9;
    color: #805d1c;
}

.history-dashboard .notice-icon {
    flex-shrink: 0;
    font-weight: 800;
}

/* ---------- Empty state ---------- */

.history-dashboard .empty-state {
    padding: 38px 20px;
    border: 1px dashed #d8cde7;
    border-radius: 14px;
    background: #fbf9fd;
    text-align: center;
}

.history-dashboard .empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 62px;
    height: 62px;
    margin-bottom: 13px;
    border-radius: 19px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.history-dashboard .empty-icon svg {
    width: 30px;
    height: 30px;
}

.history-dashboard .empty-state h3 {
    margin: 0;
    color: var(--user-text);
    font-size: 1.05rem;
    font-weight: 750;
}

.history-dashboard .empty-state p {
    max-width: 470px;
    margin: 8px auto 18px;
    color: var(--user-muted);
    font-size: .9rem;
    line-height: 1.8;
}

.history-dashboard .primary-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 11px 18px;
    border: 1px solid var(--user-purple);
    border-radius: 10px;
    background: var(--user-purple);
    color: #fff;
    font-size: .85rem;
    font-weight: 750;
    text-decoration: none;
    transition: background .2s ease, transform .2s ease;
}

.history-dashboard .primary-link:hover {
    transform: translateY(-1px);
    background: var(--user-purple-dark);
}

/* ---------- Animations ---------- */

@keyframes historyFadeUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ---------- Responsive ---------- */

@media (max-width: 760px) {
    .history-dashboard .application-row {
        flex-direction: column;
        gap: 18px;
        padding: 18px;
    }

    .history-dashboard .application-actions {
        flex-direction: row;
        align-items: center;
        justify-content: flex-start;
        width: 100%;
        padding: 15px 0 0;
        border-top: 1px solid #f0edf4;
        border-left: none;
    }

    .history-dashboard .application-actions > * {
        flex: 1;
        min-width: 0;
    }

    .history-dashboard .dashboard-link,
    .history-dashboard .withdraw-button {
        min-height: 42px;
    }
}

@media (max-width: 620px) {
    .history-dashboard .dashboard-hero {
        padding: 30px 0;
    }

    .history-dashboard .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
    }

    .history-dashboard .hero-icon svg {
        width: 27px;
        height: 27px;
    }

    .history-dashboard .dashboard-card {
        padding: 16px;
    }

    .history-dashboard .total-applications-card {
        max-width: 100%;
        min-height: 115px;
        padding: 18px;
    }

    .history-dashboard .application-list-heading h2 {
        font-size: 1.05rem;
    }

    .history-dashboard .application-main {
        gap: 11px;
    }

    .history-dashboard .company-logo {
        width: 44px;
        height: 44px;
        border-radius: 12px;
    }

    .history-dashboard .company-logo svg {
        width: 22px;
        height: 22px;
    }

    .history-dashboard .opportunity-title {
        font-size: .96rem;
    }

    .history-dashboard .application-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}

@media (max-width: 380px) {
    .history-dashboard .application-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .history-dashboard .application-actions > * {
        width: 100%;
        flex: auto;
    }

    .history-dashboard .dashboard-hero h1 {
        font-size: 1.5rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .history-dashboard *,
    .history-dashboard *::before,
    .history-dashboard *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>

<div class="user-dashboard history-dashboard">

    <!-- Welcome banner -->
    <section class="dashboard-hero">
        <div class="container dashboard-hero-inner">

            <div>
                <div class="welcome-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Application History</h1>

                <p>
                    Keep track of your training applications, review
                    their status, and manage your pending applications
                    in one place.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M7 3.5h7l5 5V20a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z"/>
                    <path d="M14 3.5V9h5"/>
                    <path d="M9 13h6"/>
                    <path d="M9 16.5h6"/>
                </svg>
            </div>

        </div>
    </section>

    <div class="container dashboard-content">

        <!-- Feedback -->
        <?php if ($notice !== ''): ?>
            <div
                class="dashboard-notice <?= $noticeType === 'success'
                    ? 'notice-success'
                    : 'notice-warning' ?>"
                role="status"
            >
                <span class="notice-icon" aria-hidden="true">
                    <?= $noticeType === 'success' ? '✓' : '!' ?>
                </span>

                <span><?= e($notice) ?></span>
            </div>
        <?php endif; ?>

        <!-- Total applications -->
        <section class="dashboard-section">

            <h2 class="section-heading">Application Overview</h2>

            <div class="total-applications-card">

                <div class="total-applications-icon" aria-hidden="true">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M7 3.5h7l5 5V20a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z"/>
                        <path d="M14 3.5V9h5"/>
                        <path d="M9 13h6"/>
                        <path d="M9 16.5h6"/>
                    </svg>
                </div>

                <div class="total-applications-info">
                    <span class="total-applications-label">
                        Total Applications
                    </span>

                    <strong><?= number_format($totalApplications) ?></strong>
                </div>

            </div>

        </section>

        <!-- Application history -->
        <section class="dashboard-section">

            <div class="dashboard-card">

                <div class="application-list-heading">
                    <h2>Your Applications</h2>

                   <div class="stat-row">
                    <span class="stat-label">Total Applications</span>
                    <span class="stat-value"><?= $totalApplications ?></span>
                </div>
                </div>

                <p class="section-description">
                    Review your submitted applications, submission dates,
                    and the latest status available for each opportunity.
                </p>

                <?php if (empty($applications)): ?>

                    <div class="empty-state">

                        <div class="empty-icon" aria-hidden="true">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M7 3.5h7l5 5V20a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 6 20V5A1.5 1.5 0 0 1 7.5 3.5Z"/>
                                <path d="M14 3.5V9h5"/>
                                <path d="M9 13h6"/>
                                <path d="M9 16.5h4"/>
                            </svg>
                        </div>

                        <h3>No Applications Yet</h3>

                        <p>
                            You haven't applied to any training opportunities
                            yet. Explore available courses and programs to
                            find opportunities that match your interests.
                        </p>

                        <a href="browse.php" class="primary-link">
                            Browse Opportunities
                            <span aria-hidden="true">→</span>
                        </a>

                    </div>

                <?php else: ?>

                    <!-- Each application occupies its own full-width row -->
                    <div class="application-list">

                        <?php foreach ($applications as $application): ?>

                            <?php
                            $opportunityId = (int)$application['OpportunityID'];

                            $title = trim(
                                (string)($application['OpportunityTitle'] ?? '')
                            );

                            $company = trim(
                                (string)($application['CompanyName'] ?? '')
                            );

                            $type = trim(
                                (string)($application['OpportunityType'] ?? '')
                            );

                            $location = trim(
                                (string)($application['OpportunityLocation'] ?? '')
                            );

                            $rawStatus = $application['ApplicationStatus'] ?? 'Pending';

                            $status = history_display_status($rawStatus);
                            $statusClass = history_status_class($rawStatus);

                            $isPending = (
                                history_normalize_status($rawStatus) === 'pending'
                            );

                            $hasOpportunity = ($title !== '');

                            if ($company === '') {
                                $company = 'External Company';
                            }

                            $typeClass = (
                                strtolower($type) === 'course'
                            ) ? 'badge-course' : 'badge-program';
                            ?>

                            <article class="application-row">

                                <!-- Opportunity information -->
                                <div class="application-main">

                                    <div class="company-logo" aria-hidden="true">
                                        <svg
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.7"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        >
                                            <rect
                                                x="3.5"
                                                y="7"
                                                width="17"
                                                height="13.5"
                                                rx="2"
                                            />
                                            <path d="M8.5 7V4.5h7V7"/>
                                            <path d="M3.5 12h17"/>
                                            <path d="M10 12v2h4v-2"/>
                                        </svg>
                                    </div>

                                    <div class="application-details">

                                        <div class="company-name">
                                            <?= e($company) ?>
                                        </div>

                                        <h3 class="opportunity-title">

                                            <?php if ($hasOpportunity): ?>

                                                <a href="opportunity-detail.php?id=<?= $opportunityId ?>">
                                                    <?= e($title) ?>
                                                </a>

                                            <?php else: ?>

                                                Opportunity no longer available

                                            <?php endif; ?>

                                        </h3>

                                        <div class="application-badges">

                                            <?php if ($type !== ''): ?>
                                                <span class="badge <?= e($typeClass) ?>">
                                                    <?= e(ucwords($type)) ?>
                                                </span>
                                            <?php endif; ?>

                                            <span class="badge <?= e($statusClass) ?>">
                                                <span aria-hidden="true">
                                                    <?php
                                                    if ($statusClass === 'status-accepted') {
                                                        echo '✓';
                                                    } elseif ($statusClass === 'status-rejected') {
                                                        echo '×';
                                                    } elseif ($statusClass === 'status-pending') {
                                                        echo '◷';
                                                    } elseif ($statusClass === 'status-shortlisted') {
                                                        echo '★';
                                                    } elseif ($statusClass === 'status-review') {
                                                        echo '↻';
                                                    } elseif ($statusClass === 'status-withdrawn') {
                                                        echo '−';
                                                    }
                                                    ?>
                                                </span>

                                                <?= e($status) ?>
                                            </span>

                                        </div>

                                        <div class="application-meta">

                                            <!-- Submission date -->
                                            <div class="meta-item">

                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    aria-hidden="true"
                                                >
                                                    <rect
                                                        x="3.5"
                                                        y="5"
                                                        width="17"
                                                        height="15.5"
                                                        rx="2"
                                                    />
                                                    <path d="M7.5 3v4"/>
                                                    <path d="M16.5 3v4"/>
                                                    <path d="M3.5 9.5h17"/>
                                                </svg>

                                                <span>
                                                    Applied on:
                                                    <strong>
                                                        <?= e(history_format_date(
                                                            $application['SubmissionDate'] ?? null
                                                        )) ?>
                                                    </strong>
                                                </span>

                                            </div>

                                            <!-- Location -->
                                            <div class="meta-item">

                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.7"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    aria-hidden="true"
                                                >
                                                    <path d="M20 10.2c0 5.1-8 11-8 11s-8-5.9-8-11a8 8 0 1 1 16 0Z"/>
                                                    <circle cx="12" cy="10" r="2.5"/>
                                                </svg>

                                                <span>
                                                    <?= e(
                                                        $location !== ''
                                                            ? $location
                                                            : 'Location not specified'
                                                    ) ?>
                                                </span>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <!-- Application actions -->
                                <div class="application-actions">

                                    <?php if ($hasOpportunity): ?>

                                        <a
                                            class="dashboard-link"
                                            href="opportunity-detail.php?id=<?= $opportunityId ?>"
                                        >
                                            View Details
                                            <span aria-hidden="true">→</span>
                                        </a>

                                    <?php else: ?>

                                        <span class="unavailable-label">
                                            Details unavailable
                                        </span>

                                    <?php endif; ?>

                                    <?php if ($isPending): ?>

                                        <form
                                            class="withdraw-form"
                                            method="POST"
                                            action="application-history.php"
                                            onsubmit="return confirm('Are you sure you want to withdraw this application?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="withdraw_application"
                                            >

                                            <input
                                                type="hidden"
                                                name="opportunity_id"
                                                value="<?= $opportunityId ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="withdraw-button"
                                            >
                                                Withdraw
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</div>

<?php user_footer(); ?>
