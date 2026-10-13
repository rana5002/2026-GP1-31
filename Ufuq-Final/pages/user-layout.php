<?php
/* Requires includes/bootstrap.php first.
   Uses $_SESSION['user_id'] and $_SESSION['role'] = 'User'. */

function require_user(): int {
    if (
        ($_SESSION['role'] ?? '') !== 'User' ||
        empty($_SESSION['user_id'])
    ) {
        header('Location: login.php');
        exit;
    }

    return (int) $_SESSION['user_id'];
}

/* Same colour per name, ported from colorFor() in components.js */
function color_for(string $name): string {
    $colors = ['#8E789F', '#557D68', '#A27B43', '#6A7FA3', '#A35D64'];
    $hash = 0;

    foreach (mb_str_split($name ?: 'U') as $ch) {
        $hash = mb_ord($ch) + (($hash << 5) - $hash);
        $hash &= 0xFFFFFFFF;

        if ($hash >= 0x80000000) {
            $hash -= 0x100000000;
        }
    }

    return $colors[abs($hash) % count($colors)];
}

function user_header(array $me, string $title, string $active): void {
    global $pdo;

    $links = [
        'user-home'   => ['Home', 'home.php'],
        'browse'      => ['Browse', 'browse.php'],
        'recommended' => ['Recommended Opportunities', 'recommended-opportunities.php'],
        'history'     => ['Application History', 'application-history.php'],
    ];

    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM usernotification un
         JOIN notification n ON n.NotificationID = un.NotificationID
         WHERE un.UserID = ? AND n.IsRead = 0'
    );
    $st->execute([$me['UserID']]);
    $unread = (int) $st->fetchColumn();

    $firstName = $me['FirstName'] ?? '';
    $lastName  = $me['LastName'] ?? '';
    $name = trim($firstName . ' ' . $lastName);

    $initials = strtoupper(
        mb_substr($firstName ?: 'U', 0, 1) .
        mb_substr($lastName, 0, 1)
    );

    $gender = $me['Gender'] ?? '';

    $pic = $gender === 'Male'
        ? 'default-avatar-male.png'
        : ($gender === 'Female' ? 'default-avatar-female.png' : null);

    /* Detect whether the current page is Favorites */
    $isFavoritesPage = basename($_SERVER['PHP_SELF'] ?? '') === 'favorites.php';
    ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($title) ?> &mdash; Ufuq</title>

    <link rel="stylesheet" href="../css/base.css">
    <link rel="stylesheet" href="../css/layout.css">
    <link rel="stylesheet" href="../css/icons.css">
</head>

<body>

<header class="site-header">
    <div class="container">

        <!-- Ufuq logo -->
        <a href="home.php" class="brand" style="text-decoration:none;">
            <img
                src="../images-website/logo.png"
                alt="Ufuq"
                class="brand-mark"
                style="width:34px;height:34px;object-fit:contain;background:transparent;"
            >
            Ufuq
        </a>

        <!-- Main navigation -->
        <nav class="nav-links" id="navLinks">
            <?php foreach ($links as $key => [$label, $href]): ?>
                <a
                    href="<?= e($href) ?>"
                    class="<?= $key === $active ? 'active' : '' ?>"
                ><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="nav-actions">

            <!-- Favorites: outlined normally, filled on Favorites page -->
            <a
                class="btn btn-ghost btn-sm"
                href="favorites.php"
                title="Favorites"
                aria-label="Favorites"
                style="padding:8px;color:<?= $isFavoritesPage ? '#8E789F' : 'var(--color-text-muted)' ?>;"
            >
                <?php if ($isFavoritesPage): ?>
                    <span
                        aria-hidden="true"
                        style="font-size:23px;line-height:1;"
                    >♥</span>
                <?php else: ?>
                    <span
                        aria-hidden="true"
                        style="font-size:23px;line-height:1;"
                    >♡</span>
                <?php endif; ?>
            </a>

            <!-- Notifications -->
            <a
                class="btn btn-ghost btn-sm"
                href="notifications.php"
                title="Notifications"
                aria-label="Notifications"
                style="padding:8px;position:relative;"
            >
                <?= icon('notification') ?>

                <?php if ($unread > 0): ?>
                    <span
                        style="position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:var(--color-danger);"
                    ></span>
                <?php endif; ?>
            </a>

            <!-- Log out -->
            <a href="logout.php" class="btn btn-ghost btn-sm">
                Log Out
            </a>

            <!-- User profile -->
            <a href="dashboard-user.php" style="display:inline-flex;">
                <?php if ($pic): ?>
                    <img
                        src="../images-website/<?= e($pic) ?>"
                        alt="<?= e($name) ?>"
                        title="<?= e($name) ?>"
                        style="width:38px;height:38px;border-radius:50%;object-fit:cover;"
                    >
                <?php else: ?>
                    <span
                        class="nav-avatar"
                        title="<?= e($name) ?>"
                        style="width:38px;height:38px;font-size:.9rem;"
                    ><?= e($initials) ?></span>
                <?php endif; ?>
            </a>

            <!-- Mobile menu -->
            <button
                class="nav-toggle"
                id="navToggle"
                aria-label="Toggle menu"
                type="button"
            >
                <?= icon('menu', 22) ?>
            </button>

        </div>
    </div>
</header>

<script>
document.getElementById("navToggle")?.addEventListener("click", function () {
    document.getElementById("navLinks")?.classList.toggle("open");
});
</script>

<main>
<?php
}

function user_footer(): void {
    ?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> Ufuq. All rights reserved.</p>
    </div>
</footer>

</body>
</html>
    <?php
}