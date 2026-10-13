
<?php
/* =========================================================
   Ufuq - Public Home Page
   PHP + MySQL
   ========================================================= */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Load the existing project bootstrap.
 * The bootstrap is expected to provide the PDO connection
 * through $pdo.
 */
$bootstrapPath = __DIR__ . '/pages/bootstrap.php';

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/pages/db.php')) {
    require_once __DIR__ . '/pages/db.php';
}

/* Redirect signed-in members to their own pages. */
if (!empty($_SESSION['member_id']) || !empty($_SESSION['user_id'])) {
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    $destinations = [
        'user'    => 'pages/home.php',
        'company' => 'pages/dashboard-company.php',
        'admin'   => 'pages/dashboard-admin.php',
    ];

    if (isset($destinations[$role])) {
        header('Location: ' . $destinations[$role]);
        exit;
    }
}

/* Escape values before displaying database content. */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/* Keep the page usable even when no opportunities exist. */
$preview = [];
$coursesPreview = [];
$databaseError = false;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    /*
     * Fetch the latest three open opportunities.
     * Company opportunities use the registered company name.
     * Other opportunities can use OpportunityProvider.
     */
    $sql = "
        SELECT
            o.OpportunityID,
            o.Title,
            o.Type,
            o.Location,
            o.Status,
            o.OpportunityProvider,
            o.CreatedByCompanyID,
            c.CompanyName
        FROM opportunity o
        LEFT JOIN company c
            ON c.CompanyID = o.CreatedByCompanyID
        WHERE o.Status = 'Open'
          AND (
              o.CreatedByCompanyID IS NULL
              OR c.Status = 'Verified'
          )
        ORDER BY o.OpportunityID DESC
        LIMIT 3
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $preview = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Fetch the latest three open courses. */
    $courseSql = "
        SELECT
            o.OpportunityID,
            o.Title,
            o.Type,
            o.Location,
            o.Status,
            o.OpportunityProvider,
            o.CreatedByCompanyID,
            c.CompanyName
        FROM opportunity o
        LEFT JOIN company c
            ON c.CompanyID = o.CreatedByCompanyID
        WHERE o.Status = 'Open'
          AND o.Type = 'Course'
          AND (
              o.CreatedByCompanyID IS NULL
              OR c.Status = 'Verified'
          )
        ORDER BY o.OpportunityID DESC
        LIMIT 3
    ";

    $courseStmt = $pdo->prepare($courseSql);
    $courseStmt->execute();
    $coursesPreview = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    /*
     * Do not expose database details to public visitors.
     * Check the PHP error log if the database query fails.
     */
    error_log('Ufuq index.php: ' . $ex->getMessage());
    $databaseError = true;
}

/* Determine the company name displayed on each card. */
function getCompanyName(array $opportunity): string
{
    $registeredName = trim(
        (string) ($opportunity['CompanyName'] ?? '')
    );

    if ($registeredName !== '') {
        return $registeredName;
    }

    $provider = trim(
        (string) ($opportunity['OpportunityProvider'] ?? '')
    );

    return $provider !== '' ? $provider : 'External Company';
}

/* Generate a consistent color for each company initial. */
function getCompanyColor(string $name): string
{
    $palette = [
        '#3157A4',
        '#397A76',
        '#7655A5',
        '#B47737',
        '#3F7195',
    ];

    $index = abs(crc32($name)) % count($palette);

    return $palette[$index];
}

