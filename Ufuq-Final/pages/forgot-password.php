<?php
session_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/members.php';
require_once __DIR__ . '/../includes/signed_token.php';
require_once __DIR__ . '/../includes/email_templates.php';

no_cache();

// Already logged in -> nothing to reset, go to own dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    header('Location: ' . dashboard_for((string) $_SESSION['role']));
    exit;
}

$errors = [];
$banner = '';
$sent   = false;
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

    if ($email === '') {
        $errors['email'] = 'This field is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        try {
            $member = member_find_by_email($pdo, $email);

            if ($member) {
                $token = token_create((int) $member['MemberID'], $member['Password']);
                $link  = rtrim((string) env('APP_URL', 'http://localhost:8888'), '/')
                       . '/pages/reset-password.php?t=' . urlencode($token);

                $mail = email_password_reset(member_display_name($pdo, $member), $link);
                send_mail($member['Email'], $mail['subject'], $mail['html'], $mail['text']);
            }

            // Same answer whether or not the email exists, so nobody can probe which emails have accounts.
            $sent = true;
        } catch (Throwable $ex) {
            error_log('Forgot password failed: ' . $ex->getMessage());
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
<title>Forgot Password &mdash; Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
<?= auth_css() ?>
body { background: var(--color-cream); }   /* beige page background*/
</style>
</head>

<body>

?>
<?php
$headerBase = '../';
$headerActive = '';
require __DIR__ . '/../includes/header.php';
?>
<main>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 980px;">

            <h2 class="text-center">Forgot your password?</h2>

            <p class="text-center" style="margin-bottom: 24px;">
                Enter your email and we&rsquo;ll send you a link to reset it.
            </p>

            <?php if ($sent): ?>
                <div class="form-success">
                    If an account exists for that email, we&rsquo;ve sent a password reset link.
                    It expires in 10 minutes. Please check your spam folder too.
                </div>
            <?php else: ?>

                <?php if ($banner): ?>
                    <div class="form-banner" style="display:block;"><?= e($banner) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

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

                        <?= errBox($errors, 'email', 'This field is required.', 'email') ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
                        Send reset link
                    </button>
                </form>

            <?php endif; ?>

            <p class="text-center" style="margin-top:20px;">
                <a href="login.php">Back to Log In</a>
            </p>

        </div>
    </div>
</main>


<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
</script>

<?= header_js('login') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>



</body>
</html>