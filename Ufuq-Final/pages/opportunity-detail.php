<?php


require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/confirm-modal.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/recommendations.php';

/* ---------- Database connection ---------- */
if (!isset($pdo) && isset($db)) {
    $pdo = $db;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection not found. Check bootstrap.php.');
}

require_login('User');

$uid = (int) ($_SESSION['user_id'] ?? 0);


/* Remember the page the student came from, for the Back link. */
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$refHost  = strtolower((string) parse_url($ref, PHP_URL_HOST));
$selfHost = strtolower((string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $ref !== ''
    && $refHost !== '' && $refHost === $selfHost
    && strpos($ref, 'opportunity-detail.php') === false
) {
    $_SESSION['detail_back'] = $ref;
}


/* ---------- Validate opportunity ID ---------- */
$opportunityId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$opportunityId || $opportunityId < 1) {
    header('Location: browse.php');
    exit;
}

/* ---------- Helpers ---------- */
function detail_h($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function detail_date($date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return 'Not specified';
    }

    try {
        return (new DateTime((string) $date))->format('d M Y');
    } catch (Throwable $e) {
        return 'Not specified';
    }
}

/* True when the opening date is set and still in the future. */
function detail_not_open_yet($openDate): bool
{
    return !empty($openDate)
        && $openDate !== '0000-00-00'
        && strtotime((string) $openDate) !== false
        && strtotime((string) $openDate) > strtotime(date('Y-m-d'));
}

function detail_is_open($status, $deadline, $openDate = null): bool
{
    if (strcasecmp(trim((string) $status), 'Closed') === 0) {
        return false;
    }

    /* Applications have not opened yet. */
    if (detail_not_open_yet($openDate)) {
        return false;
    }

    if (
        !empty($deadline)
        && $deadline !== '0000-00-00'
        && strtotime((string) $deadline) !== false
        && strtotime((string) $deadline) < strtotime(date('Y-m-d'))
    ) {
        return false;
    }

    return true;
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------- Current user ---------- */
$stmt = $pdo->prepare(
    'SELECT *
     FROM `user`
     WHERE UserID = ?
     LIMIT 1'
);
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: login.php');
    exit;
}

/* ---------- Fetch opportunity ---------- */
$stmt = $pdo->prepare(
    'SELECT *
     FROM opportunity
     WHERE OpportunityID = ?
     LIMIT 1'
);
$stmt->execute([$opportunityId]);
$opp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$opp) {
    http_response_code(404);
}

/* ---------- Count the view (once per session for each opportunity) ---------- */
if ($opp && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $viewedKey = (int) $opp['OpportunityID'];

    if (empty($_SESSION['viewed_opportunities'][$viewedKey])) {
        try {
            $pdo->prepare(
                'UPDATE opportunity
                 SET ViewCount = ViewCount + 1
                 WHERE OpportunityID = ?'
            )->execute([$viewedKey]);

            $_SESSION['viewed_opportunities'][$viewedKey] = true;
        } catch (Throwable $ignored) {
            // Never break the page if the counter cannot be updated.
        }
    }
}

