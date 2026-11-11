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

/* Same status rule as the Browse page: Closed (closed by the company or deadline passed),
   Upcoming (applications not open yet) or Open. */
function company_profile_state(array $o): string {
    $today    = date('Y-m-d');
    $status   = trim((string)($o['Status'] ?? ''));
    $deadline = (string)($o['ApplicationDeadLine'] ?? '');
    $openDate = (string)($o['ApplicationOpenDate'] ?? '');
    if (strcasecmp($status, 'Closed') === 0 || ($deadline !== '' && $deadline < $today)) {
        return 'Closed';
    }
    if ($openDate !== '' && $openDate > $today) {
        return 'Upcoming';
    }
    return 'Open';
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
        "SELECT OpportunityID, Title, Type, Location, Description, Status, StartDate, EndDate,
                ApplicationOpenDate, ApplicationDeadLine
         FROM opportunity
         WHERE CreatedByCompanyID = ?
         ORDER BY OpportunityID DESC
         LIMIT 12"
    );
    $opStmt->execute([$companyId]);
    $companyOpportunities = $opStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Average rating of all reviews on this company's opportunities. */
$avgRating = null;
$reviewCount = 0;
if ($company) {
    $rStmt = $pdo->prepare(
        "SELECT AVG(r.Rating) AS AvgRating, COUNT(*) AS ReviewCount
         FROM review r
         JOIN opportunity o ON o.OpportunityID = r.OpportunityID
         WHERE o.CreatedByCompanyID = ?"
    );
    $rStmt->execute([$companyId]);
    $ratingRow = $rStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $reviewCount = (int)($ratingRow['ReviewCount'] ?? 0);
    if ($reviewCount > 0) {
        $avgRating = round((float)$ratingRow['AvgRating'], 1);
    }
}

user_header($me, 'Company Profile', 'browse');

$name = trim((string)($company['CompanyName'] ?? 'Company'));
$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/'));
$initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($name, 0, 1));
$website = trim((string)($company['WebsiteURL'] ?? ''));
?>
<style>
/* ===== Company profile (same design as the Applicant Profile page) ===== */
.company-public-profile{
    --c-purple:#39265f;--c-purple-dark:#281a46;--c-lavender:#f0ebf8;--c-gold:#e2a94b;--c-bg:#faf6ef;
    --c-text:#29243a;--c-muted:#777184;--c-border:#e9e3ed;
    color:var(--c-text);padding-bottom:48px;background:var(--c-bg);min-height:70vh;
}
.company-public-profile *,.company-public-profile *::before,.company-public-profile *::after{box-sizing:border-box}

