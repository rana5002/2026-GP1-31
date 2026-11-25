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
<link rel="stylesheet" href="../css/applicant-profile.css">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>