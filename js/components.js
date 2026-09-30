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
   "" from index.html, "../" from anything under /pages. */
function iconHtml(name, { sizePx = 18, base = "", extraClass = "" } = {}) {
  const path = `${base}images-website/icons/${name}.png`;
  return `<span class="icon ${extraClass}" style="width:${sizePx}px;height:${sizePx}px;-webkit-mask-image:url('${path}');mask-image:url('${path}');"></span>`;
}

function navLinksFor(role, base) {
  const links = {
    guest: [
      { label: "Home", href: `${base}index.html`, nav: "home" }
    ],
    user: [
      { label: "Browse", href: `${base}pages/browse.html`, nav: "browse" },
      { label: "Skill Gap Analysis", href: `${base}pages/coming-soon.html?feature=${encodeURIComponent("Skill Gap Analysis")}`, nav: "skill-gap" },
      { label: "Application History", href: `${base}pages/coming-soon.html?feature=${encodeURIComponent("Application History")}`, nav: "history" }
    ],
    company: [
      { label: "My Opportunities", href: `${base}pages/dashboard-company.html?tab=opportunities`, nav: "opportunities" },
      { label: "Company Profile", href: `${base}pages/dashboard-company.html?tab=profile`, nav: "profile" }
    ],
    admin: [
      { label: "Company Verification", href: `${base}pages/dashboard-admin.html?tab=verification`, nav: "verification" },
      { label: "Manage Opportunities", href: `${base}pages/dashboard-admin.html?tab=opportunities`, nav: "opportunities" }
    ]
  };
  return links[role] || links.guest;
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
      <a href="${base}pages/login.html" class="btn btn-ghost btn-sm">Log In</a>
      <a href="${base}pages/signup.html" class="btn btn-primary btn-sm">Sign Up</a>
    `;
  } else {
    const profileHref = role === "user" ? `${base}pages/dashboard-user.html`
      : role === "company" ? `${base}pages/dashboard-company.html?tab=profile`
      : `${base}pages/dashboard-admin.html`;
    const favoriteBtn = role === "user"
      ? `<a class="btn btn-ghost btn-sm" href="${base}pages/coming-soon.html?feature=${encodeURIComponent("Favorites")}" title="Favorites" style="padding:8px;">
          ${iconHtml("favorite", { base })}
        </a>`
      : "";
    actionsHtml = `
      ${favoriteBtn}
      <button class="btn btn-ghost btn-sm" id="notifBtn" title="Notifications" style="padding:8px;">
        ${iconHtml("notification", { base })}
      </button>
      <button class="btn btn-ghost btn-sm" id="logoutBtn">Log Out</button>
      <a href="${profileHref}" style="display:inline-flex;">${avatarHtml(current, { base })}</a>
    `;
  }

  mount.innerHTML = `
    <header class="site-header">
      <div class="container">
        <a href="${base}index.html" class="brand" style="text-decoration:none;">
          <img src="${base}images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent;">
          Ufuq
        </a>
        <nav class="nav-links" id="navLinks">${linksHtml}</nav>
        <div class="nav-actions">
          ${actionsHtml}
          <button class="nav-toggle" id="navToggle" aria-label="Toggle menu">${iconHtml("menu", { base, sizePx: 22 })}</button>
        </div>
      </div>
    </header>
  `;

  const toggle = document.getElementById("navToggle");
  const navLinks = document.getElementById("navLinks");
  if (toggle && navLinks) {
    toggle.addEventListener("click", () => navLinks.classList.toggle("open"));
  }
  const logoutBtn = document.getElementById("logoutBtn");
  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      Store.logout();
      window.location.href = `${base}index.html`;
    });
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
              <li><a href="${base}pages/signup.html">For Companies</a></li>
              <li><a href="${base}index.html">Home</a></li>
            </ul>
          </div>
          <div>
            <h4>Account</h4>
            <ul>
              <li><a href="${base}pages/login.html">Log In</a></li>
              <li><a href="${base}pages/signup.html">Sign Up</a></li>
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
   stale bfcache'd page. Redirects to login.html if the session role
   doesn't match `allowedRoles`. */
function requireRole(allowedRoles, { base = "" } = {}) {
  function check() {
    const current = Store.getCurrentUser();
    if (!current || !allowedRoles.includes(current.role)) {
      window.location.href = `${base}login.html`;
    }
  }
  check();
  window.addEventListener("pageshow", check);
}
