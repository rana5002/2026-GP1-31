<?php

require __DIR__ . '/bootstrap.php';

require __DIR__ . '/auth.php';



require_login('Company');

$companyId = (int) $_SESSION['user_id'];

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

    (SELECT COUNT(*) FROM favourite f WHERE f.OpportunityID = o.OpportunityID) AS FavouriteCount

    FROM opportunity o WHERE o.CreatedByCompanyID = ? ORDER BY o.OpportunityID DESC');

$stmt->execute([$companyId]);

$opportunities = $stmt->fetchAll();



$stmt = $pdo->prepare('SELECT a.UserID, a.OpportunityID, a.SubmissionDate, a.ApplicationStatus,

    u.FirstName, u.LastName, o.Title

    FROM application a

    JOIN opportunity o ON o.OpportunityID = a.OpportunityID

    LEFT JOIN `user` u ON u.UserID = a.UserID

    WHERE o.CreatedByCompanyID = ?

    ORDER BY a.SubmissionDate DESC, a.OpportunityID DESC');

$stmt->execute([$companyId]);

$applicants = $stmt->fetchAll();



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

:root{--ufuq-navy:#76528b;--ufuq-navy-dark:#51365f;--ufuq-gold:#b99bcf;--ufuq-ink:#34283b;--ufuq-muted:#817487;--ufuq-line:#e9dfeb;--ufuq-bg:#FAF6EF;--ufuq-card:#fffdf9;--ufuq-radius:18px;--ufuq-shadow:0 8px 26px rgba(91,61,108,.08)}

*{box-sizing:border-box}

body{background:var(--ufuq-bg);color:var(--ufuq-ink);font-family:inherit}

a{transition:color .18s ease,background-color .18s ease,transform .18s ease}

.tab-panel{display:none}.tab-panel.active{display:block}.hidden{display:none!important}

#site-header.site-header{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--ufuq-line);box-shadow:0 3px 16px rgba(91,61,108,.045)}

#site-header .container{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:28px}

#site-header .brand{display:inline-flex;align-items:center;gap:10px;color:var(--ufuq-navy);font-size:1.35rem;font-weight:800;letter-spacing:-.03em;white-space:nowrap}

#site-header .brand-mark{width:38px!important;height:38px!important}

#site-header .nav-links{display:flex;align-items:center;justify-content:center;gap:8px;flex:1}

#site-header .nav-links a{position:relative;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#776a80;font-weight:650;font-size:.94rem;padding:12px 16px;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;white-space:nowrap;transition:color .18s ease}

#site-header .nav-links a:hover{color:var(--ufuq-navy);background:transparent!important;box-shadow:none!important}

#site-header .nav-links a.active{color:var(--ufuq-navy);background:transparent!important;box-shadow:none!important}

#site-header .nav-links a.active:after{content:"";position:absolute;bottom:4px;left:16px;right:16px;height:2px;background:var(--ufuq-gold);border-radius:3px}

#site-header .nav-actions{display:flex;align-items:center;gap:12px;white-space:nowrap}

.company-header-profile{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:var(--ufuq-navy);padding:3px;border-radius:50%;outline-offset:3px}

.company-header-profile:hover{background:transparent;transform:translateY(-1px)}

.company-header-avatar{width:42px;height:42px;flex:0 0 42px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0e7f3,#e7d9ed);color:var(--ufuq-navy);font-weight:800;font-size:1.05rem;border:2px solid white;box-shadow:0 0 0 1px #ddcde5}

.company-header-avatar img{width:100%;height:100%;object-fit:cover}

#site-header .btn{border-radius:10px;font-weight:700;padding:10px 15px}

#site-header .btn-ghost{border:1px solid #e4d8e8;color:#4b5669;background:#fffdf9}

#site-header .btn-ghost:hover{background:#f7f8fb;border-color:#d5c3dd;color:var(--ufuq-navy)}

.page-banner{position:relative;overflow:hidden;background:linear-gradient(115deg,var(--ufuq-navy-dark),var(--ufuq-navy) 68%,#8e6ba2);color:#fffdf9;padding:34px 0 38px;border:0}

.page-banner:after{content:"";position:absolute;width:300px;height:300px;border:1px solid rgba(255,255,255,.1);border-radius:50%;right:7%;top:-205px;box-shadow:0 0 0 35px rgba(255,255,255,.025),0 0 0 75px rgba(255,255,255,.02);pointer-events:none}

.page-banner .container{position:relative;z-index:1}

.company-welcome{margin:0;font-size:clamp(1.45rem,3vw,2rem);font-weight:800;letter-spacing:-.035em;color:#fffdf9;line-height:1.25}

.company-welcome-subtitle{margin-top:9px;color:#e9dcef;font-size:.98rem;letter-spacing:.01em}

.page-banner h2:not(.company-welcome){margin:0;font-size:1.8rem;font-weight:800;letter-spacing:-.025em;color:#fffdf9}

.page-content.container{padding-top:30px!important;padding-bottom:54px;min-height:58vh}

.page-content h3{color:var(--ufuq-ink);font-size:1.12rem;font-weight:800;letter-spacing:-.015em}

.grid.grid-3{gap:18px}

.card,.section-card{background:var(--ufuq-card);border:1px solid var(--ufuq-line);border-radius:var(--ufuq-radius);box-shadow:var(--ufuq-shadow)}

.stat-card{position:relative;overflow:hidden;display:flex;flex-direction:column;gap:8px;padding:24px 24px 23px;min-height:132px;border-radius:var(--ufuq-radius);transition:transform .18s ease,box-shadow .18s ease}

.stat-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--ufuq-gold)}

.stat-card:hover{transform:translateY(-3px);box-shadow:0 13px 30px rgba(91,61,108,.12)}

.stat-num{font-size:2.1rem!important;line-height:1.15;font-weight:850!important;color:var(--ufuq-navy)!important;letter-spacing:-.04em}

.stat-label{font-size:.91rem;color:var(--ufuq-muted);font-weight:600}

.company-table-wrap{overflow-x:auto;border-radius:var(--ufuq-radius)}

.data-table{width:100%;border-collapse:separate;border-spacing:0;min-width:620px}

.data-table thead th{background:#faf5f0;color:#776a80;font-size:.78rem;text-transform:uppercase;letter-spacing:.055em;font-weight:800;padding:15px 18px;border-bottom:1px solid var(--ufuq-line);white-space:nowrap}

.data-table tbody td{padding:16px 18px;border-bottom:1px solid #eee5ef;color:#4d4053;font-size:.91rem;vertical-align:middle}

.data-table tbody tr:last-child td{border-bottom:0}

.data-table tbody tr:hover{background:#fdf9f4}

.data-table tbody td:first-child{font-weight:650;color:#44364a}

.badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-size:.76rem;font-weight:750;white-space:nowrap}

.badge-success{background:#e8f4e9;color:#28734b}.badge-neutral{background:#f1eaf3;color:#606c7d}

.btn{border-radius:10px;font-weight:700;transition:transform .18s ease,box-shadow .18s ease}

.btn-primary{background:var(--ufuq-navy);border-color:var(--ufuq-navy);color:#fffdf9}

.btn-primary:hover{background:var(--ufuq-navy-dark);border-color:var(--ufuq-navy-dark);transform:translateY(-1px);box-shadow:0 5px 13px rgba(118,82,139,.2)}

.btn-ghost{border:1px solid var(--ufuq-line);background:#fffdf9;color:#62556a}

.btn-ghost:hover{background:#f0e7f3;color:var(--ufuq-navy)}

.btn .icon-danger,.icon-danger{color:#c2414b}

.flex-between{display:flex;align-items:center;justify-content:space-between;gap:16px}

.company-profile-head{display:flex;gap:20px;align-items:center;margin-bottom:24px;padding:22px;background:#fffdf9;border:1px solid var(--ufuq-line);border-radius:var(--ufuq-radius);box-shadow:var(--ufuq-shadow)}

.company-logo{width:84px;height:84px;flex:0 0 84px;border-radius:22px;object-fit:cover;border:1px solid #e1e6ef;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0e7f3,#f9fafc);color:var(--ufuq-navy);font-size:1.8rem;font-weight:800;overflow:hidden}

.company-logo img{width:100%;height:100%;object-fit:cover}

.company-field{margin-bottom:19px}.company-field label{display:block;margin-bottom:8px;font-size:.9rem;font-weight:700;color:#4d4053}.company-field .form-control{width:100%;min-height:45px;border:1px solid #dfd2e4;border-radius:10px;background:#fffdf9;padding:11px 13px;color:var(--ufuq-ink);transition:border-color .15s,box-shadow .15s}

.company-field .form-control:focus,.status-select:focus{outline:none;border-color:#a88bb8;box-shadow:0 0 0 3px rgba(118,82,139,.14)}

.company-field textarea.form-control{min-height:115px;resize:vertical}

.company-alert{padding:14px 17px;border-radius:12px;margin-bottom:18px;font-size:.92rem;font-weight:600;border:1px solid transparent}

.company-alert.success{background:#eaf8f0;color:#1b7044;border-color:#ccebd9}.company-alert.error{background:#fff1f1;color:#a62c36;border-color:#f4d1d4}

.status-select{padding:8px 10px;min-width:135px;border:1px solid #dfd2e4;border-radius:9px;background:#fffdf9;color:#3d485a}

.company-inline-form{display:inline}.company-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.field-view{margin-bottom:16px}.field-label{font-size:.82rem;color:var(--ufuq-muted);margin-bottom:4px}.rating,.rating-stars{color:#96704a;font-weight:750}.company-section-card{margin-bottom:18px}.company-help{font-size:.82rem;color:var(--ufuq-muted);line-height:1.55}

.empty-state{background:#fffdf9;border:1px dashed #d7deea;border-radius:var(--ufuq-radius);padding:38px 24px;text-align:center;color:var(--ufuq-muted)}

.empty-state h3{margin:12px 0 7px}.empty-state p{margin:0;color:var(--ufuq-muted)}

.profile-identity h2{color:var(--ufuq-navy);font-weight:800;letter-spacing:-.025em}.profile-identity-meta{color:var(--ufuq-muted);margin:4px 0}.profile-identity-line{font-size:.85rem;color:#6b7586;margin-top:8px}

#tab-profile>div{max-width:780px!important;margin-inline:auto}

#tab-profile .section-card{padding:28px!important}

#tab-profile hr{border:0;border-top:1px solid var(--ufuq-line)}

.site-footer{background:#fffdf9;border-top:1px solid var(--ufuq-line);color:var(--ufuq-muted)}

@media(max-width:760px){#site-header .container{min-height:68px;gap:10px;flex-wrap:wrap;padding-top:10px;padding-bottom:10px}#site-header .brand{font-size:1.15rem}#site-header .brand-mark{width:31px!important;height:31px!important}#site-header .nav-links{order:3;flex-basis:100%;gap:3px;justify-content:space-between;border-top:1px solid #eee5ef;padding-top:7px}#site-header .nav-links a{font-size:.82rem;padding:9px 10px;flex:1;text-align:center}#site-header .nav-links a.active:after{left:10px;right:10px;bottom:2px}.company-header-avatar{width:36px;height:36px;flex-basis:36px}#site-header .btn{padding:8px 10px;font-size:.82rem}.page-banner{padding:26px 0 30px}.page-content.container{padding-top:22px!important}.grid.grid-3{grid-template-columns:1fr;gap:12px}.stat-card{min-height:unset;padding:19px 20px}.stat-num{font-size:1.8rem!important}.flex-between{align-items:flex-start;flex-wrap:wrap}.company-profile-head{align-items:flex-start;padding:17px;gap:14px}.company-logo{width:68px;height:68px;flex-basis:68px;border-radius:18px}#tab-profile .section-card{padding:18px!important}}

/* Additional Ufuq theme polish */

.page-content.container>div{width:100%;max-width:1180px;margin:0 auto}

#tab-overview>h3,#tab-opportunities>h3,#tab-applicants>h3,#tab-engagement>h3,#tab-reviews>h3{position:relative;padding-bottom:11px}

#tab-overview>h3:after{content:"";display:block;width:42px;height:3px;border-radius:5px;background:var(--ufuq-gold);position:absolute;left:0;bottom:0}

#tab-overview .card.company-table-wrap,#tab-opportunities .card.company-table-wrap,#tab-applicants .card.company-table-wrap,#tab-engagement .card.company-table-wrap{border-radius:16px;overflow-x:auto}

#tab-profile>div{max-width:900px!important;margin-inline:auto}

#tab-profile .section-card{padding:30px!important}

#tab-profile .section-card>h3{padding-bottom:12px;margin-bottom:20px;border-bottom:1px solid var(--ufuq-line);position:relative}

#tab-profile .section-card>h3:after{content:"";position:absolute;left:0;bottom:-1px;width:48px;height:3px;background:var(--ufuq-gold);border-radius:3px}

#tab-profile .section-card .btn-primary{min-height:44px;padding:11px 22px;box-shadow:0 5px 12px rgba(31,53,102,.12)}

.company-field .form-control::placeholder{color:#a79baa}

.company-field input[type=file].form-control{padding:8px;background:#fffdf9}

.company-field input[type=file]::file-selector-button{margin-right:12px;border:0;border-radius:7px;background:#f0e7f3;color:var(--ufuq-navy);padding:8px 12px;font-weight:700;cursor:pointer}

.status-select{min-height:36px;font-size:.85rem}

.company-actions .btn{min-height:34px;display:inline-flex;align-items:center;justify-content:center}

.company-inline-form .btn{min-width:36px}

#tab-reviews>.card{padding:20px 22px;border-radius:15px;transition:transform .18s ease,box-shadow .18s ease}

#tab-reviews>.card:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(91,61,108,.11)}

#tab-reviews .rating-stars{letter-spacing:2px;font-size:1.05rem}

#tab-reviews .company-help{margin-top:6px}

#tab-opportunities .flex-between .btn{white-space:nowrap;box-shadow:0 5px 12px rgba(31,53,102,.12)}

.company-alert{display:flex;align-items:center;gap:10px;line-height:1.5}

.company-alert.success{box-shadow:inset 3px 0 0 #39a76b}

.company-alert.error{box-shadow:inset 3px 0 0 #d34c56}

.site-footer{padding:18px 0}

@media(max-width:760px){

 .page-content.container>div{max-width:100%}

 #tab-profile>div{max-width:100%!important}

 #tab-profile .section-card{padding:19px!important}

 #tab-reviews>.card{padding:16px}

 #tab-opportunities .flex-between{align-items:stretch}

 #tab-opportunities .flex-between .btn{width:100%;justify-content:center}

 .data-table thead th,.data-table tbody td{padding:12px 13px}

}

@media(max-width:430px){

 #site-header .nav-actions{gap:5px}

 #site-header .nav-links a{font-size:.76rem;padding:9px 5px}

 .company-profile-head{flex-direction:column}

 .company-profile-head .company-logo{width:64px;height:64px;flex-basis:64px}

 .page-banner h2:not(.company-welcome){font-size:1.45rem}

 .company-alert{align-items:flex-start;padding:12px}

}

@media(prefers-reduced-motion:reduce){*,*:before,*:after{transition:none!important;scroll-behavior:auto!important}}

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

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Application Method</th><th>Actions</th></tr></thead><tbody>

        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td><?= e($o['Type'] ?? '—') ?></td><td><span class="badge <?= ($o['Status'] ?? '') === 'Open' ? 'badge-success' : 'badge-neutral' ?>"><?= e($o['Status'] ?? '—') ?></span></td><td><?= e($o['ApplicationMethod'] ?? '—') ?></td><td><div class="company-actions"><a href="opportunity-form.php?id=<?= (int)$o['OpportunityID'] ?>" class="btn btn-ghost btn-sm" title="Edit"><?= icon('edit') ?></a><form method="post" class="company-inline-form" onsubmit="return confirm('Delete this opportunity? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_opportunity"><input type="hidden" name="tab" value="opportunities"><input type="hidden" name="opportunity_id" value="<?= (int)$o['OpportunityID'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Delete"><?= icon('delete',18,'icon-danger') ?></button></form></div></td></tr><?php endforeach; ?>

        </tbody></table></div><?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'applicants' ? 'active' : '' ?>" id="tab-applicants">

        <p class="text-muted" style="margin:0 0 16px">Everyone who applied to one of your opportunities through Ufuq.</p>

        <?php if (!$applicants): ?><div class="empty-state"><div class="empty-icon"><?= icon('userMale',40,'icon-muted') ?></div><h3>No applicants yet</h3><p>Applications submitted directly through Ufuq will appear here. External applications are not tracked here.</p></div><?php else: ?>

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Applicant</th><th>Opportunity</th><th>Applied On</th><th>Status</th></tr></thead><tbody>

        <?php foreach ($applicants as $a): ?><tr><td><?= e(trim(($a['FirstName'] ?? '') . ' ' . ($a['LastName'] ?? '')) ?: 'Unknown') ?></td><td><?= e($a['Title']) ?></td><td><?= display_date($a['SubmissionDate']) ?></td><td><form method="post" class="company-actions"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_application_status"><input type="hidden" name="tab" value="applicants"><input type="hidden" name="applicant_id" value="<?= (int)$a['UserID'] ?>"><input type="hidden" name="opportunity_id" value="<?= (int)$a['OpportunityID'] ?>"><select name="application_status" class="form-control status-select" aria-label="Application status"><option value="Pending" <?= $a['ApplicationStatus'] === 'Pending' ? 'selected' : '' ?>>Pending</option><option value="Under Review" <?= $a['ApplicationStatus'] === 'Under Review' ? 'selected' : '' ?>>Under Review</option><option value="Accepted" <?= $a['ApplicationStatus'] === 'Accepted' ? 'selected' : '' ?>>Accepted</option><option value="Rejected" <?= $a['ApplicationStatus'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option></select><button type="submit" class="btn btn-primary btn-sm">Update</button></form></td></tr><?php endforeach; ?>

        </tbody></table></div><?php endif; ?>

      </section>



      <section class="tab-panel <?= $activeTab === 'engagement' ? 'active' : '' ?>" id="tab-engagement">

        <p class="text-muted" style="margin:0 0 16px">How students are interacting with each of your opportunities.</p>

        <?php if (!$opportunities): ?><div class="empty-state"><div class="empty-icon"><?= icon('goal',40,'icon-muted') ?></div><h3>No engagement data yet</h3><p>Publish an opportunity to start tracking applications and favorites.</p></div><?php else: ?>

        <div class="card company-table-wrap"><table class="data-table"><thead><tr><th>Opportunity</th><th>Views</th><th>Applications</th><th>Favorites</th></tr></thead><tbody>

        <?php foreach ($opportunities as $o): ?><tr><td><?= e($o['Title']) ?></td><td title="View tracking is not configured">N/A</td><td><?= (int)$o['ApplicationCount'] ?></td><td><?= (int)$o['FavouriteCount'] ?></td></tr><?php endforeach; ?>

        </tbody></table></div><p class="company-help" style="margin-top:10px">Views are shown as N/A because the current database does not store opportunity views.</p><?php endif; ?>

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
