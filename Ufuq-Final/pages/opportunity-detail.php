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
    die('Database connection not found. Check bootstrap.php.');
}

require_login('User');

$uid = (int) ($_SESSION['user_id'] ?? 0);

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
    if (strcasecmp(trim((string) $status), 'Open') !== 0) {
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
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
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
        }

        /* Apply for an opportunity */
        elseif ($action === 'apply') {
            $status = $opp['Status'] ?? '';
            $deadline = $opp['ApplicationDeadLine'] ?? null;
            $openDate = $opp['ApplicationOpenDate'] ?? null;

            if (!detail_is_open($status, $deadline, $openDate)) {
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

        /* Submit a review */
        elseif ($action === 'submit_review') {
            $rating = filter_input(
                INPUT_POST,
                'rating',
                FILTER_VALIDATE_INT
            );

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

                if ($check->fetchColumn()) {
                    $_SESSION['detail_message'] =
                        'You have already reviewed this opportunity.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO review
                         (Rating, ReviewText, UserID, OpportunityID)
                         VALUES (?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $rating,
                        $reviewText,
                        $uid,
                        $opportunityId
                    ]);

                    $verifyCheck = $pdo->prepare(
                        "SELECT 1 FROM application
                         WHERE UserID = ? AND OpportunityID = ? AND ApplicationStatus = 'Accepted'
                         LIMIT 1"
                    );
                    $verifyCheck->execute([$uid, $opportunityId]);
                    $hasStages = !empty($opp['ScreeningDate']) || !empty($opp['InterviewDate']);

                    $_SESSION['detail_message'] = ($verifyCheck->fetchColumn() && !$hasStages)
                        ? 'Your review has been published with a Verified Participant badge.'
                        : 'Your review has been published.';
                }
            }
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
$notOpenYet = strcasecmp(trim((string) $status), 'Open') === 0 && detail_not_open_yet($openDate);
/* Shown status: Closed when the company closed it or the application deadline has passed. */
$displayStatus = $isOpen ? 'Open' : ($notOpenYet ? 'Not Open Yet' : 'Closed');
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
    'Removed from your favorites.'
];

$isSuccessMessage = in_array($message, $successMessages, true);

$userName = trim(
    ($me['FirstName'] ?? '') . ' ' . ($me['LastName'] ?? '')
);