/* ---------- Handle POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $opp) {
    $submittedToken = (string) ($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    if (
        $submittedToken === ''
        || $sessionToken === ''
        || !hash_equals($sessionToken, $submittedToken)
    ) {
        $_SESSION['detail_message'] =
            'Your session has expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        /* Save / unsave favorite */
        if ($action === 'toggle_favorite') {
            $check = $pdo->prepare(
                'SELECT 1
                 FROM favorite
                 WHERE UserID = ? AND OpportunityID = ?
                 LIMIT 1'
            );
            $check->execute([$uid, $opportunityId]);

            if ($check->fetchColumn()) {
                $stmt = $pdo->prepare(
                    'DELETE FROM favorite
                     WHERE UserID = ? AND OpportunityID = ?'
                );
                $stmt->execute([$uid, $opportunityId]);

                $_SESSION['detail_message'] =
                    'Removed from your favorites.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO favorite (UserID, OpportunityID)
                     VALUES (?, ?)'
                );
                $stmt->execute([$uid, $opportunityId]);

                $_SESSION['detail_message'] =
                    'Added to your favorites.';
            }
             recommender_reload(); 
        }

        /* Apply for an opportunity */
        elseif ($action === 'apply') {
            $status = $opp['Status'] ?? '';
            $deadline = $opp['ApplicationDeadLine'] ?? null;
            $openDate = $opp['ApplicationOpenDate'] ?? null;

            if (trim((string) ($opp['ApplicationMethod'] ?? '')) !== 'Apply Through Platform') {
        $_SESSION['detail_message'] =
            'This opportunity does not accept applications through Ufuq.';
            } elseif (!detail_is_open($status, $deadline, $openDate)) {   // كان: if (...)
                    $_SESSION['detail_message'] =
                        'This opportunity is not currently open for applications.';
                } else {
                $check = $pdo->prepare(
                    'SELECT 1
                     FROM application
                     WHERE UserID = ? AND OpportunityID = ?
                     LIMIT 1'
                );
                $check->execute([$uid, $opportunityId]);

                if ($check->fetchColumn()) {
                    $_SESSION['detail_message'] =
                        'You have already applied for this opportunity.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO application
                         (UserID, OpportunityID, SubmissionDate, ApplicationStatus)
                         VALUES (?, ?, NOW(), 'Pending')"
                    );

                    $stmt->execute([$uid, $opportunityId]);

                    $_SESSION['detail_message'] =
                        'Your application has been submitted successfully.';
                }
            }
        }

        /* Add, update, or delete the current user's review */
        elseif (in_array($action, ['submit_review', 'update_review', 'delete_review'], true)) {

            // Delete is handled separately and is restricted to the signed-in user.
            if ($action === 'delete_review') {
                $deleteStmt = $pdo->prepare(
                    'DELETE FROM review
                     WHERE UserID = ? AND OpportunityID = ?'
                );
                $deleteStmt->execute([$uid, $opportunityId]);

                $_SESSION['detail_message'] = $deleteStmt->rowCount() > 0
                    ? 'Your review has been deleted successfully.'
                    : 'No review was found to delete.';

            } else {

    // Allow adding or editing reviews only for Closed opportunities.
    if (!$isClosed) {
        $_SESSION['detail_message'] =
            'You can review this opportunity only after it is closed.';

    } else {

        $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
                $reviewText = trim((string) ($_POST['review_text'] ?? ''));
                $declared = ($_POST['completion_declaration'] ?? '') === '1';

                if (
                    $rating === false
                    || $rating === null
                    || $rating < 1
                    || $rating > 5
                    || $reviewText === ''
                ) {
                    $_SESSION['detail_message'] =
                        'Please complete all review fields: select a star rating and write your review.';

                } elseif (!$declared) {
                    $_SESSION['detail_message'] =
                        'Please confirm that you have completed this training opportunity or course.';

                } elseif (mb_strlen($reviewText, 'UTF-8') > 500) {
                    $_SESSION['detail_message'] =
                        'Your review must not exceed 500 characters.';

                } else {
                    $check = $pdo->prepare(
                        'SELECT 1
                         FROM review
                         WHERE UserID = ? AND OpportunityID = ?
                         LIMIT 1'
                    );
                    $check->execute([$uid, $opportunityId]);
                    $reviewExists = (bool) $check->fetchColumn();

                    if ($action === 'update_review') {
                        if (!$reviewExists) {
                            $_SESSION['detail_message'] =
                                'Review not found. Please refresh the page and try again.';
                        } else {
                            $updateStmt = $pdo->prepare(
                                'UPDATE review
                                 SET Rating = ?, ReviewText = ?
                                 WHERE UserID = ? AND OpportunityID = ?'
                            );
                            $updateStmt->execute([
                                $rating,
                                $reviewText,
                                $uid,
                                $opportunityId
                            ]);

                            $_SESSION['detail_message'] =
                                'Your review has been updated successfully.';
                        }

                    } elseif ($reviewExists) {
                        $_SESSION['detail_message'] =
                            'You have already reviewed this opportunity.';

                    } else {
                        $insertStmt = $pdo->prepare(
                            'INSERT INTO review
                             (Rating, ReviewText, UserID, OpportunityID)
                             VALUES (?, ?, ?, ?)'
                        );
                        $insertStmt->execute([
                            $rating,
                            $reviewText,
                            $uid,
                            $opportunityId
                        ]);

                        $verifyCheck = $pdo->prepare(
                            "SELECT 1 FROM application
                             WHERE UserID = ?
                               AND OpportunityID = ?
                               AND ApplicationStatus = 'Accepted'
                             LIMIT 1"
                        );
                        $verifyCheck->execute([$uid, $opportunityId]);

                        $hasStages = !empty($opp['ScreeningDate'])
                            || !empty($opp['InterviewDate']);

                        $_SESSION['detail_message'] =
                            ($verifyCheck->fetchColumn() && !$hasStages)
                            ? 'Your review has been published with a Verified Participant badge.'
                            : 'Your review has been published.';
                    }
                }
    }}
        }
    }

    /* Redirect after POST */
    header('Location: opportunity-detail.php?id=' . $opportunityId);
    exit;
}

/* ---------- Favorite status ---------- */
$favStmt = $pdo->prepare(
    'SELECT 1
     FROM favorite
     WHERE UserID = ? AND OpportunityID = ?
     LIMIT 1'
);
$favStmt->execute([$uid, $opportunityId]);
$isFav = (bool) $favStmt->fetchColumn();

/* ---------- Application status ---------- */
$appStmt = $pdo->prepare(
    'SELECT ApplicationStatus
     FROM application
     WHERE UserID = ? AND OpportunityID = ?
     LIMIT 1'
);
$appStmt->execute([$uid, $opportunityId]);
$applicationStatus = $appStmt->fetchColumn();
$applied = ($applicationStatus !== false);

/* ---------- Opportunity skills ---------- */
$skills = [];

if ($opp) {
    try {
        $stmt = $pdo->prepare(
            'SELECT s.SkillName
             FROM opportunityskill os
             INNER JOIN skill s ON s.SkillID = os.SkillID
             WHERE os.OpportunityID = ?
             ORDER BY s.SkillName'
        );

        $stmt->execute([$opportunityId]);
        $skills = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $skills = [];
    }
}

