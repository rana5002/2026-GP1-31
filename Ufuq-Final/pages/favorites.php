<?php


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/recommendations.php';

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
                recommender_reload();
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
            AS CompanyName,
        c.Logo AS CompanyLogo,
        c.CompanyName AS PostingCompanyName
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
$extraCss = '../css/favorites.css';
user_header($me, 'Favorites', 'favorites');
?>

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
                        $durationDays = isset($opportunity['Duration']) && $opportunity['Duration'] !== null
                            ? (int) $opportunity['Duration'] : -1;
                        $duration = $durationDays >= 0
                            ? $durationDays . ($durationDays === 1 ? ' day' : ' days')
                            : '';

                        $deadline = trim(
                            (string) ($opportunity['ApplicationDeadLine'] ?? '')
                        );

                        $deadlineText = '';
                        if ($deadline !== '' && strtotime($deadline) !== false) {
                            $deadlineText = date('d M Y', strtotime($deadline));
                        }

                        /* Status: same as Browse (Open / Upcoming / Closed). */
                        $today = date('Y-m-d');
                        $statusValue = trim((string) ($opportunity['Status'] ?? ''));
                        $openDate = (string) ($opportunity['ApplicationOpenDate'] ?? '');

                        if (strcasecmp($statusValue, 'Closed') === 0 || ($deadline !== '' && $deadline < $today)) {
                            $statusLabel = 'Closed';
                        } elseif ($openDate !== '' && $openDate > $today) {
                            $statusLabel = 'Upcoming';
                        } else {
                            $statusLabel = 'Open';
                        }

                        $statusClass = $statusLabel === 'Open'
                            ? 'badge-open'
                            : ($statusLabel === 'Upcoming' ? 'badge-upcoming' : 'badge-closed');
                        $typeClass = strcasecmp($opportunityType, 'Course') === 0 ? 'badge-course' : 'badge-program';

                        /* Avatar: company logo if it has one, otherwise the first letter of the name (same as Browse). */
                        $avatarLogo = '';
                        $avatarName = $companyName;
                        if (!empty($opportunity['CreatedByCompanyID'])) {
                            $logo = trim((string) ($opportunity['CompanyLogo'] ?? ''));
                            if ($logo !== '') {
                                $avatarLogo = preg_match('~^https?://~i', $logo) ? $logo : '../' . ltrim($logo, '/');
                            }
                            $postingName = trim((string) ($opportunity['PostingCompanyName'] ?? ''));
                            if ($postingName !== '') {
                                $avatarName = $postingName;
                            }
                        }

                        $detailUrl = 'opportunity-detail.php?id='
                            . rawurlencode((string) $opportunityId);
                        ?>

                        <article class="favorite-card">

                            <div class="opportunity-top">

                                <div class="company-info">

                                    <div class="company-logo" aria-hidden="true">
                                        <?php if ($avatarLogo !== ''): ?>
                                            <img src="<?= fav_e($avatarLogo) ?>" alt="">
                                        <?php else: ?>
                                            <?= fav_e(fav_initial($avatarName)) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="company-details">
                                        <h3 class="opportunity-title">
                                            <a href="<?= fav_e($detailUrl) ?>"><?= fav_e($opportunityTitle) ?></a>
                                        </h3>
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

                            <!-- Company name: under the logo + title row -->
                            <div class="company-name">
                                <?= fav_e($companyName) ?>
                            </div>

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

                            </div>

                            <?php if ($deadlineText !== ''): ?>
                                <div class="opportunity-deadline">
                                    <span aria-hidden="true">⧗</span>
                                    Deadline: <?= fav_e($deadlineText) ?>
                                </div>
                            <?php endif; ?>

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