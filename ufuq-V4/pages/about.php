<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$bootstrapPath = __DIR__ . '/bootstrap.php';

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
}

/* Redirect signed-in members to their own pages. */
if (!empty($_SESSION['member_id']) || !empty($_SESSION['user_id'])) {
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    $destinations = [
        'user'    => 'home.php',
        'company' => 'dashboard-company.php',
        'admin'   => 'dashboard-admin.php',
    ];

    if (isset($destinations[$role])) {
        header('Location: ' . $destinations[$role]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>About Us — Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
/* ==========================================
   UFUQ ABOUT US
   Purple hero + existing Ufuq theme
   ========================================== */

html {
    scroll-behavior: smooth;
}

.about-page {
    overflow-x: hidden;
}

.about-page section {
    position: relative;
}

/* ---------- HERO ---------- */

.about-hero {
    position: relative;
    overflow: hidden;
    padding: 75px 0 60px;

    /* Original purple-style hero */
    background: linear-gradient(
        135deg,
        #f1edfa 0%,
        #e8e0f6 50%,
        #f5f1fb 100%
    );
}

.about-hero-content {
    position: relative;
    z-index: 2;
    max-width: 850px;
    margin: 0 auto;
    text-align: center;
    animation: aboutEntrance .9s ease both;
}

.about-hero-content .hero-eyebrow {
    justify-content: center;
    margin-bottom: 20px;
}

.about-hero-content h1 {
    max-width: 800px;
    margin: 0 auto 22px;
    line-height: 1.2;
    letter-spacing: -.7px;
    color: #26365f;
}

.about-hero-content h1 .highlight {
    position: relative;
    display: inline-block;
    color: #63458f;
    white-space: nowrap;
}

.about-hero-content h1 .highlight::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -5px;
    height: 5px;
    border-radius: 20px;
    background: #b49ad9;
    transform: scaleX(0);
    transform-origin: left;
    animation: aboutUnderline .75s ease .65s forwards;
}

.about-hero-content > p {
    max-width: 680px;
    margin: 0 auto;
    color: #515a70;
    font-size: 1.06rem;
    line-height: 1.9;
}

.about-hero-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 13px;
    margin-top: 30px;
}

.about-hero-actions .btn {
    transition: transform .25s ease, box-shadow .25s ease;
}

.about-hero-actions .btn:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px rgba(31, 53, 102, .14);
}

/* Purple decorative elements */

.about-orb {
    position: absolute;
    z-index: 0;
    border-radius: 50%;
    pointer-events: none;
}

.about-orb-one {
    width: 260px;
    height: 260px;
    top: 8px;
    left: -115px;
    border: 1px solid rgba(99, 69, 143, .16);
    box-shadow:
        0 0 0 28px rgba(99, 69, 143, .035),
        0 0 0 58px rgba(99, 69, 143, .02);
    animation: aboutOrbFloat 9s ease-in-out infinite;
}

.about-orb-two {
    width: 150px;
    height: 150px;
    right: -55px;
    bottom: 10px;
    background: rgba(142, 104, 195, .13);
    animation: aboutOrbFloat 7s ease-in-out infinite reverse;
}

.about-orb-three {
    width: 13px;
    height: 13px;
    top: 23%;
    right: 17%;
    background: #a78bce;
    animation: aboutPulse 2.8s ease-in-out infinite;
}

.about-scroll-hint {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 9px;
    margin-top: 38px;
    color: #63458f;
    font-size: .8rem;
    opacity: .8;
    letter-spacing: .7px;
}

.about-scroll-line {
    width: 32px;
    height: 1px;
    background: currentColor;
}

/* ---------- SECTION HEADINGS ---------- */

.about-section-heading {
    max-width: 690px;
    margin: 0 auto 38px;
    text-align: center;
}

.about-section-heading h2 {
    margin-bottom: 12px;
}

.about-section-heading p {
    margin: 0 auto;
    line-height: 1.8;
    opacity: .8;
}

.about-mini-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    color: var(--color-primary, #1f3566);
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.6px;
}

