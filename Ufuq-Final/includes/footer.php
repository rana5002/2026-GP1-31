
<?php
$base = $base ?? '';
?>

<style>
/* Footer: blends the banner purple (#281a46 / #39265f) into the button plum (#44384D); gold accent, Playfair brand */
.site-footer.ufuq-footer{
    background:linear-gradient(135deg,#2b1f45 0%,#3a2b55 30%,#44384d 70%,#44384d 100%);
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
/* Copyright in the middle, tagline stays on the right */
.site-footer.ufuq-footer .ufuq-footer-bottom{display:grid !important;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px}
.site-footer.ufuq-footer .ufuq-footer-copy{grid-column:2;text-align:center}
.site-footer.ufuq-footer .ufuq-footer-tagline{grid-column:3;justify-self:end;text-align:right}
@media (max-width:620px){
    .site-footer.ufuq-footer .ufuq-footer-bottom{grid-template-columns:1fr}
    .site-footer.ufuq-footer .ufuq-footer-copy,
    .site-footer.ufuq-footer .ufuq-footer-tagline{grid-column:1;justify-self:center;text-align:center}
}
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
            <span class="ufuq-footer-copy">© 2026 Ufuq. All rights reserved.</span>
            <span class="ufuq-footer-tagline">Made for your next step.</span>
        </div>

    </div>
</footer>