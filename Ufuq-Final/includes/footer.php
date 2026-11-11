
<?php
$base = $base ?? '';
?>

<style>
/* Footer colours and fonts matched to the rest of Ufuq (hero purple, gold accent, Playfair brand) */
.site-footer.ufuq-footer{
    background:linear-gradient(135deg,#281a46 0%,#39265f 55%,#49316f 100%);
    color:rgba(240,235,248,.78);
    border-top:0 !important;   /* no line between the content and the footer */
    box-shadow:none !important;
}
.site-footer.ufuq-footer .ufuq-footer-brand{
    font-family:'Playfair Display',Georgia,serif;
    font-weight:700;
    color:#fff;
    text-decoration:none;
}
.site-footer.ufuq-footer .ufuq-footer-brand span{color:#e2a94b}
.site-footer.ufuq-footer .ufuq-footer-accent{background:#e2a94b}
.site-footer.ufuq-footer .ufuq-footer-description{color:rgba(240,235,248,.78)}
.site-footer.ufuq-footer .ufuq-footer-label{color:#e2a94b}
.site-footer.ufuq-footer .ufuq-footer-contact a{color:#fff;text-decoration:none;transition:color .2s ease}
.site-footer.ufuq-footer .ufuq-footer-contact a:hover{color:#e2a94b}
.site-footer.ufuq-footer .ufuq-footer-arrow{color:#e2a94b}
.site-footer.ufuq-footer .ufuq-footer-bottom{border-top-color:rgba(240,235,248,.14);color:rgba(240,235,248,.6)}
.site-footer.ufuq-footer .ufuq-footer-tagline{font-family:'Playfair Display',Georgia,serif;font-style:italic;color:rgba(240,235,248,.7)}
</style>

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