.about-mini-label::before {
    content: "";
    width: 20px;
    height: 2px;
    background: var(--color-secondary, #e2a94b);
}

/* ---------- MISSION CARDS ---------- */

.about-cards {
    align-items: stretch;
}

.about-cards .card {
    position: relative;
    height: 100%;
    overflow: hidden;
    padding: 30px 24px;
    border: 1px solid rgba(31, 53, 102, .09);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 4px 18px rgba(31, 53, 102, .035);

    opacity: 0;
    transform: translateY(26px);

    transition:
        opacity .65s ease,
        transform .65s ease,
        box-shadow .3s ease,
        border-color .3s ease;
}

.about-cards .card.is-visible {
    opacity: 1;
    transform: translateY(0);
}

.about-cards .card:nth-child(2) {
    transition-delay: .12s;
}

.about-cards .card:nth-child(3) {
    transition-delay: .24s;
}

.about-cards .card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: #b49ad9;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform .35s ease;
}

.about-cards .card:hover::before {
    transform: scaleX(1);
}

.about-cards .card:hover {
    transform: translateY(-8px);
    border-color: rgba(99, 69, 143, .22);
    box-shadow: 0 16px 36px rgba(31, 53, 102, .10);
}

.about-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 66px;
    height: 66px;
    margin: 0 auto 20px;
    border-radius: 18px;
    background: rgba(99, 69, 143, .08);
    transition:
        background .3s ease,
        transform .35s ease,
        border-radius .35s ease;
}

.about-cards .card:hover .about-icon-wrap {
    background: rgba(180, 154, 217, .22);
    transform: translateY(-3px) rotate(-3deg);
    border-radius: 22px;
}

.about-icon-wrap .icon {
    width: 31px;
    height: 31px;
    transition: transform .35s ease;
}

.about-cards .card:hover .about-icon-wrap .icon {
    transform: scale(1.12);
}

.about-cards .card h3 {
    margin-bottom: 12px;
}

.about-cards .card p {
    margin: 0;
    line-height: 1.85;
    opacity: .82;
}

/* ---------- WHY UFUQ ---------- */

.about-why-section {
    overflow: hidden;
}

.about-why-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: center;
    gap: 55px;
}

.about-why-copy {
    max-width: 550px;
}

.about-why-copy h2 {
    margin-bottom: 18px;
}

.about-why-copy > p {
    line-height: 1.9;
    opacity: .84;
}

.about-why-points {
    display: grid;
    gap: 16px;
    margin-top: 25px;
}

.about-why-point {
    display: flex;
    align-items: flex-start;
    gap: 13px;
}

.about-check {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 27px;
    height: 27px;
    border-radius: 9px;
    background: rgba(99, 69, 143, .09);
    color: #63458f;
    font-weight: 800;
    font-size: .9rem;
}

.about-why-point strong {
    display: block;
    margin-bottom: 3px;
}

.about-why-point p {
    margin: 0;
    font-size: .93rem;
    line-height: 1.7;
    opacity: .78;
}

/* Ufuq-inspired doorway illustration */

.about-horizon-visual {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 320px;
    overflow: hidden;
    border-radius: 24px;
    border: 1px solid rgba(99, 69, 143, .1);
    background: linear-gradient(
        150deg,
        #f0eafb,
        #e5dcf5 55%,
        #f7f2fc
    );
}

