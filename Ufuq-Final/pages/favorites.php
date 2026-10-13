<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

/* ---------- Authentication ---------- */
require_login('User');

$uid = (int) $_SESSION['user_id'];

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
                "DELETE FROM favourite
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
$stmt = $pdo->prepare(
    "SELECT
        o.*,
        c.CompanyName
     FROM favourite f
     JOIN opportunity o
       ON o.OpportunityID = f.OpportunityID
     LEFT JOIN company c
       ON c.CompanyID = o.CreatedByCompanyID
     WHERE f.UserID = ?
     ORDER BY f.OpportunityID DESC"
);

$stmt->execute([$uid]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalFavorites = count($favorites);

/* ---------- Page Header ---------- */
user_header($me, 'Favorites', 'favorites');
?>

<style>
:root {
    --fav-purple: #493477;
    --fav-purple-dark: #30204f;
    --fav-gold: #e2b65d;
    --fav-bg: #f7f5fb;
    --fav-card: #ffffff;
    --fav-text: #292438;
    --fav-muted: #777286;
    --fav-border: #e9e4f1;
    --fav-danger: #c74755;
    --fav-success: #25845c;
}

/* ---------- Main Page ---------- */
.favorites-page {
    width: 100%;
    min-height: 75vh;
    padding: 0 0 60px;
    background: var(--fav-bg);
    overflow: hidden;
    box-sizing: border-box;
}

.favorites-page *,
.favorites-page *::before,
.favorites-page *::after {
    box-sizing: border-box;
}

/* ---------- Full-Width Hero ---------- */
.favorites-hero {
    position: relative;
    isolation: isolate;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 30px;

    width: 100%;
    margin: 0 0 30px;
    padding: 38px max(30px, calc((100% - 1120px) / 2));

    color: #ffffff;
    background: linear-gradient(
        115deg,
        var(--fav-purple-dark) 0%,
        var(--fav-purple) 60%,
        #654c91 100%
    );

    border-radius: 0;
    overflow: hidden;
}

.favorites-hero::before {
    content: "";
    position: absolute;
    z-index: -1;
    width: 300px;
    height: 300px;
    right: 8%;
    top: -190px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 50%;
}

.favorites-hero::after {
    content: "";
    position: absolute;
    z-index: -1;
    width: 230px;
    height: 230px;
    right: 17%;
    bottom: -180px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 50%;
}

.favorites-hero-content {
    position: relative;
    z-index: 1;
    min-width: 0;
}

.favorites-eyebrow {
    margin: 0 0 10px;
    color: var(--fav-gold);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2.2px;
    text-transform: uppercase;
}

.favorites-hero h1 {
    margin: 0 0 10px;
    color: #ffffff;
    font-size: clamp(28px, 3vw, 38px);
    font-weight: 800;
    line-height: 1.25;
}

.favorites-hero-description {
    max-width: 560px;
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    font-size: 15px;
    line-height: 1.8;
}

/* Keep the original heart character */
.favorites-hero-icon {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    width: 76px;
    height: 76px;

    color: var(--fav-gold);
    background: rgba(255, 255, 255, 0.09);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 20px;

    font-size: 33px;
}

/* ---------- Content Container ---------- */
.favorites-container {
    width: min(1120px, calc(100% - 40px));
    margin: 0 auto;
}

/* ---------- Flash Message ---------- */
.favorites-alert {
    display: flex;
    align-items: center;
    gap: 10px;

    margin: 0 0 22px;
    padding: 14px 17px;

    border: 1px solid transparent;
    border-radius: 12px;

    font-size: 14px;
    line-height: 1.6;
}

.favorites-alert.success {
    color: var(--fav-success);
    background: #edf8f1;
    border-color: #d2eddb;
}

.favorites-alert.error {
    color: var(--fav-danger);
    background: #fff0f1;
    border-color: #f6d5d8;
}

/* ---------- Summary ---------- */
.favorites-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;

    margin-bottom: 28px;
    padding: 20px 23px;

    background: var(--fav-card);
    border: 1px solid var(--fav-border);
    border-radius: 16px;

    box-shadow: 0 5px 20px rgba(48, 32, 79, 0.035);
}

.favorites-summary-label {
    margin: 0 0 5px;
    color: var(--fav-muted);
    font-size: 13px;
    font-weight: 600;
}

