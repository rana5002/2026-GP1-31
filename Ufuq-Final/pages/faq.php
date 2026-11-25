<?php
/* =========================================================
   Ufuq - Frequently Asked Questions
   File: pages/faq.php
   PHP + MySQL
   ========================================================= */

require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/bootstrap.php';

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

/* Safe output helper */
if (!function_exists('faq_escape')) {
    function faq_escape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/* Shared PHP header settings */
$headerBase = '../';
$headerActive = 'faq';
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
<link rel="stylesheet" href="../css/faq.css">

</head>

<body class="faq-page">

<?php
/* Render the shared PHP header directly. */
require __DIR__ . '/../includes/header.php';
?>

<main>

    <!-- Hero -->
    <section class="faq-hero">
        <div class="faq-decoration faq-decoration-one"></div>
        <div class="faq-decoration faq-decoration-two"></div>

        <div class="container faq-hero-content">
            <div class="faq-eyebrow">Help Center</div>

            <h1>
                Questions? Let's find
                <span class="faq-title-highlight">your answers.</span>
            </h1>

            <p class="faq-hero-description">
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

            <div class="faq-categories"
                 aria-label="Filter questions by category">

                <button type="button"
                        class="faq-category active"
                        data-category="all"
                        aria-pressed="true">
                    All Questions
                </button>

                <button type="button"
                        class="faq-category"
                        data-category="account"
                        aria-pressed="false">
                    Account
                </button>

                <button type="button"
                        class="faq-category"
                        data-category="opportunities"
                        aria-pressed="false">
                    Opportunities
                </button>

                <button type="button"
                        class="faq-category"
                        data-category="companies"
                        aria-pressed="false">
                    Companies
                </button>

                <button type="button"
                        class="faq-category"
                        data-category="platform"
                        aria-pressed="false">
                    Platform
                </button>
            </div>
        </div>
    </section>

    <!-- FAQ Questions -->
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

                <?php foreach ($faqs as $index => $faq): ?>
                    <?php
                    $number = $index + 1;
                    $questionId = 'faq-question-' . $number;
                    $answerId = 'faq-answer-' . $number;
                    ?>

                    <article
                        class="faq-item"
                        data-category="<?= faq_escape($faq['category']) ?>"
                    >
                        <button
                            class="faq-question"
                            id="<?= faq_escape($questionId) ?>"
                            type="button"
                            aria-expanded="false"
                            aria-controls="<?= faq_escape($answerId) ?>"
                        >
                            <span class="faq-number">
                                <?= sprintf('%02d', $number) ?>
                            </span>

                            <span class="faq-question-text">
                                <?= faq_escape($faq['question']) ?>
                            </span>

                            <span class="faq-chevron" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path
                                        d="m6 9 6 6 6-6"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </span>
                        </button>

                        <div
                            class="faq-answer"
                            id="<?= faq_escape($answerId) ?>"
                            role="region"
                            aria-labelledby="<?= faq_escape($questionId) ?>"
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

            <a href="../index.php" class="faq-contact-button">
                Back to Home <span aria-hidden="true">→</span>
            </a>
        </div>
    </section>

</main>

<?php
/* Render the shared PHP footer directly. */
require __DIR__ . '/../includes/footer.php';
?>

<script>
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

    /* Accordion: keep only one answer open at a time. */
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

        emptyMessage.classList.toggle(
            "is-visible",
            visibleCount === 0
        );

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

    /* Reveal headings and questions on scroll. */
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