.about-horizon-sun {
    position: absolute;
    top: 58px;
    width: 112px;
    height: 112px;
    border-radius: 50%;
    background: linear-gradient(145deg, #f3d99f, #e2a94b);
    box-shadow: 0 0 0 16px rgba(226, 169, 75, .09);
    animation: aboutSunGlow 4s ease-in-out infinite;
}

.about-horizon-line {
    position: absolute;
    right: -10%;
    bottom: 70px;
    left: -10%;
    height: 115px;
    border-top: 2px solid rgba(99, 69, 143, .28);
    border-radius: 50% 50% 0 0;
    background: rgba(99, 69, 143, .06);
    transform: rotate(-5deg);
}

.about-horizon-line-two {
    bottom: 28px;
    height: 105px;
    background: rgba(99, 69, 143, .09);
    transform: rotate(5deg);
}

.about-door {
    position: absolute;
    z-index: 2;
    bottom: 44px;
    left: 50%;
    width: 104px;
    height: 147px;
    padding: 8px;
    border: 3px solid #63458f;
    border-bottom: 0;
    border-radius: 55px 55px 0 0;
    transform: translateX(-50%);
    background: rgba(255, 255, 255, .32);
    box-shadow: 0 0 0 10px rgba(99, 69, 143, .045);
    animation: aboutDoorGlow 4s ease-in-out infinite;
}

.about-door-inner {
    position: relative;
    width: 100%;
    height: 100%;
    border: 2px solid rgba(99, 69, 143, .48);
    border-bottom: 0;
    border-radius: 45px 45px 0 0;
}

.about-door-knob {
    position: absolute;
    right: 13px;
    bottom: 36px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #e2a94b;
}

.about-horizon-caption {
    position: absolute;
    z-index: 3;
    right: 15px;
    bottom: 14px;
    left: 15px;
    color: #63458f;
    text-align: center;
    font-size: .78rem;
    font-weight: 600;
    letter-spacing: .7px;
}

/* ---------- PROJECT SECTION ---------- */

.about-project {
    overflow: hidden;
}

.about-project-box {
    position: relative;
    overflow: hidden;
    padding: 44px 30px;
    border: 1px solid rgba(31, 53, 102, .10);
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 8px 28px rgba(31, 53, 102, .05);
}

.about-project-box::after {
    content: "";
    position: absolute;
    top: -90px;
    right: -75px;
    width: 210px;
    height: 210px;
    border: 1px solid rgba(180, 154, 217, .55);
    border-radius: 50%;
    box-shadow:
        0 0 0 18px rgba(180, 154, 217, .08),
        0 0 0 38px rgba(180, 154, 217, .04);
    pointer-events: none;
}

.about-project-content {
    position: relative;
    z-index: 1;
    max-width: 720px;
    margin: 0 auto;
    text-align: center;
}

.about-project-content h2 {
    margin-bottom: 13px;
}

.about-project-content > p {
    max-width: 630px;
    margin: 0 auto 25px;
    line-height: 1.9;
    opacity: .82;
}

.about-project-tags {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 9px;
    margin-bottom: 27px;
}

.about-project-tag {
    display: inline-flex;
    align-items: center;
    padding: 8px 13px;
    border: 1px solid rgba(99, 69, 143, .14);
    border-radius: 999px;
    background: rgba(99, 69, 143, .05);
    color: #63458f;
    font-size: .82rem;
    font-weight: 600;
    transition: transform .22s ease, background .22s ease;
}

.about-project-tag:hover {
    transform: translateY(-3px);
    background: rgba(180, 154, 217, .2);
}

.about-project-content .btn {
    transition: transform .25s ease, box-shadow .25s ease;
}

.about-project-content .btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(31, 53, 102, .15);
}

/* ---------- SCROLL REVEAL ---------- */

.about-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity .7s ease, transform .7s ease;
}

.about-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

.about-reveal-left {
    opacity: 0;
    transform: translateX(-24px);
    transition: opacity .7s ease, transform .7s ease;
}

.about-reveal-left.is-visible {
    opacity: 1;
    transform: translateX(0);
}

.about-reveal-right {
    opacity: 0;
    transform: translateX(24px);
    transition: opacity .7s ease, transform .7s ease;
}

.about-reveal-right.is-visible {
    opacity: 1;
    transform: translateX(0);
}

/* ---------- ANIMATIONS ---------- */

