
<?php
/* =========================================================
   Ufuq - Shared Public Header
   File: includes/header.php
   ========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('header_escape')) {
    function header_escape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$headerRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$headerUserId = $_SESSION['user_id'] ?? $_SESSION['member_id'] ?? null;

$headerName = (string) (
    $_SESSION['name']
    ?? $_SESSION['full_name']
    ?? $_SESSION['CompanyName']
    ?? ''
);

$headerBase = $headerBase ?? '';
$headerActive = $headerActive ?? '';

$headerLinks = [
    'guest' => [
        ['label' => 'Home', 'url' => $headerBase . 'index.php', 'key' => 'home'],
        ['label' => 'About Us', 'url' => $headerBase . 'pages/about.php', 'key' => 'about'],
    ],
    'user' => [
        ['label' => 'Home', 'url' => $headerBase . 'pages/home.php', 'key' => 'home'],
        ['label' => 'Browse', 'url' => $headerBase . 'pages/browse.php', 'key' => 'browse'],
        ['label' => 'Skill Gap Analysis', 'url' => $headerBase . 'pages/skill-gap.php', 'key' => 'skill-gap'],
        ['label' => 'Application History', 'url' => $headerBase . 'pages/application-history.php', 'key' => 'history'],
    ],
    'company' => [],
    'admin' => [
        ['label' => 'Company Verification', 'url' => $headerBase . 'pages/dashboard-admin.php', 'key' => 'verification'],
        ['label' => 'Manage Opportunities', 'url' => $headerBase . 'pages/admin-opportunities.php', 'key' => 'opportunities'],
    ],
];

if (!isset($headerLinks[$headerRole])) {
    $headerRole = 'guest';
}

$headerLogo = $headerBase . 'images-website/logo.png';
?>

<header class="site-header">
    <div class="container">

        <!-- Logo -->
        <a class="brand" href="<?= header_escape($headerBase . 'index.php') ?>">
            <img
                src="<?= header_escape($headerLogo) ?>"
                alt="Ufuq"
                class="site-brand-logo"
            >
            <span>Ufuq</span>
        </a>

        <!-- Main navigation -->
        <nav class="nav-links" id="siteNav" aria-label="Main navigation">
            <?php foreach ($headerLinks[$headerRole] as $link): ?>
                <a
                    href="<?= header_escape($link['url']) ?>"
                    class="<?= $headerActive === $link['key'] ? 'active' : '' ?>"
                    <?= $headerActive === $link['key'] ? 'aria-current="page"' : '' ?>
                >
                    <?= header_escape($link['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Header actions -->
        <div class="nav-actions">

            <?php if ($headerUserId !== null && $headerRole !== 'guest'): ?>

                <?php if ($headerRole === 'user'): ?>
                    <a
                        href="<?= header_escape($headerBase . 'pages/favorites.php') ?>"
                        class="header-action-link"
                    >
                        Favorites
                    </a>

                    <a
                        href="<?= header_escape($headerBase . 'pages/notifications.php') ?>"
                        class="header-action-link"
                    >
                        Notifications
                    </a>
                <?php endif; ?>

                <span class="header-user-name">
                    <?= header_escape($headerName !== '' ? $headerName : 'Account') ?>
                </span>

                <a
                    class="btn btn-outline btn-sm"
                    href="<?= header_escape($headerBase . 'logout.php') ?>"
                >
                    Log Out
                </a>

            <?php else: ?>

                <a
                    class="header-action-link header-login<?= $headerActive === 'login' ? ' is-current' : '' ?>"
                    href="<?= header_escape($headerBase . 'pages/login.php') ?>"
                    <?= $headerActive === 'login' ? 'aria-current="page"' : '' ?>
                >
                    Log In
                </a>

                <a
                    class="btn btn-primary btn-sm header-signup<?= $headerActive === 'signup' ? ' is-current' : '' ?>"
                    href="<?= header_escape($headerBase . 'pages/signup.php') ?>"
                    <?= $headerActive === 'signup' ? 'aria-current="page"' : '' ?>
                >
                    Sign Up
                </a>

            <?php endif; ?>

            <button
                class="nav-toggle"
                id="siteMenuToggle"
                type="button"
                aria-label="Toggle navigation"
                aria-expanded="false"
                aria-controls="siteNav"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>

        </div>
    </div>
</header>

<style>
/* Logo image */
.brand {
    text-decoration: none;
    color: var(--color-primary-dark);
    flex-shrink: 0;
}

