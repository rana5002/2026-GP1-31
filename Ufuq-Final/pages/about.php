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

    /* Match the public home page: warm white, beige and plum */
    background: linear-gradient(
        135deg,
        #fff 0%,
        #FBF8FC 56%,
        #F6EFE5 100%
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
    color: #44384D;
}

.about-hero-content h1 .highlight {
    position: relative;
    display: inline-block;
    color: #44384D;
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
    background: #8B7B91;
    transform: scaleX(0);
    transform-origin: left;
    animation: aboutUnderline .75s ease .65s forwards;
}

.about-hero-content > p {
    max-width: 680px;
    margin: 0 auto;
    color: #625968;
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
    box-shadow: 0 10px 25px rgba(68, 56, 77, .14);
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
    border: 1px solid rgba(68, 56, 77, .16);
    box-shadow:
        0 0 0 28px rgba(68, 56, 77, .035),
        0 0 0 58px rgba(68, 56, 77, .02);
    animation: aboutOrbFloat 9s ease-in-out infinite;
}

.about-orb-two {
    width: 150px;
    height: 150px;
    right: -55px;
    bottom: 10px;
    background: rgba(68, 56, 77, .13);
    animation: aboutOrbFloat 7s ease-in-out infinite reverse;
}

.about-orb-three {
    width: 13px;
    height: 13px;
    top: 23%;
    right: 17%;
    background: #8B7B91;
    animation: aboutPulse 2.8s ease-in-out infinite;
}

.about-scroll-hint {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 9px;
    margin-top: 38px;
    color: #44384D;
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
    background: var(--color-secondary, #E2A94B);
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
    border: 1px solid rgba(68, 56, 77, .09);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 4px 18px rgba(68, 56, 77, .035);

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
    background: #8B7B91;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform .35s ease;
}

.about-cards .card:hover::before {
    transform: scaleX(1);
}

.about-cards .card:hover {
    transform: translateY(-8px);
    border-color: rgba(68, 56, 77, .22);
    box-shadow: 0 16px 36px rgba(68, 56, 77, .10);
}

.about-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 66px;
    height: 66px;
    margin: 0 auto 20px;
    border-radius: 18px;
    background: rgba(68, 56, 77, .08);
    transition:
        background .3s ease,
        transform .35s ease,
        border-radius .35s ease;
}

.about-cards .card:hover .about-icon-wrap {
    background: rgba(139, 123, 145, .22);
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
    background: rgba(68, 56, 77, .09);
    color: #44384D;
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
    border: 1px solid rgba(68, 56, 77, .1);
    background: linear-gradient(
        150deg,
        #ECE8EF,
        #ECE8EF 55%,
        #FBF8FC
    );
}

.about-horizon-sun {
    position: absolute;
    top: 58px;
    width: 112px;
    height: 112px;
    border-radius: 50%;
    background: linear-gradient(145deg, #f3d99f, #E2A94B);
    box-shadow: 0 0 0 16px rgba(226, 169, 75, .09);
    animation: aboutSunGlow 4s ease-in-out infinite;
}

.about-horizon-line {
    position: absolute;
    right: -10%;
    bottom: 70px;
    left: -10%;
    height: 115px;
    border-top: 2px solid rgba(68, 56, 77, .28);
    border-radius: 50% 50% 0 0;
    background: rgba(68, 56, 77, .06);
    transform: rotate(-5deg);
}

.about-horizon-line-two {
    bottom: 28px;
    height: 105px;
    background: rgba(68, 56, 77, .09);
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
    border: 3px solid #44384D;
    border-bottom: 0;
    border-radius: 55px 55px 0 0;
    transform: translateX(-50%);
    background: rgba(255, 255, 255, .32);
    box-shadow: 0 0 0 10px rgba(68, 56, 77, .045);
    animation: aboutDoorGlow 4s ease-in-out infinite;
}

.about-door-inner {
    position: relative;
    width: 100%;
    height: 100%;
    border: 2px solid rgba(68, 56, 77, .48);
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
    background: #E2A94B;
}

.about-horizon-caption {
    position: absolute;
    z-index: 3;
    right: 15px;
    bottom: 14px;
    left: 15px;
    color: #44384D;
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
    border: 1px solid rgba(68, 56, 77, .10);
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 8px 28px rgba(68, 56, 77, .05);
}

.about-project-box::after {
    content: "";
    position: absolute;
    top: -90px;
    right: -75px;
    width: 210px;
    height: 210px;
    border: 1px solid rgba(139, 123, 145, .55);
    border-radius: 50%;
    box-shadow:
        0 0 0 18px rgba(139, 123, 145, .08),
        0 0 0 38px rgba(139, 123, 145, .04);
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
    border: 1px solid rgba(68, 56, 77, .14);
    border-radius: 999px;
    background: rgba(68, 56, 77, .05);
    color: #44384D;
    font-size: .82rem;
    font-weight: 600;
    transition: transform .22s ease, background .22s ease;
}

.about-project-tag:hover {
    transform: translateY(-3px);
    background: rgba(139, 123, 145, .2);
}

.about-project-content .btn {
    transition: transform .25s ease, box-shadow .25s ease;
}

.about-project-content .btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(68, 56, 77, .15);
}


