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

/* FAQ content */
$faqs = [
    [
        'category' => 'account',
        'question' => 'What is Ufuq?',
        'answer' => 'Ufuq is a platform that helps computing-field students in Saudi Arabia discover training programs and professional courses offered by companies in one organized place.'
    ],
    [
        'category' => 'account',
        'question' => 'Who can create an account on Ufuq?',
        'answer' => 'Students and eligible users can create a user account to explore opportunities. Companies can register separately to manage their profiles and publish training opportunities, subject to the platform’s verification process.'
    ],
    [
        'category' => 'account',
        'question' => 'How do I create an account?',
        'answer' => 'Open the <a href="signup.php">Sign Up</a> page, choose the appropriate account type, complete the required fields, and submit your registration.'
    ],
    [
        'category' => 'opportunities',
        'question' => 'How can I find training opportunities that suit me?',
        'answer' => 'Browse the available opportunities and use search and filters, where available, to narrow the results by your interests and field of study. Always review the opportunity details and eligibility requirements before applying.'
    ],
    [
        'category' => 'opportunities',
        'question' => 'What information should I check before applying?',
        'answer' => 'Check the program description, eligibility requirements, application deadline, schedule, duration, location, and certificate information if provided. Make sure the opportunity matches your interests and qualifications.'
    ],
    [
        'category' => 'opportunities',
        'question' => 'How do I apply for an opportunity?',
        'answer' => 'Open an opportunity to review its details. If an application option is available, follow the instructions shown on the opportunity page. Some companies may provide a link to their official application page.'
    ],
    [
        'category' => 'opportunities',
        'question' => 'Can I apply after the application deadline?',
        'answer' => 'Applications depend on the deadline and the company’s rules. Check the closing date and any instructions provided by the company. If the opportunity is closed, look for other available opportunities.'
    ],
    [
        'category' => 'companies',
        'question' => 'How can a company join Ufuq?',
        'answer' => 'A company can register through the <a href="signup.php">Sign Up</a> page by selecting the company account type and submitting the required information and documents. Registration may require administrator review before the company can publish opportunities.'
    ],
    [
        'category' => 'companies',
        'question' => 'Why does a company’s registration need approval?',
        'answer' => 'Administrator review helps verify company registrations before companies are allowed to publish opportunities on the platform. If registration is pending, allow time for the review process.'
    ],
    [
        'category' => 'platform',
        'question' => 'Does Ufuq provide course recommendations?',
        'answer' => 'Ufuq is designed to help students discover relevant training opportunities. Where recommendation features are available, they may use information such as a student’s field of study, skills, and interests to help identify relevant opportunities.'
    ],
    [
        'category' => 'platform',
        'question' => 'How can I protect my account?',
        'answer' => 'Use a strong, unique password, avoid sharing your login details, and sign out when using a shared device. Never share sensitive personal information with unknown parties.'
    ],
    [
        'category' => 'platform',
        'question' => 'What should I do if I find incorrect opportunity information?',
        'answer' => 'Review the information on the company’s official page when available. If the information on Ufuq appears incorrect or outdated, contact the platform team through an available support channel.'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>FAQ — Ufuq</title>

<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">

<style>
html {
    scroll-behavior: smooth;
}

.faq-page {
    overflow-x: hidden;
    background: #fff;
}

/* =========================
   HERO — PURPLE Ufuq THEME
   ========================= */

.faq-page .faq-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    padding: 85px 20px 65px;
    background: linear-gradient(
        135deg,
        #f1edfa 0%,
        #e8e0f6 48%,
        #f7f3fc 100%
    );
}

.faq-hero::before,
.faq-hero::after {
    content: "";
    position: absolute;
    z-index: -1;
    border-radius: 50%;
    pointer-events: none;
}

.faq-hero::before {
    width: 360px;
    height: 360px;
    top: -190px;
    left: -100px;
    border: 1px solid rgba(112, 77, 164, .18);
    box-shadow:
        0 0 0 35px rgba(112, 77, 164, .035),
        0 0 0 75px rgba(112, 77, 164, .025);
}

.faq-hero::after {
    width: 250px;
    height: 250px;
    right: -110px;
    bottom: -135px;
    background: rgba(255, 255, 255, .38);
}

.faq-hero-content {
    position: relative;
    z-index: 1;
    max-width: 850px;
    margin: 0 auto;
    text-align: center;
    animation: faqFadeUp .8s ease both;
}

.faq-hero-content .hero-eyebrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 20px;
    padding: 9px 17px;
    border: 1px solid rgba(112, 77, 164, .18);
    border-radius: 999px;
    background: rgba(255, 255, 255, .65);
    color: #7050a1;
    font-size: .82rem;
    font-weight: 700;
    letter-spacing: 1.4px;
    text-transform: uppercase;
}

