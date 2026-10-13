
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';

/* ---------- Database connection ---------- */
if (!isset($pdo) && isset($db)) {
    $pdo = $db;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection not found. Please check bootstrap.php.');
}

/* ---------- Authentication ---------- */
require_login('User');

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid < 1) {
    header('Location: login.php');
    exit;
}

/* ---------- Current user ---------- */
$stmt = $pdo->prepare(
    'SELECT * FROM `user` WHERE UserID = ? LIMIT 1'
);
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: login.php');
    exit;
}

/* ---------- Helpers ---------- */
function browse_h($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function browse_duration($startDate, $endDate): string
{
    if (empty($startDate) || empty($endDate)) {
        return 'Not specified';
    }

    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        $days = (int)$start->diff($end)->format('%r%a');

        if ($days < 0) {
            return 'Not specified';
        }

        if ($days < 14) {
            return 'Under 2 weeks';
        }

        if ($days < 63) {
            return '2–9 weeks';
        }

        return 'Over 9 weeks';

    } catch (Throwable $e) {
        return 'Not specified';
    }
}

function browse_initial($name): string
{
    $name = trim((string)$name);

    if ($name === '') {
        return 'U';
    }

    if (function_exists('mb_substr')) {
        return mb_strtoupper(
            mb_substr($name, 0, 1, 'UTF-8'),
            'UTF-8'
        );
    }

    return strtoupper(substr($name, 0, 1));
}

/* ---------- Handle favorite button ---------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'toggle_favorite'
) {
    csrf_check();

    $opportunityId = filter_input(
        INPUT_POST,
        'opportunity_id',
        FILTER_VALIDATE_INT
    );

    if (!$opportunityId || $opportunityId < 1) {
        header('Location: browse.php?msg=invalid');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Verify that the opportunity exists.
        $check = $pdo->prepare(
            'SELECT OpportunityID
             FROM opportunity
             WHERE OpportunityID = ?
             LIMIT 1'
        );
        $check->execute([$opportunityId]);

        if (!$check->fetchColumn()) {
            $pdo->rollBack();
            header('Location: browse.php?msg=unavailable');
            exit;
        }

        // Check whether the opportunity is already a favorite.
        $favoriteCheck = $pdo->prepare(
            'SELECT 1
             FROM favourite
             WHERE UserID = ?
               AND OpportunityID = ?
             LIMIT 1'
        );
        $favoriteCheck->execute([$uid, $opportunityId]);

        if ($favoriteCheck->fetchColumn()) {
            $delete = $pdo->prepare(
                'DELETE FROM favourite
                 WHERE UserID = ?
                   AND OpportunityID = ?'
            );
            $delete->execute([$uid, $opportunityId]);

        } else {
            $insert = $pdo->prepare(
                'INSERT INTO favourite (UserID, OpportunityID)
                 VALUES (?, ?)'
            );
            $insert->execute([$uid, $opportunityId]);
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Favorite update failed: ' . $e->getMessage());

        header('Location: browse.php?msg=error');
        exit;
    }

    // Preserve only the recognized filters.
    $filterKeys = [
        'type',
        'location',
        'method',
        'duration',
        'company'
    ];

    $returnFilters = [];

    foreach ($filterKeys as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key])) {
            $returnFilters[$key] = trim($_GET[$key]);
        }
    }

    $returnFilters['msg'] = 'favorite_updated';

    header('Location: browse.php?' . http_build_query($returnFilters));
    exit;
}

/* ---------- Filters ---------- */
$type = isset($_GET['type']) && is_string($_GET['type'])
    ? trim($_GET['type']) : '';

$location = isset($_GET['location']) && is_string($_GET['location'])
    ? trim($_GET['location']) : '';

$method = isset($_GET['method']) && is_string($_GET['method'])
    ? trim($_GET['method']) : '';

$duration = isset($_GET['duration']) && is_string($_GET['duration'])
    ? trim($_GET['duration']) : '';

