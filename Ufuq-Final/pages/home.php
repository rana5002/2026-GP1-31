<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/recommendations.php';

/* ---------- Authentication ---------- */
$userId = require_user();

/* ---------- Load current user ---------- */
$stmt = $pdo->prepare("
    SELECT u.*, m.Email
    FROM `user` u
    JOIN member m ON m.MemberID = u.UserID
    WHERE u.UserID = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    http_response_code(404);
    exit('User account not found.');
}

/* ---------- Helper functions ---------- */
if (!function_exists('dashboard_date')) {
    function dashboard_date($date): string
    {
        if (!$date) {
            return 'Not specified';
        }

        try {
            return (new DateTime($date))->format('d M Y');
        } catch (Throwable $e) {
            return 'Not specified';
        }
    }
}

if (!function_exists('dashboard_company_name')) {
    function dashboard_company_name(array $opportunity): string
    {
        $name = trim((string)($opportunity['OpportunityProvider'] ?? ''));

        return $name !== '' ? $name : 'External Company';
    }
}

/* ---------- Handle favorite toggle ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'toggle_favorite') {

    csrf_check();

    $opportunityId = filter_input(
        INPUT_POST,
        'opportunity_id',
        FILTER_VALIDATE_INT
    );

    if (!$opportunityId || $opportunityId < 1) {
        header('Location: home.php?msg=invalid');
        exit;
    }

    // Only allow favoriting an existing, open opportunity.
    $check = $pdo->prepare("
        SELECT OpportunityID
        FROM opportunity
        WHERE OpportunityID = ?
        AND Status IN ('Open', 'Upcoming')
        LIMIT 1
    ");
    $check->execute([$opportunityId]);

    if (!$check->fetchColumn()) {
        header('Location: home.php?msg=unavailable');
        exit;
    }

    $check = $pdo->prepare("
        SELECT 1
        FROM favorite
        WHERE UserID = ? AND OpportunityID = ?
        LIMIT 1
    ");
    $check->execute([$userId, $opportunityId]);

    if ($check->fetchColumn()) {
        $favoriteResult = 'favorite_removed';
        $delete = $pdo->prepare("
            DELETE FROM favorite
            WHERE UserID = ? AND OpportunityID = ?
        ");
        $delete->execute([$userId, $opportunityId]);
    } else {
        $favoriteResult = 'favorite_added';
        $insert = $pdo->prepare("
            INSERT INTO favorite (UserID, OpportunityID)
            VALUES (?, ?)
        ");
        $insert->execute([$userId, $opportunityId]);
    }

    recommender_reload();

    // Redirect to a safe local page to prevent duplicate POST submissions.
    $returnTo = $_POST['return_to'] ?? 'home.php';

    $allowedReturns = ['home.php', 'browse.php'];

    if (!in_array($returnTo, $allowedReturns, true)) {
        $returnTo = 'home.php';
    }

    header('Location: ' . $returnTo . '?msg=' . $favoriteResult);
    exit;
}

/* ---------- User display name ---------- */
$firstName = trim((string)($me['FirstName'] ?? ''));
$lastName  = trim((string)($me['LastName'] ?? ''));
$userName  = trim($firstName . ' ' . $lastName);

if ($userName === '') {
    $userName = 'User';
}

/* ---------- Profile completion ---------- */
$profileFields = [
    !empty($me['Phone']),
    !empty($me['EducationalStatus']),
    !empty($me['ProfilePicture'])
];

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM userstudy
    WHERE UserID = ?
");
$stmt->execute([$userId]);
$hasStudy = (int)$stmt->fetchColumn() > 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM userexperience
    WHERE UserID = ?
");
$stmt->execute([$userId]);
$hasExperience = (int)$stmt->fetchColumn() > 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM userqualification
    WHERE UserID = ?
");
$stmt->execute([$userId]);
$hasQualifications = (int)$stmt->fetchColumn() > 0;

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM userskill
    WHERE UserID = ?
");
$stmt->execute([$userId]);
$hasSkills = (int)$stmt->fetchColumn() > 0;

$profileFields[] = $hasStudy;
$profileFields[] = $hasExperience;
$profileFields[] = $hasQualifications;
$profileFields[] = $hasSkills;

$completedFields = count(array_filter($profileFields));
$profileCompletion = (int)round(
    ($completedFields / count($profileFields)) * 100
);

