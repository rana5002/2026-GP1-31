<?php
require_once __DIR__ . '/../includes/session.php';

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


</head>

<body class="about-page">

<?php
$extraCss = '../css/about.css';
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


<script>
(function () {
    "use strict";

    

    


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
