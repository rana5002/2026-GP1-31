<?php


require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/lists.php';
require_once __DIR__ . '/../includes/recommendations.php';

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

/* Status of an opportunity: Open, Upcoming (applications not open yet) or Closed.
   Closed when the company closed it, or the application deadline has passed;
   otherwise worked out from the application dates. */
function browse_state(array $o): string
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

        recommender_reload();

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

/* ---------- Companies on Ufuq (collapsible list above the opportunities) ---------- */
$platformCompanies = [];
try {
    $platformCompanies = $pdo->query(
        "SELECT c.CompanyID, c.CompanyName, c.IndustrySector, c.Logo
         FROM company c
         WHERE c.Status = 'Verified'
         ORDER BY c.CompanyName"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Company list failed: ' . $e->getMessage());
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
/* The posting company's logo and name are used for the card avatar. */
$sql = 'SELECT o.*, cmp.Logo AS CompanyLogo, cmp.CompanyName AS PostingCompanyName
        FROM opportunity o
        LEFT JOIN company cmp ON cmp.CompanyID = o.CreatedByCompanyID';

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
$extraCss = '../css/browse.css';
user_header($me, 'Browse', 'browse');
?>


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

            <?php if ($search === '' && $platformCompanies): ?>
                <!-- Companies on Ufuq: opens / closes with the arrow (remembered in this browser) -->
                <section class="companies-panel" id="companiesPanel">
                    <div class="companies-head" id="companiesHead">
                        <div>
                            <h2 class="section-heading" style="margin:0">Companies on Ufuq</h2>
                            <p class="section-description companies-sub">Discover who offers opportunities on Ufuq, and open a company to see everything it offers.</p>
                        </div>
                        <div class="section-actions">
                            <div class="results-count">
                                <?= count($platformCompanies) ?> <?= count($platformCompanies) === 1 ? 'result' : 'results' ?>
                            </div>
                            <button type="button" class="companies-toggle" id="companiesToggle" aria-expanded="true" aria-controls="companiesBody" aria-label="Show or hide companies">
                                <span class="companies-caret" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>

                    <div class="companies-body" id="companiesBody">
                        <?php foreach ($platformCompanies as $pc): ?>
                            <?php
                                $pcName = trim((string)($pc['CompanyName'] ?? 'Company'));
                                $pcLogo = trim((string)($pc['Logo'] ?? ''));
                                $pcLogoSrc = $pcLogo === '' ? '' : (preg_match('~^https?://~i', $pcLogo) ? $pcLogo : '../' . ltrim($pcLogo, '/'));
                            ?>
                            <!-- Same look as the opportunity cards: white card, same logo box, same title font -->
                            <a class="company-tile" href="company-profile.php?id=<?= (int)$pc['CompanyID'] ?>">
                                <div class="company-logo" aria-hidden="true">
                                    <?php if ($pcLogoSrc !== ''): ?>
                                        <img src="<?= browse_h($pcLogoSrc) ?>" alt="">
                                    <?php else: ?>
                                        <?= browse_h(browse_initial($pcName)) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="company-tile-text">
                                    <h3 class="company-tile-name"><?= browse_h($pcName) ?></h3>
                                    <div class="company-tile-meta"><?= browse_h($pc['IndustrySector'] ?: 'Company profile') ?></div>
                                </div>
                                <span class="btn btn-primary btn-sm company-tile-btn">View profile <span aria-hidden="true">&rarr;</span></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <!-- Results -->
        <div class="browse-toolbar companies-head" id="oppHead">

            <div>
                <h2 class="section-heading">Available Opportunities</h2>

                <p class="section-description">
                    Browse the opportunities currently recorded in Ufuq.
                </p>
            </div>

            <div class="section-actions">
                <div class="results-count">
                    <?= count($opportunities) ?>
                    <?= count($opportunities) === 1 ? 'result' : 'results' ?>
                </div>
                <button type="button" class="companies-toggle" id="oppToggle" aria-expanded="true" aria-controls="oppBody" aria-label="Show or hide opportunities">
                    <span class="companies-caret" aria-hidden="true"></span>
                </button>
            </div>

        </div>

        <div id="oppBody" class="collapse-body">
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

                    /* Avatar: company logo if it has one, otherwise the first letter of the company name.
                       Opportunities added by the admin use the first letter of the opportunity provider. */
                    $avatarLogo = '';
                    $avatarName = $provider;
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
                                    <?php if ($avatarLogo !== ''): ?>
                                        <img src="<?= browse_h($avatarLogo) ?>" alt="">
                                    <?php else: ?>
                                        <?= browse_h(browse_initial($avatarName)) ?>
                                    <?php endif; ?>
                                </div>

                                <div class="company-details">
                                    <h3 class="opportunity-title">
                                        <a href="opportunity-detail.php?id=<?= $id ?>"><?= browse_h($title) ?></a>
                                    </h3>
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

                        <!-- Company name: under the logo + title row -->
                        <div class="company-name">
                            <?= browse_h($provider) ?>
                        </div>

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
        </div><!-- /#oppBody -->

        </div><!-- /.browse-results -->
        </div><!-- /.browse-layout -->

    </div>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


<script>
/* Sections that open / close with the arrow (clicking the heading row also works).
   Companies: the choice is remembered in this browser. Opportunities: always open when the page loads. */
(function () {
    function collapsible(headId, toggleId, bodyId, storageKey) {
        var head = document.getElementById(headId);
        var toggle = document.getElementById(toggleId);
        var body = document.getElementById(bodyId);
        if (!head || !toggle || !body) return;
        function set(open) {
            body.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        var saved = null;
        if (storageKey) { try { saved = localStorage.getItem(storageKey); } catch (e) {} }
        set(saved !== '0');
        head.addEventListener('click', function (ev) {
            if (ev.target.closest('a')) return;
            var open = toggle.getAttribute('aria-expanded') !== 'true';
            set(open);
            if (storageKey) { try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {} }
        });
    }
    collapsible('companiesHead', 'companiesToggle', 'companiesBody', 'ufuqCompaniesOpen');

    collapsible('oppHead', 'oppToggle', 'oppBody', null);
})();

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