<?php
// Email templates for Ufuq (colours match css/base.css and css/layout.css).
// Each function returns ['subject' => ..., 'html' => ..., 'text' => ...]
// Use with: $t = email_verification_code('Rana', '123456');
//           send_mail($to, $t['subject'], $t['html'], $t['text']);

function email_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// The logo can only load in an email from a public address, so it is skipped on localhost.
function email_logo_html(): string
{
    $base = function_exists('env') ? rtrim((string) env('APP_URL', ''), '/') : '';

    if ($base === '' || stripos($base, 'localhost') !== false || strpos($base, '127.0.0.1') !== false) {
        return '';
    }

    $src = email_h($base . '/images-website/logo.png');
    return '<img src="' . $src . '" alt="Ufuq" width="56" style="display:block;margin:0 auto 8px;border:0;">';
}

// Shared wrapper: header, white card, footer. $content is trusted HTML built below.
function email_layout(string $title, string $content): string
{
    $title = email_h($title);
    $logo  = email_logo_html();

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#FAF6EF;font-family:'DM Sans','Segoe UI',Arial,Helvetica,sans-serif;color:#44384D;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#FAF6EF;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
        <tr>
          <td align="center" style="padding:8px 0 20px;">
            {$logo}
            <span style="font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:28px;font-weight:bold;color:#6A5B71;">Ufuq</span>
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#8E789F;">&nbsp;&nbsp;أُفق</span>
          </td>
        </tr>
        <tr>
          <td style="background-color:#FFFFFF;border-radius:12px;padding:32px 28px;border:1px solid #E8DFE9;">
            {$content}
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:20px 8px 0;font-size:12px;line-height:18px;color:#9C8FA8;">
            Ufuq &mdash; training and course opportunities for IT students in Saudi Arabia.<br>
            This is an automated message, please do not share its contents with anyone.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

// Small pieces reused by the templates
function email_heading(string $text): string
{
    return '<h2 style="margin:0 0 16px;font-family:\'Playfair Display\',Georgia,\'Times New Roman\',serif;font-size:22px;color:#44384D;">'
         . email_h($text) . '</h2>';
}

function email_button(string $label, string $url): string
{
    return '<p style="margin:0 0 24px;text-align:center;">'
         . '<a href="' . email_h($url) . '" style="display:inline-block;padding:12px 28px;background-color:#44384D;color:#FFFFFF;'
         . 'text-decoration:none;font-size:15px;font-weight:bold;border-radius:10px;">' . email_h($label) . '</a></p>';
}

// 1) Verification code (registration, valid 10 minutes) — users and companies
function email_verification_code(string $name, string $code): array
{
    $n = email_h($name);
    $c = email_h($code);

    $content = email_heading('Verify your email') . <<<HTML
<p style="margin:0 0 16px;font-size:15px;line-height:24px;">Hi {$n},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:24px;">Use the code below to finish creating your Ufuq account.</p>
<p style="margin:0 0 20px;text-align:center;">
  <span style="display:inline-block;padding:14px 28px;font-family:'Courier New',monospace;font-size:32px;letter-spacing:8px;font-weight:bold;color:#6A5B71;background-color:#EEE7F1;border-radius:10px;">{$c}</span>
</p>
<p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#6A5B71;">This code expires in <strong>10 minutes</strong>. If you request a new code, this one stops working.</p>
<p style="margin:0;font-size:14px;line-height:22px;color:#6A5B71;">If you didn&rsquo;t try to sign up, you can safely ignore this email.</p>
HTML;

    $text = "Hi {$name},\n\nYour Ufuq verification code is: {$code}\n\n"
          . "It expires in 10 minutes. If you request a new code, this one stops working.\n"
          . "If you didn't try to sign up, ignore this email.\n";

    return [
        'subject' => 'Your Ufuq verification code',
        'html'    => email_layout('Verify your email', $content),
        'text'    => $text,
    ];
}

// 2) Welcome for a user (sent once, right after their email is verified)
function email_welcome(string $name, string $siteUrl): array
{
    $n = email_h($name);

    $content = email_heading('Welcome to Ufuq') . <<<HTML
<p style="margin:0 0 16px;font-size:15px;line-height:24px;">Hi {$n},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:24px;">Your email is verified and your account is ready. Browse training and course opportunities, save your favourites, and see which skills you still need for the roles you want.</p>
HTML
    . email_button('Start exploring', $siteUrl)
    . '<p style="margin:0;font-size:14px;line-height:22px;color:#6A5B71;">Good luck with your journey!</p>';

    $text = "Hi {$name},\n\nWelcome to Ufuq! Your email is verified and your account is ready.\n"
          . "Start exploring: {$siteUrl}\n";

    return [
        'subject' => 'Welcome to Ufuq',
        'html'    => email_layout('Welcome to Ufuq', $content),
        'text'    => $text,
    ];
}

// 3) Company approved by an admin
function email_company_approved(string $companyName, string $loginUrl): array
{
    $n = email_h($companyName);

    $content = email_heading('Your company is verified')
    . '<p style="margin:0 0 16px;text-align:center;"><span style="display:inline-block;padding:6px 16px;background-color:#E6EFEA;color:#557D68;font-size:13px;font-weight:bold;border-radius:999px;">&#10003; Verified</span></p>'
    . <<<HTML
<p style="margin:0 0 16px;font-size:15px;line-height:24px;">Hello {$n},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:24px;">Good news &mdash; the Ufuq team has reviewed and verified your company registration. You can now log in and start posting training and course opportunities for students.</p>
HTML
    . email_button('Log in to your dashboard', $loginUrl)
    . '<p style="margin:0;font-size:14px;line-height:22px;color:#6A5B71;">Thank you for partnering with Ufuq.</p>';

    $text = "Hello {$companyName},\n\nGood news - the Ufuq team has verified your company registration.\n"
          . "You can now log in and post opportunities: {$loginUrl}\n";

    return [
        'subject' => 'Your company has been verified on Ufuq',
        'html'    => email_layout('Your company is verified', $content),
        'text'    => $text,
    ];
}

// 4) Password reset (link valid 10 minutes, single use)
function email_password_reset(string $name, string $resetUrl): array
{
    $n = email_h($name);
    $u = email_h($resetUrl);

    $content = email_heading('Reset your password') . <<<HTML
<p style="margin:0 0 16px;font-size:15px;line-height:24px;">Hi {$n},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:24px;">We received a request to reset your Ufuq password. Click the button below to choose a new one.</p>
HTML
    . email_button('Reset password', $resetUrl)
    . <<<HTML
<p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#6A5B71;">This link expires in <strong>10 minutes</strong> and can be used only once.</p>
<p style="margin:0 0 12px;font-size:13px;line-height:20px;color:#9C8FA8;">If the button doesn&rsquo;t work, copy this address into your browser:<br><span style="word-break:break-all;">{$u}</span></p>
<p style="margin:0;font-size:14px;line-height:22px;color:#6A5B71;">If you didn&rsquo;t ask for this, you can safely ignore this email &mdash; your password won&rsquo;t change.</p>
HTML;

    $text = "Hi {$name},\n\nWe received a request to reset your Ufuq password.\n"
          . "Open this link to choose a new one (valid for 10 minutes, single use):\n{$resetUrl}\n\n"
          . "If you didn't ask for this, ignore this email.\n";

    return [
        'subject' => 'Reset your Ufuq password',
        'html'    => email_layout('Reset your password', $content),
        'text'    => $text,
    ];
}