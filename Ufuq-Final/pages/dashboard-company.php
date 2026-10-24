<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
// Load shared dropdown options.
require_once __DIR__ . '/../includes/lists.php';

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
$fieldError = null;

function company_redirect(string $tab, string $message = ''): never {
    $url = 'dashboard-company.php?tab=' . rawurlencode($tab);
    if ($message !== '') $url .= '&msg=' . rawurlencode($message);
    header('Location: ' . $url);
    exit;
}

if (isset($_GET['msg'])) {
    $messages = [
        'saved'   => 'Company profile updated successfully.',
        'password' => 'Password changed successfully.',
        'added'   => 'Opportunity added successfully.',
        'updated' => 'Opportunity updated successfully.',
        'deleted' => 'Opportunity deleted successfully.',
        'closed'  => 'Applications for this opportunity are now closed.',
        'reopened'=> 'The opportunity is open for applications again.',
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

        /* Close Opportunity: no more applications (User Story 28). Reopen is allowed while the deadline has not passed. */
        if ($action === 'close_opportunity' || $action === 'reopen_opportunity') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            if ($action === 'reopen_opportunity') {
                $check = $pdo->prepare('SELECT ApplicationDeadLine FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
                $check->execute([$oppId, $companyId]);
                $deadline = $check->fetchColumn();
                if ($deadline !== false && $deadline !== null && $deadline < date('Y-m-d')) {
                    throw new RuntimeException('The application deadline has passed. Edit the opportunity and set a new deadline before reopening it.');
                }
            }
            $stmt = $pdo->prepare('UPDATE opportunity SET Status = ? WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
            $stmt->execute([$action === 'close_opportunity' ? 'Closed' : 'Open', $oppId, $companyId]);
            company_redirect('opportunities', $action === 'close_opportunity' ? 'closed' : 'reopened');
        }

        if ($action === 'update_application_status') {
            $oppId = (int)($_POST['opportunity_id'] ?? 0);
            $userId = (int)($_POST['applicant_id'] ?? 0);
            $status = trim($_POST['application_status'] ?? '');
            if (!in_array($status, $validApplicantStatuses, true)) throw new RuntimeException('Invalid application status.');
            $check = $pdo->prepare('SELECT 1 FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
            $check->execute([$oppId, $companyId]);
            if (!$check->fetchColumn()) throw new RuntimeException('You cannot update this application.');
            $before = $pdo->prepare('SELECT ApplicationStatus FROM application WHERE UserID = ? AND OpportunityID = ?');
            $before->execute([$userId, $oppId]);
            $oldStatus = $before->fetchColumn();

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE application SET ApplicationStatus = ? WHERE UserID = ? AND OpportunityID = ?');
            $update->execute([$status, $userId, $oppId]);

            $pdo->commit();

            /* User Story 37: when the status changes to Accepted or Rejected, the user gets an
               in-system notification with the opportunity title and the new status.
               After acceptance the company contacts the applicant outside Ufuq, using the email shown on the applicant list and profile. */
            if (in_array($status, ['Accepted', 'Rejected'], true) && $oldStatus !== false && $oldStatus !== $status) {
                $info = $pdo->prepare('SELECT o.Title, c.CompanyName FROM opportunity o JOIN company c ON c.CompanyID = o.CreatedByCompanyID WHERE o.OpportunityID = ?');
                $info->execute([$oppId]);
                $infoRow = $info->fetch(PDO::FETCH_ASSOC) ?: ['Title' => 'the opportunity', 'CompanyName' => 'the company'];
                $message = $status === 'Accepted'
                    ? 'Your application for "' . $infoRow['Title'] . '" has been accepted by ' . $infoRow['CompanyName'] . '. The company will contact you by email about the next steps.'
                    : 'Your application for "' . $infoRow['Title'] . '" has been rejected by ' . $infoRow['CompanyName'] . '.';
                notify_users($pdo, [$userId], 'ApplicationUpdate', $oppId, $message);
            }
            company_redirect('applicants', 'status');
        }

        /* ----- Company profile (each section saves on its own) ----- */
        if ($action === 'company_name') {
            $name = trim($_POST['company_name'] ?? '');
            if ($name === '') throw new FieldError('nameForm', 'company_name', 'This field is required.');
            if (mb_strlen($name) > 100) throw new FieldError('nameForm', 'company_name', 'Please enter a valid company name (up to 100 characters).');
            $stmt = $pdo->prepare('UPDATE company SET CompanyName = ? WHERE CompanyID = ?');
            $stmt->execute([$name, $companyId]);
            company_redirect('profile', 'saved');
        }

        if ($action === 'company_logo') {
            if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) throw new FieldError('logoPanel', 'logoInput', 'Please choose a company logo.');
            $file = $_FILES['logo'];
            if ($file['size'] > 3 * 1024 * 1024) throw new FieldError('logoPanel', 'logoInput', 'Logo must be 3 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowedLogo = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowedLogo[$mime])) throw new FieldError('logoPanel', 'logoInput', 'Please upload a PNG, JPG or JPEG image.');
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

            if ($phone === '') throw new FieldError('contactForm', 'phone', 'This field is required.');
            if ($email === '') throw new FieldError('contactForm', 'email', 'This field is required.');
            // Matches chk_company_phone in the database
            if (!preg_match('/^(05[0-9]{8}|011[0-9]{7})$/', $phone)) {
                throw new FieldError('contactForm', 'phone', 'Please enter a valid phone number (05XXXXXXXX or 011XXXXXXX).');
            }
            if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new FieldError('contactForm', 'email', 'Please enter a valid email address.');
            }

            $emailCheck = $pdo->prepare('SELECT MemberID FROM member WHERE Email = ? AND MemberID <> ?');
            $emailCheck->execute([$email, $companyId]);
            if ($emailCheck->fetch()) throw new FieldError('contactForm', 'email', 'Email already registered');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE company SET Phone = ? WHERE CompanyID = ?');
            $stmt->execute([$phone, $companyId]);
            $stmt = $pdo->prepare('UPDATE member SET Email = ? WHERE MemberID = ? AND Role = \'Company\'');
            $stmt->execute([$email, $companyId]);
            $pdo->commit();
            company_redirect('profile', 'saved');
        }

        /* Change password: current password, then the new password (own section). */
        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');

            if ($current === '') throw new FieldError('passwordForm', 'current_password', 'This field is required.');
            if ($new === '') throw new FieldError('passwordForm', 'new_password', 'This field is required.');

            $stmt = $pdo->prepare('SELECT Password FROM member WHERE MemberID = ?');
            $stmt->execute([$companyId]);
            if (!password_verify($current, (string)$stmt->fetchColumn())) throw new FieldError('passwordForm', 'current_password', 'Current password is incorrect.');
            if (!is_strong_password($new)) throw new FieldError('passwordForm', 'new_password', PW_MSG);
            if ($new === $current) throw new FieldError('passwordForm', 'new_password', 'New password must be different from the current password.');

            $stmt = $pdo->prepare('UPDATE member SET Password = ? WHERE MemberID = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $companyId]);
            send_password_changed_email($pdo, $companyId);
            company_redirect('profile', 'password');
        }

        if ($action === 'details') {
            $industry = trim($_POST['industry'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $website = trim($_POST['website'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($industry === '') throw new FieldError('detailsForm', 'industry', 'This field is required.');
            if ($location === '') throw new FieldError('detailsForm', 'location', 'This field is required.');
            if ($description === '') throw new FieldError('detailsForm', 'description', 'This field is required.');
            if ($location === '') throw new FieldError('detailsForm', 'location', 'Please select a location.');
            // Matches chk_company_desc in the database
            if (mb_strlen($description) < 15) throw new FieldError('detailsForm', 'description', 'Company description must be at least 15 characters.');
            if (mb_strlen($description) > 500) throw new FieldError('detailsForm', 'description', 'Company description must be 500 characters or fewer.');
            if ($website !== '' && (strlen($website) > 255 || !filter_var($website, FILTER_VALIDATE_URL))) throw new FieldError('detailsForm', 'website', 'Please enter a valid website URL.');

            $stmt = $pdo->prepare('UPDATE company SET IndustrySector = ?, Location = ?, WebsiteURL = ?, Description = ? WHERE CompanyID = ?');
            $stmt->execute([$industry, $location, $website !== '' ? $website : null, $description, $companyId]);
            company_redirect('profile', 'saved');
        }
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $ex instanceof PDOException ? 'Unable to save changes. Please check the information and try again.' : $ex->getMessage();
    if ($ex instanceof FieldError) {
        /* Shown under the field; the form reopens with what the company typed (no passwords). */
        $old = array_filter($_POST, fn($v, $k) => is_string($v) && !in_array($k, ['csrf', 'action', 'tab', 'current_password', 'new_password'], true), ARRAY_FILTER_USE_BOTH);
        $fieldError = ['form' => $ex->form, 'field' => $ex->field, 'message' => $error, 'old' => $old];
        $error = '';
    }
    /* Stay on the tab the form was sent from, so the error is shown next to it. */
    if (isset($tabAfter)) $activeTab = $tabAfter;
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
    (SELECT m.Email FROM member m WHERE m.MemberID = a.UserID) AS Email,
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
    (SELECT GROUP_CONCAT(ue2.ExperienceID) FROM userexperience ue2 WHERE ue2.UserID = a.UserID) AS ExperienceIDs,
    (SELECT GROUP_CONCAT(DISTINCT e3.ExperienceName ORDER BY e3.ExperienceName SEPARATOR ', ')
       FROM userexperience ue3 JOIN experience e3 ON e3.ExperienceID = ue3.ExperienceID
       WHERE ue3.UserID = a.UserID) AS ExperienceNames
    FROM application a
    JOIN opportunity o ON o.OpportunityID = a.OpportunityID
    LEFT JOIN `user` u ON u.UserID = a.UserID
    WHERE o.CreatedByCompanyID = ?
    ORDER BY a.SubmissionDate DESC, a.OpportunityID DESC");
$stmt->execute([$companyId]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Applicants table (columns): each applicant's skills, qualifications and experience as [id, name],
   and what each opportunity requires, so the matching items can be marked ✓. */
function company_group_rows(PDO $pdo, string $sql, array $params): array {
    $out = [];
    if (!$params) return $out;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$key, $id, $name]) {
        $out[(int)$key][] = ['id' => (int)$id, 'name' => (string)$name];
    }
    return $out;
}
$applicantIds = array_values(array_unique(array_map(static fn($a) => (int)$a['UserID'], $applicants)));
$inUsers = implode(',', array_fill(0, count($applicantIds), '?'));
$userSkillItems = company_group_rows($pdo, "SELECT us.UserID, s.SkillID, s.SkillName FROM userskill us JOIN skill s ON s.SkillID = us.SkillID WHERE us.UserID IN ($inUsers) ORDER BY s.SkillName", $applicantIds);
$userQualItems = company_group_rows($pdo, "SELECT uq.UserID, q.QualificationID, q.QualificationName FROM userqualification uq JOIN qualification q ON q.QualificationID = uq.QualificationID WHERE uq.UserID IN ($inUsers) ORDER BY q.QualificationName", $applicantIds);
$userExpItems = company_group_rows($pdo, "SELECT ue.UserID, e.ExperienceID, e.ExperienceName FROM userexperience ue JOIN experience e ON e.ExperienceID = ue.ExperienceID WHERE ue.UserID IN ($inUsers) ORDER BY ue.StartDate DESC", $applicantIds);
$userDegrees = company_group_rows($pdo, "SELECT st.UserID, 0, st.DegreeLevel FROM userstudy st WHERE st.UserID IN ($inUsers) AND st.DegreeLevel <> '' ORDER BY st.GraduationYear DESC", $applicantIds);

$companyOppIds = array_map(static fn($o) => (int)$o['OpportunityID'], $opportunities);
$inOpps = implode(',', array_fill(0, count($companyOppIds), '?'));
$oppSkillReq = company_group_rows($pdo, "SELECT os.OpportunityID, s.SkillID, s.SkillName FROM opportunityskill os JOIN skill s ON s.SkillID = os.SkillID WHERE os.OpportunityID IN ($inOpps) ORDER BY s.SkillName", $companyOppIds);
$oppQualReq = company_group_rows($pdo, "SELECT oq.OpportunityID, q.QualificationID, q.QualificationName FROM opportunityqualification oq JOIN qualification q ON q.QualificationID = oq.QualificationID WHERE oq.OpportunityID IN ($inOpps) ORDER BY q.QualificationName", $companyOppIds);
$oppExpReq = company_group_rows($pdo, "SELECT oe.OpportunityID, e.ExperienceID, e.ExperienceName FROM opportunityexperience oe JOIN experience e ON e.ExperienceID = oe.ExperienceID WHERE oe.OpportunityID IN ($inOpps) ORDER BY e.ExperienceName", $companyOppIds);

/* One column of the applicants table: items required by the opportunity are green with ✓. */
function applicant_item_list(array $items, array $required): string {
    if (!$items) return '<span class="item-none">None added</span>';
    $requiredIds = array_map(static fn($r) => $r['id'], $required);
    $html = '<ul class="item-list">';
    foreach ($items as $item) {
        $match = $item['id'] > 0 && in_array($item['id'], $requiredIds, true);
        $html .= '<li class="' . ($match ? 'is-match' : '') . '">' . ($match ? '✓ ' : '') . e($item['name']) . '</li>';
    }
    return $html . '</ul>';
}

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
    $selectedKeys = array_map('strval', $selectedIds);
    foreach ($options as $optId => $optName) {
        if (in_array((string)$optId, $selectedKeys, true)) $checked[$optId] = $optName; else $unchecked[$optId] = $optName;
    }
    $hasSearch = count($options) > 5;
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
            <?php if ($hasSearch): ?><input type="text" class="form-control lookup-search" placeholder="<?= e($searchPlaceholder) ?>" autocomplete="off" aria-label="<?= e($searchPlaceholder) ?>"><?php endif; ?>
            <div class="lookup-list <?= $hasSearch ? '' : 'no-search' ?>" role="group" aria-label="<?= e($label) ?>">
                <?php foreach ($checked + $unchecked as $optId => $optName): ?>
                    <label class="lookup-option" data-text="<?= e(mb_strtolower((string)$optName, 'UTF-8')) ?>">
                        <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e((string)$optId) ?>" <?= isset($checked[$optId]) ? 'checked' : '' ?>>
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
/* Opportunity, status and field of study: one or more ticked values (any of them matches). */
$companyOpportunityIds = array_map(static fn($o) => (int)$o['OpportunityID'], $opportunities);
$opportunityFilter = array_values(array_intersect(company_filter_ids($_GET['opportunity_filter'] ?? [], array_flip($companyOpportunityIds)), $companyOpportunityIds));
$statusFilter = array_values(array_intersect(array_map('strval', (array)($_GET['status_filter'] ?? [])), $validApplicantStatuses));
$skillFilter = company_filter_ids($_GET['skill_filter'] ?? [], $skillOptions);
$qualificationFilter = company_filter_ids($_GET['qualification_filter'] ?? [], $qualificationOptions);
$experienceFilter = company_filter_ids($_GET['experience_filter'] ?? [], $experienceOptions);
$studyFilter = array_values(array_intersect(array_map('strval', (array)($_GET['study_filter'] ?? [])), $studyOptions));

$applicantsForDisplay = array_values(array_filter($applicants, static function ($a) use ($opportunityFilter, $statusFilter, $skillFilter, $qualificationFilter, $experienceFilter, $studyFilter) {
    if ($opportunityFilter && !in_array((int)$a['OpportunityID'], $opportunityFilter, true)) return false;
    if ($statusFilter && !in_array((string)($a['ApplicationStatus'] ?? ''), $statusFilter, true)) return false;
    if (!applicant_has_all($a['SkillIDs'] ?? '', $skillFilter)) return false;
    if (!applicant_has_all($a['QualificationIDs'] ?? '', $qualificationFilter)) return false;
    if (!applicant_has_all($a['ExperienceIDs'] ?? '', $experienceFilter)) return false;
    if ($studyFilter && !array_intersect(array_map('trim', explode(',', (string)($a['FieldOfStudy'] ?? ''))), $studyFilter)) return false;
    return true;
}));
$applicantsByOpportunity = [];
foreach ($applicantsForDisplay as $applicantRow) {
    $applicantsByOpportunity[(int)$applicantRow['OpportunityID']][] = $applicantRow;
}
$activeFilterCount = count($opportunityFilter) + count($statusFilter) + count($skillFilter) + count($qualificationFilter) + count($experienceFilter) + count($studyFilter);
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
// Use shared lists to keep company profile options consistent.
$industryOptions = INDUSTRIES;
$locationOptions = CITIES;

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
/* Opportunities table: Close / Reopen, Edit and Delete stay on one line, lined up in every row */
.company-dashboard .opp-actions{flex-wrap:nowrap;gap:4px}
.company-dashboard .opp-actions .company-inline-form{margin:0}
.company-dashboard .opp-status-btn{min-width:150px;white-space:nowrap;justify-content:center}
/* My Opportunities: the whole table fits the page (no left/right scrolling), centered content, small action buttons */
.company-dashboard .opp-table-wrap{overflow-x:visible}
.company-dashboard .opp-table{table-layout:fixed;width:100%}
.company-dashboard .company-table-wrap .opp-table th,.company-dashboard .company-table-wrap .opp-table td{text-align:center;padding:12px 8px;overflow-wrap:break-word}
.company-dashboard .company-table-wrap .opp-table th{white-space:normal;font-size:.78rem;line-height:1.35}
.company-dashboard .opp-table td{font-size:.87rem}
.company-dashboard .opp-table th:nth-child(1){width:17%}
.company-dashboard .opp-table th:nth-child(2){width:10%}
.company-dashboard .opp-table th:nth-child(3){width:9%}
.company-dashboard .opp-table th:nth-child(4){width:7%}
.company-dashboard .opp-table th:nth-child(5){width:9%}
.company-dashboard .opp-table th:nth-child(6){width:11%}
.company-dashboard .opp-table th:nth-child(7){width:14%}
.company-dashboard .opp-table th:nth-child(8){width:23%}
.company-dashboard .opp-table .opp-actions{justify-content:center;flex-wrap:nowrap;gap:2px}
.company-dashboard .opp-table .opp-status-btn{min-width:0;width:122px;justify-content:center;min-height:30px;padding:5px 9px;font-size:.74rem;border-radius:8px}
.company-dashboard .opp-table .opp-actions .btn-ghost{min-height:30px;padding:4px 5px}
.company-dashboard .opp-table .opp-actions .btn-ghost svg{width:16px;height:16px}
@media (max-width:900px){.company-dashboard .opp-table-wrap{overflow-x:auto}.company-dashboard .opp-table{table-layout:auto;min-width:820px}}
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
.company-dashboard .applicants-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 16px}
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
.company-dashboard .applicant-summary{margin-top:4px;font-size:.8rem;line-height:1.55;color:var(--c-muted);overflow-wrap:anywhere}
.company-dashboard .applicant-summary strong{color:var(--c-text);font-weight:650}

/* Only the filter moves left, into the empty space beside the page; the applicant cards stay in place.
   The shift is the filter width + gap (312px), or less on screens without enough space on the left. */
.company-dashboard #tab-applicants{--filter-shift:min(312px, max(0px, calc((100vw - 100%) / 2 - 16px)))}
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
  .company-dashboard .applicants-layout{margin-left:0}
}
.company-dashboard .applicant-opportunity-card{margin:0 0 22px;border:1px solid var(--c-border);border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 5px 18px rgba(40,26,70,.045);animation:companyFadeUp .35s ease both}
.company-dashboard .applicant-opportunity-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 20px;background:#f7f4fb;border-bottom:1px solid #e6deef}
.company-dashboard .applicant-opportunity-heading h3{margin:0;color:var(--c-purple);font-size:1.05rem}
.company-dashboard .applicant-opportunity-heading p{margin:5px 0 0;color:var(--c-muted);font-size:.86rem}
.company-dashboard .applicant-count{flex-shrink:0;padding:7px 11px;border-radius:999px;background:#ece6f8;color:var(--c-purple);font-size:.82rem;font-weight:700}
.company-dashboard .applicant-opportunity-card .company-table-wrap{border:0;border-radius:0;box-shadow:none}
/* Applicants of one opportunity: triangle opens / closes the table */
.company-dashboard .opp-heading-text{flex:1;min-width:0}
.company-dashboard .lookup-list.no-search{margin-top:0}
.company-dashboard .opp-toggle{display:flex;align-items:center;gap:14px;flex:1;min-width:0;padding:0;border:0;background:none;font:inherit;text-align:left;color:inherit;cursor:pointer}
.company-dashboard .opp-toggle:focus-visible{outline:2px solid var(--c-purple);outline-offset:4px;border-radius:8px}
.company-dashboard .opp-title{display:block;color:var(--c-purple);font-size:1.05rem;font-weight:700}
.company-dashboard .opp-sub{display:block;margin-top:5px;color:var(--c-muted);font-size:.86rem}
.company-dashboard .opp-caret{flex-shrink:0;width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-top:8px solid var(--c-purple);transition:transform .2s ease}
.company-dashboard .opp-caret{transform:rotate(180deg)}
.company-dashboard .applicant-opportunity-card.is-collapsed .opp-caret{transform:none}
.company-dashboard .applicant-opportunity-card.is-collapsed .applicant-opportunity-heading{border-bottom:0}
.company-dashboard .applicant-opportunity-card.is-collapsed .company-table-wrap{display:none}
/* Applicants table with columns */
.company-dashboard .applicant-opportunity-card .company-table-wrap{overflow:visible}
.company-dashboard .applicants-table{width:100%;table-layout:fixed}
.company-dashboard .applicants-table td{overflow-wrap:anywhere}
.company-dashboard .applicants-table td{vertical-align:top}
.company-dashboard .applicants-table .col-applicant{width:25%}
.company-dashboard .applicants-table .col-status{width:20%}
.company-dashboard .applicants-table th,.company-dashboard .applicants-table td{padding:13px 12px}
.company-dashboard .applicant-name{display:block;font-size:.95rem;color:var(--c-text)}
.company-dashboard .applicant-meta{display:block;margin-top:3px;font-size:.82rem;color:var(--c-muted)}
.company-dashboard .applicant-mail{display:block;margin-top:4px;font-size:.82rem;font-weight:700;color:var(--c-purple);overflow-wrap:anywhere}
.company-dashboard .item-list{display:flex;flex-direction:column;gap:5px;margin:0;padding:0;list-style:none;font-size:.86rem;line-height:1.45}
.company-dashboard .item-list li::before{content:"• ";color:#b3a9c4}
.company-dashboard .item-list li.is-match{color:#176b3a;font-weight:700}
.company-dashboard .item-list li.is-match::before{content:""}
.company-dashboard .item-none{font-size:.84rem;color:var(--c-muted)}
.company-dashboard .status-pill{display:inline-flex;padding:5px 11px;border-radius:999px;font-size:.76rem;font-weight:800}
.company-dashboard .status-pill.is-pending{background:#fff4dc;color:#8a5d12}
.company-dashboard .status-pill.is-accepted{background:#e5f5eb;color:#247244}
.company-dashboard .status-pill.is-rejected{background:#fdeaea;color:#a32e37}
.company-dashboard .applicants-table .applicant-status-form{margin-top:10px;flex-wrap:wrap;gap:6px;min-width:0}
.company-dashboard .applicants-table .applicant-status-form .status-select{min-width:0}
.company-dashboard .applicants-table .status-select{min-width:0;width:100%}
.company-dashboard .applicants-table .applicant-status-form .btn{width:100%}
/* Small screens: each applicant becomes a stacked card, still without sideways scroll */
@media(max-width:760px){
  .company-dashboard .applicants-table thead{display:none}
  .company-dashboard .applicants-table,.company-dashboard .applicants-table tbody,.company-dashboard .applicants-table tr,.company-dashboard .applicants-table td{display:block;width:100%}
  .company-dashboard .applicants-table tr{border-bottom:1px solid #eee8f4;padding:6px 0}
  .company-dashboard .applicants-table td{border:0;padding:8px 16px}
  .company-dashboard .applicants-table td[data-label]::before{content:attr(data-label);display:block;margin-bottom:4px;color:var(--c-purple);font-size:.78rem;font-weight:800}
}
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
/* Drop-down list with search (skills, experience, qualifications, ...) */
.search-select{position:relative}
.search-select > select{display:none!important}
.search-select-toggle{display:flex!important;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;cursor:pointer;background:#fff}
.search-select-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.search-select-text.is-placeholder{color:#8a8396}
.search-select-caret{flex-shrink:0;color:#8a8396;font-size:.8rem;transition:transform .18s ease}
.search-select.is-open .search-select-caret{transform:rotate(180deg)}
.search-select-panel{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:40;padding:10px;border:1px solid #e5ddef;border-radius:12px;background:#fff;box-shadow:0 12px 30px rgba(40,26,70,.14)}
.search-select-panel[hidden]{display:none!important}
.search-select-list{max-height:210px;margin-top:8px;overflow-y:auto;overscroll-behavior:contain}
.search-select-option{display:flex;align-items:center;gap:9px;padding:8px;border-radius:8px;color:#29243a;font-size:.9rem;line-height:1.5;cursor:pointer;overflow-wrap:anywhere}
.search-select-option:hover,.search-select-option.is-selected{background:#f2ecfa}
.search-select-option.is-selected{color:#281a46;font-weight:650}
.search-select-option[hidden]{display:none!important}
.search-select-option input{width:16px;height:16px;flex-shrink:0;margin:0;accent-color:#39265f;cursor:pointer}
.search-select-empty{padding:10px 8px;color:#8a8396;font-size:.85rem;text-align:center}
.search-select-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.search-select-chips:empty{display:none}
.search-select-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 11px;border:1px solid #d9cbea;border-radius:999px;background:#f2ecfa;color:#281a46;font-size:.84rem;font-weight:700}
.search-select-chip button{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;padding:0;border:0;border-radius:50%;background:none;color:#39265f;font-size:1rem;line-height:1;cursor:pointer}
.search-select-chip button:hover{color:#d94b56;background:rgba(217,75,86,.09)}
/* Field validation: red field + message under it (same as the other forms) */
.company-dashboard .form-control.invalid{border:1.5px solid #d94b56!important;background-color:#fff7f7!important;box-shadow:0 0 0 3px rgba(217,75,86,.08)}
.company-dashboard .field-error{display:none;margin-top:7px;color:#c43643;font-size:.84rem;line-height:1.5}
.company-dashboard .required-mark{color:#d94b56;font-weight:800;margin-left:2px}
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

        <a href="../index.php" class="brand" style="text-decoration:none;">
            <img
                src="../images-website/logo.png"
                alt="Ufuq"
                class="brand-mark"
                style="width:34px;height:34px;object-fit:contain;background:transparent;"
            >
            Ufuq
        </a>

        <nav class="nav-links" id="navLinks" aria-label="Company navigation">
            <a
                href="dashboard-company.php?tab=overview"
                class="<?= in_array($activeTab, ['overview', 'reviews', 'profile'], true) ? 'active' : '' ?>"
            >
                Dashboard
            </a>

            <a
                href="dashboard-company.php?tab=opportunities"
                class="<?= $activeTab === 'opportunities' ? 'active' : '' ?>"
            >
                Opportunities
            </a>

            <a
                href="dashboard-company.php?tab=applicants"
                class="<?= $activeTab === 'applicants' ? 'active' : '' ?>"
            >
                Applicants
            </a>
        </nav>

        <div class="nav-actions" style="display:flex;align-items:center;gap:10px;">

            <a
                href="dashboard-company.php?tab=profile"
                class="company-header-profile"
                title="Open company profile"
                aria-label="Open company profile"
            >
                <span class="company-header-avatar">
                    <?php if ($logoSrc !== ''): ?>
                        <img src="<?= e($logoSrc) ?>" alt="Company avatar">
                    <?php else: ?>
                        <?= e($companyInitial) ?>
                    <?php endif; ?>
                </span>
            </a>

            <a href="logout.php" class="btn btn-ghost btn-sm">
                Log Out
            </a>

            <button
                class="nav-toggle"
                id="navToggle"
                type="button"
                aria-label="Toggle menu"
                aria-controls="navLinks"
                aria-expanded="false"
            >
                <?= icon('menu', 22) ?>
            </button>

        </div>
    </div>
</header>

<script>
(function () {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('navLinks');

    if (!toggle || !nav || toggle.dataset.initialized === 'true') {
        return;
    }

    toggle.dataset.initialized = 'true';

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(isOpen));
    });

    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    document.addEventListener('click', function (event) {
        if (!nav.contains(event.target) && !toggle.contains(event.target)) {
            nav.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>


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
      <?php
        $heroIcons = [
          'opportunities' => '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8.5 7V5a1.5 1.5 0 0 1 1.5-1.5h4A1.5 1.5 0 0 1 15.5 5v2"/><path d="M3 12.5h18"/></svg>',
          'applicants'    => '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 4.9a3.2 3.2 0 0 1 0 6.2"/><path d="M17.5 14.7c2.1.6 3.5 2.5 3.5 5.3"/></svg>',
          'reviews'       => '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5.5h16v10H9l-5 4v-14Z"/><path d="M8 9.5h8"/><path d="M8 12.5h5"/></svg>',
          'profile'       => '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20.5V5a1.5 1.5 0 0 1 1.5-1.5h8A1.5 1.5 0 0 1 15 5v15.5"/><path d="M15 9.5h3.5A1.5 1.5 0 0 1 20 11v9.5"/><path d="M2.5 20.5h19"/><path d="M8 7.5h3M8 11h3M8 14.5h3"/></svg>',
        ];
      ?>
      <div class="hero-icon" aria-hidden="true"><?= $heroIcons[$activeTab] ?? '✦' ?></div>
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
        <div class="company-table-wrap opp-table-wrap"><table class="data-table opp-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Views</th><th>Favorites</th><th>Applications</th><th>Application Method</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= e($o['Type'] ?? '—') ?></td><td><span class="badge <?= ($o['Status'] ?? '') === 'Open' ? 'badge-success' : 'badge-neutral' ?>"><?= e($o['Status'] ?? '—') ?></span></td><td><strong><?= (int)($o['ViewsCount'] ?? 0) ?></strong></td><td><strong><?= (int)($o['FavouriteCount'] ?? 0) ?></strong></td><td><strong><?= (int)($o['ApplicationCount'] ?? 0) ?></strong></td><td><?= e($o['ApplicationMethod'] ?? '—') ?></td><td><div class="company-actions opp-actions"><?php $isOpenOpp = ($o['Status'] ?? '') === 'Open'; ?><form method="post" class="company-inline-form" <?= $isOpenOpp ? 'onsubmit="return confirm(\'Close applications for this opportunity? Users will no longer be able to apply.\');"' : '' ?>><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $isOpenOpp ? 'close_opportunity' : 'reopen_opportunity' ?>"><input type="hidden" name="tab" value="opportunities"><input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>"><button class="btn btn-outline btn-sm opp-status-btn" type="submit"><?= $isOpenOpp ? 'Close Opportunity' : 'Reopen' ?></button></form><a href="opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>" class="btn btn-ghost btn-sm" title="Edit"><?= icon('edit') ?></a><form method="post" class="company-inline-form" onsubmit="return confirm('Delete this opportunity? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_opportunity"><input type="hidden" name="tab" value="opportunities"><input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Delete"><?= icon('delete', 18, 'icon-danger') ?></button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </section>

    <!-- Applicants -->
    <section class="tab-panel <?= $activeTab === 'applicants' ? 'active' : '' ?>" id="tab-applicants">
      <div class="applicants-layout" id="applicantsLayout">

      <!-- Filter sidebar (left) -->
      <form method="get" class="applicant-filter-panel" id="applicantFilterPanel">
        <input type="hidden" name="tab" value="applicants">
        <h3 class="applicant-filter-title">Filter Applicants</h3>
        <?php $opportunityOptions = []; foreach ($opportunities as $fo) { $opportunityOptions[(int)$fo['OpportunityID']] = (string)$fo['Title']; } ?>
        <?= lookup_box('opportunity_filter', 'Opportunity', 'Search opportunities...', $opportunityOptions, $opportunityFilter) ?>
        <?= lookup_box('status_filter', 'Application Status', 'Search statuses...', array_combine($validApplicantStatuses, $validApplicantStatuses), $statusFilter) ?>
        <?= lookup_box('skill_filter', 'Skills', 'Search skills...', $skillOptions, $skillFilter) ?>
        <?= lookup_box('qualification_filter', 'Qualifications', 'Search qualifications...', $qualificationOptions, $qualificationFilter) ?>
        <?= lookup_box('experience_filter', 'Experience', 'Search experience...', $experienceOptions, $experienceFilter) ?>
        <?= lookup_box('study_filter', 'Field of Study', 'Search fields of study...', $studyOptions ? array_combine($studyOptions, $studyOptions) : [], $studyFilter) ?>
        <div class="applicant-filter-actions"><button type="submit" class="btn btn-primary">Apply</button><a href="dashboard-company.php?tab=applicants" class="btn btn-ghost">Reset</a></div>
        <p class="applicant-filter-note">Skills, qualifications and experience: applicants must have all the ones you tick. Opportunity, status and field of study: any of the ones you tick.</p>
      </form>

      <div class="applicants-results">
      <!-- Filters button: on the right, above the applicant counts -->
      <div class="applicants-toolbar">
        <p class="section-description">Applicants are grouped under each opportunity. Use the filters to find candidates by skills, qualifications, experience, field of study, or application status.</p>
        <button type="button" class="btn btn-outline btn-sm filter-toggle" id="filterToggle" aria-controls="applicantFilterPanel" aria-expanded="true">
          <span class="filter-toggle-text">Hide Filters</span>
          <?php if ($activeFilterCount > 0): ?><span class="filter-toggle-count" title="Active filters"><?= $activeFilterCount ?></span><?php endif; ?>
        </button>
      </div>
      <?php if (!$opportunities): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('training', 30, 'icon-muted') ?></div><h3>No opportunities yet</h3><p>Publish an opportunity first. Applicants will appear under each opportunity when they apply through Ufuq.</p><a href="opportunity-form.php" class="btn btn-primary">Add Opportunity</a></div>
      <?php elseif (!$applicantsForDisplay && $hasApplicantFilters): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('userMale', 30, 'icon-muted') ?></div><h3>No applicants found</h3><p>Try changing the filters, or check back when students apply.</p></div>
      <?php else: ?>
        <?php foreach ($opportunities as $opportunity): ?>
          <?php
            $opportunityId = (int)$opportunity['OpportunityID'];
            if ($opportunityFilter && !in_array($opportunityId, $opportunityFilter, true)) continue;
            $opportunityApplicants = $applicantsByOpportunity[$opportunityId] ?? [];
            if ($hasApplicantFilters && !$opportunityApplicants) continue;
          ?>
          <?php
            $reqSkills = $oppSkillReq[$opportunityId] ?? [];
            $reqQuals = $oppQualReq[$opportunityId] ?? [];
            $reqExps = $oppExpReq[$opportunityId] ?? [];
            $reqNames = array_map(static fn($r) => $r['name'], array_merge($reqSkills, $reqQuals, $reqExps));
          ?>
          <article class="applicant-opportunity-card" data-opp-card="<?= $opportunityId ?>">
            <div class="applicant-opportunity-heading">
              <button type="button" class="opp-toggle" aria-expanded="true" aria-controls="opp-applicants-<?= $opportunityId ?>">
                <span class="opp-heading-text">
                  <span class="opp-title"><?= e($opportunity['Title']) ?></span>
                  <span class="opp-sub"><?= e($opportunity['Type'] ?? 'Training opportunity') ?><?php if ($reqNames): ?> &middot; Requires: <?= e(implode(', ', $reqNames)) ?><?php endif; ?></span>
                </span>
                <span class="applicant-count"><?= count($opportunityApplicants) ?> <?= count($opportunityApplicants) === 1 ? 'Applicant' : 'Applicants' ?></span>
                <span class="opp-caret" aria-hidden="true"></span>
              </button>
            </div>
            <div class="company-table-wrap" id="opp-applicants-<?= $opportunityId ?>"><table class="data-table applicants-table"><thead><tr><th class="col-applicant">Applicant</th><th>Skills</th><th>Qualifications</th><th>Experience</th><th class="col-status">Status</th></tr></thead><tbody>
              <?php if (!$opportunityApplicants): ?>
                <tr><td colspan="5" class="text-muted">No applicants for this opportunity yet.</td></tr>
              <?php else: foreach ($opportunityApplicants as $a): $aid = (int)$a['UserID']; $st = (string)($a['ApplicationStatus'] ?? 'Pending'); ?>
                <tr>
                  <td>
                    <strong class="applicant-name"><?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? '')) ?: 'Unknown') ?></strong>
                    <?php $appStudy = applicant_profile_value($a, ['FieldOfStudy']); ?>
                    <?php if ($appStudy !== ''): ?><span class="applicant-meta"><?= e($appStudy) ?></span><?php endif; ?>
                    <?php if (!empty($a['Email'])): ?><a class="applicant-mail" href="mailto:<?= e($a['Email']) ?>"><?= e($a['Email']) ?></a><?php endif; ?>
                    <a href="applicant-profile.php?id=<?= $aid ?>&amp;opportunity=<?= (int)$a['OpportunityID'] ?>" class="dashboard-link applicant-view-link">View Profile &rarr;</a>
                  </td>
                  <td data-label="Skills"><?= applicant_item_list($userSkillItems[$aid] ?? [], $reqSkills) ?></td>
                  <td data-label="Qualifications"><?= applicant_item_list(array_merge($userDegrees[$aid] ?? [], $userQualItems[$aid] ?? []), $reqQuals) ?></td>
                  <td data-label="Experience"><?= applicant_item_list($userExpItems[$aid] ?? [], $reqExps) ?></td>
                  <td data-label="Status">
                    <span class="status-pill is-<?= e(strtolower($st)) ?>"><?= e($st) ?></span>
                    <form method="post" class="company-actions applicant-status-form">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="update_application_status">
                      <input type="hidden" name="tab" value="applicants">
                      <input type="hidden" name="applicant_id" value="<?= $aid ?>">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$a['OpportunityID'] ?>">
                      <select name="application_status" class="form-control status-select" aria-label="Application status of <?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? ''))) ?>">
                        <?php foreach ($validApplicantStatuses as $opt): ?>
                          <option value="<?= e($opt) ?>" <?= $st === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-primary btn-sm">Update</button>
                    </form>
                    <span class="applicant-meta">Applied <?= display_date($a['SubmissionDate']) ?></span>
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

      <section class="profile-section profile-edit-panel profile-hidden" id="logoPanel">
        <div class="profile-section-header"><h2>Change Company Logo</h2></div>
        <form id="logoForm" method="post" enctype="multipart/form-data" class="profile-form" data-validate>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="company_logo"><input type="hidden" name="tab" value="profile">
          <input type="file" id="logoInput" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg" class="profile-hidden" required onchange="previewImage(this, 'logoPanel')">
          <p class="company-help">Selected logo: <strong data-file-name></strong></p>
          <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="cancelImage('logoInput', 'logoPanel')">Cancel</button></div>
        </form>
      </section>

      <section class="profile-section profile-edit-panel profile-hidden" id="nameForm">
        <div class="profile-section-header"><h2>Edit Company Name</h2></div>
        <form method="post" class="profile-form" data-validate>
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
        </div>
        <div id="contactForm" class="profile-edit-panel profile-hidden">
          <form method="post" class="profile-form" data-validate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="contact"><input type="hidden" name="tab" value="profile">
            <div class="profile-view-grid">
              <div class="form-group"><label class="form-label" for="phone">Phone Number</label><input class="form-control" type="tel" id="phone" name="phone" required maxlength="10" pattern="(05[0-9]{8}|011[0-9]{7})" value="<?= e($company['Phone'] ?? '') ?>"><small class="company-help">Mobile (05XXXXXXXX) or landline (011XXXXXXX).</small></div>
              <div class="form-group"><label class="form-label" for="email">Official Email</label><input class="form-control" type="email" id="email" name="email" required maxlength="100" value="<?= e($company['Email']) ?>"></div>
            </div>
            <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('contactForm')">Cancel</button></div>
          </form>
        </div>
      </section>

      <section class="profile-section">
        <div class="profile-section-header">
          <h2>Password</h2>
          <button type="button" class="profile-edit-btn" onclick="toggleProfileForm('passwordForm')"><?= icon('edit', 14) ?> Change Password</button>
        </div>
        <div class="profile-view-grid">
          <div class="profile-field"><div class="profile-field-label">Password</div><div class="profile-field-value">••••••••</div></div>
        </div>
        <div id="passwordForm" class="profile-edit-panel profile-hidden">
          <form method="post" class="profile-form" data-validate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password"><input type="hidden" name="tab" value="profile">
            <div class="profile-view-grid">
              <div class="form-group"><label class="form-label" for="current_password">Current Password</label><input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
              <div class="form-group"><label class="form-label" for="new_password">New Password</label><input class="form-control" type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"><small class="company-help">At least 8 characters, including an uppercase and a lowercase letter.</small></div>
              
            </div>
            <div class="profile-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save Changes</button><button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('passwordForm')">Cancel</button></div>
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
          <form method="post" class="profile-form" data-validate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="details"><input type="hidden" name="tab" value="profile">
            <div class="profile-view-grid">
              <div class="form-group"><label class="form-label" for="industry">Industry Sector</label><select class="form-control" id="industry" name="industry" required data-search><option value="">Select industry</option><?php foreach ($industryOptions as $industry): ?><option value="<?= e($industry) ?>" <?= ($company['IndustrySector'] ?? '') === $industry ? 'selected' : '' ?>><?= e($industry) ?></option><?php endforeach; ?><?php if (!empty($company['IndustrySector']) && !in_array($company['IndustrySector'], $industryOptions, true)): ?><option selected value="<?= e($company['IndustrySector']) ?>"><?= e($company['IndustrySector']) ?></option><?php endif; ?></select></div>
              <div class="form-group"><label class="form-label" for="location">Location</label><select class="form-control" id="location" name="location" required data-search><option value="">Select location</option><?php foreach ($locationOptions as $city): ?><option value="<?= e($city) ?>" <?= ($company['Location'] ?? '') === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?><?php if (!empty($company['Location']) && !in_array($company['Location'], $locationOptions, true)): ?><option selected value="<?= e($company['Location']) ?>"><?= e($company['Location']) ?></option><?php endif; ?></select></div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

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
/* Drop-down list with search: turns <select data-search> into a list you can type in.
   One choice (experience, qualification, city, ...) or several (skills, with chips).
   The real <select> stays in the form, so saving works the same. */
function makeSearchSelect(select) {
    const multiple = select.multiple;
    const wrap = document.createElement('div');
    wrap.className = 'search-select';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);

    const firstEmpty = Array.from(select.options).find(function (o) { return o.value === ''; });
    const placeholder = select.dataset.placeholder || (firstEmpty ? firstEmpty.textContent : 'Select...');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'form-control search-select-toggle';
    toggle.setAttribute('aria-haspopup', 'listbox');
    toggle.setAttribute('aria-expanded', 'false');
    if (select.id) toggle.id = select.id + 'Toggle';
    toggle.innerHTML = '<span class="search-select-text"></span><span class="search-select-caret" aria-hidden="true">&#9662;</span>';
    wrap.appendChild(toggle);
    select._searchToggle = toggle;
    if (select.id) {
        const label = document.querySelector('label[for="' + select.id + '"]');
        if (label) label.htmlFor = toggle.id;
    }

    const panel = document.createElement('div');
    panel.className = 'search-select-panel';
    panel.hidden = true;
    panel.innerHTML = '<input type="text" class="form-control search-select-search" placeholder="Search..." autocomplete="off"><div class="search-select-list" role="listbox"></div><div class="search-select-empty" hidden>No matches found</div>';
    wrap.appendChild(panel);
    const search = panel.querySelector('.search-select-search');
    const list = panel.querySelector('.search-select-list');
    const empty = panel.querySelector('.search-select-empty');

    let chips = null;
    if (multiple) {
        chips = document.createElement('div');
        chips.className = 'search-select-chips';
        wrap.appendChild(chips);
    }

    Array.from(select.options).forEach(function (opt) {
        if (opt.value === '') return;
        const row = document.createElement(multiple ? 'label' : 'div');
        row.className = 'search-select-option';
        row.dataset.value = opt.value;
        row.dataset.text = opt.textContent.toLowerCase();
        if (multiple) {
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = opt.selected;
            box.addEventListener('change', function () {
                opt.selected = box.checked;
                select.dispatchEvent(new Event('change'));
            });
            row.appendChild(box);
        } else {
            row.setAttribute('role', 'option');
            row.addEventListener('click', function () {
                select.value = opt.value;
                select.dispatchEvent(new Event('change'));
                close();
                toggle.focus();
            });
        }
        const text = document.createElement('span');
        text.textContent = opt.textContent;
        row.appendChild(text);
        list.appendChild(row);
    });

    function refresh() {
        const chosen = Array.from(select.selectedOptions).filter(function (o) { return o.value !== ''; });
        const textEl = toggle.querySelector('.search-select-text');
        if (multiple) {
            textEl.textContent = chosen.length ? chosen.length + ' selected' : placeholder;
            list.querySelectorAll('.search-select-option').forEach(function (row) {
                const opt = Array.from(select.options).find(function (o) { return o.value === row.dataset.value; });
                row.querySelector('input').checked = !!(opt && opt.selected);
            });
            chips.innerHTML = '';
            chosen.forEach(function (opt) {
                const chip = document.createElement('span');
                chip.className = 'search-select-chip';
                chip.textContent = opt.textContent;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Remove ' + opt.textContent);
                remove.innerHTML = '&times;';
                remove.addEventListener('click', function () {
                    opt.selected = false;
                    select.dispatchEvent(new Event('change'));
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        } else {
            textEl.textContent = chosen.length ? chosen[0].textContent : placeholder;
            list.querySelectorAll('.search-select-option').forEach(function (row) {
                row.classList.toggle('is-selected', row.dataset.value === select.value);
            });
        }
        textEl.classList.toggle('is-placeholder', chosen.length === 0);
    }

    function open() {
        document.querySelectorAll('.search-select.is-open').forEach(function (other) {
            if (other !== wrap) other._close();
        });
        panel.hidden = false;
        wrap.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        search.value = '';
        filter();
        search.focus();
    }

    function close() {
        panel.hidden = true;
        wrap.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }
    wrap._close = close;

    function filter() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        list.querySelectorAll('.search-select-option').forEach(function (row) {
            const match = row.dataset.text.indexOf(q) !== -1;
            row.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown !== 0;
    }

    toggle.addEventListener('click', function () { panel.hidden ? open() : close(); });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!multiple) {
                const first = list.querySelector('.search-select-option:not([hidden])');
                if (first) first.click();
            }
        }
        if (e.key === 'Escape') { close(); toggle.focus(); }
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    select.addEventListener('change', refresh);
    refresh();
}

document.querySelectorAll('select[data-search]').forEach(makeSearchSelect);

/* Field validation (same as the other forms): the field turns red and the message shows under it.
   Errors found by the server (for example "Email already registered") are shown the same way. */
const PW_RULE = /^(?=.*[a-z])(?=.*[A-Z]).{8,}$/;
const PW_TEXT = <?= json_encode(PW_MSG) ?>;
const FIELD_RULES = {
    phone: { msg: 'Please enter a valid phone number (05XXXXXXXX or 011XXXXXXX).' },
    new_password: { strong: true, notSameAs: 'current_password' },
    description: { minMsg: 'Company description must be at least 15 characters.' }
};

function setFieldError(el, msg) {
    el.classList.toggle('invalid', !!msg);
    if (el._searchToggle) el._searchToggle.classList.toggle('invalid', !!msg);
    const holder = el.closest('.form-group') || el.closest('form');
    let box = holder.querySelector(':scope > .field-error');
    if (!box) {
        box = document.createElement('div');
        box.className = 'field-error';
        holder.appendChild(box);
    }
    box.textContent = msg || '';
    box.style.display = msg ? 'block' : 'none';
}

function checkField(el) {
    const rule = FIELD_RULES[el.id] || {};
    if (el.type === 'file') {
        const file = el.files[0];
        if (!file) return el.required ? 'Please choose an image.' : '';
        return /\.(png|jpe?g)$/i.test(file.name) ? '' : 'Please upload a PNG, JPG or JPEG image.';
    }
    if (el.type === 'checkbox') return '';
    const v = (el.value || '').trim();
    if (el.required && v === '') return 'This field is required.';
    if (v === '') return '';
    if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.';
    if (el.type === 'url') {
        try { if (!/^https?:$/.test(new URL(v).protocol)) return 'Please enter a valid website URL.'; }
        catch (_e) { return 'Please enter a valid website URL.'; }
    }
    if (el.getAttribute('pattern') && !new RegExp('^(?:' + el.getAttribute('pattern') + ')$').test(v)) return rule.msg || 'Please enter a valid value.';
    if (el.type === 'number') {
        const n = Number(v);
        if (!Number.isInteger(n) || (el.min !== '' && n < Number(el.min)) || (el.max !== '' && n > Number(el.max))) return rule.msg || 'Please enter a valid number.';
    }
    if (el.type === 'date' && ((el.min && v < el.min) || (el.max && v > el.max))) return rule.msg || 'Please enter a valid date.';
    if (rule.strong && !PW_RULE.test(el.value)) return PW_TEXT;
    if (rule.notSameAs) {
        const other = document.getElementById(rule.notSameAs);
        if (other && other.value !== '' && other.value === el.value) return 'New password must be different from the current password.';
    }
    if (el.minLength > 0 && v.length < el.minLength) return rule.minMsg || ('Please enter at least ' + el.minLength + ' characters.');
    if (rule.after) {
        const start = document.getElementById(rule.after);
        if (start && start.value && v < start.value) return rule.afterMsg;
    }
    return '';
}

document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.noValidate = true;
    const fields = Array.from(form.querySelectorAll('input, select, textarea'))
        .filter(function (el) { return el.name && el.type !== 'hidden'; });

    fields.forEach(function (el) {
        /* Red star next to the label of each required field. */
        if (el.required && el.id) {
            const label = form.querySelector('label[for="' + el.id + '"], label[for="' + el.id + 'Toggle"]');
            if (label && !label.querySelector('.required-mark')) label.insertAdjacentHTML('beforeend', '<span class="required-mark">*</span>');
        }
        ['input', 'change'].forEach(function (evt) {
            el.addEventListener(evt, function () {
                if (el.classList.contains('invalid')) setFieldError(el, checkField(el));
            });
        });
    });

    form.addEventListener('submit', function (e) {
        let first = null;
        fields.forEach(function (el) {
            const msg = checkField(el);
            setFieldError(el, msg);
            if (msg && !first) first = el;
        });
        if (first) {
            e.preventDefault();
            if (first.type !== 'file') (first._searchToggle || first).focus();
        }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const serverError = <?= json_encode($fieldError) ?>;
    if (!serverError) return;
    const panel = document.getElementById(serverError.form);
    if (panel && panel.classList.contains('profile-hidden')) {
        if (serverError.field === 'pictureInput' || serverError.field === 'logoInput') panel.classList.remove('profile-hidden');
        else toggleProfileForm(serverError.form);
    }
    /* Keep what the user typed (passwords are never sent back). */
    Object.keys(serverError.old || {}).forEach(function (name) {
        const el = panel ? panel.querySelector('[name="' + name + '"]') : null;
        if (el && ['file', 'password', 'checkbox'].indexOf(el.type) === -1) {
            el.value = serverError.old[name];
            if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change'));
        }
    });
    const field = document.getElementById(serverError.field);
    if (field) {
        setFieldError(field, serverError.message);
        if (field.type !== 'file') (field._searchToggle || field).focus();
    }
});

/* New picture: show a preview, then save it only when "Save Changes" is clicked. */
function previewImage(input, panelId) {
    const panel = document.getElementById(panelId);
    const avatar = document.querySelector('.profile-avatar');
    if (!input.files.length || !panel || !avatar) return;
    panel.querySelector('[data-file-name]').textContent = input.files[0].name;
    panel.classList.remove('profile-hidden');
    const formatError = checkField(input);
    setFieldError(input, formatError);
    if (formatError) {
        if (avatar.dataset.original !== undefined) avatar.innerHTML = avatar.dataset.original;
        return;
    }
    if (avatar.dataset.original === undefined) avatar.dataset.original = avatar.innerHTML;
    const img = document.createElement('img');
    img.src = URL.createObjectURL(input.files[0]);
    img.alt = 'New picture preview';
    avatar.innerHTML = '';
    avatar.appendChild(img);
    panel.querySelector('[data-file-name]').textContent = input.files[0].name;
    panel.classList.remove('profile-hidden');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelImage(inputId, panelId) {
    const input = document.getElementById(inputId);
    const panel = document.getElementById(panelId);
    const avatar = document.querySelector('.profile-avatar');
    if (input) {
        input.value = '';
        setFieldError(input, '');
    }
    if (avatar && avatar.dataset.original !== undefined) avatar.innerHTML = avatar.dataset.original;
    if (panel) panel.classList.add('profile-hidden');
}

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

/* Applicants of each opportunity: the triangle opens / closes the table (remembered in this browser). */
(function () {
  const key = 'ufuqCollapsedOpportunities';
  let closed = [];
  try { closed = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) {}
  document.querySelectorAll('[data-opp-card]').forEach(function (card) {
    const id = card.dataset.oppCard;
    const btn = card.querySelector('.opp-toggle');
    function set(collapsed) {
      card.classList.toggle('is-collapsed', collapsed);
      btn.setAttribute('aria-expanded', String(!collapsed));
    }
    set(closed.indexOf(id) !== -1);
    btn.addEventListener('click', function () {
      const collapsed = !card.classList.contains('is-collapsed');
      set(collapsed);
      closed = closed.filter(function (x) { return x !== id; });
      if (collapsed) closed.push(id);
      try { localStorage.setItem(key, JSON.stringify(closed)); } catch (e) {}
    });
  });
})();

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
</body>
</html>