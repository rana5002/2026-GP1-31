<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
no_cache();

// already logged in -> go to own dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    header('Location: ' . dashboard_for((string)$_SESSION['role']));
    exit;
}

$errors = [];
$banner = '';
$email  = '';
$flash  = isset($_GET['reset']) ? 'Your password has been reset. Please log in with your new password.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    // empty fields -> validation error
    if ($email === '')    $errors['email']    = 'This field is required.';
    if ($password === '') $errors['password'] = 'This field is required.';

    if ($errors) {
        $banner = 'Please fill in both fields.';
    } else {
        try {
            $st = $pdo->prepare('SELECT MemberID, Password, Role FROM member WHERE Email = ?');
            $st->execute([$email]);
            $m = $st->fetch();

            // always run password_verify so the response time doesn't reveal whether the email exists
            $hash = $m['Password'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
            $ok   = password_verify($password, $hash);

            if (!$m || !$ok) {
                $banner = 'Invalid email or password';
            } else {
                $role = $m['Role'];

                // companies must be verified by an admin first
                if ($role === 'Company') {
                    $cs = $pdo->prepare('SELECT Status, RejectionReason FROM company WHERE CompanyID = ?');
                    $cs->execute([$m['MemberID']]);
                    $c = $cs->fetch();
                    if (!$c) {
                        $banner = 'Invalid email or password';
                    } elseif ($c['Status'] === 'Pending') {
                        $banner = 'Your registration is pending review.';
                    } elseif ($c['Status'] === 'Rejected') {
                        $banner = 'Your registration was rejected: ' . ($c['RejectionReason'] ?: 'No reason provided.');
                    }
                }

                if ($banner === '') {
                    // upgrade the hash if PHP's default algorithm/cost changed
                    if (password_needs_rehash($m['Password'], PASSWORD_DEFAULT)) {
                        $pdo->prepare('UPDATE member SET Password = ? WHERE MemberID = ?')
                            ->execute([password_hash($password, PASSWORD_DEFAULT), $m['MemberID']]);
                    }
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$m['MemberID'];
                    $_SESSION['role']    = $role;
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
<style><?= auth_css() ?></style>
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="auth-wrapper">
    <div class="auth-card" style="max-width: 980px;">
      <h2 class="text-center">Welcome back</h2>
      <p class="text-center" style="margin-bottom: 24px;">Log in to your Ufuq account.</p>

      <?php if ($flash): ?>
        <div class="form-success"><?= e($flash) ?></div>
      <?php endif; ?>
      <div id="formBanner" class="form-banner" style="display:<?= $banner ? 'block' : 'none' ?>;"><?= e($banner) ?></div>

      <form id="loginForm" method="post" novalidate>
        <div class="form-group">
          <label class="form-label icon-label" for="email"><?= icon('email') ?> Email <?= req() ?></label>
          <input type="email" id="email" name="email" class="form-control<?= isset($errors['email']) ? ' invalid' : '' ?>" value="<?= e($email) ?>" autocomplete="email">
          <?= errBox($errors, 'email', 'This field is required.', 'email') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="password"><?= icon('lock') ?> Password <?= req() ?></label>
          <input type="password" id="password" name="password" class="form-control<?= isset($errors['password']) ? ' invalid' : '' ?>" autocomplete="current-password">
          <?= errBox($errors, 'password', 'This field is required.', 'password') ?>
          <div class="auth-link-row"><a href="forgot-password.php">Forgot password?</a></div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">Log In</button>
      </form>

      <p class="text-center" style="margin-top:20px;">
        Don't have an account? <a href="signup.php">Sign Up</a>
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
      const bad = el.value.trim() === "";
      el.classList.toggle("invalid", bad);
      document.getElementById(id + "Error").style.display = bad ? "block" : "none";
      if (bad) ok = false;
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