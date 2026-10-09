-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: 09 أكتوبر 2026 الساعة 13:02
-- إصدار الخادم: 5.7.24
-- PHP Version: 8.3.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ufuq`
--

-- --------------------------------------------------------

--
-- بنية الجدول `admin`
--

CREATE TABLE `admin` (
  `AdminID` int(11) NOT NULL,
  `FirstName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `LastName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `admin`
--

INSERT INTO `admin` (`AdminID`, `FirstName`, `LastName`) VALUES
(1, 'Rana', 'Almutairi'),
(2, 'Hatun', 'Alothman'),
(3, 'Leenh', 'Almarzooq'),
(4, 'Layan', 'Alshamsan');

-- --------------------------------------------------------

--
-- بنية الجدول `application`
--

CREATE TABLE `application` (
  `UserID` int(11) NOT NULL,
  `OpportunityID` int(11) NOT NULL,
  `SubmissionDate` date NOT NULL,
  `ApplicationStatus` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `application`
--

INSERT INTO `application` (`UserID`, `OpportunityID`, `SubmissionDate`, `ApplicationStatus`) VALUES
(8, 1, '2026-10-01', 'Pending'),
(8, 2, '2026-10-02', 'Accepted'),
(9, 3, '2026-01-20', 'Accepted'),
(9, 8, '2026-10-04', 'Rejected'),
(10, 7, '2026-07-05', 'Accepted'),
(11, 4, '2026-10-06', 'Pending');

-- --------------------------------------------------------

--
-- بنية الجدول `company`
--

CREATE TABLE `company` (
  `CompanyID` int(11) NOT NULL,
  `CompanyName` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `CrNumber` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IndustrySector` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `VerificationDocument` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Status` enum('Pending','Verified','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `RejectionReason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Description` text COLLATE utf8mb4_unicode_ci,
  `Location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `WebsiteURL` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `VerifiedByAdminID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `company`
--

INSERT INTO `company` (`CompanyID`, `CompanyName`, `CrNumber`, `Phone`, `IndustrySector`, `VerificationDocument`, `Status`, `RejectionReason`, `Description`, `Location`, `WebsiteURL`, `Logo`, `VerifiedByAdminID`) VALUES
(5, 'Tuwaiq Academy', '1010123456', '0112345678', 'Technology & Training', 'docs/tuwaiq_cr.pdf', 'Verified', NULL, 'Saudi national academy offering tech bootcamps and programs in AI, cybersecurity, cloud, UX and more, in partnership with global tech companies.', 'Riyadh', 'https://tuwaiq.edu.sa', 'logos/tuwaiq.png', 1),
(6, 'SDAIA Academy', '1010234567', '0112345679', 'Data & Artificial Intelligence', 'docs/sdaia_academy_cr.pdf', 'Verified', NULL, 'Training arm of the Saudi Data and AI Authority, delivering data and AI programs through the Athka X platform.', 'Riyadh', 'https://sdaia.gov.sa', 'logos/sdaia.png', 2),
(7, 'Saudi Aramco', '1010345678', '0112345680', 'Energy', 'docs/aramco_cr.pdf', 'Verified', NULL, 'Integrated energy and chemicals company offering internship programs for Saudi university and vocational college students.', 'Dhahran', 'https://www.aramco.com', 'logos/aramco.png', 3),
(12, 'Rawafed Trading', '1010456789', '0112345681', 'Trading', 'docs/rawafed_cr.pdf', 'Rejected', 'The commercial registration document is expired and the CR number does not match the submitted documents.', 'Fictional company used to demonstrate the rejection flow.', 'Dammam', 'https://rawafed.example.com', 'logos/rawafed.png', 4);

-- --------------------------------------------------------

--
-- بنية الجدول `experience`
--

CREATE TABLE `experience` (
  `ExperienceID` int(11) NOT NULL,
  `JobTitle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Organization` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `Duration` int(11) GENERATED ALWAYS AS ((to_days(`EndDate`) - to_days(`StartDate`))) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `experience`
--

INSERT INTO `experience` (`ExperienceID`, `JobTitle`, `Organization`, `StartDate`, `EndDate`) VALUES
(1, 'Volunteer Coordinator', 'Community Volunteer Team', '2024-01-01', '2024-12-31'),
(2, 'Customer Service Representative', 'Retail Store', '2023-06-01', '2024-06-01'),
(3, 'Junior Web Developer Intern', 'Tech Startup', '2025-06-01', '2025-09-01'),
(4, 'Social Media Assistant', 'Local Agency', '2025-01-01', '2025-06-30');

-- --------------------------------------------------------

--
-- بنية الجدول `favourite`
--

CREATE TABLE `favourite` (
  `UserID` int(11) NOT NULL,
  `OpportunityID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `favourite`
