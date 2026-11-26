<?php


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
$extraCss = '../css/company-profile.css';
user_header($me, 'Company Profile', 'browse');

$name = trim((string)($company['CompanyName'] ?? 'Company'));
$logo = trim((string)($company['Logo'] ?? ''));
$logoSrc = $logo === '' ? '' : (preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/'));
$initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($name, 0, 1));
$website = safe_http_url($company['WebsiteURL'] ?? '');
?>


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