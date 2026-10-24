<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

/* ---------- Authentication ---------- */
$uid = require_user();

/* ---------- Helpers ---------- */
function fav_e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fav_initial($value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'U';
    }

    return function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
        : strtoupper(substr($value, 0, 1));
}

/* ---------- Load current user ---------- */
$stmt = $pdo->prepare(
    "SELECT *
     FROM `user`
     WHERE UserID = ?
     LIMIT 1"
);
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    header('Location: login.php');
    exit;
}

/* ---------- CSRF Token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------- Remove Favorite ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if (
        !hash_equals(
            (string) $_SESSION['csrf_token'],
            $token
        )
    ) {
        $_SESSION['favorites_message'] = [
            'type' => 'error',
            'text' => 'Your session has expired. Please try again.'
        ];
    } elseif ($action === 'remove_favorite') {
        $opportunityId = filter_input(
            INPUT_POST,
            'opportunity_id',
            FILTER_VALIDATE_INT
        );

        if ($opportunityId) {
            $deleteStmt = $pdo->prepare(
                "DELETE FROM favorite
                 WHERE UserID = ?
                   AND OpportunityID = ?"
            );

            $deleteStmt->execute([
                $uid,
                $opportunityId
            ]);

            if ($deleteStmt->rowCount() > 0) {
                $_SESSION['favorites_message'] = [
                    'type' => 'success',
                    'text' => 'Opportunity removed from your favorites.'
                ];
            } else {
                $_SESSION['favorites_message'] = [
                    'type' => 'error',
                    'text' => 'This opportunity is no longer in your favorites.'
                ];
            }
        } else {
            $_SESSION['favorites_message'] = [
                'type' => 'error',
                'text' => 'Invalid opportunity.'
            ];
        }
    }

    header('Location: favorites.php');
    exit;
}

/* ---------- Flash Message ---------- */
$flashMessage = $_SESSION['favorites_message'] ?? null;
unset($_SESSION['favorites_message']);

/* ---------- Load Favorites ---------- */
/*
 * The provider name is stored on the opportunity itself, so it also works
 * for opportunities created by an admin (no company). The company name is
 * only a fallback.
 */
$stmt = $pdo->prepare(
    "SELECT
        o.*,
        COALESCE(NULLIF(TRIM(o.OpportunityProvider), ''), c.CompanyName)
            AS CompanyName
     FROM favorite f
     JOIN opportunity o
       ON o.OpportunityID = f.OpportunityID
     LEFT JOIN company c
       ON c.CompanyID = o.CreatedByCompanyID
     WHERE f.UserID = ?
     ORDER BY f.DateAdded DESC, f.OpportunityID DESC"
);

$stmt->execute([$uid]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalFavorites = count($favorites);

/* ---------- Page Header ---------- */
user_header($me, 'Favorites', 'favorites');
?>

<style>
/* ===== Ufuq Favorites (same theme as Home / Browse) ===== */

.user-favorites {
    --fav-purple: #39265f;
    --fav-purple-dark: #281a46;
    --fav-gold: #e2a94b;
    --fav-lavender: #f0ebf8;
    --fav-bg: #faf6ef;
    --fav-card: #ffffff;
    --fav-text: #29243a;
    --fav-muted: #777184;
    --fav-border: #e9e3ed;
    --fav-danger: #c74755;
    --fav-success: #25845c;

    min-height: 70vh;
    padding-bottom: 56px;
    background: var(--fav-bg);
    color: var(--fav-text);
}

.user-favorites *,
.user-favorites *::before,
.user-favorites *::after {
    box-sizing: border-box;
}

/* ---------- Banner ---------- */
.user-favorites .favorites-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    color: #fff;
    background: linear-gradient(125deg, #281a46 0%, #39265f 55%, #49316f 100%);
}

.user-favorites .favorites-hero::before,
.user-favorites .favorites-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-favorites .favorites-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.user-favorites .favorites-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.user-favorites .favorites-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-favorites .favorites-label {
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

.user-favorites .favorites-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-favorites .favorites-hero-description {
    max-width: 560px;
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.user-favorites .favorites-hero-icon {
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
    color: var(--fav-gold);
    font-size: 2rem;
    animation: favFloat 4s ease-in-out infinite;
}

.user-favorites .favorites-container {
    animation: favFadeUp .5s ease both;
}

@keyframes favFadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@keyframes favFloat {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-5px); }
}

/* Toast: success message that slides down from the top, then disappears (same as Browse and the dashboards) */
.ufuq-toast {
    position: fixed;
    top: 18px;
    left: 50%;
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 11px;
    width: max-content;
    max-width: calc(100% - 32px);
    padding: 13px 14px 13px 16px;
    border: 1px solid #bfe3cc;
    border-radius: 13px;
    background: #eaf7ef;
    color: #1f6b47;
    font-size: .92rem;
    font-weight: 700;
    line-height: 1.5;
    box-shadow: 0 12px 30px rgba(31,107,71,.16);
    transform: translate(-50%, -150%);
    opacity: 0;
    transition: transform .35s ease, opacity .35s ease;
}

.ufuq-toast.is-shown {
    transform: translate(-50%, 0);
    opacity: 1;
}

.ufuq-toast-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #2f8a5c;
    color: #fff;
    font-size: .85rem;
}