.faq-hero-content .hero-eyebrow::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #d7a84b;
    box-shadow: 0 0 0 4px rgba(215, 168, 75, .15);
}

.faq-hero-content h1 {
    max-width: 780px;
    margin: 0 auto 18px;
    color: #252044;
    font-size: clamp(2.2rem, 5vw, 3.6rem);
    line-height: 1.15;
    letter-spacing: -1.5px;
}

.faq-title-highlight {
    display: inline-block;
    color: #7857ad;
}

.faq-hero-content > p {
    max-width: 640px;
    margin: 0 auto;
    color: #68627c;
    font-size: 1.05rem;
    line-height: 1.85;
}

/* Floating decorations */

.faq-decoration {
    position: absolute;
    pointer-events: none;
    border-radius: 50%;
}

.faq-decoration-one {
    top: 19%;
    left: 7%;
    width: 54px;
    height: 54px;
    border: 1px solid rgba(112, 77, 164, .24);
    animation: faqFloat 6s ease-in-out infinite;
}

.faq-decoration-two {
    right: 9%;
    bottom: 18%;
    width: 25px;
    height: 25px;
    background: rgba(215, 168, 75, .55);
    box-shadow: 0 0 0 10px rgba(215, 168, 75, .1);
    animation: faqFloat 5s ease-in-out infinite reverse;
}

/* =========================
   SEARCH BAR
   ========================= */

.faq-search-wrap {
    max-width: 650px;
    margin: 32px auto 0;
}

.faq-search {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 9px 8px 18px;
    border: 1px solid rgba(112, 77, 164, .17);
    border-radius: 17px;
    background: rgba(255, 255, 255, .94);
    box-shadow: 0 12px 35px rgba(64, 43, 102, .09);
    transition: border-color .25s ease, box-shadow .25s ease,
                transform .25s ease;
}

.faq-search:focus-within {
    transform: translateY(-2px);
    border-color: #9a7ac7;
    box-shadow: 0 15px 38px rgba(112, 77, 164, .16);
}

.faq-search-icon {
    flex-shrink: 0;
    color: #7958ac;
    font-size: 1.65rem;
    line-height: 1;
}

.faq-search input {
    width: 100%;
    min-width: 0;
    padding: 12px 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: #302747;
    font: inherit;
    box-shadow: none;
}

.faq-search input:focus {
    outline: none;
    box-shadow: none;
}

.faq-search input::placeholder {
    color: #9690a5;
}

.faq-search-clear {
    display: none;
    flex-shrink: 0;
    padding: 9px 12px;
    border: 0;
    border-radius: 9px;
    background: #f0eaf8;
    color: #7050a1;
    cursor: pointer;
    font: inherit;
    font-size: .84rem;
    font-weight: 600;
}

.faq-search-clear:hover {
    background: #e6daf5;
}

.faq-search-clear.is-visible {
    display: inline-flex;
}

/* =========================
   CATEGORY FILTERS
   ========================= */

.faq-categories {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 25px;
}

.faq-category {
    padding: 10px 17px;
    border: 1px solid rgba(112, 77, 164, .2);
    border-radius: 999px;
    background: rgba(255, 255, 255, .65);
    color: #6c568c;
    cursor: pointer;
    font: inherit;
    font-size: .9rem;
    font-weight: 600;
    transition: color .22s ease, background .22s ease,
                border-color .22s ease, transform .22s ease,
                box-shadow .22s ease;
}

.faq-category:hover {
    transform: translateY(-2px);
    border-color: #9878c4;
    background: #fff;
}