/* ---------- FAQ: aligned with Ufuq navy, lavender and gold ---------- */
.about-faq-section {
    overflow: hidden;
    background: linear-gradient(180deg, #FBF8FC 0%, #fff 62%, #F6EFE5 100%);
}
.about-faq-heading { max-width: 720px; margin: 0 auto 28px; text-align: center; }
.about-faq-heading h2 { margin-bottom: 12px; color: #44384D; }
.about-faq-heading p { margin: 0 auto; line-height: 1.85; color: #625968; }
.about-faq-heading .about-mini-label { margin-bottom: 14px; }
.about-faq-search-wrap { max-width: 680px; margin: 0 auto 22px; }
.about-faq-search {
    display: flex; align-items: center; gap: 12px; padding: 9px 12px 9px 18px;
    border: 1px solid rgba(68,56,77,.14); border-radius: 15px; background: #fff;
    box-shadow: 0 8px 25px rgba(68,56,77,.06); transition: border-color .2s, box-shadow .2s;
}
.about-faq-search:focus-within { border-color: #8B7B91; box-shadow: 0 10px 28px rgba(68,56,77,.12); }
.about-faq-search .search-symbol { color: #44384D; font-size: 1.35rem; }
.about-faq-search input { flex: 1; min-width: 0; padding: 11px 0; border: 0; outline: 0; background: transparent; color: #44384D; font: inherit; box-shadow: none; }
.about-faq-search input:focus { outline: none; box-shadow: none; }
.about-faq-search input::placeholder { color: #857A89; }
.about-faq-clear { display: none; padding: 8px 11px; border: 0; border-radius: 9px; background: #ECE8EF; color: #44384D; cursor: pointer; font: inherit; font-size: .84rem; }
.about-faq-clear.is-visible { display: inline-flex; }
.about-faq-categories { display: flex; justify-content: center; flex-wrap: wrap; gap: 9px; margin: 0 auto 28px; }
.about-faq-category { padding: 9px 15px; border: 1px solid rgba(68,56,77,.15); border-radius: 999px; background: #fff; color: #625968; cursor: pointer; font: inherit; font-size: .88rem; font-weight: 600; transition: all .2s ease; }
.about-faq-category:hover { transform: translateY(-2px); border-color: #8B7B91; }
.about-faq-category.active { border-color: #44384D; background: #44384D; color: #fff; box-shadow: 0 5px 13px rgba(68,56,77,.16); }
.about-faq-list { display: grid; gap: 13px; max-width: 880px; margin: 0 auto; }
.about-faq-item { overflow: hidden; border: 1px solid rgba(68,56,77,.10); border-radius: 15px; background: #fff; box-shadow: 0 4px 15px rgba(68,56,77,.035); transition: border-color .2s, box-shadow .2s, transform .2s; }
.about-faq-item:hover, .about-faq-item.is-open { border-color: rgba(68,56,77,.3); box-shadow: 0 9px 24px rgba(68,56,77,.075); }
.about-faq-item[hidden] { display: none !important; }
.about-faq-question { display: flex; align-items: center; gap: 14px; width: 100%; padding: 19px 21px; border: 0; background: transparent; color: #44384D; text-align: left; cursor: pointer; font: inherit; }
.about-faq-number { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 36px; height: 36px; border-radius: 11px; background: #ECE8EF; color: #44384D; font-size: .78rem; font-weight: 800; }
.about-faq-question-text { flex: 1; font-size: .97rem; font-weight: 650; line-height: 1.6; }
.about-faq-chevron { display: flex; align-items: center; justify-content: center; flex-shrink: 0; width: 31px; height: 31px; border-radius: 50%; background: #F7F3F7; color: #44384D; transition: transform .25s, background .25s, color .25s; }
.about-faq-chevron svg { width: 15px; height: 15px; }
.about-faq-question[aria-expanded="true"] .about-faq-chevron { transform: rotate(180deg); background: #44384D; color: #fff; }
.about-faq-answer { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .28s ease; }
.about-faq-answer-inner { overflow: hidden; min-height: 0; }
.about-faq-answer-content { padding: 0 24px 21px 71px; color: #625968; line-height: 1.85; }
.about-faq-answer-content p { margin: 0; }
.about-faq-answer-content a { color: #44384D; font-weight: 700; text-underline-offset: 3px; }
.about-faq-item.is-open .about-faq-answer { grid-template-rows: 1fr; }
.about-faq-empty { display: none; max-width: 650px; margin: 20px auto 0; padding: 28px 20px; border: 1px dashed #D8CFD9; border-radius: 15px; background: #fff; text-align: center; color: #625968; }
.about-faq-empty.is-visible { display: block; }
.about-faq-bottom { margin-top: 28px; text-align: center; color: #625968; }
.about-faq-bottom a { color: #44384D; font-weight: 700; text-underline-offset: 4px; }
.about-faq-category:focus-visible, .about-faq-question:focus-visible, .about-faq-clear:focus-visible { outline: 3px solid rgba(139,123,145,.55); outline-offset: 3px; }
@media (max-width: 520px) {
 .about-faq-question { gap: 10px; padding: 16px 13px; }
 .about-faq-number { width: 31px; height: 31px; border-radius: 9px; }
 .about-faq-question-text { font-size: .92rem; }
 .about-faq-answer-content { padding: 0 16px 18px 54px; font-size: .92rem; }
 .about-faq-categories { gap: 7px; }
 .about-faq-category { padding: 8px 11px; font-size: .82rem; }
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
        box-shadow: 0 0 0 0 rgba(139, 123, 145, .3);
    }
    50% {
        transform: scale(1.15);
        box-shadow: 0 0 0 10px rgba(139, 123, 145, 0);
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
        box-shadow: 0 0 0 10px rgba(68, 56, 77, .045);
    }
    50% {
        box-shadow: 0 0 0 17px rgba(68, 56, 77, .025);
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

<?php
$headerBase = '../';
$headerActive = 'about';
require __DIR__ . '/../includes/header.php';
?>

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
                <span class="highlight">on the Ufuq.</span>
            </h1>

            <p>
                Ufuq brings training opportunities and professional courses offered by companies in Saudi Arabia together in one organized platform. It helps users discover opportunities, while giving companies a place to share them and administrators tools to manage the platform.
            </p>

            <div class="about-hero-actions">
                <a href="signup.php" class="btn btn-primary">
                    Get Started
                </a>

                <a href="#about-faq" class="btn">
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
                        Make it easier to discover and review training opportunities
                        and professional courses, and help connect the people
                        looking for opportunities with the companies offering them.
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
                        information help users make informed decisions, while
                        companies can present opportunities more transparently.
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

                    <h3>Built for Opportunity Discovery</h3>

                    <p>
                        Designed to bring opportunity seekers, companies, and platform
                        administrators together through a clearer, more organized
                        experience for discovering and managing opportunities.
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


    <!-- Frequently asked questions, integrated into About Us -->
    <?php
    $aboutFaqs = [
        ['category' => 'account', 'question' => 'What is Ufuq?', 'answer' => 'Ufuq is a platform that brings training opportunities and professional courses offered by companies in Saudi Arabia together in one organized place. It supports opportunity seekers, companies sharing opportunities, and administrators managing the platform.'],
        ['category' => 'account', 'question' => 'Who can use Ufuq?', 'answer' => 'People looking for training opportunities can create a user account to explore available listings. Companies can register separately to manage their profiles and publish opportunities, subject to the platform’s verification process. Administrators manage platform activities.'],
        ['category' => 'account', 'question' => 'How do I create an account?', 'answer' => 'Open the <a href="signup.php">Sign Up</a> page, choose the appropriate account type, complete the required fields, and submit your registration.'],
        ['category' => 'opportunities', 'question' => 'How can I find training opportunities that suit me?', 'answer' => 'Browse the available opportunities and use search and filters, where available, to narrow the results by your interests and field of study. Always review the opportunity details and eligibility requirements before applying.'],
        ['category' => 'opportunities', 'question' => 'What information should I check before applying?', 'answer' => 'Check the program description, eligibility requirements, application deadline, schedule, duration, location, and certificate information if provided. Make sure the opportunity matches your interests and qualifications.'],
        ['category' => 'opportunities', 'question' => 'How do I apply for an opportunity?', 'answer' => 'Open an opportunity to review its details. If an application option is available, follow the instructions shown on the opportunity page. Some companies may provide a link to their official application page.'],
        ['category' => 'opportunities', 'question' => 'Can I apply after the application deadline?', 'answer' => 'Applications depend on the deadline and the company’s rules. Check the closing date and any instructions provided by the company. If the opportunity is closed, look for other available opportunities.'],
        ['category' => 'companies', 'question' => 'How can a company join Ufuq?', 'answer' => 'A company can register through the <a href="signup.php">Sign Up</a> page by selecting the company account type and submitting the required information and documents. Registration may require administrator review before the company can publish opportunities.'],
        ['category' => 'companies', 'question' => 'Why does a company’s registration need approval?', 'answer' => 'Administrator review helps verify company registrations before companies are allowed to publish opportunities on the platform. If registration is pending, allow time for the review process.'],
        ['category' => 'platform', 'question' => 'How can I protect my account?', 'answer' => 'Use a strong, unique password, avoid sharing your login details, and sign out when using a shared device. Never share sensitive personal information with unknown parties.'],
    ];
    ?>
    <section class="section about-faq-section" id="about-faq">
        <div class="container">
            <div class="about-faq-heading about-reveal">
                <div class="about-mini-label">Need a Hand?</div>
                <h2>Frequently Asked Questions</h2>
                <p>Find quick answers about Ufuq, opportunity listings, accounts, and company registration.</p>
            </div>
            <div class="about-faq-search-wrap about-reveal">
                <label class="about-faq-search" for="aboutFaqSearch">
                    <span class="search-symbol" aria-hidden="true">⌕</span>
                    <input type="search" id="aboutFaqSearch" placeholder="Search for a question..." autocomplete="off">
                    <button type="button" class="about-faq-clear" id="aboutFaqClear">Clear</button>
                </label>
            </div>
            <div class="about-faq-categories" role="group" aria-label="Filter questions by category">
                <button type="button" class="about-faq-category active" data-faq-category="all" aria-pressed="true">All Questions</button>
                <button type="button" class="about-faq-category" data-faq-category="account" aria-pressed="false">Accounts</button>
                <button type="button" class="about-faq-category" data-faq-category="opportunities" aria-pressed="false">Opportunities</button>
                <button type="button" class="about-faq-category" data-faq-category="companies" aria-pressed="false">Companies</button>
                <button type="button" class="about-faq-category" data-faq-category="platform" aria-pressed="false">Platform</button>
            </div>
            <div class="about-faq-list" id="aboutFaqList">
                <?php foreach ($aboutFaqs as $index => $faq): ?>
                    <article class="about-faq-item" data-faq-category="<?= htmlspecialchars($faq['category'], ENT_QUOTES, 'UTF-8') ?>" data-faq-search="<?= htmlspecialchars(strtolower(strip_tags($faq['question'] . ' ' . $faq['answer'])), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="about-faq-question" aria-expanded="false">
                            <span class="about-faq-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="about-faq-question-text"><?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="about-faq-chevron" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </span>
                        </button>
                        <div class="about-faq-answer"><div class="about-faq-answer-inner"><div class="about-faq-answer-content"><p><?= $faq['answer'] ?></p></div></div></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="about-faq-empty" id="aboutFaqEmpty" role="status">
                <strong>No matching questions found.</strong>
                <p>Try another search term or choose a different category.</p>
            </div>
            <div class="about-faq-bottom about-reveal">
                Still have questions? <a href="signup.php">Join Ufuq</a> to explore the platform.
            </div>
        </div>
    </section>

</main>

<?php
require __DIR__ . '/../includes/footer.php';
?>

<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
(function () {
    "use strict";

    /* Render the shared header and footer safely. */
    if (typeof renderHeader === "function") {
        renderHeader({
            active: "about",
            base: "../"
        });
    } else {
        console.error(
            "renderHeader is not available. Check ../js/components.js"
        );
    }

    


    /* FAQ search, category filters, and accessible accordion. */
    const faqItems = Array.from(document.querySelectorAll(".about-faq-item"));
    const faqSearch = document.getElementById("aboutFaqSearch");
    const faqClear = document.getElementById("aboutFaqClear");
    const faqEmpty = document.getElementById("aboutFaqEmpty");
    const faqCategoryButtons = Array.from(document.querySelectorAll(".about-faq-category"));
    let activeFaqCategory = "all";

    function filterAboutFaqs() {
        const query = (faqSearch ? faqSearch.value : "").trim().toLowerCase();
        let visibleCount = 0;
        faqItems.forEach(function (item) {
            const matchesCategory = activeFaqCategory === "all" || item.dataset.faqCategory === activeFaqCategory;
            const matchesSearch = !query || (item.dataset.faqSearch || "").includes(query);
            const visible = matchesCategory && matchesSearch;
            item.hidden = !visible;
            if (visible) visibleCount++;
            else {
                item.classList.remove("is-open");
                const button = item.querySelector(".about-faq-question");
                if (button) button.setAttribute("aria-expanded", "false");
            }
        });
        if (faqEmpty) faqEmpty.classList.toggle("is-visible", visibleCount === 0);
        if (faqClear && faqSearch) faqClear.classList.toggle("is-visible", faqSearch.value.length > 0);
    }

    if (faqSearch) faqSearch.addEventListener("input", filterAboutFaqs);
    if (faqClear && faqSearch) faqClear.addEventListener("click", function () {
        faqSearch.value = "";
        filterAboutFaqs();
        faqSearch.focus();
    });
    faqCategoryButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            activeFaqCategory = button.dataset.faqCategory || "all";
            faqCategoryButtons.forEach(function (categoryButton) {
                const active = categoryButton === button;
                categoryButton.classList.toggle("active", active);
                categoryButton.setAttribute("aria-pressed", active ? "true" : "false");
            });
            filterAboutFaqs();
        });
    });
    faqItems.forEach(function (item) {
        const button = item.querySelector(".about-faq-question");
        if (!button) return;
        button.addEventListener("click", function () {
            const willOpen = !item.classList.contains("is-open");
            item.classList.toggle("is-open", willOpen);
            button.setAttribute("aria-expanded", willOpen ? "true" : "false");
        });
    });


    /* Reveal content when scrolling. */
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
    } else {
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
                threshold: 0.05,
                rootMargin: "0px 0px -10px 0px"
            }
        );

        animatedElements.forEach(function (element) {
            observer.observe(element);
        });
    }
})();
</script>

</body>
</html>
