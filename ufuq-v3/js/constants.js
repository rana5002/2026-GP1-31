/* ============================================
   Ufuq — Shared taxonomy constants
   Fixed reference lists used across forms.
   Move these to the backend once it exists (e.g.
   served from a /taxonomy endpoint or a database
   lookup table) so both sides stay in sync.
   ============================================ */

/* "Other" is always the last entry — the UI reveals a free-text field for it. */
const FIELDS_OF_STUDY = [
  "Computer Science",
  "Information Technology",
  "Computer Engineering",
  "Software Engineering",
  "Information Systems",
  "Cybersecurity",
  "Data Science / Artificial Intelligence",
  "Network Engineering",
  "Other"
];

/* "Other" is always last — the UI reveals a free-text field for it. */
const SKILL_OPTIONS = [
  "HTML/CSS",
  "JavaScript",
  "Python",
  "Java",
  "SQL / Databases",
  "UI/UX Design",
  "Networking",
  "Cybersecurity Basics",
  "Other"
];

const EDUCATIONAL_STATUSES = ["Student", "Fresh Graduate", "Employed"];

const GENDERS = ["Male", "Female"];

const INDUSTRY_SECTORS = [
  "Information Technology",
  "Telecommunications",
  "Banking & Finance",
  "Education & Training",
  "Healthcare",
  "Government",
  "Retail & E-commerce",
  "Manufacturing",
  "Other"
];

const CITIES = [
  "Riyadh",
  "Jeddah",
  "Mecca",
  "Medina",
  "Dammam",
  "Khobar",
  "Dhahran",
  "Taif",
  "Abha",
  "Tabuk",
  "Other"
];

const OPPORTUNITY_TYPES = ["Training Program", "Course"];

const APPLICATION_METHODS = ["Add Link", "Add via the System"];