/* Banner */
.company-public-profile .dashboard-hero{position:relative;overflow:hidden;padding:38px 0 42px;margin-bottom:28px;background:linear-gradient(125deg,#281a46 0%,#39265f 55%,#49316f 100%);color:#fff}
.company-public-profile .dashboard-hero::before,.company-public-profile .dashboard-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.company-public-profile .dashboard-hero::before{width:250px;height:250px;right:7%;top:-150px;border:35px solid rgba(255,255,255,.07)}
.company-public-profile .dashboard-hero::after{width:190px;height:190px;right:23%;bottom:-145px;background:rgba(226,169,75,.10)}
.company-public-profile .dashboard-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:22px}
.company-public-profile .welcome-label{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;margin-bottom:12px;border:1px solid rgba(255,255,255,.2);border-radius:30px;background:rgba(255,255,255,.08);font-size:.84rem;letter-spacing:.2px}
.company-public-profile .dashboard-hero h1{margin:0;color:#fff;font-size:clamp(1.65rem,3vw,2.15rem);font-weight:750;line-height:1.5}
.company-public-profile .dashboard-hero p{margin:8px 0 0;color:rgba(255,255,255,.82);font-size:.96rem;line-height:1.8}
.company-public-profile .hero-icon{display:flex;align-items:center;justify-content:center;width:78px;height:78px;flex-shrink:0;border:1px solid rgba(255,255,255,.2);border-radius:24px;background:rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(15,8,30,.16);animation:cpFloat 4s ease-in-out infinite}
.company-public-profile .dashboard-content{max-width:1000px;animation:cpFadeUp .5s ease both}

.company-public-profile .back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;color:var(--c-purple);font-size:.88rem;font-weight:700;text-decoration:none}
.company-public-profile .back-link:hover{color:var(--c-purple-dark);text-decoration:underline}

/* Identity card */
.company-public-profile .profile-identity,.company-public-profile .profile-section{border:1px solid var(--c-border);border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(40,26,70,.045)}
.company-public-profile .profile-identity{display:flex;align-items:center;gap:22px;padding:25px;margin-bottom:22px}
.company-public-profile .profile-avatar{display:flex;align-items:center;justify-content:center;flex:0 0 92px;width:92px;height:92px;overflow:hidden;border:4px solid #f0ebf8;border-radius:22px;background:var(--c-lavender);color:var(--c-purple);font-size:1.8rem;font-weight:750}
.company-public-profile .profile-avatar.has-logo{background:#fff}
.company-public-profile .profile-avatar img{width:100%;height:100%;object-fit:contain;padding:6px}
.company-public-profile .profile-avatar img[hidden]{display:none!important}
.company-public-profile .profile-avatar .avatar-initial{display:none}
.company-public-profile .profile-avatar img[hidden] + .avatar-initial{display:block}
.company-public-profile .profile-identity-details{flex:1;min-width:0}
.company-public-profile .profile-identity-details h2{margin:0;color:var(--c-text);font-size:1.4rem;overflow-wrap:anywhere}
.company-public-profile .profile-subtitle{margin-top:6px;color:var(--c-muted);font-size:.92rem}
.company-public-profile .profile-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.company-public-profile .profile-tag{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:#f7f4fb;border:1px solid #e8e0f2;color:#4a3f5e;font-size:.8rem;font-weight:650}
.company-public-profile .match-pill .rating-count{color:#9a7a45;font-weight:600}
.company-public-profile .match-pill.is-empty{background:#f7f4fb;border-color:#e8e0f2;color:var(--c-muted)}
.company-public-profile .match-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#fbf6eb;border:1px solid #eee4d1;color:#76531d;font-size:.82rem;font-weight:750;white-space:nowrap}

/* Sections */
.company-public-profile .profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}
.company-public-profile .profile-grid .is-wide{grid-column:1/-1}
.company-public-profile .profile-section{padding:22px 24px;min-width:0}
.company-public-profile .profile-section-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-bottom:15px;margin-bottom:17px;border-bottom:1px solid #e9e4ef}
.company-public-profile .profile-section-header h3{display:flex;align-items:center;gap:10px;margin:0;color:var(--c-text);font-size:1.05rem;font-weight:750}
.company-public-profile .profile-section-header h3::before{content:"";display:block;width:5px;height:22px;border-radius:5px;background:var(--c-gold)}
.company-public-profile .profile-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 22px}
.company-public-profile .profile-field-label{margin-bottom:6px;color:var(--c-muted);font-size:.8rem}
.company-public-profile .profile-field-value{color:var(--c-text);font-size:.93rem;line-height:1.6;overflow-wrap:anywhere}
.company-public-profile .profile-field-value a{color:var(--c-purple);font-weight:600}
.company-public-profile .profile-about{margin:0;color:var(--c-text);font-size:.93rem;line-height:1.8;white-space:pre-line;overflow-wrap:anywhere}
.company-public-profile .profile-empty{margin:0;padding:14px;border:1px dashed #ded5ec;border-radius:12px;background:#faf9fd;color:var(--c-muted);font-size:.87rem}

/* Opportunities list */
.company-public-profile .info-list{display:flex;flex-direction:column;gap:12px;margin:0;padding:0;list-style:none}
.company-public-profile .info-item{padding:14px 16px;border:1px solid #eee9f5;border-radius:12px;background:#faf9fd}
.company-public-profile .info-item-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.company-public-profile .info-item-title{margin:0;color:var(--c-text);font-size:.95rem;font-weight:750;overflow-wrap:anywhere}
.company-public-profile .info-item-title a{color:inherit;text-decoration:none}
.company-public-profile .info-item-title a:hover{color:var(--c-purple);text-decoration:underline}
.company-public-profile .info-item-sub{margin-top:3px;color:#5e596c;font-size:.87rem;overflow-wrap:anywhere}
.company-public-profile .info-item-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}

/* Status badges (same as Browse) */
.company-public-profile .badge{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:96px;padding:5px 12px;border:1px solid transparent;border-radius:30px;font-size:.78rem;font-weight:750;white-space:nowrap}
.company-public-profile .badge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.company-public-profile .badge-open{background:#e8f5ec;color:#24653c;border-color:#cfe8d9}
.company-public-profile .badge-closed{background:#fff1f2;color:#9c263d;border-color:#f1d4d8}
.company-public-profile .badge-upcoming{background:#f3f2f5;color:#5f5a6b;border-color:#e3e0e8}

@media(max-width:760px){
  .company-public-profile .profile-grid{grid-template-columns:1fr}
}
@media(max-width:650px){
  .company-public-profile .dashboard-hero{padding:30px 0}
  .company-public-profile .hero-icon{width:60px;height:60px;border-radius:18px}
  .company-public-profile .profile-identity{align-items:flex-start;gap:15px;padding:19px;flex-wrap:wrap}
  .company-public-profile .profile-avatar{flex-basis:70px;width:70px;height:70px;font-size:1.4rem;border-radius:18px}
  .company-public-profile .profile-identity-details h2{font-size:1.15rem}
  .company-public-profile .profile-section{padding:18px 16px}
  .company-public-profile .profile-view-grid{grid-template-columns:1fr;gap:15px}
}
@keyframes cpFadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes cpFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
@media(prefers-reduced-motion:reduce){.company-public-profile *,.company-public-profile *::before,.company-public-profile *::after{animation:none!important;transition:none!important}}
</style>

<main class="company-public-profile">

  <section class="dashboard-hero">
    <div class="container dashboard-hero-inner">
      <div>
        <div class="welcome-label"><span aria-hidden="true">✦</span><span>Ufuq Learning Space</span></div>
        <h1>Company Profile</h1>
        <p>Learn about the company and explore the opportunities it offers.</p>
      </div>
      <div class="hero-icon" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="3" width="11" height="18" rx="1.5"/><path d="M15 9h4.5a.5.5 0 0 1 .5.5V21"/><path d="M8 7h3M8 11h3M8 15h3M3 21h18"/></svg></div>
    </div>
  </section>

  <div class="container dashboard-content">

    <a href="browse.php" class="back-link"><span aria-hidden="true">&larr;</span> Back to Browse</a>

    <?php if (!$company): ?>

      <section class="profile-section">
        <div class="profile-section-header"><h3>Company profile not found</h3></div>
        <p class="profile-empty">This company profile is unavailable or has not been verified.</p>
      </section>

    <?php else: ?>

      <!-- Identity -->
      <section class="profile-identity">
        <div class="profile-avatar <?= $logoSrc !== '' ? 'has-logo' : '' ?>">
          <?php if ($logoSrc !== ''): ?>
            <img src="<?= company_profile_h($logoSrc) ?>" alt="<?= company_profile_h($name) ?>" onerror="this.hidden=true;this.parentNode.classList.remove('has-logo')"><span class="avatar-initial"><?= company_profile_h($initial) ?></span>
          <?php else: ?>
            <?= company_profile_h($initial) ?>
          <?php endif; ?>
        </div>
        <div class="profile-identity-details">
          <h2><?= company_profile_h($name) ?></h2>
          <div class="profile-subtitle">
            <?= company_profile_h($company['IndustrySector'] ?: 'Industry not specified') ?>
            <?php if (!empty($company['Location'])): ?> &middot; <?= company_profile_h($company['Location']) ?><?php endif; ?>
          </div>
          <div class="profile-tags">
            <span class="profile-tag"><?= count($companyOpportunities) ?> <?= count($companyOpportunities) === 1 ? 'opportunity' : 'opportunities' ?></span>
          </div>
        </div>
        <?php if ($avgRating !== null): ?>
          <span class="match-pill" title="Average rating of this company's opportunities">★ <?= number_format($avgRating, 1) ?> <span class="rating-count">(<?= $reviewCount ?> <?= $reviewCount === 1 ? 'review' : 'reviews' ?>)</span></span>
        <?php else: ?>
          <span class="match-pill is-empty">★ No ratings yet</span>
        <?php endif; ?>
      </section>

      <div class="profile-grid">

        <!-- Company information -->
        <section class="profile-section">
          <div class="profile-section-header"><h3>Company Information</h3></div>
          <div class="profile-view-grid">
            <div><div class="profile-field-label">Industry Sector</div><div class="profile-field-value"><?= company_profile_h($company['IndustrySector'] ?: '—') ?></div></div>
            <div><div class="profile-field-label">Location</div><div class="profile-field-value"><?= company_profile_h($company['Location'] ?: '—') ?></div></div>
          </div>
        </section>

        <!-- Contact -->
        <section class="profile-section">
          <div class="profile-section-header"><h3>Contact Information</h3></div>
          <div class="profile-view-grid">
            <div style="grid-column:1/-1"><div class="profile-field-label">Website</div><div class="profile-field-value">
              <?php if ($website !== ''): ?><a href="<?= company_profile_h($website) ?>" target="_blank" rel="noopener noreferrer"><?= company_profile_h($website) ?></a><?php else: ?>—<?php endif; ?>
            </div></div>
          </div>
        </section>

        <!-- About -->
        <section class="profile-section is-wide">
          <div class="profile-section-header"><h3>About the Company</h3></div>
          <?php if (trim((string)($company['Description'] ?? '')) === ''): ?>
            <p class="profile-empty">No company description has been added yet.</p>
          <?php else: ?>
            <p class="profile-about"><?= company_profile_h($company['Description']) ?></p>
          <?php endif; ?>
        </section>

        <!-- Opportunities -->
        <section class="profile-section is-wide">
          <div class="profile-section-header"><h3>Opportunities from <?= company_profile_h($name) ?></h3></div>
          <?php if (!$companyOpportunities): ?>
            <p class="profile-empty">This company has not published any opportunities yet.</p>
          <?php else: ?>
            <ul class="info-list">
              <?php foreach ($companyOpportunities as $op):
                  $state = company_profile_state($op);
                  $stateClass = $state === 'Open' ? 'badge-open' : ($state === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
              ?>
                <li class="info-item">
                  <div class="info-item-top">
                    <div style="min-width:0">
                      <p class="info-item-title"><a href="opportunity-detail.php?id=<?= (int)$op['OpportunityID'] ?>"><?= company_profile_h($op['Title'] ?: 'Untitled opportunity') ?></a></p>
                      <div class="info-item-sub"><?= company_profile_h($op['Type'] ?: 'Opportunity') ?> &middot; <?= company_profile_h($op['Location'] ?: 'Location not specified') ?></div>
                    </div>
                    <div class="info-item-actions">
                      <span class="badge <?= $stateClass ?>"><?= company_profile_h($state) ?></span>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

      </div>

    <?php endif; ?>

  </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>