.favorites-summary-title {
    margin: 0;
    color: var(--fav-text);
    font-size: 19px;
    font-weight: 800;
}

.favorites-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 48px;
    height: 48px;
    padding: 0 12px;

    color: var(--fav-purple);
    background: #f0eafb;
    border-radius: 14px;

    font-size: 18px;
    font-weight: 800;
}

/* ---------- Section Heading ---------- */
.favorites-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;

    margin: 0 0 18px;
}

.favorites-section-heading h2 {
    margin: 0;
    color: var(--fav-text);
    font-size: 21px;
    font-weight: 800;
}

.favorites-section-heading p {
    margin: 5px 0 0;
    color: var(--fav-muted);
    font-size: 13px;
    line-height: 1.6;
}

/* ---------- Horizontal Favorites Row ---------- */
.favorites-grid {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 20px;

    width: 100%;
    padding: 3px 2px 18px;

    overflow-x: auto;
    overflow-y: hidden;

    scroll-snap-type: x proximity;
    overscroll-behavior-x: contain;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #c7b8df #eee9f5;
}

.favorites-grid::-webkit-scrollbar {
    height: 8px;
}

.favorites-grid::-webkit-scrollbar-track {
    background: #eee9f5;
    border-radius: 10px;
}

.favorites-grid::-webkit-scrollbar-thumb {
    background: #c7b8df;
    border-radius: 10px;
}

.favorites-grid::-webkit-scrollbar-thumb:hover {
    background: #a994c9;
}

/* ---------- Favorite Card ---------- */
.favorite-card {
    position: relative;
    display: flex;
    flex-direction: column;
    flex: 0 0 320px;

    width: 320px;
    min-width: 0;
    min-height: 330px;
    padding: 21px;

    background: var(--fav-card);
    border: 1px solid var(--fav-border);
    border-radius: 17px;

    box-shadow: 0 5px 18px rgba(48, 32, 79, 0.04);

    scroll-snap-align: start;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        border-color 0.2s ease;
}

.favorite-card:hover {
    transform: translateY(-3px);
    border-color: #d7c9eb;
    box-shadow: 0 12px 28px rgba(48, 32, 79, 0.09);
}

.favorite-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;

    margin-bottom: 18px;
}

.favorite-company {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.favorite-company-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    width: 46px;
    height: 46px;

    color: #ffffff;
    background: var(--fav-purple);
    border-radius: 13px;

    font-size: 17px;
    font-weight: 800;
}

.favorite-company-info {
    min-width: 0;
}

.favorite-company-name {
    margin: 0;
    color: var(--fav-muted);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.favorite-type {
    display: inline-block;
    margin-top: 4px;
    padding: 4px 9px;

    color: var(--fav-purple);
    background: #f0eafb;
    border-radius: 6px;

    font-size: 10px;
    font-weight: 700;
}

.favorite-remove-form {
    flex-shrink: 0;
    margin: 0;
}

.favorite-remove-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 36px;
    height: 36px;
    padding: 0;

    color: #cf5361;
    background: #fff1f2;
    border: 1px solid #f7dce0;
    border-radius: 10px;

    font-size: 20px;
    line-height: 1;
    cursor: pointer;

    transition:
        color 0.2s ease,
        background 0.2s ease,
        transform 0.2s ease;
}

.favorite-remove-btn:hover {
    color: #ffffff;
    background: var(--fav-danger);
    transform: scale(1.04);
}

.favorite-title {
    margin: 0 0 10px;
    color: var(--fav-text);
    font-size: 18px;
    font-weight: 800;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.favorite-description {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    overflow: hidden;

    min-height: 66px;
    margin: 0 0 18px;

    color: var(--fav-muted);
    font-size: 13px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.favorite-meta {
    display: flex;
    flex-direction: column;
    gap: 10px;

    margin: 0 0 20px;
}

.favorite-meta-item {
    display: flex;
    align-items: flex-start;
    gap: 9px;

    color: #625c70;
    font-size: 12px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.favorite-meta-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    width: 22px;
    height: 22px;

    color: var(--fav-purple);
    background: #f3effa;
    border-radius: 7px;

    font-size: 12px;
}

.favorite-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;

    margin-top: auto;
    padding-top: 15px;

    border-top: 1px solid #f0edf4;
}

.favorite-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    color: var(--fav-muted);
    font-size: 11px;
    font-weight: 700;
}

