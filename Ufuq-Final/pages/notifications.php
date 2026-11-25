<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

/* ---------- Authentication ---------- */
$userId = require_user();

/* ---------- Load current user ---------- */
$stmt = $pdo->prepare("
    SELECT u.*, m.Email
    FROM `user` u
    JOIN member m ON m.MemberID = u.UserID
    WHERE u.UserID = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    http_response_code(404);
    exit('User account not found.');
}

/* ---------- Page header ---------- */
$extraCss = '../css/notifications.css';
user_header($me, 'Notifications', '');
?>


<div class="user-notifications">

    <!-- Page Banner -->
    <section class="notifications-hero">
        <div class="container notifications-hero-inner">

            <div>
                <div class="hero-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Notifications</h1>

                <p>
                    Stay updated with important announcements
                    and upcoming notification features.
                </p>
            </div>

        </div>
    </section>

    <!-- Coming Soon Content -->
    <div class="container notifications-content">

        <div class="dashboard-card">

            <div class="card-heading-row">
                <h2>Notifications</h2>
            </div>

            <div class="notifications-empty">

                <div class="notifications-empty-icon"
                     aria-hidden="true">
                    <?= icon('notification', 30) ?>
                </div>

                <h3>Coming Soon</h3>

                <p>
                    The notifications feature will be available
                    in a future update. Stay tuned for important
                    updates about your training opportunities
                    and applications.
                </p>

                <div style="margin-top: 18px;">
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
