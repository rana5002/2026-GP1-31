
/* ============================================
   Ufuq — Shared layout components
   PHP Session + MySQL compatible
   ============================================ */

/* ---------- Shared session helper ---------- */

function getCurrentSessionUser() {
    return window.UFUQ_CURRENT_USER || null;
}

/* ---------- Escape HTML ---------- */

function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, char => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[char]);
}

/* ---------- Icon ---------- */

function iconHtml(name, {
    sizePx = 18,
    base = "",
    extraClass = ""
} = {}) {
    const path = `${base}images-website/icons/${name}.png`;

    return `<span
        class="icon ${escapeHtml(extraClass)}"
        style="width:${sizePx}px;height:${sizePx}px;-webkit-mask-image:url('${path}');mask-image:url('${path}');">
    </span>`;
}

/* ---------- Navigation links ---------- */

function navLinksFor(role, base = "") {
    const links = {
        guest: [
            {
                label: "Home",
                href: `${base}index.php`,
                nav: "home"
            },
            {
                label: "About Us",
                href: `${base}pages/about.php`,
                nav: "about"
            },
            {
                label: "Frequently Asked Questions",
                href: `${base}pages/faq.php`,
                nav: "faq"
            }
        ],

        user: [
            {
                label: "Home",
                href: `${base}pages/home.php`,
                nav: "user-home"
            },
            {
                label: "Browse",
                href: `${base}pages/browse.php`,
                nav: "browse"
            },
            {
                label: "Skill Gap Analysis",
                href: `${base}pages/skill-gap.php`,
                nav: "skill-gap"
            },
            {
                label: "Application History",
                href: `${base}pages/application-history.php`,
                nav: "history"
            }
        ],

        company: [],

        admin: [
            {
                label: "Company Verification",
                href: `${base}pages/dashboard-admin.php?tab=verification`,
                nav: "verification"
            },
            {
                label: "Manage Opportunities",
                href: `${base}pages/dashboard-admin.php?tab=opportunities`,
                nav: "opportunities"
            }
        ]
    };

    return links[role] || links.guest;
}

/* ---------- Opportunity logo colors ---------- */

const OPP_LOGO_COLORS = [
    "#8E789F",
    "#557D68",
    "#A27B43",
    "#6A7FA3",
    "#A35D64"
];

function colorFor(name) {
    const value = name || "U";
    let hash = 0;

    for (let i = 0; i < value.length; i++) {
        hash = value.charCodeAt(i) + ((hash << 5) - hash);
    }

    return OPP_LOGO_COLORS[
        Math.abs(hash) % OPP_LOGO_COLORS.length
    ];
}

/* ---------- Avatar ---------- */

function avatarHtml(person, {
    base = "",
    sizePx = 38,
    fontPx = 0.9
} = {}) {
    const name = person?.name || "U";

    const initials = name
        .trim()
        .split(/\s+/)
        .map(word => word[0])
        .filter(Boolean)
        .slice(0, 2)
        .join("")
        .toUpperCase() || "U";

    if (person?.role === "user" && person.gender) {
        const filename = (
            typeof Store !== "undefined" &&
            typeof Store.getProfilePicFilename === "function"
        )
            ? Store.getProfilePicFilename(person.gender)
            : null;

        if (filename) {
            return `<img
                src="${base}images-website/${escapeHtml(filename)}"
                alt="${escapeHtml(name)}"
                style="width:${sizePx}px;height:${sizePx}px;border-radius:50%;object-fit:cover;"
                title="${escapeHtml(name)}">`;
        }
    }

    return `<span
        style="width:${sizePx}px;height:${sizePx}px;font-size:${fontPx}rem;"
        class="nav-avatar"
        title="${escapeHtml(name)}">${escapeHtml(initials)}</span>`;
}

/* ============================================
   HEADER
   ============================================ */