/* ---------- Opportunity qualifications ---------- */
$qualifications = [];

if ($opp) {
    try {
        $stmt = $pdo->prepare(
            'SELECT q.QualificationName
             FROM opportunityqualification oq
             INNER JOIN qualification q
                 ON q.QualificationID = oq.QualificationID
             WHERE oq.OpportunityID = ?
             ORDER BY q.QualificationName'
        );

        $stmt->execute([$opportunityId]);
        $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $qualifications = [];
    }
}

/* ---------- Opportunity experience ---------- */
$experiences = [];

if ($opp) {
    try {
        $stmt = $pdo->prepare(
            'SELECT e.ExperienceName
             FROM opportunityexperience oe
             INNER JOIN experience e
                 ON e.ExperienceID = oe.ExperienceID
             WHERE oe.OpportunityID = ?
             ORDER BY e.ExperienceName'
        );

        $stmt->execute([$opportunityId]);
        $experiences = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $experiences = [];
    }
}

/* ---------- Reviews ---------- */
$reviews = [];
$averageRating = null;
$myReview = false;
$myReviewData = null;
if ($opp) {
    $reviewStmt = $pdo->prepare(
        "SELECT r.Rating, r.ReviewText, r.CreatedAt,
                u.FirstName, u.LastName, r.UserID,
                EXISTS (SELECT 1 FROM application a
                        WHERE a.UserID = r.UserID
                          AND a.OpportunityID = r.OpportunityID
                          AND a.ApplicationStatus = 'Accepted') AS IsAccepted
         FROM review r
         INNER JOIN `user` u ON u.UserID = r.UserID
         WHERE r.OpportunityID = ?
         ORDER BY r.CreatedAt DESC"
    );

    $reviewStmt->execute([$opportunityId]);
    $reviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($reviews) {
        $averageRating = array_sum(
            array_map(
                static fn($review) => (int) $review['Rating'],
                $reviews
            )
        ) / count($reviews);
    }

    foreach ($reviews as $review) {
    if ((int) $review['UserID'] === $uid) {
        $myReview = true;
        $myReviewData = $review;
        break;
    }
}
}

/* ---------- Opportunity timeline (columns of `opportunity`) ----------
   Only the stages that have a date are shown. When at least one stage exists,
   the start and end of the opportunity are added to complete the line. */
$timelineSteps = [];

if ($opp) {
    $timelineColumns = [
        'ApplicationOpenDate' => 'Application Opens',
        'ApplicationDeadLine' => 'Application Closes',
        'ScreeningDate'       => 'Initial Screening',
        'InterviewDate'       => 'Interview',
        'AcceptanceDate'      => 'Acceptance',
    ];

    foreach ($timelineColumns as $column => $stageName) {
        $stageDate = (string) ($opp[$column] ?? '');

        if ($stageDate !== '' && $stageDate !== '0000-00-00') {
            $timelineSteps[] = ['TimelineName' => $stageName, 'TimelineDate' => $stageDate];
        }
    }

    if ($timelineSteps) {
        if (!empty($opp['StartDate'])) {
            $timelineSteps[] = ['TimelineName' => 'Starts', 'TimelineDate' => (string) $opp['StartDate']];
        }

        if (!empty($opp['EndDate'])) {
            $timelineSteps[] = ['TimelineName' => 'Ends', 'TimelineDate' => (string) $opp['EndDate']];
        }

        /* Stable sort keeps the stage order when two stages share a date. */
        usort($timelineSteps, static fn($a, $b) => strcmp($a['TimelineDate'], $b['TimelineDate']));
    }
}

/* ---------- Company / provider information ---------- */
$provider = $opp['OpportunityProvider'] ?? 'Company';
$company = null;