$company = isset($_GET['company']) && is_string($_GET['company'])
    ? trim($_GET['company']) : '';

$allowedDurations = [
    'Under 2 weeks',
    '2–9 weeks',
    'Over 9 weeks'
];

if (!in_array($duration, $allowedDurations, true)) {
    $duration = '';
}

/* ---------- Build opportunity query ---------- */
$where = [];
$params = [];

if ($type !== '') {
    $where[] = 'o.Type = ?';
    $params[] = $type;
}

if ($location !== '') {
    $where[] = 'o.Location = ?';
    $params[] = $location;
}

if ($method !== '') {
    $where[] = 'o.ApplicationMethod = ?';
    $params[] = $method;
}

if ($company !== '') {
    $where[] = 'o.OpportunityProvider LIKE ?';
    $params[] = '%' . $company . '%';
}

/*
 * Keep the original behavior: do not restrict results to Status = Open.
 * This lets the user see records with other status values as well.
 */
$sql = 'SELECT o.*
        FROM opportunity o';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY o.OpportunityID DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Filter by duration ---------- */
if ($duration !== '') {
    $opportunities = array_values(
        array_filter(
            $opportunities,
            static function ($o) use ($duration) {
                return browse_duration(
                    $o['StartDate'] ?? null,
                    $o['EndDate'] ?? null
                ) === $duration;
            }
        )
    );
}

/* ---------- Dropdown values ---------- */
$locations = $pdo->query(
    "SELECT DISTINCT Location
     FROM opportunity
     WHERE Location IS NOT NULL
       AND Location <> ''
     ORDER BY Location"
)->fetchAll(PDO::FETCH_COLUMN);

$types = $pdo->query(
    "SELECT DISTINCT Type
     FROM opportunity
     WHERE Type IS NOT NULL
       AND Type <> ''
     ORDER BY Type"
)->fetchAll(PDO::FETCH_COLUMN);

$methods = $pdo->query(
    "SELECT DISTINCT ApplicationMethod
     FROM opportunity
     WHERE ApplicationMethod IS NOT NULL
       AND ApplicationMethod <> ''
     ORDER BY ApplicationMethod"
)->fetchAll(PDO::FETCH_COLUMN);

/* ---------- Current user's favorites ---------- */
$favStmt = $pdo->prepare(
    'SELECT OpportunityID
     FROM favourite
     WHERE UserID = ?'
);
$favStmt->execute([$uid]);

$favorites = array_map(
    'intval',
    $favStmt->fetchAll(PDO::FETCH_COLUMN)
);

$hasFilters = (
    $type !== ''
    || $location !== ''
    || $method !== ''
    || $duration !== ''
    || $company !== ''
);

/* ---------- Safe filter query string ---------- */
$filterQuery = [];

foreach (['type', 'location', 'method', 'duration', 'company'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $filterQuery[$key] = trim($_GET[$key]);
    }
}

$filterAction = 'browse.php';

if ($filterQuery) {
    $filterAction .= '?' . http_build_query($filterQuery);
}

/* ---------- Header ---------- */
user_header($me, 'Browse', 'browse');
?>

<style>
/* ===== Ufuq Browse Opportunities ===== */

.user-browse {
    --browse-purple: #39265f;
    --browse-purple-dark: #281a46;
    --browse-purple-mid: #49316f;
    --browse-lavender: #f0ebf8;
    --browse-gold: #e2a94b;
    --browse-bg: #faf6ef;
    --browse-text: #29243a;
    --browse-muted: #777184;
    --browse-border: #e9e3ed;

    min-height: 70vh;
    padding-bottom: 50px;
    background: var(--browse-bg);
    color: var(--browse-text);
}

.user-browse *,
.user-browse *::before,
.user-browse *::after {
    box-sizing: border-box;
}

