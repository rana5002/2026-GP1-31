/* ============================================
   Ufuq — Client-side data layer
   Persists data in the browser (localStorage) so
   the frontend works end-to-end before the
   PHP/MySQL backend exists. Every function here
   maps to a future API call, one function per
   action:

     Store.createUser(...)          -> POST /api/users
     Store.createCompany(...)       -> POST /api/companies
     Store.approveCompany(id)       -> POST /api/companies/:id/approve
     Store.createOpportunity(...)   -> POST /api/opportunities
     ...and so on.

   When the backend is ready, replace the bodies of
   these functions with fetch() calls — the pages
   that call Store.* shouldn't need to change.

   NOTE: passwords are stored in plain text here for
   front-end prototyping only. This is not acceptable
   once real user data is involved — the backend must
   hash passwords server-side (e.g. password_hash() in
   PHP) and never expose them to the client.
   ============================================ */

const Store = (() => {
  const DB_KEY = "ufuq_db";
  const SESSION_KEY = "ufuq_session";

  function _read() {
    const raw = localStorage.getItem(DB_KEY);
    if (raw) {
      const db = JSON.parse(raw);
      /* Migrate older saved databases that predate these collections. */
      let changed = false;
      ["favorites", "applications", "notifications", "reviews"].forEach(key => {
        if (!Array.isArray(db[key])) { db[key] = []; changed = true; }
      });
      if (changed) _write(db);
      return db;
    }
    const empty = { users: [], companies: [], admins: [], opportunities: [], favorites: [], applications: [], notifications: [], reviews: [] };
    localStorage.setItem(DB_KEY, JSON.stringify(empty));
    return empty;
  }
  function _write(db) {
    localStorage.setItem(DB_KEY, JSON.stringify(db));
  }
  function _nextId(list) {
    return list.reduce((max, item) => Math.max(max, item.id), 0) + 1;
  }

  /* Seed a single admin account on first load so there's a way into the
     admin dashboard before there's a real admin-provisioning flow. */
  function _ensureSeedAdmin() {
    const db = _read();
    if (!db.admins.length) {
      db.admins.push({ id: 1, role: "admin", name: "Admin", email: "admin@ufuq.sa", password: "Admin@123" });
      _write(db);
    }
  }
  _ensureSeedAdmin();

  /* ---------- Session ---------- */
  function login(id, role) {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify({ id, role }));
  }
  function logout() {
    sessionStorage.removeItem(SESSION_KEY);
  }
  function getSession() {
    const raw = sessionStorage.getItem(SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  }
  function getCurrentUser() {
    const session = getSession();
    if (!session) return null;
    const db = _read();
    const list = session.role === "user" ? db.users : session.role === "company" ? db.companies : db.admins;
    const record = list.find(u => u.id === session.id);
    return record ? { ...record, role: session.role } : null;
  }

  /* ---------- Users (students) ---------- */
  function findUserByEmail(email) {
    return _read().users.find(u => u.email.toLowerCase() === email.toLowerCase());
  }
  function createUser(data) {
    const db = _read();
    if (db.users.some(u => u.email.toLowerCase() === data.email.toLowerCase())) {
      return null; // "Email already registered"
    }
    const user = {
      id: _nextId(db.users),
      role: "user",
      name: data.name,
      email: data.email,
      phone: data.phone,
      educationalStatus: data.educationalStatus,
      fieldOfStudy: data.fieldOfStudy,
      password: data.password,
      age: data.age,
      gender: data.gender,
      profilePicture: data.profilePicture || "",
      experience: data.experience || "",
      qualifications: data.qualifications || "",
      skills: data.skills || [],
      createdAt: new Date().toISOString()
    };
    db.users.push(user);
    _write(db);
    return user;
  }
  function updateUser(id, patch) {
    const db = _read();
    const idx = db.users.findIndex(u => u.id === Number(id));
    if (idx === -1) return null;
    db.users[idx] = { ...db.users[idx], ...patch };
    _write(db);
    return db.users[idx];
  }
  function getAllUsers() {
    return _read().users;
  }

  /* ---------- Companies ---------- */
  function findCompanyByEmail(email) {
    return _read().companies.find(c => c.email.toLowerCase() === email.toLowerCase());
  }
  function createCompany(data) {
    const db = _read();
    const emailTaken = db.companies.some(c => c.email.toLowerCase() === data.email.toLowerCase());
    const crTaken = db.companies.some(c => c.crNumber === data.crNumber);
    if (emailTaken || crTaken) {
      return null; // "Company already registered"
    }
    const company = {
      id: _nextId(db.companies),
      role: "company",
      name: data.name,
      email: data.email,
      phone: data.phone,
      crNumber: data.crNumber,
      industry: data.industry,
      description: data.description,
      location: data.location,
      verificationDocName: data.verificationDocName || "",
      logoFileName: data.logoFileName || "",
      website: data.website || "",
      password: data.password,
      status: "pending",
      rejectionReason: "",
      createdAt: new Date().toISOString()
    };
    db.companies.push(company);
    _write(db);
    return company;
  }
  function updateCompany(id, patch) {
    const db = _read();
    const idx = db.companies.findIndex(c => c.id === Number(id));
    if (idx === -1) return null;
    db.companies[idx] = { ...db.companies[idx], ...patch };
    _write(db);
    return db.companies[idx];
  }
  function getAllCompanies() {
    return _read().companies;
  }
  function getCompanyById(id) {
    return _read().companies.find(c => c.id === Number(id));
  }
  function getPendingCompanies() {
    return _read().companies.filter(c => c.status === "pending");
  }
  function approveCompany(id) {
    return updateCompany(id, { status: "verified", rejectionReason: "" });
  }
  function rejectCompany(id, reason) {
    return updateCompany(id, { status: "rejected", rejectionReason: reason || "" });
  }

  /* ---------- Admins ---------- */
  function findAdminByEmail(email) {
    return _read().admins.find(a => a.email.toLowerCase() === email.toLowerCase());
  }

  /* ---------- Opportunities ---------- */
  function getAllOpportunities() {
    return _read().opportunities;
  }
  function getOpportunityById(id) {
    return _read().opportunities.find(o => o.id === Number(id));
  }
  function getOpportunitiesByCompany(companyId) {
    return _read().opportunities.filter(o => o.companyId === companyId);
  }
  function getOpportunitiesAddedByAdmin() {
    return _read().opportunities.filter(o => o.addedBy === "admin");
  }
  function createOpportunity(data) {
    const db = _read();
    const opp = {
      id: _nextId(db.opportunities),
      title: data.title,
      description: data.description,
      type: data.type,
      requiredSkills: data.requiredSkills || [],
      duration: data.duration,
      startDate: data.startDate,
      endDate: data.endDate,
      applicationDeadline: data.applicationDeadline,
      qualifications: data.qualifications || "",
      experience: data.experience || "",
      location: data.location,
      applicationMethod: data.applicationMethod,
      externalUrl: data.externalUrl || "",
      status: data.status || "Open",
      addedBy: data.addedBy,
      companyId: data.companyId || null,
      companyNameManual: data.companyNameManual || "",
      createdAt: new Date().toISOString()
    };
    db.opportunities.push(opp);
    _write(db);
    return opp;
  }
  function updateOpportunity(id, patch) {
    const db = _read();
    const idx = db.opportunities.findIndex(o => o.id === Number(id));
    if (idx === -1) return null;
    db.opportunities[idx] = { ...db.opportunities[idx], ...patch };
    _write(db);
    return db.opportunities[idx];
  }
  function deleteOpportunity(id) {
    const db = _read();
    db.opportunities = db.opportunities.filter(o => o.id !== Number(id));
    _write(db);
  }

  /* ---------- Profile pictures ----------
     Actual image files live outside this repo's control — drop them in
     at images-website/male pic.png and images-website/female pic.png. */
  function getProfilePicFilename(gender) {
    if (gender === "Male") return "default-avatar-male.png";
    if (gender === "Female") return "default-avatar-female.png";
    return null;
  }

  /* ---------- Favorites (user saves an opportunity) ---------- */
  function getFavoriteIds(userId) {
    return _read().favorites.filter(f => f.userId === Number(userId)).map(f => f.opportunityId);
  }
  function getFavoriteOpportunities(userId) {
    const ids = getFavoriteIds(userId);
    return _read().opportunities.filter(o => ids.includes(o.id));
  }
  function isFavorite(userId, opportunityId) {
    return _read().favorites.some(f => f.userId === Number(userId) && f.opportunityId === Number(opportunityId));
  }
  function toggleFavorite(userId, opportunityId) {
    const db = _read();
    const idx = db.favorites.findIndex(f => f.userId === Number(userId) && f.opportunityId === Number(opportunityId));
    let nowFavorite;
    if (idx === -1) {
      db.favorites.push({ id: _nextId(db.favorites), userId: Number(userId), opportunityId: Number(opportunityId), createdAt: new Date().toISOString() });
      nowFavorite = true;
    } else {
      db.favorites.splice(idx, 1);
      nowFavorite = false;
    }
    _write(db);
    return nowFavorite;
  }

  /* ---------- Applications (user applies through the system) ---------- */
  function hasApplied(userId, opportunityId) {
    return _read().applications.some(a => a.userId === Number(userId) && a.opportunityId === Number(opportunityId));
  }
  function applyToOpportunity(userId, opportunityId) {
    const db = _read();
    if (db.applications.some(a => a.userId === Number(userId) && a.opportunityId === Number(opportunityId))) {
      return null; // already applied
    }
    const application = {
      id: _nextId(db.applications),
      userId: Number(userId),
      opportunityId: Number(opportunityId),
      status: "Submitted",
      appliedAt: new Date().toISOString()
    };
    db.applications.push(application);
    _write(db);
    return application;
  }
  function getApplicationsForUser(userId) {
    const db = _read();
    return db.applications
      .filter(a => a.userId === Number(userId))
      .map(a => ({ ...a, opportunity: db.opportunities.find(o => o.id === a.opportunityId) || null }))
      .sort((a, b) => new Date(b.appliedAt) - new Date(a.appliedAt));
  }
  function getApplicationsForOpportunity(opportunityId) {
    const db = _read();
    return db.applications
      .filter(a => a.opportunityId === Number(opportunityId))
      .map(a => ({ ...a, user: db.users.find(u => u.id === a.userId) || null }));
  }
  function getApplicationsForCompany(companyId) {
    const db = _read();
    const oppIds = db.opportunities.filter(o => o.companyId === Number(companyId)).map(o => o.id);
    return db.applications
      .filter(a => oppIds.includes(a.opportunityId))
      .map(a => ({
        ...a,
        user: db.users.find(u => u.id === a.userId) || null,
        opportunity: db.opportunities.find(o => o.id === a.opportunityId) || null
      }))
      .sort((a, b) => new Date(b.appliedAt) - new Date(a.appliedAt));
  }
  function updateApplicationStatus(id, status) {
    const db = _read();
    const idx = db.applications.findIndex(a => a.id === Number(id));
    if (idx === -1) return null;
    db.applications[idx].status = status;
    _write(db);
    return db.applications[idx];
  }

  /* ---------- Notifications ---------- */
  function createNotification(userId, role, message, link) {
    const db = _read();
    db.notifications.push({
      id: _nextId(db.notifications),
      userId: Number(userId),
      role,
      message,
      link: link || "",
      read: false,
      createdAt: new Date().toISOString()
    });
    _write(db);
  }
  function getNotifications(userId) {
    return _read().notifications
      .filter(n => n.userId === Number(userId))
      .sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  }
  function getUnreadNotificationCount(userId) {
    return _read().notifications.filter(n => n.userId === Number(userId) && !n.read).length;
  }
  function markNotificationRead(id) {
    const db = _read();
    const idx = db.notifications.findIndex(n => n.id === Number(id));
    if (idx === -1) return;
    db.notifications[idx].read = true;
    _write(db);
  }
  function markAllNotificationsRead(userId) {
    const db = _read();
    db.notifications.forEach(n => { if (n.userId === Number(userId)) n.read = true; });
    _write(db);
  }

  /* ---------- Reviews (user reviews a company/opportunity) ---------- */
  function createReview(userId, companyId, rating, comment) {
    const db = _read();
    const review = {
      id: _nextId(db.reviews),
      userId: Number(userId),
      companyId: Number(companyId),
      rating: Number(rating),
      comment: comment || "",
      createdAt: new Date().toISOString()
    };
    db.reviews.push(review);
    _write(db);
    return review;
  }
  function getReviewsForCompany(companyId) {
    const db = _read();
    return db.reviews
      .filter(r => r.companyId === Number(companyId))
      .map(r => ({ ...r, user: db.users.find(u => u.id === r.userId) || null }))
      .sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  }
  function getCompanyAverageRating(companyId) {
    const reviews = _read().reviews.filter(r => r.companyId === Number(companyId));
    if (!reviews.length) return null;
    return reviews.reduce((sum, r) => sum + r.rating, 0) / reviews.length;
  }

  /* ---------- Engagement (view counts on a company's opportunities) ---------- */
  function recordOpportunityView(opportunityId) {
    const db = _read();
    const idx = db.opportunities.findIndex(o => o.id === Number(opportunityId));
    if (idx === -1) return;
    db.opportunities[idx].views = (db.opportunities[idx].views || 0) + 1;
    _write(db);
  }
  function getEngagementForCompany(companyId) {
    const db = _read();
    const opps = db.opportunities.filter(o => o.companyId === Number(companyId));
    return opps.map(o => ({
      opportunityId: o.id,
      title: o.title,
      views: o.views || 0,
      applications: db.applications.filter(a => a.opportunityId === o.id).length,
      favorites: db.favorites.filter(f => f.opportunityId === o.id).length
    }));
  }

  /* ---------- Profile completion (user home dashboard stat) ---------- */
  function getProfileCompletion(user) {
    if (!user) return 0;
    const fields = [user.profilePicture, user.experience, user.qualifications, (user.skills || []).length > 0, user.phone, user.educationalStatus, user.fieldOfStudy];
    const filled = fields.filter(Boolean).length;
    return Math.round((filled / fields.length) * 100);
  }

  /* ---------- Upcoming deadlines ----------
     Reminders for opportunities the user has saved to Favorites but hasn't
     applied to yet — the ones where a deadline reminder is actually useful. */
  function getUpcomingDeadlinesForUser(userId) {
    const now = new Date();
    return getFavoriteOpportunities(userId)
      .filter(o => o.status === "Open" && o.applicationDeadline && new Date(o.applicationDeadline) >= now)
      .filter(o => !hasApplied(userId, o.id))
      .sort((a, b) => new Date(a.applicationDeadline) - new Date(b.applicationDeadline))
      .map(o => ({ opportunity: o }));
  }

  return {
    login, logout, getSession, getCurrentUser,
    getProfileCompletion, getUpcomingDeadlinesForUser,
    getProfilePicFilename,
    findUserByEmail, createUser, updateUser, getAllUsers,
    findCompanyByEmail, createCompany, updateCompany, getAllCompanies, getCompanyById,
    getPendingCompanies, approveCompany, rejectCompany,
    findAdminByEmail,
    createOpportunity, updateOpportunity, deleteOpportunity, getOpportunityById,
    getOpportunitiesByCompany, getOpportunitiesAddedByAdmin, getAllOpportunities,
    getFavoriteIds, getFavoriteOpportunities, isFavorite, toggleFavorite,
    hasApplied, applyToOpportunity, getApplicationsForUser, getApplicationsForOpportunity,
    getApplicationsForCompany, updateApplicationStatus,
    createNotification, getNotifications, getUnreadNotificationCount,
    markNotificationRead, markAllNotificationsRead,
    createReview, getReviewsForCompany, getCompanyAverageRating,
    recordOpportunityView, getEngagementForCompany
  };
})();
