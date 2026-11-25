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
$extraCss = '../css/skill-gap.css';
user_header($me, 'Skill Gap', '');
?>

<div class="user-skillgap">

    <!-- Page banner -->
    <section class="skillgap-hero">
        <div class="container skillgap-hero-inner">

            <div>
                <div class="hero-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Skill Gap</h1>

                <p>
                    See which skills you need to develop to reach your goals.
                </p>
            </div>

            <div class="hero-icon" aria-hidden="true">✦</div>

        </div>
    </section>

    <div class="container skillgap-content">

        <div class="dashboard-card">

            <div class="card-heading-row">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="ai-badge">AI</span>
                    <h2>Skill Gap Analysis</h2>
                </div>
            </div>

            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">✦</div>

                <h3>Coming soon</h3>

                <p>
                    Skill gap analysis will be activated soon.
                    In the meantime, you can keep your skills up to date
                    in your profile.
                </p>

                <div style="margin-top:15px;">
                    <a href="dashboard-user.php" class="btn btn-primary btn-sm">
                        View My Profile
                    </a>
                </div>
            </div>

        </div>

    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

