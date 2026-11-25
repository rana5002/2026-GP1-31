<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
// Load shared dropdown options.
require_once __DIR__ . '/../includes/lists.php';
// Shared confirmation modal (Delete / Close Opportunity).
require_once __DIR__ . '/../includes/confirm-modal.php';



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
        // Show a clear confirmation after closing an opportunity.
        'closed' => 'Opportunity closed successfully. Applications are no longer being accepted.',
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

/* Close Opportunity: closes directly for now (the "review applicants first"
   workflow isn't built yet — the confirm() on the button explains this). */
if ($action === 'close_opportunity') {
    $oppId = (int)($_POST['opportunity_id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE opportunity SET Status = ? WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
    $stmt->execute(['Closed', $oppId, $companyId]);
    company_redirect('opportunities', 'closed');
}

   /* Reopen Opportunity (User Story 28, Layan's part): reopening a closed opportunity does NOT
          just flip its Status back to Open — its old dates/timeline/schedule are stale. Instead:
            1. A new `opportunity` row is INSERTed, copying the closed one's details, but the title
                gets "(Version N)" and every date/timeline/schedule column is left NULL, Status stays
                'Closed' so it's not shown as a live, half-filled-in listing.
            2. The company is sent to opportunity-form.php for the NEW row — Start/End/Application
                Opens/Deadline are required fields there, so the form itself forces fresh dates.
            3. The old (closed) row stays exactly as it was.
            4. Everyone who favorited the OLD opportunity gets an in-site notification that it
                reopened, and their favorite on the old listing is removed.
          If the opportunity being "reopened" is itself a just-created duplicate still missing
          dates, there is nothing to duplicate — it's a plain Status flip to Open. */
 


if ($action === 'reopen_opportunity') {
    $oppId = (int) ($_POST['opportunity_id'] ?? 0);

    // Only allow reopening a closed opportunity owned by this company.
    $stmt = $pdo->prepare(
        "SELECT *
         FROM opportunity
         WHERE OpportunityID = ?
           AND CreatedByCompanyID = ?
           AND Status = 'Closed'
           AND EndDate < ?"
    );
    $stmt->execute([$oppId, $companyId, date('Y-m-d')]);
    $oldOpp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldOpp) {
        throw new RuntimeException('Closed opportunity not found.');
    }

    // Calculate the next version number.
    $baseTitle = (string) preg_replace(
        '/\s*\(Version \d+\)\s*$/',
        '',
        (string) $oldOpp['Title']
    );

    $nextVersion = 2;

    if (preg_match(
        '/\(Version (\d+)\)\s*$/',
        (string) $oldOpp['Title'],
        $matches
    )) {
        $nextVersion = (int) $matches[1] + 1;
    }

    // Keep the draft in the session; do not insert a database row yet.
    $_SESSION['reopen_opportunity_draft'] = [
        'old_id' => $oppId,
        'company_id' => $companyId,
        'title' => $baseTitle . ' (Version ' . $nextVersion . ')',
        'data' => $oldOpp
    ];

    header('Location: opportunity-form.php?reopen=1');
    exit;
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
            $uploadDir = __DIR__ . '/../uploads/logos';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create the logo upload folder.');
            $fileName = 'company_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedLogo[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) throw new RuntimeException('Could not save the uploaded logo.');
            $stmt = $pdo->prepare('UPDATE company SET Logo = ? WHERE CompanyID = ?');
            $stmt->execute(['uploads/logos/' . $fileName, $companyId]);
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
            if ($website !== '' && (strlen($website) > 255 || safe_http_url($website) === '')) throw new FieldError('detailsForm', 'website', 'Please enter a valid website URL.');

            $stmt = $pdo->prepare('UPDATE company SET IndustrySector = ?, Location = ?, WebsiteURL = ?, Description = ? WHERE CompanyID = ?');
            $stmt->execute([$industry, $location, $website !== '' ? $website : null, $description, $companyId]);
            company_redirect('profile', 'saved');
        }
    }
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $ex instanceof PDOException
    ? 'Unable to save changes. Please check the information and try again.'
    : $ex->getMessage();
    //$error = $ex instanceof PDOException ? 'Unable to save changes. Please check the information and try again.' : $ex->getMessage();
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
    FROM opportunity o
    WHERE o.CreatedByCompanyID = ?
      AND o.Status <> \'Archived\'
    ORDER BY o.OpportunityID DESC');
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
$oppTotal = count($opportunities);
$closedCount = $oppTotal - $openCount;
$totalFavorites = array_sum(array_map(static fn($o) => (int)($o['FavouriteCount'] ?? 0), $opportunities));
$totalApplicants = count($applicants);
$totalViews = array_sum(array_map(static fn($o) => (int)($o['ViewsCount'] ?? 0), $opportunities));
$recentApplicants = array_slice($applicants, 0, 5);
/* ---------- Recent reviews for each company opportunity ---------- */
/* Get the average rating and total review count for every opportunity. */
$opportunityReviewStats = $pdo->prepare("
    SELECT
        o.OpportunityID,
        o.Title,
        COUNT(r.Rating) AS ReviewCount,
        AVG(r.Rating) AS AverageRating
    FROM opportunity o
    LEFT JOIN review r
        ON r.OpportunityID = o.OpportunityID
    WHERE o.CreatedByCompanyID = ?
      AND o.Status <> 'Archived'
    GROUP BY o.OpportunityID, o.Title
    ORDER BY o.OpportunityID DESC
");
$opportunityReviewStats->execute([$companyId]);
$opportunityReviewStats = $opportunityReviewStats->fetchAll(PDO::FETCH_ASSOC);

/* Get the two newest reviews for each opportunity owned by this company. */
$recentOpportunityReviews = $pdo->prepare("
    SELECT
        r.OpportunityID,
        r.Rating,
        r.ReviewText,
        r.CreatedAt,
        u.FirstName,
        u.LastName
    FROM review r
    JOIN opportunity o
        ON o.OpportunityID = r.OpportunityID
    LEFT JOIN `user` u
        ON u.UserID = r.UserID
    WHERE o.CreatedByCompanyID = ?
      AND o.Status <> 'Archived'
    ORDER BY r.CreatedAt DESC
");
$recentOpportunityReviews->execute([$companyId]);
$allOpportunityReviews = $recentOpportunityReviews->fetchAll(PDO::FETCH_ASSOC);

/* All reviews of each opportunity, newest first (review tables in the Opportunities tab). */
$allReviewsByOpportunity = [];
foreach ($allOpportunityReviews as $review) {
    $allReviewsByOpportunity[(int)$review['OpportunityID']][] = $review;
}

/* Group reviews by opportunity and keep only the latest two comments. */
$reviewsByOpportunity = [];

foreach ($allOpportunityReviews as $review) {
    $oppId = (int) $review['OpportunityID'];

    if (!isset($reviewsByOpportunity[$oppId])) {
        $reviewsByOpportunity[$oppId] = [];
    }

    if (count($reviewsByOpportunity[$oppId]) < 2) {
        $reviewsByOpportunity[$oppId][] = $review;
    }
}

// Use shared lists to keep company profile options consistent.
$industryOptions = INDUSTRIES;
$locationOptions = CITIES;

$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (str_starts_with($logo, 'http') ? $logo : '../' . ltrim($logo, '/'));
$companyInitial = strtoupper(substr((string)$company['CompanyName'], 0, 1));

function display_date($date): string { return $date ? e(date('M j, Y', strtotime((string)$date))) : '&mdash;'; }

$heroTitles = [
    'opportunities' => ['Opportunities', 'Manage the training programs and courses you publish.'],
    'applicants'    => ['Applications', 'Review candidates and update their application status.'],
    'reviews'       => ['Reviews', 'See how users rate and review your opportunities.'],
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
<link rel="stylesheet" href="../css/dashboard-company.css">
</head>
<body>

<header id="site-header" class="site-header">
  <div class="container">
    <a href="../index.php" class="brand" style="text-decoration:none"><img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent"> Ufuq</a>
    <?php
      /* Header link to highlight: Profile highlights nothing (it opens from the logo / picture).
         Reviews belong to Opportunities, unless they were opened from the Dashboard. */
      $navActive = $activeTab;
      if ($activeTab === 'reviews') $navActive = (($_GET['from'] ?? '') === 'overview') ? 'overview' : 'opportunities';
    ?><nav class="nav-links" id="navLinks"><a href="dashboard-company.php?tab=overview" class="<?= $navActive === 'overview' ? 'active' : '' ?>">Dashboard</a><a href="dashboard-company.php?tab=opportunities" class="<?= $navActive === 'opportunities' ? 'active' : '' ?>">Opportunities</a><a href="dashboard-company.php?tab=applicants" class="<?= $activeTab === 'applicants' ? 'active' : '' ?>">Applicants</a></nav>
    <div class="nav-actions" style="display:flex;align-items:center;gap:10px"><a href="dashboard-company.php?tab=profile" class="company-header-profile" title="Open company profile" aria-label="Open company profile"><span class="company-header-avatar"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company avatar"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></span></a><form method="post" action="logout.php" class="logout-form" style="display:inline;margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" class="btn btn-ghost btn-sm">Log Out</button></form><button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-controls="navLinks" aria-expanded="false" type="button"><?= icon('menu', 22) ?></button></div>
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
          <p>Share your training programs and courses, manage applicants, and see how users respond.</p>
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
    <?php
      /* Back arrow on pages opened from the dashboard or from the reviews summary. */
      $backPages = ['overview' => 'Dashboard', 'opportunities' => 'Opportunities'];
      $backFrom = (string)($_GET['from'] ?? '');
      if (!isset($backPages[$backFrom]) && $activeTab === 'reviews') $backFrom = 'overview';
    ?>
    <?php if (isset($backPages[$backFrom]) && $backFrom !== $activeTab): ?>
      <a href="dashboard-company.php?tab=<?= e($backFrom) ?>" class="tab-back"><span aria-hidden="true">&larr;</span> Back to <?= e($backPages[$backFrom]) ?></a>
    <?php endif; ?>

    <?php if ($success !== ''): ?><div class="company-toast" id="companyToast" role="status" aria-live="polite"><span class="company-toast-icon" aria-hidden="true">✓</span><span><?= e($success) ?></span><button type="button" class="company-toast-close" aria-label="Close">&times;</button></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="company-alert error" role="alert"><span aria-hidden="true">!</span><span><?= e($error) ?></span></div><?php endif; ?>

    <!-- Overview -->
    <section class="tab-panel <?= $activeTab === 'overview' ? 'active' : '' ?>" id="tab-overview">

      <!-- Overview -->
      <div class="card-heading-row">
        <div>
          <h2 class="section-heading" style="margin-bottom:7px">Overview</h2>
          <p class="section-description" style="margin:0">A quick look at your opportunities and how users interact with them.</p>
        </div>
      </div>

      <!-- All Opportunities highlighted, the rest as small cards (same design as the admin dashboard) -->
      <section class="highlight-grid" aria-label="Dashboard overview">

        <a href="dashboard-company.php?tab=opportunities&amp;from=overview" class="highlight-card">
          <div>
            <span class="highlight-title">All Opportunities</span>
            <span class="highlight-number"><?= $oppTotal ?></span>
            <span class="highlight-note"><?= $oppTotal === 1 ? 'Training program or course you have published.' : 'Training programs and courses you have published.' ?></span>
          </div>
          <span class="highlight-btn">Manage opportunities &rarr;</span>
        </a>

        <div class="mini-grid">

          <a href="dashboard-company.php?tab=opportunities&amp;from=overview" class="dashboard-card mini-card">
            <span class="stat-icon" aria-hidden="true"><?= icon('training', 20) ?></span>
            <div>
              <span class="mini-number"><?= $openCount ?></span>
              <span class="mini-title">Open Opportunities</span>
            </div>
          </a>

          <a href="dashboard-company.php?tab=opportunities&amp;from=overview" class="dashboard-card mini-card">
            <span class="stat-icon" aria-hidden="true">✕</span>
            <div>
              <span class="mini-number"><?= $closedCount ?></span>
              <span class="mini-title">Closed Opportunities</span>
            </div>
          </a>

          <a href="dashboard-company.php?tab=opportunities&amp;from=overview" class="dashboard-card mini-card">
            <span class="stat-icon" aria-hidden="true"><?= icon('goal', 20) ?></span>
            <div>
              <span class="mini-number"><?= number_format((int)$totalViews) ?></span>
              <span class="mini-title">Total Views</span>
            </div>
          </a>

          <a href="dashboard-company.php?tab=opportunities&amp;from=overview" class="dashboard-card mini-card">
            <span class="stat-icon" aria-hidden="true">♥</span>
            <div>
              <span class="mini-number"><?= number_format($totalFavorites) ?></span>
              <span class="mini-title">Favorites</span>
            </div>
          </a>

          <a href="dashboard-company.php?tab=reviews&amp;from=overview" class="dashboard-card mini-card">
            <span class="stat-icon" aria-hidden="true">★</span>
            <div>
              <span class="mini-number"><?= $averageRating !== null ? number_format((float)$averageRating, 1) : '&mdash;' ?></span>
              <span class="mini-title">Average Rating</span>
            </div>
          </a>

          <!-- Not part of this release -->
          <div class="dashboard-card mini-card is-soon">
            <span class="stat-icon" aria-hidden="true"><?= icon('userMale', 20) ?></span>
            <div>
              <span class="mini-title">Total Applicants</span>
              <span class="soon-text">Coming Soon</span>
            </div>
          </div>

        </div>
      </section>

      <div class="dashboard-section">
        <div class="card-heading-row">
          <div>
            <h2 class="section-heading" style="margin-bottom:7px">Recent Applicants</h2>
            <p class="section-description" style="margin:0">The latest users who applied to your opportunities.</p>
          </div>
          <a href="dashboard-company.php?tab=applicants&amp;from=overview" class="dashboard-link">View all &rarr;</a>
        </div>
        <!-- Not part of this release -->
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('userMale', 30, 'icon-muted') ?></div><h3>Coming Soon</h3><p>Recent applicants will be shown here in a future update.</p></div>
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
      <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= e($o['Type'] ?? '—') ?></td><td><span class="badge <?= ['Open' => 'badge-success', 'Closed' => 'badge-status-closed', 'Upcoming' => 'badge-status-upcoming'][$o['Status'] ?? ''] ?? 'badge-neutral' ?>"><?= e($o['Status'] ?? '—') ?></span></td><td><strong><?= (int)($o['ViewsCount'] ?? 0) ?></strong></td><td><strong><?= (int)($o['FavouriteCount'] ?? 0) ?></strong></td><td>—</td><td><?= e($o['ApplicationMethod'] ?? '—') ?></td><td><div class="company-actions opp-actions"><?php $isOpenOpp = ($o['Status'] ?? '') === 'Open'; $canReopen = ($o['Status'] ?? '') === 'Closed' && !empty($o['EndDate']) && $o['EndDate'] < date('Y-m-d'); $closeMsg = 'Reviewing applicants before closing isn\'t available yet. Close this opportunity directly for now?'; $reopenMsg = 'Reopening will publish a new copy of this opportunity with fresh dates. You\'ll be taken to edit it next. Continue?'; ?>
<?php if ($isOpenOpp): ?>

  <!-- Close Opportunity: confirmation modal on the same page -->
  <button
    type="button"
    class="btn btn-sm opp-status-btn opp-status-btn-close"
    onclick="openConfirmModal('closeOppModal', <?= (int)$o['OpportunityID'] ?>)"
  >
    Close Opportunity
  </button>


<?php elseif ($canReopen): ?>

  <!-- Reopen: only for a Closed opportunity whose EndDate has passed (no confirmation) -->
  <form method="post" class="opp-reopen-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reopen_opportunity">
    <input type="hidden" name="tab" value="opportunities">
    <input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>">
    <button type="submit" class="btn btn-sm opp-status-btn opp-status-btn-reopen">Reopen</button>
  </form>

<?php endif; ?>

<a href="opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>" class="btn btn-ghost btn-sm" title="Edit"><?= icon('edit') ?></a><button class="btn btn-ghost btn-sm" type="button" title="Delete" onclick="openConfirmModal('deleteOppModal', <?= (int)$o['OpportunityID'] ?>)"><?= icon('delete', 18, 'icon-danger') ?></button></div></td></tr><?php endforeach; ?>        </tbody></table></div>
      <?php endif; ?>

      <!-- Opportunity Reviews (under My Opportunities): one card per opportunity, same look as the applicants list.
           The triangle opens / closes the reviews table (remembered in this browser). -->
      <section class="dashboard-section opportunity-reviews-section">
        <div class="card-heading-row">
          <div>
            <h2 class="section-heading" style="margin-bottom:7px">Opportunity Reviews</h2>
            <p class="section-description" style="margin:0">Average rating and user reviews for each of your opportunities.</p>
          </div>
          <a href="dashboard-company.php?tab=reviews&amp;from=opportunities" class="dashboard-link">View all reviews &rarr;</a>
        </div>

        <?php if (!$opportunityReviewStats): ?>
          <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('favorite', 30, 'icon-muted') ?></div><h3>No opportunities yet</h3><p>Your opportunity ratings and reviews will appear here.</p></div>
        <?php else: ?>
          <?php foreach ($opportunityReviewStats as $reviewStat): ?>
            <?php
              $reviewOppId = (int)$reviewStat['OpportunityID'];
              $oppReviews = $allReviewsByOpportunity[$reviewOppId] ?? [];
              $reviewTotal = (int)$reviewStat['ReviewCount'];
              $average = $reviewStat['AverageRating'];
            ?>
            <article class="applicant-opportunity-card review-opportunity-card" data-opp-card="review-<?= $reviewOppId ?>">
              <div class="applicant-opportunity-heading">
                <button type="button" class="opp-toggle" aria-expanded="true" aria-controls="reviewTable<?= $reviewOppId ?>">
                  <span class="opp-heading-text">
                    <span class="opp-title"><?= e($reviewStat['Title']) ?></span>
                    <span class="opp-sub">
                      <span class="review-star" aria-hidden="true">★</span>
                      <?= $average !== null ? number_format((float)$average, 1) . ' / 5 average rating' : 'No ratings yet' ?>
                    </span>
                  </span>
                  <span class="applicant-count"><?= $reviewTotal ?> <?= $reviewTotal === 1 ? 'review' : 'reviews' ?></span>
                  <span class="opp-caret" aria-hidden="true"></span>
                </button>
              </div>

              <div class="company-table-wrap" id="reviewTable<?= $reviewOppId ?>">
                <?php if (!$oppReviews): ?>
                  <p class="review-table-empty">No reviews have been submitted for this opportunity yet.</p>
                <?php else: ?>
                  <table class="data-table reviews-table">
                    <thead><tr><th>User</th><th>Rating</th><th>Review</th><th>Date</th></tr></thead>
                    <tbody>
                      <?php foreach ($oppReviews as $rv): ?>
                        <?php $stars = max(0, min(5, (int)$rv['Rating'])); ?>
                        <tr>
                          <td data-label="User"><strong><?= e(trim(($rv['FirstName'] ?? '') . ' ' . ($rv['LastName'] ?? '')) ?: 'A user') ?></strong></td>
                          <td data-label="Rating"><span class="rating-stars" title="<?= $stars ?> / 5"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></span></td>
                          <td data-label="Review" class="review-text-cell"><?= nl2br(e($rv['ReviewText'] ?? '')) ?></td>
                          <td data-label="Date" class="review-date-cell"><?= display_date($rv['CreatedAt']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </section>


<!-- Applications tab — Coming Soon with close confirmation -->
<section class="tab-panel <?= $activeTab === 'applicants' ? 'active' : '' ?>" id="tab-applicants">

  <div class="empty-state">
    <div class="empty-icon" aria-hidden="true">
      <?= icon('userMale', 30, 'icon-muted') ?>
    </div>
    <h3>Coming soon</h3>
    <p>
      Reviewing Applications is not available yet.
      This feature will be added in a future update.
    </p>
  </div>

  <?php if (isset($_GET['confirm_close']) && isset($_GET['opportunity_id'])): ?>
    <div class="close-confirm-overlay">
      <div class="close-confirm-modal">

        <h2>Close Opportunity?</h2>

        <p class="close-warning">
          Application management is not available yet.
          You cannot review applicants before closing this opportunity.
        </p>

        <p>Are you sure you want to close this opportunity?</p>

        <div class="close-confirm-actions">
          <a
            href="dashboard-company.php?tab=opportunities"
            class="btn btn-ghost"
          >
            Cancel
          </a>

          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="close_opportunity">
            <input type="hidden" name="tab" value="opportunities">
            <input
              type="hidden"
              name="opportunity_id"
              value="<?= (int) $_GET['opportunity_id'] ?>"
            >

            <button type="submit" class="btn close-confirm-submit">
              Yes, Close Opportunity
            </button>
          </form>
        </div>

      </div>
    </div>
  <?php endif; ?>

</section>

    <!-- Reviews -->
    <section class="tab-panel <?= $activeTab === 'reviews' ? 'active' : '' ?>" id="tab-reviews">
      <div class="card-heading-row">
        <p class="section-description" style="margin:0">All ratings and comments users have left on your opportunities.</p>
        <?php if ($averageRating !== null): ?><span class="rating-pill">★ <?= number_format((float)$averageRating, 1) ?> / 5 (<?= $reviewCount ?>)</span><?php endif; ?>
      </div>
      <?php if (!$reviews): ?>
        <div class="empty-state"><div class="empty-icon" aria-hidden="true"><?= icon('favorite', 30, 'icon-muted') ?></div><h3>No reviews yet</h3><p>User reviews of your opportunities will appear here.</p></div>
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
          <div class="profile-field is-wide"><div class="profile-field-label">Website</div><div class="profile-field-value"><?php if (safe_http_url($company['WebsiteURL'] ?? '') !== ''): ?><a href="<?= e(safe_http_url($company['WebsiteURL'])) ?>" target="_blank" rel="noopener"><?= e($company['WebsiteURL'] ?? '') ?></a><?php else: ?>—<?php endif; ?></div></div>
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

  <!-- Confirmation modals (includes/confirm-modal.php) — kept outside the animated tab panels -->
  <?php
  confirm_modal([
      'id'            => 'deleteOppModal',
      'title'         => 'Delete Opportunity?',
      'message'       => 'This opportunity will be permanently deleted. This cannot be undone.',
      'confirm_label' => 'Yes, Delete',
      'icon'          => 'trash',
      'fields'        => ['action' => 'delete_opportunity', 'tab' => 'opportunities'],
      'id_field'      => 'opportunity_id',
  ]);
  confirm_modal([
      'id'            => 'closeOppModal',
      'title'         => 'Close Opportunity?',
      'message'       => 'Users will no longer be able to apply. Reviewing applicants before closing isn\'t available yet.',
      'confirm_label' => 'Yes, Close',
      'icon'          => 'lock',
      'fields'        => ['action' => 'close_opportunity', 'tab' => 'opportunities'],
      'id_field'      => 'opportunity_id',
  ]);
  ?>
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

document.addEventListener('ufuq:page-loaded', function () {
    const serverError = <?= json_encode($fieldError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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