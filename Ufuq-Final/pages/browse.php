<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/lists.php';

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

/* Number of days between the start and end dates, or null when unknown. */
function browse_duration_days($startDate, $endDate): ?int
{
    if (empty($startDate) || empty($endDate)) {
        return null;
    }

    try {
        $days = (int)(new DateTime($startDate))->diff(new DateTime($endDate))->format('%r%a');
        return $days >= 0 ? $days : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* Duration shown on the cards, in days (same as the details page). */
function browse_duration_text($startDate, $endDate): string
{
    $days = browse_duration_days($startDate, $endDate);
    return $days === null ? 'Not specified' : $days . ($days === 1 ? ' day' : ' days');
}

/* Status of an opportunity: Open, Upcoming (applications not open yet) or Closed. */
function browse_state(array $o): string
{
    $today    = date('Y-m-d');
    $status   = trim((string)($o['Status'] ?? ''));
    $deadline = (string)($o['ApplicationDeadLine'] ?? '');
    $openDate = (string)($o['ApplicationOpenDate'] ?? '');

    if (strcasecmp($status, 'Open') !== 0 || ($deadline !== '' && $deadline < $today)) {
        return 'Closed';
    }
    if ($openDate !== '' && $openDate > $today) {
        return 'Upcoming';
    }
    return 'Open';
}


/* Duration filter range for an opportunity, in days. */
function browse_duration($startDate, $endDate): string
{
    $days = browse_duration_days($startDate, $endDate);

    if ($days === null) {
        return 'Not specified';
    }

    if ($days <= 14) {
        return 'Up to 14 days';
    }

    if ($days <= 60) {
        return '15–60 days';
    }

    return 'More than 60 days';
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

/* Clean a list of text values from the query string (a single value is also accepted). */
function browse_str_list($value): array
{
    $out = [];

    foreach ((array)$value as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim($item);
        if ($item !== '' && mb_strlen($item, 'UTF-8') <= 100) {
            $out[$item] = $item;
        }
    }

    return array_values($out);
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
             FROM favorite
             WHERE UserID = ?
               AND OpportunityID = ?
             LIMIT 1'
        );
        $favoriteCheck->execute([$uid, $opportunityId]);

        if ($favoriteCheck->fetchColumn()) {
            $favoriteResult = 'favorite_removed';
            $delete = $pdo->prepare(
                'DELETE FROM favorite
                 WHERE UserID = ?
                   AND OpportunityID = ?'
            );
            $delete->execute([$uid, $opportunityId]);

        } else {
            $favoriteResult = 'favorite_added';
            $insert = $pdo->prepare(
                'INSERT INTO favorite (UserID, OpportunityID)
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
    $returnFilters = [];

    foreach (['q'] as $key) {
        if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
            $returnFilters[$key] = trim($_GET[$key]);
        }
    }

    foreach (['type', 'location', 'sector', 'duration', 'company'] as $key) {
        $values = browse_str_list($_GET[$key] ?? []);
        if ($values) {
            $returnFilters[$key] = $values;
        }
    }


    $returnFilters['msg'] = $favoriteResult;

    header('Location: browse.php?' . http_build_query($returnFilters));
    exit;
}

/* ---------- Search ---------- */
$search = isset($_GET['q']) && is_string($_GET['q'])
    ? trim($_GET['q']) : '';

if (mb_strlen($search, 'UTF-8') > 150) {
    $search = mb_substr($search, 0, 150, 'UTF-8');
}

/* ---------- Matching company profiles ---------- */
$companySearchResults = [];
if ($search !== '') {
    try {
        $companySearchStmt = $pdo->prepare(
            "SELECT CompanyID, CompanyName, IndustrySector, Location, Logo, Description
             FROM company
             WHERE Status = 'Verified'
               AND CompanyName LIKE ?
             ORDER BY CompanyName
             LIMIT 12"
        );
        $companySearchStmt->execute(['%' . $search . '%']);
        $companySearchResults = $companySearchStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Company profile search failed: ' . $e->getMessage());
    }
}

/* ---------- Filters ---------- */
/* Type, location, sector and duration accept several values (checkbox lists). */
$sectors = INDUSTRIES;

$typeFilter = array_values(array_intersect(browse_str_list($_GET['type'] ?? []), OPPORTUNITY_TYPES));
$locationFilter = array_values(array_intersect(browse_str_list($_GET['location'] ?? []), OPPORTUNITY_LOCATIONS));
$sectorFilter = array_values(array_intersect(browse_str_list($_GET['sector'] ?? []), $sectors));
$durationFilter = browse_str_list($_GET['duration'] ?? []);

/* Companies / providers that have opportunities (the provider name, or the posting company). */
$companies = $pdo->query(
    "SELECT DISTINCT COALESCE(NULLIF(TRIM(o.OpportunityProvider), ''), (SELECT c.CompanyName FROM company c WHERE c.CompanyID = o.CreatedByCompanyID)) AS Provider
     FROM opportunity o
     HAVING Provider IS NOT NULL AND Provider <> ''
     ORDER BY Provider"
)->fetchAll(PDO::FETCH_COLUMN);

$companyFilter = array_values(array_intersect(browse_str_list($_GET['company'] ?? []), $companies));


$allowedDurations = [
    'Up to 14 days',
    '15–60 days',
    'More than 60 days'
];

$durationFilter = array_values(array_intersect($durationFilter, $allowedDurations));

/* Status filter (Open / Upcoming / Closed). */
$allowedStatuses = ['Open', 'Upcoming', 'Closed'];
$statusFilter = array_values(array_intersect(browse_str_list($_GET['status'] ?? []), $allowedStatuses));

/* Two separate sort choices: by name, and by application dates. */
$sortOptions = [
    'newest' => 'Recently added',
    'az'     => 'A to Z',
];
$sort = (string)($_GET['sort'] ?? 'newest');
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$whenOptions = [
    ''        => 'Any',
    'closing' => 'Closing next',
    'opening' => 'Opening next',
];
$when = (string)($_GET['when'] ?? '');
if (!isset($whenOptions[$when])) {
    $when = '';
}

/* ---------- Build opportunity query ---------- */
$where = [];
$params = [];

/* Search by opportunity title or company/provider name. */
if ($search !== '') {
    $where[] = "(
        o.Title LIKE ?
        OR o.OpportunityProvider LIKE ?
        OR EXISTS (
            SELECT 1
            FROM company c
            WHERE c.CompanyID = o.CreatedByCompanyID
              AND c.CompanyName LIKE ?
        )
    )";

    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

/* Within one filter: any of the selected values. Between filters: all must match. */
$listFilters = [
    'o.Type'           => $typeFilter,
    'o.Location'       => $locationFilter,
    'o.IndustrySector' => $sectorFilter,
   
];

foreach ($listFilters as $column => $values) {
    if ($values) {
        $where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
        array_push($params, ...$values);
    }
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
if ($durationFilter) {
    $opportunities = array_values(
        array_filter(
            $opportunities,
            static function ($o) use ($durationFilter) {
                return in_array(
                    browse_duration($o['StartDate'] ?? null, $o['EndDate'] ?? null),
                    $durationFilter,
                    true
                );
            }
        )
    );
}


/* ---------- Filter by status ---------- */
if ($statusFilter) {
    $opportunities = array_values(array_filter(
        $opportunities,
        static fn($o) => in_array(browse_state($o), $statusFilter, true)
    ));
}

/* ---------- Sort ---------- */
/* "When" is the main order (closing next / opening next); A to Z breaks ties, or sorts alone. */
if ($sort === 'az' || $when !== '') {
    $rank = ['Upcoming' => 0, 'Open' => 1, 'Closed' => 2];
    usort($opportunities, static function ($a, $b) use ($sort, $when, $rank) {
        if ($when === 'closing') {
            /* Open and upcoming first, the application that closes next on top; closed ones last. */
            $ca = browse_state($a) === 'Closed' ? 1 : 0;
            $cb = browse_state($b) === 'Closed' ? 1 : 0;
            if ($ca !== $cb) return $ca <=> $cb;
            $da = (string)($a['ApplicationDeadLine'] ?? '') ?: '9999-12-31';
            $db = (string)($b['ApplicationDeadLine'] ?? '') ?: '9999-12-31';
            if ($da !== $db) return $da <=> $db;
        } elseif ($when === 'opening') {
            /* Upcoming first (the one that opens next on top), then open ones, then closed ones. */
            $ra = $rank[browse_state($a)];
            $rb = $rank[browse_state($b)];
            if ($ra !== $rb) return $ra <=> $rb;
            $key = $ra === 0 ? 'ApplicationOpenDate' : 'ApplicationDeadLine';
            $da = (string)($a[$key] ?? '') ?: '9999-12-31';
            $db = (string)($b[$key] ?? '') ?: '9999-12-31';
            if ($da !== $db) return $da <=> $db;
        }
        if ($sort === 'az') {
            return strcmp(mb_strtolower((string)$a['Title'], 'UTF-8'), mb_strtolower((string)$b['Title'], 'UTF-8'));
        }
        return 0;
    });
}


/* ---------- Current user's favorites ---------- */
$favStmt = $pdo->prepare(
    'SELECT OpportunityID
     FROM favorite
     WHERE UserID = ?'
);
$favStmt->execute([$uid]);

$favorites = array_map(
    'intval',
    $favStmt->fetchAll(PDO::FETCH_COLUMN)
);

$hasFilters = (
    $typeFilter
    || $locationFilter
    || $sectorFilter
    || $durationFilter
    || $statusFilter
    || $companyFilter
);

/* ---------- Safe filter query string ---------- */
$filterQuery = [];

foreach (['q' => $search] as $key => $value) {
    if ($value !== '') {
        $filterQuery[$key] = $value;
    }
}

foreach (['type' => $typeFilter, 'location' => $locationFilter, 'sector' => $sectorFilter, 'duration' => $durationFilter , 'status' => $statusFilter, 'company' => $companyFilter] as $key => $values) {
    if ($values) {
        $filterQuery[$key] = $values;
    }
}

if ($sort !== 'newest') {
    $filterQuery['sort'] = $sort;
}
if ($when !== '') {
    $filterQuery['when'] = $when;
}


$filterAction = 'browse.php';

if ($filterQuery) {
    $filterAction .= '?' . http_build_query($filterQuery);
}

/* Remember this search / filters, so "Back to Browse" on the details page returns to the same results. */
$_SESSION['browse_back'] = $filterAction;

/* Number of active filters, shown on the Filters button. */
$activeFilterCount = count($typeFilter) + count($locationFilter) + count($sectorFilter)
    + count($durationFilter) + count($statusFilter) + count($companyFilter);

/* Checkbox list with search, same as the company "Filter Applicants" sidebar.
   Collapsed by default; opened when something in it is selected. The triangle opens / closes it. */
function browse_lookup_box(string $name, string $label, string $searchPlaceholder, array $options, array $selected): void
{
    /* $options: [value => label]. Selected items are listed first. */
    $selected = array_map('strval', $selected);
    $checked = $unchecked = [];
    foreach ($options as $value => $text) {
        if (in_array((string)$value, $selected, true)) {
            $checked[(string)$value] = $text;
        } else {
            $unchecked[(string)$value] = $text;
        }
    }
    $isOpen = (bool)$checked;
    $hasSearch = count($options) > 5;
    $boxId = 'lookup-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
    ?>
    <div class="filter-field lookup-field <?= $isOpen ? 'is-open' : '' ?>" data-lookup-box>
        <button type="button" class="lookup-header" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= browse_h($boxId) ?>">
            <span class="filter-label"><?= browse_h($label) ?> <span class="lookup-count"><?= $checked ? '(' . count($checked) . ' selected)' : '' ?></span></span>
            <span class="lookup-caret" aria-hidden="true"></span>
        </button>
        <div class="lookup-box" id="<?= browse_h($boxId) ?>" <?= $isOpen ? '' : 'hidden' ?>>
            <?php if ($hasSearch): ?>
                <input type="text" class="form-control lookup-search" placeholder="<?= browse_h($searchPlaceholder) ?>" autocomplete="off" aria-label="<?= browse_h($searchPlaceholder) ?>">
            <?php endif; ?>
            <div class="lookup-list <?= $hasSearch ? '' : 'no-search' ?>" role="group" aria-label="<?= browse_h($label) ?>">
                <?php foreach ($checked + $unchecked as $value => $text): ?>
                    <label class="lookup-option" data-text="<?= browse_h(mb_strtolower((string)$text, 'UTF-8')) ?>">
                        <input type="checkbox" name="<?= browse_h($name) ?>[]" value="<?= browse_h($value) ?>" <?= isset($checked[$value]) ? 'checked' : '' ?>>
                        <span><?= browse_h($text) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="lookup-empty" hidden>No matches found</div>
            </div>
        </div>
    </div>
    <?php
}

/* [value => label] lists for the checkbox boxes. */
function browse_pairs(array $values): array
{
    return array_combine($values, $values) ?: [];
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

.user-browse .search-card {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    padding: 14px;
}

.user-browse .search-card .form-control {
    flex: 1;
    min-width: 0;
}

.user-browse .search-card .btn {
    min-height: 43px;
    flex-shrink: 0;
}

.user-browse .opportunity-title a {
    color: inherit;
    text-decoration: none;
}

.user-browse .opportunity-title a:hover {
    color: var(--browse-purple);
    text-decoration: underline;
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
    border: 1px solid #f1d4d8;
    background: #fff1f2;
    color: #9c263d;
}

.user-browse .badge-other {
    background: #fff5e4;
    color: #80521b;
}

/* Upcoming: applications not open yet (grey) */
.user-browse .badge-upcoming {
    border: 1px solid #e3e0e8;
    background: #f3f2f5;
    color: #5f5a6b;
}

/* Card footer: status on the left, View Details on the right */
.user-browse .opportunity-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: auto;
    padding-top: 15px;
    border-top: 1px solid #f0edf4;
}

.user-browse .opportunity-footer .badge {
    padding: 6px 12px;
    font-size: .8rem;
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

/* Toast: success message that slides down from the top, then disappears */
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

/* ===== Searchable multi-select dropdowns ===== */
.user-browse .multi-dropdown {
    position: relative;
}

.user-browse .multi-dropdown-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    text-align: left;
    cursor: pointer;
}

.user-browse .multi-dropdown-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-browse .multi-dropdown-label.is-placeholder {
    color: var(--browse-muted);
}

.user-browse .multi-dropdown-caret {
    flex-shrink: 0;
    color: var(--browse-muted);
    font-size: .8rem;
    transition: transform .18s ease;
}

.user-browse .multi-dropdown.is-open .multi-dropdown-caret {
    transform: rotate(180deg);
}

.user-browse .multi-dropdown-panel {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    z-index: 30;
    padding: 10px;
    border: 1px solid var(--browse-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 12px 30px rgba(40,26,70,.14);
}

.user-browse .multi-dropdown-panel[hidden] {
    display: none !important;
}

.user-browse .multi-dropdown-list {
    max-height: 210px;
    margin-top: 8px;
    overflow-y: auto;
}

.user-browse .multi-dropdown-option {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 8px 8px;
    border-radius: 8px;
    color: #514b5d;
    font-size: .87rem;
    line-height: 1.5;
    cursor: pointer;
    overflow-wrap: anywhere;
}

.user-browse .multi-dropdown-option:hover {
    background: var(--browse-lavender);
}

.user-browse .multi-dropdown-option[hidden] {
    display: none !important;
}

.user-browse .multi-dropdown-option input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin: 3px 0 0;
    accent-color: var(--browse-purple);
    cursor: pointer;
}

.user-browse .multi-dropdown-empty {
    padding: 10px 8px;
    color: var(--browse-muted);
    font-size: .85rem;
    text-align: center;
}

/* ===== Filter sidebar on the left (same idea as the company "Filter Applicants") ===== */
.user-browse .browse-filter-bar {
    display: flex;
    justify-content: flex-end;
    margin: 0 0 10px;
}

.user-browse .filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}


/* Sort button + menu (next to the Filters button) */
.user-browse .sort-wrap { position: relative; flex-shrink: 0; }
.user-browse .sort-toggle { display: inline-flex; align-items: center; gap: 8px; }
.user-browse .sort-toggle[aria-expanded="true"],
.user-browse .sort-toggle.is-active { background: var(--browse-lavender); }
.user-browse .sort-menu {
    position: absolute; top: calc(100% + 8px); left: 0; z-index: 50; width: 250px; padding: 10px;
    border: 1px solid #e5ddef; border-radius: 14px; background: #fff; box-shadow: 0 12px 30px rgba(40, 26, 70, .16);
}
.user-browse .sort-menu[hidden] { display: none; }
.user-browse .sort-menu-title { margin: 6px 8px 4px; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: #8a8396; }
.user-browse .sort-menu-line { margin: 6px 0; border-top: 1px solid #eee7f6; }
.user-browse .sort-option { display: flex; align-items: center; gap: 10px; padding: 9px 8px; border-radius: 9px; cursor: pointer; font-size: .92rem; color: #29243a; }
.user-browse .sort-option:hover { background: var(--browse-lavender); }
.user-browse .sort-option input { width: 16px; height: 16px; margin: 0; accent-color: #39265f; }



/* Filters button: same color as when you hover on it, while the filter card is open */
.user-browse .filter-toggle[aria-expanded="true"] {
    background: var(--browse-lavender);
}


.user-browse .filter-toggle-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--browse-gold);
    color: #fff;
    font-size: .74rem;
    font-weight: 800;
}

.user-browse .browse-layout {
    display: grid;
    grid-template-columns: 290px minmax(0, 1fr);
    gap: 22px;
    align-items: start;
}

.user-browse .browse-layout.filters-hidden {
    grid-template-columns: minmax(0, 1fr);
}

.user-browse .browse-layout.filters-hidden .filter-panel {
    display: none;
}

.user-browse .browse-results {
    min-width: 0;
}

/* Only the filter moves left, into the empty space beside the page; the opportunity cards stay in place. */
.user-browse .browse-content {
    --filter-shift: min(312px, max(0px, calc((100vw - 100%) / 2 - 16px)));
}

.user-browse .browse-layout {
    margin-left: calc(-1 * var(--filter-shift));
}

.user-browse .browse-layout.filters-hidden {
    margin-left: 0;
}

.user-browse .filter-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px;
    background: #fff;
    border: 1px solid var(--browse-border);
    border-radius: 16px;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    animation: browseFadeUp .3s ease both;
}

.user-browse .filter-panel-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee8f4;
    color: var(--browse-text);
    font-size: 1rem;
    font-weight: 750;
}

.user-browse .filter-panel-title::before {
    content: "";
    display: block;
    width: 5px;
    height: 20px;
    border-radius: 5px;
    background: var(--browse-gold);
}

.user-browse .filter-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 0;
}

.user-browse .filter-field > label,
.user-browse .filter-label {
    color: var(--browse-text);
    font-size: .86rem;
    font-weight: 700;
}

.user-browse .filter-field .form-control {
    min-height: 42px;
    padding: 9px 11px;
}

.user-browse .filter-panel-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-browse .filter-panel-actions .btn {
    flex: 1;
}



/* Category header with a triangle that opens / closes its list */
.user-browse .lookup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    padding: 4px 2px;
    border: 0;
    border-radius: 8px;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.user-browse .lookup-header:hover .filter-label {
    color: var(--browse-purple);
}

.user-browse .lookup-header:focus-visible {
    outline: 2px solid var(--browse-purple);
    outline-offset: 2px;
}

.user-browse .lookup-caret {
    flex-shrink: 0;
    width: 0;
    height: 0;
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-top: 7px solid var(--browse-purple);
    transition: transform .2s ease;
}

.user-browse .lookup-field.is-open .lookup-caret {
    transform: rotate(180deg);
}

.user-browse .lookup-box[hidden] {
    display: none !important;
}

/* Checkbox list box with search: shows 5 items, scroll for the rest */
.user-browse .lookup-count {
    color: var(--browse-purple);
    font-size: .8rem;
    font-weight: 650;
}

.user-browse .lookup-box {
    padding: 8px;
    border: 1px solid #ddd5e8;
    border-radius: 10px;
    background: #fff;
    animation: browseFadeUp .2s ease both;
}

.user-browse .lookup-box:focus-within {
    border-color: var(--browse-purple);
    box-shadow: 0 0 0 3px rgba(57,38,95,.10);
}

.user-browse .lookup-box .lookup-search {
    min-height: 36px;
    padding: 7px 10px;
    font-size: .85rem;
}

.user-browse .lookup-list {
    max-height: 180px;
    margin-top: 6px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.user-browse .lookup-list.no-search {
    margin-top: 0;
}

.user-browse .lookup-option {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 36px;
    padding: 6px 8px;
    border-radius: 8px;
    color: #514b5d;
    font-size: .87rem;
    line-height: 1.4;
    cursor: pointer;
    overflow-wrap: anywhere;
}

.user-browse .lookup-option:hover {
    background: var(--browse-lavender);
}

.user-browse .lookup-option:has(input:checked) {
    background: #f2ecfa;
    color: var(--browse-purple-dark);
    font-weight: 650;
}

.user-browse .lookup-option[hidden] {
    display: none !important;
}

.user-browse .lookup-option input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin: 0;
    accent-color: var(--browse-purple);
    cursor: pointer;
}

.user-browse .lookup-empty {
    padding: 10px 8px;
    color: var(--browse-muted);
    font-size: .85rem;
    text-align: center;
}

/* With the filter open, the cards share the remaining width. */
.user-browse .browse-layout:not(.filters-hidden) .opportunity-grid {
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
}

@media (max-width: 900px) {
    .user-browse .browse-layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .user-browse .browse-layout {
        margin-left: 0;
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

/* Company profile search results */
.user-browse .company-search-results {
    margin: 0 0 24px;
}
.user-browse .company-search-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 12px;
    margin-top: 13px;
}
.user-browse .company-search-card {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    padding: 15px;
    border: 1px solid #e2d7ec;
    border-radius: 14px;
    background: linear-gradient(135deg, #fff 0%, #f3edf8 100%);
    color: var(--browse-text);
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(40,26,70,.05);
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.user-browse .company-search-card:hover {
    transform: translateY(-3px);
    border-color: #bba8d1;
    box-shadow: 0 10px 22px rgba(40,26,70,.11);
}
.user-browse .company-search-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    overflow: hidden;
    border-radius: 13px;
    background: #e5d9f0;
    color: var(--browse-purple);
    font-weight: 800;
    font-size: 1.1rem;
}
.user-browse .company-search-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.user-browse .company-search-name {
    display: block;
    color: var(--browse-purple);
    font-weight: 800;
    overflow-wrap: anywhere;
}
.user-browse .company-search-meta {
    display: block;
    margin-top: 3px;
    color: var(--browse-muted);
    font-size: .8rem;
}
.user-browse .company-search-arrow {
    margin-left: auto;
    color: #8e789f;
    font-size: 1.15rem;
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

        <?php if (in_array($message, ['favorite_added', 'favorite_removed', 'favorite_updated'], true)): ?>
            <!-- Success message: slides down from the top, then disappears (same as the dashboards) -->
            <div class="browse-toast" id="browseToast" role="status" aria-live="polite">
                <span class="browse-toast-icon" aria-hidden="true">✓</span>
                <span><?= $message === 'favorite_added' ? 'Added to your favorites.' : 'Removed from your favorites.' ?></span>
                <button type="button" class="browse-toast-close" aria-label="Close">&times;</button>
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

        <div class="browse-layout" id="browseLayout">

               <!-- Filter sidebar (left) -->
        <form method="GET" action="browse.php" class="filter-panel" id="filterPanel">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="q" value="<?= browse_h($search) ?>">
            <?php endif; ?>
            <?php if ($sort !== 'newest'): ?>
                <input type="hidden" name="sort" value="<?= browse_h($sort) ?>">
            <?php endif; ?>
            <?php if ($when !== ''): ?>
                <input type="hidden" name="when" value="<?= browse_h($when) ?>">
            <?php endif; ?>



            <h3 class="filter-panel-title">Filter Opportunities</h3>

           

            <?php browse_lookup_box('status', 'Status', 'Search status...', browse_pairs($allowedStatuses), $statusFilter); ?>
            <?php browse_lookup_box('type', 'Opportunity Type', 'Search types...', browse_pairs(OPPORTUNITY_TYPES), $typeFilter); ?>
            <?php browse_lookup_box('location', 'Opportunity Location', 'Search cities...', browse_pairs(OPPORTUNITY_LOCATIONS), $locationFilter); ?>
            <?php browse_lookup_box('sector', 'Industry Sector', 'Search sectors...', browse_pairs($sectors), $sectorFilter); ?>
            <?php browse_lookup_box('duration', 'Duration', 'Search durations...', browse_pairs($allowedDurations), $durationFilter); ?>


        

            <div class="filter-panel-actions">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="<?= browse_h('browse.php' . ($search !== '' ? '?' . http_build_query(['q' => $search]) : '')) ?>" class="btn btn-ghost">Reset</a>
            </div>

         
        </form>

        <div class="browse-results">

         

            <!-- Search: opportunity or company name (Enter or the Search button) -->
            <form method="GET" action="browse.php" class="dashboard-card search-card" role="search">
                
                <!-- Filters button: opens / closes the filter card on the left -->
                <button
                    type="button"
                    class="btn btn-outline filter-toggle"
                    id="filterToggle"
                    aria-controls="filterPanel"
                    aria-expanded="true"
                >
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4 2v-8z"/></svg>
                    <span class="filter-toggle-text">Filters</span>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="filter-toggle-count" title="Active filters"><?= $activeFilterCount ?></span>
                    <?php endif; ?>
                </button>

             <!-- Sort button: menu with the sort choices (applies when you pick one) -->
                <div class="sort-wrap">
                    <button
                        type="button"
                        class="btn btn-outline sort-toggle<?= ($sort !== 'newest' || $when !== '') ? ' is-active' : '' ?>"
                        id="sortToggle"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="sortMenu"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></svg>
                        <span>Sort</span>
                    </button>

                    <div class="sort-menu" id="sortMenu" hidden>
                        <div class="sort-menu-title">Sort by</div>
                        <?php foreach ($sortOptions as $value => $text): ?>
                            <label class="sort-option">
                                <input type="radio" name="sort" value="<?= browse_h($value) ?>" <?= $sort === $value ? 'checked' : '' ?>>
                                <span><?= browse_h($text) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <div class="sort-menu-line"></div>

                        <div class="sort-menu-title">Application dates</div>
                        <?php foreach ($whenOptions as $value => $text): ?>
                            <label class="sort-option">
                                <input type="radio" name="when" value="<?= browse_h($value) ?>" <?= $when === $value ? 'checked' : '' ?>>
                                <span><?= browse_h($text) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php foreach ($filterQuery as $key => $value): ?>
                    <?php if ($key === 'q' || $key === 'sort' || $key === 'when') continue; ?>
                    <?php foreach ((array)$value as $item): ?>
                        <input type="hidden" name="<?= browse_h($key) ?><?= is_array($value) ? '[]' : '' ?>" value="<?= browse_h($item) ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <input
                    type="search"
                    class="form-control"
                    name="q"
                    value="<?= browse_h($search) ?>"
                    placeholder="Search by opportunity or company name"
                    maxlength="150"
                    aria-label="Search by opportunity or company name"
                >

                <button type="submit" class="btn btn-primary">
                    <span aria-hidden="true">⌕</span>
                    Search
                </button>

                <?php if ($search !== ''): ?>
                    <a href="<?= browse_h('browse.php' . (($tmp = array_diff_key($filterQuery, ['q' => 1])) ? '?' . http_build_query($tmp) : '')) ?>" class="btn btn-ghost">
                        Clear
                    </a>
                <?php endif; ?>
            </form>

            <?php if ($search !== ''): ?>
                <section class="company-search-results" aria-labelledby="companySearchHeading">
                    <h2 class="section-heading" id="companySearchHeading">Companies matching your search</h2>
                    <p class="section-description">Select a company to view its profile and details.</p>
                    <?php if ($companySearchResults): ?>
                        <div class="company-search-grid">
                            <?php foreach ($companySearchResults as $companyResult): ?>
                                <?php
                                    $companyName = trim((string)($companyResult['CompanyName'] ?? 'Company'));
                                    $companyLogo = trim((string)($companyResult['Logo'] ?? ''));
                                    $companyLogoSrc = $companyLogo === '' ? '' : (preg_match('~^https?://~i', $companyLogo) ? $companyLogo : '../' . ltrim($companyLogo, '/'));
                                    $companyInitial = browse_initial($companyName);
                                ?>
                                <a class="company-search-card" href="company-profile.php?id=<?= (int)$companyResult['CompanyID'] ?>">
                                    <span class="company-search-logo">
                                        <?php if ($companyLogoSrc !== ''): ?>
                                            <img src="<?= browse_h($companyLogoSrc) ?>" alt="">
                                        <?php else: ?>
                                            <?= browse_h($companyInitial) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span style="min-width:0">
                                        <span class="company-search-name"><?= browse_h($companyName) ?></span>
                                        <span class="company-search-meta"><?= browse_h($companyResult['IndustrySector'] ?: 'Company profile') ?></span>
                                    </span>
                                    <span class="company-search-arrow" aria-hidden="true">›</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="section-description" style="margin-top:10px">No verified company profiles matched this name.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

        <!-- Results -->
        <div class="browse-toolbar">

            <div>
                <h2 class="section-heading">Available Opportunities</h2>

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
                        ? 'No opportunities match your selected filter criteria'
                        : ($search !== ''
                            ? 'No opportunities or courses found matching your search'
                            : 'No opportunities available yet') ?>
                </h2>

                <p>
                    <?= $hasFilters
                        ? 'Try changing your filters or clearing them.'
                        : ($search !== ''
                            ? 'Try a different opportunity or company name.'
                            : 'New training programs and courses will appear here when available.') ?>
                </p>

                <?php if ($hasFilters || $search !== ''): ?>
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

                    $opDuration = browse_duration_text(
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

                    /* Past the application deadline counts as Closed, even if the status is still Open. */
                    $opDeadline = (string)($o['ApplicationDeadLine'] ?? '');
                    if (
                        strcasecmp($opStatus, 'Open') === 0
                        && $opDeadline !== ''
                        && $opDeadline < date('Y-m-d')
                    ) {
                        $opStatus = 'Closed';
                    }

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
                                        <a href="opportunity-detail.php?id=<?= $id ?>"><?= browse_h($title) ?></a>
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

                        <?php
                        /* Same status as the Status filter: Open, Upcoming or Closed. */
                        $cardState = browse_state($o);
                        $cardStateClass = $cardState === 'Open'
                            ? 'badge-open'
                            : ($cardState === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
                        ?>
                        <div class="opportunity-footer">
                            <span class="badge <?= $cardStateClass ?>"><?= browse_h($cardState) ?></span>

                            <a class="btn btn-primary" href="opportunity-detail.php?id=<?= $id ?>">
                                View Details <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        </div><!-- /.browse-results -->
        </div><!-- /.browse-layout -->

    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


<script>
/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('browseToast');
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
    toast.querySelector('.browse-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();


/* Sort menu: opens under the Sort button; picking a choice applies it right away. */
(function () {
    const btn = document.getElementById('sortToggle');
    const menu = document.getElementById('sortMenu');
    if (!btn || !menu) return;

    function setOpen(open) {
        menu.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
    }

    btn.addEventListener('click', function () { setOpen(menu.hidden); });
    document.addEventListener('click', function (e) {
        if (!menu.hidden && !menu.contains(e.target) && !btn.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { setOpen(false); btn.focus(); }
    });
    menu.addEventListener('change', function () { btn.closest('form').submit(); });
})();





/* Show / hide the filter sidebar (remembered in this browser). */
(function () {
    const layout = document.getElementById('browseLayout');
    const btn = document.getElementById('filterToggle');
    if (!layout || !btn) return;
    

    function setHidden(hidden) {
        layout.classList.toggle('filters-hidden', hidden);
        btn.setAttribute('aria-expanded', String(!hidden));
        
        try { localStorage.setItem('ufuqBrowseFiltersHidden', hidden ? '1' : '0'); } catch (e) {}
    }

    let hidden = false;
    try { hidden = localStorage.getItem('ufuqBrowseFiltersHidden') === '1'; } catch (e) {}
    setHidden(hidden);
    btn.addEventListener('click', function () {
        setHidden(!layout.classList.contains('filters-hidden'));
    });
})();

/* Checkbox list boxes (skills / qualifications / experience): triangle, search and selected count. */
document.querySelectorAll('[data-lookup-box]').forEach(function (field) {
    const search = field.querySelector('.lookup-search');
    const options = field.querySelectorAll('.lookup-option');
    const boxes = field.querySelectorAll('input[type="checkbox"]');
    const empty = field.querySelector('.lookup-empty');
    const countEl = field.querySelector('.lookup-count');
    const header = field.querySelector('.lookup-header');
    const box = field.querySelector('.lookup-box');

    header.addEventListener('click', function () {
        const willOpen = box.hidden;
        box.hidden = !willOpen;
        field.classList.toggle('is-open', willOpen);
        header.setAttribute('aria-expanded', String(willOpen));
        if (willOpen && search) search.focus();
    });

    function updateCount() {
        const n = Array.from(boxes).filter(function (b) { return b.checked; }).length;
        countEl.textContent = n ? '(' + n + ' selected)' : '';
    }

    if (search) {
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            let visible = 0;
            options.forEach(function (opt) {
                const match = opt.dataset.text.indexOf(q) !== -1;
                opt.hidden = !match;
                if (match) visible++;
            });
            empty.hidden = visible !== 0;
        });

        /* Enter in the search box should not submit the filters. */
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    }
    field.addEventListener('change', updateCount);
    updateCount();
});
</script>