.faq-category.active {
    border-color: #7655a8;
    background: #7655a8;
    color: #fff;
    box-shadow: 0 5px 14px rgba(118, 85, 168, .22);
}

/* =========================
   QUESTIONS SECTION
   ========================= */

.faq-questions-section {
    padding-top: 76px;
    padding-bottom: 80px;
    background:
        radial-gradient(ellipse at 0% 15%, rgba(232, 224, 246, .35), transparent 35%),
        #fff;
}

.faq-section-heading {
    margin-bottom: 35px;
    text-align: center;
}

.faq-section-heading h2 {
    margin-bottom: 12px;
    color: #292342;
    font-size: clamp(1.7rem, 3vw, 2.3rem);
}

.faq-section-heading p {
    max-width: 600px;
    margin: 0 auto;
    color: #777187;
    line-height: 1.8;
}

.faq-heading-line {
    display: block;
    width: 54px;
    height: 4px;
    margin: 17px auto 0;
    border-radius: 999px;
    background: linear-gradient(90deg, #d7a84b, #9675c2);
}

.faq-list {
    display: grid;
    gap: 15px;
    max-width: 850px;
    margin: 0 auto;
}

.faq-item {
    overflow: hidden;
    border: 1px solid #eee8f5;
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 4px 16px rgba(55, 38, 85, .035);
    opacity: 0;
    transform: translateY(18px);
    transition: opacity .5s ease, transform .5s ease,
                box-shadow .28s ease, border-color .28s ease;
}

.faq-item.is-visible {
    opacity: 1;
    transform: translateY(0);
}

.faq-item:hover {
    border-color: #d8c9eb;
    box-shadow: 0 10px 28px rgba(87, 61, 128, .09);
}

.faq-item[hidden] {
    display: none !important;
}

.faq-question {
    display: flex;
    align-items: center;
    gap: 16px;
    width: 100%;
    padding: 21px 23px;
    border: 0;
    background: transparent;
    color: #302747;
    text-align: left;
    cursor: pointer;
    font: inherit;
}

.faq-question-text {
    flex: 1;
    font-size: .99rem;
    font-weight: 650;
    line-height: 1.65;
}

.faq-number {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: 1px solid #eee5f7;
    border-radius: 12px;
    background: #f5f0fb;
    color: #7857ad;
    font-size: .78rem;
    font-weight: 800;
    transition: background .25s ease, color .25s ease,
                border-color .25s ease, transform .25s ease;
}

.faq-chevron {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 33px;
    height: 33px;
    border-radius: 50%;
    background: #f5f0fb;
    color: #7857ad;
    transition: transform .3s ease, background .25s ease,
                color .25s ease;
}

.faq-chevron svg {
    width: 16px;
    height: 16px;
}

.faq-question[aria-expanded="true"] .faq-chevron {
    transform: rotate(180deg);
    background: #7857ad;
    color: #fff;
}

.faq-question[aria-expanded="true"] .faq-number {
    transform: scale(1.04);
    border-color: #7857ad;
    background: #7857ad;
    color: #fff;
}

.faq-question:focus-visible,
.faq-category:focus-visible,
.faq-search-clear:focus-visible {
    outline: 3px solid rgba(120, 87, 173, .35);
    outline-offset: 4px;
}

/* Answer animation */

.faq-answer {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows .32s ease;
}

.faq-answer-inner {
    overflow: hidden;
    min-height: 0;
}

.faq-answer-content {
    padding: 0 25px 23px 77px;
    color: #777187;
    line-height: 1.9;
}

.faq-answer-content p {
    margin: 0;
}

.faq-answer-content a {
    color: #7857ad;
    font-weight: 700;
    text-decoration-color: #c7b4e2;
    text-underline-offset: 4px;
}

.faq-answer-content a:hover {
    color: #594080;
}

.faq-item.is-open .faq-answer {
    grid-template-rows: 1fr;
}

.faq-item.is-open {
    border-color: #d8c9eb;
    box-shadow: 0 8px 24px rgba(87, 61, 128, .07);
}

/* =========================
   EMPTY SEARCH RESULTS
   ========================= */

.faq-empty {
    display: none;
    max-width: 650px;
    margin: 25px auto 0;
    padding: 38px 22px;
    border: 1px dashed #d8c9eb;
    border-radius: 18px;
    background: #faf7fd;
    text-align: center;
}

.faq-empty.is-visible {
    display: block;
    animation: faqFadeUp .3s ease both;
}

.faq-empty-symbol {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 58px;
    height: 58px;
    margin-bottom: 15px;
    border-radius: 18px;
    background: #eee6f8;
    color: #7857ad;
    font-size: 1.7rem;
}

.faq-empty h3 {
    margin-bottom: 8px;
    color: #302747;
}

.faq-empty p {
    margin: 0;
    color: #777187;
    line-height: 1.7;
}

/* =========================
   CONTACT CTA
   ========================= */

.faq-page .faq-contact {
    position: relative;
    overflow: hidden;
    padding: 72px 20px;
    background: linear-gradient(
        135deg,
        #f1edfa 0%,
        #e7def5 52%,
        #f7f3fc 100%
    );
    text-align: center;
}

.faq-contact::before,
.faq-contact::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.faq-contact::before {
    width: 260px;
    height: 260px;
    top: -155px;
    right: -70px;
    border: 1px solid rgba(112, 77, 164, .2);
    box-shadow: 0 0 0 25px rgba(112, 77, 164, .035);
}

.faq-contact::after {
    width: 190px;
    height: 190px;
    bottom: -125px;
    left: -65px;
    background: rgba(255, 255, 255, .3);
}

.faq-contact-inner {
    position: relative;
    z-index: 1;
    max-width: 700px;
    margin: 0 auto;
}

.faq-contact-symbol {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
    border: 1px solid rgba(112, 77, 164, .18);
    border-radius: 20px;
    background: rgba(255, 255, 255, .8);
    color: #7857ad;
    font-size: 1.8rem;
    font-weight: 700;
    box-shadow: 0 8px 25px rgba(87, 61, 128, .08);
    animation: faqFloat 4s ease-in-out infinite;
}

.faq-contact-inner h2 {
    margin-bottom: 13px;
    color: #292342;
    font-size: clamp(1.8rem, 3vw, 2.35rem);
}

.faq-contact-inner p {
    max-width: 560px;
    margin: 0 auto 27px;
    color: #68627c;
    line-height: 1.85;
}

.faq-contact .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 13px 25px;
    border: 1px solid #7655a8;
    border-radius: 12px;
    background: #7655a8;
    color: #fff;
    text-decoration: none;
    font-weight: 700;
    box-shadow: 0 7px 18px rgba(118, 85, 168, .2);
    transition: transform .25s ease, box-shadow .25s ease,
                background .25s ease;
}

