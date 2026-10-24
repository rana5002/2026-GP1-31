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
user_header($me, 'Notifications', '');
?>

<style>
/* ===== Ufuq Notifications Coming Soon ===== */

.user-notifications {
    --user-purple: #39265f;
    --user-purple-dark: #281a46;
    --user-lavender: #f0ebf8;
    --user-gold: #e2a94b;
    --user-bg: #faf6ef;
    --user-text: #29243a;
    --user-muted: #777184;
    --user-border: #e9e3ed;

    color: var(--user-text);
    padding-bottom: 48px;
    background: var(--user-bg);
    min-height: 70vh;
}

.user-notifications *,
.user-notifications *::before,
.user-notifications *::after {
    box-sizing: border-box;
}

/* ===== Hero Banner ===== */

.user-notifications .notifications-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    background: linear-gradient(
        125deg,
        #281a46 0%,
        #39265f 55%,
        #49316f 100%
    );
    color: #fff;
}

.user-notifications .notifications-hero::before,
.user-notifications .notifications-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-notifications .notifications-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255, 255, 255, .07);
}

.user-notifications .notifications-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226, 169, 75, .10);
}

.user-notifications .notifications-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-notifications .hero-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 30px;
    background: rgba(255, 255, 255, .08);
    font-size: .84rem;
    letter-spacing: .2px;
}

.user-notifications .notifications-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-notifications .notifications-hero p {
    margin: 8px 0 0;
    color: rgba(255, 255, 255, .82);
    font-size: .96rem;
    line-height: 1.8;
}

/* ===== Coming Soon Card ===== */

.user-notifications .notifications-content {
    max-width: 1000px;
    animation: notifFadeUp .5s ease both;
}

.user-notifications .dashboard-card {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--user-border);
    border-top: 3px solid var(--user-gold);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40, 26, 70, .045);
}

.user-notifications .card-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.user-notifications .card-heading-row h2 {
    margin: 0;
    color: var(--user-text);
    font-size: 1.12rem;
    font-weight: 750;
}

.user-notifications .ai-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    padding: 5px 10px;
    border: 1px solid #e4d9f1;
    border-radius: 9px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: .78rem;
    font-weight: 800;
}

.user-notifications .notifications-empty {
    padding: 48px 18px;
    border: 1px dashed #d8cde7;
    border-radius: 14px;
    background: #fbf9fd;
    text-align: center;
}

.user-notifications .notifications-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    margin-bottom: 11px;
    border-radius: 19px;
    background: var(--user-lavender);
    color: var(--user-purple);
    font-size: 1.45rem;
}

.user-notifications .notifications-empty h3 {
    margin: 0;
    color: var(--user-text);
    font-size: 1rem;
    font-weight: 750;
}

.user-notifications .notifications-empty p {
    max-width: 420px;
    margin: 7px auto 0;
    color: var(--user-muted);
    font-size: .87rem;
    line-height: 1.7;
}

/* ===== Button ===== */

.user-notifications .btn {
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.user-notifications .btn:hover {
    transform: translateY(-1px);
}

.user-notifications .btn-primary {
    background: var(--user-purple);
    border-color: var(--user-purple);
    box-shadow: 0 4px 12px rgba(57, 38, 95, .15);
}

.user-notifications .btn-primary:hover {
    background: var(--user-purple-dark);
    border-color: var(--user-purple-dark);
    box-shadow: 0 7px 17px rgba(40, 26, 70, .20);
}

/* ===== Animation ===== */

@keyframes notifFadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ===== Responsive ===== */

@media (max-width: 620px) {
    .user-notifications .notifications-hero {
        padding: 30px 0;
    }

    .user-notifications .dashboard-card {
        padding: 16px;
    }

    .user-notifications .notifications-empty {
        padding: 38px 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-notifications *,
    .user-notifications *::before,
    .user-notifications *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>

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
