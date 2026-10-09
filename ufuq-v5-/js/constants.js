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

/* Reference job roles + the skills typically expected for each, grouped by
   category. Used by the Skill Gap page so "Target Job Role" is a guided
   choice instead of free text, and the expected-skills list updates with it.
   Move this to the backend taxonomy once it exists. */
const IT_JOBS_AND_SKILLS = [
  { title: "Software Engineer", category: "Software Development",
    skills: ["Java", "Python", "C++", "OOP", "Data Structures", "Algorithms", "Git", "Software Architecture", "Problem Solving"] },
  { title: "Full-Stack Developer", category: "Web Development",
    skills: ["HTML5", "CSS3", "JavaScript", "React", "Angular", "Node.js", "PHP", "MySQL", "REST APIs", "AJAX"] },
  { title: "Front-End Developer", category: "Web Development",
    skills: ["HTML5", "CSS3", "JavaScript", "TypeScript", "React", "Vue.js", "Tailwind CSS", "Responsive Design"] },
  { title: "Back-End Developer", category: "Web Development",
    skills: ["Node.js", "Python", "Java", "PHP", "Express.js", "Django", "SQL", "MongoDB", "RESTful APIs"] },
  { title: "Mobile Application Developer", category: "Mobile Development",
    skills: ["Java", "Kotlin", "Swift", "Flutter", "React Native", "Mobile Security", "REST APIs", "UI/UX Design"] },
  { title: "Data Analyst", category: "Data & Analytics",
    skills: ["SQL", "Python", "R", "Excel", "Power BI", "Tableau", "Data Visualization", "Data Cleaning", "Statistics"] },
  { title: "Data Scientist", category: "Data & Analytics",
    skills: ["Python", "R", "Machine Learning", "Deep Learning", "Pandas", "NumPy", "Scikit-Learn", "Big Data", "Statistics"] },
  { title: "Data Engineer", category: "Data & Analytics",
    skills: ["SQL", "Python", "ETL Pipelines", "Apache Spark", "Hadoop", "Data Warehousing", "PostgreSQL", "NoSQL"] },
  { title: "AI / Machine Learning Engineer", category: "Artificial Intelligence",
    skills: ["Python", "TensorFlow", "PyTorch", "Computer Vision", "NLP", "Neural Networks", "Algorithm Optimization"] },
  { title: "Database Administrator (DBA)", category: "Databases",
    skills: ["SQL", "MySQL", "PostgreSQL", "Oracle", "ERD Modeling", "Database Security", "Backup & Recovery", "Performance Tuning"] },
  { title: "Cybersecurity Analyst", category: "Cybersecurity",
    skills: ["Network Security", "Threat Modeling", "SIEM Tools", "Vulnerability Assessment", "OWASP Benchmarks", "Incident Response"] },
  { title: "Penetration Tester / Ethical Hacker", category: "Cybersecurity",
    skills: ["Kali Linux", "Burp Suite", "Metasploit", "MobSF", "JADX", "Wireshark", "Web & Mobile Security", "Vulnerability Auditing"] },
  { title: "Information Security Officer", category: "Cybersecurity",
    skills: ["Risk Assessment", "ISO 27001", "Security Governance", "Compliance", "Identity & Access Management (IAM)"] },
  { title: "Network Engineer", category: "Networking",
    skills: ["Routing & Switching", "Cisco CCNA/CCNP", "TCP/IP", "Firewalls", "Wi-Fi Security (WPA2/WPA3)", "VPN", "Wireshark"] },
  { title: "Cloud Engineer", category: "Cloud Computing",
    skills: ["AWS", "Microsoft Azure", "Google Cloud (GCP)", "Cloud Architecture", "Terraform", "Cloud Security"] },
  { title: "DevOps Engineer", category: "Cloud & Systems",
    skills: ["CI/CD Pipelines", "Docker", "Kubernetes", "Linux", "Git", "Jenkins", "Ansible", "Shell Scripting"] },
  { title: "Systems Administrator", category: "Systems & Infrastructure",
    skills: ["Linux (Ubuntu/RHEL)", "Windows Server", "Active Directory", "Virtualization (VMware/VirtualBox)", "Bash Scripting", "System Maintenance"] },
  { title: "Systems Analyst", category: "IT Management",
    skills: ["Requirements Gathering", "UML Modeling", "SDLC", "Business Process Mapping", "System Architecture", "Functional Specifications"] },
  { title: "Business Analyst (IT)", category: "IT Management",
    skills: ["Data Analysis", "Agile / Scrum", "User Stories", "Process Optimization", "Requirements Engineering", "SQL"] },
  { title: "Software QA / Test Engineer", category: "Quality Assurance",
    skills: ["Manual Testing", "Automation Testing", "Selenium", "Postman", "Test Cases Design", "Bug Tracking (Jira)", "SDLC & STLC"] },
  { title: "UI/UX Designer", category: "Design & Product",
    skills: ["Figma", "Adobe XD", "Wireframing", "Prototyping", "User Research", "Information Architecture", "Design Systems"] },
  { title: "IT Project Manager / Scrum Master", category: "IT Management",
    skills: ["Agile & Scrum Frameworks", "Jira", "Risk Management", "Project Planning", "Team Leadership", "Budgeting"] }
];