.user-browse .browse-hero {
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

.user-browse .browse-hero::before,
.user-browse .browse-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-browse .browse-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.user-browse .browse-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.user-browse .browse-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-browse .welcome-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px;
    background: rgba(255,255,255,.08);
    font-size: .84rem;
}

.user-browse .browse-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-browse .browse-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.user-browse .hero-icon {
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
    animation: browseFloat 4s ease-in-out infinite;
}

.user-browse .browse-content {
    animation: browseFadeUp .5s ease both;
}

.user-browse .browse-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 18px;
}

.user-browse .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 6px;
    color: var(--browse-text);
    font-size: 1.2rem;
    font-weight: 750;
}

.user-browse .section-heading::before {
    content: "";
    width: 5px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--browse-gold);
}

.user-browse .section-description {
    margin: 0;
    color: var(--browse-muted);
    font-size: .89rem;
    line-height: 1.7;
}

.user-browse .results-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border: 1px solid #e4dced;
    border-radius: 30px;
    background: #f7f4fb;
    color: var(--browse-purple);
    font-size: .83rem;
    font-weight: 750;
    white-space: nowrap;
}

.user-browse .dashboard-card {
    min-width: 0;
    padding: 21px;
    border: 1px solid var(--browse-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: border-color .22s ease, box-shadow .22s ease;
}

.user-browse .filters-card {
    margin-bottom: 24px;
    border-top: 3px solid var(--browse-gold);
    animation: browseFadeUp .35s ease both;
}

.user-browse .filters-card[hidden] {
    display: none !important;
}

.user-browse .filters-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}

.user-browse .filters-heading h2 {
    margin: 0;
    color: var(--browse-text);
    font-size: 1.1rem;
    font-weight: 750;
}

.user-browse .filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.user-browse .filter-group {
    min-width: 0;
    padding: 15px;
    border: 1px solid #eee8f3;
    border-radius: 13px;
    background: #fdfcff;
}

.user-browse .filter-group h3 {
    margin: 0 0 13px;
    color: var(--browse-purple);
    font-size: .91rem;
    font-weight: 750;
}

.user-browse .filter-options {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.user-browse .filter-option {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    color: #514b5d;
    font-size: .87rem;
    line-height: 1.55;
    cursor: pointer;
    overflow-wrap: anywhere;
}

.user-browse .filter-option input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin: 3px 0 0;
    accent-color: var(--browse-purple);
    cursor: pointer;
}

.user-browse .form-control {
    width: 100%;
    min-height: 43px;
    padding: 10px 12px;
    border: 1px solid #ddd5e7;
    border-radius: 9px;
    background: #fff;
    color: var(--browse-text);
    font: inherit;
    font-size: .88rem;
}

.user-browse .form-control:focus {
    outline: 3px solid rgba(57,38,95,.12);
    border-color: var(--browse-purple);
}

.user-browse .filter-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 20px;
}

.user-browse .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 38px;
    padding: 9px 14px;
    border: 1px solid transparent;
    border-radius: 9px;
    font: inherit;
    font-size: .85rem;
    font-weight: 700;
    line-height: 1.35;
    text-decoration: none;
    cursor: pointer;
    transition: transform .18s ease, background .18s ease,
                box-shadow .18s ease, border-color .18s ease;
}

.user-browse .btn:hover {
    transform: translateY(-1px);
}

.user-browse .btn-primary {
    border-color: var(--browse-purple);
    background: var(--browse-purple);
    color: #fff;
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
}

.user-browse .btn-primary:hover {
    border-color: var(--browse-purple-dark);
    background: var(--browse-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.2);
}

.user-browse .btn-outline {
    border-color: #d9cde8;
    background: #fff;
    color: var(--browse-purple);
}

.user-browse .btn-outline:hover {
    background: var(--browse-lavender);
}

.user-browse .btn-ghost {
    background: transparent;
    color: var(--browse-muted);
}

.user-browse .btn-ghost:hover {
    background: #f5f1f8;
    color: var(--browse-purple);
}

