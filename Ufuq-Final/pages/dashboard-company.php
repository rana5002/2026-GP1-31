<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_login('Company');

$companyId = (int) $_SESSION['user_id'];

$validTabs = ['overview', 'opportunities', 'applicants', 'reviews', 'profile'];
$activeTab = $_GET['tab'] ?? 'overview';
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'overview';

/* Application statuses allowed by the database (chk_app_status) */
$validApplicantStatuses = ['Pending', 'Accepted', 'Rejected'];

$success = '';
$error = '';

function company_redirect(string $tab, string $message = ''): never {
    $url = 'dashboard-company.php?tab=' . rawurlencode($tab);
    if ($message !== '') $url .= '&msg=' . rawurlencode($message);
    header('Location: ' . $url);
    exit;
}

if (isset($_GET['msg'])) {
    $messages = [
        'saved'   => 'Company profile updated successfully.',
        'added'   => 'Opportunity added successfully.',
        'updated' => 'Opportunity updated successfully.',
        'deleted' => 'Opportunity deleted successfully.',
        'status'  => 'Application status updated successfully.'
    ];
    $success = $messages[$_GET['msg']] ?? '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';
        $tabAfter = $_POST['tab'] ?? 'profile';
        if (!in_array($tabAfter, $validTabs, true)) $tabAfter = 'profile';

        if ($action === 'delete_opportunity') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
            $stmt->execute([$oppId, $companyId]);
            company_redirect('opportunities', 'deleted');
        }

        if ($action === 'update_application_status') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            $userId = (int)($_POST['applicant_id'] ?? 0);
            $status = trim($_POST['application_status'] ?? '');
            if (!in_array($status, $validApplicantStatuses, true)) throw new RuntimeException('Invalid application status.');
            $check = $pdo->prepare('SELECT 1 FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
            $check->execute([$oppId, $companyId]);
            if (!$check->fetchColumn()) throw new RuntimeException('You cannot update this application.');
            $update = $pdo->prepare('UPDATE application SET ApplicationStatus = ? WHERE UserID = ? AND OpportunityID = ?');
            $update->execute([$status, $userId, $oppId]);
            company_redirect('applicants', 'status');
        }

        /* ----- Company profile (each section saves on its own) ----- */
        if ($action === 'company_name') {
            $name = trim($_POST['company_name'] ?? '');
            if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('Please enter a valid company name (up to 100 characters).');
            $stmt = $pdo->prepare('UPDATE company SET CompanyName = ? WHERE CompanyID = ?');
            $stmt->execute([$name, $companyId]);
            company_redirect('profile', 'saved');
        }

        if ($action === 'company_logo') {
            if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Please choose a company logo.');
            $file = $_FILES['logo'];
            if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Logo must be 3 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedLogo = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowedLogo[$mime])) throw new RuntimeException('Please upload a PNG or JPG image.');
            $uploadDir = __DIR__ . '/../uploads/company/logos';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create the logo upload folder.');
            $fileName = 'company_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedLogo[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) throw new RuntimeException('Could not save the uploaded logo.');
            $stmt = $pdo->prepare('UPDATE company SET Logo = ? WHERE CompanyID = ?');
            $stmt->execute(['uploads/company/logos/' . $fileName, $companyId]);
            company_redirect('profile', 'saved');
        }

        if ($action === 'contact') {
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');

            // Matches chk_company_phone in the database
            if (!preg_match('/^(05[0-9]{8}|011[0-9]{7})$/', $phone)) {
                throw new RuntimeException('Please enter a valid phone number (05XXXXXXXX or 011XXXXXXX).');
            }
            if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if ($password !== '' && strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');

            $emailCheck = $pdo->prepare('SELECT MemberID FROM member WHERE Email = ? AND MemberID <> ?');
            $emailCheck->execute([$email, $companyId]);
            if ($emailCheck->fetch()) throw new RuntimeException('Email already registered');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE company SET Phone = ? WHERE CompanyID = ?');
            $stmt->execute([$phone, $companyId]);
            if ($password !== '') {
                $stmt = $pdo->prepare('UPDATE member SET Email = ?, Password = ? WHERE MemberID = ? AND Role = \'Company\'');
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $companyId]);
            } else {
                $stmt = $pdo->prepare('UPDATE member SET Email = ? WHERE MemberID = ? AND Role = \'Company\'');
                $stmt->execute([$email, $companyId]);
            }
            $pdo->commit();
            company_redirect('profile', 'saved');
        }

        if ($action === 'details') {
            $industry = trim($_POST['industry'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($industry === '') throw new RuntimeException('Please select an industry sector.');
            if ($location === '') throw new RuntimeException('Please select a location.');
            // Matches chk_company_desc in the database
            if (mb_strlen($description) < 15) throw new RuntimeException('Company description must be at least 15 characters.');
            if (mb_strlen($description) > 500) throw new RuntimeException('Company description must be 500 characters or fewer.');
            if ($website !== '' && (strlen($website) > 255 || !filter_var($website, FILTER_VALIDATE_URL))) throw new RuntimeException('Please enter a valid website URL.');

            $stmt = $pdo->prepare('UPDATE company SET IndustrySector = ?, Location = ?, WebsiteURL = ?, Description = ? WHERE CompanyID = ?');
            $stmt->execute([$industry, $location, $website !== '' ? $website : null, $description, $companyId]);
            company_redirect('profile', 'saved');
        }
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $ex instanceof PDOException ? 'Unable to save changes. Please check the information and try again.' : $ex->getMessage();
}

$stmt = $pdo->prepare('SELECT c.*, m.Email FROM company c JOIN member m ON m.MemberID = c.CompanyID WHERE c.CompanyID = ? AND m.Role = \'Company\'');
$stmt->execute([$companyId]);
$company = $stmt->fetch();
if (!$company) { http_response_code(404); exit('Company profile not found.'); }

$stmt = $pdo->prepare('SELECT o.*,
    (SELECT COUNT(*) FROM application a WHERE a.OpportunityID = o.OpportunityID) AS ApplicationCount,
    (SELECT COUNT(*) FROM favorite f WHERE f.OpportunityID = o.OpportunityID) AS FavouriteCount,
    o.ViewCount AS ViewsCount
    FROM opportunity o WHERE o.CreatedByCompanyID = ? ORDER BY o.OpportunityID DESC');
$stmt->execute([$companyId]);
$opportunities = $stmt->fetchAll();

/*
 * Applicants + profile information.
 * Skills, studies, certificates and experience live in separate tables in the
 * database, so they are collected per applicant with sub-queries.
 */
$stmt = $pdo->prepare("SELECT a.UserID, a.OpportunityID, a.SubmissionDate, a.ApplicationStatus,
    u.*, o.Title,
    (SELECT GROUP_CONCAT(s.SkillName ORDER BY s.SkillName SEPARATOR ', ')
       FROM userskill us JOIN skill s ON s.SkillID = us.SkillID
       WHERE us.UserID = a.UserID) AS Skills,
    (SELECT GROUP_CONCAT(DISTINCT st.FieldOfStudy ORDER BY st.FieldOfStudy SEPARATOR ', ')
       FROM userstudy st WHERE st.UserID = a.UserID) AS FieldOfStudy,
    (SELECT GROUP_CONCAT(DISTINCT st.DegreeLevel ORDER BY st.DegreeLevel SEPARATOR ', ')
       FROM userstudy st WHERE st.UserID = a.UserID) AS Degrees,
    (SELECT GROUP_CONCAT(q.QualificationName ORDER BY q.QualificationName SEPARATOR ', ')
       FROM userqualification uq JOIN qualification q ON q.QualificationID = uq.QualificationID
       WHERE uq.UserID = a.UserID) AS Certificates,
    (SELECT GROUP_CONCAT(CONCAT(e.ExperienceName, ' (', ue.JobTitle, ', ', ue.Organization, ')') ORDER BY ue.StartDate DESC SEPARATOR '; ')
       FROM userexperience ue JOIN experience e ON e.ExperienceID = ue.ExperienceID
       WHERE ue.UserID = a.UserID) AS Experience,
    (SELECT GROUP_CONCAT(us2.SkillID) FROM userskill us2 WHERE us2.UserID = a.UserID) AS SkillIDs,
    (SELECT GROUP_CONCAT(uq2.QualificationID) FROM userqualification uq2 WHERE uq2.UserID = a.UserID) AS QualificationIDs,
    (SELECT GROUP_CONCAT(ue2.ExperienceID) FROM userexperience ue2 WHERE ue2.UserID = a.UserID) AS ExperienceIDs
    FROM application a
    JOIN opportunity o ON o.OpportunityID = a.OpportunityID
    LEFT JOIN `user` u ON u.UserID = a.UserID
    WHERE o.CreatedByCompanyID = ?
    ORDER BY a.SubmissionDate DESC, a.OpportunityID DESC");
$stmt->execute([$companyId]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($applicants as &$applicantRow) {
    $applicantRow['Qualifications'] = implode(', ', array_filter([
        (string)($applicantRow['Degrees'] ?? ''),
        (string)($applicantRow['Certificates'] ?? '')
    ], static fn($v) => $v !== ''));
}
unset($applicantRow);

/** Return the first populated profile value among known column-name variants. */
function applicant_profile_value(array $row, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && is_scalar($row[$key])) {
            $value = trim((string)$row[$key]);
            if ($value !== '') return $value;
        }
    }
    return '';
}

function applicant_matches_text(string $value, string $filter): bool {
    if ($filter === '') return true;
    return function_exists('mb_stripos') ? mb_stripos($value, $filter) !== false : stripos($value, $filter) !== false;
}

/* Lookup lists for the filter dropdowns, read from the database as [ID => Name]. */
function company_lookup_options(PDO $pdo, string $table, string $idCol, string $nameCol): array {
    $rows = $pdo->query("SELECT `$idCol` AS id, `$nameCol` AS name FROM `$table` ORDER BY `$nameCol`")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) $out[(int)$row['id']] = (string)$row['name'];
    return $out;
}

/* Keep only the posted IDs that exist in the lookup list. */
function company_filter_ids($value, array $options): array {
    $ids = [];
    foreach ((array)$value as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if ($id !== false && isset($options[$id])) $ids[$id] = $id;
    }
    return array_values($ids);
}

/* True when the comma-separated IDs of an applicant contain every selected ID. */
function applicant_has_all(?string $csv, array $selectedIds): bool {
    if (!$selectedIds) return true;
    $have = array_map('intval', array_filter(explode(',', (string)$csv), 'strlen'));
    return !array_diff($selectedIds, $have);
}

/* Always-visible checkbox list with a search box on top. The box shows about 5 items
   and scrolls for the rest. Selected items are listed first. */
function lookup_box(string $name, string $label, string $searchPlaceholder, array $options, array $selectedIds): string {
    $checked = $unchecked = [];
    foreach ($options as $optId => $optName) {
        if (in_array((int)$optId, $selectedIds, true)) $checked[$optId] = $optName; else $unchecked[$optId] = $optName;
    }
    /* Collapsed by default; opened when something in it is selected. The triangle opens / closes it. */
    $isOpen = (bool)$selectedIds;
    $boxId = 'lookup-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
    ob_start(); ?>
    <div class="applicant-filter-field lookup-field <?= $isOpen ? 'is-open' : '' ?>" data-lookup-box>
        <button type="button" class="lookup-header" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= e($boxId) ?>">
            <span class="applicant-filter-label"><?= e($label) ?> <span class="lookup-count"><?= $selectedIds ? '(' . count($selectedIds) . ' selected)' : '' ?></span></span>
            <span class="lookup-caret" aria-hidden="true"></span>
        </button>
        <div class="lookup-box" id="<?= e($boxId) ?>" <?= $isOpen ? '' : 'hidden' ?>>
            <input type="text" class="form-control lookup-search" placeholder="<?= e($searchPlaceholder) ?>" autocomplete="off" aria-label="<?= e($searchPlaceholder) ?>">
            <div class="lookup-list" role="group" aria-label="<?= e($label) ?>">
                <?php foreach ($checked + $unchecked as $optId => $optName): ?>
                    <label class="lookup-option" data-text="<?= e(mb_strtolower((string)$optName, 'UTF-8')) ?>">
                        <input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int)$optId ?>" <?= isset($checked[$optId]) ? 'checked' : '' ?>>
                        <span><?= e($optName) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="lookup-empty" hidden>No matches found</div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

$skillOptions = company_lookup_options($pdo, 'skill', 'SkillID', 'SkillName');
$qualificationOptions = company_lookup_options($pdo, 'qualification', 'QualificationID', 'QualificationName');
$experienceOptions = company_lookup_options($pdo, 'experience', 'ExperienceID', 'ExperienceName');
$studyOptions = $pdo->query('SELECT DISTINCT FieldOfStudy FROM userstudy WHERE FieldOfStudy <> \'\' ORDER BY FieldOfStudy')->fetchAll(PDO::FETCH_COLUMN);

// Filters are scoped to the opportunities owned by the logged-in company.
$selectedOpportunity = (int)($_GET['opportunity_filter'] ?? 0);
$selectedStatus = trim((string)($_GET['status_filter'] ?? ''));
$skillFilter = company_filter_ids($_GET['skill_filter'] ?? [], $skillOptions);
$qualificationFilter = company_filter_ids($_GET['qualification_filter'] ?? [], $qualificationOptions);
$experienceFilter = company_filter_ids($_GET['experience_filter'] ?? [], $experienceOptions);
$studyFilter = trim((string)($_GET['study_filter'] ?? ''));
if (!in_array($selectedStatus, $validApplicantStatuses, true)) $selectedStatus = '';
$companyOpportunityIds = array_map(static fn($o) => (int)$o['OpportunityID'], $opportunities);
if ($selectedOpportunity && !in_array($selectedOpportunity, $companyOpportunityIds, true)) $selectedOpportunity = 0;

$applicantsForDisplay = array_values(array_filter($applicants, static function ($a) use ($selectedOpportunity, $selectedStatus, $skillFilter, $qualificationFilter, $experienceFilter, $studyFilter) {
    if ($selectedOpportunity && (int)$a['OpportunityID'] !== $selectedOpportunity) return false;
    if ($selectedStatus !== '' && (string)($a['ApplicationStatus'] ?? '') !== $selectedStatus) return false;
    if (!applicant_has_all($a['SkillIDs'] ?? '', $skillFilter)) return false;
    if (!applicant_has_all($a['QualificationIDs'] ?? '', $qualificationFilter)) return false;
    if (!applicant_has_all($a['ExperienceIDs'] ?? '', $experienceFilter)) return false;
    if (!applicant_matches_text(applicant_profile_value($a, ['FieldOfStudy']), $studyFilter)) return false;
    return true;
}));
$applicantsByOpportunity = [];
foreach ($applicantsForDisplay as $applicantRow) {
    $applicantsByOpportunity[(int)$applicantRow['OpportunityID']][] = $applicantRow;
}
$activeFilterCount = ($selectedOpportunity > 0 ? 1 : 0) + ($selectedStatus !== '' ? 1 : 0) + count($skillFilter) + count($qualificationFilter) + count($experienceFilter) + ($studyFilter !== '' ? 1 : 0);
$hasApplicantFilters = $activeFilterCount > 0;

$stmt = $pdo->prepare('SELECT COUNT(*) FROM review r JOIN opportunity o ON o.OpportunityID = r.OpportunityID WHERE o.CreatedByCompanyID = ?');
$stmt->execute([$companyId]);
$reviewCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT AVG(r.Rating) FROM review r JOIN opportunity o ON o.OpportunityID = r.OpportunityID WHERE o.CreatedByCompanyID = ?');
$stmt->execute([$companyId]);
$averageRating = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT r.Rating, r.ReviewText, r.CreatedAt, u.FirstName, u.LastName, o.Title
    FROM review r JOIN opportunity o ON o.OpportunityID = r.OpportunityID
    LEFT JOIN `user` u ON u.UserID = r.UserID
    WHERE o.CreatedByCompanyID = ? ORDER BY r.CreatedAt DESC');
$stmt->execute([$companyId]);
$reviews = $stmt->fetchAll();

$openCount = count(array_filter($opportunities, static fn($o) => strtolower((string)$o['Status']) === 'open'));
$totalApplicants = count($applicants);
$totalViews = array_sum(array_map(static fn($o) => (int)($o['ViewsCount'] ?? 0), $opportunities));
$recentApplicants = array_slice($applicants, 0, 5);
$industryOptions = ['Technology', 'Education & Training', 'Marketing', 'Administration', 'Media', 'Healthcare', 'Finance', 'Trading', 'Other'];
$locationOptions = defined('CITIES') ? CITIES : ['Riyadh', 'Jeddah', 'Mecca', 'Medina', 'Dammam', 'Khobar', 'Dhahran', 'Taif', 'Abha', 'Tabuk', 'Online', 'Other'];
$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (str_starts_with($logo, 'http') ? $logo : '../' . ltrim($logo, '/'));
$companyInitial = strtoupper(substr((string)$company['CompanyName'], 0, 1));

function display_date($date): string { return $date ? e(date('M j, Y', strtotime((string)$date))) : '&mdash;'; }

$heroTitles = [
    'opportunities' => ['Opportunities', 'Manage the training programs and courses you publish.'],
    'applicants'    => ['Applicants', 'Review candidates and update their application status.'],
    'reviews'       => ['Reviews', 'Read what students are saying about your opportunities.'],
    'profile'       => ['Company Profile', 'Keep your company information up to date.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Company Dashboard &mdash; Ufuq</title>
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">
<style>
/* ===== Ufuq Company Dashboard (same design as the user Home page) ===== */

.tab-panel{display:none}
.tab-panel.active{display:block;animation:companyFadeUp .42s ease both}

.company-dashboard{
    --c-purple:#39265f;--c-purple-dark:#281a46;--c-purple-mid:#49316f;
    --c-lavender:#f0ebf8;--c-gold:#e2a94b;--c-bg:#faf6ef;
    --c-text:#29243a;--c-muted:#777184;--c-border:#e9e3ed;
    color:var(--c-text);padding-bottom:48px;background:var(--c-bg);min-height:70vh;
}
.company-dashboard *,.company-dashboard *::before,.company-dashboard *::after{box-sizing:border-box}
.company-dashboard a{text-underline-offset:3px}

/* Welcome banner */
.company-dashboard .dashboard-hero{position:relative;overflow:hidden;padding:38px 0 42px;margin-bottom:28px;background:linear-gradient(125deg,#281a46 0%,#39265f 55%,#49316f 100%);color:#fff}
.company-dashboard .dashboard-hero::before,.company-dashboard .dashboard-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.company-dashboard .dashboard-hero::before{width:250px;height:250px;right:7%;top:-150px;border:35px solid rgba(255,255,255,.07)}
.company-dashboard .dashboard-hero::after{width:190px;height:190px;right:23%;bottom:-145px;background:rgba(226,169,75,.10)}
.company-dashboard .dashboard-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:22px}
.company-dashboard .welcome-label{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;margin-bottom:12px;border:1px solid rgba(255,255,255,.2);border-radius:30px;background:rgba(255,255,255,.08);font-size:.84rem;letter-spacing:.2px}
.company-dashboard .dashboard-hero h1{margin:0;color:#fff;font-size:clamp(1.65rem,3vw,2.15rem);font-weight:750;line-height:1.5}
.company-dashboard .dashboard-hero p{margin:8px 0 0;color:rgba(255,255,255,.82);font-size:.96rem;line-height:1.8}
.company-dashboard .hero-icon{display:flex;align-items:center;justify-content:center;width:78px;height:78px;flex-shrink:0;border:1px solid rgba(255,255,255,.2);border-radius:24px;background:rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(15,8,30,.16);font-size:2rem;animation:companyFloat 4s ease-in-out infinite}
.company-dashboard .dashboard-content{animation:companyFadeUp .5s ease both}

/* Headings */
.company-dashboard .dashboard-section{margin-bottom:24px}
.company-dashboard .section-heading{display:flex;align-items:center;gap:10px;margin:0 0 16px;color:var(--c-text);font-size:1.18rem;font-weight:750}
.company-dashboard .section-heading::before{content:"";display:block;width:5px;height:23px;flex-shrink:0;border-radius:5px;background:var(--c-gold)}
.company-dashboard .section-description{margin:-7px 0 16px;color:var(--c-muted);font-size:.9rem;line-height:1.7}
.company-dashboard .card-heading-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px}

/* Cards */
.company-dashboard .dashboard-card{min-width:0;padding:20px;border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(40,26,70,.045);transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease}
.company-dashboard .dashboard-card:hover{border-color:#d5c8e4;box-shadow:0 10px 26px rgba(40,26,70,.075)}

/* Overview stat cards */
.company-dashboard .stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:28px}
.company-dashboard .stat-card{display:flex;flex-direction:column;justify-content:space-between;min-height:150px;color:var(--c-text);text-decoration:none}
.company-dashboard .stat-card:hover{transform:translateY(-3px)}
.company-dashboard .stat-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:15px}
.company-dashboard .stat-title{font-size:.93rem;font-weight:700}
.company-dashboard .stat-icon{display:inline-flex;align-items:center;justify-content:center;width:43px;height:43px;flex-shrink:0;border-radius:13px;background:var(--c-lavender);color:var(--c-purple)}
.company-dashboard .stat-number{display:block;margin-bottom:5px;color:var(--c-purple);font-size:2rem;font-weight:800;line-height:1.3}
.company-dashboard .stat-note{color:var(--c-muted);font-size:.83rem;line-height:1.6}
.company-dashboard .dashboard-link{display:inline-flex;align-items:center;gap:6px;color:var(--c-purple);font-size:.86rem;font-weight:700;text-decoration:none}
.company-dashboard .dashboard-link:hover{color:var(--c-purple-dark);text-decoration:underline}

/* Alerts */
.company-dashboard .company-alert{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;margin-bottom:22px;border-radius:13px;font-size:.9rem;line-height:1.6;border:1px solid transparent;animation:companyFadeUp .35s ease both}
.company-dashboard .company-alert.success{background:#e4f6ed;color:#286546;border-color:#b8e5cf}
.company-dashboard .company-alert.error{background:#fbe6e7;color:#9a3038;border-color:#f0c6ca}
/* Toast: success message that slides down from the top, then disappears */
.company-toast{position:fixed;top:18px;left:50%;z-index:1000;display:flex;align-items:center;gap:11px;width:max-content;max-width:calc(100% - 32px);padding:13px 14px 13px 16px;border:1px solid #bfe3cc;border-radius:13px;background:#eaf7ef;color:#1f6b47;font-size:.92rem;font-weight:700;line-height:1.5;box-shadow:0 12px 30px rgba(31,107,71,.16);transform:translate(-50%,-150%);opacity:0;transition:transform .35s ease,opacity .35s ease}
.company-toast.is-shown{transform:translate(-50%,0);opacity:1}
.company-toast-icon{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;flex-shrink:0;border-radius:50%;background:#2f8a5c;color:#fff;font-size:.85rem}
.company-toast-close{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;flex-shrink:0;padding:0;border:0;border-radius:8px;background:none;color:#1f6b47;font-size:1.2rem;line-height:1;cursor:pointer}
.company-toast-close:hover{background:rgba(31,107,71,.1)}
@media(prefers-reduced-motion:reduce){.company-toast{transition:none}}

/* Tables */
.company-dashboard .company-table-wrap{overflow-x:auto;border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(40,26,70,.045)}
.company-dashboard .company-table-wrap .data-table{width:100%;margin:0;border-collapse:collapse}
.company-dashboard .company-table-wrap .data-table thead{background:#f1edf7}
.company-dashboard .company-table-wrap .data-table th{padding:15px 18px;color:var(--c-purple);font-size:.87rem;font-weight:750;text-align:start;white-space:nowrap;border-bottom:1px solid #e6deef}
.company-dashboard .company-table-wrap .data-table td{padding:15px 18px;border-bottom:1px solid #f0edf4;color:#464052;font-size:.91rem;vertical-align:middle}
.company-dashboard .company-table-wrap .data-table tbody tr{transition:background .18s ease}
.company-dashboard .company-table-wrap .data-table tbody tr:hover{background:#faf8fc}
.company-dashboard .company-table-wrap .data-table tbody tr:last-child td{border-bottom:none}

/* Badges, buttons */
.company-dashboard .badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:30px;font-size:.76rem;font-weight:750;white-space:nowrap}
.company-dashboard .btn{transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease}
.company-dashboard .btn:hover{transform:translateY(-1px)}
.company-dashboard .btn:active{transform:translateY(0)}
.company-dashboard .btn-primary{background:var(--c-purple);border-color:var(--c-purple);box-shadow:0 4px 12px rgba(57,38,95,.15)}
.company-dashboard .btn-primary:hover{background:var(--c-purple-dark);border-color:var(--c-purple-dark);box-shadow:0 7px 17px rgba(40,26,70,.20)}
.company-dashboard .btn-outline{border-radius:9px}
.company-dashboard .text-muted{color:var(--c-muted)}
.company-dashboard .company-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.company-dashboard .company-inline-form{display:inline;margin:0}
.company-dashboard .status-select{padding:7px 9px;min-width:135px}

/* Empty states */
.company-dashboard .empty-state{padding:30px 18px;border:1px dashed #d8cde7;border-radius:14px;background:#fbf9fd;text-align:center}
.company-dashboard .empty-state .empty-icon{display:inline-flex;align-items:center;justify-content:center;width:58px;height:58px;margin-bottom:11px;border-radius:19px;background:var(--c-lavender);color:var(--c-purple)}
.company-dashboard .empty-state h3{margin:0;color:var(--c-text);font-size:1rem;font-weight:750}
.company-dashboard .empty-state p{margin:7px 0 0;color:var(--c-muted);font-size:.87rem;line-height:1.7}
.company-dashboard .empty-state .btn{margin-top:14px}

/* Applicants */
/* Applicants: filter sidebar on the left, shown / hidden with the Filters button */
.company-dashboard .applicants-toolbar{display:flex;align-items:center;justify-content:flex-start;gap:14px;flex-wrap:wrap;margin:0 0 16px}
.company-dashboard .applicants-toolbar .filter-toggle{flex-shrink:0}
.company-dashboard .applicants-toolbar .section-description{margin:0;flex:1 1 320px}
.company-dashboard .filter-toggle{display:inline-flex;align-items:center;gap:8px}
.company-dashboard .filter-toggle-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--c-gold);color:#fff;font-size:.74rem;font-weight:800}
.company-dashboard .applicants-layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:22px;align-items:start}
.company-dashboard .applicants-layout.filters-hidden{grid-template-columns:minmax(0,1fr)}
.company-dashboard .applicants-layout.filters-hidden .applicant-filter-panel{display:none}
.company-dashboard .applicants-results{min-width:0}
.company-dashboard .applicant-filter-panel{display:flex;flex-direction:column;gap:16px;padding:18px;background:#fff;border:1px solid var(--c-border);border-radius:16px;box-shadow:0 5px 18px rgba(40,26,70,.045);animation:companyFadeUp .3s ease both}
.company-dashboard .applicant-filter-title{display:flex;align-items:center;gap:9px;margin:0;padding-bottom:12px;border-bottom:1px solid #eee8f4;color:var(--c-text);font-size:1rem;font-weight:750}
.company-dashboard .applicant-filter-title::before{content:"";display:block;width:5px;height:20px;border-radius:5px;background:var(--c-gold)}
.company-dashboard .applicant-filter-field{display:flex;flex-direction:column;gap:7px;min-width:0}
.company-dashboard .applicant-filter-field > label,.company-dashboard .applicant-filter-label{font-size:.86rem;font-weight:700;color:var(--c-text)}
.company-dashboard .applicant-filter-field .form-control{width:100%;min-height:42px;border:1px solid #ddd5e8;border-radius:9px;background:#fff;padding:9px 11px}
.company-dashboard .applicant-filter-actions{display:flex;gap:8px;align-items:center}
.company-dashboard .applicant-filter-actions .btn{flex:1}
.company-dashboard .applicant-filter-note{font-size:.8rem;line-height:1.55;color:var(--c-muted);margin:0}
.company-dashboard .applicant-view-link{margin-top:6px;font-size:.82rem}

/* Only the filter moves left, into the empty space beside the page; the applicant cards stay in place.
   The shift is the filter width + gap (312px), or less on screens without enough space on the left. */
.company-dashboard #tab-applicants{--filter-shift:min(312px, max(0px, calc((100vw - 100%) / 2 - 16px)))}
.company-dashboard .applicants-toolbar,
.company-dashboard .applicants-layout{margin-left:calc(-1 * var(--filter-shift))}
.company-dashboard .applicants-layout.filters-hidden{margin-left:0}

/* Category header with a triangle that opens / closes its list */
.company-dashboard .lookup-header{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:4px 2px;border:0;background:none;font:inherit;text-align:left;cursor:pointer;border-radius:8px}
.company-dashboard .lookup-header:hover .applicant-filter-label{color:var(--c-purple)}
.company-dashboard .lookup-header:focus-visible{outline:2px solid var(--c-purple);outline-offset:2px}
.company-dashboard .lookup-caret{flex-shrink:0;width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:7px solid var(--c-purple);transition:transform .2s ease}
.company-dashboard .lookup-field.is-open .lookup-caret{transform:rotate(180deg)}
.company-dashboard .lookup-box[hidden]{display:none!important}

/* Checkbox list box with search: shows 5 items, scroll for the rest */
.company-dashboard .lookup-count{color:var(--c-purple);font-weight:650;font-size:.8rem}
.company-dashboard .lookup-box{padding:8px;border:1px solid #ddd5e8;border-radius:10px;background:#fff;animation:companyFadeUp .2s ease both}
.company-dashboard .lookup-box:focus-within{border-color:var(--c-purple);box-shadow:0 0 0 3px rgba(57,38,95,.10)}
.company-dashboard .applicant-filter-field .lookup-box .lookup-search{min-height:36px;padding:7px 10px;font-size:.85rem}
.company-dashboard .lookup-list{max-height:180px;margin-top:6px;overflow-y:auto;overscroll-behavior:contain}
.company-dashboard .lookup-option{display:flex;align-items:center;gap:9px;min-height:36px;padding:6px 8px;border-radius:8px;font-size:.87rem;line-height:1.4;cursor:pointer;overflow-wrap:anywhere}
.company-dashboard .lookup-option:hover{background:var(--c-lavender)}
.company-dashboard .lookup-option:has(input:checked){background:#f2ecfa;color:var(--c-purple-dark);font-weight:650}
.company-dashboard .lookup-option[hidden]{display:none!important}
.company-dashboard .lookup-option input{width:16px;height:16px;flex-shrink:0;margin:0;accent-color:var(--c-purple);cursor:pointer}
.company-dashboard .lookup-empty{padding:10px 8px;color:#8a8396;font-size:.85rem;text-align:center}
@media(max-width:900px){
  .company-dashboard .applicants-layout{grid-template-columns:minmax(0,1fr)}
  .company-dashboard .applicants-toolbar,.company-dashboard .applicants-layout{margin-left:0}
}
.company-dashboard .applicant-opportunity-card{margin:0 0 22px;border:1px solid var(--c-border);border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 5px 18px rgba(40,26,70,.045);animation:companyFadeUp .35s ease both}
.company-dashboard .applicant-opportunity-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 20px;background:#f7f4fb;border-bottom:1px solid #e6deef}
.company-dashboard .applicant-opportunity-heading h3{margin:0;color:var(--c-purple);font-size:1.05rem}
.company-dashboard .applicant-opportunity-heading p{margin:5px 0 0;color:var(--c-muted);font-size:.86rem}
.company-dashboard .applicant-count{flex-shrink:0;padding:7px 11px;border-radius:999px;background:#ece6f8;color:var(--c-purple);font-size:.82rem;font-weight:700}
.company-dashboard .applicant-opportunity-card .company-table-wrap{border:0;border-radius:0;box-shadow:none}
.company-dashboard .applicant-status-form{min-width:245px}
.company-dashboard .applicant-profile-details{margin-top:8px;font-size:.82rem;color:#596579}
.company-dashboard .applicant-profile-details summary{cursor:pointer;color:var(--c-purple);font-weight:700;list-style-position:inside}
.company-dashboard .applicant-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px;padding:10px 0 2px}
.company-dashboard .applicant-profile-grid span{overflow-wrap:anywhere}

/* Reviews */
.company-dashboard .rating-stars{color:#d69e16;letter-spacing:1px}
.company-dashboard .company-help{font-size:.82rem;line-height:1.55;color:var(--c-muted)}
.company-dashboard .review-card{margin-bottom:14px}
.company-dashboard .rating-pill{display:inline-flex;align-items:center;padding:6px 12px;border-radius:30px;background:#fbf6eb;border:1px solid #eee4d1;color:#76531d;font-size:.84rem;font-weight:750}

/* Profile */

/* Profile (same layout as the user profile page) */
.company-dashboard .profile-identity,.company-dashboard .profile-section{border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(40,26,70,.045);transition:box-shadow .22s ease,border-color .22s ease}
.company-dashboard .profile-identity:hover,.company-dashboard .profile-section:hover{border-color:#d8cbe7;box-shadow:0 9px 24px rgba(40,26,70,.075)}
.company-dashboard .profile-identity{display:flex;align-items:center;gap:22px;padding:25px;margin-bottom:22px}
.company-dashboard .profile-avatar-wrap{position:relative;flex:0 0 92px;width:92px;height:92px}
.company-dashboard .profile-avatar{display:flex;align-items:center;justify-content:center;width:92px;height:92px;overflow:hidden;border:4px solid #f0ebf8;border-radius:50%;background:var(--c-lavender);color:var(--c-purple);font-size:1.8rem;font-weight:750}
.company-dashboard .profile-avatar img{width:100%;height:100%;object-fit:cover}
.company-dashboard .profile-avatar-upload{position:absolute;right:-3px;bottom:0;display:flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:2px solid #fff;border-radius:50%;background:var(--c-purple);color:#fff;cursor:pointer;transition:transform .18s ease,background .18s ease}
.company-dashboard .profile-avatar-upload:hover{transform:scale(1.07);background:var(--c-purple-dark)}
.company-dashboard .profile-identity-details{flex:1;min-width:0}
.company-dashboard .profile-name-row{display:flex;align-items:center;flex-wrap:wrap;gap:12px}
.company-dashboard .profile-name-row h2{margin:0;color:var(--c-text);font-size:1.35rem;overflow-wrap:anywhere}
.company-dashboard .profile-subtitle{margin-top:6px;color:var(--c-muted);font-size:.9rem}
.company-dashboard .profile-meta{margin-top:9px;color:var(--c-muted);font-size:.84rem}
.company-dashboard .profile-section{padding:24px;margin-bottom:20px}
.company-dashboard .profile-section-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding-bottom:17px;margin-bottom:19px;border-bottom:1px solid #e9e4ef}
.company-dashboard .profile-section-header h2{display:flex;align-items:center;gap:10px;margin:0;color:var(--c-text);font-size:1.08rem;font-weight:750}
.company-dashboard .profile-section-header h2::before{content:"";display:block;width:5px;height:23px;border-radius:5px;background:var(--c-gold)}
.company-dashboard .profile-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:21px 25px}
.company-dashboard .profile-field{min-width:0}
.company-dashboard .profile-field.is-wide{grid-column:1/-1}
.company-dashboard .profile-field-label{margin-bottom:7px;color:var(--c-muted);font-size:.82rem}
.company-dashboard .profile-field-value{color:var(--c-text);font-size:.93rem;line-height:1.65;overflow-wrap:anywhere;white-space:pre-wrap}
.company-dashboard .profile-field-value a{color:var(--c-purple);font-weight:600}
.company-dashboard .profile-form{margin-top:5px}
.company-dashboard .profile-form .form-group{margin-bottom:17px}
.company-dashboard .profile-form .form-group.is-wide{grid-column:1/-1}
.company-dashboard .profile-form .form-label{display:block;margin-bottom:7px;color:var(--c-text);font-size:.87rem;font-weight:650}
.company-dashboard .profile-form .form-control{width:100%;border-radius:9px;border-color:#ddd5e8;transition:border-color .18s ease,box-shadow .18s ease}
.company-dashboard .profile-form .form-control:focus{border-color:var(--c-purple);box-shadow:0 0 0 3px rgba(57,38,95,.10);outline:none}
.company-dashboard .profile-form textarea{resize:vertical}
.company-dashboard .profile-form-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:19px}
.company-dashboard .profile-hidden{display:none!important}
.company-dashboard .profile-edit-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:0;border-radius:9px;background:transparent;color:var(--c-purple);font:inherit;font-size:.86rem;font-weight:700;cursor:pointer;transition:background .18s ease,color .18s ease}
.company-dashboard .profile-edit-btn:hover{background:var(--c-lavender);color:var(--c-purple-dark)}
.company-dashboard .profile-status{display:inline-flex;align-items:center;padding:3px 10px;border-radius:30px;font-size:.76rem;font-weight:750;background:#fbf6eb;color:#76531d}
.company-dashboard .profile-status.is-verified{background:#e8f5ec;color:#24653c}
.company-dashboard .profile-status.is-rejected{background:#fbe6e7;color:#9a3038}

/* Header profile avatar (unchanged) */
.company-header-profile{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;padding:5px 8px;border-radius:10px;white-space:nowrap}
.company-header-profile:hover{background:var(--color-surface-alt,#f3f4f6)}
.company-header-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e9edf5;color:var(--color-primary,#1f3566);font-weight:700;border:1px solid var(--color-border,#ddd)}
.company-header-avatar img{width:100%;height:100%;object-fit:cover}

@media(max-width:900px){.company-dashboard .stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){
  .company-dashboard .dashboard-hero{padding:30px 0}
  .company-dashboard .hero-icon{width:60px;height:60px;border-radius:18px;font-size:1.6rem}
  .company-dashboard .stat-grid{grid-template-columns:1fr;gap:12px}
  .company-dashboard .stat-card{min-height:130px}
  .company-dashboard .dashboard-card{padding:16px}
  .company-dashboard .applicant-filter-panel{padding:14px}
  .company-dashboard .applicant-opportunity-heading{align-items:flex-start;padding:15px;flex-direction:column}
  .company-dashboard .applicant-status-form{min-width:0}
  .company-dashboard .applicant-status-form .status-select{min-width:115px}
  .company-dashboard .applicant-profile-grid{grid-template-columns:1fr}
  .company-dashboard .profile-identity{align-items:flex-start;gap:15px;padding:19px}
  .company-dashboard .profile-avatar-wrap,.company-dashboard .profile-avatar{width:70px;height:70px;flex-basis:70px}
  .company-dashboard .profile-avatar{font-size:1.4rem}
  .company-dashboard .profile-name-row h2{font-size:1.1rem}
  .company-dashboard .profile-section{padding:19px 16px}
  .company-dashboard .profile-view-grid{grid-template-columns:1fr;gap:17px}
    }
@keyframes companyFadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes companyFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}
}
</style>
</head>
<body>
<header id="site-header" class="site-header">
  <div class="container">
    <a href="../index.php" class="brand" style="text-decoration:none"><img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent"> Ufuq</a>
    <nav class="nav-links"><a href="dashboard-company.php?tab=overview" class="<?= in_array($activeTab, ['overview', 'reviews', 'profile'], true) ? 'active' : '' ?>">Dashboard</a><a href="dashboard-company.php?tab=opportunities" class="<?= $activeTab === 'opportunities' ? 'active' : '' ?>">Opportunities</a><a href="dashboard-company.php?tab=applicants" class="<?= $activeTab === 'applicants' ? 'active' : '' ?>">Applicants</a></nav>
    <div class="nav-actions" style="display:flex;align-items:center;gap:10px"><a href="dashboard-company.php?tab=profile" class="company-header-profile" title="Open company profile" aria-label="Open company profile"><span class="company-header-avatar"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company avatar"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></span></a><a href="logout.php" class="btn btn-ghost btn-sm">Log Out</a></div>
  </div>
</header>

<main>
<div class="company-dashboard">

  <!-- Welcome Banner -->
  <section class="dashboard-hero">
    <div class="container dashboard-hero-inner">
      <div>
        <div class="welcome-label"><span aria-hidden="true">✦</span><span>Ufuq Company Space</span></div>
        <?php if ($activeTab === 'overview'): ?>
          <h1>Welcome back, <?= e($company['CompanyName']) ?>!</h1>
          <p>Publish opportunities, review applicants, and track how students engage with your programs.</p>
        <?php else: ?>
          <h1><?= e($heroTitles[$activeTab][0]) ?></h1>
          <p><?= e($heroTitles[$activeTab][1]) ?></p>
        <?php endif; ?>
      </div>
      <div class="hero-icon" aria-hidden="true">✦</div>
    </div>
  </section>

  <div class="container dashboard-content">

    <?php if ($success !== ''): ?><div class="company-toast" id="companyToast" role="status" aria-live="polite"><span class="company-toast-icon" aria-hidden="true">✓</span><span><?= e($success) ?></span><button type="button" class="company-toast-close" aria-label="Close">&times;</button></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="company-alert error" role="alert"><span aria-hidden="true">!</span><span><?= e($error) ?></span></div><?php endif; ?>

    <!-- Overview -->
    <section class="tab-panel <?= $activeTab === 'overview' ? 'active' : '' ?>" id="tab-overview">

      <div class="stat-grid" aria-label="Dashboard overview">
        <a href="dashboard-company.php?tab=opportunities" class="dashboard-card stat-card">
          <div class="stat-top"><span class="stat-title">Open Opportunities</span><span class="stat-icon" aria-hidden="true"><?= icon('training', 22) ?></span></div>
          <div><span class="stat-number"><?= $openCount ?></span><span class="stat-note">Currently accepting applications.</span></div>
        </a>
        <a href="dashboard-company.php?tab=applicants" class="dashboard-card stat-card">
          <div class="stat-top"><span class="stat-title">Total Applicants</span><span class="stat-icon" aria-hidden="true"><?= icon('userMale', 22) ?></span></div>
          <div><span class="stat-number"><?= $totalApplicants ?></span><span class="stat-note">Across all your opportunities.</span></div>
        </a>
        <a href="dashboard-company.php?tab=opportunities" class="dashboard-card stat-card">
          <div class="stat-top"><span class="stat-title">Total Views</span><span class="stat-icon" aria-hidden="true"><?= icon('goal', 22) ?></span></div>
          <div><span class="stat-number"><?= (int)$totalViews ?></span><span class="stat-note">Visits to your opportunity pages.</span></div>
        </a>
        <a href="dashboard-company.php?tab=reviews" class="dashboard-card stat-card">
          <div class="stat-top"><span class="stat-title">Average Rating</span><span class="stat-icon" aria-hidden="true"><?= icon('favorite', 22) ?></span></div>
          <div><span class="stat-number"><?= $averageRating !== null ? number_format((float)$averageRating, 1) : '&mdash;' ?></span><span class="stat-note"><?= $reviewCount ?> <?= $reviewCount === 1 ? 'review' : 'reviews' ?> so far.</span></div>
        </a>
      </div>

      <div class="dashboard-section">
        <div class="card-heading-row">
          <div>
            <h2 class="section-heading" style="margin-bottom:7px">Recent Applicants</h2>
            <p class="section-description" style="margin:0">The latest students who applied to your opportunities.</p>
          </div>
          <a href="dashboard-company.php?tab=applicants" class="dashboard-link">View all &rarr;</a>
        </div>
        <?php if (!$recentApplicants): ?>
          <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('userMale', 30, 'icon-muted') ?></div><h3>No applicants yet</h3><p>Applicants will appear here when students apply to your opportunities.</p></div>
        <?php else: ?>
          <div class="company-table-wrap"><table class="data-table"><thead><tr><th>Applicant</th><th>Opportunity</th><th>Applied</th></tr></thead><tbody>
            <?php foreach ($recentApplicants as $a): ?><tr><td><?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? '')) ?: 'Unknown') ?></td><td><?= e($a['Title']) ?></td><td><?= display_date($a['SubmissionDate']) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Opportunities -->
    <section class="tab-panel <?= $activeTab === 'opportunities' ? 'active' : '' ?>" id="tab-opportunities">
      <div class="card-heading-row">
        <div>
          <h2 class="section-heading" style="margin-bottom:7px">My Opportunities</h2>
          <p class="section-description" style="margin:0">Training programs and courses you've published.</p>
        </div>
        <a href="opportunity-form.php" class="btn btn-primary"><?= icon('add') ?> Add Opportunity</a>
      </div>
      <?php if (!$opportunities): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('training', 30, 'icon-muted') ?></div><h3>No opportunities yet</h3><p>Click "Add Opportunity" to publish your first training program or course.</p></div>
      <?php else: ?>
        <div class="company-table-wrap"><table class="data-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Views</th><th>Favorites</th><th>Application Method</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= e($o['Type'] ?? '—') ?></td><td><span class="badge <?= ($o['Status'] ?? '') === 'Open' ? 'badge-success' : 'badge-neutral' ?>"><?= e($o['Status'] ?? '—') ?></span></td><td><strong><?= (int)($o['ViewsCount'] ?? 0) ?></strong></td><td><strong><?= (int)($o['FavouriteCount'] ?? 0) ?></strong></td><td><?= e($o['ApplicationMethod'] ?? '—') ?></td><td><div class="company-actions"><a href="opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>" class="btn btn-ghost btn-sm" title="Edit"><?= icon('edit') ?></a><form method="post" class="company-inline-form" onsubmit="return confirm('Delete this opportunity? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_opportunity"><input type="hidden" name="tab" value="opportunities"><input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Delete"><?= icon('delete', 18, 'icon-danger') ?></button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>

    <!-- Applicants -->
    <section class="tab-panel <?= $activeTab === 'applicants' ? 'active' : '' ?>" id="tab-applicants">
      <div class="applicants-toolbar">
        <button type="button" class="btn btn-outline btn-sm filter-toggle" id="filterToggle" aria-controls="applicantFilterPanel" aria-expanded="true">
          <span class="filter-toggle-text">Hide Filters</span>
          <?php if ($activeFilterCount > 0): ?><span class="filter-toggle-count" title="Active filters"><?= $activeFilterCount ?></span><?php endif; ?>
        </button>
        <p class="section-description">Applicants are grouped under each opportunity. Use the filters to find candidates by skills, qualifications, experience, field of study, or application status.</p>
      </div>

      <div class="applicants-layout" id="applicantsLayout">

      <!-- Filter sidebar (left) -->
      <form method="get" class="applicant-filter-panel" id="applicantFilterPanel">
        <input type="hidden" name="tab" value="applicants">
        <h3 class="applicant-filter-title">Filter Applicants</h3>
        <div class="applicant-filter-field">
          <label for="opportunity_filter">Opportunity</label>
          <select id="opportunity_filter" name="opportunity_filter" class="form-control">
            <option value="0">All Opportunities</option>
            <?php foreach ($opportunities as $filterOpportunity): ?>
              <option value="<?= (int)$filterOpportunity['OpportunityID'] ?>" <?= $selectedOpportunity === (int)$filterOpportunity['OpportunityID'] ? 'selected' : '' ?>><?= e($filterOpportunity['Title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="applicant-filter-field">
          <label for="status_filter">Application Status</label>
          <select id="status_filter" name="status_filter" class="form-control">
            <option value="">All Statuses</option>
            <?php foreach ($validApplicantStatuses as $filterStatus): ?>
              <option value="<?= e($filterStatus) ?>" <?= $selectedStatus === $filterStatus ? 'selected' : '' ?>><?= e($filterStatus) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= lookup_box('skill_filter', 'Skills', 'Search skills...', $skillOptions, $skillFilter) ?>
        <?= lookup_box('qualification_filter', 'Qualifications', 'Search qualifications...', $qualificationOptions, $qualificationFilter) ?>
        <?= lookup_box('experience_filter', 'Experience', 'Search experience...', $experienceOptions, $experienceFilter) ?>
        <div class="applicant-filter-field">
          <label for="study_filter">Field of Study</label>
          <input id="study_filter" name="study_filter" class="form-control" list="studyOptions" autocomplete="off" value="<?= e($studyFilter) ?>" placeholder="Type or choose a field">
          <datalist id="studyOptions">
            <?php foreach ($studyOptions as $studyOption): ?><option value="<?= e($studyOption) ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
        <div class="applicant-filter-actions"><button type="submit" class="btn btn-primary">Apply</button><a href="dashboard-company.php?tab=applicants" class="btn btn-ghost">Reset</a></div>
        <p class="applicant-filter-note">When several skills, qualifications or experiences are selected, only applicants who have all of them are shown.</p>
      </form>

      <div class="applicants-results">
      <?php if (!$opportunities): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('training', 30, 'icon-muted') ?></div><h3>No opportunities yet</h3><p>Publish an opportunity first. Applicants will appear under each opportunity when they apply through Ufuq.</p><a href="opportunity-form.php" class="btn btn-primary">Add Opportunity</a></div>
      <?php elseif (!$applicantsForDisplay && $hasApplicantFilters): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('userMale', 30, 'icon-muted') ?></div><h3>No applicants found</h3><p>Try changing the filters, or check back when students apply.</p></div>
      <?php else: ?>
        <?php foreach ($opportunities as $opportunity): ?>
          <?php
            $opportunityId = (int)$opportunity['OpportunityID'];
            if ($selectedOpportunity && $selectedOpportunity !== $opportunityId) continue;
            $opportunityApplicants = $applicantsByOpportunity[$opportunityId] ?? [];
            if ($hasApplicantFilters && !$opportunityApplicants) continue;
          ?>
          <article class="applicant-opportunity-card">
            <div class="applicant-opportunity-heading">
              <div><h3><?= e($opportunity['Title']) ?></h3><p><?= e($opportunity['Type'] ?? 'Training opportunity') ?></p></div>
              <span class="applicant-count"><?= count($opportunityApplicants) ?> <?= count($opportunityApplicants) === 1 ? 'Applicant' : 'Applicants' ?></span>
            </div>
            <div class="company-table-wrap"><table class="data-table"><thead><tr><th>Applicant</th><th>Applied On</th><th>Status</th></tr></thead><tbody>
              <?php if (!$opportunityApplicants): ?>
                <tr><td colspan="3" class="text-muted">No applicants for this opportunity yet.</td></tr>
              <?php else: foreach ($opportunityApplicants as $a): ?>
                <tr>
                  <td>
                    <strong><?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? '')) ?: 'Unknown') ?></strong>
                    <?php $appStudy = applicant_profile_value($a, ['FieldOfStudy']); ?>
                    <?php if ($appStudy !== ''): ?><div class="company-help"><?= e($appStudy) ?></div><?php endif; ?>
                    <a href="applicant-profile.php?id=<?= (int)$a['UserID'] ?>&amp;opportunity=<?= (int)$a['OpportunityID'] ?>" class="dashboard-link applicant-view-link">View Profile &rarr;</a>
                  </td>
                  <td><?= display_date($a['SubmissionDate']) ?></td>
                  <td>
                    <form method="post" class="company-actions applicant-status-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="update_application_status">
                      <input type="hidden" name="tab" value="applicants">
                      <input type="hidden" name="applicant_id" value="<?= (int)$a['UserID'] ?>">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$a['OpportunityID'] ?>">
                      <select name="application_status" class="form-control status-select" aria-label="Application status">
                        <?php foreach ($validApplicantStatuses as $st): ?>
                          <option value="<?= e($st) ?>" <?= ($a['ApplicationStatus'] ?? '') === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-primary btn-sm">Update</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody></table></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
      </div>
    </section>

    <!-- Reviews -->
    <section class="tab-panel <?= $activeTab === 'reviews' ? 'active' : '' ?>" id="tab-reviews">
      <div class="card-heading-row">
        <p class="section-description" style="margin:0">What students are saying about your opportunities.</p>
        <?php if ($averageRating !== null): ?><span class="rating-pill">★ <?= number_format((float)$averageRating, 1) ?> / 5 (<?= $reviewCount ?>)</span><?php endif; ?>
      </div>
      <?php if (!$reviews): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('favorite', 30, 'icon-muted') ?></div><h3>No reviews yet</h3><p>Student reviews of your opportunities will appear here.</p></div>
      <?php else: ?>
        <?php foreach ($reviews as $r): ?>
          <div class="dashboard-card review-card">
            <div class="card-heading-row" style="margin-bottom:6px">
              <strong><?= e(trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? '')) ?: 'A user') ?></strong>
              <span class="rating-stars"><?= str_repeat('★', max(0, min(5, (int)$r['Rating']))) . str_repeat('☆', 5 - max(0, min(5, (int)$r['Rating']))) ?></span>
            </div>
            <div class="company-help">For: <?= e($r['Title']) ?> · <?= display_date($r['CreatedAt']) ?></div>
            <?php if (!empty($r['ReviewText'])): ?><p style="margin:8px 0 0;line-height:1.7"><?= nl2br(e($r['ReviewText'])) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <!-- Profile -->
    <section class="tab-panel <?= $activeTab === 'profile' ? 'active' : '' ?>" id="tab-profile">

      <div class="profile-identity">
        <div class="profile-avatar-wrap">
          <div class="profile-avatar"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company logo"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></div>
          <button type="button" class="profile-avatar-upload" title="Change company logo" aria-label="Change company logo" onclick="document.getElementById('logoInput').click()"><?= icon('upload', 15) ?></button>
        </div>
        <div class="profile-identity-details">
          <div class="profile-name-row">
            <h2><?= e($company['CompanyName']) ?></h2>
            <button type="button" class="profile-edit-btn" onclick="toggleProfileForm('nameForm')"><?= icon('edit', 14) ?> Edit</button>
          </div>
          <div class="profile-subtitle"><?= e($company['IndustrySector'] ?: 'Industry not specified') ?></div>
          <div class="profile-meta">CR Number: <?= e($company['CrNumber']) ?> &middot; <span class="profile-status <?= $company['Status'] === 'Verified' ? 'is-verified' : ($company['Status'] === 'Rejected' ? 'is-rejected' : '') ?>"><?= e($company['Status']) ?></span></div>
        </div>
      </div>

      <form id="logoForm" method="post" enctype="multipart/form-data" class="profile-hidden">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="company_logo"><input type="hidden" name="tab" value="profile">
        <input type="file" id="logoInput" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg" onchange="if(this.files.length){this.form.submit();}">
      </form>

      <section class="profile-section profile-edit-panel profile-hidden" id="nameForm">
        <div class="profile-section-header"><h2>Edit Company Name</h2></div>
        <form method="post" class="profile-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="company_name"><input type="hidden" name="tab" value="profile">
          <div class="form-group"><label class="form-label" for="company_name">Company Name</label><input class="form-control" id="company_name" name="company_name" required maxlength="100" value="<?= e($company['CompanyName']) ?>"></div>
          <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('nameForm')">Cancel</button></div>
        </form>
      </section>

      <section class="profile-section">
        <div class="profile-section-header">
          <h2>Contact Information</h2>
          <button type="button" class="profile-edit-btn" onclick="toggleProfileForm('contactForm')"><?= icon('edit', 14) ?> Edit</button>
        </div>
        <div class="profile-view-grid">
          <div class="profile-field"><div class="profile-field-label">Phone Number</div><div class="profile-field-value"><?= e($company['Phone'] ?: '—') ?></div></div>
          <div class="profile-field"><div class="profile-field-label">Official Email</div><div class="profile-field-value"><?= e($company['Email'] ?: '—') ?></div></div>
          <div class="profile-field"><div class="profile-field-label">Password</div><div class="profile-field-value">••••••••</div></div>
        </div>
        <div id="contactForm" class="profile-edit-panel profile-hidden">
          <form method="post" class="profile-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="contact"><input type="hidden" name="tab" value="profile">
            <div class="profile-view-grid">
              <div class="form-group"><label class="form-label" for="phone">Phone Number</label><input class="form-control" type="tel" id="phone" name="phone" required maxlength="10" pattern="(05[0-9]{8}|011[0-9]{7})" value="<?= e($company['Phone'] ?? '') ?>"><small class="company-help">Mobile (05XXXXXXXX) or landline (011XXXXXXX).</small></div>
              <div class="form-group"><label class="form-label" for="email">Official Email</label><input class="form-control" type="email" id="email" name="email" required maxlength="100" value="<?= e($company['Email']) ?>"></div>
              <div class="form-group"><label class="form-label" for="password">New Password</label><input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password"><small class="company-help">At least 8 characters. Your password is securely hashed before storage.</small></div>
            </div>
            <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('contactForm')">Cancel</button></div>
          </form>
        </div>
      </section>

      <section class="profile-section">
        <div class="profile-section-header">
          <h2>Company Details</h2>
          <button type="button" class="profile-edit-btn" onclick="toggleProfileForm('detailsForm')"><?= icon('edit', 14) ?> Edit</button>
        </div>
        <div class="profile-view-grid">
          <div class="profile-field"><div class="profile-field-label">Industry Sector</div><div class="profile-field-value"><?= e($company['IndustrySector'] ?: '—') ?></div></div>
          <div class="profile-field"><div class="profile-field-label">Location</div><div class="profile-field-value"><?= e($company['Location'] ?: '—') ?></div></div>
          <div class="profile-field is-wide"><div class="profile-field-label">Website</div><div class="profile-field-value"><?php if (!empty($company['WebsiteURL'])): ?><a href="<?= e($company['WebsiteURL']) ?>" target="_blank" rel="noopener"><?= e($company['WebsiteURL']) ?></a><?php else: ?>—<?php endif; ?></div></div>
          <div class="profile-field is-wide"><div class="profile-field-label">Description</div><div class="profile-field-value"><?= e($company['Description'] ?: '—') ?></div></div>
        </div>
        <div id="detailsForm" class="profile-edit-panel profile-hidden">
          <form method="post" class="profile-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="details"><input type="hidden" name="tab" value="profile">
            <div class="profile-view-grid">
              <div class="form-group"><label class="form-label" for="industry">Industry Sector</label><select class="form-control" id="industry" name="industry" required><option value="">Select industry</option><?php foreach ($industryOptions as $industry): ?><option value="<?= e($industry) ?>" <?= ($company['IndustrySector'] ?? '') === $industry ? 'selected' : '' ?>><?= e($industry) ?></option><?php endforeach; ?><?php if (!empty($company['IndustrySector']) && !in_array($company['IndustrySector'], $industryOptions, true)): ?><option selected value="<?= e($company['IndustrySector']) ?>"><?= e($company['IndustrySector']) ?></option><?php endif; ?></select></div>
              <div class="form-group"><label class="form-label" for="location">Location</label><select class="form-control" id="location" name="location" required><option value="">Select location</option><?php foreach ($locationOptions as $city): ?><option value="<?= e($city) ?>" <?= ($company['Location'] ?? '') === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?><?php if (!empty($company['Location']) && !in_array($company['Location'], $locationOptions, true)): ?><option selected value="<?= e($company['Location']) ?>"><?= e($company['Location']) ?></option><?php endif; ?></select></div>
              <div class="form-group is-wide"><label class="form-label" for="website">Website URL</label><input class="form-control" type="url" id="website" name="website" maxlength="255" placeholder="https://example.com" value="<?= e($company['WebsiteURL'] ?? '') ?>"></div>
              <div class="form-group is-wide"><label class="form-label" for="description">Company Description</label><textarea class="form-control" id="description" name="description" rows="4" required minlength="15" maxlength="500"><?= e($company['Description'] ?? '') ?></textarea><small class="company-help">15&ndash;500 characters.</small></div>
            </div>
            <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('detailsForm')">Cancel</button></div>
          </form>
        </div>
      </section>

    </section>

  </div>
</div>
</main>

<footer class="site-footer"><div class="container"><div class="footer-bottom"><span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span></div></div></footer>
<script>
/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('companyToast');
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
    toast.querySelector('.company-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();
function toggleProfileForm(id) {
  const target = document.getElementById(id);
  if (!target) return;
  const willOpen = target.classList.contains('profile-hidden');
  document.querySelectorAll('#tab-profile .profile-edit-panel').forEach(function (panel) { panel.classList.add('profile-hidden'); });
  if (willOpen) {
    target.classList.remove('profile-hidden');
    target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

/* Applicants: show / hide the filter sidebar (remembered in this browser). */
(function () {
  const layout = document.getElementById('applicantsLayout');
  const btn = document.getElementById('filterToggle');
  if (!layout || !btn) return;
  const text = btn.querySelector('.filter-toggle-text');
  function setHidden(hidden) {
    layout.classList.toggle('filters-hidden', hidden);
    btn.setAttribute('aria-expanded', String(!hidden));
    text.textContent = hidden ? 'Show Filters' : 'Hide Filters';
    try { localStorage.setItem('ufuqApplicantFiltersHidden', hidden ? '1' : '0'); } catch (e) {}
  }
  let hidden = false;
  try { hidden = localStorage.getItem('ufuqApplicantFiltersHidden') === '1'; } catch (e) {}
  setHidden(hidden);
  btn.addEventListener('click', function () { setHidden(!layout.classList.contains('filters-hidden')); });
})();

/* Checkbox list boxes (skills / qualifications / experience): search and selected count. */
document.querySelectorAll('[data-lookup-box]').forEach(function (field) {
  const search = field.querySelector('.lookup-search');
  const options = field.querySelectorAll('.lookup-option');
  const boxes = field.querySelectorAll('input[type="checkbox"]');
  const empty = field.querySelector('.lookup-empty');
  const countEl = field.querySelector('.lookup-count');
  const header = field.querySelector('.lookup-header');
  const box = field.querySelector('.lookup-box');

  /* Triangle: open / close this category's list. */
  header.addEventListener('click', function () {
    const willOpen = box.hidden;
    box.hidden = !willOpen;
    field.classList.toggle('is-open', willOpen);
    header.setAttribute('aria-expanded', String(willOpen));
    if (willOpen) search.focus();
  });

  function updateCount() {
    const n = Array.from(boxes).filter(function (b) { return b.checked; }).length;
    countEl.textContent = n ? '(' + n + ' selected)' : '';
  }

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
  field.addEventListener('change', updateCount);
  updateCount();
});
</script>
</body>
</html>