/* ---------- Notifications ---------- */
$stmt = $pdo->prepare("
    SELECT n.NotificationID, n.Message, n.CreatedAt, un.IsRead
    FROM usernotification un
    JOIN notification n
        ON n.NotificationID = un.NotificationID
    WHERE un.UserID = ?
    ORDER BY n.CreatedAt DESC, n.NotificationID DESC
    LIMIT 3
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Favorite opportunity IDs ---------- */
$stmt = $pdo->prepare("
    SELECT OpportunityID
    FROM favorite
    WHERE UserID = ?
");
$stmt->execute([$userId]);

$favoriteIds = array_map(
    'intval',
    $stmt->fetchAll(PDO::FETCH_COLUMN)
);

/* ---------- Upcoming deadlines ---------- */
/*
 * Show upcoming deadlines for favorited opportunities
 * that are open, have a future deadline, and have not
 * already been applied for by this user.
 */
$stmt = $pdo->prepare("
    SELECT
        o.OpportunityID,
        o.Title,
        o.ApplicationDeadLine,
        o.ApplicationOpenDate,
        o.OpportunityProvider,
        o.Location,
        o.Status
    FROM favorite f
    JOIN opportunity o
        ON o.OpportunityID = f.OpportunityID
    WHERE f.UserID = ?
      AND o.Status = 'Open'
      AND o.ApplicationDeadLine IS NOT NULL
      AND o.ApplicationDeadLine >= CURDATE()
      AND NOT EXISTS (
          SELECT 1
          FROM application a
          WHERE a.UserID = f.UserID
            AND a.OpportunityID = o.OpportunityID
      )
    ORDER BY o.ApplicationDeadLine ASC
    LIMIT 4
");
$stmt->execute([$userId]);
$deadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Opportunity preview ---------- */
$stmt = $pdo->prepare("
    SELECT o.*, cmp.Logo AS CompanyLogo, cmp.CompanyName AS PostingCompanyName
    FROM opportunity o
    LEFT JOIN company cmp ON cmp.CompanyID = o.CreatedByCompanyID
    WHERE o.Status = 'Open'
      AND (
          o.ApplicationDeadLine IS NULL
          OR o.ApplicationDeadLine >= CURDATE()
      )
    ORDER BY o.OpportunityID DESC
    LIMIT 3
");
$stmt->execute();
$opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Page header ---------- */

/* ---------- Recommended opportunities (top 5 from the recommender) ---------- */
/* Status shown on the card, same rule as browse.php: Open, Upcoming or Closed. */
if (!function_exists('home_state')) {
    function home_state(array $o): string
    {
        $today    = date('Y-m-d');
        $status   = trim((string)($o['Status'] ?? ''));
        $deadline = (string)($o['ApplicationDeadLine'] ?? '');
        $openDate = (string)($o['ApplicationOpenDate'] ?? '');

        if (strcasecmp($status, 'Closed') === 0 || ($deadline !== '' && $deadline < $today)) {
            return 'Closed';
        }
        if ($openDate !== '' && $openDate > $today) {
            return 'Upcoming';
        }
        return 'Open';
    }
}

/* Duration in days, same text as browse.php. */
if (!function_exists('home_duration')) {
    function home_duration($start, $end): string
    {
        if (empty($start) || empty($end)) {
            return 'Not specified';
        }
        try {
            $days = (int)(new DateTime($start))->diff(new DateTime($end))->format('%r%a');
            return $days >= 0 ? $days . ($days === 1 ? ' day' : ' days') : 'Not specified';
        } catch (Throwable $e) {
            return 'Not specified';
        }
    }
}

$recoItems = [];
try {
    $recoRows = get_recommended_opportunities($pdo, (int)$userId, 5);
    $recoIds  = array_values(array_filter(array_map(
        static fn($r) => (int)($r['OpportunityID'] ?? 0),
        $recoRows
    )));

    if ($recoIds) {
        // Full rows + company logo, then put them back in the recommender's order.
        $marks = implode(',', array_fill(0, count($recoIds), '?'));
        $stmt = $pdo->prepare("
            SELECT o.*, cmp.Logo AS CompanyLogo, cmp.CompanyName AS PostingCompanyName
            FROM opportunity o
            LEFT JOIN company cmp ON cmp.CompanyID = o.CreatedByCompanyID
            WHERE o.OpportunityID IN ($marks)
        ");
        $stmt->execute($recoIds);

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['OpportunityID']] = $row;
        }
        foreach ($recoIds as $rid) {
            if (isset($byId[$rid])) {
                $recoItems[] = $byId[$rid];
            }
        }
    }
} catch (Throwable $e) {
    error_log('Home recommendations failed: ' . $e->getMessage());
    $recoItems = [];
}

user_header($me, 'Home', 'user-home');

?>

<style>
/* ===== Ufuq User Dashboard ===== */

.user-dashboard {
    --user-purple: #39265f;
    --user-purple-dark: #281a46;
    --user-purple-mid: #49316f;
    --user-lavender: #f0ebf8;
    --user-gold: #e2a94b;
    --user-bg: #faf6ef;
    --user-text: #29243a;
    --user-muted: #777184;
    --user-border: #e9e3ed;

    color: var(--user-text);
    padding-bottom: 48px;
    background: var(--user-bg);
    min-height: 70vh;
}

.user-dashboard *,
.user-dashboard *::before,
.user-dashboard *::after {
    box-sizing: border-box;
}

.user-dashboard a {
    text-underline-offset: 3px;
}

.user-dashboard .dashboard-hero {
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

.user-dashboard .dashboard-hero::before,
.user-dashboard .dashboard-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-dashboard .dashboard-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.user-dashboard .dashboard-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.user-dashboard .dashboard-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-dashboard .welcome-label {
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

.user-dashboard .dashboard-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-dashboard .dashboard-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.user-dashboard .hero-icon {
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
    animation: userFloat 4s ease-in-out infinite;
}

.user-dashboard .dashboard-content {
    animation: userFadeUp .5s ease both;
}

.user-dashboard .dashboard-section {
    margin-bottom: 24px;
}

.user-dashboard .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 16px;
    color: var(--user-text);
    font-size: 1.18rem;
    font-weight: 750;
}

.user-dashboard .section-heading::before {
    content: "";
    display: block;
    width: 5px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--user-gold);
}

.user-dashboard .section-description {
    margin: -7px 0 16px;
    color: var(--user-muted);
    font-size: .9rem;
    line-height: 1.7;
}

.user-dashboard .dashboard-card {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--user-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition:
        transform .22s ease,
        box-shadow .22s ease,
        border-color .22s ease;
}

.user-dashboard .dashboard-card:hover {
    border-color: #d5c8e4;
    box-shadow: 0 10px 26px rgba(40,26,70,.075);
}

.user-dashboard .stat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.user-dashboard .stat-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 155px;
    color: var(--user-text);
    text-decoration: none;
}

.user-dashboard .stat-card:hover {
    transform: translateY(-3px);
}

.user-dashboard .stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 17px;
}

.user-dashboard .stat-title {
    font-size: .93rem;
    font-weight: 700;
}

.user-dashboard .stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 43px;
    height: 43px;
    flex-shrink: 0;
    border-radius: 13px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.user-dashboard .stat-number {
    display: block;
    margin-bottom: 5px;
    color: var(--user-purple);
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.3;
}

.user-dashboard .stat-number.coming-soon-text {
    /* h3 so it uses the same heading font as the other "Coming soon" titles */
    margin: 0 0 5px;
    font-size: 1.35rem;
    font-weight: 750;
    line-height: 1.5;
}

/* Explore Opportunities sits directly on the page background (no shaded block) */
.user-dashboard #opportunities {
    background: transparent;
    box-shadow: none;
    border: 0;
}

