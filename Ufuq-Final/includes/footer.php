
<?php
$base = $base ?? '';
?>

<footer class="site-footer">
    <div class="container">

        <div class="footer-grid">

            <!-- Brand -->
            <div>
                <div class="footer-brand">Ufuq</div>

                <p style="color:#A79FB8; font-size:0.88rem;">
                    Centralizing training programs and professional courses
                    for Saudi students and professionals in computing fields.
                </p>
            </div>

            <!-- Platform -->
            <div>
                <h4>Platform</h4>
                <ul>
                    <li>
                        <a href="<?= htmlspecialchars($base) ?>signup.php">
                            For Companies
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($base) ?>index.php">
                            Home
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Account -->
            <div>
                <h4>Account</h4>
                <ul>
                    <li>
                        <a href="<?= htmlspecialchars($base) ?>login.php">
                            Log In
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($base) ?>signup.php">
                            Sign Up
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Support -->
            <div>
                <h4>Support</h4>
                <ul>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Contact Us</a></li>
                    <li><a href="#">Terms &amp; Privacy</a></li>
                </ul>
            </div>

        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <span>
                &copy; 2026 Ufuq &mdash; IT496 Graduation Project
            </span>
        </div>

    </div>
</footer>