@keyframes aboutEntrance {
    from {
        opacity: 0;
        transform: translateY(25px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes aboutUnderline {
    to {
        transform: scaleX(1);
    }
}

@keyframes aboutOrbFloat {
    0%, 100% {
        transform: translateY(0) rotate(0deg);
    }
    50% {
        transform: translateY(-15px) rotate(5deg);
    }
}

@keyframes aboutPulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(180, 154, 217, .3);
    }
    50% {
        transform: scale(1.15);
        box-shadow: 0 0 0 10px rgba(180, 154, 217, 0);
    }
}

@keyframes aboutSunGlow {
    0%, 100% {
        box-shadow: 0 0 0 16px rgba(226, 169, 75, .09);
    }
    50% {
        box-shadow: 0 0 0 24px rgba(226, 169, 75, .045);
    }
}

@keyframes aboutDoorGlow {
    0%, 100% {
        box-shadow: 0 0 0 10px rgba(99, 69, 143, .045);
    }
    50% {
        box-shadow: 0 0 0 17px rgba(99, 69, 143, .025);
    }
}

/* ---------- RESPONSIVE ---------- */

@media (max-width: 800px) {
    .about-hero {
        padding: 55px 0 45px;
    }

    .about-why-grid {
        grid-template-columns: 1fr;
        gap: 32px;
    }

    .about-why-copy {
        max-width: none;
    }

    .about-horizon-visual {
        min-height: 285px;
    }

    .about-project-box {
        padding: 36px 22px;
    }

    .about-orb-one {
        width: 190px;
        height: 190px;
        left: -100px;
    }
}

@media (max-width: 520px) {
    .about-hero {
        padding-top: 42px;
    }

    .about-hero-content h1 {
        letter-spacing: -.3px;
    }

    .about-hero-content h1 .highlight {
        white-space: normal;
    }

    .about-hero-content > p {
        font-size: .98rem;
    }

    .about-hero-actions {
        flex-direction: column;
        align-items: stretch;
        max-width: 270px;
        margin-right: auto;
        margin-left: auto;
    }

    .about-cards .card {
        padding: 25px 20px;
    }

    .about-project-box {
        padding: 32px 17px;
    }

    .about-horizon-visual {
        min-height: 265px;
    }

    .about-door {
        width: 90px;
        height: 132px;
    }

    .about-project-tags {
        gap: 7px;
    }
}

/* Respect reduced-motion preferences */
@media (prefers-reduced-motion: reduce) {
    html {
        scroll-behavior: auto;
    }

    .about-page *,
    .about-page *::before,
    .about-page *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }

    .about-reveal,
    .about-reveal-left,
    .about-reveal-right,
    .about-cards .card {
        opacity: 1;
        transform: none;
    }
}
</style>
</head>

<body class="about-page">

<div id="site-header"></div>