.user-dashboard .stat-note {
    color: var(--user-muted);
    font-size: .83rem;
    line-height: 1.6;
}

.user-dashboard .progress-track {
    width: 100%;
    height: 7px;
    margin: 11px 0 8px;
    overflow: hidden;
    border-radius: 20px;
    background: #eee8f3;
}

.user-dashboard .progress-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(
        90deg,
        var(--user-purple),
        #8066a5
    );
    transition: width .4s ease;
}

.user-dashboard .dashboard-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--user-purple);
    font-size: .86rem;
    font-weight: 700;
    text-decoration: none;
}

.user-dashboard .dashboard-link:hover {
    color: var(--user-purple-dark);
    text-decoration: underline;
}

.user-dashboard .card-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.user-dashboard .card-heading-row h2,
.user-dashboard .card-heading-row h3 {
    margin: 0;
    color: var(--user-text);
    font-size: 1.12rem;
    font-weight: 750;
}

.user-dashboard .ai-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    padding: 5px 10px;
    border: 1px solid #e4d9f1;
    border-radius: 9px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: .78rem;
    font-weight: 800;
}

.user-dashboard .recommendation-card {
    margin-bottom: 24px;
    border-top: 3px solid var(--user-gold);
}

.user-dashboard .recommendation-note {
    margin: 0 0 16px;
    color: var(--user-muted);
    font-size: .89rem;
    line-height: 1.7;
}

.user-dashboard .recommendation-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.user-dashboard .opportunity-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.user-dashboard .opportunity-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 17px;
    border: 1px solid #ece7f2;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.04);
    transition:
        transform .22s ease,
        box-shadow .22s ease,
        border-color .22s ease;
    animation: userFadeUp .4s ease both;
}

.user-dashboard .opportunity-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.09);
}

.user-dashboard .opportunity-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 16px;
}

.user-dashboard .company-info {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.user-dashboard .company-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    border-radius: 14px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: 1.15rem;
    font-weight: 800;
}

.user-dashboard .company-details {
    min-width: 0;
}