.ufuq-toast-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: none;
    color: #1f6b47;
    font-size: 1.2rem;
    line-height: 1;
    cursor: pointer;
}

.ufuq-toast-close:hover {
    background: rgba(31,107,71,.1);
}

@media (prefers-reduced-motion: reduce) {
    .ufuq-toast { transition: none; }
}

/* ---------- Flash Message (errors) ---------- */
.user-favorites .favorites-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 22px;
    padding: 14px 17px;
    border: 1px solid transparent;
    border-radius: 12px;
    font-size: .9rem;
    line-height: 1.6;
}

.user-favorites .favorites-alert.success {
    color: var(--fav-success);
    background: #edf8f1;
    border-color: #d2eddb;
}

.user-favorites .favorites-alert.error {
    color: var(--fav-danger);
    background: #fff0f1;
    border-color: #f6d5d8;
}

/* ---------- Summary ---------- */
.user-favorites .favorites-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 28px;
    padding: 20px 23px;
    background: var(--fav-card);
    border: 1px solid var(--fav-border);
    border-top: 3px solid var(--fav-gold);
    border-radius: 16px;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
}

.user-favorites .favorites-summary-label {
    margin: 0 0 5px;
    color: var(--fav-muted);
    font-size: .83rem;
    font-weight: 600;
}

.user-favorites .favorites-summary-title {
    margin: 0;
    color: var(--fav-text);
    font-size: 1.18rem;
    font-weight: 800;
}

.user-favorites .favorites-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 48px;
    height: 48px;
    padding: 0 12px;
    color: var(--fav-purple);
    background: var(--fav-lavender);
    border-radius: 14px;
    font-size: 1.1rem;
    font-weight: 800;
}

/* ---------- Section Heading ---------- */
.user-favorites .favorites-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin: 0 0 18px;
}

.user-favorites .favorites-section-heading h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: var(--fav-text);
    font-size: 1.2rem;
    font-weight: 750;
}

.user-favorites .favorites-section-heading h2::before {
    content: "";
    width: 5px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--fav-gold);
}

.user-favorites .favorites-section-heading p {
    margin: 6px 0 0;
    color: var(--fav-muted);
    font-size: .85rem;
    line-height: 1.6;
}

/* ----------  Favorites styles ---------- */
.user-favorites .favorites-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)) !important;
    gap: 20px !important;
    overflow-x: visible !important;
    scroll-snap-type: none !important;
    padding: 10px 0 !important;
}

.user-favorites .favorite-card {
    width: 100% !important;
    flex: 1 1 auto !important;
}

.user-favorites .favorites-grid::-webkit-scrollbar {
    height: 8px;
}

.user-favorites .favorites-grid::-webkit-scrollbar-track {
    background: #eee9f5;
    border-radius: 10px;
}

.user-favorites .favorites-grid::-webkit-scrollbar-thumb {
    background: #c7b8df;
    border-radius: 10px;
}

.user-favorites .favorites-grid::-webkit-scrollbar-thumb:hover {
    background: #a994c9;
}

/* ---------- Favorite Card (same card and icons as the Browse page) ---------- */
.user-favorites .favorite-card {
    display: flex;
    flex-direction: column;
    flex: 0 0 320px;
    width: 320px;
    min-width: 0;
    padding: 19px;
    border: 1px solid #ece7f2;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    scroll-snap-align: start;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.user-favorites .favorite-card:hover {
    transform: translateY(-3px);
    border-color: #cfc1e2;
    box-shadow: 0 12px 28px rgba(40,26,70,.1);
}

