<?php
session_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/signup_save.php';
require_once __DIR__ . '/../includes/email_templates.php';

no_cache();

// "Back to Sign Up": forget the waiting registration (and its uploaded files).
if (isset($_GET['cancel'])) {
    verification_clear(true);
    header('Location: signup.php');
    exit;
}

$view   = '';   // '' = code form | 'ended' | 'taken' | 'company_done'
$banner = '';
$notice = '';
$error  = '';

// A company that just finished: show the "pending admin review" card once.
if (isset($_GET['done']) && !empty($_SESSION['signup_done'])) {
    unset($_SESSION['signup_done']);
    $view = 'company_done';
}

$pending = verification_pending();

if ($view === '' && !$pending) {
    header('Location: signup.php');
    exit;
}

$payload = $pending['payload'] ?? [];
$isUser  = (($payload['type'] ?? '') === 'User');
$name    = $isUser ? (string) ($payload['first'] ?? '') : (string) ($payload['name'] ?? '');
$email   = (string) ($payload['email'] ?? '');

$endedMessage = '';

if ($view === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'resend') {
        $r = verification_resend();

        if ($r['limit']) {
            // Too many new codes: start over (also deletes the uploaded files).
            verification_clear(true);
            $view = 'ended';
            $endedMessage = 'Too many new codes were requested.';
        } elseif ($r['code'] !== null) {
            $mail = email_verification_code($name, $r['code']);
            if (send_mail($email, $mail['subject'], $mail['html'], $mail['text'])) {
                $notice = 'A new code has been sent. The old one no longer works.';
            } else {
                $banner = 'We could not send the email. Please try again.';
            }
        } else {
            $banner = 'Please wait ' . max(1, (int) $r['wait']) . ' seconds before asking for a new code.';
        }
    } else {
        $given  = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        $result = verification_check($given);

        if ($result === 'ok') {
            $id = null;

            try {
                $id = signup_save($pdo, $payload);
            } catch (Throwable $ex) {
                // Was the email (or the company's CR number) taken while the code was waiting?
                $taken = false;
                try {
                    $st = $pdo->prepare('SELECT 1 FROM member WHERE Email = ?');
                    $st->execute([$email]);
                    $taken = (bool) $st->fetchColumn();

                    if (!$taken && !$isUser) {
                        $st = $pdo->prepare('SELECT 1 FROM company WHERE CrNumber = ?');
                        $st->execute([(string) ($payload['cr'] ?? '')]);
                        $taken = (bool) $st->fetchColumn();
                    }
                } catch (Throwable $ignore) {
                    // keep $taken = false
                }

                if ($taken) {
                    verification_clear(true); // also deletes the uploaded files
                    $view = 'taken';
                } else {
                    error_log('Signup save failed: ' . $ex->getMessage());
                    $error = 'Something went wrong. Please try again.';
                }
            }

            if ($id !== null) {
                verification_clear(false); // saved: keep the uploaded files

                if ($isUser) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $id;
                    $_SESSION['role']    = 'User';

                    // Welcome email, sent once (right here, never at login). A failure must not block the new user.
                    try {
                        $home = rtrim((string) env('APP_URL', 'http://localhost:8888'), '/') . '/pages/home.php';
                        $w    = email_welcome($name, $home);
                        send_mail($email, $w['subject'], $w['html'], $w['text']);
                    } catch (Throwable $ex) {
                        error_log('Welcome email failed: ' . $ex->getMessage());
                    }

                    header('Location: home.php');
                    exit;
                }

                // Company: saved as Pending. The admin reviews it, then an approval email is sent.
                $_SESSION['signup_done'] = 1;
                header('Location: verify-code.php?done=1');
                exit;
            }
        } elseif ($result === 'wrong') {
            $error = 'That code is not correct.';
        } elseif ($result === 'format') {
            $error = 'Please enter the 6-digit code from your email.';
        } elseif ($result === 'expired') {
            $error = 'This code has expired. Please request a new one.';
        } elseif ($result === 'locked') {
            $error = 'Too many wrong tries. Please request a new code.';
        }
    }
}

// Hide the middle of the email: ra***@gmail.com
function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return $email;
    }
    return mb_substr($local, 0, 2) . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email &mdash; Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
<?= auth_css() ?>

.code-input {
    text-align: center;
    font-size: 1.8rem;
    letter-spacing: .5rem;
    font-weight: 600;
}
.resend-row { margin-top: 16px; }
</style>
</head>

<body>

<div id="site-header"></div>

<main>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 980px;">

        <?php if ($view === 'company_done'): ?>

            <div class="text-center">
                <h3>Registration Pending</h3>
                <p>Your email is verified and your company account is <strong>pending</strong>.
                   We&rsquo;ll email you once it&rsquo;s approved, and then you can log in.</p>
                <a href="login.php" class="btn btn-outline" style="margin-top:8px;">Back to Log In</a>
            </div>

        <?php elseif ($view === 'ended'): ?>

            <div class="text-center">
                <h3>Please register again</h3>
                <p><?= e($endedMessage) ?> For your security, your sign-up was cancelled.</p>
                <a href="signup.php" class="btn btn-primary" style="margin-top:8px;">Back to Sign Up</a>
            </div>

        <?php elseif ($view === 'taken'): ?>

            <div class="text-center">
                <h3>Already registered</h3>
                <p>This account was just registered. Please log in instead.</p>
                <a href="login.php" class="btn btn-primary" style="margin-top:8px;">Log In</a>
            </div>

        <?php else: ?>

            <h2 class="text-center">Verify your email</h2>

            <p class="text-center" style="margin-bottom: 24px;">
                We sent a 6-digit code to <strong><?= e(mask_email($email)) ?></strong>.
                It expires in 10 minutes. Please check your spam folder too.
            </p>

            <?php if ($notice): ?>
                <div class="form-success"><?= e($notice) ?></div>
            <?php endif; ?>

            <?php if ($banner): ?>
                <div class="form-banner" style="display:block;"><?= e($banner) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify">

                <div class="form-group">
                    <label class="form-label" for="code">Verification code <?= req() ?></label>

                    <input
                        type="text"
                        id="code"
                        name="code"
                        class="form-control code-input<?= $error ? ' invalid' : '' ?>"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        autofocus
                    >

                    <?php if ($error): ?>
                        <div class="form-error" style="display:block;"><?= e($error) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">Verify</button>
            </form>

            <form method="post" class="resend-row text-center">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="resend">
                Didn&rsquo;t get it? <button type="submit" class="btn btn-outline" style="padding:6px 14px;">Send a new code</button>
            </form>

            <p class="text-center" style="margin-top:20px;">
                <a href="verify-code.php?cancel=1">Back to Sign Up</a>
            </p>

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

// digits only in the code box
var codeBox = document.getElementById("code");
if (codeBox) {
    codeBox.addEventListener("input", function () {
        codeBox.value = codeBox.value.replace(/\D/g, "").slice(0, 6);
    });
}
</script>

<?= header_js('signup') ?>

</body>
</html>