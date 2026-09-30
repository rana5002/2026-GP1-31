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
    if (raw) return JSON.parse(raw);
    const empty = { users: [], companies: [], admins: [], opportunities: [] };
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

  return {
    login, logout, getSession, getCurrentUser,
    getProfilePicFilename,
    findUserByEmail, createUser, updateUser, getAllUsers,
    findCompanyByEmail, createCompany, updateCompany, getAllCompanies, getCompanyById,
    getPendingCompanies, approveCompany, rejectCompany,
    findAdminByEmail,
    createOpportunity, updateOpportunity, deleteOpportunity, getOpportunityById,
    getOpportunitiesByCompany, getOpportunitiesAddedByAdmin, getAllOpportunities
  };
})();
