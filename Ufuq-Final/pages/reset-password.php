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

.pw-wrap { position: relative; }
.pw-wrap > input { padding-right: 44px; }
.pw-eye {
  position: absolute; top: 0; right: 0; height: 100%; width: 44px;
  display: flex; align-items: center; justify-content: center;
  background: none; border: 0; cursor: pointer; color: var(--color-text-faint);
}
.pw-eye:hover, .pw-eye:focus-visible { color: var(--color-primary-dark); outline: none; }
.pw-eye svg { width: 20px; height: 20px; }
.pw-eye .eye-off { display: none; }
.pw-eye.shown .eye-on { display: none; }
.pw-eye.shown .eye-off { display: block; }

</style>
</head>

<body>

<?php
$headerBase = '../';
$headerActive = '';
require __DIR__ . '/../includes/header.php';
?>
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


<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>

    
(function () {
  "use strict";
  var EYE = '<svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>' +
            '<svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.9 10.9 0 0 1 12 19c-7 0-11-7-11-7a19.8 19.8 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 5c7 0 11 7 11 7a19.7 19.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrap = document.createElement("div");
    wrap.className = "pw-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "pw-eye";
    btn.setAttribute("aria-label", "Show password");
    btn.innerHTML = EYE;
    btn.addEventListener("click", function () {
      var show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.classList.toggle("shown", show);
      btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
    });
    wrap.appendChild(btn);
  });
})();

</script>



<?= header_js('login') ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>