.favorite-status-dot {
    width: 7px;
    height: 7px;
    flex-shrink: 0;

    background: #c2bdcb;
    border-radius: 50%;
}

.favorite-status.open .favorite-status-dot {
    background: #2da66c;
}

.favorite-status.closed .favorite-status-dot {
    background: #d65b65;
}

.favorite-details-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;

    min-height: 38px;
    padding: 9px 13px;

    color: #ffffff;
    background: var(--fav-purple);
    border: 1px solid var(--fav-purple);
    border-radius: 9px;

    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;

    transition:
        background 0.2s ease,
        border-color 0.2s ease;
}

.favorite-details-btn:hover {
    color: #ffffff;
    background: var(--fav-purple-dark);
    border-color: var(--fav-purple-dark);
}

/* ---------- Empty State ---------- */
.favorites-empty {
    padding: 55px 25px;
    text-align: center;

    background: #ffffff;
    border: 1px dashed #d9cde9;
    border-radius: 18px;
}

.favorites-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 76px;
    height: 76px;
    margin: 0 auto 18px;

    color: var(--fav-purple);
    background: #f0eafb;
    border-radius: 22px;

    font-size: 34px;
}

.favorites-empty h2 {
    margin: 0 0 10px;
    color: var(--fav-text);
    font-size: 22px;
    font-weight: 800;
}

.favorites-empty p {
    max-width: 430px;
    margin: 0 auto 22px;

    color: var(--fav-muted);
    font-size: 14px;
    line-height: 1.8;
}

.favorites-browse-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 44px;
    padding: 12px 20px;

    color: #ffffff;
    background: var(--fav-purple);
    border-radius: 10px;

    font-size: 13px;
    font-weight: 700;
    text-decoration: none;

    transition: background 0.2s ease;
}

.favorites-browse-btn:hover {
    color: #ffffff;
    background: var(--fav-purple-dark);
}

/* ---------- Responsive ---------- */
@media (max-width: 700px) {
    .favorites-hero {
        gap: 16px;
        padding: 30px 20px;
    }

    .favorites-hero h1 {
        font-size: 27px;
    }

    .favorites-hero-description {
        font-size: 13px;
    }

    .favorites-hero-icon {
        width: 58px;
        height: 58px;
        border-radius: 16px;
        font-size: 27px;
    }

    .favorites-container {
        width: calc(100% - 32px);
    }

    .favorites-summary {
        padding: 16px;
    }

    .favorites-summary-title {
        font-size: 16px;
    }

    .favorites-count {
        min-width: 42px;
        height: 42px;
    }

    .favorites-section-heading h2 {
        font-size: 18px;
    }

    .favorite-card {
        flex-basis: 285px;
        width: 285px;
        padding: 17px;
    }
}

@media (max-width: 400px) {
    .favorites-hero {
        padding: 25px 16px;
    }

    .favorites-hero-icon {
        width: 48px;
        height: 48px;
        font-size: 23px;
    }

    .favorites-hero h1 {
        font-size: 24px;
    }

    .favorites-container {
        width: calc(100% - 24px);
    }

    .favorite-card {
        flex-basis: 265px;
        width: 265px;
    }
}
</style>