.user-browse .btn-sm {
    min-height: 35px;
    padding: 8px 12px;
    font-size: .82rem;
}

.user-browse .opportunity-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 16px;
}

.user-browse .opportunity-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 19px;
    border: 1px solid #ece7f2;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    animation: browseFadeUp .45s ease both;
    transition: transform .22s ease, box-shadow .22s ease,
                border-color .22s ease;
}

.user-browse .opportunity-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.1);
}

.user-browse .opportunity-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.user-browse .company-info {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.user-browse .company-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 14px;
    background: var(--browse-lavender);
    color: var(--browse-purple);
    font-size: 1.15rem;
    font-weight: 800;
}

.user-browse .company-details {
    min-width: 0;
}

.user-browse .opportunity-title {
    margin: 0 0 4px;
    color: var(--browse-text);
    font-size: 1rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-browse .company-name {
    color: var(--browse-muted);
    font-size: .82rem;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-browse .favorite-form {
    flex-shrink: 0;
    margin: 0;
}

.user-browse .favorite-button {
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
    transition: transform .18s ease, background .18s ease,
                border-color .18s ease;
}

.user-browse .favorite-button:hover {
    transform: translateY(-1px);
    border-color: #e5b9c1;
    background: #fff6f7;
}

.user-browse .favorite-button.is-favorite {
    border-color: #f1d4d8;
    background: #fff1f2;
    color: #bd334d;
}

.user-browse .opportunity-description {
    display: -webkit-box;
    overflow: hidden;
    margin: 0 0 17px;
    color: #625b6d;
    font-size: .87rem;
    line-height: 1.8;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}

.user-browse .opportunity-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 17px;
    color: var(--browse-muted);
    font-size: .81rem;
    line-height: 1.6;
}

.user-browse .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 30px;
    font-size: .76rem;
    font-weight: 750;
    white-space: normal;
    overflow-wrap: anywhere;
}

.user-browse .badge-course {
    background: #e8f5ec;
    color: #24653c;
}

.user-browse .badge-program {
    background: #eee8f8;
    color: var(--browse-purple);
}

.user-browse .badge-open {
    background: #e8f5ec;
    color: #24653c;
}

.user-browse .badge-closed {
    background: #f1edf4;
    color: #625b6d;
}

.user-browse .badge-other {
    background: #fff5e4;
    color: #80521b;
}

.user-browse .opportunity-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid #f0edf4;
}

.user-browse .empty-state {
    padding: 40px 20px;
    border: 1px dashed #d8cde7;
    border-radius: 16px;
    background: #fbf9fd;
    text-align: center;
    animation: browseFadeUp .4s ease both;
}

.user-browse .empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 66px;
    height: 66px;
    margin-bottom: 13px;
    border-radius: 21px;
    background: var(--browse-lavender);
    color: var(--browse-purple);
    font-size: 1.8rem;
}

.user-browse .empty-state h2 {
    margin: 0;
    color: var(--browse-text);
    font-size: 1.08rem;
    font-weight: 750;
}

.user-browse .empty-state p {
    margin: 8px 0 0;
    color: var(--browse-muted);
    font-size: .89rem;
    line-height: 1.8;
}

.user-browse .dashboard-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px 16px;
    margin-bottom: 20px;
    border: 1px solid #e4dced;
    border-radius: 13px;
    background: #f7f4fb;
    color: var(--browse-purple);
    font-size: .9rem;
    line-height: 1.65;
}

.user-browse .dashboard-notice.notice-warning {
    border-color: #f0d6b0;
    background: #fff8ed;
    color: #80521b;
}

.user-browse .dashboard-notice.notice-error {
    border-color: #f1d4d8;
    background: #fff4f5;
    color: #9c263d;
}