if ($opp && !empty($opp['CreatedByCompanyID'])) {
    try {
        $stmt = $pdo->prepare(
            'SELECT CompanyName, WebsiteURL, Description, Location, Status
             FROM company
             WHERE CompanyID = ?
             LIMIT 1'
        );

        $stmt->execute([(int) $opp['CreatedByCompanyID']]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($company && !empty($company['CompanyName'])) {
            $provider = $company['CompanyName'];
        }
    } catch (Throwable $e) {
        $company = null;
    }
}

/* ---------- Values for display ---------- */
$title = $opp['Title'] ?? 'Untitled Opportunity';
$description = $opp['Description'] ?? '';
$type = $opp['Type'] ?? 'Opportunity';
$location = $opp['Location'] ?? 'Not specified';
$status = $opp['Status'] ?? 'Not specified';
$method = $opp['ApplicationMethod'] ?? '';
$externalURL = $opp['ExternalURL'] ?? '';
$deadline = $opp['ApplicationDeadLine'] ?? null;
$openDate = $opp['ApplicationOpenDate'] ?? null;
$startDate = $opp['StartDate'] ?? null;
$endDate = $opp['EndDate'] ?? null;
/* Duration is a generated column (days between StartDate and EndDate). */
$durationDays = isset($opp['Duration']) && $opp['Duration'] !== null ? (int) $opp['Duration'] : null;

/* Optional weekly schedule (ScheduleDays is a SET such as "Sun,Mon,Tue"). */
$scheduleDays = array_values(array_filter(explode(',', (string) ($opp['ScheduleDays'] ?? ''))));
$scheduleStart = (string) ($opp['ScheduleStartTime'] ?? '');
$scheduleEnd = (string) ($opp['ScheduleEndTime'] ?? '');
$scheduleTime = ($scheduleStart !== '' && $scheduleEnd !== '')
    ? date('g:i A', strtotime($scheduleStart)) . ' – ' . date('g:i A', strtotime($scheduleEnd))
    : '';

/* Selection stages are the Initial Screening / Interview steps of the timeline. */
$hasSelectionDates = !empty($opp['ScreeningDate']) || !empty($opp['InterviewDate']);
$isPlatformApply = trim((string) ($opp['ApplicationMethod'] ?? '')) === 'Apply Through Platform';
$priceStatus = $opp['PriceStatus'] ?? 'Unknown';
$retail = $opp['Retail'] ?? null;
$industrySector = $opp['IndustrySector'] ?? 'Not specified';

$isOpen = detail_is_open($status, $deadline, $openDate);
$notOpenYet = strcasecmp(trim((string) $status), 'Closed') !== 0
    && !(!empty($deadline) && $deadline !== '0000-00-00' && (string) $deadline < date('Y-m-d'))
    && detail_not_open_yet($openDate);
/* Shown status (same as Browse): Closed when closed by the provider or the deadline has passed,
   Upcoming when applications have not opened yet, otherwise Open. */
$displayStatus = $isOpen ? 'Open' : ($notOpenYet ? 'Upcoming' : 'Closed');
$isClosed = $displayStatus === 'Closed';

/* "Verified Participant": an accepted application through Ufuq and no extra selection stages
   (Initial Screening / Interview). External-link opportunities have no application, so no badge. */
$canVerifyReviews = !$hasSelectionDates;

$message = (string) ($_SESSION['detail_message'] ?? '');
unset($_SESSION['detail_message']);

$successMessages = [
    'Your review has been submitted.',
    'Your review has been published.',
    'Your review has been published with a Verified Participant badge.',
    'Your application has been submitted successfully.',
    'Added to your favorites.',
    'Your review has been updated successfully.',
    'Your review has been deleted successfully.',
    'Removed from your favorites.'
];

$isSuccessMessage = in_array($message, $successMessages, true);

$userName = trim(
    ($me['FirstName'] ?? '') . ' ' . ($me['LastName'] ?? '')
);

/* ---------- Page header ---------- */
$extraCss = '../css/opportunity-detail.css';
user_header($me, 'Opportunity Details', 'browse');
?>


<!-- Page banner (same banner as Home / Browse) -->
<section class="detail-hero">
    <div class="container detail-hero-inner">
        <div>
            <div class="detail-hero-label">
                <span aria-hidden="true">✦</span>
                <span>Ufuq Learning Space</span>
            </div>

            <h1>Opportunity Details</h1>

            <p>
                Review the opportunity information and apply when you are ready.
            </p>
        </div>

        <div class="detail-hero-icon" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4.5" width="14" height="16.5" rx="2"/><path d="M9 3h6v3H9z"/><path d="M9 11h6M9 14.5h6M9 18h4"/></svg></div>
    </div>
</section>

<div class="detail-page">
    <div class="detail-container">

                <a href="<?= htmlspecialchars((string) ($_SESSION['detail_back'] ?? 'browse.php'), ENT_QUOTES, 'UTF-8') ?>" class="detail-back">
            <span aria-hidden="true">&larr;</span>
            Back
        </a>

        <?php if (!$opp): ?>

            <section class="card not-found-card">
                <h2>Opportunity Not Found</h2>

                <p>
                    This opportunity could not be found in the database.
                    It may have been removed, or the link may be incorrect.
                </p>

                <a href="browse.php" class="btn btn-primary">
                    Back to Browse
                </a>
            </section>

        <?php else: ?>

            <?php if ($message !== '' && $isSuccessMessage): ?>
                <div class="ufuq-toast" id="successToast" role="status" aria-live="polite">
                    <span class="ufuq-toast-icon" aria-hidden="true">✓</span>
                    <span><?= detail_h($message) ?></span>
                    <button type="button" class="ufuq-toast-close" aria-label="Close">&times;</button>
                </div>
            <?php elseif ($message !== ''): ?>
                <div
                    id="statusToast"
                    class="status-toast <?= $isSuccessMessage ? 'success' : 'error' ?>"
                    role="status"
                    aria-live="polite"
                >
                    <span class="toast-icon" aria-hidden="true">
                        <?= $isSuccessMessage ? '✓' : '!' ?>
                    </span>

                    <span><?= detail_h($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Opportunity Details -->
            <section class="card">

                <div class="opportunity-heading">

                    <div class="opportunity-heading-main">

                        <h2><?= detail_h($title) ?></h2>

                        <div class="opportunity-provider-line">
                            <span><?= detail_h($provider) ?></span>
                            <span class="separator">&bull;</span>
                            <span><?= detail_h($location) ?></span>
                        </div>

                    </div>

                    <form
                        method="POST"
                        action="opportunity-detail.php?id=<?= (int) $opportunityId ?>"
                        class="favorite-action-form"
                    >
                        <input type="hidden" name="action" value="toggle_favorite">

                        <input
    type="hidden"
    name="action"
    value="<?= $myReviewData ? 'update_review' : 'submit_review' ?>"
>

                        <button type="submit" class="btn btn-ghost">
                            <span class="favorite-heart" aria-hidden="true">
                                <?= $isFav ? '&#9829;' : '&#9825;' ?>
                            </span>

                            <?= $isFav ? 'Saved to Favorites' : 'Add to Favorites' ?>
                        </button>
                    </form>

                </div>

                <div class="divider"></div>

                <section>
                    <h3>About This Opportunity</h3>

                    <p class="detail-description"><?= detail_h(
                        $description ?: 'No description provided.'
                    ) ?></p>

                    <!-- Type and status -->
                    <div class="heading-badges description-badges">
                        <span class="badge badge-type">
                            <?= detail_h($type) ?>
                        </span>

                        <span class="badge status-badge <?= $isOpen ? 'is-open' : ($isClosed ? 'is-closed' : 'is-upcoming') ?>">
                            <?= detail_h($displayStatus) ?>
                        </span>
                    </div>
                </section>

                <div class="divider"></div>

                <!-- Opportunity Information -->
                <div class="grid grid-3">

                    <div class="field-view">
                        <div class="field-label">Start Date</div>
                        <?= detail_h(detail_date($startDate)) ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">End Date</div>
                        <?= detail_h(detail_date($endDate)) ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Duration</div>
                        <?= $durationDays !== null && $durationDays >= 0
                            ? detail_h(number_format($durationDays) . ' ' . ($durationDays === 1 ? 'day' : 'days'))
                            : 'Not specified' ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Application Deadline</div>
                        <?= detail_h(detail_date($deadline)) ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Price</div>

                        <?php if ($priceStatus === 'Free'): ?>
                            Free
                        <?php elseif ($priceStatus === 'Paid' && $retail !== null && $retail !== '' && (float) $retail > 0): ?>
                            <?= detail_h(number_format((float) $retail, 2)) ?> SAR
                        <?php elseif ($priceStatus === 'Paid'): ?>
                            <?php /* Paid with price 0 = paid, but the price is not known yet. */ ?>
                            Paid (price not specified)
                        <?php else: ?>
                            Price not specified
                        <?php endif; ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Industry Sector</div>
                        <?= detail_h($industrySector) ?>
                    </div>

                </div>

                <!-- Timeline -->
                <?php if ($timelineSteps): ?>
                    <?php
                    $tlToday = date('Y-m-d');
                    $tlNext = null;

                    foreach ($timelineSteps as $tlIndex => $tlStep) {
                        if ((string) $tlStep['TimelineDate'] > $tlToday) {
                            $tlNext = $tlIndex;
                            break;
                        }
                    }
                    ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Timeline</h3>

                        <div class="tl-scroll">
                            <ol class="tl-track">
                                <?php foreach ($timelineSteps as $tlIndex => $tlStep):
                                    $tlDone = (string) $tlStep['TimelineDate'] <= $tlToday;
                                    $tlNextDone = isset($timelineSteps[$tlIndex + 1])
                                        && (string) $timelineSteps[$tlIndex + 1]['TimelineDate'] <= $tlToday;
                                    $tlClass = 'tl-step'
                                        . ($tlDone ? ' is-done' : '')
                                        . ($tlIndex === $tlNext ? ' is-next' : '')
                                        . ($tlNextDone ? ' next-done' : '');
                                ?>
                                    <li class="<?= detail_h($tlClass) ?>">
                                        <span class="tl-dot" aria-hidden="true"></span>

                                        <div class="tl-info">
                                            <div class="tl-name"><?= detail_h($tlStep['TimelineName']) ?></div>
                                            <div class="tl-date"><?= detail_h(detail_date($tlStep['TimelineDate'])) ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </div>

                        <?php /* Selection stages are the Initial Screening / Interview steps above.
                                 Platform opportunities without them say so under the timeline. */ ?>
                        <?php if ($isPlatformApply && !$hasSelectionDates): ?>
                            <div class="tl-note">
                                <p>No additional selection stages are required.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Schedule (only when the provider set one; otherwise the start date, end date and duration above apply) -->
                <?php if ($scheduleDays && $scheduleTime !== ''): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Schedule</h3>
                        <p class="schedule-time"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><?= detail_h($scheduleTime) ?></span></p>

                        <div class="schedule-days">
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayKey): ?>
                                <span class="schedule-day <?= in_array($dayKey, $scheduleDays, true) ? 'is-on' : '' ?>"><?= detail_h($dayKey) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Qualifications -->
                <?php if ($qualifications): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Qualifications</h3>

                        <ul class="detail-list">
                            <?php foreach ($qualifications as $q): ?>
                                <li><?= detail_h($q['QualificationName']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <!-- Experience -->
                <?php if ($experiences): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Experience Needed</h3>

                        <ul class="detail-list">
                            <?php foreach ($experiences as $experience): ?>
                                <li><?= detail_h($experience['ExperienceName']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <!-- Required Skills -->
                <?php if ($skills): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Required Skills</h3>

                        <div class="skill-tags">
                            <?php foreach ($skills as $skill): ?>
                                <span class="badge badge-primary skill-tag">
                                    <?= detail_h($skill) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Company Information -->
                <?php if ($company && !empty($company['Description'])): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>About the Company</h3>

                        <div class="company-description">
                            <p><?= detail_h($company['Description']) ?></p>
                        </div>

                        <?php if (!empty($company['WebsiteURL'])): ?>
                            <?php
                            $websiteURL = trim((string) $company['WebsiteURL']);
                            $websiteParts = parse_url($websiteURL);
                            $websiteScheme = strtolower(
                                (string) ($websiteParts['scheme'] ?? '')
                            );
                            $websiteHost = (string) ($websiteParts['host'] ?? '');

                            $validWebsite = (
                                filter_var($websiteURL, FILTER_VALIDATE_URL)
                                && in_array($websiteScheme, ['http', 'https'], true)
                                && $websiteHost !== ''
                            );
                            ?>

                            <?php if ($validWebsite): ?>
                                <p style="margin:14px 0 0;">
                                    <a
                                        href="<?= detail_h($websiteURL) ?>"
                                        class="detail-back"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Visit Company Website
                                        <span aria-hidden="true">&#8599;</span>
                                    </a>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <!-- Application -->
                <div class="divider"></div>

                <section id="applyArea">
                    <h3>Apply for This Opportunity</h3>

                    <div class="apply-area">

                        <?php if ($notOpenYet): ?>

                            <button type="button" class="btn btn-outline" disabled>
                                Opens on <?= detail_h(detail_date($openDate)) ?>
                            </button>

                            <p class="apply-note">
                                Applications for this opportunity have not opened yet.
                            </p>

                        <?php elseif (!$isOpen): ?>

                            <button type="button" class="btn btn-outline" disabled>
                                Applications Closed
                            </button>

                            <p class="apply-note">
                                This opportunity is currently unavailable for applications.
                            </p>

                        <?php elseif ($applied): ?>

                            <button type="button" class="btn btn-outline" disabled>
                                Already Applied
                                (<?= detail_h($applicationStatus) ?>)
                            </button>

                            <p class="apply-note">
                                Your application has already been recorded.
                            </p>

                        <?php elseif (
                            trim((string) $method) !== 'Apply Through Platform'
                            && !empty($externalURL)
                        ): ?>

                            <?php
                            $externalParts = parse_url((string) $externalURL);
                            $externalScheme = strtolower(
                                (string) ($externalParts['scheme'] ?? '')
                            );
                            $externalHost = (string) ($externalParts['host'] ?? '');

                            $validExternalURL = (
                                filter_var(
                                    (string) $externalURL,
                                    FILTER_VALIDATE_URL
                                )
                                && in_array(
                                    $externalScheme,
                                    ['http', 'https'],
                                    true
                                )
                                && $externalHost !== ''
                            );
                            ?>

                            <?php if ($validExternalURL): ?>
                                <a
                                    href="<?= detail_h($externalURL) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn btn-primary"
                                >
                                    Apply on Company Website
                                    <span aria-hidden="true">&#8599;</span>
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline" disabled>
                                    Application Link Unavailable
                                </button>
                            <?php endif; ?>

                        <?php else: ?>

                            <form
                                method="POST"
                                action="opportunity-detail.php?id=<?= (int) $opportunityId ?>"
                                onsubmit="return confirm('Do you want to apply for this opportunity? Your application will use the qualifications, experience and skills in your profile.');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= detail_h($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="apply"
                                >

                                <button type="submit" class="btn btn-primary">
                                    Apply Now
                                    <span aria-hidden="true">&#8594;</span>
                                </button>
                            </form>

                            <p class="apply-note">
                                Your application uses the qualifications, experience and skills in your profile.
                                Update your profile first if anything is missing.
                            </p>

                        <?php endif; ?>

                    </div>
                </section>

            </section>

            <!-- Reviews -->
            <section class="card reviews-card" id="companyReviewsCard">

                <div class="reviews-header">
                    <h3>Opportunity Reviews</h3>

                    <div class="average-rating">
                        <span class="average-rating-star" aria-hidden="true">★</span>
                        <?php if ($averageRating !== null): ?>
                            <span><?= number_format($averageRating, 1) ?> / 5</span>
                            <span class="text-muted">
                                (<?= count($reviews) ?>
                                <?= count($reviews) === 1 ? 'review' : 'reviews' ?>)
                            </span>
                        <?php else: ?>
                            <span>No ratings yet</span>
                        <?php endif; ?>
                    </div>
                </div>
<?php if ($isClosed): ?>

                <!-- Add / Edit Review Form -->
                <div class="review-form-panel">
                    <div class="review-form-heading">
                        <div>
                            <h4><?= $myReviewData ? 'Edit Your Review' : 'Share Your Experience' ?></h4>
                            <p>
                                <?= $myReviewData
                                    ? 'Update your rating or change what you wrote.'
                                    : 'Your feedback helps others make informed decisions.' ?>
                            </p>
                        </div>
                        <?php if ($myReviewData): ?>
                            <span class="review-status-badge">Your review</span>
                        <?php endif; ?>
                    </div>

                    <form
                        method="POST"
                        action="opportunity-detail.php?id=<?= (int) $opportunityId ?>#companyReviewsCard"
                        id="reviewForm"
                        novalidate
                    >
                        <input type="hidden" name="csrf_token"
                               value="<?= detail_h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action"
                               value="<?= $myReviewData ? 'update_review' : 'submit_review' ?>">
                        <input type="hidden" name="rating" id="rating"
                               value="<?= $myReviewData ? (int) $myReviewData['Rating'] : 0 ?>">

                        <div class="form-group">
                            <label>
                                Your Rating <span class="required-mark">*</span>
                            </label>

                            <div class="rating-control">
                                <div
                                    id="starRating"
                                    class="rating-star-row"
                                    role="radiogroup"
                                    aria-label="Choose a rating from 1 to 5"
                                >
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <button
                                            type="button"
                                            class="rating-star"
                                            data-rating="<?= $i ?>"
                                            role="radio"
                                            aria-checked="<?= ($myReviewData && (int)$myReviewData['Rating'] === $i) ? 'true' : 'false' ?>"
                                            aria-label="<?= $i ?> stars"
                                        >★</button>
                                    <?php endfor; ?>

                                    <span id="ratingText" class="rating-text">
                                        <?= $myReviewData
                                            ? (int)$myReviewData['Rating'] . ' out of 5'
                                            : 'Select a rating' ?>
                                    </span>
                                </div>

                                <span id="ratingError" class="rating-error"
                                      role="alert" aria-live="polite">
                                    Please select a rating from 1 to 5.
                                </span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="review_text">
                                Share Your Experience <span class="required-mark">*</span>
                            </label>
                            <textarea
                                class="form-control"
                                name="review_text"
                                id="review_text"
                                rows="4"
                                maxlength="500"
                                placeholder="What did you think about this opportunity?"
                                required
                            ><?= detail_h($myReviewData['ReviewText'] ?? '') ?></textarea>

                            <span id="reviewTextError" class="field-error" role="alert">
                                Please write your review.
                            </span>
                            <div class="review-character-note">
                                Maximum 500 characters.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="declaration-check">
                                <input
                                    type="checkbox"
                                    name="completion_declaration"
                                    id="completionDeclaration"
                                    value="1"
                                    required
                                    <?= $myReviewData ? 'checked' : '' ?>
                                >
                                <span>
                                    I confirm that I have completed this training opportunity or course.
                                    <span class="required-mark">*</span>
                                </span>
                            </label>
                            <span id="declarationError" class="field-error" role="alert">
                                Please confirm that you have completed this opportunity.
                            </span>
                        </div>

                        <div class="review-form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?= $myReviewData ? 'Save Changes' : 'Submit Review' ?>
                                <span aria-hidden="true">&#8594;</span>
                            </button>
                        </div>
                    </form>

                    <?php if ($myReviewData): ?>
                        <button
                            type="button"
                            class="btn btn-delete-review"
                            onclick="openConfirmModal('deleteReviewModal')"
                        >
                            Delete My Review
                            <?= icon('delete', 18, 'icon-danger') ?>
                        </button>
                    <?php endif; ?>
                </div>
<?php else: ?>

    <div class="review-form-panel">
        <p class="review-empty">
            Reviews and ratings are available only after this opportunity is closed.
        </p>
    </div>

<?php endif; ?>
                <div class="divider"></div>

                <!-- Review List -->
                <div id="reviewList" class="review-list">
                    <?php if (!$reviews): ?>
                        <p class="review-empty">
                            No reviews yet. Be the first to share your experience.
                        </p>
                    <?php else: ?>
                        <?php foreach ($reviews as $review): ?>
                            <?php
                            $reviewName = trim(
                                ($review['FirstName'] ?? '') . ' ' .
                                ($review['LastName'] ?? '')
                            );
                            $rating = max(0, min(5, (int) $review['Rating']));
                            $isMyReview = (int) $review['UserID'] === $uid;
                            ?>
                            <article class="review-item">
                                <div class="review-item-header">
                                    <div class="review-author-group">
                                        <strong class="review-author">
                                            <?= detail_h($reviewName ?: 'A user') ?>
                                        </strong>
                                        <?php if ($isMyReview): ?>
                                            <span class="my-review-badge">Your review</span>
                                        <?php endif; ?>
                                        <?php if ($canVerifyReviews && (int)($review['IsAccepted'] ?? 0) === 1): ?>
                                            <span class="verified-badge" title="Accepted through Ufuq">
                                                <span aria-hidden="true">✓</span>
                                                Verified Participant
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <span class="review-stars"
                                          aria-label="<?= $rating ?> out of 5 stars">
                                        <span class="filled"><?= str_repeat('★', $rating) ?></span><span class="empty"><?= str_repeat('☆', 5 - $rating) ?></span>
                                    </span>
                                </div>

                                <?php if (!empty($review['ReviewText'])): ?>
                                    <p class="review-text"><?= detail_h($review['ReviewText']) ?></p>
                                <?php endif; ?>

                                <div class="review-date">
                                    <?= detail_h(detail_date($review['CreatedAt'] ?? null)) ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

    </div>
</div>


<?php
/* Shared confirmation modal for deleting the signed-in user's review. */
confirm_modal([
    'id'            => 'deleteReviewModal',
    'title'         => 'Delete your review?',
    'message'       => 'Are you sure you want to delete your review? This action cannot be undone.',
    'confirm_label' => 'Delete Review',
    'icon'          => 'trash',
    'variant'       => 'danger',
    'fields'        => ['action' => 'delete_review'],
]);
?>


<script>
/* ---------- Interactive Rating ---------- */
(function () {
    const starContainer = document.getElementById('starRating');

    if (!starContainer) {
        return;
    }

    const stars = Array.from(
        starContainer.querySelectorAll('.rating-star')
    );

    const ratingInput = document.getElementById('rating');
    const ratingText = document.getElementById('ratingText');
    const ratingError = document.getElementById('ratingError');
    const reviewForm = document.getElementById('reviewForm');

    let selectedRating = Number(ratingInput.value) || 0;
    let errorTimer = null;

    function hideRatingError() {
        if (!ratingError) {
            return;
        }

        ratingError.classList.remove('is-visible');

        if (errorTimer) {
            window.clearTimeout(errorTimer);
        }

        errorTimer = window.setTimeout(function () {
            if (!ratingError.classList.contains('is-visible')) {
                ratingError.style.display = 'none';
            }
        }, 220);
    }

    function showRatingError() {
        if (!ratingError) {
            return;
        }

        if (errorTimer) {
            window.clearTimeout(errorTimer);
        }

        ratingError.style.display = 'block';

        requestAnimationFrame(function () {
            ratingError.classList.add('is-visible');
        });
    }

    function updateStars(rating) {
        stars.forEach(function (star) {
            const value = Number(star.dataset.rating);
            const active = value <= rating && rating > 0;

            star.style.color = active ? '#e9ac10' : '#d4d0dc';

            star.setAttribute(
                'aria-checked',
                value === rating && rating > 0 ? 'true' : 'false'
            );
        });

        if (ratingText) {
            ratingText.textContent = rating > 0
                ? rating + ' out of 5'
                : 'Select a rating';
        }

        if (rating > 0) {
            hideRatingError();
        }
    }

    stars.forEach(function (star, index) {
        star.addEventListener('click', function () {
            selectedRating = Number(this.dataset.rating);
            ratingInput.value = String(selectedRating);
            updateStars(selectedRating);
        });

        star.addEventListener('mouseenter', function () {
            updateStars(Number(this.dataset.rating));
        });

        star.addEventListener('focus', function () {
            updateStars(Number(this.dataset.rating));
        });

        star.addEventListener('keydown', function (event) {
            let nextIndex = index;

            if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                nextIndex = Math.min(stars.length - 1, index + 1);
            } else if (
                event.key === 'ArrowLeft'
                || event.key === 'ArrowDown'
            ) {
                nextIndex = Math.max(0, index - 1);
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = stars.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            stars[nextIndex].focus();
            stars[nextIndex].click();
        });
    });

    starContainer.addEventListener('mouseleave', function () {
        updateStars(selectedRating);
    });

    const reviewText = document.getElementById('review_text');
    const reviewTextError = document.getElementById('reviewTextError');
    const declaration = document.getElementById('completionDeclaration');
    const declarationError = document.getElementById('declarationError');

    reviewForm.addEventListener('submit', function (event) {
        const rating = Number(ratingInput.value);
        const textMissing = reviewText.value.trim() === '';
        const notDeclared = !declaration.checked;

        reviewTextError.classList.toggle('is-visible', textMissing);
        declarationError.classList.toggle('is-visible', notDeclared);

        if (textMissing || notDeclared) {
            event.preventDefault();
        }

        if (rating >= 1 && rating <= 5) {
            if (textMissing) {
                reviewText.focus();
            } else if (notDeclared) {
                declaration.focus();
            }
        }

        if (rating < 1 || rating > 5) {
            event.preventDefault();
            showRatingError();

            starContainer.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            stars[0].focus({ preventScroll: true });
        }
    });

    reviewText.addEventListener('input', function () {
        if (reviewText.value.trim() !== '') reviewTextError.classList.remove('is-visible');
    });
    declaration.addEventListener('change', function () {
        if (declaration.checked) declarationError.classList.remove('is-visible');
    });

    updateStars(selectedRating);
})();

/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('successToast');
    if (!toast) return;

    /* Move it to <body> so the page animations do not affect its fixed position. */
    document.body.appendChild(toast);

    function hide() {
        toast.classList.remove('is-shown');
        setTimeout(function () { toast.remove(); }, 400);
    }

    requestAnimationFrame(function () { toast.classList.add('is-shown'); });
    const timer = setTimeout(hide, 3500);
    toast.querySelector('.ufuq-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();

/* ---------- Auto-hide Status Message (errors) ---------- */
(function () {
    const toast = document.getElementById('statusToast');

    if (!toast) {
        return;
    }

    window.setTimeout(function () {
        toast.classList.add('toast-hidden');

        window.setTimeout(function () {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 350);
    }, 4000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>