.user-dashboard .opportunity-title {
    margin: 0 0 4px;
    color: var(--user-text);
    font-size: .98rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-dashboard .company-name {
    color: var(--user-muted);
    font-size: .81rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.user-dashboard .favorite-form {
    flex-shrink: 0;
    margin: 0;
}

.user-dashboard .favorite-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid #e9e3ed;
    border-radius: 11px;
    background: #fff;
    color: #777184;
    cursor: pointer;
    transition:
        background .18s ease,
        border-color .18s ease,
        transform .18s ease;
}

.user-dashboard .favorite-button:hover {
    transform: translateY(-1px);
    border-color: #d6c5df;
    background: #f7f2fa;
}

.user-dashboard .favorite-button.is-favorite {
    border-color: #f1d4d8;
    background: #fff1f2;
    color: #bd334d;
}

.user-dashboard .opportunity-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 15px;
    color: var(--user-muted);
    font-size: .82rem;
    line-height: 1.6;
}

.user-dashboard .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 30px;
    font-size: .76rem;
    font-weight: 750;
    white-space: nowrap;
}

.user-dashboard .badge-course {
    background: #e8f5ec;
    color: #24653c;
}

.user-dashboard .badge-program {
    background: #eee8f8;
    color: var(--user-purple);
}

.user-dashboard .match-label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 13px;
    color: #426c50;
    font-size: .79rem;
    font-weight: 700;
}

.user-dashboard .opportunity-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid #f0edf4;
}

/* Upcoming deadlines: one light box per opportunity (same as the company profile list) */
.user-dashboard .deadline-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.user-dashboard .deadline-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid #eee9f5;
    border-radius: 12px;
    background: #faf9fd;
}

.user-dashboard .deadline-title {
    display: block;
    margin: 0;
    color: var(--user-text);
    font-size: .95rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
    text-decoration: none;
}

.user-dashboard .deadline-title:hover {
    color: var(--user-purple);
    text-decoration: underline;
}

.user-dashboard .deadline-company {
    margin-top: 3px;
    color: #5e596c;
    font-size: .87rem;
    overflow-wrap: anywhere;
}

.user-dashboard .deadline-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    flex-shrink: 0;
    min-width: 96px;
    padding: 5px 12px;
    border: 1px solid transparent;
    border-radius: 30px;
    font-size: .78rem;
    font-weight: 750;
    white-space: nowrap;
}

.user-dashboard .deadline-status::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}