/* ---------- Page header ---------- */
user_header($me, 'Opportunity Details', 'browse');
?>

    <style>
        :root {
            --detail-purple: #39265f;
            --detail-purple-dark: #281a46;
            --detail-purple-light: #f0ebf8;
            --detail-gold: #e2a94b;
            --detail-bg: #faf6ef;
            --detail-card: #ffffff;
            --detail-text: #29243a;
            --detail-muted: #777184;
            --detail-border: #e9e3ed;
            --detail-green: #25845c;
            --detail-red: #c74755;
        }

        /* ---------- Page ---------- */
        body {
            background: var(--detail-bg);
            color: var(--detail-text);
        }

        .detail-page {
            min-height: 60vh;
            padding: 0 0 60px;
            background: var(--detail-bg);
        }

        /* ---------- Banner ---------- */
        .detail-hero {
            position: relative;
            overflow: hidden;
            padding: 38px 0 42px;
            margin-bottom: 28px;
            color: #ffffff;
            background: linear-gradient(
                125deg,
                #281a46 0%,
                #39265f 55%,
                #49316f 100%
            );
        }

        .detail-hero::before,
        .detail-hero::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .detail-hero::before {
            width: 250px;
            height: 250px;
            right: 7%;
            top: -150px;
            border: 35px solid rgba(255, 255, 255, 0.07);
        }

        .detail-hero::after {
            width: 190px;
            height: 190px;
            right: 23%;
            bottom: -145px;
            background: rgba(226, 169, 75, 0.10);
        }

        .detail-hero-inner {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 22px;
        }

        .detail-hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            margin-bottom: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.08);
            font-size: 0.84rem;
            letter-spacing: 0.2px;
        }

        .detail-hero h1 {
            margin: 0;
            color: #ffffff;
            font-size: clamp(1.65rem, 3vw, 2.15rem);
            font-weight: 750;
            line-height: 1.5;
        }

        .detail-hero p {
            margin: 8px 0 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.96rem;
            line-height: 1.8;
        }

        .detail-hero-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 78px;
            height: 78px;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 28px rgba(15, 8, 30, 0.16);
            font-size: 2rem;
        }

        @media (max-width: 620px) {
            .detail-hero {
                padding: 30px 0;
            }

            .detail-hero-icon {
                width: 60px;
                height: 60px;
                border-radius: 18px;
                font-size: 1.6rem;
            }
        }

        .detail-container {
            width: min(1000px, calc(100% - 40px));
            margin: 0 auto;
        }

        .detail-page *,
        .detail-page *::before,
        .detail-page *::after {
            box-sizing: border-box;
        }

        /* ---------- Breadcrumb ---------- */
        .detail-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--detail-purple);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .detail-back:hover {
            color: var(--detail-purple-dark);
            text-decoration: underline;
        }

        /* ---------- General Cards ---------- */
        .detail-page .card {
            padding: 27px;
            background: var(--detail-card);
            border: 1px solid var(--detail-border);
            border-top: 3px solid var(--detail-gold);
            border-radius: 18px;
            box-shadow: 0 5px 22px rgba(40, 26, 70, 0.045);
        }

        .detail-page h2,
        .detail-page h3,
        .detail-page h4 {
            color: var(--detail-text);
        }

        .detail-page h2 {
            font-size: clamp(23px, 3vw, 30px);
            font-weight: 800;
            line-height: 1.4;
        }

        .detail-page h3 {
            margin-top: 0;
            margin-bottom: 13px;
            font-size: 17px;
            font-weight: 800;
        }

        .detail-page p {
            color: #5e596c;
            line-height: 1.85;
        }

        .detail-page .text-muted {
            color: var(--detail-muted);
        }

        .detail-page .divider {
            height: 1px;
            margin: 23px 0;
            background: var(--detail-border);
            border: 0;
        }

        /* ---------- Layout Utilities ---------- */
        .detail-page .flex-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .detail-page .grid {
            display: grid;
            gap: 14px;
        }

        .detail-page .grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        /* ---------- Badges ---------- */
        .detail-page .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            max-width: 100%;
            padding: 6px 11px;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 750;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .detail-page .badge-neutral {
            color: var(--detail-purple);
            background: var(--detail-purple-light);
            border-color: #e6dcf6;
        }

        .detail-page .badge-primary {
            color: var(--detail-purple);
            background: #f2edfa;
            border-color: #e4d9f3;
        }

        .detail-page .badge-success {
            color: #176b3a;
            background: #e8f7ed;
            border-color: #c5e9d0;
        }

        /* ---------- Buttons ---------- */
        .detail-page .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 17px;
            border: 1px solid transparent;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 750;
            line-height: 1.4;
            text-decoration: none;
            cursor: pointer;
            transition:
                background 0.2s ease,
                color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease;
        }

        .detail-page .btn:not(:disabled):hover {
            transform: translateY(-1px);
        }

        .detail-page .btn-primary {
            color: #ffffff;
            background: var(--detail-purple);
            border-color: var(--detail-purple);
        }

        .detail-page .btn-primary:hover {
            color: #ffffff;
            background: var(--detail-purple-dark);
            border-color: var(--detail-purple-dark);
        }

        .detail-page .btn-outline {
            color: var(--detail-purple);
            background: #ffffff;
            border-color: #d8cde9;
        }

        .detail-page .btn-outline:hover:not(:disabled) {
            background: var(--detail-purple-light);
        }

        .detail-page .btn-ghost {
            color: var(--detail-purple);
            background: #f7f3fc;
            border-color: #e8def5;
        }

        .detail-page .btn-ghost:hover {
            color: var(--detail-purple-dark);
            background: #eee6fa;
        }

        .detail-page .btn:disabled {
            color: #8b8696;
            background: #f0eef3;
            border-color: #e5e1ea;
            cursor: not-allowed;
            opacity: 0.9;
        }

        /* ---------- Opportunity Heading ---------- */
        .opportunity-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
        }

        .opportunity-heading-main {
            min-width: 0;
        }

        .opportunity-heading h2 {
            margin: 10px 0 5px;
            overflow-wrap: anywhere;
        }

        .opportunity-provider-line {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 7px;
            color: var(--detail-muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .opportunity-provider-line .separator {
            color: var(--detail-gold);
            font-weight: 900;
        }

        .favorite-action-form {
            flex-shrink: 0;
            margin: 0;
        }

        .favorite-action-form .btn {
            white-space: nowrap;
        }

        .favorite-heart {
            color: var(--detail-gold);
            font-size: 20px;
            line-height: 1;
        }

        /* ---------- Opportunity Information ---------- */
        .field-view {
            min-width: 0;
            padding: 15px;
            background: #faf9fd;
            border: 1px solid #eee9f5;
            border-radius: 12px;
            color: var(--detail-text);
            font-size: 13px;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .field-label {
            margin-bottom: 6px;
            color: var(--detail-muted);
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 0.25px;
            text-transform: uppercase;
        }

        .detail-description {
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .skill-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .detail-page .skill-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
            height: auto;
            min-height: 30px;
            padding: 6px 12px;
            border-radius: 999px;
            white-space: normal;
        }

        .detail-list {
            margin: 0;
            padding-left: 21px;
            color: #5e596c;
        }

        .detail-list li {
            padding: 4px 0;
            line-height: 1.8;
            overflow-wrap: anywhere;
        }

        .company-description {
            padding: 16px;
            background: #faf9fd;
            border-left: 3px solid var(--detail-gold);
            border-radius: 0 10px 10px 0;
        }

        .company-description p {
            margin: 0;
            white-space: pre-line;
        }

        /* ---------- Application Section ---------- */
        .apply-area {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .apply-note {
            width: 100%;
            margin: 0;
            color: var(--detail-muted);
            font-size: 12px;
        }

        /* Toast: success message that slides down from the top, then disappears (same as Browse and the dashboards) */
        .ufuq-toast {
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

        .ufuq-toast.is-shown {
            transform: translate(-50%, 0);
            opacity: 1;
        }

        .ufuq-toast-icon {
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

        .ufuq-toast-close {
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

        .ufuq-toast-close:hover {
            background: rgba(31,107,71,.1);
        }

        @media (prefers-reduced-motion: reduce) {
            .ufuq-toast { transition: none; }
        }

        /* ---------- Status Toast ---------- */
        .status-toast {
            display: flex;
            align-items: center;
            gap: 11px;
            width: 100%;
            margin: 0 0 20px;
            padding: 14px 16px;
            border: 1px solid transparent;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.65;
            box-shadow: 0 4px 14px rgba(40, 26, 70, 0.04);
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .status-toast.success {
            color: #176b3a;
            background: #edf8f1;
            border-color: #ccebd6;
        }

        .status-toast.error {
            color: #a52b2b;
            background: #fff0f1;
            border-color: #f3d0d3;
        }

        .toast-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.75);
        }

        .status-toast.toast-hidden {
            opacity: 0;
            transform: translateY(-6px);
            pointer-events: none;
        }

        .detail-page .badge-closed {
            color: #9c263d;
            background: #fff1f2;
            border-color: #f1d4d8;
        }

        .heading-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 8px;
            padding: 3px 9px;
            border: 1px solid #c5e9d0;
            border-radius: 999px;
            background: #e8f7ed;
            color: #176b3a;
            font-size: 11px;
            font-weight: 750;
            vertical-align: middle;
        }

        .declaration-check {
            display: flex !important;
            align-items: flex-start;
            gap: 9px;
            padding: 12px 14px;
            border: 1px solid #eee9f5;
            border-radius: 10px;
            background: #faf9fd;
            font-weight: 600 !important;
            line-height: 1.6;
            cursor: pointer;
        }

        .declaration-check input {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin: 3px 0 0;
            accent-color: var(--detail-purple);
            cursor: pointer;
        }

        .field-error {
            display: none;
            margin-top: 6px;
            color: var(--detail-red);
            font-size: 12px;
        }

        .field-error.is-visible {
            display: block;
        }

        /* ---------- Review Summary ---------- */
        .reviews-card {
            margin-top: 24px;
        }

        .reviews-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .reviews-header h3 {
            margin: 0;
        }

        .average-rating {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            color: var(--detail-text);
            background: #fbf6e9;
            border: 1px solid #f1e4bf;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 750;
            white-space: nowrap;
        }

        .average-rating-star {
            color: #e6a900;
            font-size: 18px;
        }

        /* ---------- Rating Stars ---------- */
        .rating-star {
            padding: 0;
            margin: 0;
            color: #d4d0dc;
            background: none;
            border: none;
            font-family: inherit;
            font-size: 38px;
            line-height: 1.2;
            cursor: pointer;
            transition: transform 0.15s ease, color 0.15s ease;
        }

        .rating-star:hover {
            transform: scale(1.1);
        }

        .rating-star:focus-visible {
            outline: 2px solid var(--detail-purple);
            outline-offset: 4px;
            border-radius: 4px;
        }

        .rating-control {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        .rating-star-row {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .rating-text {
            margin-left: 8px;
            color: var(--detail-muted);
            font-size: 12px;
        }

        .rating-error {
            display: none;
            margin-top: 3px;
            color: var(--detail-red);
            font-size: 12px;
            line-height: 1.5;
            opacity: 0;
            transform: translateY(-3px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }

        .rating-error.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* ---------- Form Controls ---------- */
        .detail-page .form-group {
            margin-bottom: 16px;
        }

        .detail-page .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--detail-text);
            font-size: 13px;
            font-weight: 700;
        }

        .detail-page .form-control {
            display: block;
            width: 100%;
            min-height: 45px;
            padding: 11px 13px;
            color: var(--detail-text);
            background: #ffffff;
            border: 1px solid #ded7e9;
            border-radius: 10px;
            font: inherit;
            font-size: 13px;
            line-height: 1.6;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .detail-page .form-control::placeholder {
            color: #a49daf;
        }

        .detail-page .form-control:focus {
            outline: none;
            border-color: var(--detail-purple);
            box-shadow: 0 0 0 3px rgba(73, 52, 119, 0.1);
        }

        .detail-page textarea.form-control {
            min-height: 110px;
            resize: vertical;
        }

        /* ---------- Individual Reviews ---------- */
        .review-item {
            padding: 17px 0;
            border-bottom: 1px solid var(--detail-border);
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .review-author {
            color: var(--detail-text);
            font-size: 13px;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .review-stars {
            display: inline-flex;
            align-items: center;
            gap: 1px;
            font-size: 19px;
            line-height: 1.3;
            letter-spacing: 1px;
            white-space: nowrap;
        }

        .review-stars .filled {
            color: #e9ac10;
        }

        .review-stars .empty {
            color: #d8d3df;
        }

        .review-text {
            margin: 9px 0 0;
            color: #5e596c;
            font-size: 13px;
            line-height: 1.8;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .review-date {
            margin-top: 8px;
            color: var(--detail-muted);
            font-size: 11px;
        }

        .review-empty {
            margin: 0;
            padding: 18px;
            color: var(--detail-muted);
            background: #faf9fd;
            border: 1px dashed #ded5ec;
            border-radius: 12px;
            font-size: 13px;
        }

        /* ---------- Not Found ---------- */
        .not-found-card {
            margin-top: 16px;
        }

        .not-found-card h2 {
            margin-top: 0;
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 700px) {
            .detail-page {
                padding-top: 20px;
            }

            .detail-container {
                width: calc(100% - 28px);
            }

            .detail-page .card {
                padding: 20px;
                border-radius: 15px;
            }

            .detail-page .grid-2 {
                grid-template-columns: minmax(0, 1fr);
            }

            .opportunity-heading {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .favorite-action-form {
                align-self: flex-start;
            }

            .opportunity-heading h2 {
                font-size: 24px;
            }

            .reviews-header {
                align-items: flex-start;
            }

            .rating-star {
                font-size: 34px;
            }

            .detail-page .btn {
                max-width: 100%;
                white-space: normal;
                text-align: center;
            }

            .apply-area form,
            .apply-area form .btn {
                width: 100%;
            }
        }

        @media (max-width: 400px) {
            .detail-container {
                width: calc(100% - 22px);
            }

            .detail-page .card {
                padding: 16px;
            }

            .opportunity-heading h2 {
                font-size: 21px;
            }

            .rating-star {
                font-size: 30px;
            }

            .rating-star-row {
                gap: 2px;
            }

            .rating-text {
                width: 100%;
                margin: 4px 0 0;
            }
        }

        /* ---------- Schedule ---------- */
        .schedule-days {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .schedule-day {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            padding: 7px 10px;
            border: 1px solid #e6e1ee;
            border-radius: 10px;
            background: #faf9fd;
            color: #b2abbd;
            font-size: 12.5px;
            font-weight: 750;
        }

        .schedule-day.is-on {
            border-color: var(--detail-purple);
            background: var(--detail-purple);
            color: #fff;
        }

        .schedule-time {
            margin: 12px 0 0;
            color: var(--detail-text) !important;
            font-weight: 700;
        }

        .tl-note {
            margin-top: 18px;
            padding: 12px 14px;
            border: 1px dashed #ded5ec;
            border-radius: 10px;
            background: #faf9fd;
        }

        .tl-note p {
            margin: 0;
        }

        /* ---------- Timeline ---------- */
        .tl-scroll {
            padding: 6px 2px 4px;
            overflow-x: auto;
        }

        .tl-track {
            display: flex;
            min-width: min-content;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .tl-step {
            position: relative;
            flex: 1 1 0;
            min-width: 120px;
            padding: 0 8px;
            text-align: center;
        }

        /* Line that joins this circle to the previous one. */
        .tl-step::before {
            content: "";
            position: absolute;
            top: 13px;
            left: -50%;
            width: 100%;
            height: 4px;
            border-radius: 4px;
            background: #e4dcef;
        }

        .tl-step:first-child::before {
            display: none;
        }

        .tl-step.is-done::before {
            background: var(--detail-purple);
        }

        .tl-dot {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            margin: 0 auto 11px;
            box-sizing: border-box;
            border: 3px solid #d5c9e6;
            border-radius: 50%;
            background: #fff;
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            line-height: 1;
        }

        .tl-step.is-done .tl-dot {
            border-color: var(--detail-purple);
            background: var(--detail-purple);
        }

        .tl-step.is-done .tl-dot::after {
            content: "\2713";
        }

        .tl-step.is-next .tl-dot {
            border-color: var(--detail-gold);
            background: #fff;
            box-shadow: 0 0 0 6px rgba(226, 169, 75, 0.22);
        }

        .tl-name {
            color: var(--detail-text);
            font-size: 13.5px;
            font-weight: 750;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .tl-step.is-next .tl-name {
            color: var(--detail-purple-dark);
        }

        .tl-date {
            margin-top: 2px;
            color: var(--detail-muted);
            font-size: 12.5px;
        }

        @media (max-width: 620px) {
            .tl-scroll {
                overflow: visible;
            }

            .tl-track {
                flex-direction: column;
                min-width: 0;
            }

            .tl-step {
                display: flex;
                align-items: flex-start;
                gap: 14px;
                min-width: 0;
                padding: 0 0 22px;
                text-align: left;
            }

            .tl-step:last-child {
                padding-bottom: 0;
            }

            .tl-step::before {
                display: none;
            }

            /* Vertical line down to the next circle. */
            .tl-step:not(:last-child)::after {
                content: "";
                position: absolute;
                top: 30px;
                bottom: 0;
                left: 13px;
                width: 4px;
                border-radius: 4px;
                background: #e4dcef;
            }

            .tl-step.next-done::after {
                background: var(--detail-purple);
            }

            .tl-dot {
                flex: 0 0 30px;
                margin: 0;
            }

            .tl-info {
                padding-top: 3px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .detail-page *,
            .detail-page *::before,
            .detail-page *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>

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

        <div class="detail-hero-icon" aria-hidden="true">✦</div>
    </div>
</section>

<div class="detail-page">
    <div class="detail-container">

        <a href="browse.php" class="detail-back">
            <span aria-hidden="true">&larr;</span>
            Back to Browse
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

                        <div class="heading-badges">
                            <span class="badge badge-neutral">
                                <?= detail_h($type) ?>
                            </span>

                            <?php if ($isClosed): ?>
                                <span class="badge badge-closed">Closed</span>
                            <?php endif; ?>
                        </div>

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
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= detail_h($_SESSION['csrf_token']) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="toggle_favorite"
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
                </section>

                <div class="divider"></div>

                <!-- Opportunity Information -->
                <div class="grid grid-2">

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
                        <div class="field-label">Application Method</div>
                        <?= detail_h($method ?: 'Not specified') ?>
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

                    <div class="field-view">
                        <div class="field-label">Opportunity Status</div>

                        <span class="badge <?= $isOpen ? 'badge-success' : ($isClosed ? 'badge-closed' : 'badge-neutral') ?>">
                            <?= detail_h($displayStatus) ?>
                        </span>
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

                        <div class="schedule-days">
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayKey): ?>
                                <span class="schedule-day <?= in_array($dayKey, $scheduleDays, true) ? 'is-on' : '' ?>"><?= detail_h($dayKey) ?></span>
                            <?php endforeach; ?>
                        </div>

                        <p class="schedule-time"><?= detail_h($scheduleTime) ?></p>
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
                                onsubmit="return confirm('Do you want to apply for this opportunity?');"
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
                            <span>
                                <?= number_format($averageRating, 1) ?> / 5
                            </span>

                            <span class="text-muted">
                                (<?= count($reviews) ?>
                                <?= count($reviews) === 1 ? 'review' : 'reviews' ?>)
                            </span>
                        <?php else: ?>
                            <span>No ratings yet</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Review Form -->
                <div style="margin-top:24px;">

                    <?php if ($myReview): ?>

                        <p class="review-empty">
                            You have already reviewed this opportunity.
                            Thank you for sharing your experience.
                        </p>

                    <?php else: ?>

                        <form
                            method="POST"
                            action="opportunity-detail.php?id=<?= (int) $opportunityId ?>"
                            id="reviewForm"
                            novalidate
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= detail_h($_SESSION['csrf_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="submit_review"
                            >

                            <input
                                type="hidden"
                                name="rating"
                                id="rating"
                                value="0"
                            >

                            <div class="form-group">

                                <label>
                                    Your Rating
                                    <span style="color:#c74755;">*</span>
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
                                                aria-checked="false"
                                                aria-label="<?= $i ?> stars"
                                            >★</button>
                                        <?php endfor; ?>

                                        <span
                                            id="ratingText"
                                            class="rating-text"
                                        >
                                            Select a rating
                                        </span>
                                    </div>

                                    <span
                                        id="ratingError"
                                        class="rating-error"
                                        role="alert"
                                        aria-live="polite"
                                    >
                                        Please select a rating from 1 to 5.
                                    </span>

                                </div>
                            </div>

                            <div class="form-group">
                                <label for="review_text">
                                    Share Your Experience
                                    <span style="color:#c74755;">*</span>
                                </label>

                                <textarea
                                    class="form-control"
                                    name="review_text"
                                    id="review_text"
                                    rows="4"
                                    maxlength="500"
                                    placeholder="What did you think about this opportunity?"
                                    required
                                ></textarea>

                                <span id="reviewTextError" class="field-error" role="alert">
                                    Please write your review.
                                </span>

                                <p class="text-muted"
                                   style="margin:6px 0 0; font-size:11px;">
                                    Maximum 500 characters.
                                </p>
                            </div>

                            <div class="form-group">
                                <label class="declaration-check">
                                    <input
                                        type="checkbox"
                                        name="completion_declaration"
                                        id="completionDeclaration"
                                        value="1"
                                        required
                                    >
                                    <span>
                                        I confirm that I have completed this training opportunity or course.
                                        <span style="color:#c74755;">*</span>
                                    </span>
                                </label>

                                <span id="declarationError" class="field-error" role="alert">
                                    Please confirm that you have completed this opportunity.
                                </span>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                Submit Review
                                <span aria-hidden="true">&#8594;</span>
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

                <div class="divider"></div>

                <!-- Review List -->
                <div id="reviewList">

                    <?php if (!$reviews): ?>

                        <p class="review-empty">
                            No reviews yet. Be the first to share your experience.
                        </p>

                    <?php else: ?>

                        <?php foreach ($reviews as $review): ?>

                            <?php
                            $reviewName = trim(
                                ($review['FirstName'] ?? '')
                                . ' '
                                . ($review['LastName'] ?? '')
                            );

                            $rating = max(
                                0,
                                min(5, (int) $review['Rating'])
                            );
                            ?>

                            <article class="review-item">

                                <div class="review-item-header">

                                    <strong class="review-author">
                                        <?= detail_h($reviewName ?: 'A user') ?>

                                        <?php if ($canVerifyReviews && (int) ($review['IsAccepted'] ?? 0) === 1): ?>
                                            <span class="verified-badge" title="Accepted through Ufuq">
                                                <span aria-hidden="true">✓</span>
                                                Verified Participant
                                            </span>
                                        <?php endif; ?>
                                    </strong>

                                    <span
                                        class="review-stars"
                                        aria-label="<?= $rating ?> out of 5 stars"
                                    >
                                        <span class="filled"><?= str_repeat('★', $rating) ?></span><span class="empty"><?= str_repeat('☆', 5 - $rating) ?></span>
                                    </span>

                                </div>

                                <?php if (!empty($review['ReviewText'])): ?>
                                    <p class="review-text"><?= detail_h(
                                        $review['ReviewText']
                                    ) ?></p>
                                <?php endif; ?>

                                <div class="review-date">
                                    <?= detail_h(
                                        detail_date($review['CreatedAt'] ?? null)
                                    ) ?>
                                </div>

                            </article>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>

        <?php endif; ?>

    </div>
</div>

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

    let selectedRating = 0;
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

<?php admin_footer(); ?>
