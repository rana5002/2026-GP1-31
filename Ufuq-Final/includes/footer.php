
<?php
$base = $base ?? '';
?>

<footer class="site-footer ufuq-footer">
    <div class="container">

        <div class="ufuq-footer-main">
            <div class="ufuq-footer-about">
                <a
                    class="ufuq-footer-brand"
                    href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>index.php"
                >
                    Ufuq<span>.</span>
                </a>

                <div class="ufuq-footer-accent"></div>

                <p class="ufuq-footer-description">
                    Find your next opportunity.<br>
                    Build your future with Ufuq.
                </p>
            </div>

            <div class="ufuq-footer-contact">
                <span class="ufuq-footer-label">LET'S CONNECT</span>

                <a
                    href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=ufuq.platform.team@gmail.com"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    ufuq.platform.team@gmail.com
                    <span class="ufuq-footer-arrow" aria-hidden="true">↗</span>
                </a>
            </div>
        </div>

        <div class="ufuq-footer-bottom">
            <span>© 2026 Ufuq. All rights reserved.</span>
            <span class="ufuq-footer-tagline">Made for your next step.</span>
        </div>

    </div>
</footer>
