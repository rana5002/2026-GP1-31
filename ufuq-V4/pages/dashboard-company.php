<?php

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/auth.php';



require_login('Company');

$companyId = (int) $_SESSION['user_id'];

// Opportunity view tracking table. The opportunity detail page must insert
// one row per recorded visit for the Views count to increase.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS opportunity_view (
        ViewID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        OpportunityID INT NOT NULL,
        ViewedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_opportunity_view_opportunity (OpportunityID),
        INDEX idx_opportunity_view_date (ViewedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $ignored) {
    // Keep the dashboard available if table creation is restricted.
}


$validTabs = ['overview','opportunities','applicants','engagement','reviews','profile'];

$activeTab = $_GET['tab'] ?? 'overview';

if (!in_array($activeTab, $validTabs, true)) $activeTab = 'overview';



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

        'saved' => 'Company profile updated successfully.',

        'deleted' => 'Opportunity deleted successfully.',

        'status' => 'Application status updated successfully.'

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

            $allowedStatuses = ['Pending', 'Under Review', 'Accepted', 'Rejected', 'Submitted'];

            if (!in_array($status, $allowedStatuses, true)) throw new RuntimeException('Invalid application status.');

            $check = $pdo->prepare('SELECT 1 FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');

            $check->execute([$oppId, $companyId]);

            if (!$check->fetchColumn()) throw new RuntimeException('You cannot update this application.');

            $update = $pdo->prepare('UPDATE application SET ApplicationStatus = ? WHERE UserID = ? AND OpportunityID = ?');

            $update->execute([$status, $userId, $oppId]);

            company_redirect('applicants', 'status');

        }



        if ($action === 'save_profile') {

            $name = trim($_POST['company_name'] ?? '');

            $phone = trim($_POST['phone'] ?? '');

            $email = trim($_POST['email'] ?? '');

            $password = (string)($_POST['password'] ?? '');

            $industry = trim($_POST['industry'] ?? '');

            $description = trim($_POST['description'] ?? '');

            $location = trim($_POST['location'] ?? '');

            $website = trim($_POST['website'] ?? '');



            if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

                throw new RuntimeException('Please enter a valid company name and email address.');

            }

            if ($phone !== '' && !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {

                throw new RuntimeException('Please enter a valid phone number.');

            }

            if ($description === '') throw new RuntimeException('Company description is required.');

            if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) throw new RuntimeException('Please enter a valid website URL.');

            if ($password !== '' && strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');



            $emailCheck = $pdo->prepare('SELECT MemberID FROM member WHERE Email = ? AND MemberID <> ?');

            $emailCheck->execute([$email, $companyId]);

            if ($emailCheck->fetch()) throw new RuntimeException('Email already registered');



            $logoPath = null;

            if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {

                if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Logo upload failed.');

                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, ['png','jpg','jpeg'], true)) throw new RuntimeException('Please upload a PNG or JPG image.');

                if ($_FILES['logo']['size'] > 3 * 1024 * 1024) throw new RuntimeException('Logo must be 3 MB or smaller.');

                $uploadDir = __DIR__ . '/../uploads/company/logos';

                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create the logo upload folder.');

                $fileName = 'company_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

                if (!move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . '/' . $fileName)) throw new RuntimeException('Could not save the uploaded logo.');

                $logoPath = 'uploads/company/logos/' . $fileName;

            }



            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE member SET Email = ? WHERE MemberID = ? AND Role = \'Company\'');

            $stmt->execute([$email, $companyId]);

            if ($password !== '') {

                $stmt = $pdo->prepare('UPDATE member SET Password = ? WHERE MemberID = ?');

                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $companyId]);

            }

            $sql = 'UPDATE company SET CompanyName = ?, Phone = ?, IndustrySector = ?, Description = ?, Location = ?, WebsiteURL = ?';

            $params = [$name, $phone !== '' ? $phone : null, $industry !== '' ? $industry : null, $description, $location !== '' ? $location : null, $website !== '' ? $website : null];

            if ($logoPath !== null) { $sql .= ', Logo = ?'; $params[] = $logoPath; }

            $sql .= ' WHERE CompanyID = ?';

            $params[] = $companyId;

            $stmt = $pdo->prepare($sql);

            $stmt->execute($params);

            $pdo->commit();

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

    (SELECT COUNT(*) FROM favourite f WHERE f.OpportunityID = o.OpportunityID) AS FavouriteCount,

    (SELECT COUNT(*) FROM opportunity_view v WHERE v.OpportunityID = o.OpportunityID) AS ViewsCount

    FROM opportunity o WHERE o.CreatedByCompanyID = ? ORDER BY o.OpportunityID DESC');

