<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

no_cache();

// Already logged in -> go to own dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    header('Location: ' . dashboard_for((string)$_SESSION['role']));
    exit;
}

$errors = [];
$banner = '';
$email  = '';
$flash  = isset($_GET['reset'])
    ? 'Your password has been reset. Please log in with your new password.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null)
        ? trim($_POST['email'])
        : '';

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password']
        : '';

    // Validate empty fields
    if ($email === '') {
        $errors['email'] = 'This field is required.';
    }

    if ($password === '') {
        $errors['password'] = 'This field is required.';
    }

    if ($errors) {
        $banner = 'Please fill in both fields.';
    } else {
        try {
            $st = $pdo->prepare(
                'SELECT MemberID, Password, Role
                 FROM member
                 WHERE Email = ?'
            );
            $st->execute([$email]);
            $m = $st->fetch();

            // Always verify the password to reduce email-existence timing leaks
            $hash = $m['Password']
                ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

            $ok = password_verify($password, $hash);

            if (!$m || !$ok) {
                $banner = 'Invalid email or password';
            } else {
                $role = $m['Role'];

                // Companies must be verified by an admin first
                if ($role === 'Company') {
                    $cs = $pdo->prepare(
                        'SELECT Status, RejectionReason
                         FROM company
                         WHERE CompanyID = ?'
                    );
                    $cs->execute([(int)$m['MemberID']]);
                    $c = $cs->fetch();

                    if (!$c) {
                        $banner = 'Invalid email or password';
                    } elseif ($c['Status'] === 'Pending') {
                        $banner = 'Your registration is pending review.';
                    } elseif ($c['Status'] === 'Rejected') {
                        $banner = 'Your registration was rejected: '
                            . ($c['RejectionReason'] ?: 'No reason provided.');
                    }
                }

                if ($banner === '') {
                    // Upgrade password hash if needed
                    if (password_needs_rehash(
                        $m['Password'],
                        PASSWORD_DEFAULT
                    )) {
                        $pdo->prepare(
                            'UPDATE member SET Password = ? WHERE MemberID = ?'
                        )->execute([
                            password_hash($password, PASSWORD_DEFAULT),
                            (int)$m['MemberID']
                        ]);
                    }

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)$m['MemberID'];
                    $_SESSION['role'] = $role;

                    // Get the logged-in admin's name
                    if ($role === 'Admin') {
                        $adminStmt = $pdo->prepare(
                            'SELECT FirstName, LastName
                             FROM admin
                             WHERE AdminID = ?'
                        );

                        $adminStmt->execute([(int)$m['MemberID']]);
                        $admin = $adminStmt->fetch();

                        $_SESSION['admin_name'] = $admin
                            ? $admin['FirstName'] . ' ' . $admin['LastName']
                            : 'Admin';
                    } else {
                        unset($_SESSION['admin_name']);
                    }

                    header('Location: ' . dashboard_for($role));
                    exit;
                }
            }
        } catch (PDOException $ex) {
            $banner = 'Something went wrong. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In &mdash; Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
<?= auth_css() ?>

/* ======================================
   Ufuq Login — subtle motion enhancements
   Original form layout and styling retained
   ====================================== */

/* Soft entrance for the existing login card */
.auth-wrapper {
    animation: loginPageFade .55s ease-out both;
}

.auth-card {
    animation: loginCardEnter .65s cubic-bezier(.2, .7, .25, 1) both;
    transform-origin: center;
}

/* Gentle highlight around the existing form fields */
.auth-card .form-control {
    transition:
        border-color .22s ease,
        box-shadow .22s ease,
        background-color .22s ease;
}

.auth-card .form-control:focus {
    outline: none;
    transform: translateY(-1px);
}

/* Keep invalid-field styling controlled by auth_css() */
.auth-card .form-control.invalid {
    transition: border-color .2s ease, box-shadow .2s ease;
}

/* Subtle button interaction without changing its colors */
.auth-card button[type="submit"] {
    transition:
        transform .2s ease,
        box-shadow .2s ease,
        filter .2s ease;
}

.auth-card button[type="submit"]:hover {
    transform: translateY(-2px);
    filter: brightness(1.035);
    box-shadow: 0 7px 17px rgba(31, 53, 102, .13);
}

.auth-card button[type="submit"]:active {
    transform: translateY(0) scale(.99);
    box-shadow: none;
}

.auth-card button[type="submit"]:focus-visible {
    outline: 3px solid rgba(31, 53, 102, .25);
    outline-offset: 3px;
}

/* Existing links receive a small hover transition */
.auth-card a {
    transition: color .2s ease, opacity .2s ease;
}

.auth-card a:hover {
    opacity: .82;
}

/* Success and error banners appear gently */
.auth-card .form-success,
.auth-card .form-banner {
    animation: loginBannerEnter .3s ease-out both;
}

/* Motion */
@keyframes loginPageFade {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes loginCardEnter {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes loginBannerEnter {
    from {
        opacity: 0;
        transform: translateY(-4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Respect device accessibility preferences */
@media (prefers-reduced-motion: reduce) {
    .auth-wrapper,
    .auth-card,
    .auth-card .form-success,
    .auth-card .form-banner {
        animation: none !important;
    }

    .auth-card .form-control,
    .auth-card button[type="submit"],
    .auth-card a {
        transition: none !important;
    }

    .auth-card .form-control:focus,
    .auth-card button[type="submit"]:hover,
    .auth-card button[type="submit"]:active {
        transform: none;
    }
}
</style>
</head>

<body>

<div id="site-header"></div>

<main>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 980px;">

            <h2 class="text-center">Welcome back</h2>

            <p class="text-center" style="margin-bottom: 24px;">
                Log in to your Ufuq account.
            </p>

            <?php if ($flash): ?>
                <div class="form-success">
                    <?= e($flash) ?>
                </div>
            <?php endif; ?>

            <div
                id="formBanner"
                class="form-banner"
                style="display:<?= $banner ? 'block' : 'none' ?>;"
            ><?= e($banner) ?></div>

            <form id="loginForm" method="post" novalidate>

                <div class="form-group">
                    <label class="form-label icon-label" for="email">
                        <?= icon('email') ?> Email <?= req() ?>
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control<?= isset($errors['email']) ? ' invalid' : '' ?>"
                        value="<?= e($email) ?>"
                        autocomplete="email"
                    >

                    <?= errBox(
                        $errors,
                        'email',
                        'This field is required.',
                        'email'
                    ) ?>
                </div>

                <div class="form-group">
                    <label class="form-label icon-label" for="password">
                        <?= icon('lock') ?> Password <?= req() ?>
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control<?= isset($errors['password']) ? ' invalid' : '' ?>"
                        autocomplete="current-password"
                    >

                    <?= errBox(
                        $errors,
                        'password',
                        'This field is required.',
                        'password'
                    ) ?>

                    <div class="auth-link-row">
                        <a href="forgot-password.php">Forgot password?</a>
                    </div>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-block"
                    style="margin-top:8px;"
                >
                    Log In
                </button>

            </form>

            <p class="text-center" style="margin-top:20px;">
                Don't have an account?
                <a href="signup.php">Sign Up</a>
            </p>

        </div>
    </div>
</main>

<div id="site-footer"></div>

<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
renderHeader({ active: "", base: "../" });
renderFooter({ base: "../" });

const banner = document.getElementById("formBanner");

document.getElementById("loginForm").addEventListener("submit", e => {
    let ok = true;

    ["email", "password"].forEach(id => {
        const el = document.getElementById(id);
        const error = document.getElementById(id + "Error");
        const bad = el.value.trim() === "";

        el.classList.toggle("invalid", bad);

        if (error) {
            error.style.display = bad ? "block" : "none";
        }

        if (bad) {
            ok = false;
        }
    });

    if (!ok) {
        e.preventDefault();
        banner.textContent = "Please fill in both fields.";
        banner.style.display = "block";
    }
});
</script>

<?= header_js('login') ?>

</body>
</html>