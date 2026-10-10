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

function detail_is_open($status, $deadline): bool
{
    if (strcasecmp(trim((string) $status), 'Open') !== 0) {
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
                 FROM favourite
                 WHERE UserID = ? AND OpportunityID = ?
                 LIMIT 1'
            );
            $check->execute([$uid, $opportunityId]);

            if ($check->fetchColumn()) {
                $stmt = $pdo->prepare(
                    'DELETE FROM favourite
                     WHERE UserID = ? AND OpportunityID = ?'
                );
                $stmt->execute([$uid, $opportunityId]);

                $_SESSION['detail_message'] =
                    'Removed from your favorites.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO favourite (UserID, OpportunityID)
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

            if (!detail_is_open($status, $deadline)) {
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
                         VALUES (?, ?, CURDATE(), 'Pending')"
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

            if (
                $rating === false
                || $rating === null
                || $rating < 1
                || $rating > 5
            ) {
                $_SESSION['detail_message'] =
                    'Please select a rating from 1 to 5.';
            } elseif (mb_strlen($reviewText, 'UTF-8') > 2000) {
                $_SESSION['detail_message'] =
                    'Your review must not exceed 2000 characters.';
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
                        $reviewText !== '' ? $reviewText : null,
                        $uid,
                        $opportunityId
                    ]);

                    $_SESSION['detail_message'] =
                        'Your review has been submitted.';
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
     FROM favourite
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
            'SELECT q.FieldOfStudy, q.DegreeLevel
             FROM opportunityqualification oq
             INNER JOIN qualification q
                 ON q.QualificationID = oq.QualificationID
             WHERE oq.OpportunityID = ?'
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
            'SELECT e.*
             FROM opportunityexperience oe
             INNER JOIN experience e
                 ON e.ExperienceID = oe.ExperienceID
             WHERE oe.OpportunityID = ?'
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
        'SELECT r.Rating, r.ReviewText, r.CreatedAt,
                u.FirstName, u.LastName, r.UserID
         FROM review r
         INNER JOIN `user` u ON u.UserID = r.UserID
         WHERE r.OpportunityID = ?
         ORDER BY r.CreatedAt DESC'
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
$startDate = $opp['StartDate'] ?? null;
$endDate = $opp['EndDate'] ?? null;
$timeline = $opp['TimeLine'] ?? '';
$price = $opp['Price'] ?? null;
$industrySector = $opp['IndustrySector'] ?? 'Not specified';

$isOpen = detail_is_open($status, $deadline);

$message = (string) ($_SESSION['detail_message'] ?? '');
unset($_SESSION['detail_message']);

$successMessages = [
    'Your review has been submitted.',
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= detail_h($title) ?> — Ufuq</title>

    <link rel="stylesheet" href="../css/base.css">
    <link rel="stylesheet" href="../css/layout.css">
    <link rel="stylesheet" href="../css/icons.css">

    <style>
        :root {
            --detail-purple: #493477;
            --detail-purple-dark: #30204f;
            --detail-purple-light: #f0eafb;
            --detail-gold: #e2b65d;
            --detail-bg: #f7f5fb;
            --detail-card: #ffffff;
            --detail-text: #292438;
            --detail-muted: #777286;
            --detail-border: #e9e4f1;
            --detail-green: #25845c;
            --detail-red: #c74755;
        }

        /* ---------- Page ---------- */
        body {
            background: var(--detail-bg);
            color: var(--detail-text);
        }

        .detail-page {
            min-height: 75vh;
            padding: 28px 0 60px;
            background: var(--detail-bg);
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
            border-radius: 18px;
            box-shadow: 0 5px 22px rgba(48, 32, 79, 0.045);
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
            box-shadow: 0 4px 14px rgba(48, 32, 79, 0.04);
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

        @media (prefers-reduced-motion: reduce) {
            .detail-page *,
            .detail-page *::before,
            .detail-page *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>

<main class="detail-page">
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

            <?php if ($message !== ''): ?>
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

                        <span class="badge badge-neutral">
                            <?= detail_h($type) ?>
                        </span>

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
                        <div class="field-label">Application Deadline</div>
                        <?= detail_h(detail_date($deadline)) ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Schedule / Timeline</div>
                        <?= detail_h($timeline ?: 'Not specified') ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Application Method</div>
                        <?= detail_h($method ?: 'Not specified') ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Price</div>

                        <?php if ($price !== null && $price !== ''): ?>
                            <?= detail_h(number_format((float) $price, 2)) ?> SAR
                        <?php else: ?>
                            Not specified
                        <?php endif; ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Industry Sector</div>
                        <?= detail_h($industrySector) ?>
                    </div>

                    <div class="field-view">
                        <div class="field-label">Opportunity Status</div>

                        <span class="badge <?= $isOpen ? 'badge-success' : 'badge-neutral' ?>">
                            <?= detail_h($status) ?>
                        </span>
                    </div>

                </div>

                <!-- Qualifications -->
                <?php if ($qualifications): ?>
                    <div class="divider"></div>

                    <section>
                        <h3>Qualifications</h3>

                        <ul class="detail-list">
                            <?php foreach ($qualifications as $q): ?>
                                <li>
                                    <?= detail_h(
                                        $q['FieldOfStudy'] ?: 'Field not specified'
                                    ) ?>

                                    <?php if (!empty($q['DegreeLevel'])): ?>
                                        — <?= detail_h($q['DegreeLevel']) ?>
                                    <?php endif; ?>
                                </li>
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
                                <?php
                                $experienceValues = [];

                                foreach ($experience as $key => $value) {
                                    if (
                                        $key !== 'ExperienceID'
                                        && $value !== null
                                        && trim((string) $value) !== ''
                                    ) {
                                        $experienceValues[] = $value;
                                    }
                                }
                                ?>

                                <?php if ($experienceValues): ?>
                                    <li><?= detail_h(
                                        implode(' — ', $experienceValues)
                                    ) ?></li>
                                <?php endif; ?>
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

                        <?php if (!$isOpen): ?>

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
                            strcasecmp(trim((string) $method), 'Internal') !== 0
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
                                </label>

                                <textarea
                                    class="form-control"
                                    name="review_text"
                                    id="review_text"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="What did you think about this opportunity?"
                                ></textarea>

                                <p class="text-muted"
                                   style="margin:6px 0 0; font-size:11px;">
                                    Maximum 2000 characters.
                                </p>
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
</main>

<div id="site-footer"></div>

<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
renderFooter({ base: "../" });
</script>

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

    reviewForm.addEventListener('submit', function (event) {
        const rating = Number(ratingInput.value);

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

    updateStars(selectedRating);
})();

/* ---------- Auto-hide Status Message ---------- */
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

</body>
</html>