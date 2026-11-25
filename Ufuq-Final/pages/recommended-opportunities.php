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
$extraCss = '../css/recommended-opportunities.css';
user_header($me, 'Recommended Opportunities', 'recommended');
?>



<div class="user-recommended">

    <!-- Page banner -->
    <section class="recommended-hero">
        <div class="container recommended-hero-inner">

            <div>
                <div class="hero-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Recommended Opportunities</h1>

                <p>
                    Opportunities matched to your profile will appear here.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">✦</div>

        </div>
    </section>

    <div class="container recommended-content">

        <div class="dashboard-card">

            <div class="card-heading-row">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="ai-badge">AI</span>
                    <h2>Personalized Recommendations</h2>
                </div>
            </div>

            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">✦</div>

                <h3>Coming soon</h3>

                <p>
                    Personalized recommendations will be activated soon.
                    In the meantime, you can explore all available
                    opportunities.
                </p>

                <div style="margin-top:15px;">
                    <a href="browse.php" class="btn btn-primary btn-sm">
                        Browse Opportunities
                    </a>
                </div>
            </div>

        </div>

    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>