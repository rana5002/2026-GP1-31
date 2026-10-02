Ufuq Front-End Prototype — IT496

This version implements as much of the Product Backlog as possible without PHP/MySQL.

Front-end implemented with localStorage:
- User and Company registration with account-type switching and client-side validation.
- Email duplicate check, phone format check, password length, image/file format checks.
- Login demo and role-based redirect (User / Company / Admin).
- Search and advanced filtering for opportunities/courses.
- Empty states when no opportunities/courses exist.
- Opportunity details, application method display, closed state, favorites, comparison.
- Local application history and applicant status flow.
- Reviews, completion declaration and Verified Participant badge logic when Company marks an application Completed.
- User notifications, read/mark-all-read behaviour.
- Recommendations based on profile field/skills (front-end heuristic).
- Skill-gap preview (rule-based front-end demo; replace with the project dataset/AI later).
- AI opportunity/review summary preview generated locally; replace with the real LLM API later.
- Company publishing, applicant filtering/status updates, engagement counters and reviews.
- Admin company approval/rejection, opportunity close/delete, user suspension/delete, and action log.
- Opportunity views and favorite counters.
- Responsive interface and front-end validation.

Still needs PHP/MySQL/backend or external services:
- Real authentication/session protection and password hashing.
- Database persistence and true data integrity/reliability.
- Real email sending.
- Real-time notifications and scheduled deadline reminders.
- Real company/document verification storage.
- Real AI recommendation, skill-gap dataset/cosine similarity, and LLM summarization.
- Server-side validation/security/authorization.
- Production performance/availability testing.

Demo admin account:
Email: admin@ufuq.sa
Password: Admin1234

All localStorage keys start with "ufuq_". Clear browser site data to reset the front-end demo.
