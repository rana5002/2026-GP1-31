<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

$uid = require_user();

/* Allowed values (must match the CHECK constraints in the database) */
const PROFILE_EDU_STATUSES = ['Student', 'Fresh Graduate', 'Graduate', 'Employee'];
const PROFILE_DEGREES      = ['High School', 'Diploma', "Bachelor's Degree", "Master's Degree", 'Doctoral Degree'];

function profile_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function profile_redirect(): void
{
    header('Location: profile.php');
    exit;
}

function profile_age(?string $dob): string
{
    if (!$dob) return '—';
    try {
        return (string)(new DateTime($dob))->diff(new DateTime())->y;
    } catch (Exception $e) {
        return '—';
    }
}

function profile_date(?string $date): string
{
    if (!$date) return '—';
    try {
        return (new DateTime($date))->format('M j, Y');
    } catch (Exception $e) {
        return $date;
    }
}

function profile_gender_label(?string $g): string
{
    return $g === 'M' ? 'Male' : ($g === 'F' ? 'Female' : '—');
}

function profile_duration(?string $start, ?string $end): string
{
    if (!$start) return '';
    try {
        $d = (new DateTime($start))->diff($end ? new DateTime($end) : new DateTime('today'));
        if ($d->y > 0) return $d->y . ' year(s)';
        if ($d->m > 0) return $d->m . ' month(s)';
        return 'Less than a month';
    } catch (Exception $e) {
        return '';
    }
}

function profile_valid_date(string $value): ?DateTime
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
    return $d->format('Y-m-d') === $value ? $d : null;
}

function profile_flash(string $type, string $message): void
{
    $_SESSION['profile_flash'] = ['type' => $type, 'message' => $message];
}

/* Load profile (user + member) */
$stmt = $pdo->prepare(
    'SELECT u.*, m.Email
     FROM `user` u
     JOIN member m ON m.MemberID = u.UserID
     WHERE u.UserID = ?
     LIMIT 1'
);
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    http_response_code(404);
    exit('User profile was not found.');
}