.site-brand-logo {
    display: block;
    width: 38px;
    height: 38px;
    object-fit: contain;
}

.brand span {
    display: inline-block;
}

/* Header layout */
.site-header .container {
    min-height: var(--header-height);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

/* Home / About Us in the middle of the header */
@media (min-width: 861px) {
    .site-header .container {
        position: relative;
    }

    .site-header .nav-links {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }
}

/* Header actions */
.nav-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-3);
    flex-shrink: 0;
}

.header-action-link {
    color: var(--color-text-muted);
    font-size: 0.92rem;
    font-weight: 500;
    text-decoration: none;
}

.header-action-link:hover {
    color: var(--color-primary-dark);
    text-decoration: none;
}

.header-user-name {
    color: var(--color-primary-dark);
    font-size: 0.9rem;
    font-weight: 700;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Mobile navigation */
.nav-toggle {
    display: none;
    width: 40px;
    height: 40px;
    padding: 8px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    cursor: pointer;
}

.nav-toggle span {
    display: block;
    width: 100%;
    height: 2px;
    margin: 4px 0;
    background: var(--color-primary);
}

@media (max-width: 860px) {
    .site-header .container {
        position: relative;
        flex-wrap: wrap;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    .nav-toggle {
        display: block;
    }

    .site-header .nav-links {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 100;
        display: none;
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-3);
        padding: var(--space-4) var(--space-5);
        background: var(--color-surface);
        border-bottom: 1px solid var(--color-border);
    }

    .site-header .nav-links.open {
        display: flex;
    }

    .site-header .nav-links a {
        width: 100%;
        padding: 8px 0;
    }

    .header-action-link {
        display: none;
    }

    .header-user-name {
        max-width: 95px;
    }

    .nav-actions {
        margin-left: auto;
    }
}

@media (max-width: 480px) {
    .site-brand-logo {
        width: 34px;
        height: 34px;
    }

    .header-user-name {
        display: none;
    }

    .nav-actions {
        gap: 8px;
    }
}
.site-header .nav-actions .btn {
    font-size: 0.95rem;
    padding: 9px 14px;
    border-radius: 10px;
    line-height: normal;
    font-weight: 700;
}

.site-header .nav-actions .btn-sm {
    padding: 9px 14px;
}

/* Log In / Sign Up: same size */
.site-header .nav-actions .header-login,
.site-header .nav-actions .header-signup {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    min-width: 96px;
    height: 40px;
    padding: 0 16px;
    border: 1px solid transparent;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
}

.site-header .nav-actions .header-login {
    color: var(--color-primary-dark);
    background: transparent;
    border-color: transparent;
}

.site-header .nav-actions .header-login:hover {
    background: #f0ebf8;
}

/* Current page: the button of the page you are on looks pressed */
.site-header .nav-actions .header-login.is-current,
.site-header .nav-actions .header-signup.is-current {
    cursor: default;
    pointer-events: none;
}

.site-header .nav-actions .header-login.is-current {
    color: var(--color-primary-dark);
    background: #ece4f7;
    border-color: #c9b8e2;
    box-shadow: inset 0 1px 3px rgba(40, 26, 70, .12);
}

/* Sign Up keeps its own colour, with a dark shadow under it */
.site-header .nav-actions .header-signup.is-current {
    box-shadow: 0 7px 14px -3px rgba(20, 10, 40, .6);
}

</style>

<script>
(function () {
    const toggle = document.getElementById('siteMenuToggle');
    const nav = document.getElementById('siteNav');

    if (!toggle || !nav || toggle.dataset.initialized === 'true') {
        return;
    }

    toggle.dataset.initialized = 'true';

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(isOpen));
    });

    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>