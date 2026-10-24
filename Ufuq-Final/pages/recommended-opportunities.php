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
user_header($me, 'Recommended Opportunities', 'recommended');
?>

<style>
/* ===== Ufuq Recommended Opportunities ===== */

.user-recommended {
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

.user-recommended *,
.user-recommended *::before,
.user-recommended *::after {
    box-sizing: border-box;
}

.user-recommended .recommended-hero {
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

.user-recommended .recommended-hero::before,
.user-recommended .recommended-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-recommended .recommended-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.user-recommended .recommended-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.user-recommended .recommended-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-recommended .hero-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px;
    background: rgba(255,255,255,.08);
    font-size: .84rem;
    letter-spacing: .2px;
}

.user-recommended .recommended-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-recommended .recommended-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.user-recommended .hero-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 78px;
    height: 78px;
    flex-shrink: 0;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 24px;
    background: rgba(255,255,255,.08);
    box-shadow: 0 10px 28px rgba(15,8,30,.16);
    font-size: 2rem;
    animation: recFloat 4s ease-in-out infinite;
}

.user-recommended .recommended-content {
    animation: recFadeUp .5s ease both;
}

.user-recommended .dashboard-card {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--user-border);
    border-top: 3px solid var(--user-gold);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
}

.user-recommended .card-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.user-recommended .card-heading-row h2 {
    margin: 0;
    color: var(--user-text);
    font-size: 1.12rem;
    font-weight: 750;
}

.user-recommended .ai-badge {
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

.user-recommended .empty-state {
    padding: 48px 18px;
    border: 1px dashed #d8cde7;
    border-radius: 14px;
    background: #fbf9fd;
    text-align: center;
}

.user-recommended .empty-icon {
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

.user-recommended .empty-state h3 {
    margin: 0;
    color: var(--user-text);
    font-size: 1rem;
    font-weight: 750;
}

.user-recommended .empty-state p {
    max-width: 420px;
    margin: 7px auto 0;
    color: var(--user-muted);
    font-size: .87rem;
    line-height: 1.7;
}

.user-recommended .btn {
    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.user-recommended .btn:hover {
    transform: translateY(-1px);
}

.user-recommended .btn-primary {
    background: var(--user-purple);
    border-color: var(--user-purple);
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
}

.user-recommended .btn-primary:hover {
    background: var(--user-purple-dark);
    border-color: var(--user-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.20);
}

@keyframes recFadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes recFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}

@media (max-width: 620px) {
    .user-recommended .recommended-hero {
        padding: 30px 0;
    }

    .user-recommended .hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .user-recommended .dashboard-card {
        padding: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-recommended *,
    .user-recommended *::before,
    .user-recommended *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>

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