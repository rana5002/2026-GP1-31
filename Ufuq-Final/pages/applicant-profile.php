<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_login('Company');

$companyId = (int) $_SESSION['user_id'];
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$focusOpportunity = filter_input(INPUT_GET, 'opportunity', FILTER_VALIDATE_INT) ?: 0;

/* Application statuses allowed by the database */
$validApplicantStatuses = ['Pending', 'Accepted', 'Rejected'];

/* ---------- Company (for the header) ---------- */
$stmt = $pdo->prepare('SELECT CompanyID, CompanyName, Logo FROM company WHERE CompanyID = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$company) { http_response_code(404); exit('Company profile not found.'); }

$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (str_starts_with($logo, 'http') ? $logo : '../' . ltrim($logo, '/'));
$companyInitial = strtoupper(substr((string)$company['CompanyName'], 0, 1));

/* ---------- Applications of this user to THIS company's opportunities ----------
   A company may only see applicants who applied to one of its own opportunities. */
$stmt = $pdo->prepare(
    'SELECT a.OpportunityID, a.SubmissionDate, a.ApplicationStatus, o.Title, o.Type
     FROM application a
     JOIN opportunity o ON o.OpportunityID = a.OpportunityID
     WHERE a.UserID = ? AND o.CreatedByCompanyID = ?
     ORDER BY a.SubmissionDate DESC'
);
$stmt->execute([$userId, $companyId]);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$userId || !$applications) {
    header('Location: dashboard-company.php?tab=applicants');
    exit;
}

$appliedIds = array_map(static fn($a) => (int)$a['OpportunityID'], $applications);
if (!in_array($focusOpportunity, $appliedIds, true)) {
    $focusOpportunity = $appliedIds[0];
}

/* ---------- Applicant information ---------- */
$stmt = $pdo->prepare('SELECT u.*, m.Email FROM `user` u JOIN member m ON m.MemberID = u.UserID WHERE u.UserID = ?');
$stmt->execute([$userId]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$applicant) {
    header('Location: dashboard-company.php?tab=applicants');
    exit;
}

