/* ============================================
   Ufuq — Shared layout components
   Injects the navbar and footer into any page
   that includes this script plus two empty
   containers: <div id="site-header"></div> and
   <div id="site-footer"></div>.

   Usage on a page (after store.js is loaded):
     <script src="js/store.js"></script>          (or "../js/store.js" from /pages)
     <script src="js/constants.js"></script>
     <script src="js/components.js"></script>
     <script>
       renderHeader({ active: "home", base: "" });   // "" at root, "../" inside /pages
       renderFooter({ base: "" });
     </script>

   The header reads the signed-in session from Store
   itself, so pages don't need to pass a role/user in.
   ============================================ */

/* Builds a mask-based icon span for one of the 25 PNGs in
   images-website/icons/. `base` must match the page's depth:
   "" from index.php, "../" from anything under /pages. */
function iconHtml(name, { sizePx = 18, base = "", extraClass = "" } = {}) {
  const path = `${base}images-website/icons/${name}.png`;
  return `<span class="icon ${extraClass}" style="width:${sizePx}px;height:${sizePx}px;-webkit-mask-image:url('${path}');mask-image:url('${path}');"></span>`;
}

function navLinksFor(role, base) {
  const links = {
    guest: [
      { label: "Home", href: `${base}index.php`, nav: "home" },
      { label: "Opportunities", href: `${base}index.php#opportunities`, nav: "" },
      { label: "About Us", href: `${base}pages/about.php`, nav: "about" }
    ],
    user: [
      { label: "Home", href: `${base}pages/home.php`, nav: "user-home" },
      { label: "Browse", href: `${base}pages/browse.php`, nav: "browse" },
      { label: "Skill Gap Analysis", href: `${base}pages/skill-gap.php`, nav: "skill-gap" },
      { label: "Application History", href: `${base}pages/application-history.php`, nav: "history" }
    ],
    /* Company navigation lives in the dashboard sidebar now — the header
       stays free for the Ufuq brand + the company's own logo, centered. */
    company: [],
    admin: [
      { label: "Company Verification", href: `${base}pages/dashboard-admin.php?tab=verification`, nav: "verification" },
      { label: "Manage Opportunities", href: `${base}pages/dashboard-admin.php?tab=opportunities`, nav: "opportunities" }
    ]
  };
  return links[role] || links.guest;
}

/* Picks a consistent accent color for a company/opportunity "logo" chip so
   cards don't all read as one flat lavender block. Same name -> same color. */
const OPP_LOGO_COLORS = ["#8E789F", "#557D68", "#A27B43", "#6A7FA3", "#A35D64"];
function colorFor(name) {
  const str = name || "U";
  let hash = 0;
  for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
  return OPP_LOGO_COLORS[Math.abs(hash) % OPP_LOGO_COLORS.length];
}

/* Builds an avatar — a gender picture for a user who has one set, initials otherwise.
   `sizePx` lets callers reuse this at different sizes (navbar vs. sidebar). */
function avatarHtml(person, { base = "", sizePx = 38, fontPx = 0.9 } = {}) {
  const initials = (person.name || "U").split(" ").map(w => w[0]).slice(0, 2).join("").toUpperCase();
  if (person.role === "user" && person.gender) {
    const filename = Store.getProfilePicFilename(person.gender);
    if (filename) {
      return `<img src="${base}images-website/${filename}" alt="${person.name}" style="width:${sizePx}px;height:${sizePx}px;border-radius:50%;object-fit:cover;" title="${person.name}">`;
    }
  }
  return `<span style="width:${sizePx}px;height:${sizePx}px;font-size:${fontPx}rem;" class="nav-avatar" title="${person.name}">${initials}</span>`;
}

