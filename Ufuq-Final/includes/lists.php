<?php
// Shared lists: every page uses these, so the choices are the same everywhere.
// To change a list, change it here only.

const EDU_STATUSES = ['Student', 'Fresh Graduate', 'Graduate', 'Employed', 'Job Seeker'];

// Ufuq is for computing fields only.
const FIELDS = [
    'Computer Science', 'Information Technology', 'Information Systems', 'Software Engineering',
    'Computer Engineering', 'Cybersecurity', 'Artificial Intelligence', 'Data Science',
    'Networks & Communications', 'Web & Mobile Development', 'Other',
];

const INDUSTRIES = [
    'Technology', 'Education & Training', 'Marketing', 'Trading', 'Media', 'Administration',
    'Healthcare', 'Finance', 'Engineering & Construction', 'Retail',
    'Telecommunications', 'Cybersecurity', 'Artificial Intelligence & Data', 'Insurance',
    'Government & Public Sector', 'Energy, Oil & Gas', 'Utilities & Environment',
    'Manufacturing & Industrial', 'Logistics & Transportation', 'E-commerce', 'Real Estate',
    'Hospitality & Tourism', 'Consulting & Professional Services', 'Pharmaceuticals',
    'Non-profit & Social Services', 'Agriculture & Food',
];



const CITIES = [
    'Riyadh', 'Jeddah', 'Makkah', 'Madinah', 'Dammam', 'Khobar', 'Dhahran', 'Jubail',
    'Taif', 'Abha', 'Khamis Mushait', 'Tabuk', 'Buraidah', 'Hail', 'Najran', 'Jazan',
    'Yanbu', 'Al Ahsa', 'Qatif', 'Al Kharj', 'Arar', 'Sakaka', 'Al Baha', 'Unaizah',
    
];

// Opportunity location: Online + all the cities above
define('OPPORTUNITY_LOCATIONS', array_merge(['Online'], CITIES));


//Type
const OPPORTUNITY_TYPES = ['Training', 'Course'];  