function renderHeader({
    active = "",
    base = ""
} = {}) {
    const mount = document.getElementById("site-header");

    if (!mount) {
        return;
    }

    /* Read the PHP session object only once. */
    const sessionUser = getCurrentSessionUser();
    const role = sessionUser?.role || "guest";
    const links = navLinksFor(role, base);

    const linksHtml = links.map(link => `
        <a
            href="${link.href}"
            class="${link.nav === active ? "active" : ""}">
            ${escapeHtml(link.label)}
        </a>
    `).join("");

    let actionsHtml = "";

    /* ---------- Guest actions ---------- */

    if (!sessionUser) {
        actionsHtml = `
            <a
                href="${base}pages/login.php"
                class="btn btn-ghost btn-sm">
                Log In
            </a>

            <a
                href="${base}pages/signup.php"
                class="btn btn-primary btn-sm">
                Sign Up
            </a>
        `;
    } else {
        /* ---------- Profile link ---------- */

        const profileHref = role === "user"
            ? `${base}pages/home.php`
            : role === "company"
                ? `${base}pages/dashboard-company.php?tab=profile`
                : `${base}pages/dashboard-admin.php`;

        /* ---------- Favorites ---------- */

        const isFavoritesPage =
            window.location.pathname.endsWith("/favorites.php");

        const favoriteBtn = role === "user"
            ? `<a
                class="btn btn-ghost btn-sm"
                href="${base}pages/favorites.php"
                title="Favorites"
                aria-label="Favorites"
                style="padding:8px;color:${isFavoritesPage ? "#8E789F" : "var(--color-text-muted)"};">
                ${iconHtml("favorite", {
                    base,
                    extraClass: isFavoritesPage ? "icon-primary" : ""
                })}
            </a>`
            : "";

        /* ---------- Notifications, logout and profile ---------- */

        actionsHtml = `
            ${favoriteBtn}

            <div style="position:relative;">
                <button
                    type="button"
                    class="btn btn-ghost btn-sm"
                    id="notifBtn"
                    title="Notifications"
                    aria-label="Notifications"
                    style="padding:8px;">

                    ${iconHtml("notification", { base })}

                    <span
                        id="notifDot"
                        class="hidden"
                        style="position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:var(--color-danger);">
                    </span>
                </button>

                <div
                    id="notifDropdown"
                    class="hidden card"
                    style="position:absolute;right:0;top:calc(100% + 8px);width:300px;max-width:calc(100vw - 32px);padding:var(--space-3);z-index:200;">
                </div>
            </div>

            <button
                type="button"
                class="btn btn-ghost btn-sm"
                id="logoutBtn">
                Log Out
            </button>

            <a href="${profileHref}" style="display:inline-flex;">
                ${avatarHtml(sessionUser, { base })}
            </a>
        `;
    }

    /* ---------- Company header centerpiece ---------- */

    const companyNameSpan = sessionUser
        ? `<span
            class="company-header-name"
            style="font-family:var(--font-body);font-weight:700;font-size:0.95rem;letter-spacing:0.01em;color:var(--color-text);">
            ${escapeHtml(sessionUser.name || "Company")}
        </span>`
        : "";

    const companyLogo = sessionUser?.logoFileName
        ? `<img
            id="companyHeaderLogo"
            src="${base}images-website/${escapeHtml(sessionUser.logoFileName)}"
            alt="${escapeHtml(sessionUser.name || "Company")}"
            style="height:32px;max-width:160px;object-fit:contain;">`
        : companyNameSpan;

    const centerHtml = role === "company"
        ? `<div class="site-header-center">${companyLogo}</div>`
        : `<nav class="nav-links" id="navLinks">${linksHtml}</nav>`;

    /* ---------- Header HTML ---------- */

    mount.innerHTML = `
        <header class="site-header">
            <div class="container">

                <a
                    href="${base}index.php"
                    class="brand"
                    style="text-decoration:none;">

                    <img
                        src="${base}images-website/logo.png"
                        alt="Ufuq"
                        class="brand-mark"
                        style="width:34px;height:34px;object-fit:contain;background:transparent;">

                    Ufuq
                </a>

                ${centerHtml}

                <div class="nav-actions">
                    ${actionsHtml}

                    ${role !== "company"
                        ? `<button
                            type="button"
                            class="nav-toggle"
                            id="navToggle"
                            aria-label="Toggle menu"
                            aria-expanded="false">
                            ${iconHtml("menu", {
                                base,
                                sizePx: 22
                            })}
                        </button>`
                        : ""}
                </div>

            </div>
        </header>
    `;

    /* ---------- Mobile navigation ---------- */

    const toggle = document.getElementById("navToggle");
    const navLinksElement = document.getElementById("navLinks");

    if (toggle && navLinksElement) {
        toggle.addEventListener("click", () => {
            const isOpen = navLinksElement.classList.toggle("open");

            toggle.setAttribute("aria-expanded", String(isOpen));
        });
    }

    /* ---------- Company logo fallback ---------- */

    const companyLogoImg =
        document.getElementById("companyHeaderLogo");

    if (companyLogoImg) {
        companyLogoImg.addEventListener("error", () => {
            companyLogoImg.outerHTML = companyNameSpan;
        });
    }

    /* ---------- Logout through PHP ---------- */

    const logoutBtn = document.getElementById("logoutBtn");

    if (logoutBtn) {
        logoutBtn.addEventListener("click", () => {
            window.location.href = `${base}logout.php`;
        });
    }

    /* ---------- Notifications ---------- */

    const notifBtn = document.getElementById("notifBtn");
    const notifDropdown = document.getElementById("notifDropdown");

    /*
     * Note:
     * These notifications still use Store/browser storage.
     * They are not yet loaded from MySQL.
     */

    if (
        notifBtn &&
        notifDropdown &&
        sessionUser &&
        typeof Store !== "undefined" &&
        typeof Store.getNotifications === "function" &&
        typeof Store.getUnreadNotificationCount === "function"
    ) {
        function refreshNotifDot() {
            const unread =
                Store.getUnreadNotificationCount(sessionUser.id);

            const dot = document.getElementById("notifDot");

            if (dot) {
                dot.classList.toggle("hidden", unread === 0);
            }
        }

        function renderDropdown() {
            const items = Store
                .getNotifications(sessionUser.id)
                .slice(0, 3);

            notifDropdown.innerHTML = items.length
                ? items.map(notification => `
                    <a
                        href="${notification.link
                            ? base + notification.link
                            : "#"}"
                        style="display:block;padding:8px 6px;border-bottom:1px solid var(--color-border);font-size:0.85rem;color:${notification.read ? "var(--color-text-muted)" : "var(--color-text)"};font-weight:${notification.read ? 400 : 600};">
                        ${escapeHtml(notification.message)}
                    </a>
                `).join("") + `
                    <a
                        href="${base}pages/notifications.php"
                        style="display:block;text-align:center;padding:8px 0 0;font-size:0.82rem;font-weight:600;">
                        View all
                    </a>
                `
                : `
                    <div
                        class="text-muted"
                        style="font-size:0.85rem;padding:6px;">
                        No notifications yet.
                    </div>
                `;
        }

        refreshNotifDot();

        notifBtn.addEventListener("click", event => {
            event.stopPropagation();

            const opening =
                notifDropdown.classList.contains("hidden");

            if (opening) {
                renderDropdown();

                if (
                    typeof Store.markAllNotificationsRead === "function"
                ) {
                    Store.markAllNotificationsRead(sessionUser.id);
                }

                refreshNotifDot();
            }

            notifDropdown.classList.toggle("hidden");
        });

        notifDropdown.addEventListener("click", event => {
            event.stopPropagation();
        });

        document.addEventListener("click", event => {
            if (
                !notifDropdown.contains(event.target) &&
                !notifBtn.contains(event.target)
            ) {
                notifDropdown.classList.add("hidden");
            }
        });
    }
}

