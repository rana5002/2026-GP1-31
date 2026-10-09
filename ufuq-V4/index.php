<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ufuq &mdash; Find Your Next Training Program or Course</title>
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/layout.css">
<link rel="stylesheet" href="css/icons.css">
</head>
<body>

<div id="site-header"></div>

<main>
  <!-- Hero -->
  <section class="hero-split">
    <div class="container hero-split-grid">
      <div>
        <div class="hero-eyebrow">Saudi Career Development</div>
        <h1>Your next step starts with <span style="color:var(--color-primary);">Ufuq</span>.</h1>
        <p style="font-size:1.05rem;">Discover training opportunities and professional courses, evaluate their details and experiences, and take the next step &mdash; all from one organized platform.</p>
        <div class="flex gap-3" style="margin-top: 28px; flex-wrap: wrap;">
          <a href="#opportunities" class="btn btn-primary">Explore Opportunities</a>
        </div>
      </div>
      <div class="hero-visual">
        <div class="hero-visual-circle">
          <img src="images-website/logo.png" alt="Ufuq" style="width:120px;height:120px;object-fit:contain;">
        </div>
        <div class="hero-floating-card" style="top:8%; right:0;">
          <div class="ft-title">Smart discovery</div>
          <div class="ft-body">Recommendations based on your profile.</div>
        </div>
        <div class="hero-floating-card" style="bottom:6%; left:-6%;">
          <div class="ft-title">One organized place</div>
          <div class="ft-body">Explore, compare, save and apply.</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Opportunities preview (training programs + courses together) -->
  <section class="preview-section" id="opportunities">
    <div class="container">
      <div class="preview-section-header">
        <div>
          <div class="preview-eyebrow">Explore</div>
          <h2 style="margin:0;">Opportunities</h2>
          <p style="margin:4px 0 0;">Training opportunities and courses will appear here when published.</p>
        </div>
        <a href="pages/login.php">View all &rarr;</a>
      </div>
      <div id="oppPreviewGrid" class="grid grid-3"></div>
      <div class="preview-empty hidden" id="oppPreviewEmpty">
        <h3>No opportunities available yet</h3>
        <p style="margin:0;">There are currently no published opportunities. Check back later.</p>
      </div>
    </div>
  </section>

  <!-- Courses -->
  <section class="section courses-section" id="courses">
    <div class="container">
            <div class="preview-section-header">

      <div class="courses-head">
        <div class="preview-eyebrow">Learn</div>
        <h2>Courses</h2>
        <p>Professional courses will appear here when published.</p>
      </div>
      <a href="pages/login.php">View all &rarr;</a>
      </div>
      <div id="coursesPreviewGrid" class="grid grid-3"></div>
      <div class="courses-empty hidden" id="coursesPreviewEmpty">
        <h3>No courses available yet</h3>
        <p style="margin:0;">There are currently no published courses. Check back later.</p>
      </div>
    </div>
  </section>

  <!-- Value props -->
  <section class="section section-journey">
    <div class="container">
      <h2 class="text-center" style="margin-bottom: 40px;">Built for computing students &mdash; and the companies training them</h2>
      <div class="grid grid-3">
        <div class="card text-center">
          <div class="flex-center" style="margin-bottom:12px;">
            <span class="icon icon-primary" style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/training.png');mask-image:url('images-website/icons/training.png');"></span>
          </div>
          <h3>One Place for Opportunities</h3>
          <p>Training programs and courses from verified companies, gathered in a single platform.</p>
        </div>
        <div class="card text-center">
          <div class="flex-center" style="margin-bottom:12px;">
            <span class="icon icon-primary" style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/company.png');mask-image:url('images-website/icons/company.png');"></span>
          </div>
          <h3>Verified Companies</h3>
          <p>Every company account is reviewed by an admin before it can publish opportunities.</p>
        </div>
        <div class="card text-center">
          <div class="flex-center" style="margin-bottom:12px;">
            <span class="icon icon-primary" style="width:30px;height:30px;-webkit-mask-image:url('images-website/icons/goal.png');mask-image:url('images-website/icons/goal.png');"></span>
          </div>
          <h3>A Profile That Follows You</h3>
          <p>Keep your field of study, skills and experience in one profile, ready to grow with the platform.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA for companies -->
  <section class="section section-journey">
    <div class="container">
      <div class="card cta-card flex-between" style="flex-wrap: wrap; gap: 20px;">
        <div>
          <h3 style="margin-bottom:4px;">Are you offering a training program or course?</h3>
          <p style="margin:0;">Register your company to publish opportunities and reach students across Saudi Arabia.</p>
        </div>
        <a href="pages/signup.php" class="btn btn-primary">Register Your Company</a>
      </div>
    </div>
  </section>
</main>

<div id="site-footer"></div>

<script src="js/store.js"></script>
<script src="js/constants.js"></script>
<script src="js/components.js"></script>
<script>
  /* Signed-in visitors land on their own home/dashboard, not the guest page. */
  const session = Store.getSession();
  if (session) {
    const dest = session.role === "user" ? "pages/home.php"
      : session.role === "company" ? "pages/dashboard-company.php"
      : "pages/dashboard-admin.php";
    window.location.href = dest;
  }

  renderHeader({ active: "home", base: "" });
  renderFooter({ base: "" });

  function companyNameFor(o) {
    if (o.companyId) {
      const c = Store.getCompanyById(o.companyId);
      return c ? c.name : "Unknown Company";
    }
    return o.companyNameManual || "External Company";
  }

  function previewCardHtml(o) {
    return `
      <div class="card opp-card card-hover">
        <div class="opp-card-top">
          <div class="flex gap-3" style="align-items:center;">
            <div class="opp-logo" style="background:${colorFor(companyNameFor(o))};">${companyNameFor(o).slice(0, 1).toUpperCase()}</div>
            <div>
              <h3 style="margin:0 0 2px; font-size:1.02rem;">${o.title}</h3>
              <div class="text-muted" style="font-size:0.82rem;">${companyNameFor(o)}</div>
            </div>
          </div>
        </div>
        <div class="opp-meta">
          <span class="badge ${o.type === "Course" ? "badge-success" : "badge-primary"}">${o.type}</span>
          <span>${o.location}</span>
        </div>
        <div class="opp-footer">
          <a class="btn btn-outline btn-sm" href="pages/login.php">View Details</a>
        </div>
      </div>
    `;
  }

  /* Opportunities preview */
  const preview = Store.getAllOpportunities().filter(o => o.status === "Open").slice(0, 3);

  document.getElementById("oppPreviewGrid").innerHTML = preview.map(previewCardHtml).join("");
  document.getElementById("oppPreviewGrid").classList.toggle("hidden", preview.length === 0);
  document.getElementById("oppPreviewEmpty").classList.toggle("hidden", preview.length > 0);

  /* Courses preview */
  const coursesPreview = Store.getAllOpportunities()
    .filter(o => o.status === "Open" && o.type === "Course")
    .slice(0, 3);

  document.getElementById("coursesPreviewGrid").innerHTML = coursesPreview.map(previewCardHtml).join("");
  document.getElementById("coursesPreviewGrid").classList.toggle("hidden", coursesPreview.length === 0);
  document.getElementById("coursesPreviewEmpty").classList.toggle("hidden", coursesPreview.length > 0);
</script>

</body>
</html>