.faq-contact .btn:hover {
    transform: translateY(-3px);
    background: #654590;
    box-shadow: 0 11px 25px rgba(118, 85, 168, .28);
}

/* =========================
   ANIMATIONS
   ========================= */

.faq-reveal {
    opacity: 0;
    transform: translateY(22px);
    transition: opacity .65s ease, transform .65s ease;
}

.faq-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

@keyframes faqFadeUp {
    from {
        opacity: 0;
        transform: translateY(22px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes faqFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-9px);
    }
}

/* =========================
   RESPONSIVE DESIGN
   ========================= */

@media (max-width: 700px) {
    .faq-page .faq-hero {
        padding: 65px 18px 48px;
    }

    .faq-hero-content h1 {
        letter-spacing: -.7px;
    }

    .faq-hero-content > p {
        font-size: .97rem;
    }

    .faq-decoration-one {
        left: -20px;
    }

    .faq-decoration-two {
        right: 5%;
    }

    .faq-questions-section {
        padding-top: 55px;
        padding-bottom: 60px;
    }

    .faq-question {
        gap: 11px;
        padding: 17px 14px;
    }

    .faq-question-text {
        font-size: .92rem;
    }

    .faq-number {
        width: 32px;
        height: 32px;
        border-radius: 10px;
    }

    .faq-chevron {
        width: 29px;
        height: 29px;
    }

    .faq-answer-content {
        padding: 0 16px 19px 57px;
        font-size: .93rem;
    }

    .faq-categories {
        gap: 8px;
    }

    .faq-category {
        padding: 9px 13px;
        font-size: .84rem;
    }

    .faq-search {
        padding-left: 13px;
    }

    .faq-page .faq-contact {
        padding: 55px 18px;
    }
}

