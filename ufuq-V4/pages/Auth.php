<?php
/**
 * auth.php — shared helpers for login / logout / forgot / reset pages
 * and the guard used by every protected page.
 *
 * Protect a page (first lines of home.php, dashboard-company.php, dashboard-admin.php, ...):
 *     require __DIR__ . '/auth.php';
 *     require_login('User');      // or 'Company' / 'Admin'
 */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function req(): string { return '<span class="req">*</span>'; }

function icon(string $n): string {
    $u = "../images-website/icons/$n.png";
    return '<span class="icon" style="-webkit-mask-image:url(\'' . $u . '\');mask-image:url(\'' . $u . '\');"></span>';
}

function errBox(array $errs, string $id, string $default, string $key): string {
    $show = isset($errs[$key]) ? 'block' : 'none';
    $msg  = $errs[$key] ?? $default;
    return '<div class="form-error" id="' . $id . 'Error" data-msg="' . e($default) . '" style="display:' . $show . ';">' . e($msg) . '</div>';
}

/** Stops the browser from caching the page (so the Back button can't show a protected page after logout). */
function no_cache(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function dashboard_for(string $role): string {
    $map = ['User' => 'home.php', 'Company' => 'dashboard-company.php', 'Admin' => 'dashboard-admin.php'];
    return $map[$role] ?? 'login.php';
}

/** Guard for protected pages. */
function require_login(?string $role = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    no_cache();
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }
    if ($role !== null && $_SESSION['role'] !== $role) {
        header('Location: ' . dashboard_for((string)$_SESSION['role']));
        exit;
    }
}

function is_strong_password(string $p): bool {
    return strlen($p) >= 8
        && preg_match('/[a-z]/', $p)
        && preg_match('/[A-Z]/', $p)
        && preg_match('/\d/', $p)
        && preg_match('/[^A-Za-z0-9]/', $p);
}

const PW_MSG = 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.';

/** "!" button + bullet list of password rules. */
function pwHelp(string $id): string {
    $items = ['len' => 'At least 8 characters', 'upper' => 'One uppercase letter (A-Z)', 'lower' => 'One lowercase letter (a-z)',
              'num' => 'One number (0-9)', 'special' => 'One special character (! @ # $ % ...)'];
    $li = '';
    foreach ($items as $rule => $text) $li .= '<li data-rule="' . $rule . '">' . $text . '</li>';
    return '<div class="pw-help"><button type="button" class="pw-help-btn" data-target="' . $id . 'Rules" aria-expanded="false" aria-label="Show password requirements">!</button>'
         . '<span class="pw-help-label">Password requirements</span></div>'
         . '<ul class="pw-rules is-hidden" id="' . $id . 'Rules" data-for="' . $id . '">' . $li . '</ul>';
}

/** Shared CSS (soft red border only on invalid fields, banners, password help, pressed header button). */
function auth_css(): string {
    return <<<'CSS'
:root { --err: #e5646a; --err-text: #cf4a50; }
.req { color: var(--err); font-weight: 700; margin-left: 2px; }
.form-error { color: var(--err-text); font-weight: 500; font-size: .85rem; margin-top: 6px; }
.form-control.invalid { border: 1.5px solid var(--err) !important; }
.form-banner {
  background: #fdf1f1; border: 1px solid #f1c0c2; color: var(--err-text);
  font-weight: 500; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px;
}
.form-success {
  background: #edf7ef; border: 1px solid #b7dfc0; color: #1a7f37;
  font-weight: 500; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px;
}
.auth-link-row { text-align: right; margin-top: 8px; font-size: .9rem; }
.pw-help { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.pw-help-btn {
  width: 22px; height: 22px; padding: 0; border-radius: 50%; cursor: pointer;
  border: 1.5px solid #9ca3af; background: #fff; color: #6b7280;
  font-weight: 700; font-size: .8rem; line-height: 1;
}
.pw-help-btn:hover, .pw-help-btn[aria-expanded="true"] { border-color: var(--color-primary); color: var(--color-primary); }
.pw-help-label { font-size: .8rem; color: #6b7280; }
.pw-rules { margin: 8px 0 0; padding-left: 22px; list-style: disc; font-size: .82rem; color: #6b7280; }
.pw-rules.is-hidden { display: none; }
.pw-rules li.ok { color: #1a7f37; }
#site-header a.is-pressed {
  transform: translateY(1px);
  box-shadow: inset 0 3px 8px rgba(0, 0, 0, .28) !important;
  filter: brightness(.92);
}
CSS;
}

/** Header tweak: no underline on any nav item, and the link whose href contains $match looks pressed. Output AFTER renderHeader(). */
function header_js(string $match): string {
    $m = json_encode($match);
    return <<<JS
<script>
(function () {
  var header = document.getElementById("site-header");
  if (!header) return;
  header.querySelectorAll(".active, [aria-current]").forEach(function (el) {
    el.classList.remove("active"); el.removeAttribute("aria-current");
  });
  header.querySelectorAll("a").forEach(function (a) {
    if ((a.getAttribute("href") || "").toLowerCase().indexOf($m) !== -1) {
      a.classList.add("is-pressed"); a.setAttribute("aria-current", "page");
    }
  });
})();
</script>
JS;
}