.user-favorites .opportunity-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 15px;
}

.user-favorites .company-info {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.user-favorites .company-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    border-radius: 14px;
    background: var(--fav-lavender);
    color: var(--fav-purple);
    font-size: 1.15rem;
    font-weight: 800;
}

.user-favorites .company-details {
    min-width: 0;
}

.user-favorites .opportunity-title {
    margin: 0 0 4px;
    color: var(--fav-text);
    font-size: 1rem;
    font-weight: 750;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-favorites .opportunity-title a {
    color: inherit;
    text-decoration: none;
}

.user-favorites .opportunity-title a:hover {
    color: var(--fav-purple);
    text-decoration: underline;
}

.user-favorites .company-name {
    color: var(--fav-muted);
    font-size: .82rem;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.user-favorites .favorite-form {
    flex-shrink: 0;
    margin: 0;
}

.user-favorites .favorite-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 37px;
    height: 37px;
    padding: 0;
    border: 1px solid #f1d4d8;
    border-radius: 11px;
    background: #fff1f2;
    color: #bd334d;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    transition: transform .18s ease, background .18s ease, border-color .18s ease;
}

.user-favorites .favorite-button:hover {
    transform: translateY(-1px);
    border-color: #e5b9c1;
    background: #fff6f7;
}

.user-favorites .opportunity-description {
    display: -webkit-box;
    overflow: hidden;
    margin: 0 0 17px;
    color: #625b6d;
    font-size: .87rem;
    line-height: 1.8;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}

.user-favorites .opportunity-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 17px;
    color: var(--fav-muted);
    font-size: .81rem;
    line-height: 1.6;
}

.user-favorites .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 10px;
    border-radius: 30px;
    font-size: .76rem;
    font-weight: 750;
    white-space: normal;
    overflow-wrap: anywhere;
}

.user-favorites .badge-course {
    background: #e8f5ec;
    color: #24653c;
}

.user-favorites .badge-program {
    background: #eee8f8;
    color: var(--fav-purple);
}

.user-favorites .badge-open {
    background: #e8f5ec;
    color: #24653c;
}

.user-favorites .badge-closed {
    border: 1px solid #f1d4d8;
    background: #fff1f2;
    color: #9c263d;
}

.user-favorites .opportunity-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid #f0edf4;
}

.user-favorites .favorite-details-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 35px;
    padding: 8px 12px;
    color: #fff;
    background: var(--fav-purple);
    border: 1px solid var(--fav-purple);
    border-radius: 9px;
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.user-favorites .favorite-details-btn:hover {
    color: #fff;
    background: var(--fav-purple-dark);
    border-color: var(--fav-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.2);
    transform: translateY(-1px);
}

/* ---------- Empty State ---------- */
.user-favorites .favorites-empty {
    padding: 55px 25px;
    text-align: center;
    background: #fbf9fd;
    border: 1px dashed #d8cde7;
    border-radius: 18px;
}

.user-favorites .favorites-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    margin: 0 auto 18px;
    color: var(--fav-purple);
    background: var(--fav-lavender);
    border-radius: 22px;
    font-size: 2.1rem;
}

.user-favorites .favorites-empty h2 {
    margin: 0 0 10px;
    color: var(--fav-text);
    font-size: 1.2rem;
    font-weight: 750;
}

.user-favorites .favorites-empty p {
    max-width: 430px;
    margin: 0 auto 22px;
    color: var(--fav-muted);
    font-size: .9rem;
    line-height: 1.8;
}

.user-favorites .favorites-browse-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 10px 18px;
    color: #fff;
    background: var(--fav-purple);
    border-radius: 9px;
    box-shadow: 0 4px 12px rgba(57,38,95,.15);
    font-size: .85rem;
    font-weight: 700;
    text-decoration: none;
    transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.user-favorites .favorites-browse-btn:hover {
    color: #fff;
    background: var(--fav-purple-dark);
    box-shadow: 0 7px 17px rgba(40,26,70,.2);
    transform: translateY(-1px);
}