@media (max-width: 400px) {
    .faq-question {
        gap: 8px;
        padding: 15px 10px;
    }

    .faq-question-text {
        font-size: .87rem;
    }

    .faq-number {
        width: 29px;
        height: 29px;
        font-size: .72rem;
    }

    .faq-answer-content {
        padding-left: 47px;
    }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
    html {
        scroll-behavior: auto;
    }

    .faq-page *,
    .faq-page *::before,
    .faq-page *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }

    .faq-item,
    .faq-reveal {
        opacity: 1;
        transform: none;
    }
}
</style>
</head>

<body class="faq-page">

<div id="site-header"></div>

<main>

    <!-- Hero -->
    <section class="faq-hero">
        <div class="faq-decoration faq-decoration-one"></div>
        <div class="faq-decoration faq-decoration-two"></div>

        <div class="container faq-hero-content">

            <div class="hero-eyebrow">Help Center</div>

            <h1>
                Questions? Let's find
                <span class="faq-title-highlight">your answers.</span>
            </h1>

            <p>
                Everything you need to know about Ufuq, from discovering
                training opportunities to managing your account.
            </p>

            <div class="faq-search-wrap">
                <div class="faq-search">
                    <span class="faq-search-icon" aria-hidden="true">⌕</span>

                    <input
                        type="search"
                        id="faqSearch"
                        placeholder="Search for a question..."
                        aria-label="Search frequently asked questions"
                        autocomplete="off"
                    >

                    <button
                        type="button"
                        class="faq-search-clear"
                        id="faqSearchClear"
                        aria-label="Clear search"
                    >
                        Clear
                    </button>
                </div>
            </div>

            <div class="faq-categories" aria-label="Filter questions by category">
                <button type="button" class="faq-category active"
                        data-category="all" aria-pressed="true">
                    All Questions
                </button>

                <button type="button" class="faq-category"
                        data-category="account" aria-pressed="false">
                    Account
                </button>

                <button type="button" class="faq-category"
                        data-category="opportunities" aria-pressed="false">
                    Opportunities
                </button>

                <button type="button" class="faq-category"
                        data-category="companies" aria-pressed="false">
                    Companies
                </button>

                <button type="button" class="faq-category"
                        data-category="platform" aria-pressed="false">
                    Platform
                </button>
            </div>

        </div>
    </section>

    <!-- Questions -->
    <section class="faq-questions-section">
        <div class="container">

            <div class="faq-section-heading faq-reveal">
                <h2>Frequently Asked Questions</h2>
                <p>
                    Browse the questions below or use the search bar
                    to find what you need.
                </p>
                <span class="faq-heading-line"></span>
            </div>

            <div class="faq-list" id="faqList">

                <?php foreach ($faqs as $index => $faq):
                    $number = $index + 1;
                    $questionId = 'faq-question-' . $number;
                    $answerId = 'faq-answer-' . $number;
                ?>
                    <article class="faq-item"
                             data-category="<?= htmlspecialchars($faq['category'], ENT_QUOTES, 'UTF-8') ?>">

                        <button
                            class="faq-question"
                            id="<?= $questionId ?>"
                            type="button"
                            aria-expanded="false"
                            aria-controls="<?= $answerId ?>"
                        >
                            <span class="faq-number">
                                <?= sprintf('%02d', $number) ?>
                            </span>

                            <span class="faq-question-text">
                                <?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <span class="faq-chevron" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path d="m6 9 6 6 6-6"
                                          stroke="currentColor"
                                          stroke-width="2"
                                          stroke-linecap="round"
                                          stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </button>

                        <div
                            class="faq-answer"
                            id="<?= $answerId ?>"
                            role="region"
                            aria-labelledby="<?= $questionId ?>"
                            aria-hidden="true"
                        >
                            <div class="faq-answer-inner">
                                <div class="faq-answer-content">
                                    <p><?= $faq['answer'] ?></p>
                                </div>
                            </div>
                        </div>

                    </article>
                <?php endforeach; ?>

            </div>

            <div class="faq-empty" id="faqEmpty" aria-live="polite">
                <div class="faq-empty-symbol" aria-hidden="true">⌕</div>
                <h3>No matching questions found</h3>
                <p>
                    Try a different keyword or choose another category.
                </p>
            </div>

        </div>
    </section>

    <!-- Contact CTA -->
    <section class="faq-contact">
        <div class="faq-decoration faq-decoration-one"></div>
        <div class="faq-decoration faq-decoration-two"></div>

        <div class="container faq-contact-inner faq-reveal">

            <div class="faq-contact-symbol" aria-hidden="true">?</div>

            <h2>Still have questions?</h2>

            <p>
                Couldn't find the answer you were looking for?
                Visit Ufuq and explore the available opportunities.
            </p>

            <a href="../index.php" class="btn btn-primary">
                Back to Home <span aria-hidden="true">→</span>
            </a>

        </div>
    </section>

