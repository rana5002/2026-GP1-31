<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

$uid = require_user();

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

function profile_flash(string $type, string $message): void
{
    $_SESSION['profile_flash'] = ['type' => $type, 'message' => $message];
}

/* Load profile */
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

            if ($phone === '' || !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
                throw new RuntimeException('Please enter a valid phone number.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if (!in_array($gender, ['Male', 'Female'], true)) {
                throw new RuntimeException('Please select your gender.');
            }
            if ($dob === '') {
                throw new RuntimeException('Please enter your date of birth.');
            }

            $birthDate = DateTime::createFromFormat('!Y-m-d', $dob);
            $dateErrors = DateTime::getLastErrors();
            if (
                !$birthDate ||
                ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $birthDate->format('Y-m-d') !== $dob ||
                $birthDate > new DateTime('today') ||
                (int)$birthDate->diff(new DateTime('today'))->y < 15 ||
                (int)$birthDate->diff(new DateTime('today'))->y > 100
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
            $stmt = $pdo->prepare('UPDATE `user` SET FirstName = ?, LastName = ? WHERE UserID = ?');
            $stmt->execute([$firstName, $lastName, $uid]);
            profile_flash('success', 'Name updated successfully.');
            profile_redirect();
        }

        if ($action === 'education') {
            $status = trim($_POST['EducationalStatus'] ?? '');
            $field = trim($_POST['FieldOfStudy'] ?? '');
            $allowedStatuses = ['Student', 'Fresh Graduate', 'Employed'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Please select a valid educational status.');
            }
            if ($field === '') {
                throw new RuntimeException('Field of study is required.');
            }
            $stmt = $pdo->prepare('UPDATE `user` SET EducationalStatus = ?, FieldOfStudy = ? WHERE UserID = ?');
            $stmt->execute([$status, $field, $uid]);
            profile_flash('success', 'Education information updated successfully.');
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

        if ($action === 'add_experience') {
            $jobTitle = trim($_POST['JobTitle'] ?? '');
            $organization = trim($_POST['Organization'] ?? '');
            $startDate = trim($_POST['StartDate'] ?? '');
            $endDate = trim($_POST['EndDate'] ?? '');

            if ($jobTitle === '' || $organization === '' || $startDate === '') {
                throw new RuntimeException('Please fill in the required experience fields.');
            }
            if ($endDate !== '' && $endDate < $startDate) {
                throw new RuntimeException('End date cannot be earlier than start date.');
            }
            $start = DateTime::createFromFormat('!Y-m-d', $startDate);
            $end = $endDate !== '' ? DateTime::createFromFormat('!Y-m-d', $endDate) : null;
            if (!$start || ($endDate !== '' && !$end)) {
                throw new RuntimeException('Please enter valid experience dates.');
            }

            $duration = $start->diff($end ?? new DateTime('today'));
            $durationText = $duration->y > 0 ? $duration->y . ' year(s)' :
                ($duration->m > 0 ? $duration->m . ' month(s)' : 'Less than a month');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO experience (JobTitle, Organization, StartDate, EndDate, Duration)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$jobTitle, $organization, $startDate, $endDate !== '' ? $endDate : null, $durationText]);
            $experienceId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO userexperience (UserID, ExperienceID) VALUES (?, ?)');
            $stmt->execute([$uid, $experienceId]);
            $pdo->commit();
            profile_flash('success', 'Experience added successfully.');
            profile_redirect();
        }

        if ($action === 'add_qualification') {
            $degree = trim($_POST['DegreeLevel'] ?? '');
            $institution = trim($_POST['Institution'] ?? '');
            $year = (int)($_POST['YearObtained'] ?? 0);
            $field = trim($_POST['QualificationField'] ?? '');
            if ($degree === '' || $institution === '' || $field === '') {
                throw new RuntimeException('Please complete the qualification fields.');
            }
            if ($year < 1900 || $year > (int)date('Y')) {
                throw new RuntimeException('Please enter a valid year obtained.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO qualification (FieldOfStudy, DegreeLevel, Institution, YearObtained, Duration)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$field, $degree, $institution, $year, null]);
            $qualificationId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO userqualification (UserID, QualificationID) VALUES (?, ?)');
            $stmt->execute([$uid, $qualificationId]);
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

/* Read experience records */
$stmt = $pdo->prepare(
    'SELECT e.* FROM userexperience ue
     JOIN experience e ON e.ExperienceID = ue.ExperienceID
     WHERE ue.UserID = ? ORDER BY e.StartDate DESC'
);
$stmt->execute([$uid]);
$experiences = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Read qualifications */
$stmt = $pdo->prepare(
    'SELECT q.* FROM userqualification uq
     JOIN qualification q ON q.QualificationID = uq.QualificationID
     WHERE uq.UserID = ? ORDER BY q.YearObtained DESC'
);
$stmt->execute([$uid]);
$qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$skills = $pdo->query('SELECT SkillID, SkillName FROM skill ORDER BY SkillName')->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT s.SkillID FROM userskill us JOIN skill s ON s.SkillID = us.SkillID WHERE us.UserID = ?');
$stmt->execute([$uid]);
$selectedSkills = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$flash = $_SESSION['profile_flash'] ?? null;
unset($_SESSION['profile_flash']);

$fullName = trim(($me['FirstName'] ?? '') . ' ' . ($me['LastName'] ?? ''));
$firstInitial = mb_substr($me['FirstName'] ?? '', 0, 1);
$lastInitial = mb_substr($me['LastName'] ?? '', 0, 1);

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
    color: var(--profile-text);
    padding-bottom: 50px;
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
                <div class="profile-subtitle"><?= profile_escape($me['FieldOfStudy'] ?: 'Field of study not specified') ?></div>
                <div class="profile-meta">
                    <?= profile_escape($me['EducationalStatus'] ?: 'Educational status not specified') ?>
                    · <?= profile_escape(profile_age($me['DateOfBirth'] ?? null)) ?> years old
                    <?php if (!empty($me['Gender'])): ?> · <?= profile_escape($me['Gender']) ?><?php endif; ?>
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
                        <input class="form-control" id="FirstName" name="FirstName" required maxlength="100" value="<?= profile_escape($me['FirstName']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="LastName">Last Name</label>
                        <input class="form-control" id="LastName" name="LastName" required maxlength="100" value="<?= profile_escape($me['LastName']) ?>">
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
                <div class="profile-field"><div class="profile-field-label">Gender</div><div class="profile-field-value"><?= profile_escape($me['Gender'] ?: '—') ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Password</div><div class="profile-field-value">••••••••</div></div>
            </div>
            <div id="contactForm" class="profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="contact">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="Phone">Phone Number</label>
                            <input class="form-control" id="Phone" name="Phone" type="tel" required pattern="\+?[0-9]{8,15}" value="<?= profile_escape($me['Phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="Email">Email Address</label>
                            <input class="form-control" id="Email" name="Email" type="email" required value="<?= profile_escape($me['Email']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="DateOfBirth">Date of Birth</label>
                            <input class="form-control" id="DateOfBirth" name="DateOfBirth" type="date" min="<?= date('Y-m-d', strtotime('-100 years')) ?>" max="<?= date('Y-m-d', strtotime('-15 years')) ?>" required value="<?= profile_escape($me['DateOfBirth']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="Gender">Gender</label>
                            <select class="form-control" id="Gender" name="Gender" required>
                                <option value="">Select gender</option>
                                <option value="Male" <?= $me['Gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $me['Gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
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
                <button type="button" class="icon-btn" onclick="toggleProfileForm('educationForm')"><?= icon('edit', 14) ?> Edit</button>
            </div>
            <div class="profile-view-grid" id="educationView">
                <div class="profile-field"><div class="profile-field-label">Educational Status</div><div class="profile-field-value"><?= profile_escape($me['EducationalStatus'] ?: '—') ?></div></div>
                <div class="profile-field"><div class="profile-field-label">Field of Study</div><div class="profile-field-value"><?= profile_escape($me['FieldOfStudy'] ?: '—') ?></div></div>
            </div>
            <div id="educationForm" class="profile-hidden">
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="education">
                    <div class="profile-view-grid">
                        <div class="form-group">
                            <label class="form-label" for="EducationalStatus">Educational Status</label>
                            <select class="form-control" id="EducationalStatus" name="EducationalStatus" required>
                                <?php foreach (['Student', 'Fresh Graduate', 'Employed'] as $status): ?>
                                    <option value="<?= profile_escape($status) ?>" <?= $me['EducationalStatus'] === $status ? 'selected' : '' ?>><?= profile_escape($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="FieldOfStudy">Field of Study</label>
                            <input class="form-control" id="FieldOfStudy" name="FieldOfStudy" required maxlength="150" value="<?= profile_escape($me['FieldOfStudy']) ?>">
                        </div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Save Changes</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('educationForm')">Cancel</button>
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
                        <?= profile_escape($experience['Organization']) ?><br>
                        <?= profile_escape(profile_date($experience['StartDate'] ?? null)) ?> – <?= profile_escape(profile_date($experience['EndDate'] ?? null)) ?>
                        <?php if (!empty($experience['Duration'])): ?> · <?= profile_escape($experience['Duration']) ?><?php endif; ?>
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
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="add_experience">
                    <div class="form-group"><label class="form-label" for="JobTitle">Job Title</label><input class="form-control" id="JobTitle" name="JobTitle" required maxlength="150"></div>
                    <div class="form-group"><label class="form-label" for="Organization">Organization</label><input class="form-control" id="Organization" name="Organization" required maxlength="150"></div>
                    <div class="profile-view-grid">
                        <div class="form-group"><label class="form-label" for="StartDate">Start Date</label><input class="form-control" id="StartDate" name="StartDate" type="date" required></div>
                        <div class="form-group"><label class="form-label" for="EndDate">End Date</label><input class="form-control" id="EndDate" name="EndDate" type="date"><small class="profile-empty">Leave blank if this experience is ongoing.</small></div>
                    </div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Add Experience</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('experienceForm')">Cancel</button>
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
                    <h3 class="profile-record-title">
                        <?= profile_escape($qualification['DegreeLevel']) ?>
                        <?php if (!empty($qualification['FieldOfStudy'])): ?> — <?= profile_escape($qualification['FieldOfStudy']) ?><?php endif; ?>
                    </h3>
                    <div class="profile-record-subtitle">
                        <?= profile_escape($qualification['Institution']) ?>
                        <?php if (!empty($qualification['YearObtained'])): ?> · <?= (int)$qualification['YearObtained'] ?><?php endif; ?>
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
                <form method="post" class="profile-form">
                    <input type="hidden" name="action" value="add_qualification">
                    <div class="form-group"><label class="form-label" for="DegreeLevel">Degree Level</label><input class="form-control" id="DegreeLevel" name="DegreeLevel" required maxlength="100" placeholder="e.g. Bachelor's Degree"></div>
                    <div class="form-group"><label class="form-label" for="QualificationField">Field of Study</label><input class="form-control" id="QualificationField" name="QualificationField" required maxlength="150"></div>
                    <div class="form-group"><label class="form-label" for="Institution">Institution</label><input class="form-control" id="Institution" name="Institution" required maxlength="150"></div>
                    <div class="form-group"><label class="form-label" for="YearObtained">Year Obtained</label><input class="form-control" id="YearObtained" name="YearObtained" type="number" min="1900" max="<?= date('Y') ?>" required></div>
                    <div class="profile-form-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Add Qualification</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProfileForm('qualificationForm')">Cancel</button>
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

<?php user_footer(); ?>