$stmt->execute([$companyId]);

$opportunities = $stmt->fetchAll();



// Load applicant profile columns as well as application data so the company can
// filter candidates using the profile fields available in this database.
$stmt = $pdo->prepare('SELECT a.UserID, a.OpportunityID, a.SubmissionDate, a.ApplicationStatus,
    u.*, o.Title
    FROM application a
    JOIN opportunity o ON o.OpportunityID = a.OpportunityID
    LEFT JOIN `user` u ON u.UserID = a.UserID
    WHERE o.CreatedByCompanyID = ?
    ORDER BY a.SubmissionDate DESC, a.OpportunityID DESC');
$stmt->execute([$companyId]);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Filters are scoped to the opportunities owned by the logged-in company.
$selectedOpportunity = (int)($_GET['opportunity_filter'] ?? 0);
$selectedStatus = trim((string)($_GET['status_filter'] ?? ''));
$qualificationFilter = trim((string)($_GET['qualification_filter'] ?? ''));
$skillFilter = trim((string)($_GET['skill_filter'] ?? ''));
$studyFilter = trim((string)($_GET['study_filter'] ?? ''));
$experienceFilter = trim((string)($_GET['experience_filter'] ?? ''));
$validApplicantStatuses = ['Pending', 'Under Review', 'Accepted', 'Rejected', 'Submitted'];
if (!in_array($selectedStatus, $validApplicantStatuses, true)) $selectedStatus = '';
$companyOpportunityIds = array_map(static fn($o) => (int)$o['OpportunityID'], $opportunities);
if ($selectedOpportunity && !in_array($selectedOpportunity, $companyOpportunityIds, true)) $selectedOpportunity = 0;

$applicantsForDisplay = array_values(array_filter($applicants, static function ($a) use ($selectedOpportunity, $selectedStatus, $qualificationFilter, $skillFilter, $studyFilter, $experienceFilter) {
    if ($selectedOpportunity && (int)$a['OpportunityID'] !== $selectedOpportunity) return false;
    if ($selectedStatus !== '' && (string)($a['ApplicationStatus'] ?? '') !== $selectedStatus) return false;
    $qualification = applicant_profile_value($a, ['Qualifications', 'Qualification', 'DegreeLevel', 'EducationLevel', 'EducationalStatus', 'HighestQualification']);
    $skills = applicant_profile_value($a, ['Skills', 'Skill', 'TechnicalSkills', 'TechnicalSkill', 'UserSkills']);
    $study = applicant_profile_value($a, ['FieldOfStudy', 'Major', 'StudyField', 'Specialization', 'CollegeMajor']);
    $experience = applicant_profile_value($a, ['Experience', 'WorkExperience', 'YearsOfExperience', 'ExperienceLevel', 'ExperienceDetails']);
    if (!applicant_matches_text($qualification, $qualificationFilter)) return false;
    if (!applicant_matches_text($skills, $skillFilter)) return false;
    if (!applicant_matches_text($study, $studyFilter)) return false;
    if (!applicant_matches_text($experience, $experienceFilter)) return false;
    return true;
}));
$applicantsByOpportunity = [];
foreach ($applicantsForDisplay as $applicantRow) {
    $applicantsByOpportunity[(int)$applicantRow['OpportunityID']][] = $applicantRow;
}
$hasApplicantFilters = $selectedOpportunity > 0 || $selectedStatus !== '' || $qualificationFilter !== '' || $skillFilter !== '' || $studyFilter !== '' || $experienceFilter !== '';


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

$recentApplicants = array_slice($applicants, 0, 5);

$industryOptions = ['Technology','Education & Training','Marketing','Administration','Media','Healthcare','Finance','Trading','Other'];

$locationOptions = defined('CITIES') ? CITIES : ['Riyadh','Jeddah','Mecca','Medina','Dammam','Khobar','Dhahran','Taif','Abha','Tabuk','Online','Other'];

$logo = trim((string)($company['Logo'] ?? ''));

$logoSrc = $logo === '' ? '' : (str_starts_with($logo, 'http') ? $logo : '../' . ltrim($logo, '/'));

$companyInitial = strtoupper(substr((string)$company['CompanyName'], 0, 1));

function company_tab_link(string $tab, string $label, string $active): string {

    return '<a class="company-side-link ' . ($tab === $active ? 'active' : '') . '" href="dashboard-company.php?tab=' . e($tab) . '">' . e($label) . '</a>';

}

function display_date($date): string { return $date ? e(date('M j, Y', strtotime((string)$date))) : '&mdash;'; }

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
/* Dashboard polish only. The shared site header markup and styling are left untouched. */
.tab-panel{display:none}
.tab-panel.active{display:block;animation:contentFadeIn .42s ease both}
.hidden{display:none!important}

@keyframes contentFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes bannerFadeIn{from{opacity:0;transform:translateY(-7px)}to{opacity:1;transform:translateY(0)}}
@keyframes softRise{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:translateY(0)}}

