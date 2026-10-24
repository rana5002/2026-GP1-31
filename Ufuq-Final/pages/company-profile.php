<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';

if (!isset($pdo) && isset($db)) {
    $pdo = $db;
}
require_login('User');

$uid = (int)($_SESSION['user_id'] ?? 0);
$userStmt = $pdo->prepare('SELECT * FROM `user` WHERE UserID = ? LIMIT 1');
$userStmt->execute([$uid]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$me) {
    header('Location: login.php');
    exit;
}

function company_profile_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$companyId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$companyId || $companyId < 1) {
    http_response_code(404);
    $company = null;
} else {
    $stmt = $pdo->prepare(
        "SELECT CompanyID, CompanyName, IndustrySector, Location, WebsiteURL, Description, Logo
         FROM company
         WHERE CompanyID = ? AND Status = 'Verified'
         LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) {
        http_response_code(404);
    }
}

$companyOpportunities = [];
if ($company) {
    $opStmt = $pdo->prepare(
        "SELECT OpportunityID, Title, Type, Location, Description, Status, StartDate, EndDate
         FROM opportunity
         WHERE CreatedByCompanyID = ?
         ORDER BY OpportunityID DESC
         LIMIT 12"
    );
    $opStmt->execute([$companyId]);
    $companyOpportunities = $opStmt->fetchAll(PDO::FETCH_ASSOC);
}

user_header($me, 'Company Profile', 'browse');