/* Handle profile updates */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'contact') {
            $phone = trim($_POST['Phone'] ?? '');
            $email = trim($_POST['Email'] ?? '');
            $dob = trim($_POST['DateOfBirth'] ?? '');
            $gender = $_POST['Gender'] ?? '';
            $password = $_POST['Password'] ?? '';

            if ($phone === '' || strlen($phone) > 15 || !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
                throw new RuntimeException('Please enter a valid phone number.');
            }
            if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if (!in_array($gender, ['M', 'F'], true)) {
                throw new RuntimeException('Please select your gender.');
            }
            if ($dob === '') {
                throw new RuntimeException('Please enter your date of birth.');
            }

            $birthDate = profile_valid_date($dob);
            $today = new DateTime('today');
            if (
                !$birthDate ||
                $birthDate > $today ||
                (int)$birthDate->diff($today)->y < 15 ||
                (int)$birthDate->diff($today)->y > 100
            ) {
                throw new RuntimeException('Date of birth must correspond to an age between 15 and 100.');
            }
            if ($password !== '' && strlen($password) < 8) {
                throw new RuntimeException('Password must be at least 8 characters.');
            }

            $stmt = $pdo->prepare('SELECT MemberID FROM member WHERE Email = ? AND MemberID <> ? LIMIT 1');
            $stmt->execute([$email, $uid]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Email already registered');
            }

            $pdo->beginTransaction();
            // Age is recalculated automatically by the database trigger (trg_user_age_update)
            $stmt = $pdo->prepare('UPDATE `user` SET Phone = ?, DateOfBirth = ?, Gender = ? WHERE UserID = ?');
            $stmt->execute([$phone, $dob, $gender, $uid]);

            if ($password !== '') {
                $stmt = $pdo->prepare('UPDATE member SET Email = ?, Password = ? WHERE MemberID = ?');
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $uid]);
            } else {
                $stmt = $pdo->prepare('UPDATE member SET Email = ? WHERE MemberID = ?');
                $stmt->execute([$email, $uid]);
            }
            $pdo->commit();
            profile_flash('success', 'Contact information updated successfully.');
            profile_redirect();
        }

        if ($action === 'name') {
            $firstName = trim($_POST['FirstName'] ?? '');
            $lastName = trim($_POST['LastName'] ?? '');
            if ($firstName === '' || $lastName === '') {
                throw new RuntimeException('First name and last name are required.');
            }
            if (mb_strlen($firstName) > 30 || mb_strlen($lastName) > 30) {
                throw new RuntimeException('First name and last name must be 30 characters or fewer.');
            }
            $stmt = $pdo->prepare('UPDATE `user` SET FirstName = ?, LastName = ? WHERE UserID = ?');
            $stmt->execute([$firstName, $lastName, $uid]);
            profile_flash('success', 'Name updated successfully.');
            profile_redirect();
        }

        /* Educational status (user.EducationalStatus) */
        if ($action === 'education') {
            $status = trim($_POST['EducationalStatus'] ?? '');
            if (!in_array($status, PROFILE_EDU_STATUSES, true)) {
                throw new RuntimeException('Please select a valid educational status.');
            }
            $stmt = $pdo->prepare('UPDATE `user` SET EducationalStatus = ? WHERE UserID = ?');
            $stmt->execute([$status, $uid]);
            profile_flash('success', 'Educational status updated successfully.');
            profile_redirect();
        }

        /* Studies (userstudy table) */
        if ($action === 'add_study') {
            $field = trim($_POST['FieldOfStudy'] ?? '');
            $degree = trim($_POST['DegreeLevel'] ?? '');
            $university = trim($_POST['UniversityName'] ?? '');
            $year = (int)($_POST['GraduationYear'] ?? 0);

            if ($field === '' || $degree === '' || $university === '') {
                throw new RuntimeException('Please complete the study fields.');
            }
            if (mb_strlen($field) > 100 || mb_strlen($university) > 100) {
                throw new RuntimeException('Field of study and university name must be 100 characters or fewer.');
            }
            if (!in_array($degree, PROFILE_DEGREES, true)) {
                throw new RuntimeException('Please select a valid degree level.');
            }
            if ($year < 1900 || $year > 2100) {
                throw new RuntimeException('Please enter a valid graduation year.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO userstudy (UserID, FieldOfStudy, DegreeLevel, UniversityName, GraduationYear)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $field, $degree, $university, $year]);
            profile_flash('success', 'Study added successfully.');
            profile_redirect();
        }

        if ($action === 'delete_study') {
            $fieldId = (int)($_POST['FieldID'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM userstudy WHERE FieldID = ? AND UserID = ?');
            $stmt->execute([$fieldId, $uid]);
            profile_flash('success', 'Study removed from your profile.');
            profile_redirect();
        }

        if ($action === 'skills') {
            $skillIds = $_POST['Skills'] ?? [];
            if (!is_array($skillIds)) $skillIds = [];
            $skillIds = array_values(array_unique(array_filter(array_map('intval', $skillIds), fn($id) => $id > 0)));

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM userskill WHERE UserID = ?');
            $stmt->execute([$uid]);

            if ($skillIds) {
                $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
                $stmt = $pdo->prepare("SELECT SkillID FROM skill WHERE SkillID IN ($placeholders)");
                $stmt->execute($skillIds);
                $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $insert = $pdo->prepare('INSERT INTO userskill (UserID, SkillID) VALUES (?, ?)');
                foreach ($validIds as $skillId) $insert->execute([$uid, $skillId]);
            }
            $pdo->commit();
            profile_flash('success', 'Skills updated successfully.');
            profile_redirect();
        }

        if ($action === 'picture') {
            if (empty($_FILES['ProfilePicture']) || $_FILES['ProfilePicture']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose a profile picture.');
            }
            $file = $_FILES['ProfilePicture'];
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('The image must be smaller than 5 MB.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Please upload a PNG or JPG image.');
            }

            $uploadDir = dirname(__DIR__) . '/uploads/profile-pictures/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to create the profile picture folder.');
            }
            $filename = 'user_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                throw new RuntimeException('Unable to save the profile picture.');
            }
            $relativePath = 'uploads/profile-pictures/' . $filename;
            $stmt = $pdo->prepare('UPDATE `user` SET ProfilePicture = ? WHERE UserID = ?');
            $stmt->execute([$relativePath, $uid]);
            profile_flash('success', 'Profile picture updated successfully.');
            profile_redirect();
        }

        /* Experience: `experience` is a lookup table; the details live in `userexperience` */
        if ($action === 'add_experience') {
            $experienceId = (int)($_POST['ExperienceID'] ?? 0);
            $jobTitle = trim($_POST['JobTitle'] ?? '');
            $organization = trim($_POST['Organization'] ?? '');
            $startDate = trim($_POST['StartDate'] ?? '');
            $endDate = trim($_POST['EndDate'] ?? '');

            if ($experienceId <= 0 || $jobTitle === '' || $organization === '' || $startDate === '') {
                throw new RuntimeException('Please fill in the required experience fields.');
            }
            if (mb_strlen($jobTitle) > 50) {
                throw new RuntimeException('Job title must be 50 characters or fewer.');
            }
            if (mb_strlen($organization) > 100) {
                throw new RuntimeException('Organization must be 100 characters or fewer.');
            }

            $start = profile_valid_date($startDate);
            $end = $endDate !== '' ? profile_valid_date($endDate) : null;
            if (!$start || ($endDate !== '' && !$end)) {
                throw new RuntimeException('Please enter valid experience dates.');
            }
            if ($end && $end < $start) {
                throw new RuntimeException('End date cannot be earlier than start date.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM experience WHERE ExperienceID = ?');
            $stmt->execute([$experienceId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Please select a valid experience type.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM userexperience WHERE UserID = ? AND ExperienceID = ?');
            $stmt->execute([$uid, $experienceId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('You have already added this experience type.');
            }

            // Duration is a generated column in the database, so it is not inserted
            $stmt = $pdo->prepare(
                'INSERT INTO userexperience (UserID, ExperienceID, JobTitle, Organization, StartDate, EndDate)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $experienceId, $jobTitle, $organization, $startDate, $endDate !== '' ? $endDate : null]);
            profile_flash('success', 'Experience added successfully.');
            profile_redirect();
        }

        /* Qualifications: `qualification` is a lookup table; the details live in `userqualification` */
        if ($action === 'add_qualification') {
            $qualificationId = (int)($_POST['QualificationID'] ?? 0);
            $institution = trim($_POST['Institution'] ?? '');
            $year = (int)($_POST['YearObtained'] ?? 0);
            $durationRaw = trim($_POST['Duration'] ?? '');

            if ($qualificationId <= 0 || $institution === '') {
                throw new RuntimeException('Please complete the qualification fields.');
            }
            if (mb_strlen($institution) > 100) {
                throw new RuntimeException('Institution must be 100 characters or fewer.');
            }
            if ($year < 1900 || $year > (int)date('Y')) {
                throw new RuntimeException('Please enter a valid year obtained.');
            }
            $duration = null;
            if ($durationRaw !== '') {
                $duration = (int)$durationRaw;
                if ($duration < 1 || $duration > 120) {
                    throw new RuntimeException('Duration must be between 1 and 120 months.');
                }
            }

            $stmt = $pdo->prepare('SELECT 1 FROM qualification WHERE QualificationID = ?');
            $stmt->execute([$qualificationId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Please select a valid qualification.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM userqualification WHERE UserID = ? AND QualificationID = ?');
            $stmt->execute([$uid, $qualificationId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('You have already added this qualification.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO userqualification (UserID, QualificationID, Institution, YearObtained, Duration)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $qualificationId, $institution, $year, $duration]);
            profile_flash('success', 'Qualification added successfully.');
            profile_redirect();
        }

        if ($action === 'delete_experience') {
            $experienceId = (int)($_POST['ExperienceID'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM userexperience WHERE UserID = ? AND ExperienceID = ?');
            $stmt->execute([$uid, $experienceId]);
            profile_flash('success', 'Experience removed from your profile.');
            profile_redirect();
        }

        if ($action === 'delete_qualification') {
            $qualificationId = (int)($_POST['QualificationID'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM userqualification WHERE UserID = ? AND QualificationID = ?');
            $stmt->execute([$uid, $qualificationId]);
            profile_flash('success', 'Qualification removed from your profile.');
            profile_redirect();
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        profile_flash('error', $e instanceof PDOException
            ? 'Unable to save your changes. Please check the information and try again.'
            : $e->getMessage());
        profile_redirect();
    }
}

/* Refresh profile after updates */
$stmt = $pdo->prepare('SELECT u.*, m.Email FROM `user` u JOIN member m ON m.MemberID = u.UserID WHERE u.UserID = ? LIMIT 1');
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

/* Read studies */
$stmt = $pdo->prepare('SELECT * FROM userstudy WHERE UserID = ? ORDER BY GraduationYear DESC, FieldID DESC');
$stmt->execute([$uid]);
$studies = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Read experience records */
$stmt = $pdo->prepare(
    'SELECT ue.ExperienceID, ue.JobTitle, ue.Organization, ue.StartDate, ue.EndDate, e.ExperienceName
     FROM userexperience ue
     JOIN experience e ON e.ExperienceID = ue.ExperienceID
     WHERE ue.UserID = ? ORDER BY ue.StartDate DESC'
);
$stmt->execute([$uid]);
$experiences = $stmt->fetchAll(PDO::FETCH_ASSOC);
$usedExperienceIds = array_map('intval', array_column($experiences, 'ExperienceID'));

$allExperienceTypes = $pdo->query('SELECT ExperienceID, ExperienceName FROM experience ORDER BY ExperienceName')->fetchAll(PDO::FETCH_ASSOC);
$experienceOptions = array_values(array_filter($allExperienceTypes, fn($e) => !in_array((int)$e['ExperienceID'], $usedExperienceIds, true)));

/* Read qualifications */
$stmt = $pdo->prepare(
    'SELECT uq.QualificationID, uq.Institution, uq.YearObtained, uq.Duration, q.QualificationName
     FROM userqualification uq
     JOIN qualification q ON q.QualificationID = uq.QualificationID
     WHERE uq.UserID = ? ORDER BY uq.YearObtained DESC'
);
$stmt->execute([$uid]);
$qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$usedQualificationIds = array_map('intval', array_column($qualifications, 'QualificationID'));

$allQualificationTypes = $pdo->query('SELECT QualificationID, QualificationName FROM qualification ORDER BY QualificationName')->fetchAll(PDO::FETCH_ASSOC);
$qualificationOptions = array_values(array_filter($allQualificationTypes, fn($q) => !in_array((int)$q['QualificationID'], $usedQualificationIds, true)));

/* Skills */
$skills = $pdo->query('SELECT SkillID, SkillName FROM skill ORDER BY SkillName')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT s.SkillID FROM userskill us JOIN skill s ON s.SkillID = us.SkillID WHERE us.UserID = ?');
$stmt->execute([$uid]);
$selectedSkills = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

$fullName = trim(($me['FirstName'] ?? '') . ' ' . ($me['LastName'] ?? ''));
$firstInitial = mb_substr($me['FirstName'] ?? '', 0, 1);
$lastInitial = mb_substr($me['LastName'] ?? '', 0, 1);

/* Main field of study = most recent study record */
$mainField = $studies[0]['FieldOfStudy'] ?? '';

user_header($me, 'My Profile', '');
?>

<style>
/* Profile page styled to match the Ufuq Admin Dashboard */
.profile-dashboard {
    --profile-purple: #39265f;
    --profile-purple-dark: #281a46;
    --profile-purple-mid: #49316f;
    --profile-lavender: #f0ebf8;
    --profile-gold: #e2a94b;
    --profile-text: #29243a;
    --profile-muted: #777184;
    --profile-bg: #faf6ef;
    color: var(--profile-text);
    padding-bottom: 50px;
    background: var(--profile-bg);
    min-height: 70vh;
}
.profile-dashboard * { box-sizing: border-box; }
.profile-dashboard .profile-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    background: linear-gradient(125deg, #281a46 0%, #39265f 55%, #49316f 100%);
    color: #fff;
}
.profile-dashboard .profile-hero::before,
.profile-dashboard .profile-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}
.profile-dashboard .profile-hero::before {
    width: 250px; height: 250px; right: 7%; top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}
.profile-dashboard .profile-hero::after {
    width: 190px; height: 190px; right: 23%; bottom: -145px;
    background: rgba(226,169,75,.10);
}
.profile-dashboard .profile-hero-inner {
    position: relative; z-index: 1; display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 22px;
}
.profile-dashboard .welcome-label {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; margin-bottom: 12px; border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px; background: rgba(255,255,255,.08); font-size: .84rem;
}
.profile-dashboard .profile-hero h1 {
    margin: 0; color: #fff; font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750; line-height: 1.5;
}
.profile-dashboard .profile-hero p { margin: 8px 0 0; color: rgba(255,255,255,.82); font-size: .98rem; }
.profile-dashboard .hero-icon {
    display: flex; align-items: center; justify-content: center; width: 78px; height: 78px;
    border: 1px solid rgba(255,255,255,.2); border-radius: 24px; background: rgba(255,255,255,.08);
    box-shadow: 0 10px 28px rgba(15,8,30,.16); font-size: 2rem; animation: profileFloat 4s ease-in-out infinite;
}
.profile-dashboard .profile-content { animation: profileFadeUp .55s ease both; }
.profile-dashboard .profile-alert {
    display: flex; align-items: center; gap: 10px; padding: 13px 16px; margin-bottom: 22px;
    border-radius: 13px; line-height: 1.6; animation: profileFadeUp .35s ease both;
}
.profile-dashboard .profile-alert.success { border: 1px solid #b8e5cf; background: #e4f6ed; color: #286546; }
.profile-dashboard .profile-alert.error { border: 1px solid #f0c6ca; background: #fbe6e7; color: #9a3038; }
.profile-dashboard .profile-identity,
.profile-dashboard .profile-section {
    border: 1px solid #ece7f2; border-radius: 16px; background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.profile-dashboard .profile-identity:hover,
.profile-dashboard .profile-section:hover {
    border-color: #d8cbe7; box-shadow: 0 9px 24px rgba(40,26,70,.075);
}
.profile-dashboard .profile-identity {
    display: flex; align-items: center; gap: 22px; padding: 25px; margin-bottom: 22px;
    animation: profileFadeUp .45s ease both;
}
.profile-dashboard .profile-avatar-wrap { position: relative; flex: 0 0 92px; width: 92px; height: 92px; }
.profile-dashboard .profile-avatar {
    display: flex; align-items: center; justify-content: center; width: 92px; height: 92px;
    overflow: hidden; border: 4px solid #f0ebf8; border-radius: 50%;
    background: var(--profile-lavender); color: var(--profile-purple); font-size: 1.8rem; font-weight: 750;
}
.profile-dashboard .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
.profile-dashboard .profile-avatar-upload {
    position: absolute; right: -3px; bottom: 0; display: flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border: 2px solid #fff; border-radius: 50%;
    background: var(--profile-purple); color: #fff; cursor: pointer; transition: transform .18s ease, background .18s ease;
}
.profile-dashboard .profile-avatar-upload:hover { transform: scale(1.07); background: var(--profile-purple-dark); }
.profile-dashboard .profile-identity-details { flex: 1; min-width: 0; }
.profile-dashboard .profile-name-row { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
.profile-dashboard .profile-name-row h2 { margin: 0; color: var(--profile-text); font-size: 1.35rem; overflow-wrap: anywhere; }
.profile-dashboard .profile-subtitle { margin-top: 6px; color: var(--profile-muted); font-size: .9rem; }
.profile-dashboard .profile-meta { margin-top: 9px; color: var(--profile-muted); font-size: .84rem; }
.profile-dashboard .profile-section {
    padding: 24px; margin-bottom: 20px; animation: profileFadeUp .45s ease both;
}
.profile-dashboard .profile-section-header {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
    padding-bottom: 17px; margin-bottom: 19px; border-bottom: 1px solid #e9e4ef;
}
.profile-dashboard .profile-section-header h2 {
    display: flex; align-items: center; gap: 10px; margin: 0; color: var(--profile-text);
    font-size: 1.08rem; font-weight: 750;
}
.profile-dashboard .profile-section-header h2::before {
    content: ""; display: block; width: 5px; height: 23px; border-radius: 5px; background: var(--profile-gold);
}
.profile-dashboard .profile-header-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.profile-dashboard .profile-view-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 21px 25px; }
.profile-dashboard .profile-field { min-width: 0; }
.profile-dashboard .profile-field-label { margin-bottom: 7px; color: var(--profile-muted); font-size: .82rem; }
.profile-dashboard .profile-field-value { color: var(--profile-text); font-size: .93rem; line-height: 1.65; overflow-wrap: anywhere; white-space: pre-wrap; }
.profile-dashboard .profile-form { margin-top: 5px; }
.profile-dashboard .profile-form .form-group { margin-bottom: 17px; }
.profile-dashboard .profile-form .form-label { display: block; margin-bottom: 7px; color: var(--profile-text); font-size: .87rem; font-weight: 650; }
.profile-dashboard .profile-form .form-control {
    width: 100%; box-sizing: border-box; border-radius: 9px; border-color: #ddd5e8;
    transition: border-color .18s ease, box-shadow .18s ease;
}
.profile-dashboard .profile-form .form-control:focus { border-color: var(--profile-purple); box-shadow: 0 0 0 3px rgba(57,38,95,.10); outline: none; }
.profile-dashboard .profile-form textarea { resize: vertical; }
.profile-dashboard .profile-form-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 19px; }
.profile-dashboard .profile-form-actions .btn-primary { background: var(--profile-purple); border-color: var(--profile-purple); }
.profile-dashboard .profile-form-actions .btn-primary:hover { background: var(--profile-purple-dark); border-color: var(--profile-purple-dark); }
.profile-dashboard .profile-hidden { display: none !important; }
.profile-dashboard .profile-record { padding: 17px 0; border-bottom: 1px solid #f0edf4; }
.profile-dashboard .profile-record:first-child { padding-top: 0; }
.profile-dashboard .profile-record:last-child { padding-bottom: 0; border-bottom: 0; }
.profile-dashboard .profile-record-title { margin: 0 0 5px; color: var(--profile-text); font-size: .96rem; font-weight: 750; }
.profile-dashboard .profile-record-subtitle { color: var(--profile-muted); font-size: .85rem; line-height: 1.7; }
.profile-dashboard .profile-record-actions { display: flex; justify-content: flex-end; margin-top: 8px; }
.profile-dashboard .profile-chip-list { display: flex; flex-wrap: wrap; gap: 8px; }
.profile-dashboard .profile-chip {
    display: inline-flex; padding: 7px 11px; border: 1px solid #e2d8ef; border-radius: 9px;
    background: var(--profile-lavender); color: var(--profile-purple); font-size: .82rem; font-weight: 600;
}
.profile-dashboard .profile-skills-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 11px; }
.profile-dashboard .profile-skill-option {
    display: flex; align-items: center; gap: 9px; padding: 11px 12px; border: 1px solid #e9e4ef;
    border-radius: 10px; font-size: .88rem; cursor: pointer; transition: border-color .18s ease, background .18s ease;
}
.profile-dashboard .profile-skill-option:has(input:checked) { border-color: var(--profile-purple); background: var(--profile-lavender); }
.profile-dashboard .profile-skill-option input { accent-color: var(--profile-purple); }
.profile-dashboard .profile-empty { color: var(--profile-muted); font-size: .9rem; line-height: 1.7; }
.profile-dashboard .profile-inline-form { padding-top: 17px; margin-top: 20px; border-top: 1px solid #e9e4ef; }
.profile-dashboard .profile-subheading { margin: 24px 0 14px; color: var(--profile-muted); font-size: .82rem; font-weight: 650; letter-spacing: .02em; }
.profile-dashboard .profile-danger-btn { border: 0; padding: 5px 8px; border-radius: 7px; background: transparent; color: #a43c45; font-size: .82rem; cursor: pointer; }
.profile-dashboard .profile-danger-btn:hover { background: #fff0f1; }
.profile-dashboard .icon-btn { border-radius: 9px; transition: background .18s ease, color .18s ease; }
.profile-dashboard .icon-btn:hover { background: var(--profile-lavender); color: var(--profile-purple); }
@media (max-width: 700px) {
    .profile-dashboard .profile-hero { padding: 30px 0; }
    .profile-dashboard .hero-icon { width: 60px; height: 60px; border-radius: 18px; font-size: 1.6rem; }
    .profile-dashboard .profile-identity { align-items: flex-start; gap: 15px; padding: 19px; }
    .profile-dashboard .profile-avatar-wrap, .profile-dashboard .profile-avatar { width: 70px; height: 70px; flex-basis: 70px; }
    .profile-dashboard .profile-avatar { font-size: 1.4rem; }
    .profile-dashboard .profile-name-row h2 { font-size: 1.1rem; }
    .profile-dashboard .profile-section { padding: 19px 16px; }
    .profile-dashboard .profile-view-grid, .profile-dashboard .profile-skills-grid { grid-template-columns: 1fr; gap: 17px; }
}
@keyframes profileFadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes profileFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@media (prefers-reduced-motion: reduce) {
    .profile-dashboard *, .profile-dashboard *::before, .profile-dashboard *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
}
</style>

<div class="profile-dashboard">
    <section class="profile-hero">
        <div class="container profile-hero-inner">
            <div>
                <div class="welcome-label"><span>✦</span><span>Ufuq User Profile</span></div>
                <h1>Welcome back, <?= profile_escape($me['FirstName'] ?? 'User') ?>!</h1>
                <p>Manage your personal information, education, experience, and skills.</p>
            </div>
            <div class="hero-icon" aria-hidden="true">✦</div>
        </div>
    </section>

    <div class="container profile-content">
        <?php if ($flash): ?>
            <div class="profile-alert <?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status">
                <span aria-hidden="true"><?= ($flash['type'] ?? '') === 'success' ? '✓' : '!' ?></span>
                <span><?= profile_escape($flash['message'] ?? '') ?></span>
            </div>
        <?php endif; ?>

        <section class="profile-identity">
            <div class="profile-avatar-wrap">
                <div class="profile-avatar">
                    <?php if (!empty($me['ProfilePicture'])): ?>
                        <img src="../<?= profile_escape(ltrim($me['ProfilePicture'], '/')) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= profile_escape(mb_strtoupper($firstInitial . $lastInitial)) ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="profile-avatar-upload" title="Change profile picture" aria-label="Change profile picture" onclick="document.getElementById('pictureInput').click()">
                    <?= icon('upload', 15) ?>
                </button>
            </div>
            <div class="profile-identity-details">
                <div class="profile-name-row">
                    <h2><?= profile_escape($fullName) ?></h2>
                    <button type="button" class="icon-btn" onclick="toggleProfileForm('nameForm')"><?= icon('edit', 14) ?> Edit</button>
                </div>
                <div class="profile-subtitle"><?= profile_escape($mainField ?: 'Field of study not specified') ?></div>
                <div class="profile-meta">
                    <?= profile_escape($me['EducationalStatus'] ?: 'Educational status not specified') ?>
                    · <?= profile_escape(profile_age($me['DateOfBirth'] ?? null)) ?> years old
                    <?php if (!empty($me['Gender'])): ?> · <?= profile_escape(profile_gender_label($me['Gender'])) ?><?php endif; ?>
                </div>
            </div>
        </section>

        <form id="pictureInputForm" method="post" enctype="multipart/form-data" class="profile-hidden">
            <input type="hidden" name="action" value="picture">
            <input type="file" id="pictureInput" name="ProfilePicture" accept=".png,.jpg,.jpeg,image/png,image/jpeg" onchange="if(this.files.length){this.form.submit();}">
        </form>

        <section class="profile-section profile-hidden" id="nameForm">
            <div class="profile-section-header"><h2>Edit Name</h2></div>
            <form method="post" class="profile-form">
                <input type="hidden" name="action" value="name">
                <div class="profile-view-grid">
                    <div class="form-group">
                        <label class="form-label" for="FirstName">First Name</label>
                        <input class="form-control" id="FirstName" name="FirstName" required maxlength="30" value="<?= profile_escape($me['FirstName']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="LastName">Last Name</label>
                        <input class="form-control" id="LastName" name="LastName" required maxlength="30" value="<?= profile_escape($me['LastName']) ?>">
                    </div>
                </div>
                <div class="profile-form-actions">
                    <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('nameForm')">Cancel</button>
                </div>
            </form>
        </section>

        <section class="profile-section">
            <div class="profile-section-header">
                <h2>Contact Information</h2>
                <button type="button" class="icon-btn" onclick="toggleProfileForm('contactForm')"><?= icon('edit', 14) ?> Edit</button>
            </div>
            <div class="profile-view-grid" id="contactView">
                <div class="profile-field"><div class="profile-field-label">Phone Number</div><div class="profile-field-value"><?= profile_escape($me['Phone'] ?: '—') ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Email Address</div><div class="profile-field-value"><?= profile_escape($me['Email'] ?: '—') ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Date of Birth</div><div class="profile-field-value"><?= profile_escape(profile_date($me['DateOfBirth'] ?? null)) ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Age</div><div class="profile-field-value"><?= profile_escape(profile_age($me['DateOfBirth'] ?? null)) ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Gender</div><div class="profile-field-value"><?= profile_escape(profile_gender_label($me['Gender'] ?? null)) ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Password</div><div class="profile-field-value">••••••••</div></div>
            </div>
            <div id="contactForm" class="profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="contact">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="Phone">Phone Number</label>
                            <input class="form-control" id="Phone" name="Phone" type="tel" required maxlength="15" pattern="\+?[0-9]{8,15}" value="<?= profile_escape($me['Phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="Email">Email Address</label>
                            <input class="form-control" id="Email" name="Email" type="email" required maxlength="100" value="<?= profile_escape($me['Email']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="DateOfBirth">Date of Birth</label>
                            <input class="form-control" id="DateOfBirth" name="DateOfBirth" type="date" min="<?= date('Y-m-d', strtotime('-100 years')) ?>" max="<?= date('Y-m-d', strtotime('-15 years')) ?>" required value="<?= profile_escape($me['DateOfBirth']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="Gender">Gender</label>
                            <select class="form-control" id="Gender" name="Gender" required>
                                <option value="">Select gender</option>
                                <option value="M" <?= $me['Gender'] === 'M' ? 'selected' : '' ?>>Male</option>
                                <option value="F" <?= $me['Gender'] === 'F' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="Password">New Password</label>
                            <input class="form-control" id="Password" name="Password" type="password" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password">
                            <small class="profile-empty">At least 8 characters. Your password is securely hashed before storage.</small>
                        </div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('contactForm')">Cancel</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="profile-section">
            <div class="profile-section-header">
                <h2>Education</h2>
                <div class="profile-header-actions">
                    <button type="button" class="icon-btn" onclick="toggleProfileForm('educationForm')"><?= icon('edit', 14) ?> Edit Status</button>
                    <button type="button" class="icon-btn" onclick="toggleProfileForm('studyForm')">+ Add Study</button>
                </div>
            </div>

            <div class="profile-view-grid" id="educationView">
                <div class="profile-field"><div class="profile-field-label">Educational Status</div><div class="profile-field-value"><?= profile_escape($me['EducationalStatus'] ?: '—') ?></div></div>
            </div>

            <div id="educationForm" class="profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="education">
                    <div class="form-group">
                        <label class="form-label" for="EducationalStatus">Educational Status</label>
                        <select class="form-control" id="EducationalStatus" name="EducationalStatus" required>
                            <?php foreach (PROFILE_EDU_STATUSES as $status): ?>
                                <option value="<?= profile_escape($status) ?>" <?= $me['EducationalStatus'] === $status ? 'selected' : '' ?>><?= profile_escape($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('educationForm')">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="profile-subheading">Studies</div>
            <?php if (!$studies): ?>
                <p class="profile-empty">No studies have been added yet.</p>
            <?php else: foreach ($studies as $study): ?>
                <div class="profile-record">
                    <h3 class="profile-record-title"><?= profile_escape($study['DegreeLevel']) ?> — <?= profile_escape($study['FieldOfStudy']) ?></h3>
                    <div class="profile-record-subtitle">
                        <?= profile_escape($study['UniversityName']) ?> · <?= (int)$study['GraduationYear'] ?>
                    </div>
                    <div class="profile-record-actions">
                        <form method="post" onsubmit="return confirm('Remove this study from your profile?')">
                            <input type="hidden" name="action" value="delete_study">
                            <input type="hidden" name="FieldID" value="<?= (int)$study['FieldID'] ?>">
                            <button class="profile-danger-btn" type="submit">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>

            <div id="studyForm" class="profile-inline-form profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="add_study">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="StudyDegree">Degree Level</label>
                            <select class="form-control" id="StudyDegree" name="DegreeLevel" required>
                                <option value="">Select degree level</option>
                                <?php foreach (PROFILE_DEGREES as $degree): ?>
                                    <option value="<?= profile_escape($degree) ?>"><?= profile_escape($degree) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="StudyField">Field of Study</label>
                            <input class="form-control" id="StudyField" name="FieldOfStudy" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="UniversityName">University / Institution</label>
                            <input class="form-control" id="UniversityName" name="UniversityName" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="GraduationYear">Graduation Year</label>
                            <input class="form-control" id="GraduationYear" name="GraduationYear" type="number" min="1900" max="2100" required>
                        </div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Add Study</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('studyForm')">Cancel</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="profile-section">
            <div class="profile-section-header">
                <h2>Experience</h2>
                <button type="button" class="icon-btn" onclick="toggleProfileForm('experienceForm')">+ Add Experience</button>
            </div>
            <?php if (!$experiences): ?>
                <p class="profile-empty">No experience has been added yet.</p>
            <?php else: foreach ($experiences as $experience): ?>
                <div class="profile-record">
                    <h3 class="profile-record-title"><?= profile_escape($experience['JobTitle']) ?></h3>
                    <div class="profile-record-subtitle">
                        <?= profile_escape($experience['ExperienceName']) ?> · <?= profile_escape($experience['Organization']) ?><br>
                        <?= profile_escape(profile_date($experience['StartDate'] ?? null)) ?> – <?= !empty($experience['EndDate']) ? profile_escape(profile_date($experience['EndDate'])) : 'Present' ?>
                        <?php $durationText = profile_duration($experience['StartDate'] ?? null, $experience['EndDate'] ?? null); ?>
                        <?php if ($durationText !== ''): ?> · <?= profile_escape($durationText) ?><?php endif; ?>
                    </div>
                    <div class="profile-record-actions">
                        <form method="post" onsubmit="return confirm('Remove this experience from your profile?')">
                            <input type="hidden" name="action" value="delete_experience">
                            <input type="hidden" name="ExperienceID" value="<?= (int)$experience['ExperienceID'] ?>">
                            <button class="profile-danger-btn" type="submit">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>

            <div id="experienceForm" class="profile-inline-form profile-hidden">
                <?php if (!$experienceOptions): ?>
                    <p class="profile-empty">You have already added every available experience type.</p>
                    <div class="profile-form-actions">
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('experienceForm')">Close</button>
                    </div>
                <?php else: ?>
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="add_experience">
                    <div class="form-group">
                        <label class="form-label" for="ExperienceID">Experience Type</label>
                        <select class="form-control" id="ExperienceID" name="ExperienceID" required>
                            <option value="">Select experience type</option>
                            <?php foreach ($experienceOptions as $opt): ?>
                                <option value="<?= (int)$opt['ExperienceID'] ?>"><?= profile_escape($opt['ExperienceName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label" for="JobTitle">Job Title</label><input class="form-control" id="JobTitle" name="JobTitle" required maxlength="50"></div>
                    <div class="form-group"><label class="form-label" for="Organization">Organization</label><input class="form-control" id="Organization" name="Organization" required maxlength="100"></div>
                    <div class="profile-view-grid">
                        <div class="form-group"><label class="form-label" for="StartDate">Start Date</label><input class="form-control" id="StartDate" name="StartDate" type="date" required></div>
                        <div class="form-group"><label class="form-label" for="EndDate">End Date</label><input class="form-control" id="EndDate" name="EndDate" type="date"><small class="profile-empty">Leave blank if this experience is ongoing.</small></div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Add Experience</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('experienceForm')">Cancel</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="profile-section">
            <div class="profile-section-header">
                <h2>Qualifications</h2>
                <button type="button" class="icon-btn" onclick="toggleProfileForm('qualificationForm')">+ Add Qualification</button>
            </div>
            <?php if (!$qualifications): ?>
                <p class="profile-empty">No qualifications have been added yet.</p>
            <?php else: foreach ($qualifications as $qualification): ?>
                <div class="profile-record">
                    <h3 class="profile-record-title"><?= profile_escape($qualification['QualificationName']) ?></h3>
                    <div class="profile-record-subtitle">
                        <?= profile_escape($qualification['Institution']) ?>
                        <?php if (!empty($qualification['YearObtained'])): ?> · <?= (int)$qualification['YearObtained'] ?><?php endif; ?>
                        <?php if (!empty($qualification['Duration'])): ?> · <?= (int)$qualification['Duration'] ?> month(s)<?php endif; ?>
                    </div>
                    <div class="profile-record-actions">
                        <form method="post" onsubmit="return confirm('Remove this qualification from your profile?')">
                            <input type="hidden" name="action" value="delete_qualification">
                            <input type="hidden" name="QualificationID" value="<?= (int)$qualification['QualificationID'] ?>">
                            <button class="profile-danger-btn" type="submit">Remove</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>

            <div id="qualificationForm" class="profile-inline-form profile-hidden">
                <?php if (!$qualificationOptions): ?>
                    <p class="profile-empty">You have already added every available qualification.</p>
                    <div class="profile-form-actions">
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('qualificationForm')">Close</button>
                    </div>
                <?php else: ?>
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="add_qualification">
                    <div class="form-group">
                        <label class="form-label" for="QualificationID">Qualification</label>
                        <select class="form-control" id="QualificationID" name="QualificationID" required>
                            <option value="">Select qualification</option>
                            <?php foreach ($qualificationOptions as $opt): ?>
                                <option value="<?= (int)$opt['QualificationID'] ?>"><?= profile_escape($opt['QualificationName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label" for="Institution">Institution</label><input class="form-control" id="Institution" name="Institution" required maxlength="100"></div>
                    <div class="profile-view-grid">
                        <div class="form-group"><label class="form-label" for="YearObtained">Year Obtained</label><input class="form-control" id="YearObtained" name="YearObtained" type="number" min="1900" max="<?= date('Y') ?>" required></div>
                        <div class="form-group"><label class="form-label" for="Duration">Duration (months)</label><input class="form-control" id="Duration" name="Duration" type="number" min="1" max="120"><small class="profile-empty">Optional (1–120 months).</small></div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Add Qualification</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('qualificationForm')">Cancel</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="profile-section">
            <div class="profile-section-header">
                <h2>Skills</h2>
                <button type="button" class="icon-btn" onclick="toggleProfileForm('skillsForm')"><?= icon('edit', 14) ?> Edit Skills</button>
            </div>
            <div class="profile-chip-list" id="skillsView">
                <?php
                $visibleSkills = array_values(array_filter($skills, fn($skill) => in_array((int)$skill['SkillID'], $selectedSkills, true)));
                ?>
                <?php if (!$visibleSkills): ?>
                    <span class="profile-empty">No skills have been selected yet.</span>
                <?php else: foreach ($visibleSkills as $skill): ?>
                    <span class="profile-chip"><?= profile_escape($skill['SkillName']) ?></span>
                <?php endforeach; endif; ?>
            </div>
            <div id="skillsForm" class="profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="skills">
                    <?php if (!$skills): ?>
                        <p class="profile-empty">No skills are available in the database yet.</p>
                    <?php else: ?>
                        <div class="profile-skills-grid">
                            <?php foreach ($skills as $skill): ?>
                                <label class="profile-skill-option">
                                    <input type="checkbox" name="Skills[]" value="<?= (int)$skill['SkillID'] ?>" <?= in_array((int)$skill['SkillID'], $selectedSkills, true) ? 'checked' : '' ?>>
                                    <?= profile_escape($skill['SkillName']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Skills</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('skillsForm')">Cancel</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
function toggleProfileForm(id) {
    const target = document.getElementById(id);
    if (!target) return;
    const willOpen = target.classList.contains('profile-hidden');

    document.querySelectorAll('.profile-inline-form, #nameForm, #contactForm, #educationForm, #skillsForm')
        .forEach(form => form.classList.add('profile-hidden'));

    if (willOpen) {
        target.classList.remove('profile-hidden');
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
</script>

<?php admin_footer(); ?>