function renderHeader({ active = "", base = "" } = {}) {
  const mount = document.getElementById("site-header");
  if (!mount) return;

  const current = Store.getCurrentUser();
  const role = current ? current.role : "guest";
  const links = navLinksFor(role, base);
  const linksHtml = links.map(l =>
    `<a href="${l.href}" class="${l.nav === active ? "active" : ""}">${l.label}</a>`
  ).join("");

  let actionsHtml = "";
  if (!current) {
    actionsHtml = `
      <a href="${base}pages/login.php" class="btn btn-ghost btn-sm">Log In</a>
      <a href="${base}pages/signup.php" class="btn btn-primary btn-sm">Sign Up</a>
    `;
  } else {
    const profileHref = role === "user" ? `${base}pages/dashboard-user.php`
      : role === "company" ? `${base}pages/dashboard-company.php?tab=profile`
      : `${base}pages/dashboard-admin.php`;
    const favoriteBtn = role === "user"
      ? `<a class="btn btn-ghost btn-sm" href="${base}pages/favorites.php" title="Favorites" style="padding:8px;">
          ${iconHtml("favorite", { base })}
        </a>`
      : "";
    actionsHtml = `
      ${favoriteBtn}
      <div style="position:relative;">
        <button class="btn btn-ghost btn-sm" id="notifBtn" title="Notifications" style="padding:8px;">
          ${iconHtml("notification", { base })}
          <span id="notifDot" class="hidden" style="position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:var(--color-danger);"></span>
        </button>
        <div id="notifDropdown" class="hidden card" style="position:absolute;right:0;top:calc(100% + 8px);width:300px;padding:var(--space-3);z-index:200;"></div>
      </div>
      <button class="btn btn-ghost btn-sm" id="logoutBtn">Log Out</button>
      <a href="${profileHref}" style="display:inline-flex;">${avatarHtml(current, { base })}</a>
    `;
  }

  /* Company header centerpiece: both logos together, never one replacing
     the other — Ufuq's brand stays on the left, the company's own logo
     (once uploaded) sits centered in the header. */
  const companyNameSpan = current ? `<span class="company-header-name" style="font-family:var(--font-body); font-weight:700; font-size:0.95rem; letter-spacing:0.01em; color:var(--color-text);">${current.name}</span>` : "";
  const centerHtml = role === "company"
    ? `<div class="site-header-center">
        ${current.logoFileName
          /* There's no real file storage behind this yet — only the filename
             is saved, not the actual image — so if it can't load, fall back
             to the company name instead of showing a broken image icon
             (handled in JS below via an error listener, not an inline
             handler, since the attribute quoting breaks on names/styles
             that contain a double quote). */
          ? `<img id="companyHeaderLogo" src="${base}images-website/${current.logoFileName}" alt="${current.name}" style="height:32px;max-width:160px;object-fit:contain;">`
          : companyNameSpan}
      </div>`
    : `<nav class="nav-links" id="navLinks">${linksHtml}</nav>`;

  mount.innerHTML = `
    <header class="site-header">
      <div class="container">
        <a href="${base}index.php" class="brand" style="text-decoration:none;">
          <img src="${base}images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent;">
          Ufuq
        </a>
        ${centerHtml}
        <div class="nav-actions">
          ${actionsHtml}
          ${role !== "company" ? `<button class="nav-toggle" id="navToggle" aria-label="Toggle menu">${iconHtml("menu", { base, sizePx: 22 })}</button>` : ""}
        </div>
      </div>
    </header>
  `;

  const toggle = document.getElementById("navToggle");
  const navLinks = document.getElementById("navLinks");
  if (toggle && navLinks) {
    toggle.addEventListener("click", () => navLinks.classList.toggle("open"));
  }
  const companyLogoImg = document.getElementById("companyHeaderLogo");
  if (companyLogoImg) {
    companyLogoImg.addEventListener("error", () => {
      companyLogoImg.outerHTML = companyNameSpan;
    });
  }
  const logoutBtn = document.getElementById("logoutBtn");
  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      Store.logout();
      window.location.href = `${base}index.php`;
    });
  }

  /* ---------- Notification bell dropdown ---------- */
  const notifBtn = document.getElementById("notifBtn");
  const notifDropdown = document.getElementById("notifDropdown");
  if (notifBtn && notifDropdown && current) {
    function refreshNotifDot() {
      const unread = Store.getUnreadNotificationCount(current.id);
      document.getElementById("notifDot").classList.toggle("hidden", unread === 0);
    }
    function renderDropdown() {
      const items = Store.getNotifications(current.id).slice(0, 3);
      notifDropdown.innerHTML = items.length
        ? items.map(n => `
            <a href="${n.link ? base + n.link : "#"}" style="display:block;padding:8px 6px;border-bottom:1px solid var(--color-border);font-size:0.85rem;color:${n.read ? "var(--color-text-muted)" : "var(--color-text)"};font-weight:${n.read ? 400 : 600};">
              ${n.message}
            </a>
          `).join("") + `<a href="${base}pages/notifications.php" style="display:block;text-align:center;padding:8px 0 0;font-size:0.82rem;font-weight:600;">View all</a>`
        : `<div class="text-muted" style="font-size:0.85rem;padding:6px;">No notifications yet.</div>`;
    }
    refreshNotifDot();
    notifBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      const opening = notifDropdown.classList.contains("hidden");
      if (opening) {
        renderDropdown();
        Store.markAllNotificationsRead(current.id);
        refreshNotifDot();
      }
      notifDropdown.classList.toggle("hidden");
    });
    document.addEventListener("click", (e) => {
      if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
        notifDropdown.classList.add("hidden");
      }
    });
  }
}