/* Render one opportunity card without changing its content. */
function renderOpportunityCard(array $opportunity): void
{
    $title = (string) ($opportunity['Title'] ?? '');
    $company = getCompanyName($opportunity);
    $type = (string) ($opportunity['Type'] ?? '');
    $location = (string) ($opportunity['Location'] ?? '');
    $initial = $company !== ''
        ? strtoupper(substr($company, 0, 1))
        : 'C';

    $badgeClass = $type === 'Course'
        ? 'badge-success'
        : 'badge-primary';

    $color = getCompanyColor($company);
    ?>
    <div class="card opp-card card-hover reveal">
        <div class="opp-card-top">
            <div class="flex gap-3" style="align-items:center;">
                <div
                    class="opp-logo"
                    style="background:<?= e($color) ?>;"
                ><?= e($initial) ?></div>

                <div>
                    <h3 style="margin:0 0 2px;font-size:1.02rem;">
                        <?= e($title) ?>
                    </h3>

                    <div class="text-muted" style="font-size:0.82rem;">
                        <?= e($company) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="opp-meta">
            <span class="badge <?= e($badgeClass) ?>">
                <?= e($type) ?>
            </span>

            <span><?= e($location) ?></span>
        </div>

        <div class="opp-footer">
            <a class="btn btn-outline btn-sm" href="pages/login.php">
                View Details
            </a>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Ufuq &mdash; Find Your Next Training Program or Course</title>

<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/layout.css">
<link rel="stylesheet" href="css/icons.css">

<style>
/* =========================================================
   Subtle motion effects - original Ufuq content and layout
   remain unchanged.
   ========================================================= */

html {
    scroll-behavior: smooth;
}

.hero-split-grid > div:first-child {
    animation: ufuqFadeUp .8s ease both;
}

.hero-visual {
    animation: ufuqFloatIn .9s ease both;
}

.hero-visual-circle {
    animation: ufuqBreath 5s ease-in-out infinite;
    transform-origin: center;
}

.hero-floating-card {
    animation: ufuqFloat 4.5s ease-in-out infinite;
}

.hero-floating-card:nth-of-type(2) {
    animation-delay: -.9s;
}

.preview-section-header {
    animation: ufuqFadeUp .6s ease both;
}

.opp-card {
    transition:
        transform .28s ease,
        box-shadow .28s ease,
        border-color .28s ease;
}

.opp-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 14px 32px rgba(31, 53, 102, .11);
}

.opp-logo {
    transition: transform .28s ease;
}

.opp-card:hover .opp-logo {
    transform: scale(1.08);
}

.section-journey .card {
    transition:
        transform .28s ease,
        box-shadow .28s ease;
}

.section-journey .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 28px rgba(31, 53, 102, .09);
}

.btn {
    transition:
        transform .2s ease,
        box-shadow .2s ease;
}

.btn:hover {
    transform: translateY(-2px);
}

.reveal {
    opacity: 0;
    transform: translateY(22px);
    transition:
        opacity .65s ease,
        transform .65s ease;
}

.reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

/* Gentle stagger for cards. */
.reveal:nth-child(2) {
    transition-delay: .10s;
}

.reveal:nth-child(3) {
    transition-delay: .20s;
}

@keyframes ufuqFadeUp {
    from {
        opacity: 0;
        transform: translateY(18px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ufuqFloatIn {
    from {
        opacity: 0;
        transform: translateX(16px) scale(.98);
    }
    to {
        opacity: 1;
        transform: translateX(0) scale(1);
    }
}

@keyframes ufuqFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-7px);
    }
}

@keyframes ufuqBreath {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.035);
    }
}

@media (prefers-reduced-motion: reduce) {
    html {
        scroll-behavior: auto;
    }

    *,
    *::before,
    *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }

    .reveal {
        opacity: 1;
        transform: none;
    }
}
</style>
</head>

<body>

<div id="site-header"></div>

