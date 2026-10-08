-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: 08 OCT 2026  19:05hr
-- Server Version: 5.7.24
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
--   `admin`
--

CREATE TABLE `admin` (
  `AdminID` int(11) NOT NULL,
  `FirstName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `LastName` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `admin` (`AdminID`, `FirstName`, `LastName`) VALUES
(1, 'Rana', 'Alshehri'),
(2, 'Hatoun', 'Alzahrani'),
(3, 'Leena', 'Almutairi'),
(4, 'Layan', 'Alghamdi');

-- --------------------------------------------------------

--
-- `application`
--

CREATE TABLE `application` (
  `UserID` int(11) NOT NULL,
  `OpportunityID` int(11) NOT NULL,
  `SubmissionDate` date NOT NULL,
  `ApplicationStatus` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `application` (`UserID`, `OpportunityID`, `SubmissionDate`, `ApplicationStatus`) VALUES
(8, 1, '2026-10-01', 'Pending'),
(8, 2, '2026-10-02', 'Accepted'),
(9, 3, '2026-10-03', 'Accepted'),
(9, 8, '2026-10-04', 'Rejected'),
(10, 7, '2026-10-05', 'Accepted'),
(11, 4, '2026-10-06', 'Pending');

-- --------------------------------------------------------

--
-- `company`
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
--

INSERT INTO `company` (`CompanyID`, `CompanyName`, `CrNumber`, `Phone`, `IndustrySector`, `VerificationDocument`, `Status`, `RejectionReason`, `Description`, `Location`, `WebsiteURL`, `Logo`, `VerifiedByAdminID`) VALUES
(5, 'FutureTech Solutions', '1010123456', '0112345678', 'Technology', 'docs/futuretech_cr.pdf', 'Verified', NULL, 'Software and data solutions company.', 'Riyadh', 'https://futuretech.example.com', 'logos/futuretech.png', 1),
(6, 'Business Academy', '1010234567', '0112345679', 'Education & Training', 'docs/businessacademy_cr.pdf', 'Verified', NULL, 'Professional training and business courses.', 'Riyadh', 'https://businessacademy.example.com', 'logos/businessacademy.png', 2),
(7, 'Horizon Marketing', '1010345678', '0112345680', 'Marketing', 'docs/horizon_cr.pdf', 'Verified', NULL, 'Digital marketing and branding agency.', 'Jeddah', 'https://horizon.example.com', 'logos/horizon.png', 3),
(12, 'Rawafed Trading', '1010456789', '0112345681', 'Trading', 'docs/rawafed_cr.pdf', 'Rejected', 'The commercial registration document is expired and the CR number does not match the submitted documents.', 'Company is a trading business seeking to post training opportunities.', 'Dammam', 'https://rawafed.example.com', 'logos/rawafed.png', 4);

-- --------------------------------------------------------

--
-- `experience`
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
--

INSERT INTO `experience` (`ExperienceID`, `JobTitle`, `Organization`, `StartDate`, `EndDate`) VALUES
(1, 'Volunteer Coordinator', 'Community Volunteer Team', '2024-01-01', '2024-12-31'),
(2, 'Customer Service Representative', 'Retail Store', '2023-06-01', '2024-06-01'),
(3, 'Junior Web Developer Intern', 'Tech Startup', '2025-06-01', '2025-09-01'),
(4, 'Social Media Assistant', 'Local Agency', '2025-01-01', '2025-06-30');

-- --------------------------------------------------------

--
-- `favourite`
--

CREATE TABLE `favourite` (
  `UserID` int(11) NOT NULL,
  `OpportunityID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `favourite` (`UserID`, `OpportunityID`) VALUES
(8, 1),
(9, 3),
(10, 7);

-- --------------------------------------------------------

--
-- `member`
--

CREATE TABLE `member` (
  `MemberID` int(11) NOT NULL,
  `Email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Role` enum('Admin','Company','User') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `member` (`MemberID`, `Email`, `Password`, `Role`) VALUES
(1, 'rana@admin.com', '$2b$10$DummyHashForTesting', 'Admin'),
(2, 'hatoun@admin.com', '$2b$10$DummyHashForTesting', 'Admin'),
(3, 'leena@admin.com', '$2b$10$DummyHashForTesting', 'Admin'),
(4, 'layan@admin.com', '$2b$10$DummyHashForTesting', 'Admin'),
(5, 'info@futuretech.com', '$2b$10$DummyHashForTesting', 'Company'),
(6, 'info@businessacademy.com', '$2b$10$DummyHashForTesting', 'Company'),
(7, 'info@horizonmarketing.com', '$2b$10$DummyHashForTesting', 'Company'),
(8, 'sara@user.com', '$2b$10$DummyHashForTesting', 'User'),
(9, 'noura@user.com', '$2b$10$DummyHashForTesting', 'User'),
(10, 'ahmed@user.com', '$2b$10$DummyHashForTesting', 'User'),
(11, 'khalid@user.com', '$2b$10$DummyHashForTesting', 'User'),
(12, 'info@rawafed.com', '$2b$10$DummyHashForTesting', 'Company');

-- --------------------------------------------------------

--
-- `notification`
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
-- `notification`
--

INSERT INTO `notification` (`NotificationID`, `Message`, `IsRead`, `CreatedAt`, `Type`, `OpportunityID`) VALUES
(1, 'A new training opportunity matching your skills was posted: Software Development Training.', 0, '2026-10-01 09:00:00', 'NewOpportunity', 1),
(2, 'Your application for Data Analysis Training has been accepted.', 0, '2026-10-06 10:00:00', 'ApplicationUpdate', 2),
(3, 'Reminder: the deadline for Introduction to AI is approaching.', 0, '2026-10-07 08:00:00', 'Reminder', 16);

-- --------------------------------------------------------

--
-- `opportunity`
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
--

INSERT INTO `opportunity` (`OpportunityID`, `Title`, `Description`, `Type`, `ApplicationMethod`, `OpportunityProvider`, `IndustrySector`, `ExternalURL`, `StartDate`, `EndDate`, `ApplicationDeadLine`, `TimeLine`, `Location`, `Status`, `Price`, `CreatedByAdminID`, `CreatedByCompanyID`) VALUES
(1, 'Software Development Training', 'Hands-on training on web development projects.', 'Training', 'Internal', 'FutureTech Solutions', 'Technology', 'https://futuretech.example.com/t1', '2026-12-01', '2027-02-01', '2026-11-15', 'Sun-Thu, 9AM-3PM', 'Riyadh', 'Open', '0.00', NULL, 5),
(2, 'Data Analysis Training', 'Work with real datasets using SQL and Excel.', 'Training', 'Internal', 'FutureTech Solutions', 'Technology', 'https://futuretech.example.com/t2', '2026-12-10', '2027-03-10', '2026-11-20', 'Sun-Thu, 10AM-4PM', 'Riyadh', 'Open', '0.00', NULL, 5),
(3, 'Digital Marketing Training', 'Social media campaigns and analytics.', 'Training', 'Internal', 'Horizon Marketing', 'Marketing', 'https://horizon.example.com/t1', '2026-12-05', '2027-02-05', '2026-11-18', 'Mon-Thu, 9AM-2PM', 'Jeddah', 'Open', '0.00', NULL, 7),
(4, 'Graphic Design Training', 'Branding and visual identity design.', 'Training', 'Internal', 'Horizon Marketing', 'Marketing', 'https://horizon.example.com/t2', '2027-01-04', '2027-03-04', '2026-12-10', 'Sun-Wed, 10AM-3PM', 'Jeddah', 'Open', '0.00', NULL, 7),
(5, 'Customer Service Training', 'Learn communication and client handling.', 'Training', 'Internal', 'Business Academy', 'Education & Training', 'https://businessacademy.example.com/t1', '2026-12-15', '2027-01-30', '2026-11-30', 'Sun-Thu, 9AM-1PM', 'Riyadh', 'Open', '0.00', NULL, 6),
(6, 'Python for Beginners', 'Introductory programming course in Python.', 'Course', 'Internal', 'FutureTech Solutions', 'Technology', 'https://futuretech.example.com/c1', '2026-11-20', '2026-12-20', '2026-11-10', 'Evenings, 4 weeks', 'Online', 'Open', '300.00', NULL, 5),
(7, 'Excel Essentials', 'Formulas, tables and charts in Excel.', 'Course', 'Internal', 'Business Academy', 'Education & Training', 'https://businessacademy.example.com/c1', '2026-11-25', '2026-12-25', '2026-11-12', 'Weekends, 4 weeks', 'Riyadh', 'Open', '250.00', NULL, 6),
(8, 'SEO Fundamentals', 'Search engine optimization basics.', 'Course', 'Internal', 'Horizon Marketing', 'Marketing', 'https://horizon.example.com/c1', '2026-12-02', '2026-12-30', '2026-11-15', 'Evenings, 4 weeks', 'Online', 'Open', '200.00', NULL, 7),
(9, 'Project Management Basics', 'Planning and managing projects.', 'Course', 'Internal', 'Business Academy', 'Education & Training', 'https://businessacademy.example.com/c2', '2027-01-10', '2027-02-10', '2026-12-20', 'Weekends, 5 weeks', 'Riyadh', 'Open', '400.00', NULL, 6),
(10, 'UI/UX Design Course', 'User interface and experience design with Figma.', 'Course', 'Internal', 'FutureTech Solutions', 'Technology', 'https://futuretech.example.com/c2', '2027-01-15', '2027-02-25', '2026-12-25', 'Evenings, 6 weeks', 'Online', 'Open', '350.00', NULL, 5),
(11, 'Administrative Assistant Training', 'Office administration and organization skills.', 'Training', 'Internal', 'Platform Admin', 'Administration', 'https://example.com/a1', '2026-12-01', '2027-01-15', '2026-11-20', 'Sun-Thu, 9AM-2PM', 'Riyadh', 'Open', '0.00', 1, NULL),
(12, 'Cybersecurity Training', 'Fundamentals of protecting systems and data.', 'Training', 'Internal', 'Platform Admin', 'Technology', 'https://example.com/a2', '2026-12-08', '2027-02-08', '2026-11-25', 'Sun-Thu, 10AM-3PM', 'Riyadh', 'Open', '0.00', 2, NULL),
(13, 'Content Writing Training', 'Writing for blogs, social media and websites.', 'Training', 'Internal', 'Platform Admin', 'Media', 'https://example.com/a3', '2027-01-05', '2027-02-20', '2026-12-15', 'Mon-Thu, 9AM-1PM', 'Online', 'Open', '0.00', 3, NULL),
(14, 'Business English', 'English for professional communication.', 'Course', 'Internal', 'Platform Admin', 'Education & Training', 'https://example.com/a4', '2026-11-22', '2026-12-22', '2026-11-12', 'Evenings, 4 weeks', 'Online', 'Open', '150.00', 4, NULL),
(15, 'Public Speaking', 'Build confidence and presentation skills.', 'Course', 'Internal', 'Platform Admin', 'Education & Training', 'https://example.com/a5', '2026-12-12', '2027-01-12', '2026-11-28', 'Weekends, 4 weeks', 'Riyadh', 'Open', '120.00', 1, NULL),
(16, 'Introduction to AI', 'Basics of artificial intelligence and machine learning.', 'Course', 'Internal', 'Platform Admin', 'Technology', 'https://example.com/a6', '2027-01-03', '2027-02-14', '2026-12-18', 'Evenings, 6 weeks', 'Online', 'Open', '280.00', 2, NULL);

-- --------------------------------------------------------

--
--

CREATE TABLE `opportunityexperience` (
  `OpportunityID` int(11) NOT NULL,
  `ExperienceID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- `opportunityexperience`
--

INSERT INTO `opportunityexperience` (`OpportunityID`, `ExperienceID`) VALUES
(5, 2),
(11, 2),
(1, 3),
(3, 4);

-- --------------------------------------------------------

--
-- `opportunityqualification`
--

CREATE TABLE `opportunityqualification` (
  `OpportunityID` int(11) NOT NULL,
  `QualificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `opportunityqualification` (`OpportunityID`, `QualificationID`) VALUES
(1, 1),
(2, 1),
(12, 1),
(11, 2),
(4, 3),
(12, 3),
(3, 4);

-- --------------------------------------------------------

--
-- `opportunityskill`
--

CREATE TABLE `opportunityskill` (
  `OpportunityID` int(11) NOT NULL,
  `SkillID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `opportunityskill` (`OpportunityID`, `SkillID`) VALUES
(1, 1),
(6, 1),
(16, 1),
(1, 2),
(2, 2),
(3, 3),
(5, 3),
(11, 3),
(13, 3),
(14, 3),
(15, 3),
(2, 4),
(7, 4),
(11, 4),
(4, 5),
(10, 5),
(9, 6),
(12, 7),
(3, 8),
(8, 8);

-- --------------------------------------------------------

--
-- `qualification`
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
--

INSERT INTO `qualification` (`QualificationID`, `FieldOfStudy`, `DegreeLevel`, `Institution`, `YearObtained`, `Duration`) VALUES
(1, 'Computer Science', 'Bachelor', 'King Saud University', 2026, 4),
(2, 'Business Administration', 'Bachelor', 'King Saud University', 2023, 4),
(3, 'Information Systems', 'Diploma', 'Technical College', 2025, 2),
(4, 'Marketing', 'Bachelor', 'Princess Nourah University', 2026, 4);

-- --------------------------------------------------------

--
-- `review`
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
--

INSERT INTO `review` (`ReviewID`, `Rating`, `ReviewText`, `CreatedAt`, `UserID`, `OpportunityID`) VALUES
(1, 5, 'Great training, very practical and well organized.', '2026-10-06 14:30:00', 9, 3),
(2, 4, 'Useful course, the instructor explained everything clearly.', '2026-10-07 11:15:00', 10, 7);

-- --------------------------------------------------------

--
-- `skill`
--

CREATE TABLE `skill` (
  `SkillID` int(11) NOT NULL,
  `SkillName` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
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
-- `user`
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
--

INSERT INTO `user` (`UserID`, `FirstName`, `LastName`, `Phone`, `DateOfBirth`, `Age`, `Gender`, `ProfilePicture`, `EducationalStatus`, `FieldOfStudy`) VALUES
(8, 'Sara', 'Alharbi', '0501111111', '2003-04-12', 23, 'Female', 'pics/sara.png', 'Undergraduate', 'Computer Science'),
(9, 'Noura', 'Alotaibi', '0502222222', '2002-09-25', 24, 'Female', 'pics/noura.png', 'Undergraduate', 'Marketing'),
(10, 'Ahmed', 'Alqahtani', '0503333333', '2001-01-30', 25, 'Male', 'pics/ahmed.png', 'Graduate', 'Business Administration'),
(11, 'Khalid', 'Aldosari', '0504444444', '2004-06-18', 22, 'Male', 'pics/khalid.png', 'Undergraduate', 'Information Systems');

--
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
-- `userexperience`
--

CREATE TABLE `userexperience` (
  `UserID` int(11) NOT NULL,
  `ExperienceID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `userexperience` (`UserID`, `ExperienceID`) VALUES
(9, 1),
(11, 1),
(10, 2),
(8, 3),
(9, 4);

-- --------------------------------------------------------

--
-- `usernotification`
--

CREATE TABLE `usernotification` (
  `UserID` int(11) NOT NULL,
  `NotificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `usernotification` (`UserID`, `NotificationID`) VALUES
(8, 1),
(10, 1),
(8, 2),
(8, 3),
(11, 3);

-- --------------------------------------------------------

--
-- `userqualification`
--

CREATE TABLE `userqualification` (
  `UserID` int(11) NOT NULL,
  `QualificationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--

INSERT INTO `userqualification` (`UserID`, `QualificationID`) VALUES
(8, 1),
(10, 2),
(11, 3),
(9, 4);

-- --------------------------------------------------------

--
-- `userskill`
--

CREATE TABLE `userskill` (
  `UserID` int(11) NOT NULL,
  `SkillID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
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
--   `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`AdminID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE;

--
--  `application`
--
ALTER TABLE `application`
  ADD CONSTRAINT `application_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- `company`
--
ALTER TABLE `company`
  ADD CONSTRAINT `company_ibfk_1` FOREIGN KEY (`CompanyID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE,
  ADD CONSTRAINT `company_ibfk_2` FOREIGN KEY (`VerifiedByAdminID`) REFERENCES `admin` (`AdminID`) ON DELETE SET NULL;

--
-- `favourite`
--
ALTER TABLE `favourite`
  ADD CONSTRAINT `favourite_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `favourite_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- `opportunity`
--
ALTER TABLE `opportunity`
  ADD CONSTRAINT `opportunity_ibfk_1` FOREIGN KEY (`CreatedByAdminID`) REFERENCES `admin` (`AdminID`) ON DELETE SET NULL,
  ADD CONSTRAINT `opportunity_ibfk_2` FOREIGN KEY (`CreatedByCompanyID`) REFERENCES `company` (`CompanyID`) ON DELETE SET NULL;

--
-- `opportunityexperience`
--
ALTER TABLE `opportunityexperience`
  ADD CONSTRAINT `opportunityexperience_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityexperience_ibfk_2` FOREIGN KEY (`ExperienceID`) REFERENCES `experience` (`ExperienceID`) ON DELETE CASCADE;

--
-- `opportunityqualification`
--
ALTER TABLE `opportunityqualification`
  ADD CONSTRAINT `opportunityqualification_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityqualification_ibfk_2` FOREIGN KEY (`QualificationID`) REFERENCES `qualification` (`QualificationID`) ON DELETE CASCADE;

--
-- `opportunityskill`
--
ALTER TABLE `opportunityskill`
  ADD CONSTRAINT `opportunityskill_ibfk_1` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE,
  ADD CONSTRAINT `opportunityskill_ibfk_2` FOREIGN KEY (`SkillID`) REFERENCES `skill` (`SkillID`) ON DELETE CASCADE;

--
-- `review`
--
ALTER TABLE `review`
  ADD CONSTRAINT `review_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `review_ibfk_2` FOREIGN KEY (`OpportunityID`) REFERENCES `opportunity` (`OpportunityID`) ON DELETE CASCADE;

--
-- `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `member` (`MemberID`) ON DELETE CASCADE;

--
-- `userexperience`
--
ALTER TABLE `userexperience`
  ADD CONSTRAINT `userexperience_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userexperience_ibfk_2` FOREIGN KEY (`ExperienceID`) REFERENCES `experience` (`ExperienceID`) ON DELETE CASCADE;

--
-- `usernotification`
--
ALTER TABLE `usernotification`
  ADD CONSTRAINT `usernotification_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `usernotification_ibfk_2` FOREIGN KEY (`NotificationID`) REFERENCES `notification` (`NotificationID`) ON DELETE CASCADE;

--
-- `userqualification`
--
ALTER TABLE `userqualification`
  ADD CONSTRAINT `userqualification_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userqualification_ibfk_2` FOREIGN KEY (`QualificationID`) REFERENCES `qualification` (`QualificationID`) ON DELETE CASCADE;

--
-- `userskill`
--
ALTER TABLE `userskill`
  ADD CONSTRAINT `userskill_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  ADD CONSTRAINT `userskill_ibfk_2` FOREIGN KEY (`SkillID`) REFERENCES `skill` (`SkillID`) ON DELETE CASCADE;

DELIMITER $$
--
--
CREATE DEFINER=`root`@`localhost` EVENT `evt_update_user_age` ON SCHEDULE EVERY 1 DAY STARTS '2026-10-09 00:00:00' ON COMPLETION NOT PRESERVE ENABLE DO UPDATE `User` SET Age = TIMESTAMPDIFF(YEAR, DateOfBirth, CURDATE())$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
