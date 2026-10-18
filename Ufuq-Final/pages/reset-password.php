<?php
session_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/members.php';
require_once __DIR__ . '/../includes/signed_token.php';

no_cache();

$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';

// Is this link genuine, unexpired and unused? (A used link fails because the password hash changed.)
$member   = null;
$memberId = $token !== '' ? token_member_id($token) : null;

if ($memberId !== null) {
    try {
        $candidate = member_find_by_id($pdo, $memberId);
        if ($candidate && token_is_valid($token, $candidate['Password'])) {
            $member = $candidate;
        }
    } catch (Throwable $ex) {
        error_log('Reset link check failed: ' . $ex->getMessage());
    }
}

$errors = [];
$banner = '';

if ($member && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['confirm'] ?? null) ? $_POST['confirm'] : '';

    if ($password === '') {
        $errors['password'] = 'This field is required.';
    } elseif (!is_strong_password($password)) {
        $errors['password'] = PW_MSG;
    }

    if ($confirm === '') {
        $errors['confirm'] = 'This field is required.';
    } elseif ($password !== '' && !isset($errors['password']) && $password !== $confirm) {
        $errors['confirm'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        try {
            $pdo->prepare('UPDATE member SET Password = ? WHERE MemberID = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $member['MemberID']]);

            // The password hash changed, so this link (and any other unused one) is now dead.
            header('Location: login.php?reset=1');
            exit;
        } catch (Throwable $ex) {
            error_log('Reset password failed: ' . $ex->getMessage());
            $banner = 'Something went wrong. Please try again.';
        }
    } else {
        $banner = 'Please fix the highlighted fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password &mdash; Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
<?= auth_css() ?>
</style>
</head>

<body>

<div id="site-header"></div>

<main>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 980px;">

        <?php if (!$member): ?>

            <h2 class="text-center">Link not valid</h2>

            <p class="text-center" style="margin-bottom: 24px;">
                This reset link is invalid, has expired, or was already used.
            </p>

            <a href="forgot-password.php" class="btn btn-primary btn-block">Request a new link</a>

        <?php else: ?>

            <h2 class="text-center">Choose a new password</h2>

            <p class="text-center" style="margin-bottom: 24px;">
                Enter a new password for your Ufuq account.
            </p>

            <?php if ($banner): ?>
                <div class="form-banner" style="display:block;"><?= e($banner) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="t" value="<?= e($token) ?>">

                <div class="form-group">
                    <label class="form-label icon-label" for="password">
                        <?= icon('lock') ?> New password <?= req() ?>
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control<?= isset($errors['password']) ? ' invalid' : '' ?>"
                        autocomplete="new-password"
                    >

                    <?= errBox($errors, 'password', 'This field is required.', 'password') ?>

                    <div class="pw-help"><span class="pw-help-label"><?= e(PW_MSG) ?></span></div>
                </div>

                <div class="form-group">
                    <label class="form-label icon-label" for="confirm">
                        <?= icon('lock') ?> Confirm new password <?= req() ?>
                    </label>

                    <input
                        type="password"
                        id="confirm"
                        name="confirm"
                        class="form-control<?= isset($errors['confirm']) ? ' invalid' : '' ?>"
                        autocomplete="new-password"
                    >

                    <?= errBox($errors, 'confirm', 'This field is required.', 'confirm') ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">
                    Reset password
                </button>
            </form>

        <?php endif; ?>

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
</script>

<?= header_js('login') ?>

</body>
</html>