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

$extraCss = '../css/home.css';
user_header($me, 'Home', 'user-home');

?>

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