</main>

<div id="site-footer"></div>

<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>

<script>
/* Keep the existing shared Ufuq header and footer. */
renderHeader({ active: "faq", base: "../" });
renderFooter({ base: "../" });

(function () {
    "use strict";

    const searchInput = document.getElementById("faqSearch");
    const clearButton = document.getElementById("faqSearchClear");
    const categoryButtons = document.querySelectorAll(".faq-category");
    const faqItems = Array.from(document.querySelectorAll(".faq-item"));
    const emptyMessage = document.getElementById("faqEmpty");

    let activeCategory = "all";

    function closeItem(item) {
        const question = item.querySelector(".faq-question");
        const answer = item.querySelector(".faq-answer");

        item.classList.remove("is-open");
        question.setAttribute("aria-expanded", "false");
        answer.setAttribute("aria-hidden", "true");
    }

    function openItem(item) {
        const question = item.querySelector(".faq-question");
        const answer = item.querySelector(".faq-answer");

        item.classList.add("is-open");
        question.setAttribute("aria-expanded", "true");
        answer.setAttribute("aria-hidden", "false");
    }

    /* Accordion: only one answer is open at a time. */
    faqItems.forEach(function (item) {
        const question = item.querySelector(".faq-question");

        question.addEventListener("click", function () {
            const wasOpen = item.classList.contains("is-open");

            faqItems.forEach(closeItem);

            if (!wasOpen) {
                openItem(item);
            }
        });
    });

    /* Search and category filters work together. */
    function filterQuestions() {
        const term = searchInput.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        faqItems.forEach(function (item) {
            const matchesCategory =
                activeCategory === "all" ||
                item.dataset.category === activeCategory;

            const matchesSearch =
                term === "" ||
                item.textContent.toLocaleLowerCase().includes(term);

            const shouldShow = matchesCategory && matchesSearch;

            item.hidden = !shouldShow;

            if (shouldShow) {
                visibleCount++;
            } else {
                closeItem(item);
            }
        });

        emptyMessage.classList.toggle("is-visible", visibleCount === 0);

        clearButton.classList.toggle(
            "is-visible",
            searchInput.value.length > 0
        );
    }

    searchInput.addEventListener("input", filterQuestions);

    clearButton.addEventListener("click", function () {
        searchInput.value = "";
        filterQuestions();
        searchInput.focus();
    });

    categoryButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            activeCategory = button.dataset.category;

            categoryButtons.forEach(function (categoryButton) {
                const isActive = categoryButton === button;

                categoryButton.classList.toggle("active", isActive);
                categoryButton.setAttribute(
                    "aria-pressed",
                    isActive ? "true" : "false"
                );
            });

            filterQuestions();
        });
    });

    /* Reveal cards and headings as they enter the viewport. */
    const animatedElements = document.querySelectorAll(
        ".faq-reveal, .faq-item"
    );

    const reduceMotion = window.matchMedia(
        "(prefers-reduced-motion: reduce)"
    ).matches;

    if (reduceMotion || !("IntersectionObserver" in window)) {
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
                threshold: 0.06,
                rootMargin: "0px 0px -15px 0px"
            }
        );

        animatedElements.forEach(function (element) {
            observer.observe(element);
        });
    }

    filterQuestions();
})();
</script>

</body>
</html>