/* Page banner and spacing — purple gradient with soft decorative circles */
.page-banner{position:relative;isolation:isolate;overflow:hidden;background:linear-gradient(115deg,#281a46 0%,#39265f 52%,#4b3478 100%)!important;border-bottom:3px solid #e2a94b;padding:30px 0;animation:bannerFadeIn .45s ease both}
.page-banner:before,.page-banner:after{content:"";position:absolute;z-index:-1;pointer-events:none;border-radius:50%;border:1px solid rgba(255,255,255,.16)}
.page-banner:before{width:230px;height:230px;right:8%;top:-115px;background:radial-gradient(circle at 35% 65%,rgba(255,255,255,.11),rgba(255,255,255,.015) 68%);box-shadow:0 0 0 22px rgba(255,255,255,.035),0 0 0 48px rgba(255,255,255,.025)}
.page-banner:after{width:145px;height:145px;right:32%;bottom:-95px;background:rgba(255,255,255,.045);box-shadow:0 0 0 18px rgba(255,255,255,.025),0 0 0 38px rgba(255,255,255,.018)}
.page-banner>.container{position:relative;z-index:1}
.page-banner h2{color:#fff!important}
.company-welcome-subtitle{color:#e6dff2!important}
.page-content{padding-bottom:42px;animation:contentFadeIn .5s ease both}
.page-content h3{letter-spacing:-.015em}
.page-content>div>section>h3{color:var(--color-primary,#1f3566)}

/* Cards and statistics */
main .card{border:1px solid rgba(31,53,102,.075);border-radius:14px;box-shadow:0 3px 12px rgba(31,53,102,.035);transition:transform .23s ease,box-shadow .23s ease,border-color .23s ease}
main .stat-card{position:relative;overflow:hidden;min-height:118px;display:flex;flex-direction:column;justify-content:center;gap:7px;padding:22px}
main .stat-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--color-primary,#1f3566);opacity:.72;transform:scaleY(.35);transform-origin:center;transition:transform .25s ease}
main .stat-card:hover{transform:translateY(-4px);box-shadow:0 12px 26px rgba(31,53,102,.095);border-color:rgba(31,53,102,.15)}
main .stat-card:hover:before{transform:scaleY(1)}
.stat-num{display:inline-block;font-size:clamp(1.7rem,3vw,2.15rem);font-weight:750;line-height:1.15;color:var(--color-primary,#1f3566);transition:transform .23s ease}
.stat-card:hover .stat-num{transform:translateX(2px)}
.stat-label{font-size:.9rem;line-height:1.4;color:var(--color-text-muted,#6b7280)}

/* Tables */
.company-table-wrap{overflow-x:auto;border:1px solid rgba(31,53,102,.08);border-radius:13px;background:var(--color-surface,#fff)}
.company-table-wrap .data-table{width:100%;border-collapse:separate;border-spacing:0}
.company-table-wrap .data-table thead th{background:#f6f8fc;color:#33415f;font-size:.83rem;font-weight:700;white-space:nowrap;padding:14px 16px;border-bottom:1px solid #e8edf5}
.company-table-wrap .data-table tbody td{padding:14px 16px;border-bottom:1px solid #edf0f5;vertical-align:middle}
.company-table-wrap .data-table tbody tr:last-child td{border-bottom:0}
.data-table tbody tr{transition:background-color .18s ease}
.data-table tbody tr:hover{background:rgba(31,53,102,.035)}

/* Buttons inside dashboard content only — header buttons are unaffected */
main .btn{transition:transform .18s ease,box-shadow .18s ease,filter .18s ease}
main .btn:hover{transform:translateY(-1px)}
main .btn-primary:hover{box-shadow:0 5px 13px rgba(31,53,102,.17);filter:brightness(1.035)}
main .btn:active{transform:translateY(0)}
.company-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
.company-inline-form{display:inline}
.status-select{padding:7px 9px;min-width:135px}

/* Profile form */
.company-profile-head{display:flex;gap:18px;align-items:center;margin-bottom:24px;padding:4px 0 18px;border-bottom:1px solid rgba(31,53,102,.09)}
.company-logo{width:76px;height:76px;flex-shrink:0;border-radius:50%;object-fit:cover;border:1px solid var(--color-border,#ddd);display:flex;align-items:center;justify-content:center;background:#f3f5fa;color:var(--color-primary,#1f3566);font-size:1.8rem;font-weight:700;overflow:hidden;transition:box-shadow .23s ease,transform .23s ease}
.company-logo:hover{transform:scale(1.035);box-shadow:0 6px 16px rgba(31,53,102,.12)}
.company-logo img{width:100%;height:100%;object-fit:cover}
.company-field{margin-bottom:18px}
.company-field label{display:block;margin-bottom:7px;font-size:.9rem;font-weight:600;color:#344054}
.company-field .form-control{width:100%;border-radius:9px;transition:border-color .18s ease,box-shadow .18s ease,background-color .18s ease}
.company-field .form-control:focus{border-color:var(--color-primary,#1f3566);box-shadow:0 0 0 3px rgba(31,53,102,.1);outline:none}
#tab-profile .section-card{border-radius:15px;box-shadow:0 5px 20px rgba(31,53,102,.055)}
#tab-profile hr{border:0;border-top:1px solid #e9edf4}


/* Applicants: filters and opportunity-grouped applicant lists */
.applicant-filter-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:0 0 22px;padding:18px;background:#f7f8fc;border:1px solid #e7ebf3;border-radius:14px}
.applicant-filter-field{display:flex;flex-direction:column;gap:7px;flex:1 1 220px;min-width:180px}
.applicant-filter-field label{font-size:.86rem;font-weight:700;color:#344054}
.applicant-filter-field .form-control{width:100%;min-height:42px;border:1px solid #d9dfeb;border-radius:9px;background:#fff;padding:9px 11px}
.applicant-filter-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.applicant-opportunity-card{margin:0 0 22px;border:1px solid #e4e8f1;border-radius:15px;background:#fff;overflow:hidden;box-shadow:0 4px 15px rgba(31,53,102,.045);animation:softRise .35s ease both}
.applicant-opportunity-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 20px;background:linear-gradient(110deg,#f8f7fc,#f5f7fb);border-bottom:1px solid #e7ebf3}
.applicant-opportunity-heading h3{margin:0;color:#30234c;font-size:1.05rem}
.applicant-opportunity-heading p{margin:5px 0 0;color:#70788a;font-size:.86rem}
.applicant-count{flex-shrink:0;padding:7px 11px;border-radius:999px;background:#ece6f8;color:#49336d;font-size:.82rem;font-weight:700}
.applicant-opportunity-card .company-table-wrap{border:0;border-radius:0}
.applicant-status-form{min-width:245px}
.applicant-profile-details{margin-top:8px;font-size:.82rem;color:#596579}
.applicant-profile-details summary{cursor:pointer;color:#49336d;font-weight:700;list-style-position:inside}
.applicant-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px 14px;padding:10px 0 2px}
.applicant-profile-grid span{overflow-wrap:anywhere}
.applicant-filter-note{flex-basis:100%;font-size:.8rem;color:#737d90;margin-top:-4px}
@media(max-width:650px){.applicant-filter-bar{padding:14px}.applicant-filter-field{flex-basis:100%;min-width:0}.applicant-filter-actions{width:100%}.applicant-opportunity-heading{align-items:flex-start;padding:15px;flex-direction:column}.applicant-status-form{min-width:0}.applicant-status-form .status-select{min-width:115px}.applicant-profile-grid{grid-template-columns:1fr}}

/* Reviews and empty states */
#tab-reviews>.card{padding:18px 20px}
.rating-stars{color:#d69e16;letter-spacing:1px}
.company-help{font-size:.82rem;line-height:1.55;color:var(--color-text-muted,#6b7280)}
.field-view{margin-bottom:16px}
.field-label{font-size:.82rem;color:var(--color-text-muted,#6b7280);margin-bottom:4px}
.company-section-card{margin-bottom:18px}
.empty-state{border:1px dashed #d8deea;border-radius:14px;padding:34px 20px;background:rgba(246,248,252,.55)}
.empty-state h3{margin-top:10px}

/* Alerts */
.company-alert{padding:13px 16px;border-radius:10px;margin-bottom:17px;border:1px solid transparent;animation:softRise .3s ease both}
.company-alert.success{background:#eaf8f0;color:#176b39;border-color:#ccebd8}
.company-alert.error{background:#fff0f0;color:#a61b29;border-color:#f5d0d3}
.company-welcome{margin:0;font-size:1.5rem;font-weight:700}
.company-welcome-subtitle{margin-top:5px;color:var(--color-text-muted,#6b7280);font-size:.95rem}

/* Header profile rules retained as they were */
.company-header-profile{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;padding:5px 8px;border-radius:10px;white-space:nowrap}
.company-header-profile:hover{background:var(--color-surface-alt,#f3f4f6)}
.company-header-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e9edf5;color:var(--color-primary,#1f3566);font-weight:700;border:1px solid var(--color-border,#ddd)}
.company-header-avatar img{width:100%;height:100%;object-fit:cover}

@media(max-width:700px){
  .page-content{padding-top:18px!important;padding-bottom:28px}
  main .stat-card{min-height:100px;padding:17px}
  .company-table-wrap .data-table thead th,.company-table-wrap .data-table tbody td{padding:11px 12px}
  .company-profile-head{gap:13px;align-items:flex-start}
  .company-logo{width:64px;height:64px}
}
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}
}
</style>

</head>

<body>

<header id="site-header" class="site-header">

  <div class="container">

    <a href="../index.php" class="brand" style="text-decoration:none"><img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent"> Ufuq</a>

    <nav class="nav-links"><a href="dashboard-company.php?tab=overview" class="<?= in_array($activeTab, ['overview','engagement','reviews','profile'], true) ? 'active' : '' ?>">Dashboard</a><a href="dashboard-company.php?tab=opportunities" class="<?= $activeTab === 'opportunities' ? 'active' : '' ?>">Opportunities</a><a href="dashboard-company.php?tab=applicants" class="<?= $activeTab === 'applicants' ? 'active' : '' ?>">Applicants</a></nav>

    <div class="nav-actions" style="display:flex;align-items:center;gap:10px"><a href="dashboard-company.php?tab=profile" class="company-header-profile" title="Open company profile" aria-label="Open company profile"><span class="company-header-avatar"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company avatar"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></span></a><a href="logout.php" class="btn btn-ghost btn-sm">Log Out</a></div>

  </div>

</header>

<main>

  <div class="page-banner"><div class="container"><?php if ($activeTab === 'overview'): ?><h2 class="company-welcome" style="margin:0">Welcome, <?= e($company['CompanyName']) ?></h2><div class="company-welcome-subtitle"><?= e($company['IndustrySector'] ?? 'Company account') ?></div><?php else: ?><h2 style="margin:0"><?= $activeTab === 'profile' ? 'Company Profile' : ($activeTab === 'opportunities' ? 'Opportunities' : ($activeTab === 'applicants' ? 'Applicants' : ucfirst($activeTab))) ?></h2><?php endif; ?></div></div>

  <div class="page-content container" style="padding-top:24px"><div>

      <?php if ($success !== ''): ?><div class="company-alert success"><?= e($success) ?></div><?php endif; ?>

      <?php if ($error !== ''): ?><div class="company-alert error"><?= e($error) ?></div><?php endif; ?>



      <section class="tab-panel <?= $activeTab === 'overview' ? 'active' : '' ?>" id="tab-overview">

        <h3 style="margin-bottom:16px">Overview</h3>

        <div class="grid grid-3" style="margin-bottom:28px">

          <div class="card stat-card"><span class="stat-num"><?= $openCount ?></span><span class="stat-label">Open Opportunities</span></div>

          <div class="card stat-card"><span class="stat-num"><?= $totalApplicants ?></span><span class="stat-label">Total Applicants</span></div>

          <div class="card stat-card"><span class="stat-num"><?= $averageRating !== null ? number_format((float)$averageRating, 1) : '&mdash;' ?></span><span class="stat-label">Average Rating</span></div>

        </div>

        <h3 style="margin-bottom:12px">Recent Applicants</h3>

        <?php if (!$recentApplicants): ?><div class="empty-state"><p>No applicants yet.</p></div><?php else: ?>

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Applicant</th><th>Opportunity</th><th>Applied</th></tr></thead><tbody>

          <?php foreach ($recentApplicants as $a): ?><tr><td><?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? '')) ?: 'Unknown') ?></td><td><?= e($a['Title']) ?></td><td><?= display_date($a['SubmissionDate']) ?></td></tr><?php endforeach; ?>

        </tbody></table></div><?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'opportunities' ? 'active' : '' ?>" id="tab-opportunities">

        <div class="flex-between" style="margin-bottom:16px"><p class="text-muted" style="margin:0">Training programs and courses you've published.</p><a href="opportunity-form.php" class="btn btn-primary"><?= icon('add') ?> Add Opportunity</a></div>

        <?php if (!$opportunities): ?><div class="empty-state"><div class="empty-icon"><?= icon('training',40,'icon-muted') ?></div><h3>No opportunities yet</h3><p>Click "Add Opportunity" to publish your first training program or course.</p></div><?php else: ?>

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Views</th><th>Favorites</th><th>Application Method</th><th>Actions</th></tr></thead><tbody>

        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= e($o['Type'] ?? '—') ?></td><td><span class="badge <?= ($o['Status'] ?? '') === 'Open' ? 'badge-success' : 'badge-neutral' ?>"><?= e($o['Status'] ?? '—') ?></span></td><td><strong><?= (int)($o['ViewsCount'] ?? 0) ?></strong></td><td><strong><?= (int)($o['FavouriteCount'] ?? 0) ?></strong></td><td><?= e($o['ApplicationMethod'] ?? '—') ?></td><td><div class="company-actions"><a href="opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>" class="btn btn-ghost btn-sm" title="Edit"><?= icon('edit') ?></a><form method="post" class="company-inline-form" onsubmit="return confirm('Delete this opportunity? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_opportunity"><input type="hidden" name="tab" value="opportunities"><input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Delete"><?= icon('delete',18,'icon-danger') ?></button></form></div></td></tr><?php endforeach; ?>

        </tbody></table></div><?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'applicants' ? 'active' : '' ?>" id="tab-applicants">
        <p class="text-muted" style="margin:0 0 16px">Applicants are grouped under each opportunity. Use the filters to find candidates by qualification, technical skills, field of study, experience, or application status.</p>

        <form method="get" class="applicant-filter-bar">
          <input type="hidden" name="tab" value="applicants">
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
          <div class="applicant-filter-field">
            <label for="qualification_filter">Qualification</label>
            <input id="qualification_filter" name="qualification_filter" class="form-control" value="<?= e($qualificationFilter) ?>" placeholder="e.g. Bachelor's degree">
          </div>
          <div class="applicant-filter-field">
            <label for="skill_filter">Skill</label>
            <input id="skill_filter" name="skill_filter" class="form-control" value="<?= e($skillFilter) ?>" placeholder="e.g. Python, Cybersecurity">
          </div>
          <div class="applicant-filter-field">
            <label for="study_filter">Field of Study</label>
            <input id="study_filter" name="study_filter" class="form-control" value="<?= e($studyFilter) ?>" placeholder="e.g. Computer Science">
          </div>
          <div class="applicant-filter-field">
            <label for="experience_filter">Experience</label>
            <input id="experience_filter" name="experience_filter" class="form-control" value="<?= e($experienceFilter) ?>" placeholder="e.g. Internship, 2 years">
          </div>
          <div class="applicant-filter-actions"><button type="submit" class="btn btn-primary">Apply Filters</button><a href="dashboard-company.php?tab=applicants" class="btn btn-ghost">Reset</a></div>
          <div class="applicant-filter-note">Text filters match part of the information saved in the applicant's profile.</div>
        </form>

        <?php if (!$opportunities): ?>
          <div class="empty-state"><div class="empty-icon"><?= icon('training',40,'icon-muted') ?></div><h3>No opportunities yet</h3><p>Publish an opportunity first. Applicants will appear under each opportunity when they apply through Ufuq.</p><a href="opportunity-form.php" class="btn btn-primary">Add Opportunity</a></div>
        <?php elseif (!$applicantsForDisplay && $hasApplicantFilters): ?>
          <div class="empty-state"><div class="empty-icon"><?= icon('userMale',40,'icon-muted') ?></div><h3>No applicants found</h3><p>Try changing the filters, or check back when students apply.</p></div>
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
                      <?php
                        $appQualification = applicant_profile_value($a, ['Qualifications', 'Qualification', 'DegreeLevel', 'EducationLevel', 'EducationalStatus', 'HighestQualification']);
                        $appSkills = applicant_profile_value($a, ['Skills', 'Skill', 'TechnicalSkills', 'TechnicalSkill', 'UserSkills']);
                        $appStudy = applicant_profile_value($a, ['FieldOfStudy', 'Major', 'StudyField', 'Specialization', 'CollegeMajor']);
                        $appExperience = applicant_profile_value($a, ['Experience', 'WorkExperience', 'YearsOfExperience', 'ExperienceLevel', 'ExperienceDetails']);
                      ?>
                      <?php if ($appQualification !== '' || $appSkills !== '' || $appStudy !== '' || $appExperience !== ''): ?>
                        <details class="applicant-profile-details"><summary>View profile details</summary>
                          <div class="applicant-profile-grid">
                            <?php if ($appQualification !== ''): ?><span><strong>Qualification:</strong> <?= e($appQualification) ?></span><?php endif; ?>
                            <?php if ($appSkills !== ''): ?><span><strong>Skills:</strong> <?= e($appSkills) ?></span><?php endif; ?>
                            <?php if ($appStudy !== ''): ?><span><strong>Field of study:</strong> <?= e($appStudy) ?></span><?php endif; ?>
                            <?php if ($appExperience !== ''): ?><span><strong>Experience:</strong> <?= e($appExperience) ?></span><?php endif; ?>
                          </div>
                        </details>
                      <?php endif; ?>
                    </td>
                    <td><?= display_date($a['SubmissionDate']) ?></td>
                    <td><form method="post" class="company-actions applicant-status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_application_status"><input type="hidden" name="tab" value="applicants"><input type="hidden" name="applicant_id" value="<?= (int)$a['UserID'] ?>"><input type="hidden" name="opportunity_id" value="<?= (int)$a['OpportunityID'] ?>"><select name="application_status" class="form-control status-select" aria-label="Application status"><option value="Submitted" <?= $a['ApplicationStatus'] === 'Submitted' ? 'selected' : '' ?>>Submitted</option><option value="Pending" <?= $a['ApplicationStatus'] === 'Pending' ? 'selected' : '' ?>>Pending</option><option value="Under Review" <?= $a['ApplicationStatus'] === 'Under Review' ? 'selected' : '' ?>>Under Review</option><option value="Accepted" <?= $a['ApplicationStatus'] === 'Accepted' ? 'selected' : '' ?>>Accepted</option><option value="Rejected" <?= $a['ApplicationStatus'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option></select><button type="submit" class="btn btn-primary btn-sm">Update</button></form></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody></table></div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="tab-panel <?= $activeTab === 'engagement' ? 'active' : '' ?>" id="tab-engagement">

        <p class="text-muted" style="margin:0 0 16px">How students are interacting with each of your opportunities.</p>

        <?php if (!$opportunities): ?><div class="empty-state"><div class="empty-icon"><?= icon('goal',40,'icon-muted') ?></div><h3>No engagement data yet</h3><p>Publish an opportunity to start tracking applications and favorites.</p></div><?php else: ?>

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Opportunity</th><th>Views</th><th>Applications</th><th>Favorites</th></tr></thead><tbody>

        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= (int)($o['ViewsCount'] ?? 0) ?></td><td><?= (int)$o['ApplicationCount'] ?></td><td><?= (int)$o['FavouriteCount'] ?></td></tr><?php endforeach; ?>

        </tbody></table></div><p class="company-help" style="margin-top:10px">Views increase when the opportunity details page records visits in the opportunity_view table. Views from before tracking was enabled cannot be recovered.</p><?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'reviews' ? 'active' : '' ?>" id="tab-reviews">

        <div class="flex-between" style="margin-bottom:16px"><p class="text-muted" style="margin:0">What students are saying about your company.</p><span class="rating"><?= $averageRating !== null ? '★ ' . number_format((float)$averageRating, 1) . ' / 5 (' . $reviewCount . ')' : '' ?></span></div>

        <?php if (!$reviews): ?><div class="empty-state"><div class="empty-icon"><?= icon('favorite',40,'icon-muted') ?></div><h3>No reviews yet</h3></div><?php else: ?>

          <?php foreach ($reviews as $r): ?><div class="card" style="margin-bottom:12px"><div class="flex-between"><strong><?= e(trim(($r['FirstName'] ?? '') . ' ' . ($r['LastName'] ?? '')) ?: 'A user') ?></strong><span class="rating-stars"><?= str_repeat('★', max(0,min(5,(int)$r['Rating']))) . str_repeat('☆', 5-max(0,min(5,(int)$r['Rating']))) ?></span></div><div class="company-help">For: <?= e($r['Title']) ?> · <?= display_date($r['CreatedAt']) ?></div><?php if (!empty($r['ReviewText'])): ?><p style="margin:6px 0 0"><?= nl2br(e($r['ReviewText'])) ?></p><?php endif; ?></div><?php endforeach; ?>

        <?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'profile' ? 'active' : '' ?>" id="tab-profile">

        <div style="max-width:640px">

          <?php if ($success !== ''): ?><div class="company-alert success"><?= e($success) ?></div><?php endif; ?>

          <div class="profile-identity company-profile-head"><div class="company-logo"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company logo"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></div><div><h2 style="margin:0 0 4px"><?= e($company['CompanyName']) ?></h2><div class="profile-identity-meta"><?= e($company['IndustrySector'] ?? '') ?></div><div class="profile-identity-line">CR Number: <strong><?= e($company['CrNumber']) ?></strong></div><div class="company-help">Status: <?= e($company['Status']) ?></div></div></div>

          <form method="post" enctype="multipart/form-data" class="card section-card" style="padding:20px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_profile"><input type="hidden" name="tab" value="profile">

            <h3 style="margin-top:0">Company Information</h3>

            <div class="company-field"><label class="form-label" for="company_name">Company Name</label><input class="form-control" type="text" id="company_name" name="company_name" required value="<?= e($company['CompanyName']) ?>"></div>

            <div class="company-field"><label class="form-label" for="phone">Phone</label><input class="form-control" type="tel" id="phone" name="phone" value="<?= e($company['Phone'] ?? '') ?>" pattern="\+?[0-9]{8,15}"><div class="company-help">Enter 8–15 digits, optionally starting with +.</div></div>

            <div class="company-field"><label class="form-label" for="email">Official Email</label><input class="form-control" type="email" id="email" name="email" required value="<?= e($company['Email']) ?>"></div>

            <div class="company-field"><label class="form-label" for="password">New Password</label><input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password"><div class="company-help">If changed, the password is securely hashed before storage.</div></div>

            <hr style="margin:22px 0"><h3>Company Details</h3>

            <div class="company-field"><label class="form-label" for="industry">Industry Sector</label><select class="form-control" id="industry" name="industry"><option value="">Select industry</option><?php foreach ($industryOptions as $industry): ?><option value="<?= e($industry) ?>" <?= ($company['IndustrySector'] ?? '') === $industry ? 'selected' : '' ?>><?= e($industry) ?></option><?php endforeach; ?><?php if (!empty($company['IndustrySector']) && !in_array($company['IndustrySector'], $industryOptions, true)): ?><option selected value="<?= e($company['IndustrySector']) ?>"><?= e($company['IndustrySector']) ?></option><?php endif; ?></select></div>

            <div class="company-field"><label class="form-label" for="description">Company Description</label><textarea class="form-control" id="description" name="description" rows="4" required><?= e($company['Description'] ?? '') ?></textarea></div>

            <div class="company-field"><label class="form-label" for="location">Location</label><select class="form-control" id="location" name="location"><option value="">Select location</option><?php foreach ($locationOptions as $city): ?><option value="<?= e($city) ?>" <?= ($company['Location'] ?? '') === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?><?php if (!empty($company['Location']) && !in_array($company['Location'], $locationOptions, true)): ?><option selected value="<?= e($company['Location']) ?>"><?= e($company['Location']) ?></option><?php endif; ?></select></div>

            <div class="company-field"><label class="form-label" for="website">Website URL</label><input class="form-control" type="url" id="website" name="website" value="<?= e($company['WebsiteURL'] ?? '') ?>" placeholder="https://example.com"></div>

            <div class="company-field"><label class="form-label" for="logo">Company Logo</label><input class="form-control" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg"><div class="company-help">PNG or JPG, maximum 3 MB.</div></div>

            <button type="submit" class="btn btn-primary">Save Changes</button>

          </form>

        </div>

      </section>

    </div>

  </div></div>

</main>

<footer class="site-footer"><div class="container"><div class="footer-bottom"><span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span></div></div></footer>

</body>

</html>
