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

$headerLinks = [
    'guest' => [
        ['label' => 'Home', 'url' => $headerBase . 'index.php', 'key' => 'home'],
        ['label' => 'About Us', 'url' => $headerBase . 'pages/about.php', 'key' => 'about'],
        ['label' => 'Frequently Asked Questions', 'url' => $headerBase . 'pages/faq.php', 'key' => 'faq'],
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

$headerActive = $headerActive ?? '';
$headerLogo = $headerBase . 'images-website/logo.png';
?>

<header class="site-header">
    <div class="container site-header-inner">

        <!-- Logo -->
        <a class="site-brand" href="<?= header_escape($headerBase . 'index.php') ?>">
            <img
                src="<?= header_escape($headerLogo) ?>"
                alt="Ufuq"
                class="site-brand-logo"
            >
            <span class="site-brand-name">Ufuq</span>
        </a>

        <!-- Main navigation -->
        <nav class="site-nav" id="siteNav" aria-label="Main navigation">
            <?php foreach ($headerLinks[$headerRole] as $link): ?>
                <a
                    href="<?= header_escape($link['url']) ?>"
                    class="site-nav-link<?= $headerActive === $link['key'] ? ' active' : '' ?>"
                    <?= $headerActive === $link['key'] ? 'aria-current="page"' : '' ?>
                >
                    <?= header_escape($link['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Header actions -->
        <div class="site-header-actions">
            <?php if ($headerUserId !== null && $headerRole !== 'guest'): ?>

                <?php if ($headerRole === 'user'): ?>
                    <a
                        class="header-action-link"
                        href="<?= header_escape($headerBase . 'pages/favorites.php') ?>"
                        aria-label="Favorites"
                    >
                        Favorites
                    </a>

                    <a
                        class="header-action-link"
                        href="<?= header_escape($headerBase . 'pages/notifications.php') ?>"
                        aria-label="Notifications"
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
                    class="header-action-link"
                    href="<?= header_escape($headerBase . 'pages/login.php') ?>"
                >
                    Log In
                </a>

                <a
                    class="btn btn-primary btn-sm"
                    href="<?= header_escape($headerBase . 'pages/signup.php') ?>"
                >
                    Sign Up
                </a>

            <?php endif; ?>

            <button
                class="site-menu-toggle"
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
.site-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    background: rgba(255, 255, 255, .97);
    border-bottom: 1px solid rgba(31, 53, 102, .09);
    backdrop-filter: blur(12px);
}

.site-header-inner {
    min-height: 78px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

.site-brand {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    text-decoration: none;
    color: var(--color-primary, #1f3566);
    font-weight: 800;
    font-size: 1.35rem;
}

.site-brand-logo {
    width: 42px;
    height: 42px;
    object-fit: contain;
}

.site-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 25px;
}

.site-nav-link,
.header-action-link {
    color: var(--color-text, #26334d);
    text-decoration: none;
    font-size: .92rem;
    font-weight: 600;
    transition: color .2s ease;
}

.site-nav-link:hover,
.site-nav-link.active,
.header-action-link:hover {
    color: var(--color-primary, #1f3566);
}

.site-nav-link.active {
    font-weight: 800;
}

.site-header-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
}

.header-user-name {
    color: var(--color-primary, #1f3566);
    font-size: .9rem;
    font-weight: 700;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.site-menu-toggle {
    display: none;
    width: 40px;
    height: 40px;
    padding: 9px;
    border: 1px solid #e0e5ef;
    border-radius: 8px;
    background: white;
    cursor: pointer;
}

.site-menu-toggle span {
    display: block;
    height: 2px;
    width: 100%;
    margin: 4px 0;
    background: var(--color-primary, #1f3566);
}

@media (max-width: 900px) {
    .site-header-inner {
        min-height: 70px;
        flex-wrap: wrap;
        gap: 12px;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    .site-menu-toggle {
        display: inline-block;
    }

    .site-nav {
        display: none;
        order: 3;
        width: 100%;
        align-items: stretch;
        flex-direction: column;
        gap: 0;
        padding: 8px 0;
    }

    .site-nav.is-open {
        display: flex;
    }

    .site-nav-link {
        padding: 12px 8px;
        border-radius: 7px;
    }

    .site-nav-link:hover {
        background: #f4f6fb;
    }

    .site-header-actions {
        margin-left: auto;
        gap: 10px;
    }

    .header-action-link {
        display: none;
    }

    .header-user-name {
        max-width: 95px;
    }
}

@media (max-width: 480px) {
    .site-brand-name {
        font-size: 1.15rem;
    }

    .site-brand-logo {
        width: 36px;
        height: 36px;
    }

    .header-user-name {
        display: none;
    }

    .site-header-actions {
        gap: 8px;
    }
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
        const isOpen = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(isOpen));
    });

    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>