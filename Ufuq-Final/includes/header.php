
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