<main>

    <!-- Purple hero -->
    <section class="about-hero">
        <div class="about-orb about-orb-one"></div>
        <div class="about-orb about-orb-two"></div>
        <div class="about-orb about-orb-three"></div>

        <div class="container about-hero-content">

            <div class="hero-eyebrow">
                About Ufuq
            </div>

            <h1>
                Your next opportunity is
                <span class="highlight">on the horizon.</span>
            </h1>

            <p>
                Ufuq brings training programs and professional courses
                for computing-field students in Saudi Arabia into one
                organized platform. Our goal is to make discovering
                opportunities easier and help students take meaningful
                steps toward their professional future.
            </p>

            <div class="about-hero-actions">
                <a href="signup.php" class="btn btn-primary">
                    Get Started
                </a>

                <a href="faq.php" class="btn">
                    Explore FAQs
                </a>
            </div>

            <div class="about-scroll-hint" aria-hidden="true">
                <span class="about-scroll-line"></span>
                <span>DISCOVER UFUQ</span>
                <span class="about-scroll-line"></span>
            </div>

        </div>
    </section>

    <!-- Mission and values -->
    <section class="section">
        <div class="container">

            <div class="about-section-heading about-reveal">
                <div class="about-mini-label">What We Stand For</div>

                <h2>A clearer path to your future</h2>

                <p>
                    Ufuq is built around a simple idea:
                    finding the right learning opportunity should be
                    organized, accessible, and inspiring.
                </p>
            </div>

            <div class="grid grid-3 about-cards">

                <div class="card text-center">
                    <div class="about-icon-wrap">
                        <span
                            class="icon icon-primary"
                            style="mask-image:url('../images-website/icons/goal.png');-webkit-mask-image:url('../images-website/icons/goal.png');"
                            aria-hidden="true"
                        ></span>
                    </div>

                    <h3>Our Mission</h3>

                    <p>
                        Make it easier for computing students to discover,
                        compare, and apply for relevant training programs
                        and professional courses.
                    </p>
                </div>

                <div class="card text-center">
                    <div class="about-icon-wrap">
                        <span
                            class="icon icon-primary"
                            style="mask-image:url('../images-website/icons/company.png');-webkit-mask-image:url('../images-website/icons/company.png');"
                            aria-hidden="true"
                        ></span>
                    </div>

                    <h3>Trust & Transparency</h3>

                    <p>
                        Company verification and clear opportunity
                        information help students make informed decisions
                        about their learning and career development.
                    </p>
                </div>

                <div class="card text-center">
                    <div class="about-icon-wrap">
                        <span
                            class="icon icon-primary"
                            style="mask-image:url('../images-website/icons/training.png');-webkit-mask-image:url('../images-website/icons/training.png');"
                            aria-hidden="true"
                        ></span>
                    </div>

                    <h3>Built for Computing Fields</h3>

                    <p>
                        Focused on IT, Computer Science, Computer
                        Engineering, and related fields, with an emphasis
                        on learning and professional growth.
                    </p>
                </div>

            </div>
        </div>
    </section>

    <!-- Meaning behind the name -->
    <section class="section section-alt about-why-section">
        <div class="container">

            <div class="about-why-grid">

                <div class="about-why-copy about-reveal-left">
                    <div class="about-mini-label">The Meaning Behind Our Name</div>

                    <h2>Why Ufuq?</h2>

                    <p>
                        <strong>Ufuq</strong> (أفق) is the Arabic word
                        for horizon. It represents new possibilities,
                        fresh perspectives, and the opportunities that
                        help us move forward.
                    </p>

                    <p>
                        The open-door concept in our visual identity
                        represents access to new opportunities.
                        Ufuq aims to give students one organized starting
                        point for discovering training programs and
                        professional courses.
                    </p>

                    <div class="about-why-points">

                        <div class="about-why-point">
                            <span class="about-check" aria-hidden="true">✓</span>
                            <div>
                                <strong>Discover</strong>
                                <p>Explore training opportunities in one place.</p>
                            </div>
                        </div>

                        <div class="about-why-point">
                            <span class="about-check" aria-hidden="true">✓</span>
                            <div>
                                <strong>Explore</strong>
                                <p>Review details and find opportunities that fit your goals.</p>
                            </div>
                        </div>

                        <div class="about-why-point">
                            <span class="about-check" aria-hidden="true">✓</span>
                            <div>
                                <strong>Grow</strong>
                                <p>Take steps toward developing your skills and experience.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Decorative Ufuq illustration -->
                <div class="about-horizon-visual about-reveal-right"
                     role="img"
                     aria-label="An open doorway beneath a golden sun, symbolizing new opportunities">

                    <div class="about-horizon-sun"></div>
                    <div class="about-horizon-line"></div>
                    <div class="about-horizon-line about-horizon-line-two"></div>

                    <div class="about-door">
                        <div class="about-door-inner">
                            <span class="about-door-knob"></span>
                        </div>
                    </div>

                    <div class="about-horizon-caption">
                        OPEN THE DOOR TO NEW POSSIBILITIES
                    </div>

                </div>

            </div>
        </div>
    </section>

    <!-- About the project -->
    <section class="section about-project">
        <div class="container">

            <div class="about-project-box about-reveal">

                <div class="about-project-content">

                    <div class="about-mini-label">
                        About Our Project
                    </div>

                    <h2>Meet Ufuq</h2>

                    <p>
                        Ufuq is an IT496 graduation project designed to
                        organize the discovery of training opportunities
                        and professional courses. The platform brings
                        students, companies, and administrators together
                        through a structured digital experience.
                    </p>

                    <div class="about-project-tags">
                        <span class="about-project-tag">Student Experience</span>
                        <span class="about-project-tag">Company Verification</span>
                        <span class="about-project-tag">Training Opportunities</span>
                        <span class="about-project-tag">Smart Features</span>
                    </div>

                    <a href="signup.php" class="btn btn-primary">
                        Join Ufuq
                    </a>

                </div>

            </div>
        </div>
    </section>

</main>

<div id="site-footer"></div>

<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
/* Preserve the existing shared Ufuq header and footer. */
renderHeader({ active: "about", base: "../" });
renderFooter({ base: "../" });

(function () {
    "use strict";

    const animatedElements = document.querySelectorAll(
        ".about-reveal, " +
        ".about-reveal-left, " +
        ".about-reveal-right, " +
        ".about-cards .card"
    );

    const reduceMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

    if (
        reduceMotion ||
        !("IntersectionObserver" in window)
    ) {
        animatedElements.forEach(function (element) {
            element.classList.add("is-visible");
        });

        return;
    }

    const observer = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add("is-visible");
                    observer.unobserve(entry.target);
                }
            });
        },
        {
            threshold: 0.12,
            rootMargin: "0px 0px -25px 0px"
        }
    );

    animatedElements.forEach(function (element) {
        observer.observe(element);
    });
})();
</script>

</body>
</html>