<main class="favorites-page">

    <!-- Full-width hero banner -->
    <section class="favorites-hero">
        <div class="favorites-hero-content">
            <p class="favorites-eyebrow">YOUR UFUQ COLLECTION</p>

            <h1>Favorites</h1>

            <p class="favorites-hero-description">
                Keep track of the training programs and opportunities
                you love, all in one place.
            </p>
        </div>

        <div class="favorites-hero-icon" aria-hidden="true">
            &#9829;
        </div>
    </section>

    <!-- Main content stays centered -->
    <div class="favorites-container">

        <?php if ($flashMessage): ?>
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
                            (string) (
                                $opportunity['Title']
                                ?? $opportunity['OpportunityTitle']
                                ?? $opportunity['Name']
                                ?? 'Untitled Opportunity'
                            )
                        );

                        $description = trim(
                            (string) (
                                $opportunity['Description']
                                ?? $opportunity['OpportunityDescription']
                                ?? ''
                            )
                        );

                        $opportunityType = trim(
                            (string) (
                                $opportunity['OpportunityType']
                                ?? $opportunity['Type']
                                ?? $opportunity['Category']
                                ?? 'Opportunity'
                            )
                        );

                        $location = trim(
                            (string) (
                                $opportunity['Location']
                                ?? $opportunity['City']
                                ?? ''
                            )
                        );

                        $duration = trim(
                            (string) (
                                $opportunity['Duration']
                                ?? ''
                            )
                        );

                        $deadline = trim(
                            (string) (
                                $opportunity['ApplicationDeadline']
                                ?? $opportunity['Deadline']
                                ?? $opportunity['ClosingDate']
                                ?? ''
                            )
                        );

                        $statusValue = strtolower(
                            trim(
                                (string) (
                                    $opportunity['Status']
                                    ?? $opportunity['OpportunityStatus']
                                    ?? ''
                                )
                            )
                        );

                        $isClosed = in_array(
                            $statusValue,
                            ['closed', 'inactive', 'expired', 'completed'],
                            true
                        );

                        if (
                            !$isClosed
                            && $deadline !== ''
                            && strtotime($deadline) !== false
                            && strtotime($deadline) < strtotime(date('Y-m-d'))
                        ) {
                            $isClosed = true;
                        }

                        $statusLabel = $isClosed ? 'Closed' : 'Available';
                        $statusClass = $isClosed ? 'closed' : 'open';

                        $detailUrl = 'opportunity-detail.php?id='
                            . rawurlencode((string) $opportunityId);
                        ?>

                        <article class="favorite-card">

                            <div class="favorite-card-top">

                                <div class="favorite-company">

                                    <div
                                        class="favorite-company-logo"
                                        <?php if (function_exists('color_for')): ?>
                                            style="background: <?= fav_e(color_for($companyName)) ?>;"
                                        <?php endif; ?>
                                        aria-hidden="true"
                                    >
                                        <?= fav_e(fav_initial($companyName)) ?>
                                    </div>

                                    <div class="favorite-company-info">
                                        <p class="favorite-company-name">
                                            <?= fav_e($companyName) ?>
                                        </p>

                                        <span class="favorite-type">
                                            <?= fav_e($opportunityType) ?>
                                        </span>
                                    </div>

                                </div>

                                <form
                                    class="favorite-remove-form"
                                    method="POST"
                                    action="favorites.php"
                                    onsubmit="return confirm('Remove this opportunity from your favorites?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= fav_e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove_favorite"
                                    >

                                    <input
                                        type="hidden"
                                        name="opportunity_id"
                                        value="<?= $opportunityId ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="favorite-remove-btn"
                                        title="Remove from favorites"
                                        aria-label="Remove <?= fav_e($opportunityTitle) ?> from favorites"
                                    >
                                        &#9829;
                                    </button>
                                </form>

                            </div>

                            <h3 class="favorite-title">
                                <?= fav_e($opportunityTitle) ?>
                            </h3>

                            <p class="favorite-description">
                                <?= $description !== ''
                                    ? fav_e($description)
                                    : 'View the opportunity details to learn more about this program.' ?>
                            </p>

                            <div class="favorite-meta">

                                <?php if ($location !== ''): ?>
                                    <div class="favorite-meta-item">
                                        <span class="favorite-meta-icon" aria-hidden="true">
                                            &#9906;
                                        </span>

                                        <span>
                                            <?= fav_e($location) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($duration !== ''): ?>
                                    <div class="favorite-meta-item">
                                        <span class="favorite-meta-icon" aria-hidden="true">
                                            &#9719;
                                        </span>

                                        <span>
                                            <?= fav_e($duration) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($deadline !== ''): ?>
                                    <div class="favorite-meta-item">
                                        <span class="favorite-meta-icon" aria-hidden="true">
                                            &#128197;
                                        </span>

                                        <span>
                                            Deadline: <?= fav_e($deadline) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                            </div>

                            <div class="favorite-card-footer">

                                <span class="favorite-status <?= fav_e($statusClass) ?>">
                                    <span class="favorite-status-dot"></span>
                                    <?= fav_e($statusLabel) ?>
                                </span>

                                <a
                                    class="favorite-details-btn"
                                    href="<?= fav_e($detailUrl) ?>"
                                >
                                    View Details
                                    <span aria-hidden="true">&#8594;</span>
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

                <h2>No Favorites Yet</h2>

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

</main>

<?php
/*
 * Keep the footer function used by your existing user layout.
 * If your layout already outputs the footer through user_header(),
 * remove this call only if your original file did not have it.
 */
if (function_exists('user_footer')) {
    user_footer();
}
?>