<main>
    <!-- Hero -->
    <section class="hero-split">
        <div class="container hero-split-grid">
            <div>
                <div class="hero-eyebrow">Saudi Career Development</div>

                <h1>
                    Your next step starts with
                    <span style="color:var(--color-primary);">Ufuq</span>.
                </h1>

                <p style="font-size:1.05rem;">
                    Discover training opportunities and professional courses,
                    evaluate their details and experiences, and take the next
                    step &mdash; all from one organized platform.
                </p>

                <div class="flex gap-3"
                     style="margin-top:28px;flex-wrap:wrap;">
                    <a href="#opportunities" class="btn btn-primary">
                        Explore Opportunities
                    </a>
                </div>
            </div>

            <div class="hero-visual">
                <div class="hero-visual-circle">
                    <img
                        src="images-website/logo.png"
                        alt="Ufuq"
                        style="width:120px;height:120px;object-fit:contain;"
                    >
                </div>

                <div class="hero-floating-card" style="top:8%;right:0;">
                    <div class="ft-title">Smart discovery</div>
                    <div class="ft-body">
                        Recommendations based on your profile.
                    </div>
                </div>

                <div class="hero-floating-card" style="bottom:6%;left:-6%;">
                    <div class="ft-title">One organized place</div>
                    <div class="ft-body">
                        Explore, compare, save and apply.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Opportunities preview -->
    <section class="preview-section" id="opportunities">
        <div class="container">
            <div class="preview-section-header">
                <div>
                    <div class="preview-eyebrow">Explore</div>
                    <h2 style="margin:0;">Opportunities</h2>
                    <p style="margin:4px 0 0;">
                        Training opportunities and courses will appear here
                        when published.
                    </p>
                </div>

                <a href="pages/login.php">View all &rarr;</a>
            </div>

            <div id="oppPreviewGrid" class="grid grid-3">
                <?php foreach ($preview as $opportunity): ?>
                    <?php renderOpportunityCard($opportunity); ?>
                <?php endforeach; ?>
            </div>

            <div
                class="preview-empty <?= empty($preview) ? '' : 'hidden' ?>"
                id="oppPreviewEmpty"
            >
                <h3>No opportunities available yet</h3>
                <p style="margin:0;">
                    There are currently no published opportunities.
                    Check back later.
                </p>
            </div>
        </div>
    </section>

    <!-- Courses -->
    <section class="section courses-section" id="courses">
        <div class="container">
            <div class="preview-section-header">
                <div class="courses-head">
                    <div class="preview-eyebrow">Learn</div>
                    <h2>Courses</h2>
                    <p>
                        Professional courses will appear here when published.
                    </p>
                </div>

                <a href="pages/login.php">View all &rarr;</a>
            </div>

            <div id="coursesPreviewGrid" class="grid grid-3">
                <?php foreach ($coursesPreview as $course): ?>
                    <?php renderOpportunityCard($course); ?>
                <?php endforeach; ?>
            </div>

            <div
                class="courses-empty <?= empty($coursesPreview) ? '' : 'hidden' ?>"
                id="coursesPreviewEmpty"
            >
                <h3>No courses available yet</h3>
                <p style="margin:0;">
                    There are currently no published courses.
                    Check back later.
                </p>
            </div>
        </div>
    </section>

    <!-- Value props -->
    <section class="section section-journey">
        <div class="container">
            <h2 class="text-center" style="margin-bottom:40px;">
                Built for computing students &mdash; and the companies training them
            </h2>

            <div class="grid grid-3">
                <div class="card text-center reveal">
                    <div class="flex-center" style="margin-bottom:12px;">
                        <span
                            class="icon icon-primary"
                            style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/training.png');mask-image:url('images-website/icons/training.png');"
                        ></span>
                    </div>
                    <h3>One Place for Opportunities</h3>
                    <p>
                        Training programs and courses from verified companies,
                        gathered in a single platform.
                    </p>
                </div>

                <div class="card text-center reveal">
                    <div class="flex-center" style="margin-bottom:12px;">
                        <span
                            class="icon icon-primary"
                            style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/company.png');mask-image:url('images-website/icons/company.png');"
                        ></span>
                    </div>
                    <h3>Verified Companies</h3>
                    <p>
                        Every company account is reviewed by an admin before
                        it can publish opportunities.
                    </p>
                </div>

                <div class="card text-center reveal">
                    <div class="flex-center" style="margin-bottom:12px;">
                        <span
                            class="icon icon-primary"
                            style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/goal.png');mask-image:url('images-website/icons/goal.png');"
                        ></span>
                    </div>
                    <h3>A Profile That Follows You</h3>
                    <p>
                        Keep your field of study, skills and experience in one
                        profile, ready to grow with the platform.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA for companies -->
    <section class="section section-journey">
        <div class="container">
            <div
                class="card cta-card flex-between"
                style="flex-wrap:wrap;gap:20px;"
            >
                <div>
                    <h3 style="margin-bottom:4px;">
                        Are you offering a training program or course?
                    </h3>
                    <p style="margin:0;">
                        Register your company to publish opportunities and
                        reach students across Saudi Arabia.
                    </p>
                </div>

                <a href="pages/signup.php" class="btn btn-primary">
                    Register Your Company
                </a>
            </div>
        </div>
    </section>
</main>

<div id="site-footer"></div>

<script src="js/store.js"></script>
<script src="js/constants.js"></script>
<script src="js/components.js"></script>

<script>
/*
 * Header and footer use the existing project components.
 * PHP handles authentication and opportunity data.
 */
renderHeader({ active: "home", base: "" });
renderFooter({ base: "" });

/* Reveal elements when they enter the viewport. */
(function () {
    const revealElements = document.querySelectorAll(".reveal");

    if (!("IntersectionObserver" in window)) {
        revealElements.forEach(function (element) {
            element.classList.add("is-visible");
        });
        return;
    }

    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add("is-visible");
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.12,
        rootMargin: "0px 0px -25px 0px"
    });

    revealElements.forEach(function (element) {
        observer.observe(element);
    });
})();

/* Show server-rendered opportunity cards without a hidden flash. */
document.querySelectorAll(
    "#oppPreviewGrid .reveal, #coursesPreviewGrid .reveal"
).forEach(function (card) {
    card.classList.add("is-visible");
});
</script>

</body>
</html>