/* ============================================
   COMPANY SIDEBAR
   ============================================ */

function renderCompanySidebar({
    active = "",
    base = ""
} = {}) {
    const mount = document.getElementById("site-sidebar");

    if (!mount) {
        return;
    }

    const items = [
        {
            tab: "overview",
            label: "Overview",
            icon: "document"
        },
        {
            tab: "opportunities",
            label: "My Opportunities",
            icon: "training"
        },
        {
            tab: "applicants",
            label: "Applicants",
            icon: "userMale"
        },
        {
            tab: "engagement",
            label: "Engagement",
            icon: "goal"
        },
        {
            tab: "reviews",
            label: "Reviews",
            icon: "favorite"
        },
        {
            tab: "profile",
            label: "Company Profile",
            icon: "company"
        }
    ];

    const activeItem = items.find(item => item.tab === active);

    mount.innerHTML = `
        <div class="sidebar">

            <button
                type="button"
                class="sidebar-toggle"
                id="sidebarToggle">
                ${iconHtml("menu", {
                    base,
                    sizePx: 16
                })}
                ${activeItem ? activeItem.label : "Menu"}
            </button>

            <nav class="sidebar-nav" id="sidebarNav">
                ${items.map(item => `
                    <a
                        href="${base}pages/dashboard-company.php?tab=${item.tab}"
                        class="${item.tab === active ? "active" : ""}">
                        ${iconHtml(item.icon, {
                            base,
                            sizePx: 16
                        })}
                        ${escapeHtml(item.label)}
                    </a>
                `).join("")}
            </nav>

        </div>
    `;

    const toggleBtn = document.getElementById("sidebarToggle");
    const nav = document.getElementById("sidebarNav");

    if (toggleBtn && nav) {
        toggleBtn.addEventListener("click", () => {
            nav.classList.toggle("open");
        });
    }
}