--

INSERT INTO `favourite` (`UserID`, `OpportunityID`) VALUES
(8, 1),
(9, 3),
(10, 7);

-- --------------------------------------------------------

--
-- بنية الجدول `member`
--

CREATE TABLE `member` (
  `MemberID` int(11) NOT NULL,
  `Email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Role` enum('Admin','Company','User') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `member`
--

INSERT INTO `member` (`MemberID`, `Email`, `Password`, `Role`) VALUES
(1, 'rana@gmail.com', '$2y$10$12ybGKaRGYiP6gE2i04iO.p/PnHu4.lX1TM/KXWPpL4cPA2wa7pIO', 'Admin'),
(2, 'hatun@gmail.com', '$2y$10$PFXusarVUzyJO8rCl8lulemX7TqdHIyF7tVfiPWejGb8i5tv.Th/O', 'Admin'),
(3, 'leenh@gmail.com', '$2y$10$HTVLLlOotO7mcBRCVsz48.p2C2eIZ3Vgqpr/26xQw6CDUNT2yGPui', 'Admin'),
(4, 'layan@gmail.com', '$2y$10$ijzaVWwt7K1xHJiP4NVOc.Dfdt0a2rZ6lRoL8scF9DKSe7QIUoY4W', 'Admin'),
(5, 'tuwaiq.academy@gmail.com', '$2y$10$sCBsPHo9yVGNzzv/U8Iuke.UBxvkKwMx53SaX69hINTUJynU6wM9a', 'Company'),
(6, 'sdaia.academy@outlook.com', '$2y$10$WbQHeEgR.jRMKs5nk4oIf.Ws.LXus5L3cID.tU6kvUFze2NqedKXO', 'Company'),
(7, 'aramco.careers@icloud.com', '$2y$10$fBz8GJfZmg4bPSkAgarYEOcyhD5BG1jgwsBpR3XRgJlo6zLAIOerG', 'Company'),
(8, 'sara@gmail.com', '$2y$10$joKwP6hbKs/2VXsaCWPDTe0vg/.L45JU2TOF7TKvrYYQr80QhuvGi', 'User'),
(9, 'noura@gmail.com', '$2y$10$lOXsiBLS.QEsmlKhL7BS/O8OxeKzInMBkNGPVcCOqJlxybjJba2Ra', 'User'),
(10, 'ahmed@gmail.com', '$2y$10$qvlEFEWFXHOWUb5P5OykZuJCWtvcS12p1eh1rAHqfjxg7BointG6m', 'User'),
(11, 'khalid@gmail.com', '$2y$10$G9jbpS32Tjoq/4WvXWjLE.jGSm5IH3bJ89dSwvEm7EuOmhvbcjcRO', 'User'),
(12, 'rawafed.trading@gmail.com', '$2y$10$ih2cApaIq7lLR9vMgnfCmuaX7Zv/EaRaopeLtg/QV0GG0sgCfxEjq', 'Company');

-- --------------------------------------------------------

--
-- بنية الجدول `notification`
--

CREATE TABLE `notification` (
  `NotificationID` int(11) NOT NULL,
  `Message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `IsRead` tinyint(1) NOT NULL DEFAULT '0',
  `CreatedAt` datetime DEFAULT CURRENT_TIMESTAMP,
  `Type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `OpportunityID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `notification`
--

INSERT INTO `notification` (`NotificationID`, `Message`, `IsRead`, `CreatedAt`, `Type`, `OpportunityID`) VALUES
(1, 'A new training opportunity matching your skills was posted: Tuwaiq Cybersecurity Bootcamp.', 0, '2026-10-01 09:00:00', 'NewOpportunity', 1),
(2, 'Your application for SDAIA Professional Training in Generative AI and LLMs has been accepted.', 0, '2026-10-06 10:00:00', 'ApplicationUpdate', 2),
(3, 'Reminder: the deadline for Introduction to AI is approaching.', 0, '2026-10-07 08:00:00', 'Reminder', 16);

-- --------------------------------------------------------

--
-- بنية الجدول `opportunity`
--

CREATE TABLE `opportunity` (
  `OpportunityID` int(11) NOT NULL,
  `Title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Description` text COLLATE utf8mb4_unicode_ci,
  `Type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ApplicationMethod` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `OpportunityProvider` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IndustrySector` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ExternalURL` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `Duration` int(11) GENERATED ALWAYS AS ((to_days(`EndDate`) - to_days(`StartDate`))) STORED,
  `ApplicationDeadLine` date DEFAULT NULL,
  `TimeLine` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT '0.00',
  `CreatedByAdminID` int(11) DEFAULT NULL,
  `CreatedByCompanyID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `opportunity`
--

INSERT INTO `opportunity` (`OpportunityID`, `Title`, `Description`, `Type`, `ApplicationMethod`, `OpportunityProvider`, `IndustrySector`, `ExternalURL`, `StartDate`, `EndDate`, `ApplicationDeadLine`, `TimeLine`, `Location`, `Status`, `Price`, `CreatedByAdminID`, `CreatedByCompanyID`) VALUES
(1, 'Tuwaiq Cybersecurity Bootcamp', 'Intensive in-person bootcamp in penetration testing and cybersecurity, with employment opportunities for top performers.', 'Training', 'Internal', 'Tuwaiq Academy', 'Technology', 'https://tuwaiq.edu.sa', '2027-01-17', '2027-06-17', '2026-12-20', 'Approx. 5 months, in-person in Riyadh', 'Riyadh', 'Open', '0.00', NULL, 5),
(2, 'Professional Training in Generative AI and LLMs (NVIDIA)', 'Four-week hands-on program on building and customizing LLMs, preparing for the NVIDIA Certified Associate Gen AI LLMs exam.', 'Training', 'Internal', 'SDAIA Academy', 'Data & Artificial Intelligence', 'https://athkax.sdaia.gov.sa/events/professional-training-in-generative-ai-nvidia', '2026-11-15', '2026-12-13', '2026-11-08', '4 weeks, schedule on Athka X', 'Riyadh', 'Open', '0.00', NULL, 6),
(3, 'Tuwaiq AI Product-Building Bootcamp (with NTDP)', 'Ten-week bootcamp to build AI products; top projects received incubation support through MVPLAB.', 'Training', 'Internal', 'Tuwaiq Academy', 'Technology', 'https://tuwaiq.edu.sa', '2026-02-01', '2026-04-12', '2026-01-25', '10 weeks, in-person in Riyadh', 'Riyadh', 'Closed', '0.00', NULL, 5),
(4, 'Modern Data Engineering for AI Systems', 'Professional track on data engineering for AI systems from the SDAIA Academy summer 2026 initiative.', 'Training', 'Internal', 'SDAIA Academy', 'Data & Artificial Intelligence', 'https://athkax.sdaia.gov.sa', '2026-11-22', '2026-12-20', '2026-11-15', 'Bootcamp, schedule on Athka X', 'Riyadh', 'Open', '0.00', NULL, 6),
(5, 'Saudi Aramco University Internship Program', 'Internship for Saudi students whose universities require practical training before graduation.', 'Internship', 'Internal', 'Saudi Aramco', 'Energy', 'https://www.aramco.com/en/careers/for-saudi-applicants/student-opportunities/university-and-vocational-college-internship-programs/university-internship-program', '2027-01-18', NULL, '2026-11-02', 'Applications 26 Oct - 2 Nov 2026; orientation 17 Jan 2027', 'Saudi Arabia', 'Upcoming', '0.00', NULL, 7),
(6, 'Saudi Aramco Vocational College Internship Program', 'Practical training for students of vocational and technical colleges.', 'Internship', 'Internal', 'Saudi Aramco', 'Energy', 'https://www.aramco.com/en/careers/for-saudi-applicants/student-opportunities/university-and-vocational-college-internship-programs/vocational-college-internship-program', '2026-08-31', NULL, NULL, 'Orientation 30 Aug 2026; start 31 Aug 2026', 'Saudi Arabia', 'Closed', '0.00', NULL, 7),
(7, 'Enhancing Productivity and Workflows with AI', 'Beginner course on using AI tools to improve productivity, from the SDAIA Academy summer 2026 initiative.', 'Course', 'Internal', 'SDAIA Academy', 'Data & Artificial Intelligence', 'https://athkax.sdaia.gov.sa', '2026-07-12', '2026-08-06', '2026-07-08', 'Summer 2026 initiative, schedule on Athka X', 'Online', 'Closed', '0.00', NULL, 6),
(8, 'Apple Developer Academy at Tuwaiq', 'Nine-month app development program for women developers in Riyadh, run with Apple.', 'Training', 'Internal', 'Tuwaiq Academy', 'Technology', 'https://tuwaiq.edu.sa', '2026-11-15', '2027-08-15', '2026-10-31', '9 months, in-person in Riyadh', 'Riyadh', 'Open', '0.00', NULL, 5),
(9, 'SDAIA Summer of the Future Camps', 'Eighteen specialized data and AI training camps held in August 2026.', 'Course', 'Internal', 'SDAIA Academy', 'Data & Artificial Intelligence', 'https://athkax.sdaia.gov.sa', '2026-08-01', '2026-08-31', '2026-07-25', 'August 2026, 18 camps', 'Online', 'Closed', '0.00', NULL, 6),
(10, 'Tuwaiq Artificial Intelligence Hackathon', 'One-day AI hackathon using the vibe coding methodology, with 1,500+ participants.', 'Hackathon', 'Internal', 'Tuwaiq Academy', 'Technology', 'https://tuwaiq.edu.sa', '2026-03-08', '2026-03-08', '2026-03-01', '1 day, Riyadh', 'Riyadh', 'Closed', '0.00', NULL, 5),
(11, 'Administrative Assistant Training', 'Office administration and organization skills.', 'Training', 'Internal', 'Platform Admin', 'Administration', 'https://example.com/a1', '2026-12-01', '2027-01-15', '2026-11-20', 'Sun-Thu, 9AM-2PM', 'Riyadh', 'Open', '0.00', 1, NULL),
(12, 'Cybersecurity Training', 'Fundamentals of protecting systems and data.', 'Training', 'Internal', 'Platform Admin', 'Technology', 'https://example.com/a2', '2026-12-08', '2027-02-08', '2026-11-25', 'Sun-Thu, 10AM-3PM', 'Riyadh', 'Open', '0.00', 2, NULL),
(13, 'Content Writing Training', 'Writing for blogs, social media and websites.', 'Training', 'Internal', 'Platform Admin', 'Media', 'https://example.com/a3', '2027-01-05', '2027-02-20', '2026-12-15', 'Mon-Thu, 9AM-1PM', 'Online', 'Open', '0.00', 3, NULL),
(14, 'Business English', 'English for professional communication.', 'Course', 'Internal', 'Platform Admin', 'Education & Training', 'https://example.com/a4', '2026-11-22', '2026-12-22', '2026-11-12', 'Evenings, 4 weeks', 'Online', 'Open', '150.00', 4, NULL),
(15, 'Public Speaking', 'Build confidence and presentation skills.', 'Course', 'Internal', 'Platform Admin', 'Education & Training', 'https://example.com/a5', '2026-12-12', '2027-01-12', '2026-11-28', 'Weekends, 4 weeks', 'Riyadh', 'Open', '120.00', 1, NULL),
(16, 'Introduction to AI', 'Basics of artificial intelligence and machine learning.', 'Course', 'Internal', 'Platform Admin', 'Technology', 'https://example.com/a6', '2027-01-03', '2027-02-14', '2026-12-18', 'Evenings, 6 weeks', 'Online', 'Open', '280.00', 2, NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `opportunityexperience`
--

CREATE TABLE `opportunityexperience` (
  `OpportunityID` int(11) NOT NULL,
  `ExperienceID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `opportunityexperience`
--

INSERT INTO `opportunityexperience` (`OpportunityID`, `ExperienceID`) VALUES
(11, 2),
(1, 3);

-- --------------------------------------------------------

--
-- بنية الجدول `opportunityqualification`
--

CREATE TABLE `opportunityqualification` (
  `OpportunityID` int(11) NOT NULL,
  `QualificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `opportunityqualification`
--

INSERT INTO `opportunityqualification` (`OpportunityID`, `QualificationID`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 1),
(12, 1),
(5, 2),
(7, 2),
(11, 2),
(1, 3),
(6, 3),
(12, 3),
(5, 4);

-- --------------------------------------------------------

--
-- بنية الجدول `opportunityskill`
--

CREATE TABLE `opportunityskill` (
  `OpportunityID` int(11) NOT NULL,
  `SkillID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `opportunityskill`
--

INSERT INTO `opportunityskill` (`OpportunityID`, `SkillID`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(9, 1),
(10, 1),
(16, 1),
(2, 2),
(4, 2),
(5, 3),
(6, 3),
(7, 3),
(11, 3),
(13, 3),
(14, 3),
(15, 3),
(7, 4),
(11, 4),
(3, 6),
(5, 6),
(6, 6),
(8, 6),
(10, 6),
(1, 7),
(3, 7),
(5, 7),
(8, 7),
(9, 7),
(12, 7);

-- --------------------------------------------------------

--
-- بنية الجدول `qualification`
--

CREATE TABLE `qualification` (
  `QualificationID` int(11) NOT NULL,
  `FieldOfStudy` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `DegreeLevel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Institution` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `YearObtained` year(4) DEFAULT NULL,
  `Duration` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `qualification`
--

INSERT INTO `qualification` (`QualificationID`, `FieldOfStudy`, `DegreeLevel`, `Institution`, `YearObtained`, `Duration`) VALUES
(1, 'Computer Science', 'Bachelor', 'King Saud University', 2026, 4),
(2, 'Business Administration', 'Bachelor', 'King Saud University', 2023, 4),
(3, 'Information Systems', 'Diploma', 'Technical College', 2025, 2),
(4, 'Marketing', 'Bachelor', 'Princess Nourah University', 2026, 4);

-- --------------------------------------------------------

--
-- بنية الجدول `review`
--

CREATE TABLE `review` (
  `ReviewID` int(11) NOT NULL,
  `Rating` tinyint(4) NOT NULL,
  `ReviewText` text COLLATE utf8mb4_unicode_ci,
  `CreatedAt` datetime DEFAULT CURRENT_TIMESTAMP,
  `UserID` int(11) NOT NULL,
  `OpportunityID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `review`
--

INSERT INTO `review` (`ReviewID`, `Rating`, `ReviewText`, `CreatedAt`, `UserID`, `OpportunityID`) VALUES
(1, 5, 'Great training, very practical and well organized.', '2026-04-20 14:30:00', 9, 3),
(2, 4, 'Useful course, the instructor explained everything clearly.', '2026-08-10 11:15:00', 10, 7);

-- --------------------------------------------------------

--
-- بنية الجدول `skill`
--

CREATE TABLE `skill` (
  `SkillID` int(11) NOT NULL,
  `SkillName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `skill`
--

INSERT INTO `skill` (`SkillID`, `SkillName`) VALUES
(3, 'Communication'),
(8, 'Digital Marketing'),
(5, 'Graphic Design'),
(4, 'Microsoft Excel'),
(7, 'Problem Solving'),
(1, 'Python'),
(2, 'SQL'),
(6, 'Teamwork');

-- --------------------------------------------------------

--
-- بنية الجدول `user`
--

CREATE TABLE `user` (
  `UserID` int(11) NOT NULL,
  `FirstName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `LastName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `DateOfBirth` date DEFAULT NULL,
  `Age` int(11) DEFAULT NULL,
  `Gender` enum('Male','Female') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ProfilePicture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `EducationalStatus` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FieldOfStudy` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `user`
--

INSERT INTO `user` (`UserID`, `FirstName`, `LastName`, `Phone`, `DateOfBirth`, `Age`, `Gender`, `ProfilePicture`, `EducationalStatus`, `FieldOfStudy`) VALUES
(8, 'Sara', 'Alharbi', '0501111111', '2003-04-12', 23, 'Female', 'pics/sara.png', 'Undergraduate', 'Computer Science'),
(9, 'Noura', 'Alotaibi', '0502222222', '2002-09-25', 24, 'Female', 'pics/noura.png', 'Undergraduate', 'Marketing'),
(10, 'Ahmed', 'Alqahtani', '0503333333', '2001-01-30', 25, 'Male', 'pics/ahmed.png', 'Graduate', 'Business Administration'),
(11, 'Khalid', 'Aldosari', '0504444444', '2004-06-18', 22, 'Male', 'pics/khalid.png', 'Undergraduate', 'Information Systems');

--
-- القوادح `user`
--
DELIMITER $$
CREATE TRIGGER `trg_user_age_insert` BEFORE INSERT ON `user` FOR EACH ROW SET NEW.Age = TIMESTAMPDIFF(YEAR, NEW.DateOfBirth, CURDATE())
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_user_age_update` BEFORE UPDATE ON `user` FOR EACH ROW SET NEW.Age = TIMESTAMPDIFF(YEAR, NEW.DateOfBirth, CURDATE())
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- بنية الجدول `userexperience`
--

CREATE TABLE `userexperience` (
  `UserID` int(11) NOT NULL,
  `ExperienceID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `userexperience`
--

INSERT INTO `userexperience` (`UserID`, `ExperienceID`) VALUES
(9, 1),
(11, 1),
(10, 2),
(8, 3),
(9, 4);

-- --------------------------------------------------------

--
-- بنية الجدول `usernotification`
--

CREATE TABLE `usernotification` (
  `UserID` int(11) NOT NULL,
  `NotificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `usernotification`
--

INSERT INTO `usernotification` (`UserID`, `NotificationID`) VALUES
(8, 1),
(10, 1),
(8, 2),
(8, 3),
(11, 3);

-- --------------------------------------------------------

--
-- بنية الجدول `userqualification`
--

CREATE TABLE `userqualification` (
  `UserID` int(11) NOT NULL,
  `QualificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `userqualification`
--

INSERT INTO `userqualification` (`UserID`, `QualificationID`) VALUES
(8, 1),
(10, 2),
(11, 3),
(9, 4);

-- --------------------------------------------------------

--
-- بنية الجدول `userskill`
--

CREATE TABLE `userskill` (
  `UserID` int(11) NOT NULL,
  `SkillID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `userskill`
--

INSERT INTO `userskill` (`UserID`, `SkillID`) VALUES
(8, 1),
(8, 2),
(10, 2),
(9, 3),
(11, 3),
(10, 4),
(11, 5),
(9, 6),
(10, 6),
(8, 7),
(9, 8);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`AdminID`);

--
-- Indexes for table `application`
--
ALTER TABLE `application`
  ADD PRIMARY KEY (`UserID`,`OpportunityID`),
  ADD KEY `OpportunityID` (`OpportunityID`);

--
-- Indexes for table `company`
--
ALTER TABLE `company`
  ADD PRIMARY KEY (`CompanyID`),
  ADD UNIQUE KEY `CrNumber` (`CrNumber`),
  ADD KEY `VerifiedByAdminID` (`VerifiedByAdminID`);

--
-- Indexes for table `experience`
--
ALTER TABLE `experience`
  ADD PRIMARY KEY (`ExperienceID`);

--
-- Indexes for table `favourite`
--
ALTER TABLE `favourite`
  ADD PRIMARY KEY (`UserID`,`OpportunityID`),
  ADD KEY `OpportunityID` (`OpportunityID`);

--
-- Indexes for table `member`
--
ALTER TABLE `member`
  ADD PRIMARY KEY (`MemberID`),
  ADD UNIQUE KEY `Email` (`Email`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`NotificationID`),
  ADD KEY `OpportunityID` (`OpportunityID`);

--
-- Indexes for table `opportunity`
--
ALTER TABLE `opportunity`
  ADD PRIMARY KEY (`OpportunityID`),
  ADD KEY `CreatedByAdminID` (`CreatedByAdminID`),
  ADD KEY `CreatedByCompanyID` (`CreatedByCompanyID`);

--
-- Indexes for table `opportunityexperience`
--
ALTER TABLE `opportunityexperience`
  ADD PRIMARY KEY (`OpportunityID`,`ExperienceID`),
  ADD KEY `ExperienceID` (`ExperienceID`);

--
-- Indexes for table `opportunityqualification`
--
ALTER TABLE `opportunityqualification`
  ADD PRIMARY KEY (`OpportunityID`,`QualificationID`),
  ADD KEY `QualificationID` (`QualificationID`);

--
-- Indexes for table `opportunityskill`
--
ALTER TABLE `opportunityskill`
  ADD PRIMARY KEY (`OpportunityID`,`SkillID`),
  ADD KEY `SkillID` (`SkillID`);

--
-- Indexes for table `qualification`
--
ALTER TABLE `qualification`
  ADD PRIMARY KEY (`QualificationID`);

--
-- Indexes for table `review`
--
ALTER TABLE `review`
  ADD PRIMARY KEY (`ReviewID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `OpportunityID` (`OpportunityID`);

--
-- Indexes for table `skill`
--
ALTER TABLE `skill`
  ADD PRIMARY KEY (`SkillID`),
  ADD UNIQUE KEY `SkillName` (`SkillName`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`UserID`);

--
-- Indexes for table `userexperience`
--
ALTER TABLE `userexperience`
  ADD PRIMARY KEY (`UserID`,`ExperienceID`),
  ADD KEY `ExperienceID` (`ExperienceID`);

--
-- Indexes for table `usernotification`
--
ALTER TABLE `usernotification`
  ADD PRIMARY KEY (`UserID`,`NotificationID`),
  ADD KEY `NotificationID` (`NotificationID`);

--
-- Indexes for table `userqualification`
--
ALTER TABLE `userqualification`
  ADD PRIMARY KEY (`UserID`,`QualificationID`),
  ADD KEY `QualificationID` (`QualificationID`);

--
-- Indexes for table `userskill`
--
ALTER TABLE `userskill`
  ADD PRIMARY KEY (`UserID`,`SkillID`),
  ADD KEY `SkillID` (`SkillID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `experience`
--
ALTER TABLE `experience`
  MODIFY `ExperienceID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `member`
--
ALTER TABLE `member`
  MODIFY `MemberID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `NotificationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `opportunity`
--
ALTER TABLE `opportunity`
  MODIFY `OpportunityID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `qualification`
--
ALTER TABLE `qualification`
  MODIFY `QualificationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `review`
--
ALTER TABLE `review`
  MODIFY `ReviewID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `skill`
--
ALTER TABLE `skill`
  MODIFY `SkillID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- قيود الجداول المحفوظة
--

--
-- القيود للجدول `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`AdminID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE;

--
-- القيود للجدول `application`
--
ALTER TABLE `application`
  ADD CONSTRAINT `application_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- القيود للجدول `company`
--
ALTER TABLE `company`
  ADD CONSTRAINT `company_ibfk_1` FOREIGN KEY (`CompanyID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_ibfk_2` FOREIGN KEY (`VerifiedByAdminID`) REFERENCES `admin` (`AdminID`) ON DELETE SET NULL;

--
-- القيود للجدول `favourite`
--
ALTER TABLE `favourite`
  ADD CONSTRAINT `favourite_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `favourite_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- القيود للجدول `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- القيود للجدول `opportunity`
--
ALTER TABLE `opportunity`
  ADD CONSTRAINT `opportunity_ibfk_1` FOREIGN KEY (`CreatedByAdminID`) REFERENCES `admin` (`AdminID`) ON DELETE SET NULL,
  ADD CONSTRAINT `opportunity_ibfk_2` FOREIGN KEY (`CreatedByCompanyID`) REFERENCES `company` (`CompanyID`) ON DELETE SET NULL;

--
-- القيود للجدول `opportunityexperience`
--
ALTER TABLE `opportunityexperience`
  ADD CONSTRAINT `opportunityexperience_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityexperience_ibfk_2` FOREIGN KEY (`ExperienceID`) REFERENCES `experience` (`ExperienceID`) ON DELETE CASCADE;

--
-- القيود للجدول `opportunityqualification`
--
ALTER TABLE `opportunityqualification`
  ADD CONSTRAINT `opportunityqualification_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityqualification_ibfk_2` FOREIGN KEY (`QualificationID`) REFERENCES `qualification` (`QualificationID`) ON DELETE CASCADE;

--
-- القيود للجدول `opportunityskill`
--
ALTER TABLE `opportunityskill`
  ADD CONSTRAINT `opportunityskill_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityskill_ibfk_2` FOREIGN KEY (`SkillID`) REFERENCES `skill` (`SkillID`) ON DELETE CASCADE;

--
-- القيود للجدول `review`
--
ALTER TABLE `review`
  ADD CONSTRAINT `review_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `review_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- القيود للجدول `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE;

--
-- القيود للجدول `userexperience`
--
ALTER TABLE `userexperience`
  ADD CONSTRAINT `userexperience_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userexperience_ibfk_2` FOREIGN KEY (`ExperienceID`) REFERENCES `experience` (`ExperienceID`) ON DELETE CASCADE;

--
-- القيود للجدول `usernotification`
--
ALTER TABLE `usernotification`
  ADD CONSTRAINT `usernotification_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `usernotification_ibfk_2` FOREIGN KEY (`NotificationID`) REFERENCES `notification` (`NotificationID`) ON DELETE CASCADE;

--
-- القيود للجدول `userqualification`
--
ALTER TABLE `userqualification`
  ADD CONSTRAINT `userqualification_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userqualification_ibfk_2` FOREIGN KEY (`QualificationID`) REFERENCES `qualification` (`QualificationID`) ON DELETE CASCADE;

--
-- القيود للجدول `userskill`
--
ALTER TABLE `userskill`
  ADD CONSTRAINT `userskill_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userskill_ibfk_2` FOREIGN KEY (`SkillID`) REFERENCES `skill` (`SkillID`) ON DELETE CASCADE;

DELIMITER $$
--
-- أحداث
--
CREATE DEFINER=`root`@`localhost` EVENT `evt_update_user_age` ON SCHEDULE EVERY 1 DAY STARTS '2026-10-09 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO UPDATE `User` SET Age = TIMESTAMPDIFF(YEAR, DateOfBirth, CURDATE())$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