/* ---------- Responsive ---------- */
@media (max-width: 700px) {
    .user-favorites .favorites-hero {
        padding: 30px 0;
    }

    .user-favorites .favorites-hero-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        font-size: 1.6rem;
    }

    .user-favorites .favorites-summary {
        padding: 16px;
    }

    .user-favorites .favorites-summary-title {
        font-size: 1rem;
    }

    .user-favorites .favorites-count {
        min-width: 42px;
        height: 42px;
    }

    .user-favorites .favorite-card {
        flex-basis: 285px;
        width: 285px;
        padding: 17px;
    }
}

@media (max-width: 400px) {
    .user-favorites .favorite-card {
        flex-basis: 265px;
        width: 265px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-favorites *,
    .user-favorites *::before,
    .user-favorites *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>

<?php /* user_header() already opens <main>, so this wrapper is a div. */ ?>
<div class="user-favorites">

    <!-- Banner -->
    <section class="favorites-hero">
        <div class="container favorites-hero-inner">

            <div>
                <div class="favorites-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Favorites</h1>

                <p class="favorites-hero-description">
                    Keep track of the training programs and opportunities
                    you love, all in one place.
                </p>
            </div>

            <div class="favorites-hero-icon" aria-hidden="true">
                &#9829;
            </div>

        </div>
    </section>

    <!-- Main content -->
    <div class="container favorites-container">

    <?php if ($flashMessage && ($flashMessage['type'] ?? '') === 'success'): ?>
            <div class="ufuq-toast" id="successToast" role="status" aria-live="polite">
                <span class="ufuq-toast-icon" aria-hidden="true">✓</span>
                <span><?= fav_e($flashMessage['text'] ?? '') ?></span>
                <button type="button" class="ufuq-toast-close" aria-label="Close">&times;</button>
            </div>
        <?php elseif ($flashMessage): ?>
            <div class="favorites-alert <?= fav_e($flashMessage['type'] ?? 'success') ?>">
                <span aria-hidden="true">
                    <?= ($flashMessage['type'] ?? '') === 'error' ? '&#9888;' : '&#10003;' ?>
                </span>

                <span>
                    <?= fav_e($flashMessage['text'] ?? '') ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Favorites summary -->
        <section class="favorites-summary">
            <div>
                <p class="favorites-summary-label">
                    Your saved opportunities
                </p>

                <h2 class="favorites-summary-title">
                    Total Favorites
                </h2>
            </div>

            <div class="favorites-count">
                <?= (int) $totalFavorites ?>
            </div>
        </section>

        <?php if (!empty($favorites)): ?>

            <section class="favorites-section">

                <div class="favorites-section-heading">
                    <div>
                        <h2>Saved Opportunities</h2>

                        <p>
                            Scroll horizontally to explore your saved opportunities.
                        </p>
                    </div>
                </div>

                <!-- All opportunity cards remain in one horizontal row -->
                <div class="favorites-grid">

                    <?php foreach ($favorites as $opportunity): ?>

                        <?php
                        $opportunityId = (int) ($opportunity['OpportunityID'] ?? 0);

                        $companyName = trim(
                            (string) ($opportunity['CompanyName'] ?? '')
                        );

                        if ($companyName === '') {
                            $companyName = 'Company';
                        }

                        $opportunityTitle = trim(
                            (string) ($opportunity['Title'] ?? '')
                        );

                        if ($opportunityTitle === '') {
                            $opportunityTitle = 'Untitled Opportunity';
                        }

                        $description = trim(
                            (string) ($opportunity['Description'] ?? '')
                        );

                        $opportunityType = trim(
                            (string) ($opportunity['Type'] ?? '')
                        );

                        if ($opportunityType === '') {
                            $opportunityType = 'Opportunity';
                        }

                        $location = trim(
                            (string) ($opportunity['Location'] ?? '')
                        );

                        /* Duration is stored as a number of days. */
                        $durationDays = (int) ($opportunity['Duration'] ?? 0);
                        $duration = $durationDays > 0
                            ? $durationDays . ($durationDays === 1 ? ' day' : ' days')
                            : '';

                        $deadline = trim(
                            (string) ($opportunity['ApplicationDeadLine'] ?? '')
                        );

                        $deadlineText = '';
                        if ($deadline !== '' && strtotime($deadline) !== false) {
                            $deadlineText = date('d M Y', strtotime($deadline));
                        }

                        $statusValue = strtolower(
                            trim((string) ($opportunity['Status'] ?? ''))
                        );

                        $isClosed = $statusValue === 'closed';

                        if (
                            !$isClosed
                            && $deadline !== ''
                            && strtotime($deadline) !== false
                            && strtotime($deadline) < strtotime(date('Y-m-d'))
                        ) {
                            $isClosed = true;
                        }

                        /* Same status badge as the Browse page: Open, or Closed after the deadline / when closed. */
                        $statusLabel = $isClosed ? 'Closed' : 'Open';
                        $statusClass = $isClosed ? 'badge-closed' : 'badge-open';
                        $typeClass = strcasecmp($opportunityType, 'Course') === 0 ? 'badge-course' : 'badge-program';

                        $detailUrl = 'opportunity-detail.php?id='
                            . rawurlencode((string) $opportunityId);
                        ?>

                        <article class="favorite-card">

                            <div class="opportunity-top">

                                <div class="company-info">

                                    <div class="company-logo" aria-hidden="true">
                                        <?= fav_e(fav_initial($companyName)) ?>
                                    </div>

                                    <div class="company-details">

                                        <h3 class="opportunity-title">
                                            <a href="<?= fav_e($detailUrl) ?>"><?= fav_e($opportunityTitle) ?></a>
                                        </h3>

                                        <div class="company-name">
                                            <?= fav_e($companyName) ?>
                                        </div>

                                    </div>

                                </div>

                                <!-- Remove from favorites -->
                                <form
                                    class="favorite-form"
                                    method="POST"
                                    action="favorites.php"
                                    onsubmit="return confirm('Remove this opportunity from your favorites?');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= fav_e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="remove_favorite">
                                    <input type="hidden" name="opportunity_id" value="<?= $opportunityId ?>">

                                    <button
                                        type="submit"
                                        class="favorite-button"
                                        title="Remove from favorites"
                                        aria-label="Remove <?= fav_e($opportunityTitle) ?> from favorites"
                                    >
                                        <span aria-hidden="true">♥</span>
                                    </button>
                                </form>

                            </div>

                            <p class="opportunity-description">
                                <?= $description !== ''
                                    ? fav_e($description)
                                    : 'No description provided.' ?>
                            </p>

                            <div class="opportunity-meta">

                                <span class="badge <?= $typeClass ?>">
                                    <?= fav_e($opportunityType) ?>
                                </span>

                                <span>
                                    <span aria-hidden="true">⌖</span>
                                    <?= fav_e($location !== '' ? $location : 'Location not specified') ?>
                                </span>

                                <span>
                                    <span aria-hidden="true">◷</span>
                                    <?= fav_e($duration !== '' ? $duration : 'Not specified') ?>
                                </span>

                                <?php if ($deadlineText !== ''): ?>
                                    <span>
                                        <span aria-hidden="true">⧗</span>
                                        Deadline: <?= fav_e($deadlineText) ?>
                                    </span>
                                <?php endif; ?>

                            </div>

                            <div class="opportunity-footer">

                                <span class="badge <?= $statusClass ?>">
                                    <?= fav_e($statusLabel) ?>
                                </span>

                                <a class="favorite-details-btn" href="<?= fav_e($detailUrl) ?>">
                                    View Details
                                    <span aria-hidden="true">&rarr;</span>
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php else: ?>

            <section class="favorites-empty">

                <div class="favorites-empty-icon" aria-hidden="true">
                    &#9829;
                </div>

                <h2>No saved opportunities yet</h2>

                <p>
                    You haven't saved any opportunities yet.
                    Explore available training programs and save the ones
                    that interest you to find them here later.
                </p>

                <a class="favorites-browse-btn" href="browse.php">
                    Explore Opportunities
                    <span aria-hidden="true">&nbsp;&#8594;</span>
                </a>

            </section>

        <?php endif; ?>

    </div>

</div>

<script>
/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('successToast');
    if (!toast) return;

    /* Move it to <body> so the page animations do not affect its fixed position. */
    document.body.appendChild(toast);

    function hide() {
        toast.classList.remove('is-shown');
        setTimeout(function () { toast.remove(); }, 400);
    }

    requestAnimationFrame(function () { toast.classList.add('is-shown'); });
    const timer = setTimeout(hide, 3500);
    toast.querySelector('.ufuq-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