$stmt = $pdo->prepare('SELECT FieldOfStudy, DegreeLevel, UniversityName, GraduationYear FROM userstudy WHERE UserID = ? ORDER BY GraduationYear DESC, FieldID');
$stmt->execute([$userId]);
$studies = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT s.SkillID, s.SkillName FROM userskill us JOIN skill s ON s.SkillID = us.SkillID WHERE us.UserID = ? ORDER BY s.SkillName');
$stmt->execute([$userId]);
$skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT q.QualificationID, q.QualificationName, uq.Institution, uq.YearObtained, uq.Duration
     FROM userqualification uq JOIN qualification q ON q.QualificationID = uq.QualificationID
     WHERE uq.UserID = ? ORDER BY uq.YearObtained DESC, q.QualificationName'
);
$stmt->execute([$userId]);
$qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT e.ExperienceID, e.ExperienceName, ue.JobTitle, ue.Organization, ue.StartDate, ue.EndDate, ue.Duration
     FROM userexperience ue JOIN experience e ON e.ExperienceID = ue.ExperienceID
     WHERE ue.UserID = ? ORDER BY ue.StartDate DESC'
);
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- What the selected opportunity asks for (to highlight matches) ---------- */
function opportunity_required_ids(PDO $pdo, string $table, string $idCol, int $opportunityId): array {
    $stmt = $pdo->prepare("SELECT `$idCol` FROM `$table` WHERE OpportunityID = ?");
    $stmt->execute([$opportunityId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
$requiredSkills = opportunity_required_ids($pdo, 'opportunityskill', 'SkillID', $focusOpportunity);
$requiredQualifications = opportunity_required_ids($pdo, 'opportunityqualification', 'QualificationID', $focusOpportunity);
$requiredExperience = opportunity_required_ids($pdo, 'opportunityexperience', 'ExperienceID', $focusOpportunity);

$skillIds = array_map(static fn($s) => (int)$s['SkillID'], $skills);
$matchedSkills = count(array_intersect($requiredSkills, $skillIds));

$focusTitle = '';
foreach ($applications as $app) {
    if ((int)$app['OpportunityID'] === $focusOpportunity) { $focusTitle = (string)$app['Title']; break; }
}

/* ---------- Display helpers ---------- */
function display_date($date, string $format = 'M j, Y'): string {
    return $date ? e(date($format, strtotime((string)$date))) : '&mdash;';
}

/* Durations are shown in days, the exact value stored in the database. */
function days_text($days): string {
    if ($days === null || $days === '' || (int)$days < 0) return '';
    $days = (int)$days;
    return number_format($days) . ' day' . ($days === 1 ? '' : 's');
}

$fullName = trim(($applicant['FirstName'] ?? '') . ' ' . ($applicant['LastName'] ?? '')) ?: 'Applicant';
$initials = strtoupper(mb_substr((string)($applicant['FirstName'] ?? 'A'), 0, 1) . mb_substr((string)($applicant['LastName'] ?? ''), 0, 1));
/* Same default avatars as the user pages: Gender is 'M' or 'F'. */
$gender = strtoupper(substr((string)($applicant['Gender'] ?? ''), 0, 1));
$avatar = $gender === 'M' ? 'default-avatar-male.png' : ($gender === 'F' ? 'default-avatar-female.png' : '');
$genderText = $gender === 'M' ? 'Male' : ($gender === 'F' ? 'Female' : '—');

$success = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($fullName) ?> &mdash; Applicant Profile &mdash; Ufuq</title>
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">
<style>
/* ===== Applicant profile (same design as the company dashboard) ===== */
.company-dashboard{
    --c-purple:#39265f;--c-purple-dark:#281a46;--c-lavender:#f0ebf8;--c-gold:#e2a94b;--c-bg:#faf6ef;
    --c-text:#29243a;--c-muted:#777184;--c-border:#e9e3ed;
    color:var(--c-text);padding-bottom:48px;background:var(--c-bg);min-height:70vh;
}
.company-dashboard *,.company-dashboard *::before,.company-dashboard *::after{box-sizing:border-box}

/* Banner */
.company-dashboard .dashboard-hero{position:relative;overflow:hidden;padding:38px 0 42px;margin-bottom:28px;background:linear-gradient(125deg,#281a46 0%,#39265f 55%,#49316f 100%);color:#fff}
.company-dashboard .dashboard-hero::before,.company-dashboard .dashboard-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.company-dashboard .dashboard-hero::before{width:250px;height:250px;right:7%;top:-150px;border:35px solid rgba(255,255,255,.07)}
.company-dashboard .dashboard-hero::after{width:190px;height:190px;right:23%;bottom:-145px;background:rgba(226,169,75,.10)}
.company-dashboard .dashboard-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:22px}
.company-dashboard .welcome-label{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;margin-bottom:12px;border:1px solid rgba(255,255,255,.2);border-radius:30px;background:rgba(255,255,255,.08);font-size:.84rem;letter-spacing:.2px}
.company-dashboard .dashboard-hero h1{margin:0;color:#fff;font-size:clamp(1.65rem,3vw,2.15rem);font-weight:750;line-height:1.5}
.company-dashboard .dashboard-hero p{margin:8px 0 0;color:rgba(255,255,255,.82);font-size:.96rem;line-height:1.8}
.company-dashboard .hero-icon{display:flex;align-items:center;justify-content:center;width:78px;height:78px;flex-shrink:0;border:1px solid rgba(255,255,255,.2);border-radius:24px;background:rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(15,8,30,.16);font-size:2rem;animation:companyFloat 4s ease-in-out infinite}
.company-dashboard .dashboard-content{max-width:1000px;animation:companyFadeUp .5s ease both}

.company-dashboard .back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;color:var(--c-purple);font-size:.88rem;font-weight:700;text-decoration:none}
.company-dashboard .back-link:hover{color:var(--c-purple-dark);text-decoration:underline}
.company-dashboard .company-alert{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;margin-bottom:22px;border-radius:13px;font-size:.9rem;line-height:1.6;border:1px solid #b8e5cf;background:#e4f6ed;color:#286546}

/* Identity card */
.company-dashboard .profile-identity,.company-dashboard .profile-section{border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(40,26,70,.045)}
.company-dashboard .profile-identity{display:flex;align-items:center;gap:22px;padding:25px;margin-bottom:22px}
.company-dashboard .profile-avatar{display:flex;align-items:center;justify-content:center;flex:0 0 92px;width:92px;height:92px;overflow:hidden;border:4px solid #f0ebf8;border-radius:50%;background:var(--c-lavender);color:var(--c-purple);font-size:1.8rem;font-weight:750}
.company-dashboard .profile-avatar img{width:100%;height:100%;object-fit:cover}
.company-dashboard .profile-identity-details{flex:1;min-width:0}
.company-dashboard .profile-identity-details h2{margin:0;color:var(--c-text);font-size:1.4rem;overflow-wrap:anywhere}
.company-dashboard .profile-subtitle{margin-top:6px;color:var(--c-muted);font-size:.92rem}
.company-dashboard .profile-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.company-dashboard .profile-tag{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:#f7f4fb;border:1px solid #e8e0f2;color:#4a3f5e;font-size:.8rem;font-weight:650}
.company-dashboard .match-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#fbf6eb;border:1px solid #eee4d1;color:#76531d;font-size:.82rem;font-weight:750;white-space:nowrap}

/* Sections */
.company-dashboard .profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
.company-dashboard .profile-grid .is-wide{grid-column:1/-1}
.company-dashboard .profile-section{padding:22px 24px;min-width:0}
.company-dashboard .profile-section-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-bottom:15px;margin-bottom:17px;border-bottom:1px solid #e9e4ef}
.company-dashboard .profile-section-header h3{display:flex;align-items:center;gap:10px;margin:0;color:var(--c-text);font-size:1.05rem;font-weight:750}
.company-dashboard .profile-section-header h3::before{content:"";display:block;width:5px;height:22px;border-radius:5px;background:var(--c-gold)}
.company-dashboard .profile-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 22px}
.company-dashboard .profile-field-label{margin-bottom:6px;color:var(--c-muted);font-size:.8rem}
.company-dashboard .profile-field-value{color:var(--c-text);font-size:.93rem;line-height:1.6;overflow-wrap:anywhere}
.company-dashboard .profile-field-value a{color:var(--c-purple);font-weight:600}
.company-dashboard .profile-empty{margin:0;padding:14px;border:1px dashed #ded5ec;border-radius:12px;background:#faf9fd;color:var(--c-muted);font-size:.87rem}

/* Lists */
.company-dashboard .info-list{display:flex;flex-direction:column;gap:12px;margin:0;padding:0;list-style:none}
.company-dashboard .info-item{padding:14px 16px;border:1px solid #eee9f5;border-radius:12px;background:#faf9fd}
.company-dashboard .info-item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap}
.company-dashboard .info-item-title{margin:0;color:var(--c-text);font-size:.95rem;font-weight:750;overflow-wrap:anywhere}
.company-dashboard .info-item-sub{margin-top:3px;color:#5e596c;font-size:.87rem;overflow-wrap:anywhere}
.company-dashboard .info-item-meta{margin-top:6px;color:var(--c-muted);font-size:.8rem}
.company-dashboard .skill-tags{display:flex;flex-wrap:wrap;gap:8px}
.company-dashboard .skill-tag{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#f2edfa;border:1px solid #e4d9f3;color:var(--c-purple);font-size:.82rem;font-weight:700}
.company-dashboard .skill-tag.is-match,.company-dashboard .info-item.is-match{border-color:#c5e9d0;background:#eef9f2}
.company-dashboard .skill-tag.is-match{color:#176b3a}
.company-dashboard .match-note{margin:12px 0 0;color:var(--c-muted);font-size:.8rem}

/* Applications table */
.company-dashboard .company-table-wrap{overflow-x:auto}
.company-dashboard .data-table{width:100%;margin:0;border-collapse:collapse}
.company-dashboard .data-table th{padding:12px 14px;color:var(--c-purple);font-size:.84rem;font-weight:750;text-align:start;white-space:nowrap;background:#f1edf7;border-bottom:1px solid #e6deef}
.company-dashboard .data-table td{padding:13px 14px;border-bottom:1px solid #f0edf4;color:#464052;font-size:.9rem;vertical-align:middle}
.company-dashboard .data-table tbody tr:last-child td{border-bottom:none}
.company-dashboard .data-table tr.is-focus td{background:#faf7fd}
.company-dashboard .status-form{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.company-dashboard .status-select{padding:7px 9px;min-width:125px}
.company-dashboard .badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:30px;font-size:.76rem;font-weight:750;white-space:nowrap}
.company-dashboard .btn-primary{background:var(--c-purple);border-color:var(--c-purple)}
.company-dashboard .btn-primary:hover{background:var(--c-purple-dark);border-color:var(--c-purple-dark)}

/* Header avatar (same as the dashboard) */
.company-header-profile{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;padding:5px 8px;border-radius:10px;white-space:nowrap}
.company-header-profile:hover{background:var(--color-surface-alt,#f3f4f6)}
.company-header-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e9edf5;color:var(--color-primary,#1f3566);font-weight:700;border:1px solid var(--color-border,#ddd)}
.company-header-avatar img{width:100%;height:100%;object-fit:cover}

@media(max-width:760px){
  .company-dashboard .profile-grid{grid-template-columns:1fr}
}
@media(max-width:650px){
  .company-dashboard .dashboard-hero{padding:30px 0}
  .company-dashboard .hero-icon{width:60px;height:60px;border-radius:18px;font-size:1.6rem}
  .company-dashboard .profile-identity{align-items:flex-start;gap:15px;padding:19px;flex-wrap:wrap}
  .company-dashboard .profile-avatar{flex-basis:70px;width:70px;height:70px;font-size:1.4rem}
  .company-dashboard .profile-identity-details h2{font-size:1.15rem}
  .company-dashboard .profile-section{padding:18px 16px}
  .company-dashboard .profile-view-grid{grid-template-columns:1fr;gap:15px}
}
@keyframes companyFadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes companyFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<header id="site-header" class="site-header">
  <div class="container">
    <a href="../index.php" class="brand" style="text-decoration:none"><img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent"> Ufuq</a>
    <nav class="nav-links"><a href="dashboard-company.php?tab=overview">Dashboard</a><a href="dashboard-company.php?tab=opportunities">Opportunities</a><a href="dashboard-company.php?tab=applicants" class="active">Applicants</a></nav>
    <div class="nav-actions" style="display:flex;align-items:center;gap:10px"><a href="dashboard-company.php?tab=profile" class="company-header-profile" title="Open company profile" aria-label="Open company profile"><span class="company-header-avatar"><?php if ($logoSrc !== ''): ?><img src="<?= e($logoSrc) ?>" alt="Company avatar"><?php else: ?><?= e($companyInitial) ?><?php endif; ?></span></a><a href="logout.php" class="btn btn-ghost btn-sm">Log Out</a></div>
  </div>
</header>

<main>
<div class="company-dashboard">

  <section class="dashboard-hero">
    <div class="container dashboard-hero-inner">
      <div>
        <div class="welcome-label"><span aria-hidden="true">✦</span><span>Ufuq Company Space</span></div>
        <h1>Applicant Profile</h1>
        <p>Review the applicant's background before deciding on their application.</p>
      </div>
      <div class="hero-icon" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20.5c0-4 3.4-6.5 7.5-6.5s7.5 2.5 7.5 6.5"/></svg></div>
    </div>
  </section>

  <div class="container dashboard-content">

    <a href="dashboard-company.php?tab=applicants" class="back-link"><span aria-hidden="true">&larr;</span> Back to Applicants</a>

    <?php if ($success !== ''): ?><div class="company-alert" role="status"><span aria-hidden="true">✓</span><span><?= e($success) ?></span></div><?php endif; ?>

    <!-- Identity -->
    <section class="profile-identity">
      <div class="profile-avatar">
        <?php if ($avatar !== ''): ?><img src="../images-website/<?= e($avatar) ?>" alt="<?= e($fullName) ?>"><?php else: ?><?= e($initials) ?><?php endif; ?>
      </div>
      <div class="profile-identity-details">
        <h2><?= e($fullName) ?></h2>
        <div class="profile-subtitle">
          <?= e($applicant['EducationalStatus'] ?: 'Educational status not specified') ?>
          <?php if (!empty($studies[0]['FieldOfStudy'])): ?> &middot; <?= e($studies[0]['FieldOfStudy']) ?><?php endif; ?>
        </div>
        <div class="profile-tags">
          <span class="profile-tag"><?= (int)$applicant['Age'] ?> years old</span>
          <span class="profile-tag"><?= e($genderText) ?></span>
          <span class="profile-tag"><?= count($applications) ?> <?= count($applications) === 1 ? 'application' : 'applications' ?> to your opportunities</span>
        </div>
      </div>
      <?php if ($requiredSkills): ?>
        <span class="match-pill" title="Required skills of <?= e($focusTitle) ?>">★ <?= $matchedSkills ?> / <?= count($requiredSkills) ?> required skills</span>
      <?php endif; ?>
    </section>

    <div class="profile-grid">

      <!-- Contact -->
      <section class="profile-section">
        <div class="profile-section-header"><h3>Contact Information</h3></div>
        <div class="profile-view-grid">
          <div><div class="profile-field-label">Email</div><div class="profile-field-value"><a href="mailto:<?= e($applicant['Email']) ?>"><?= e($applicant['Email']) ?></a></div></div>
          <div><div class="profile-field-label">Phone</div><div class="profile-field-value"><?= e($applicant['Phone'] ?: '—') ?></div></div>
        </div>
      </section>

      <!-- Personal -->
      <section class="profile-section">
        <div class="profile-section-header"><h3>Personal Information</h3></div>
        <div class="profile-view-grid">
          <div><div class="profile-field-label">Date of Birth</div><div class="profile-field-value"><?= display_date($applicant['DateOfBirth']) ?></div></div>
          <div><div class="profile-field-label">Age</div><div class="profile-field-value"><?= (int)$applicant['Age'] ?></div></div>
          <div><div class="profile-field-label">Gender</div><div class="profile-field-value"><?= e($genderText) ?></div></div>
          <div><div class="profile-field-label">Educational Status</div><div class="profile-field-value"><?= e($applicant['EducationalStatus'] ?: '—') ?></div></div>
        </div>
      </section>

      <!-- Education -->
      <section class="profile-section is-wide">
        <div class="profile-section-header"><h3>Education</h3></div>
        <?php if (!$studies): ?>
          <p class="profile-empty">No education details added.</p>
        <?php else: ?>
          <ul class="info-list">
            <?php foreach ($studies as $study): ?>
              <li class="info-item">
                <div class="info-item-top">
                  <div>
                    <p class="info-item-title"><?= e($study['FieldOfStudy']) ?></p>
                    <div class="info-item-sub"><?= e($study['DegreeLevel'] ?: 'Degree not specified') ?><?php if (!empty($study['UniversityName'])): ?> &middot; <?= e($study['UniversityName']) ?><?php endif; ?></div>
                  </div>
                  <?php if (!empty($study['GraduationYear'])): ?><span class="profile-tag">Graduation <?= (int)$study['GraduationYear'] ?></span><?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <!-- Skills -->
      <section class="profile-section is-wide">
        <div class="profile-section-header"><h3>Skills</h3></div>
        <?php if (!$skills): ?>
          <p class="profile-empty">No skills added.</p>
        <?php else: ?>
          <div class="skill-tags">
            <?php foreach ($skills as $skill): $isMatch = in_array((int)$skill['SkillID'], $requiredSkills, true); ?>
              <span class="skill-tag <?= $isMatch ? 'is-match' : '' ?>"><?= $isMatch ? '✓ ' : '' ?><?= e($skill['SkillName']) ?></span>
            <?php endforeach; ?>
          </div>
          <?php if ($requiredSkills): ?><p class="match-note">Skills marked ✓ are required by "<?= e($focusTitle) ?>".</p><?php endif; ?>
        <?php endif; ?>
      </section>

      <!-- Qualifications -->
      <section class="profile-section">
        <div class="profile-section-header"><h3>Qualifications</h3></div>
        <?php if (!$qualifications): ?>
          <p class="profile-empty">No qualifications added.</p>
        <?php else: ?>
          <ul class="info-list">
            <?php foreach ($qualifications as $q): $isMatch = in_array((int)$q['QualificationID'], $requiredQualifications, true); ?>
              <li class="info-item <?= $isMatch ? 'is-match' : '' ?>">
                <p class="info-item-title"><?= $isMatch ? '✓ ' : '' ?><?= e($q['QualificationName']) ?></p>
                <div class="info-item-sub"><?= e($q['Institution']) ?></div>
                <div class="info-item-meta">
                  Obtained <?= (int)$q['YearObtained'] ?>
                  <?php $qDur = days_text($q['Duration']); ?>
                  <?php if ($qDur !== ''): ?> &middot; <?= e($qDur) ?><?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <!-- Experience -->
      <section class="profile-section">
        <div class="profile-section-header"><h3>Experience</h3></div>
        <?php if (!$experiences): ?>
          <p class="profile-empty">No experience added.</p>
        <?php else: ?>
          <ul class="info-list">
            <?php foreach ($experiences as $x): $isMatch = in_array((int)$x['ExperienceID'], $requiredExperience, true); ?>
              <li class="info-item <?= $isMatch ? 'is-match' : '' ?>">
                <p class="info-item-title"><?= $isMatch ? '✓ ' : '' ?><?= e($x['JobTitle']) ?></p>
                <div class="info-item-sub"><?= e($x['Organization']) ?> &middot; <?= e($x['ExperienceName']) ?></div>
                <div class="info-item-meta">
                  <?= display_date($x['StartDate'], 'M Y') ?> &ndash; <?= $x['EndDate'] ? display_date($x['EndDate'], 'M Y') : 'Present' ?>
                  <?php $dur = days_text($x['Duration']); ?>
                  <?php if ($dur !== ''): ?> &middot; <?= e($dur) ?><?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </div>
  </div>
</div>
</main>

<footer class="site-footer"><div class="container"><div class="footer-bottom"><span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span></div></div></footer>
</body>
</html>