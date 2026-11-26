
<?php


require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/user-layout.php';
require_once __DIR__ . '/auth.php';

/* ---------- Authentication ---------- */
$uid = require_user();

/* ---------- Load current user ---------- */
$userStmt = $pdo->prepare("
    SELECT u.*, m.Email
    FROM `user` u
    JOIN member m ON m.MemberID = u.UserID
    WHERE u.UserID = ?
    LIMIT 1
");
$userStmt->execute([$uid]);
$me = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: login.php');
    exit;
}

/* ---------- Page header ---------- */
$extraCss = '../css/application-history.css';
user_header($me, 'Application History', 'history');
?>


<div class="user-skillgap">

    <!-- Page Banner -->
    <section class="skillgap-hero">
        <div class="container skillgap-hero-inner">

            <div>
                <div class="hero-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Application History</h1>

                <p>
                    Track your submitted applications and follow
                    your training journey in one place.
                </p>
            </div>

        </div>
    </section>

    <!-- Coming Soon Content -->
    <div class="container skillgap-content">

        <div class="dashboard-card">

            <div class="card-heading-row">
                <h2>Application History</h2>
            </div>

            <div class="empty-state">

                <div class="empty-icon" aria-hidden="true">
                    <?= icon('form', 30) ?>
                </div>

                <h3>Coming Soon</h3>

                <p>
                    Application History will be available soon.
                    We're working on making it easier to track
                    your applications and their progress.
                </p>

                <div style="margin-top: 15px;">
                    <a href="home.php"
                       class="btn btn-primary btn-sm">
                        Back to Home
                    </a>
                </div>

            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