/* ============================================
   FOOTER
   ============================================ */

function renderFooter({ base = "" } = {}) {
    const mount = document.getElementById("site-footer");

    if (!mount) {
        return;
    }

    mount.innerHTML = `
        <footer class="site-footer">
            <div class="container">

                <div class="footer-grid">

                    <div>
                        <div class="footer-brand">Ufuq</div>

                        <p style="color:#A79FB8;font-size:0.88rem;">
                            Centralizing training programs and professional
                            courses for Saudi students and professionals in
                            computing fields.
                        </p>
                    </div>

                    <div>
                        <h4>Platform</h4>
                        <ul>
                            <li>
                                <a href="${base}pages/signup.php">
                                    For Companies
                                </a>
                            </li>
                            <li>
                                <a href="${base}index.php">Home</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4>Account</h4>
                        <ul>
                            <li>
                                <a href="${base}pages/login.php">Log In</a>
                            </li>
                            <li>
                                <a href="${base}pages/signup.php">Sign Up</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4>Support</h4>
                        <ul>
                            <li><a href="#">Help Center</a></li>
                            <li><a href="#">Contact Us</a></li>
                            <li><a href="#">Terms &amp; Privacy</a></li>
                        </ul>
                    </div>

                </div>

                <div
                    class="footer-bottom"
                    style="justify-content:center;text-align:center;">

                    <span>
                        &copy; 2026 Ufuq &mdash; IT496 Graduation Project
                    </span>

                </div>

            </div>
        </footer>
    `;
}

/* ============================================
   REMOVABLE CHIP INPUT
   ============================================ */

function initChipInput({
    inputId,
    addBtnId,
    listId,
    values,
    onChange,
    base = ""
}) {
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);

    const addBtn = addBtnId
        ? document.getElementById(addBtnId)
        : null;

    if (!input || !list || !Array.isArray(values)) {
        return;
    }

    function render() {
        list.innerHTML = values.map((value, index) => `
            <span
                class="badge badge-primary"
                style="display:inline-flex;align-items:center;gap:6px;margin:0 6px 6px 0;">

                ${escapeHtml(value)}

                <button
                    type="button"
                    data-remove-index="${index}"
                    aria-label="Remove ${escapeHtml(value)}"
                    style="background:none;border:none;color:inherit;cursor:pointer;padding:0;display:inline-flex;">
                    ${iconHtml("close", {
                        base,
                        sizePx: 12
                    })}
                </button>

            </span>
        `).join("") || `
            <span class="text-muted" style="font-size:0.85rem;">
                None added yet.
            </span>
        `;

        list.querySelectorAll("[data-remove-index]")
            .forEach(button => {
                button.addEventListener("click", () => {
                    values.splice(
                        Number(button.dataset.removeIndex),
                        1
                    );

                    render();

                    if (typeof onChange === "function") {
                        onChange(values);
                    }
                });
            });
    }

    function addValue() {
        const value = input.value.trim();

        if (!value) {
            return;
        }

        if (!values.includes(value)) {
            values.push(value);

            if (typeof onChange === "function") {
                onChange(values);
            }
        }

        input.value = "";
        render();
    }

    input.addEventListener("keydown", event => {
        if (event.key === "Enter") {
            event.preventDefault();
            addValue();
        }
    });

    if (addBtn) {
        addBtn.addEventListener("click", addValue);
    }

    render();
}

/* ============================================
   AUTH GUARD
   ============================================ */

/*
 * Client-side convenience guard only.
 * Every protected PHP page must independently
 * validate the PHP session and user's role.
 */

function requireRole(allowedRoles, { base = "" } = {}) {
    function checkRole() {
        const activeUser = getCurrentSessionUser();

        if (
            !activeUser ||
            !Array.isArray(allowedRoles) ||
            !allowedRoles.includes(activeUser.role)
        ) {
            window.location.href = `${base}pages/login.php`;
        }
    }

    checkRole();

    window.addEventListener("pageshow", checkRole);
}