/* Renders a dashboard sidebar for the company role into #site-sidebar.
   Pages that want it must include <div id="site-sidebar"></div> wrapped
   together with their main content in a `.with-sidebar` grid. */
function renderCompanySidebar({ active = "", base = "" } = {}) {
  const mount = document.getElementById("site-sidebar");
  if (!mount) return;
  const items = [
    { tab: "overview", label: "Overview", icon: "document" },
    { tab: "opportunities", label: "My Opportunities", icon: "training" },
    { tab: "applicants", label: "Applicants", icon: "userMale" },
    { tab: "engagement", label: "Engagement", icon: "goal" },
    { tab: "reviews", label: "Reviews", icon: "favorite" },
    { tab: "profile", label: "Company Profile", icon: "company" }
  ];
  const activeItem = items.find(i => i.tab === active);
  mount.innerHTML = `
    <div class="sidebar">
      <button type="button" class="sidebar-toggle" id="sidebarToggle">
        ${iconHtml("menu", { base, sizePx: 16 })} ${activeItem ? activeItem.label : "Menu"}
      </button>
      <nav class="sidebar-nav" id="sidebarNav">
        ${items.map(i => `
          <a href="dashboard-company.php?tab=${i.tab}" class="${i.tab === active ? "active" : ""}">
            ${iconHtml(i.icon, { base, sizePx: 16 })} ${i.label}
          </a>
        `).join("")}
      </nav>
    </div>
  `;
  const toggleBtn = document.getElementById("sidebarToggle");
  const nav = document.getElementById("sidebarNav");
  if (toggleBtn && nav) {
    toggleBtn.addEventListener("click", () => nav.classList.toggle("open"));
  }
}

function renderFooter({ base = "" } = {}) {
  const mount = document.getElementById("site-footer");
  if (!mount) return;

  mount.innerHTML = `
    <footer class="site-footer">
      <div class="container">
        <div class="footer-grid">
          <div>
            <div class="footer-brand">Ufuq</div>
            <p style="color:#A79FB8; font-size:0.88rem;">
              Centralizing training programs and professional courses for Saudi students and professionals in computing fields.
            </p>
          </div>
          <div>
            <h4>Platform</h4>
            <ul>
              <li><a href="${base}pages/signup.php">For Companies</a></li>
              <li><a href="${base}index.php">Home</a></li>
            </ul>
          </div>
          <div>
            <h4>Account</h4>
            <ul>
              <li><a href="${base}pages/login.php">Log In</a></li>
              <li><a href="${base}pages/signup.php">Sign Up</a></li>
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
        <div class="footer-bottom">
          <span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span>
          <span>Built with HTML, CSS &amp; JavaScript</span>
        </div>
      </div>
    </footer>
  `;
}

/* ---------- Small shared UI helpers ---------- */

/* Reusable removable-chip input: type a value, press Enter or click Add.
   Renders into `listId` and keeps `values` (an array) in sync. */
function initChipInput({ inputId, addBtnId, listId, values, onChange, base = "" }) {
  const input = document.getElementById(inputId);
  const list = document.getElementById(listId);
  const addBtn = addBtnId ? document.getElementById(addBtnId) : null;

  function render() {
    list.innerHTML = values.map((v, i) => `
      <span class="badge badge-primary" style="display:inline-flex; align-items:center; gap:6px; margin:0 6px 6px 0;">
        ${v}
        <button type="button" data-remove-index="${i}" style="background:none;border:none;color:inherit;cursor:pointer;padding:0;display:inline-flex;">
          ${iconHtml("close", { base, sizePx: 12 })}
        </button>
      </span>
    `).join("") || `<span class="text-muted" style="font-size:0.85rem;">None added yet.</span>`;

    list.querySelectorAll("[data-remove-index]").forEach(btn => {
      btn.addEventListener("click", () => {
        values.splice(Number(btn.dataset.removeIndex), 1);
        render();
        onChange(values);
      });
    });
  }

  function addValue() {
    const val = input.value.trim();
    if (!val) return;
    if (!values.includes(val)) {
      values.push(val);
      onChange(values);
    }
    input.value = "";
    render();
  }

  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); addValue(); }
  });
  if (addBtn) addBtn.addEventListener("click", addValue);

  render();
}

/* Auth guard for protected pages — call at the top of the page's script,
   AND on `pageshow` so the browser back-button after logout can't show a
   stale bfcache'd page. Redirects to login.php if the session role
   doesn't match `allowedRoles`. */
function requireRole(allowedRoles, { base = "" } = {}) {
  function check() {
    const current = Store.getCurrentUser();
    if (!current || !allowedRoles.includes(current.role)) {
      window.location.href = `${base}login.php`;
    }
  }
  check();
  window.addEventListener("pageshow", check);
}
