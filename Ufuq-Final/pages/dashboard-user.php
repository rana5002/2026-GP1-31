<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
// Load shared lists used across the platform.
require_once __DIR__ . '/../includes/lists.php';

$uid = require_user();

/* Allowed values (must match the CHECK constraints in the database) */
const PROFILE_DEGREES      = ['High School', 'Diploma', "Bachelor's Degree", "Master's Degree", 'Doctoral Degree'];

function profile_escape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function profile_redirect(): void
{
    header('Location: dashboard-user.php');
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

            if ($phone === '') throw new FieldError('contactForm', 'Phone', 'This field is required.');
            if ($email === '') throw new FieldError('contactForm', 'Email', 'This field is required.');
            if ($dob === '') throw new FieldError('contactForm', 'DateOfBirth', 'This field is required.');
            if ($gender === '') throw new FieldError('contactForm', 'Gender', 'This field is required.');
            if (strlen($phone) > 15 || !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
                throw new FieldError('contactForm', 'Phone', 'Please enter a valid phone number.');
            }
            if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new FieldError('contactForm', 'Email', 'Please enter a valid email address.');
            }
            if (!in_array($gender, ['M', 'F'], true)) {
                throw new FieldError('contactForm', 'Gender', 'Please select your gender.');
            }
            if ($dob === '') {
                throw new FieldError('contactForm', 'DateOfBirth', 'Please enter your date of birth.');
            }

            $birthDate = profile_valid_date($dob);
            $today = new DateTime('today');
            if (
                !$birthDate ||
                $birthDate > $today ||
                (int)$birthDate->diff($today)->y < 15 ||
                (int)$birthDate->diff($today)->y > 100
            ) {
                throw new FieldError('contactForm', 'DateOfBirth', 'Date of birth must correspond to an age between 15 and 100.');
            }

            $stmt = $pdo->prepare('SELECT MemberID FROM member WHERE Email = ? AND MemberID <> ? LIMIT 1');
            $stmt->execute([$email, $uid]);
            if ($stmt->fetchColumn()) {
                throw new FieldError('contactForm', 'Email', 'Email already registered');
            }

            $pdo->beginTransaction();
            // Age is recalculated automatically by the database trigger (trg_user_age_update)
            $stmt = $pdo->prepare('UPDATE `user` SET Phone = ?, DateOfBirth = ?, Gender = ? WHERE UserID = ?');
            $stmt->execute([$phone, $dob, $gender, $uid]);

            $stmt = $pdo->prepare('UPDATE member SET Email = ? WHERE MemberID = ?');
            $stmt->execute([$email, $uid]);
            $pdo->commit();
            profile_flash('success', 'Contact information updated successfully.');
            profile_redirect();
        }

        /* Change password: current password, then the new password (own section). */
        if ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');

            if ($current === '') throw new FieldError('passwordForm', 'current_password', 'This field is required.');
            if ($new === '') throw new FieldError('passwordForm', 'new_password', 'This field is required.');

            $stmt = $pdo->prepare('SELECT Password FROM member WHERE MemberID = ?');
            $stmt->execute([$uid]);
            if (!password_verify($current, (string)$stmt->fetchColumn())) throw new FieldError('passwordForm', 'current_password', 'Current password is incorrect.');
            if (!is_strong_password($new)) throw new FieldError('passwordForm', 'new_password', PW_MSG);
            if ($new === $current) throw new FieldError('passwordForm', 'new_password', 'New password must be different from the current password.');

            $stmt = $pdo->prepare('UPDATE member SET Password = ? WHERE MemberID = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            send_password_changed_email($pdo, $uid);
            profile_flash('success', 'Password changed successfully.');
            profile_redirect();
        }

        if ($action === 'name') {
            $firstName = trim($_POST['FirstName'] ?? '');
            $lastName = trim($_POST['LastName'] ?? '');
            if ($firstName === '') throw new FieldError('nameForm', 'FirstName', 'This field is required.');
            if ($lastName === '') throw new FieldError('nameForm', 'LastName', 'This field is required.');
            if (mb_strlen($firstName) > 30 || mb_strlen($lastName) > 30) {
                throw new FieldError('nameForm', 'FirstName', 'First name and last name must be 30 characters or fewer.');
            }
            $stmt = $pdo->prepare('UPDATE `user` SET FirstName = ?, LastName = ? WHERE UserID = ?');
            $stmt->execute([$firstName, $lastName, $uid]);
            profile_flash('success', 'Name updated successfully.');
            profile_redirect();
        }

        /* Educational status (user.EducationalStatus) */
        if ($action === 'education') {
            $status = trim($_POST['EducationalStatus'] ?? '');
            if (!in_array($status, EDU_STATUSES, true)) {
                throw new FieldError('educationForm', 'EducationalStatus', 'Please select a valid educational status.');
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

            if ($degree === '') throw new FieldError('studyForm', 'StudyDegree', 'This field is required.');
            if ($field === '') throw new FieldError('studyForm', 'StudyField', 'This field is required.');
            if ($university === '') throw new FieldError('studyForm', 'UniversityName', 'This field is required.');
            if (mb_strlen($university) > 100) {
                throw new FieldError('studyForm', 'UniversityName', 'University name must be 100 characters or fewer.');
            }
            if (!in_array($field, FIELDS, true)) {
                throw new FieldError('studyForm', 'StudyField', 'Please select a valid field of study.');
            }
            if (!in_array($degree, PROFILE_DEGREES, true)) {
                throw new FieldError('studyForm', 'StudyDegree', 'Please select a valid degree level.');
            }
            if ($year < 1900 || $year > 2100) {
                throw new FieldError('studyForm', 'GraduationYear', 'Please enter a valid graduation year.');
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
                throw new FieldError('pictureForm', 'pictureInput', 'Please choose a profile picture.');
            }
            $file = $_FILES['ProfilePicture'];
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new FieldError('pictureForm', 'pictureInput', 'The image must be smaller than 5 MB.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($allowed[$mime])) {
                throw new FieldError('pictureForm', 'pictureInput', 'Please upload a PNG, JPG or JPEG image.');
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

            if ($experienceId <= 0) throw new FieldError('experienceForm', 'ExperienceID', 'This field is required.');
            if ($jobTitle === '') throw new FieldError('experienceForm', 'JobTitle', 'This field is required.');
            if ($organization === '') throw new FieldError('experienceForm', 'Organization', 'This field is required.');
            if ($startDate === '') throw new FieldError('experienceForm', 'StartDate', 'This field is required.');
            if (mb_strlen($jobTitle) > 50) {
                throw new FieldError('experienceForm', 'JobTitle', 'Job title must be 50 characters or fewer.');
            }
            if (mb_strlen($organization) > 100) {
                throw new FieldError('experienceForm', 'Organization', 'Organization must be 100 characters or fewer.');
            }

            $start = profile_valid_date($startDate);
            $end = $endDate !== '' ? profile_valid_date($endDate) : null;
            if (!$start || ($endDate !== '' && !$end)) {
                throw new FieldError('experienceForm', 'StartDate', 'Please enter valid experience dates.');
            }
            if ($end && $end < $start) {
                throw new FieldError('experienceForm', 'EndDate', 'End date cannot be earlier than start date.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM experience WHERE ExperienceID = ?');
            $stmt->execute([$experienceId]);
            if (!$stmt->fetchColumn()) {
                throw new FieldError('experienceForm', 'ExperienceID', 'Please select a valid experience type.');
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

            if ($qualificationId <= 0) throw new FieldError('qualificationForm', 'QualificationID', 'This field is required.');
            if ($institution === '') throw new FieldError('qualificationForm', 'Institution', 'This field is required.');
            if (mb_strlen($institution) > 100) {
                throw new FieldError('qualificationForm', 'Institution', 'Institution must be 100 characters or fewer.');
            }
            if ($year < 1900 || $year > (int)date('Y')) {
                throw new FieldError('qualificationForm', 'YearObtained', 'Please enter a valid year obtained.');
            }
            $duration = null;
            if ($durationRaw !== '') {
                $duration = (int)$durationRaw;
                if ($duration < 1 || $duration > 120) {
                    throw new FieldError('qualificationForm', 'Duration', 'Duration must be between 1 and 120 months.');
                }
            }

            $stmt = $pdo->prepare('SELECT 1 FROM qualification WHERE QualificationID = ?');
            $stmt->execute([$qualificationId]);
            if (!$stmt->fetchColumn()) {
                throw new FieldError('qualificationForm', 'QualificationID', 'Please select a valid qualification.');
            }

            

            $stmt = $pdo->prepare(
                'INSERT INTO userqualification (UserID, QualificationID, Institution, YearObtained, Duration)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $qualificationId, $institution, $year, $duration]);
            profile_flash('success', 'Qualification added successfully.');
            profile_redirect();
        }


        /* Edit an experience (the type can be changed too; the same type may be used more than once) */
        if ($action === 'edit_experience') {
            $recordId = (int)($_POST['UserExperienceID'] ?? 0);
            $experienceId = (int)($_POST['ExperienceID'] ?? 0);
            $jobTitle = trim($_POST['JobTitle'] ?? '');
            $organization = trim($_POST['Organization'] ?? '');
            $startDate = trim($_POST['StartDate'] ?? '');
            $endDate = trim($_POST['EndDate'] ?? '');

            if ($experienceId <= 0) throw new FieldError('editExperienceForm', 'EditExperienceID', 'This field is required.');
            if ($jobTitle === '') throw new FieldError('editExperienceForm', 'EditJobTitle', 'This field is required.');
            if ($organization === '') throw new FieldError('editExperienceForm', 'EditOrganization', 'This field is required.');
            if ($startDate === '') throw new FieldError('editExperienceForm', 'EditStartDate', 'This field is required.');
            if (mb_strlen($jobTitle) > 50) {
                throw new FieldError('editExperienceForm', 'EditJobTitle', 'Job title must be 50 characters or fewer.');
            }
            if (mb_strlen($organization) > 100) {
                throw new FieldError('editExperienceForm', 'EditOrganization', 'Organization must be 100 characters or fewer.');
            }

            $start = profile_valid_date($startDate);
            $end = $endDate !== '' ? profile_valid_date($endDate) : null;
            if (!$start || ($endDate !== '' && !$end)) {
                throw new FieldError('editExperienceForm', 'EditStartDate', 'Please enter valid experience dates.');
            }
            if ($end && $end < $start) {
                throw new FieldError('editExperienceForm', 'EditEndDate', 'End date cannot be earlier than start date.');
            }

            /* The record must belong to this user. */
            $stmt = $pdo->prepare('SELECT 1 FROM userexperience WHERE UserID = ? AND UserExperienceID = ?');
            $stmt->execute([$uid, $recordId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('This experience was not found on your profile.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM experience WHERE ExperienceID = ?');
            $stmt->execute([$experienceId]);
            if (!$stmt->fetchColumn()) {
                throw new FieldError('editExperienceForm', 'EditExperienceID', 'Please select a valid experience type.');
            }

            // Duration is a generated column in the database, so it is not updated
            $stmt = $pdo->prepare(
                'UPDATE userexperience SET ExperienceID = ?, JobTitle = ?, Organization = ?, StartDate = ?, EndDate = ?
                 WHERE UserID = ? AND UserExperienceID = ?'
            );
            $stmt->execute([$experienceId, $jobTitle, $organization, $startDate, $endDate !== '' ? $endDate : null, $uid, $recordId]);
            profile_flash('success', 'Experience updated successfully.');
            profile_redirect();
        }

        /* Edit a qualification (the qualification can be changed too; the same one may be used more than once) */
        if ($action === 'edit_qualification') {
            $recordId = (int)($_POST['UserQualificationID'] ?? 0);
            $qualificationId = (int)($_POST['QualificationID'] ?? 0);
            $institution = trim($_POST['Institution'] ?? '');
            $year = (int)($_POST['YearObtained'] ?? 0);
            $durationRaw = trim($_POST['Duration'] ?? '');

            if ($qualificationId <= 0) throw new FieldError('editQualificationForm', 'EditQualificationID', 'This field is required.');
            if ($institution === '') throw new FieldError('editQualificationForm', 'EditInstitution', 'This field is required.');
            if (mb_strlen($institution) > 100) {
                throw new FieldError('editQualificationForm', 'EditInstitution', 'Institution must be 100 characters or fewer.');
            }
            if ($year < 1900 || $year > (int)date('Y')) {
                throw new FieldError('editQualificationForm', 'EditYearObtained', 'Please enter a valid year obtained.');
            }
            $duration = null;
            if ($durationRaw !== '') {
                $duration = (int)$durationRaw;
                if ($duration < 1 || $duration > 120) {
                    throw new FieldError('editQualificationForm', 'EditDuration', 'Duration must be between 1 and 120 months.');
                }
            }

            /* The record must belong to this user. */
            $stmt = $pdo->prepare('SELECT 1 FROM userqualification WHERE UserID = ? AND UserQualificationID = ?');
            $stmt->execute([$uid, $recordId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('This qualification was not found on your profile.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM qualification WHERE QualificationID = ?');
            $stmt->execute([$qualificationId]);
            if (!$stmt->fetchColumn()) {
                throw new FieldError('editQualificationForm', 'EditQualificationID', 'Please select a valid qualification.');
            }

            $stmt = $pdo->prepare(
                'UPDATE userqualification SET QualificationID = ?, Institution = ?, YearObtained = ?, Duration = ?
                 WHERE UserID = ? AND UserQualificationID = ?'
            );
            $stmt->execute([$qualificationId, $institution, $year, $duration, $uid, $recordId]);
            profile_flash('success', 'Qualification updated successfully.');
            profile_redirect();
        }


        if ($action === 'delete_experience') {
            $recordId = (int)($_POST['UserExperienceID'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM userexperience WHERE UserID = ? AND UserExperienceID = ?');
            $stmt->execute([$uid, $recordId]);
            profile_flash('success', 'Experience removed from your profile.');
            profile_redirect();
        }

        if ($action === 'delete_qualification') {
            $recordId = (int)($_POST['UserQualificationID'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM userqualification WHERE UserID = ? AND UserQualificationID = ?');
            $stmt->execute([$uid, $recordId]);
            profile_flash('success', 'Qualification removed from your profile.');
            profile_redirect();
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        profile_flash('error', $e instanceof PDOException
            ? 'Unable to save your changes. Please check the information and try again.'
            : $e->getMessage());
        if ($e instanceof FieldError) {
            /* Shown under the field; the form reopens with what the user typed (no passwords). */
            $old = array_filter($_POST, fn($v, $k) => is_string($v) && !in_array($k, ['action', 'current_password', 'new_password'], true), ARRAY_FILTER_USE_BOTH);
            $_SESSION['profile_flash'] += ['form' => $e->form, 'field' => $e->field, 'old' => $old];
        }
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
    'SELECT ue.UserExperienceID, ue.ExperienceID, ue.JobTitle, ue.Organization, ue.StartDate, ue.EndDate, e.ExperienceName
     FROM userexperience ue
     JOIN experience e ON e.ExperienceID = ue.ExperienceID
     WHERE ue.UserID = ? ORDER BY ue.StartDate DESC'
);
$stmt->execute([$uid]);
$experiences = $stmt->fetchAll(PDO::FETCH_ASSOC);
$usedExperienceIds = array_map('intval', array_column($experiences, 'ExperienceID'));

$allExperienceTypes = $pdo->query('SELECT ExperienceID, ExperienceName FROM experience ORDER BY ExperienceName')->fetchAll(PDO::FETCH_ASSOC);
$experienceOptions = $allExperienceTypes; // the same type can be added more than once

/* Read qualifications */
$stmt = $pdo->prepare(
    'SELECT uq.UserQualificationID, uq.QualificationID, uq.Institution, uq.YearObtained, uq.Duration, q.QualificationName
     FROM userqualification uq
     JOIN qualification q ON q.QualificationID = uq.QualificationID
     WHERE uq.UserID = ? ORDER BY uq.YearObtained DESC'
);
$stmt->execute([$uid]);
$qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$usedQualificationIds = array_map('intval', array_column($qualifications, 'QualificationID'));

$allQualificationTypes = $pdo->query('SELECT QualificationID, QualificationName FROM qualification ORDER BY QualificationName')->fetchAll(PDO::FETCH_ASSOC);
$qualificationOptions = $allQualificationTypes; // the same qualification can be added more than once

/* Skills */
$skills = $pdo->query('SELECT SkillID, SkillName FROM skill ORDER BY SkillName')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT s.SkillID FROM userskill us JOIN skill s ON s.SkillID = us.SkillID WHERE us.UserID = ?');
$stmt->execute([$uid]);
$selectedSkills = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

/* Error that belongs to one field: shown under the field instead of at the top. */
$fieldError = null;
if ($flash && ($flash['type'] ?? '') === 'error' && !empty($flash['field'])) {
    $fieldError = ['form' => $flash['form'], 'field' => $flash['field'], 'message' => $flash['message'], 'old' => $flash['old'] ?? []];
    $flash = null;
}

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
.profile-dashboard .profile-record-actions { display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin-top: 8px; }
.profile-dashboard .profile-record-actions form { margin: 0; display: flex; }
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
.profile-dashboard .profile-danger-btn { display: inline-flex; align-items: center; gap: 6px; border: 0; padding: 6px 10px; border-radius: 9px; background: transparent; color: #a43c45; font-family: inherit; font-size: .85rem; font-weight: 600; cursor: pointer; transition: background .18s ease; }
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
/* Success message: slides down from the top, then disappears (same as the other pages) */
.ufuq-toast {
    position: fixed; top: 18px; left: 50%; z-index: 1000; display: flex; align-items: center; gap: 11px;
    width: max-content; max-width: calc(100% - 32px); padding: 13px 14px 13px 16px;
    border: 1px solid #bfe3cc; border-radius: 13px; background: #eaf7ef; color: #1f6b47;
    font-size: .92rem; font-weight: 700; line-height: 1.5; box-shadow: 0 12px 30px rgba(31,107,71,.16);
    transform: translate(-50%, -150%); opacity: 0; transition: transform .35s ease, opacity .35s ease;
}
.ufuq-toast.is-shown { transform: translate(-50%, 0); opacity: 1; }
.ufuq-toast-icon {
    display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; flex-shrink: 0;
    border-radius: 50%; background: #2f8a5c; color: #fff; font-size: .85rem;
}
.ufuq-toast-close {
    display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; flex-shrink: 0;
    padding: 0; border: 0; border-radius: 8px; background: none; color: #1f6b47; font-size: 1.2rem; line-height: 1; cursor: pointer;
}
.ufuq-toast-close:hover { background: rgba(31,107,71,.1); }
/* Drop-down list with search (skills, experience, qualifications, ...) */
.search-select{position:relative}
.search-select > select{display:none!important}
.search-select-toggle{display:flex!important;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;cursor:pointer;background:#fff}
.search-select-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.search-select-text.is-placeholder{color:#8a8396}
.search-select-caret{flex-shrink:0;color:#8a8396;font-size:.8rem;transition:transform .18s ease}
.search-select.is-open .search-select-caret{transform:rotate(180deg)}
.search-select-panel{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:40;padding:10px;border:1px solid #e5ddef;border-radius:12px;background:#fff;box-shadow:0 12px 30px rgba(40,26,70,.14)}
.search-select-panel[hidden]{display:none!important}
.search-select-list{max-height:210px;margin-top:8px;overflow-y:auto;overscroll-behavior:contain}
.search-select-option{display:flex;align-items:center;gap:9px;padding:8px;border-radius:8px;color:#29243a;font-size:.9rem;line-height:1.5;cursor:pointer;overflow-wrap:anywhere}
.search-select-option:hover,.search-select-option.is-selected{background:#f2ecfa}
.search-select-option.is-selected{color:#281a46;font-weight:650}
.search-select-option[hidden]{display:none!important}
.search-select-option input{width:16px;height:16px;flex-shrink:0;margin:0;accent-color:#39265f;cursor:pointer}
.search-select-empty{padding:10px 8px;color:#8a8396;font-size:.85rem;text-align:center}
.search-select-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.search-select-chips:empty{display:none}
.search-select-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 11px;border:1px solid #d9cbea;border-radius:999px;background:#f2ecfa;color:#281a46;font-size:.84rem;font-weight:700}
.search-select-chip button{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;padding:0;border:0;border-radius:50%;background:none;color:#39265f;font-size:1rem;line-height:1;cursor:pointer}
.search-select-chip button:hover{color:#d94b56;background:rgba(217,75,86,.09)}
/* Field validation: red field + message under it (same as the other forms) */
.profile-dashboard .form-control.invalid{border:1.5px solid #d94b56!important;background-color:#fff7f7!important;box-shadow:0 0 0 3px rgba(217,75,86,.08)}
.profile-dashboard .field-error{display:none;margin-top:7px;color:#c43643;font-size:.84rem;line-height:1.5}
.profile-dashboard .required-mark{color:#d94b56;font-weight:800;margin-left:2px}
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
        <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
            <div class="ufuq-toast" id="successToast" role="status" aria-live="polite">
                <span class="ufuq-toast-icon" aria-hidden="true">✓</span>
                <span><?= profile_escape($flash['message'] ?? '') ?></span>
                <button type="button" class="ufuq-toast-close" aria-label="Close">&times;</button>
            </div>
        <?php elseif ($flash): ?>
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

        <section class="profile-section profile-hidden" id="pictureForm">
            <div class="profile-section-header"><h2>Change Profile Picture</h2></div>
            <form id="pictureInputForm" method="post" enctype="multipart/form-data" class="profile-form" data-validate>
                <input type="hidden" name="action" value="picture">
                <input type="file" id="pictureInput" name="ProfilePicture" accept=".png,.jpg,.jpeg,image/png,image/jpeg" class="profile-hidden" required onchange="previewImage(this, 'pictureForm')">
                <p class="profile-empty">Selected image: <strong data-file-name></strong></p>
                <div class="profile-form-actions">
                    <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="cancelImage('pictureInput', 'pictureForm')">Cancel</button>
                </div>
            </form>
        </section>

        <section class="profile-section profile-hidden" id="nameForm">
            <div class="profile-section-header"><h2>Edit Name</h2></div>
            <form method="post" class="profile-form" data-validate>
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
            </div>
            <div id="contactForm" class="profile-hidden">
                <form method="post" class="profile-form" data-validate>
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
                <h2>Password</h2>
                <button type="button" class="icon-btn" onclick="toggleProfileForm('passwordForm')"><?= icon('edit', 14) ?> Change Password</button>
            </div>
            <div class="profile-view-grid">
                <div class="profile-field"><div class="profile-field-label">Password</div><div class="profile-field-value">••••••••</div></div>
            </div>
            <div id="passwordForm" class="profile-hidden">
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="change_password">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input class="form-control" id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password">
                            <small class="profile-empty">At least 8 characters, including an uppercase and a lowercase letter.</small>
                        </div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('passwordForm')">Cancel</button>
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
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="education">
                    <div class="form-group">
                        <label class="form-label" for="EducationalStatus">Educational Status</label>
                        <select class="form-control" id="EducationalStatus" name="EducationalStatus" required>
                            <?php foreach (EDU_STATUSES as $status): ?>
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
                            <button class="profile-danger-btn" type="submit"><?= icon('delete', 14) ?> Remove</button>                          </form>
                    </div>
                </div>
            <?php endforeach; endif; ?>

            <div id="studyForm" class="profile-inline-form profile-hidden">
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="add_study">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="StudyDegree">Degree Level</label>
                            <select class="form-control" id="StudyDegree" name="DegreeLevel" required data-search>
                                <option value="">Select degree level</option>
                                <?php foreach (PROFILE_DEGREES as $degree): ?>
                                    <option value="<?= profile_escape($degree) ?>"><?= profile_escape($degree) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="StudyField">Field of Study</label>
                            <select class="form-control" id="StudyField" name="FieldOfStudy" required data-search>
                                     <option value="">Select field of study</option>
                                        <?php foreach (FIELDS as $f): ?>
                                     <option value="<?= profile_escape($f) ?>"><?= profile_escape($f) ?></option>
                                         <?php endforeach; ?>
                            </select>
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

                    <button type="button" class="icon-btn" onclick="editRecord('experience', <?= (int)$experience['UserExperienceID'] ?>)"><?= icon('edit', 14) ?> Edit</button>
                        <form method="post" onsubmit="return confirm('Remove this experience from your profile?')">
                            <input type="hidden" name="action" value="delete_experience">
                            <input type="hidden" name="UserExperienceID" value="<?= (int)$experience['UserExperienceID'] ?>">   
                         <button class="profile-danger-btn" type="submit"><?= icon('delete', 14) ?> Remove</button>
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
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="add_experience">
                    <div class="form-group">
                        <label class="form-label" for="ExperienceID">Experience Type</label>
                        <select class="form-control" id="ExperienceID" name="ExperienceID" required data-search>
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

            <div id="editExperienceForm" class="profile-inline-form profile-hidden">
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="edit_experience">
                    <input type="hidden" name="UserExperienceID" id="UserExperienceID" value="">
                    <div class="form-group">
                        <label class="form-label" for="EditExperienceID">Experience Type</label>
                        <select class="form-control" id="EditExperienceID" name="ExperienceID" required>
                            <?php foreach ($allExperienceTypes as $opt): ?>
                                <option value="<?= (int)$opt['ExperienceID'] ?>"><?= profile_escape($opt['ExperienceName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label" for="EditJobTitle">Job Title</label><input class="form-control" id="EditJobTitle" name="JobTitle" required maxlength="50"></div>
                    <div class="form-group"><label class="form-label" for="EditOrganization">Organization</label><input class="form-control" id="EditOrganization" name="Organization" required maxlength="100"></div>
                    <div class="profile-view-grid">
                        <div class="form-group"><label class="form-label" for="EditStartDate">Start Date</label><input class="form-control" id="EditStartDate" name="StartDate" type="date" required></div>
                        <div class="form-group"><label class="form-label" for="EditEndDate">End Date</label><input class="form-control" id="EditEndDate" name="EndDate" type="date"><small class="profile-empty">Leave blank if this experience is ongoing.</small></div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('editExperienceForm')">Cancel</button>
                    </div>
                </form>
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


                        <button type="button" class="icon-btn" onclick="editRecord('qualification', <?= (int)$qualification['UserQualificationID'] ?>)"><?= icon('edit', 14) ?> Edit</button>

                        <form method="post" onsubmit="return confirm('Remove this qualification from your profile?')">
                            <input type="hidden" name="action" value="delete_qualification">
                            <input type="hidden" name="UserQualificationID" value="<?= (int)$qualification['UserQualificationID'] ?>">
                            <button class="profile-danger-btn" type="submit"><?= icon('delete', 14) ?> Remove</button>
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
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="add_qualification">
                    <div class="form-group">
                        <label class="form-label" for="QualificationID">Qualification</label>
                        <select class="form-control" id="QualificationID" name="QualificationID" required data-search>
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

                <div id="editQualificationForm" class="profile-inline-form profile-hidden">
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="edit_qualification">
                    <input type="hidden" name="UserQualificationID" id="UserQualificationID" value="">
                    <div class="form-group">
                        <label class="form-label" for="EditQualificationID">Qualification</label>
                        <select class="form-control" id="EditQualificationID" name="QualificationID" required>
                            <?php foreach ($allQualificationTypes as $opt): ?>
                                <option value="<?= (int)$opt['QualificationID'] ?>"><?= profile_escape($opt['QualificationName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label" for="EditInstitution">Institution</label><input class="form-control" id="EditInstitution" name="Institution" required maxlength="100"></div>
                    <div class="profile-view-grid">
                        <div class="form-group"><label class="form-label" for="EditYearObtained">Year Obtained</label><input class="form-control" id="EditYearObtained" name="YearObtained" type="number" min="1900" max="<?= date('Y') ?>" required></div>
                        <div class="form-group"><label class="form-label" for="EditDuration">Duration (months)</label><input class="form-control" id="EditDuration" name="Duration" type="number" min="1" max="120"><small class="profile-empty">Optional.</small></div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('editQualificationForm')">Cancel</button>
                    </div>
                </form>
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
                <form method="post" class="profile-form" data-validate>
                    <input type="hidden" name="action" value="skills">
                    <?php if (!$skills): ?>
                        <p class="profile-empty">No skills are available in the database yet.</p>
                    <?php else: ?>
                        <div class="form-group">
                            <label class="form-label" for="SkillsSelect">Skills</label>
                            <select class="form-control" id="SkillsSelect" name="Skills[]" multiple data-search data-placeholder="Select skills">
                                <?php foreach ($skills as $skill): ?>
                                    <option value="<?= (int)$skill['SkillID'] ?>" <?= in_array((int)$skill['SkillID'], $selectedSkills, true) ? 'selected' : '' ?>><?= profile_escape($skill['SkillName']) ?></option>
                                <?php endforeach; ?>
                            </select>
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
/* Toast: slides down, stays for a few seconds, then slides up and is removed. */
(function () {
    const toast = document.getElementById('successToast');
    if (!toast) return;
    document.body.appendChild(toast);
    function hide() {
        toast.classList.remove('is-shown');
        setTimeout(function () { toast.remove(); }, 400);
    }
    requestAnimationFrame(function () { toast.classList.add('is-shown'); });
    const timer = setTimeout(hide, 3500);
    toast.querySelector('.ufuq-toast-close').addEventListener('click', function () {
        clearTimeout(timer);
        hide();
    });
})();

/* Drop-down list with search: turns <select data-search> into a list you can type in.
   One choice (experience, qualification, city, ...) or several (skills, with chips).
   The real <select> stays in the form, so saving works the same. */
function makeSearchSelect(select) {
    const multiple = select.multiple;
    const wrap = document.createElement('div');
    wrap.className = 'search-select';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);

    const firstEmpty = Array.from(select.options).find(function (o) { return o.value === ''; });
    const placeholder = select.dataset.placeholder || (firstEmpty ? firstEmpty.textContent : 'Select...');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'form-control search-select-toggle';
    toggle.setAttribute('aria-haspopup', 'listbox');
    toggle.setAttribute('aria-expanded', 'false');
    if (select.id) toggle.id = select.id + 'Toggle';
    toggle.innerHTML = '<span class="search-select-text"></span><span class="search-select-caret" aria-hidden="true">&#9662;</span>';
    wrap.appendChild(toggle);
    select._searchToggle = toggle;
    if (select.id) {
        const label = document.querySelector('label[for="' + select.id + '"]');
        if (label) label.htmlFor = toggle.id;
    }

    const panel = document.createElement('div');
    panel.className = 'search-select-panel';
    panel.hidden = true;
    panel.innerHTML = '<input type="text" class="form-control search-select-search" placeholder="Search..." autocomplete="off"><div class="search-select-list" role="listbox"></div><div class="search-select-empty" hidden>No matches found</div>';
    wrap.appendChild(panel);
    const search = panel.querySelector('.search-select-search');
    const list = panel.querySelector('.search-select-list');
    const empty = panel.querySelector('.search-select-empty');

    let chips = null;
    if (multiple) {
        chips = document.createElement('div');
        chips.className = 'search-select-chips';
        wrap.appendChild(chips);
    }

    Array.from(select.options).forEach(function (opt) {
        if (opt.value === '') return;
        const row = document.createElement(multiple ? 'label' : 'div');
        row.className = 'search-select-option';
        row.dataset.value = opt.value;
        row.dataset.text = opt.textContent.toLowerCase();
        if (multiple) {
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = opt.selected;
            box.addEventListener('change', function () {
                opt.selected = box.checked;
                select.dispatchEvent(new Event('change'));
            });
            row.appendChild(box);
        } else {
            row.setAttribute('role', 'option');
            row.addEventListener('click', function () {
                select.value = opt.value;
                select.dispatchEvent(new Event('change'));
                close();
                toggle.focus();
            });
        }
        const text = document.createElement('span');
        text.textContent = opt.textContent;
        row.appendChild(text);
        list.appendChild(row);
    });

    function refresh() {
        const chosen = Array.from(select.selectedOptions).filter(function (o) { return o.value !== ''; });
        const textEl = toggle.querySelector('.search-select-text');
        if (multiple) {
            textEl.textContent = chosen.length ? chosen.length + ' selected' : placeholder;
            list.querySelectorAll('.search-select-option').forEach(function (row) {
                const opt = Array.from(select.options).find(function (o) { return o.value === row.dataset.value; });
                row.querySelector('input').checked = !!(opt && opt.selected);
            });
            chips.innerHTML = '';
            chosen.forEach(function (opt) {
                const chip = document.createElement('span');
                chip.className = 'search-select-chip';
                chip.textContent = opt.textContent;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Remove ' + opt.textContent);
                remove.innerHTML = '&times;';
                remove.addEventListener('click', function () {
                    opt.selected = false;
                    select.dispatchEvent(new Event('change'));
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        } else {
            textEl.textContent = chosen.length ? chosen[0].textContent : placeholder;
            list.querySelectorAll('.search-select-option').forEach(function (row) {
                row.classList.toggle('is-selected', row.dataset.value === select.value);
            });
        }
        textEl.classList.toggle('is-placeholder', chosen.length === 0);
    }

    function open() {
        document.querySelectorAll('.search-select.is-open').forEach(function (other) {
            if (other !== wrap) other._close();
        });
        panel.hidden = false;
        wrap.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        search.value = '';
        filter();
        search.focus();
    }

    function close() {
        panel.hidden = true;
        wrap.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }
    wrap._close = close;

    function filter() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        list.querySelectorAll('.search-select-option').forEach(function (row) {
            const match = row.dataset.text.indexOf(q) !== -1;
            row.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown !== 0;
    }

    toggle.addEventListener('click', function () { panel.hidden ? open() : close(); });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!multiple) {
                const first = list.querySelector('.search-select-option:not([hidden])');
                if (first) first.click();
            }
        }
        if (e.key === 'Escape') { close(); toggle.focus(); }
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    select.addEventListener('change', refresh);
    refresh();
}

document.querySelectorAll('select[data-search]').forEach(makeSearchSelect);

/* Field validation (same as the other forms): the field turns red and the message shows under it.
   Errors found by the server (for example "Email already registered") are shown the same way. */
const PW_RULE = /^(?=.*[a-z])(?=.*[A-Z]).{8,}$/;
const PW_TEXT = <?= json_encode(PW_MSG) ?>;
const FIELD_RULES = {
    Phone: { msg: 'Please enter a valid phone number.' },
    DateOfBirth: { msg: 'Date of birth must correspond to an age between 15 and 100.' },
    new_password: { strong: true, notSameAs: 'current_password' },
    GraduationYear: { msg: 'Please enter a valid graduation year.' },
    YearObtained: { msg: 'Please enter a valid year obtained.' },
    Duration: { msg: 'Duration must be between 1 and 120 months.' },
    EndDate: { after: 'StartDate', afterMsg: 'End date cannot be earlier than start date.' },
    EditYearObtained: { msg: 'Please enter a valid year obtained.' },
    EditDuration: { msg: 'Duration must be between 1 and 120 months.' },
    EditEndDate: { after: 'EditStartDate', afterMsg: 'End date cannot be earlier than start date.' }
};

function setFieldError(el, msg) {
    el.classList.toggle('invalid', !!msg);
    if (el._searchToggle) el._searchToggle.classList.toggle('invalid', !!msg);
    const holder = el.closest('.form-group') || el.closest('form');
    let box = holder.querySelector(':scope > .field-error');
    if (!box) {
        box = document.createElement('div');
        box.className = 'field-error';
        holder.appendChild(box);
    }
    box.textContent = msg || '';
    box.style.display = msg ? 'block' : 'none';
}

function checkField(el) {
    const rule = FIELD_RULES[el.id] || {};
    if (el.type === 'file') {
        const file = el.files[0];
        if (!file) return el.required ? 'Please choose an image.' : '';
        return /\.(png|jpe?g)$/i.test(file.name) ? '' : 'Please upload a PNG, JPG or JPEG image.';
    }
    if (el.type === 'checkbox') return '';
    const v = (el.value || '').trim();
    if (el.required && v === '') return 'This field is required.';
    if (v === '') return '';
    if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.';
    if (el.type === 'url') {
        try { if (!/^https?:$/.test(new URL(v).protocol)) return 'Please enter a valid website URL.'; }
        catch (_e) { return 'Please enter a valid website URL.'; }
    }
    if (el.getAttribute('pattern') && !new RegExp('^(?:' + el.getAttribute('pattern') + ')$').test(v)) return rule.msg || 'Please enter a valid value.';
    if (el.type === 'number') {
        const n = Number(v);
        if (!Number.isInteger(n) || (el.min !== '' && n < Number(el.min)) || (el.max !== '' && n > Number(el.max))) return rule.msg || 'Please enter a valid number.';
    }
    if (el.type === 'date' && ((el.min && v < el.min) || (el.max && v > el.max))) return rule.msg || 'Please enter a valid date.';
    if (rule.strong && !PW_RULE.test(el.value)) return PW_TEXT;
    if (rule.notSameAs) {
        const other = document.getElementById(rule.notSameAs);
        if (other && other.value !== '' && other.value === el.value) return 'New password must be different from the current password.';
    }
    if (el.minLength > 0 && v.length < el.minLength) return rule.minMsg || ('Please enter at least ' + el.minLength + ' characters.');
    if (rule.after) {
        const start = document.getElementById(rule.after);
        if (start && start.value && v < start.value) return rule.afterMsg;
    }
    return '';
}

document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.noValidate = true;
    const fields = Array.from(form.querySelectorAll('input, select, textarea'))
        .filter(function (el) { return el.name && el.type !== 'hidden'; });

    fields.forEach(function (el) {
        /* Red star next to the label of each required field. */
        if (el.required && el.id) {
            const label = form.querySelector('label[for="' + el.id + '"], label[for="' + el.id + 'Toggle"]');
            if (label && !label.querySelector('.required-mark')) label.insertAdjacentHTML('beforeend', '<span class="required-mark">*</span>');
        }
        ['input', 'change'].forEach(function (evt) {
            el.addEventListener(evt, function () {
                if (el.classList.contains('invalid')) setFieldError(el, checkField(el));
            });
        });
    });

    form.addEventListener('submit', function (e) {
        let first = null;
        fields.forEach(function (el) {
            const msg = checkField(el);
            setFieldError(el, msg);
            if (msg && !first) first = el;
        });
        if (first) {
            e.preventDefault();
            if (first.type !== 'file') (first._searchToggle || first).focus();
        }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const serverError = <?= json_encode($fieldError) ?>;
    if (!serverError) return;
    const panel = document.getElementById(serverError.form);
    if (panel && panel.classList.contains('profile-hidden')) {
        if (serverError.field === 'pictureInput' || serverError.field === 'logoInput') panel.classList.remove('profile-hidden');
        else toggleProfileForm(serverError.form);
    }
    /* Keep what the user typed (passwords are never sent back). */
    Object.keys(serverError.old || {}).forEach(function (name) {
        const el = panel ? panel.querySelector('[name="' + name + '"]') : null;
        if (el && ['file', 'password', 'checkbox'].indexOf(el.type) === -1) {
            el.value = serverError.old[name];
            if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change'));
        }
    });

if (serverError.form === 'editExperienceForm') fillEditRecord('experience', +((serverError.old || {}).UserExperienceID || 0));
if (serverError.form === 'editQualificationForm') fillEditRecord('qualification', +((serverError.old || {}).UserQualificationID || 0));
    const field = document.getElementById(serverError.field);
    if (field) {
        setFieldError(field, serverError.message);
        if (field.type !== 'file') (field._searchToggle || field).focus();
    }
});

/* New picture: show a preview, then save it only when "Save Changes" is clicked. */
function previewImage(input, panelId) {
    const panel = document.getElementById(panelId);
    const avatar = document.querySelector('.profile-avatar');
    if (!input.files.length || !panel || !avatar) return;
    panel.querySelector('[data-file-name]').textContent = input.files[0].name;
    panel.classList.remove('profile-hidden');
    const formatError = checkField(input);
    setFieldError(input, formatError);
    if (formatError) {
        if (avatar.dataset.original !== undefined) avatar.innerHTML = avatar.dataset.original;
        return;
    }
    if (avatar.dataset.original === undefined) avatar.dataset.original = avatar.innerHTML;
    const img = document.createElement('img');
    img.src = URL.createObjectURL(input.files[0]);
    img.alt = 'New picture preview';
    avatar.innerHTML = '';
    avatar.appendChild(img);
    panel.querySelector('[data-file-name]').textContent = input.files[0].name;
    panel.classList.remove('profile-hidden');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelImage(inputId, panelId) {
    const input = document.getElementById(inputId);
    const panel = document.getElementById(panelId);
    const avatar = document.querySelector('.profile-avatar');
    if (input) {
        input.value = '';
        setFieldError(input, '');
    }
    if (avatar && avatar.dataset.original !== undefined) avatar.innerHTML = avatar.dataset.original;
    if (panel) panel.classList.add('profile-hidden');
}


/* Edit an experience / qualification: fill the edit form with the saved values and open it. */
const EDIT_DATA = {
    experience: <?= json_encode(array_column($experiences, null, 'UserExperienceID'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    qualification: <?= json_encode(array_column($qualifications, null, 'UserQualificationID'), JSON_HEX_TAG | JSON_HEX_AMP) ?>
};

function fillEditRecord(kind, id) {
    const row = (EDIT_DATA[kind] || {})[id];
    if (!row) return;
    if (kind === 'experience') {
        document.getElementById('UserExperienceID').value = id;
        document.getElementById('EditExperienceID').value = row.ExperienceID;
        document.getElementById('EditJobTitle').value = row.JobTitle || '';
        document.getElementById('EditOrganization').value = row.Organization || '';
        document.getElementById('EditStartDate').value = row.StartDate || '';
        document.getElementById('EditEndDate').value = row.EndDate || '';
    } else {
        document.getElementById('UserQualificationID').value = id;
        document.getElementById('EditQualificationID').value = row.QualificationID;
        document.getElementById('EditInstitution').value = row.Institution || '';
        document.getElementById('EditYearObtained').value = row.YearObtained || '';
        document.getElementById('EditDuration').value = row.Duration || '';
    }
}

function editRecord(kind, id) {
    const panelId = kind === 'experience' ? 'editExperienceForm' : 'editQualificationForm';
    const panel = document.getElementById(panelId);
    if (!panel) return;
    fillEditRecord(kind, id);
    panel.querySelectorAll('.invalid').forEach(function (el) { setFieldError(el, ''); });
    if (panel.classList.contains('profile-hidden')) toggleProfileForm(panelId);
    else panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}




function toggleProfileForm(id) {
    const target = document.getElementById(id);
    if (!target) return;
    const willOpen = target.classList.contains('profile-hidden');

    document.querySelectorAll('.profile-inline-form, #nameForm, #contactForm, #passwordForm, #educationForm, #skillsForm')
        .forEach(form => form.classList.add('profile-hidden'));

    if (willOpen) {
        target.classList.remove('profile-hidden');
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