$name = trim((string)($company['CompanyName'] ?? 'Company'));
$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/'));
$initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($name, 0, 1));
?>
<style>
.company-public-profile{--cp-purple:#39265f;--cp-dark:#281a46;--cp-lav:#eee7f6;--cp-bg:#faf6ef;--cp-text:#29243a;--cp-muted:#777184;min-height:70vh;padding-bottom:48px;background:var(--cp-bg);color:var(--cp-text)}
.company-public-profile *{box-sizing:border-box}
.company-public-profile .cp-back-wrap{max-width:1132px;width:calc(100% - 40px);margin:0 auto;padding-top:27px;padding-bottom:0}
.company-public-profile .cp-back{display:inline-flex;align-items:center;gap:7px;color:#777087;text-decoration:none;font-size:.92rem;font-weight:650}
.company-public-profile .cp-back:hover{color:var(--cp-purple);text-decoration:underline}
.company-public-profile .cp-hero{position:relative;overflow:hidden;max-width:1132px;width:calc(100% - 40px);margin:18px auto 0;padding:22px;background:linear-gradient(120deg,#fff,#f8f4fc);color:#302348;border:1px solid #e6d8f0;border-radius:17px}
.company-public-profile .cp-hero:after{content:none}
.company-public-profile .cp-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:20px}
.company-public-profile .cp-logo{display:flex;align-items:center;justify-content:center;width:64px;height:64px;flex:0 0 64px;border-radius:17px;background:#f1eaf8;color:var(--cp-purple);font-size:1.6rem;font-weight:800;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.10)}
.company-public-profile .cp-logo img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain}.company-public-profile .cp-logo img[hidden]{display:none!important}.company-public-profile .cp-logo .cp-logo-initial{display:none}.company-public-profile .cp-logo img[hidden] + .cp-logo-initial{display:block}
.company-public-profile .cp-eyebrow{font-size:.83rem;color:#6a8f76;margin-bottom:7px}
.company-public-profile h1{margin:0;color:#302348;font-size:clamp(1.6rem,3vw,2.2rem);overflow-wrap:anywhere}
.company-public-profile .cp-hero p{margin:7px 0 0;color:#746b82}
.company-public-profile .cp-content{padding-top:25px}.company-public-profile .cp-back{display:inline-flex;align-items:center;gap:6px;color:#786e89;text-decoration:none;font-size:.9rem;font-weight:650}.company-public-profile .cp-back:hover{color:#39265f;text-decoration:underline}
.company-public-profile .cp-card{padding:23px;margin-bottom:18px;border:1px solid #e6ddea;border-top:3px solid #c6a15d;border-radius:16px;background:linear-gradient(135deg,#fff 0%,#f8f3fa 100%);box-shadow:0 6px 20px rgba(40,26,70,.06)}
.company-public-profile .cp-card h2{margin:0 0 17px;color:var(--cp-purple);font-size:1.15rem}
.company-public-profile .cp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.company-public-profile .cp-field{padding:14px;border:1px solid #e8deef;border-radius:12px;background:#f4eef8;overflow-wrap:anywhere}
.company-public-profile .cp-label{font-size:.78rem;color:var(--cp-muted);margin-bottom:5px}
.company-public-profile .cp-value{font-weight:650;color:var(--cp-text);line-height:1.7;white-space:pre-line}
.company-public-profile .cp-opportunity{padding:15px 0;border-top:1px solid #e8deef}
.company-public-profile .cp-opportunity:first-of-type{border-top:0}
.company-public-profile .cp-opportunity h3{margin:0 0 5px;font-size:1rem;color:var(--cp-purple)}
.company-public-profile .cp-opportunity p{margin:4px 0;color:var(--cp-muted);font-size:.88rem;line-height:1.7}
.company-public-profile .cp-empty{text-align:center;padding:28px 15px;border:1px dashed #cbb9dc;border-radius:12px;background:#f2ebf7;color:var(--cp-muted)}
.company-public-profile .cp-btn{display:inline-flex;margin-top:18px;padding:10px 15px;border-radius:9px;background:var(--cp-purple);color:#fff;text-decoration:none;font-weight:700}
.company-public-profile .cp-btn:hover{background:var(--cp-dark)}
@media(max-width:600px){.company-public-profile .cp-hero{width:calc(100% - 28px);padding:18px}.company-public-profile .cp-hero-inner{align-items:flex-start;flex-direction:column}.company-public-profile .cp-grid{grid-template-columns:1fr}.company-public-profile .cp-card{padding:17px}}
</style>
<main class="company-public-profile">
<div class="container cp-back-wrap"><a class="cp-back" href="browse.php">← Back to Browse</a></div>
<?php if (!$company): ?>
  <section class="cp-hero"><div class="cp-eyebrow">Ufuq Company Space</div><h1>Company profile not found</h1><p>This company profile is unavailable or has not been verified.</p></section>
  <div class="container cp-content"><a class="cp-btn" href="browse.php">Back to Browse</a></div>
<?php else: ?>
  <section class="cp-hero">
    <div class="cp-hero-inner">
      <div class="cp-logo"><?php if ($logoSrc !== ''): ?><img src="<?= company_profile_h($logoSrc) ?>" alt="" onerror="this.hidden=true"><span class="cp-logo-initial"><?= company_profile_h($initial) ?></span><?php else: ?><?= company_profile_h($initial) ?><?php endif; ?></div>
      <div><div class="cp-eyebrow">Verified company · Ufuq</div><h1><?= company_profile_h($name) ?></h1><p><?= company_profile_h($company['IndustrySector'] ?: 'Industry not specified') ?><?= !empty($company['Location']) ? ' · ' . company_profile_h($company['Location']) : '' ?></p></div>
    </div>
  </section>
  <div class="container cp-content">
    <section class="cp-card">
      <h2>Company Information</h2>
      <div class="cp-grid">
        <div class="cp-field"><div class="cp-label">Industry Sector</div><div class="cp-value"><?= company_profile_h($company['IndustrySector'] ?: 'Not specified') ?></div></div>
        <div class="cp-field"><div class="cp-label">Location</div><div class="cp-value"><?= company_profile_h($company['Location'] ?: 'Not specified') ?></div></div>
        <div class="cp-field"><div class="cp-label">Website</div><div class="cp-value"><?php if (!empty($company['WebsiteURL'])): ?><a href="<?= company_profile_h($company['WebsiteURL']) ?>" target="_blank" rel="noopener noreferrer"><?= company_profile_h($company['WebsiteURL']) ?></a><?php else: ?>Not provided<?php endif; ?></div></div>
        <div class="cp-field" style="grid-column:1/-1"><div class="cp-label">About the Company</div><div class="cp-value"><?= company_profile_h($company['Description'] ?: 'No company description has been added yet.') ?></div></div>
      </div>
    </section>
    <section class="cp-card">
      <h2>Opportunities from <?= company_profile_h($name) ?></h2>
      <?php if (!$companyOpportunities): ?>
        <div class="cp-empty">This company has not published any opportunities yet.</div>
      <?php else: ?>
        <?php foreach ($companyOpportunities as $op): ?>
          <article class="cp-opportunity">
            <h3><?= company_profile_h($op['Title'] ?: 'Untitled opportunity') ?></h3>
            <p><?= company_profile_h($op['Type'] ?: 'Opportunity') ?> · <?= company_profile_h($op['Location'] ?: 'Location not specified') ?> · <?= company_profile_h($op['Status'] ?: 'Status not specified') ?></p>
            <?php if (!empty($op['Description'])): ?><p><?= company_profile_h($op['Description']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