.user-dashboard .deadline-status.badge-open { background: #e8f5ec; color: #24653c; border-color: #cfe8d9; }
.user-dashboard .deadline-status.badge-upcoming { background: #f3f2f5; color: #5f5a6b; border-color: #e3e0e8; }
.user-dashboard .deadline-status.badge-closed { background: #fff1f2; color: #9c263d; border-color: #f1d4d8; }

/* Deadline badge: gold, same size and shape as the status badges */
.user-dashboard .deadline-status.deadline-badge {
    background: #fbf6eb;
    color: #76531d;
    border-color: #eee4d1;
}

.user-dashboard .notification-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.user-dashboard .notification-item {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 12px 0;
    border-bottom: 1px solid #f0edf4;
}

.user-dashboard .notification-item:last-child {
    border-bottom: none;
}

.user-dashboard .notification-dot {
    width: 9px;
    height: 9px;
    flex-shrink: 0;
    margin-top: 6px;
    border-radius: 50%;
    background: #d5cde0;
}

.user-dashboard .notification-dot.unread {
    background: var(--user-gold);
    box-shadow: 0 0 0 4px #fbf4e7;
}

.user-dashboard .notification-message {
    margin: 0;
    color: var(--user-text);
    font-size: .87rem;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.user-dashboard .notification-message.unread {
    font-weight: 700;
}

.user-dashboard .notification-date {
    display: block;
    margin-top: 3px;
    color: var(--user-muted);
    font-size: .76rem;
}

.user-dashboard .empty-state {
    padding: 30px 18px;
    border: 1px dashed #d8cde7;
    border-radius: 14px;
    background: #fbf9fd;
    text-align: center;
}

.user-dashboard .empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    margin-bottom: 11px;
    border-radius: 19px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: 1.45rem;
}

.user-dashboard .empty-state h3 {
    margin: 0;
    color: var(--user-text);
    font-size: 1rem;
    font-weight: 750;
}

.user-dashboard .empty-state p {
    margin: 7px 0 0;
    color: var(--user-muted);
    font-size: .87rem;
    line-height: 1.7;
}

.user-dashboard .dashboard-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 16px;
    margin-bottom: 22px;
    border: 1px solid #e4dced;
    border-radius: 13px;
    background: #f7f4fb;
    color: var(--user-purple);
    font-size: .9rem;
    line-height: 1.6;
}

.user-dashboard .dashboard-notice.notice-warning {
    border-color: #f0d6b0;
    background: #fff8ed;
    color: #80521b;
}

.user-dashboard .dashboard-divider {
    height: 1px;
    margin: 28px 0;
    border: 0;
    background: linear-gradient(
        90deg,
        transparent,
        #dcd2e9,
        transparent
    );
}

.user-dashboard .btn {
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.user-dashboard .btn:hover {
    transform: translateY(-1px);
}

.user-dashboard .btn-primary {
    background: var(--user-purple);
    border-color: var(--user-purple);
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
}

.user-dashboard .btn-primary:hover {
    background: var(--user-purple-dark);
    border-color: var(--user-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.20);
}

.user-dashboard .btn-outline {
    border-radius: 9px;
}

.user-dashboard .text-muted {
    color: var(--user-muted);
}

@keyframes userFadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes userFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@media (max-width: 900px) {
    .user-dashboard .recommendation-list,
    .user-dashboard .opportunity-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .user-dashboard .dashboard-hero {
        padding: 30px 0;
    }

    .user-dashboard .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .user-dashboard .stat-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .user-dashboard .stat-card {
        min-height: 135px;
    }

    .user-dashboard .recommendation-list,
    .user-dashboard .opportunity-grid {
        grid-template-columns: 1fr;
    }

    .user-dashboard .dashboard-card {
        padding: 16px;
    }

    .user-dashboard .card-heading-row {
        align-items: flex-start;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-dashboard *,
    .user-dashboard *::before,
    .user-dashboard *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}

/* Toast: success message that slides down from the top, then disappears (same as Browse) */
.browse-toast {
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

.browse-toast.is-shown {
    transform: translate(-50%, 0);
    opacity: 1;
}

.browse-toast-icon {
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

.browse-toast-close {
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

.browse-toast-close:hover {
    background: rgba(31,107,71,.1);
}

@media (prefers-reduced-motion: reduce) {
    .browse-toast { transition: none; }
}

/* Explore Opportunities: same card as Browse, 3 per row */
.user-dashboard #opportunities .opportunity-grid {
    gap: 18px;
}

/* ===== Recommended cards (same look as the Browse cards) ===== */
.user-dashboard .reco-grid {
    /* One row: each card is as wide as 1/3 of the box; the rest scroll sideways. */
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: calc((100% - 2 * 18px) / 3);
    gap: 18px;
    padding: 4px 4px 14px;
    margin: 0 -4px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    overscroll-behavior-x: contain;
}

.user-dashboard .reco-card {
    scroll-snap-align: start;
}

.user-dashboard .reco-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 250px;
    padding: 19px;
    border: 1px solid #ece7f2;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.user-dashboard .reco-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.1);
}

.user-dashboard .reco-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.user-dashboard .reco-info {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    min-width: 0;
}

.user-dashboard .reco-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 14px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: 1.15rem;
    font-weight: 800;
}

.user-dashboard .reco-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #fff;
}

.user-dashboard .reco-title {
    margin: 0;
    min-height: calc(2 * 1.55em);
    color: var(--user-text);
    font-size: 1rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-dashboard .reco-title a {
    color: inherit;
    text-decoration: none;
}

.user-dashboard .reco-title a:hover {
    color: var(--user-purple);
    text-decoration: underline;
}

.user-dashboard .reco-company {
    margin: auto 0 8px;
    padding-left: 11px;
    color: var(--user-muted);
    font-size: .86rem;
    font-weight: 400;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-dashboard .reco-fav-form {
    flex-shrink: 0;
    margin: 0;
}

.user-dashboard .reco-fav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 37px;
    height: 37px;
    padding: 0;
    border: 1px solid #e9e3ed;
    border-radius: 11px;
    background: #fff;
    color: #777184;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    transition: transform .18s ease, background .18s ease, border-color .18s ease;
}

.user-dashboard .reco-fav:hover {
    transform: translateY(-1px);
    border-color: #e5b9c1;
    background: #fff6f7;
}

.user-dashboard .reco-fav.is-favorite {
    border-color: #f1d4d8;
    background: #fff1f2;
    color: #bd334d;
}

.user-dashboard .reco-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 17px;
    color: var(--user-muted);
    font-size: .81rem;
    line-height: 1.6;
}

.user-dashboard .reco-type,
.user-dashboard .reco-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    box-sizing: border-box;
    min-width: 96px;
    height: 30px;
    padding: 0 12px;
    border: 1px solid #e7e0ef;
    border-radius: 30px;
    font-size: .78rem;
    font-weight: 650;
    line-height: 1;
    white-space: nowrap;
}

.user-dashboard .reco-type {
    background: transparent;
    color: #5b4a7a;
}

.user-dashboard .reco-status {
    font-weight: 750;
}

.user-dashboard .reco-status::before {
    content: "";
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    border-radius: 50%;
    background: currentColor;
}

.user-dashboard .reco-status.badge-open {
    border-color: #cfe8d9;
    background: #e8f5ec;
    color: #24653c;
}

.user-dashboard .reco-status.badge-upcoming {
    border-color: #e3e0e8;
    background: #f3f2f5;
    color: #5f5a6b;
}

.user-dashboard .reco-status.badge-closed {
    border-color: #f1d4d8;
    background: #fff1f2;
    color: #9c263d;
}

.user-dashboard .reco-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #f0edf4;
}

@media (max-width: 950px) {
    .user-dashboard .reco-grid { grid-auto-columns: calc((100% - 18px) / 2); }
}

@media (max-width: 620px) {
    .user-dashboard .reco-grid { grid-auto-columns: 85%; }
    .user-dashboard .reco-card { padding: 16px; }
}



</style>

<div class="user-dashboard">

    <!-- Welcome Banner -->
    <section class="dashboard-hero">
        <div class="container dashboard-hero-inner">

            <div>
                <div class="welcome-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>
                    Welcome back, <?= e($firstName !== '' ? $firstName : 'User') ?>!
                </h1>

                <p>
                    Discover training opportunities, track your deadlines,
                    and keep developing your skills.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">✦</div>

        </div>
    </section>

    <div class="container dashboard-content">

        <!-- Feedback Messages -->
        <?php
        $message = $_GET['msg'] ?? '';
        ?>

        <?php if (in_array($message, ['favorite_added', 'favorite_removed', 'favorite_updated'], true)): ?>
            <!-- Toast: slides down from the top, then disappears (same as Browse) -->
            <div class="browse-toast" id="browseToast" role="status" aria-live="polite">
                <span class="browse-toast-icon" aria-hidden="true">✓</span>
                <span><?= $message === 'favorite_added' ? 'Added to your favorites.' : 'Removed from your favorites.' ?></span>
                <button type="button" class="browse-toast-close" aria-label="Close">&times;</button>
            </div>
        <?php elseif ($message === 'unavailable'): ?>
            <div class="dashboard-notice notice-warning" role="status">
                <span aria-hidden="true">!</span>
                <span>This opportunity is no longer available.</span>
            </div>
        <?php elseif ($message === 'invalid'): ?>
            <div class="dashboard-notice notice-warning" role="status">
                <span aria-hidden="true">!</span>
                <span>Invalid opportunity. Please try again.</span>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <section class="stat-grid" aria-label="Dashboard overview">

            <a href="skill-gap.php" class="dashboard-card stat-card">
                <div class="stat-top">
                    <span class="stat-title">Skill Gap</span>

                    <span class="stat-icon" aria-hidden="true">◈</span>
                </div>

                <div>
                    <h3 class="stat-number coming-soon-text">
                        Coming Soon
                    </h3>

                    <span class="stat-note">
                        Skill gap analysis will be activated soon.
                    </span>
                </div>

                <div style="margin-top:14px;">
                    <span class="dashboard-link">
                        View skill gap &rarr;
                    </span>
                </div>
            </a>

            <a href="dashboard-user.php"
               class="dashboard-card stat-card">

                <div class="stat-top">
                    <span class="stat-title">Profile Completion</span>

                    <span class="stat-icon" aria-hidden="true">◎</span>
                </div>

                <div>
                    <span class="stat-number">
                        <?= $profileCompletion ?>%
                    </span>

                    <div class="progress-track"
                         role="progressbar"
                         aria-label="Profile completion"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="<?= $profileCompletion ?>">

                        <div class="progress-fill"
                             style="width:<?= $profileCompletion ?>%;">
                        </div>
                    </div>

                    <span class="stat-note">
                        <?= $profileCompletion < 100
                            ? 'Complete your profile to improve your experience.'
                            : 'Your profile is complete.' ?>
                    </span>
                </div>

                <div style="margin-top:14px;">
                    <span class="dashboard-link">
                        View profile &rarr;
                    </span>
                </div>

            </a>

        </section>

        <!-- Recommended Opportunities -->
               
        <section class="dashboard-section">
            <div class="dashboard-card recommendation-card">

                <div class="card-heading-row">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="ai-badge">AI</span>
                        <h2>Recommended Opportunities</h2>
                    </div>
                    <a href="browse.php" class="dashboard-link">Browse all &rarr;</a>
                </div>

                <?php if ($recoItems): ?>

                    <div class="reco-grid">
                        <?php foreach ($recoItems as $o): ?>
                            <?php
                            $oppId      = (int)$o['OpportunityID'];
                            $isFavorite = in_array($oppId, $favoriteIds, true);
                            $provider   = dashboard_company_name($o);

                            // Avatar: posting company's logo, otherwise the first letter of the name.
                            $avatarName = $provider;
                            $avatarLogo = '';
                            if (!empty($o['CreatedByCompanyID'])) {
                                $logo = trim((string)($o['CompanyLogo'] ?? ''));
                                if ($logo !== '') {
                                    $avatarLogo = preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/');
                                }
                                $postingName = trim((string)($o['PostingCompanyName'] ?? ''));
                                if ($postingName !== '') {
                                    $avatarName = $postingName;
                                }
                            }

                            $opType     = trim((string)($o['Type'] ?? 'Opportunity'));
                            $opLocation = trim((string)($o['Location'] ?? ''));
                            if ($opLocation === '') {
                                $opLocation = 'Location not specified';
                            }
                            $opDuration = home_duration($o['StartDate'] ?? null, $o['EndDate'] ?? null);

                            $state      = home_state($o);
                            $stateClass = $state === 'Open'
                                ? 'badge-open'
                                : ($state === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
                            ?>

                            <article class="reco-card">

                                <div class="reco-top">
                                    <div class="reco-info">
                                        <div class="reco-logo" aria-hidden="true">
                                            <?php if ($avatarLogo !== ''): ?>
                                                <img src="<?= e($avatarLogo) ?>" alt="">
                                            <?php else: ?>
                                                <?= e(mb_strtoupper(mb_substr($avatarName, 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                            <?php endif; ?>
                                        </div>

                                        <h3 class="reco-title">
                                            <a href="opportunity-detail.php?id=<?= $oppId ?>"><?= e($o['Title']) ?></a>
                                        </h3>
                                    </div>

                                    <form method="post" class="reco-fav-form">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="toggle_favorite">
                                        <input type="hidden" name="opportunity_id" value="<?= $oppId ?>">
                                        <input type="hidden" name="return_to" value="home.php">

                                        <button type="submit"
                                                class="reco-fav <?= $isFavorite ? 'is-favorite' : '' ?>"
                                                aria-label="<?= $isFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
                                                title="<?= $isFavorite ? 'Remove from favorites' : 'Save to favorites' ?>">
                                            <span aria-hidden="true"><?= $isFavorite ? '♥' : '♡' ?></span>
                                        </button>
                                    </form>
                                </div>

                                <div class="reco-company"><?= e($provider) ?></div>

                                <div class="reco-meta">
                                    <span class="reco-type"><?= e($opType) ?></span>
                                    <span><span aria-hidden="true">⌖</span> <?= e($opLocation) ?></span>
                                    <span><span aria-hidden="true">◷</span> <?= e($opDuration) ?></span>
                                </div>

                                <div class="reco-footer">
                                    <span class="reco-status <?= $stateClass ?>"><?= e($state) ?></span>

                                    <a class="btn btn-primary btn-sm"
                                       href="opportunity-detail.php?id=<?= $oppId ?>">
                                        View Details <span aria-hidden="true">&rarr;</span>
                                    </a>
                                </div>

                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>

                    <div class="empty-state">
                        <div class="empty-icon" aria-hidden="true">✦</div>
                        <h3>No recommendations yet</h3>
                        <p>
                            Complete your profile and save a few opportunities,
                            and we will suggest ones that fit you.
                        </p>
                    </div>

                <?php endif; ?>

            </div>
        </section>

        <!-- Upcoming Deadlines -->
        <section class="dashboard-section">

            <div class="dashboard-card">

                <div class="card-heading-row">
                    <h2>Upcoming Deadlines</h2>

                    <a href="favorites.php" class="dashboard-link">
                        My favorites &rarr;
                    </a>
                </div>

                <p class="section-description">
                    Application deadlines for your saved opportunities.
                </p>

                <?php if ($deadlines): ?>

                    <ul class="deadline-list">

                        <?php foreach ($deadlines as $d):
                            $dState = home_state($d);
                            $dClass = $dState === 'Open'
                                ? 'badge-open'
                                : ($dState === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
                            $dLocation = trim((string)($d['Location'] ?? ''));
                        ?>

                            <li class="deadline-item">

                                <div style="min-width:0;">
                                    <a class="deadline-title"
                                       href="opportunity-detail.php?id=<?= (int)$d['OpportunityID'] ?>">
                                        <?= e($d['Title']) ?>
                                    </a>

                                    <div class="deadline-company">
                                        <?= e(dashboard_company_name($d)) ?>
                                        &middot;
                                        <?= e($dLocation !== '' ? $dLocation : 'Location not specified') ?>
                                    </div>
                                </div>

                                <!-- Deadline inside the badge (same shape as the status badge) -->
                                <span class="deadline-status deadline-badge">
                                    Deadline: <?= e(dashboard_date($d['ApplicationDeadLine'])) ?>
                                </span>

                            </li>

                        <?php endforeach; ?>

                    </ul>

                <?php else: ?>

                    <div class="empty-state">
                        <div class="empty-icon" aria-hidden="true">◷</div>

                        <h3>No upcoming deadlines</h3>

                        <p>
                            Save opportunities to your favorites to keep track
                            of their upcoming application deadlines.
                        </p>

                        <div style="margin-top:15px;">
                            <a href="browse.php" class="btn btn-primary btn-sm">
                                Browse Opportunities
                            </a>
                        </div>
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- Recent Notifications -->
        <section class="dashboard-section">

            <div class="dashboard-card">

                <div class="card-heading-row">
                    <h2>Recent Notifications</h2>

                    <a href="notifications.php" class="dashboard-link">
                        View all &rarr;
                    </a>
                </div>

                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">✦</div>

                    <h3>Coming soon</h3>

                    <p>
                        Notifications will be activated soon.
                    </p>
                </div>

            </div>

        </section>

        <hr class="dashboard-divider">

        <!-- Opportunities Preview -->
        <section class="dashboard-section" id="opportunities">

            <div class="card-heading-row">
                <div>
                    <h2 class="section-heading" style="margin-bottom:7px;">
                        Explore Opportunities
                    </h2>

                    <p class="section-description" style="margin:0;">
                        Discover the latest training programs and courses.
                    </p>
                </div>

                <a href="browse.php" class="dashboard-link">
                    View all &rarr;
                </a>
            </div>

            <?php if ($opportunities): ?>

                <div class="opportunity-grid">

                    <?php foreach ($opportunities as $o): ?>
                        <?php
                        $oppId      = (int)$o['OpportunityID'];
                        $isFavorite = in_array($oppId, $favoriteIds, true);
                        $provider   = dashboard_company_name($o);

                        // Avatar: posting company's logo, otherwise the first letter of the name (same as Browse).
                        $avatarName = $provider;
                        $avatarLogo = '';
                        if (!empty($o['CreatedByCompanyID'])) {
                            $logo = trim((string)($o['CompanyLogo'] ?? ''));
                            if ($logo !== '') {
                                $avatarLogo = preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/');
                            }
                            $postingName = trim((string)($o['PostingCompanyName'] ?? ''));
                            if ($postingName !== '') {
                                $avatarName = $postingName;
                            }
                        }

                        $opType     = trim((string)($o['Type'] ?? 'Opportunity'));
                        $opLocation = trim((string)($o['Location'] ?? ''));
                        if ($opLocation === '') {
                            $opLocation = 'Location not specified';
                        }
                        $opDuration = home_duration($o['StartDate'] ?? null, $o['EndDate'] ?? null);

                        $state      = home_state($o);
                        $stateClass = $state === 'Open'
                            ? 'badge-open'
                            : ($state === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
                        ?>

                        <article class="reco-card">

                            <div class="reco-top">
                                <div class="reco-info">
                                    <div class="reco-logo" aria-hidden="true">
                                        <?php if ($avatarLogo !== ''): ?>
                                            <img src="<?= e($avatarLogo) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_strtoupper(mb_substr($avatarName, 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="reco-title">
                                        <a href="opportunity-detail.php?id=<?= $oppId ?>"><?= e($o['Title']) ?></a>
                                    </h3>
                                </div>

                                <form method="post" class="reco-fav-form">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle_favorite">
                                    <input type="hidden" name="opportunity_id" value="<?= $oppId ?>">
                                    <input type="hidden" name="return_to" value="home.php">

                                    <button type="submit"
                                            class="reco-fav <?= $isFavorite ? 'is-favorite' : '' ?>"
                                            aria-label="<?= $isFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
                                            title="<?= $isFavorite ? 'Remove from favorites' : 'Save to favorites' ?>">
                                        <span aria-hidden="true"><?= $isFavorite ? '♥' : '♡' ?></span>
                                    </button>
                                </form>
                            </div>

                            <div class="reco-company"><?= e($provider) ?></div>

                            <div class="reco-meta">
                                <span class="reco-type"><?= e($opType) ?></span>
                                <span><span aria-hidden="true">⌖</span> <?= e($opLocation) ?></span>
                                <span><span aria-hidden="true">◷</span> <?= e($opDuration) ?></span>
                            </div>

                            <div class="reco-footer">
                                <span class="reco-status <?= $stateClass ?>"><?= e($state) ?></span>

                                <a class="btn btn-primary btn-sm"
                                   href="opportunity-detail.php?id=<?= $oppId ?>">
                                    View Details <span aria-hidden="true">&rarr;</span>
                                </a>
                            </div>

                        </article>
                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">⌕</div>

                    <h3>No opportunities available yet</h3>

                    <p>
                        Check back soon. New training programs and courses
                        will appear here when companies publish them.
                    </p>
                </div>

            <?php endif; ?>

        </section>

    </div>
</div>
<script>
/* Toast: slides down, stays for a few seconds, then slides up and is removed (same as Browse). */
(function () {
    const toast = document.getElementById('browseToast');
    if (!toast) return;

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
    toast.querySelector('.browse-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>