@keyframes browseFadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes browseFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@media (max-width: 950px) {
    .user-browse .opportunity-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .user-browse .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .user-browse .browse-hero {
        padding: 30px 0;
    }

    .user-browse .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .user-browse .opportunity-grid,
    .user-browse .filter-grid {
        grid-template-columns: 1fr;
    }

    .user-browse .dashboard-card {
        padding: 16px;
    }

    .user-browse .opportunity-card {
        padding: 16px;
    }

    .user-browse .browse-toolbar {
        align-items: flex-start;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-browse *,
    .user-browse *::before,
    .user-browse *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
</style>

<main class="user-browse">

    <!-- Welcome Banner -->
    <section class="browse-hero">
        <div class="container browse-hero-inner">

            <div>
                <div class="welcome-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Browse Opportunities</h1>

                <p>
                    Discover training programs and courses that support
                    your learning and career goals.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">⌕</div>

        </div>
    </section>

    <div class="container browse-content">

        <!-- Action Messages -->
        <?php $message = $_GET['msg'] ?? ''; ?>

        <?php if ($message === 'favorite_updated'): ?>
            <div class="dashboard-notice" role="status">
                <span aria-hidden="true">✓</span>
                <span>Your favorites have been updated.</span>
            </div>
        <?php elseif ($message === 'unavailable'): ?>
            <div class="dashboard-notice notice-warning" role="status">
                <span aria-hidden="true">!</span>
                <span>The selected opportunity could not be found.</span>
            </div>
        <?php elseif ($message === 'invalid'): ?>
            <div class="dashboard-notice notice-warning" role="status">
                <span aria-hidden="true">!</span>
                <span>Please select a valid opportunity.</span>
            </div>
        <?php elseif ($message === 'error'): ?>
            <div class="dashboard-notice notice-error" role="status">
                <span aria-hidden="true">!</span>
                <span>We could not update your favorites. Please try again.</span>
            </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="browse-toolbar">

            <div>
                <h2 class="section-heading">Explore Opportunities</h2>

                <p class="section-description">
                    Find courses and training programs using the filters below.
                </p>
            </div>

            <button
                type="button"
                class="btn btn-outline"
                id="filtersToggleBtn"
                aria-controls="filterPanel"
                aria-expanded="<?= $hasFilters ? 'true' : 'false' ?>"
            >
                <span aria-hidden="true">☷</span>
                <span id="filtersToggleText">
                    <?= $hasFilters ? 'Hide Filters' : 'Show Filters' ?>
                </span>
            </button>

        </div>

        <!-- Filters -->
        <form
            method="GET"
            action="browse.php"
            class="dashboard-card filters-card"
            id="filterPanel"
            <?= $hasFilters ? '' : 'hidden' ?>
        >

            <div class="filters-heading">
                <h2>Filter Opportunities</h2>

                <?php if ($hasFilters): ?>
                    <a href="browse.php" class="btn btn-ghost btn-sm">
                        Clear All
                    </a>
                <?php endif; ?>
            </div>

            <div class="filter-grid">

                <!-- Opportunity Type -->
                <div class="filter-group">
                    <h3>Opportunity Type</h3>

                    <div class="filter-options">

                        <?php foreach ($types as $item): ?>
                            <label class="filter-option">
                                <input
                                    type="radio"
                                    name="type"
                                    value="<?= browse_h($item) ?>"
                                    <?= $type === $item ? 'checked' : '' ?>
                                >
                                <span><?= browse_h($item) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <label class="filter-option">
                            <input
                                type="radio"
                                name="type"
                                value=""
                                <?= $type === '' ? 'checked' : '' ?>
                            >
                            <span>Any type</span>
                        </label>

                    </div>
                </div>

                <!-- Location -->
                <div class="filter-group">
                    <h3>Location / City</h3>

                    <select class="form-control" name="location">
                        <option value="">Any location</option>

                        <?php foreach ($locations as $item): ?>
                            <option
                                value="<?= browse_h($item) ?>"
                                <?= $location === $item ? 'selected' : '' ?>
                            >
                                <?= browse_h($item) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- Duration -->
                <div class="filter-group">
                    <h3>Duration</h3>

                    <div class="filter-options">

                        <?php foreach ($allowedDurations as $item): ?>
                            <label class="filter-option">
                                <input
                                    type="radio"
                                    name="duration"
                                    value="<?= browse_h($item) ?>"
                                    <?= $duration === $item ? 'checked' : '' ?>
                                >
                                <span><?= browse_h($item) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <label class="filter-option">
                            <input
                                type="radio"
                                name="duration"
                                value=""
                                <?= $duration === '' ? 'checked' : '' ?>
                            >
                            <span>Any duration</span>
                        </label>

                    </div>
                </div>

                <!-- Application Method -->
                <div class="filter-group">
                    <h3>Application Method</h3>

                    <div class="filter-options">

                        <?php foreach ($methods as $item): ?>
                            <label class="filter-option">
                                <input
                                    type="radio"
                                    name="method"
                                    value="<?= browse_h($item) ?>"
                                    <?= $method === $item ? 'checked' : '' ?>
                                >
                                <span><?= browse_h($item) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <label class="filter-option">
                            <input
                                type="radio"
                                name="method"
                                value=""
                                <?= $method === '' ? 'checked' : '' ?>
                            >
                            <span>Any method</span>
                        </label>

                    </div>
                </div>

                <!-- Company -->
                <div class="filter-group">
                    <h3>Company Name</h3>

                    <input
                        type="text"
                        class="form-control"
                        name="company"
                        value="<?= browse_h($company) ?>"
                        placeholder="Search by company name"
                        maxlength="150"
                    >
                </div>

            </div>

            <div class="filter-actions">

                <button type="submit" class="btn btn-primary">
                    <span aria-hidden="true">⌕</span>
                    Apply Filters
                </button>

                <a href="browse.php" class="btn btn-outline">
                    Reset Filters
                </a>

            </div>

        </form>

        <!-- Results -->
        <div class="browse-toolbar" style="margin-top:26px;">

            <div>
                <h2 class="section-heading">Available Listings</h2>

                <p class="section-description">
                    Browse the opportunities currently recorded in Ufuq.
                </p>
            </div>

            <div class="results-count">
                <?= count($opportunities) ?>
                <?= count($opportunities) === 1 ? 'result' : 'results' ?>
            </div>

        </div>

        <?php if (!$opportunities): ?>

            <div class="empty-state">

                <div class="empty-icon" aria-hidden="true">⌕</div>

                <h2>
                    <?= $hasFilters
                        ? 'No opportunities match your filters'
                        : 'No opportunities available yet' ?>
                </h2>

                <p>
                    <?= $hasFilters
                        ? 'Try changing your search criteria or clearing the filters.'
                        : 'New training programs and courses will appear here when available.' ?>
                </p>

                <?php if ($hasFilters): ?>
                    <div style="margin-top:17px;">
                        <a href="browse.php" class="btn btn-primary">
                            Clear Filters
                        </a>
                    </div>
                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="opportunity-grid">

                <?php foreach ($opportunities as $o): ?>

                    <?php
                    $id = (int)($o['OpportunityID'] ?? 0);

                    $title = $o['Title'] ?? 'Untitled Opportunity';
                    $provider = trim((string)($o['OpportunityProvider'] ?? ''));
                    $description = trim((string)($o['Description'] ?? ''));
                    $opType = trim((string)($o['Type'] ?? 'Opportunity'));
                    $opLocation = trim((string)($o['Location'] ?? ''));
                    $opStatus = trim((string)($o['Status'] ?? 'Not specified'));

                    if ($provider === '') {
                        $provider = 'External Company';
                    }

                    if ($opLocation === '') {
                        $opLocation = 'Location not specified';
                    }

                    $opDuration = browse_duration(
                        $o['StartDate'] ?? null,
                        $o['EndDate'] ?? null
                    );

                    $isFav = in_array($id, $favorites, true);

                    $descriptionLength = function_exists('mb_strlen')
                        ? mb_strlen($description, 'UTF-8')
                        : strlen($description);

                    $shortDescription = function_exists('mb_substr')
                        ? mb_substr($description, 0, 150, 'UTF-8')
                        : substr($description, 0, 150);

                    $statusClass = strcasecmp($opStatus, 'Open') === 0
                        ? 'badge-open'
                        : (
                            in_array(
                                strtolower($opStatus),
                                ['closed', 'expired', 'cancelled', 'canceled'],
                                true
                            )
                            ? 'badge-closed'
                            : 'badge-other'
                        );

                    $typeClass = strcasecmp($opType, 'Course') === 0
                        ? 'badge-course'
                        : 'badge-program';

                    $canApply = strcasecmp($opStatus, 'Open') === 0;
                    ?>

                    <article class="opportunity-card">

                        <div class="opportunity-top">

                            <div class="company-info">

                                <div class="company-logo" aria-hidden="true">
                                    <?= browse_h(browse_initial($provider)) ?>
                                </div>

                                <div class="company-details">

                                    <h3 class="opportunity-title">
                                        <?= browse_h($title) ?>
                                    </h3>

                                    <div class="company-name">
                                        <?= browse_h($provider) ?>
                                    </div>

                                </div>

                            </div>

                            <!-- Favorite -->
                            <form
                                method="POST"
                                action="<?= browse_h($filterAction) ?>"
                                class="favorite-form"
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= browse_h(csrf_token()) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="toggle_favorite"
                                >

                                <input
                                    type="hidden"
                                    name="opportunity_id"
                                    value="<?= $id ?>"
                                >

                                <button
                                    type="submit"
                                    class="favorite-button <?= $isFav ? 'is-favorite' : '' ?>"
                                    title="<?= $isFav ? 'Remove from favorites' : 'Save to favorites' ?>"
                                    aria-label="<?= $isFav ? 'Remove from favorites' : 'Save to favorites' ?>"
                                >
                                    <span aria-hidden="true">
                                        <?= $isFav ? '♥' : '♡' ?>
                                    </span>
                                </button>

                            </form>

                        </div>

                        <p class="opportunity-description">
                            <?php if ($description !== ''): ?>
                                <?= browse_h($shortDescription) ?>
                                <?= $descriptionLength > 150 ? '…' : '' ?>
                            <?php else: ?>
                                <span class="text-muted">
                                    No description provided.
                                </span>
                            <?php endif; ?>
                        </p>

                        <div class="opportunity-meta">

                            <span class="badge <?= $typeClass ?>">
                                <?= browse_h($opType) ?>
                            </span>

                            <span>
                                <span aria-hidden="true">⌖</span>
                                <?= browse_h($opLocation) ?>
                            </span>

                            <span>
                                <span aria-hidden="true">◷</span>
                                <?= browse_h($opDuration) ?>
                            </span>

                        </div>

                        <div class="opportunity-footer">

                            <span class="badge <?= $statusClass ?>">
                                <?= browse_h($opStatus) ?>
                            </span>

                            <a
                                class="btn btn-primary btn-sm"
                                href="opportunity-detail.php?id=<?= $id ?>"
                            >
                                View Details
                                <span aria-hidden="true">→</span>
                            </a>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<?php user_footer(); ?>

<script>
(function () {
    const filterPanel = document.getElementById('filterPanel');
    const toggleButton = document.getElementById('filtersToggleBtn');
    const toggleText = document.getElementById('filtersToggleText');

    if (!filterPanel || !toggleButton || !toggleText) {
        return;
    }

    toggleButton.addEventListener('click', function () {
        const willOpen = filterPanel.hidden;

        filterPanel.hidden = !willOpen;
        toggleButton.setAttribute('aria-expanded', String(willOpen));

        toggleText.textContent = willOpen
            ? 'Hide Filters'
            : 'Show Filters';
    });
})();
</script>
