<?php
session_start();
require __DIR__ . '/db.php';

/* ================= Config ================= */
const PHONE_USER  = '/^05[0-9]{8}$/';     // Saudi mobile: 05XXXXXXXX
const PHONE_COMP  = '/^0[15][0-9]{8}$/';  // company: mobile 05XXXXXXXX or landline 01XXXXXXXX
const CR_REGEX    = '/^[1-9][0-9]{9}$/';  // Saudi CR number: exactly 10 digits
const DEGREE_LEVELS = ['High School', 'Diploma', 'Bachelor', 'Master', 'PhD'];
const EXP_ERR  = 'Please complete every field in each experience entry. Dates cannot be in the future, and the end date cannot be before the start date.';
const QUAL_ERR = 'Please complete every field in each qualification entry.';
const PW_MSG      = 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.';
const IMG_EXT     = ['png', 'jpg', 'jpeg'];
const IMG_MIME    = ['image/png', 'image/jpeg'];
const DOC_EXT     = ['pdf', 'png', 'jpg', 'jpeg'];
const DOC_MIME    = ['application/pdf', 'image/png', 'image/jpeg'];

$EDU_STATUSES = ['Student', 'Fresh Graduate', 'Employed'];
$FIELDS       = ['Computer Science', 'Information Systems', 'Business Administration', 'Marketing', 'Engineering',
                 'Medicine & Health Sciences', 'Law', 'Education', 'Design', 'Other'];
$INDUSTRIES   = ['Technology', 'Education & Training', 'Marketing', 'Trading', 'Media', 'Administration',
                 'Healthcare', 'Finance', 'Engineering & Construction', 'Retail', 'Other'];
$CITIES       = ['Riyadh', 'Jeddah', 'Dammam', 'Khobar', 'Makkah', 'Madinah', 'Abha', 'Tabuk', 'Online'];

/* ================= Helpers ================= */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function v(array $a, string $k) { return e($a[$k] ?? ''); }
function sel(array $a, string $k, string $val) { return (($a[$k] ?? '') === $val) ? ' selected' : ''; }
function inv(array $errs, string $k) { return isset($errs[$k]) ? ' invalid' : ''; }
function req() { return '<span class="req">*</span>'; }
function icon($n, $extra = '', $cls = '') {
    $u = "../images-website/icons/$n.png";
    return '<span class="icon ' . $cls . '" style="-webkit-mask-image:url(\'' . $u . '\');mask-image:url(\'' . $u . '\');' . $extra . '"></span>';
}
function errBox(array $errs, string $id, string $default, string $key) {
    $show = isset($errs[$key]) ? 'block' : 'none';
    $msg  = $errs[$key] ?? $default;
    return '<div class="form-error" id="' . $id . 'Error" data-msg="' . e($default) . '" style="display:' . $show . ';">' . e($msg) . '</div>';
}
function arr_val(array $p, string $k, int $i): string {
    $a = $p[$k] ?? [];
    if (!is_array($a)) return '';
    $a = array_values($a);
    return (isset($a[$i]) && is_string($a[$i])) ? trim($a[$i]) : '';
}
function entry_count(array $p, string $k): int {
    return is_array($p[$k] ?? null) ? count($p[$k]) : 0;
}
/** Experience entries: title, organization, start, end (duration is computed by the DB). Fully empty entries are ignored. */
function parse_experience(array $p, ?array &$bad, ?string &$err): array {
    $rows = []; $bad = []; $err = null;
    $today = new DateTime('today');
    for ($i = 0, $n = entry_count($p, 'exp_title'); $i < $n; $i++) {
        $t = arr_val($p, 'exp_title', $i); $o = arr_val($p, 'exp_org', $i);
        $s = arr_val($p, 'exp_start', $i); $e = arr_val($p, 'exp_end', $i);
        if ($t === '' && $o === '' && $s === '' && $e === '') continue;
        $b = [];
        if ($t === '' || mb_strlen($t) > 100) $b[] = 'title';
        if ($o === '' || mb_strlen($o) > 150) $b[] = 'org';
        $sd = DateTime::createFromFormat('!Y-m-d', $s);
        $ed = DateTime::createFromFormat('!Y-m-d', $e);
        $sOk = $sd && $sd->format('Y-m-d') === $s && $sd <= $today;
        $eOk = $ed && $ed->format('Y-m-d') === $e && $ed <= $today;
        if (!$sOk) $b[] = 'start';
        if (!$eOk) $b[] = 'end';
        if ($sOk && $eOk && $ed < $sd) $b[] = 'end';
        if ($b) { $bad[$i] = $b; $err = EXP_ERR; } else { $rows[] = [$t, $o, $s, $e]; }
    }
    return $rows;
}
/** Qualification entries: field of study, degree level, year obtained, duration (years). */
function parse_qualifications(array $p, ?array &$bad, ?string &$err): array {
    $rows = []; $bad = []; $err = null;
    $yMax = (int)date('Y'); $yMin = $yMax - 60;
    for ($i = 0, $n = entry_count($p, 'qual_field'); $i < $n; $i++) {
        $f  = arr_val($p, 'qual_field', $i);  $dg = arr_val($p, 'qual_degree', $i);
        $yr = arr_val($p, 'qual_year', $i);   $du = arr_val($p, 'qual_duration', $i);
        if ($f === '' && $dg === '' && $yr === '' && $du === '') continue;
        $b = [];
        if ($f === '' || mb_strlen($f) > 100) $b[] = 'field';
        if (!in_array($dg, DEGREE_LEVELS, true)) $b[] = 'degree';
        if (!ctype_digit($yr) || (int)$yr < $yMin || (int)$yr > $yMax) $b[] = 'year';
        if (!ctype_digit($du) || (int)$du < 1 || (int)$du > 10) $b[] = 'duration';
        if ($b) { $bad[$i] = $b; $err = QUAL_ERR; } else { $rows[] = [$f, $dg, (int)$yr, (int)$du]; }
    }
    return $rows;
}
function exp_row(array $v = [], array $bad = []): string {
    $c = function ($f) use ($bad) { return in_array($f, $bad, true) ? ' invalid' : ''; };
    $today = date('Y-m-d');
    ob_start(); ?>
    <div class="entry-row">
      <div class="form-group">
        <label class="form-label">Job Title</label>
        <input type="text" name="exp_title[]" class="form-control entry-input<?= $c('title') ?>" maxlength="100" value="<?= e($v['title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Organization</label>
        <input type="text" name="exp_org[]" class="form-control entry-input<?= $c('org') ?>" maxlength="150" value="<?= e($v['org'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Start Date</label>
        <input type="date" name="exp_start[]" class="form-control entry-input<?= $c('start') ?>" max="<?= $today ?>" value="<?= e($v['start'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">End Date</label>
        <input type="date" name="exp_end[]" class="form-control entry-input<?= $c('end') ?>" max="<?= $today ?>" value="<?= e($v['end'] ?? '') ?>">
      </div>
      <button type="button" class="entry-remove" aria-label="Remove this entry" title="Remove">&times;</button>
    </div>
    <?php
    return ob_get_clean();
}
function qual_row(array $v = [], array $bad = []): string {
    $c = function ($f) use ($bad) { return in_array($f, $bad, true) ? ' invalid' : ''; };
    $yMax = (int)date('Y');
    ob_start(); ?>
    <div class="entry-row">
      <div class="form-group">
        <label class="form-label">Field of Study</label>
        <input type="text" name="qual_field[]" class="form-control entry-input<?= $c('field') ?>" maxlength="100" value="<?= e($v['field'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Degree Level</label>
        <select name="qual_degree[]" class="form-control entry-input<?= $c('degree') ?>">
          <option value="">Select&hellip;</option>
          <?php foreach (DEGREE_LEVELS as $d): ?>
            <option value="<?= e($d) ?>"<?= (($v['degree'] ?? '') === $d) ? ' selected' : '' ?>><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Year Obtained</label>
        <select name="qual_year[]" class="form-control entry-input<?= $c('year') ?>">
          <option value="">Select&hellip;</option>
          <?php for ($y = $yMax; $y >= $yMax - 60; $y--): ?>
            <option value="<?= $y ?>"<?= (($v['year'] ?? '') === (string)$y) ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Duration</label>
        <select name="qual_duration[]" class="form-control entry-input<?= $c('duration') ?>">
          <option value="">Select&hellip;</option>
          <?php for ($i = 1; $i <= 10; $i++): ?>
            <option value="<?= $i ?>"<?= (($v['duration'] ?? '') === (string)$i) ? ' selected' : '' ?>><?= $i ?> <?= $i === 1 ? 'year' : 'years' ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <button type="button" class="entry-remove" aria-label="Remove this entry" title="Remove">&times;</button>
    </div>
    <?php
    return ob_get_clean();
}
function pwHelp(string $id): string {
    $items = ['len' => 'At least 8 characters', 'upper' => 'One uppercase letter (A-Z)', 'lower' => 'One lowercase letter (a-z)',
              'num' => 'One number (0-9)', 'special' => 'One special character (! @ # $ % ...)'];
    $li = '';
    foreach ($items as $rule => $text) $li .= '<li data-rule="' . $rule . '">' . $text . '</li>';
    return '<div class="pw-help"><button type="button" class="pw-help-btn" data-target="' . $id . 'Rules" aria-expanded="false" aria-label="Show password requirements">!</button>'
         . '<span class="pw-help-label">Password requirements</span></div>'
         . '<ul class="pw-rules is-hidden" id="' . $id . 'Rules" data-for="' . $id . '">' . $li . '</ul>';
}
function is_strong_password(string $p): bool {
    return strlen($p) >= 8
        && preg_match('/[a-z]/', $p)
        && preg_match('/[A-Z]/', $p)
        && preg_match('/\d/', $p)
        && preg_match('/[^A-Za-z0-9]/', $p);
}
/** returns 'none' | 'bad' | 'ok' */
function check_file(string $key, array $exts, array $mimes): string {
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) return 'none';
    $f = $_FILES[$key];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return 'bad';
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) return 'bad';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    return in_array($mime, $mimes, true) ? 'ok' : 'bad';
}
function store_file(string $key, string $sub): string {
    $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
    $dir = __DIR__ . '/../uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    move_uploaded_file($_FILES[$key]['tmp_name'], "$dir/$name");
    return "$sub/$name";
}
function delete_files(array $paths): void {
    foreach ($paths as $p) { @unlink(__DIR__ . '/../uploads/' . $p); }
}

/* Skills from DB */
$allSkills = $pdo->query('SELECT SkillName FROM skill ORDER BY SkillName')->fetchAll(PDO::FETCH_COLUMN);

/* ================= Handle POST ================= */
$type    = (($_POST['account_type'] ?? 'User') === 'Company') ? 'Company' : 'User';
$errors  = [];
$banner  = '';
$pending = false;
$expBad = []; $qualBad = []; $expRows = []; $qualRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emptyFound = false;
    $p = $_POST;

    /* ---------- USER ---------- */
    if ($type === 'User') {
        $first  = trim($p['first_name'] ?? '');
        $last   = trim($p['last_name'] ?? '');
        $email  = trim($p['email'] ?? '');
        $phone  = trim($p['phone'] ?? '');
        $edu    = $p['edu_status'] ?? '';
        $field  = $p['field_of_study'] ?? '';
        if ($field === 'Other') $field = trim($p['field_other'] ?? '');
        $dob    = trim($p['dob'] ?? '');
        $gender = $p['gender'] ?? '';
        $pass   = $p['password'] ?? '';
        $pass2  = $p['password2'] ?? '';

        // required fields
        foreach ([
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone,
            'edu_status' => $edu, 'field_of_study' => $field, 'dob' => $dob, 'gender' => $gender,
            'password' => $pass, 'password2' => $pass2,
        ] as $k => $val) {
            if ($val === '') { $errors[$k] = 'This field is required.'; $emptyFound = true; }
        }

        if (!isset($errors['first_name']) && mb_strlen($first) > 50) $errors['first_name'] = 'Maximum 50 characters.';
        if (!isset($errors['last_name'])  && mb_strlen($last)  > 50) $errors['last_name']  = 'Maximum 50 characters.';
        if (!isset($errors['email']) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email address.';
        if (!isset($errors['phone']) && !preg_match(PHONE_USER, $phone)) $errors['phone'] = 'Phone number must start with 05 and be 10 digits (e.g. 05XXXXXXXX).';
        if (!isset($errors['edu_status']) && !in_array($edu, $EDU_STATUSES, true)) $errors['edu_status'] = 'Please select a valid option.';
        if (!isset($errors['gender']) && !in_array($gender, ['Male', 'Female'], true)) $errors['gender'] = 'Please select a gender.';

        if (!isset($errors['dob'])) {
            $dChk = DateTime::createFromFormat('!Y-m-d', $dob);
            if (!$dChk || $dChk->format('Y-m-d') !== $dob) {
                $errors['dob'] = 'Please enter a valid date of birth.';
            } else {
                $d   = new DateTime($dob);
                $age = (new DateTime('today'))->diff($d)->y;
                if ($d > new DateTime('today') || $age < 15) $errors['dob'] = 'You must be at least 15 years old.';
                elseif ($age > 100) $errors['dob'] = 'Please enter a valid date of birth.';
            }
        }

        if (!isset($errors['password']) && !is_strong_password($pass)) $errors['password'] = PW_MSG;
        if (!isset($errors['password2']) && $pass !== $pass2) $errors['password2'] = 'Passwords do not match.';

        $picState = check_file('picture', IMG_EXT, IMG_MIME);
        if ($picState === 'bad') $errors['picture'] = 'Invalid image format. Only PNG, JPG or JPEG are allowed.';

        $expRows  = parse_experience($p, $expBad, $errExp);
        if ($errExp) $errors['experience'] = $errExp;
        $qualRows = parse_qualifications($p, $qualBad, $errQual);
        if ($errQual) $errors['qualifications'] = $errQual;

        if ($emptyFound) {
            $banner = 'All fields are mandatory. Please check the highlighted fields.';
        } elseif ($errors) {
            $banner = 'Please fix the highlighted fields.';
        }

        // email already registered?
        if (!$errors) {
            $st = $pdo->prepare('SELECT 1 FROM member WHERE Email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) {
                $errors['email'] = 'Email already registered';
                $banner = 'Email already registered';
            }
        }

        // save
        if (!$errors) {
            $saved = [];
            try {
                $picPath = '';
                if ($picState === 'ok') { $picPath = store_file('picture', 'pics'); $saved[] = $picPath; }

                $pdo->beginTransaction();

                $pdo->prepare('INSERT INTO member (Email, Password, Role) VALUES (?, ?, "User")')
                    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
                $uid = (int)$pdo->lastInsertId();

                // Age is filled automatically by the DB trigger from DateOfBirth
                $pdo->prepare('INSERT INTO `user` (UserID, FirstName, LastName, Phone, DateOfBirth, Gender, ProfilePicture, EducationalStatus, FieldOfStudy)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$uid, $first, $last, $phone, $dob, $gender, $picPath ?: null, $edu, $field]);

                // optional: experience entries (Duration is a generated column in the DB)
                foreach ($expRows as [$t, $o, $sd, $ed]) {
                    $pdo->prepare('INSERT INTO experience (JobTitle, Organization, StartDate, EndDate) VALUES (?, ?, ?, ?)')
                        ->execute([$t, $o, $sd, $ed]);
                    $pdo->prepare('INSERT INTO userexperience (UserID, ExperienceID) VALUES (?, ?)')
                        ->execute([$uid, (int)$pdo->lastInsertId()]);
                }

                // optional: qualification entries
                foreach ($qualRows as [$qf, $dg, $yr, $du]) {
                    $pdo->prepare('INSERT INTO qualification (FieldOfStudy, DegreeLevel, YearObtained, Duration) VALUES (?, ?, ?, ?)')
                        ->execute([$qf, $dg, $yr, $du]);
                    $pdo->prepare('INSERT INTO userqualification (UserID, QualificationID) VALUES (?, ?)')
                        ->execute([$uid, (int)$pdo->lastInsertId()]);
                }


                // optional: skills
                $skills = [];
                foreach ((array)($p['skills'] ?? []) as $s) {
                    if ($s !== 'Other' && in_array($s, $allSkills, true)) $skills[] = $s;
                }
                if (in_array('Other', (array)($p['skills'] ?? []), true) && trim($p['skill_other'] ?? '') !== '') {
                    foreach (explode(',', $p['skill_other']) as $s) {
                        $s = mb_substr(trim($s), 0, 100);
                        if ($s !== '') $skills[] = $s;
                    }
                }
                foreach (array_unique($skills) as $s) {
                    $q = $pdo->prepare('SELECT SkillID FROM skill WHERE SkillName = ?');
                    $q->execute([$s]);
                    $sid = $q->fetchColumn();
                    if (!$sid) {
                        $pdo->prepare('INSERT INTO skill (SkillName) VALUES (?)')->execute([$s]);
                        $sid = $pdo->lastInsertId();
                    }
                    $pdo->prepare('INSERT IGNORE INTO userskill (UserID, SkillID) VALUES (?, ?)')->execute([$uid, $sid]);
                }

                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $uid;
                $_SESSION['role']    = 'User';
                header('Location: home.php');
                exit;
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                delete_files($saved);
                if ($ex->getCode() === '23000') {
                    $errors['email'] = 'Email already registered';
                    $banner = 'Email already registered';
                } else {
                    $banner = 'Something went wrong. Please try again.';
                }
            }
        }
    }

    /* ---------- COMPANY ---------- */
    else {
        $name  = trim($p['company_name'] ?? '');
        $email = trim($p['email'] ?? '');
        $phone = trim($p['phone'] ?? '');
        $cr    = trim($p['cr_number'] ?? '');
        $ind   = $p['industry'] ?? '';
        $loc   = $p['location'] ?? '';
        $desc  = trim($p['description'] ?? '');
        $web   = trim($p['website'] ?? '');
        $pass  = $p['password'] ?? '';
        $pass2 = $p['password2'] ?? '';

        foreach ([
            'company_name' => $name, 'email' => $email, 'phone' => $phone, 'cr_number' => $cr,
            'industry' => $ind, 'location' => $loc, 'description' => $desc, 'password' => $pass, 'password2' => $pass2,
        ] as $k => $val) {
            if ($val === '') { $errors[$k] = 'This field is required.'; $emptyFound = true; }
        }

        $docState  = check_file('doc', DOC_EXT, DOC_MIME);
        $logoState = check_file('logo', IMG_EXT, IMG_MIME);
        if ($docState === 'none')  { $errors['doc']  = 'This field is required.'; $emptyFound = true; }
        if ($logoState === 'none') { $errors['logo'] = 'This field is required.'; $emptyFound = true; }
        if ($docState === 'bad')   $errors['doc']  = 'Invalid file format. Only PDF, PNG, JPG or JPEG are allowed.';
        if ($logoState === 'bad')  $errors['logo'] = 'Invalid file format. Only PNG, JPG or JPEG are allowed.';

        if (!isset($errors['company_name']) && mb_strlen($name) > 150) $errors['company_name'] = 'Maximum 150 characters.';
        if (!isset($errors['email']) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email address.';
        if (!isset($errors['phone']) && !preg_match(PHONE_COMP, $phone)) $errors['phone'] = 'Phone must be 10 digits and start with 05 (mobile) or 01 (landline).';
        if (!isset($errors['description']) && mb_strlen($desc) < 15) $errors['description'] = 'Description must be at least 15 characters.';
        if (!isset($errors['cr_number']) && !preg_match(CR_REGEX, $cr)) $errors['cr_number'] = 'CR number must be exactly 10 digits.';
        if (!isset($errors['industry']) && !in_array($ind, $INDUSTRIES, true)) $errors['industry'] = 'Please select a valid option.';
        if (!isset($errors['location']) && !in_array($loc, $CITIES, true)) $errors['location'] = 'Please select a valid option.';
        if (!isset($errors['password']) && !is_strong_password($pass)) $errors['password'] = PW_MSG;
        if (!isset($errors['password2']) && $pass !== $pass2) $errors['password2'] = 'Passwords do not match.';
        if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) $errors['website'] = 'Please enter a valid URL (https://...).';

        if ($emptyFound) {
            $banner = 'All required fields must be filled.';
        } elseif ($errors) {
            $banner = 'Please fix the highlighted fields.';
        }

        // email or CR already registered?
        if (!$errors) {
            $st = $pdo->prepare('SELECT 1 FROM member WHERE Email = ?');
            $st->execute([$email]);
            $dupEmail = (bool)$st->fetchColumn();
            $st = $pdo->prepare('SELECT 1 FROM company WHERE CrNumber = ?');
            $st->execute([$cr]);
            if ($dupEmail || $st->fetchColumn()) {
                $errors['email'] = 'Company already registered';
                $banner = 'Company already registered';
            }
        }

        if (!$errors) {
            $saved = [];
            try {
                $docPath  = store_file('doc', 'docs');   $saved[] = $docPath;
                $logoPath = store_file('logo', 'logos'); $saved[] = $logoPath;

                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO member (Email, Password, Role) VALUES (?, ?, "Company")')
                    ->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
                $cid = (int)$pdo->lastInsertId();

                $pdo->prepare('INSERT INTO company (CompanyID, CompanyName, CrNumber, Phone, IndustrySector, VerificationDocument, Status, Description, Location, WebsiteURL, Logo)
                               VALUES (?, ?, ?, ?, ?, ?, "Pending", ?, ?, ?, ?)')
                    ->execute([$cid, $name, $cr, $phone, $ind, $docPath, $desc, $loc, $web ?: null, $logoPath]);
                $pdo->commit();

                $pending = true;
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                delete_files($saved);
                if ($ex->getCode() === '23000') {
                    $errors['email'] = 'Company already registered';
                    $banner = 'Company already registered';
                } else {
                    $banner = 'Something went wrong. Please try again.';
                }
            }
        }
    }
}

$U  = ($type === 'User')    ? $_POST : [];
$C  = ($type === 'Company') ? $_POST : [];
$eU = ($type === 'User')    ? $errors : [];
$eC = ($type === 'Company') ? $errors : [];
$postedSkills = (array)($U['skills'] ?? []);
$expView = []; $qualView = [];
for ($i = 0, $n = entry_count($U, 'exp_title'); $i < $n; $i++) {
    $expView[] = ['title' => arr_val($U, 'exp_title', $i), 'org' => arr_val($U, 'exp_org', $i),
                  'start' => arr_val($U, 'exp_start', $i), 'end' => arr_val($U, 'exp_end', $i)];
}
for ($i = 0, $n = entry_count($U, 'qual_field'); $i < $n; $i++) {
    $qualView[] = ['field' => arr_val($U, 'qual_field', $i), 'degree' => arr_val($U, 'qual_degree', $i),
                   'year' => arr_val($U, 'qual_year', $i), 'duration' => arr_val($U, 'qual_duration', $i)];
}
if (!$expView)  $expView[]  = [];
if (!$qualView) $qualView[] = [];
$dobMax = date('Y-m-d', strtotime('-15 years'));   // youngest allowed
$dobMin = date('Y-m-d', strtotime('-100 years'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up &mdash; Ufuq</title>
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<style>
  :root { --err: #e5646a; --err-text: #cf4a50; --err-bg: #fff6f6; }
  .req { color: var(--err); font-weight: 700; margin-left: 2px; }
  .form-error { color: var(--err-text); font-weight: 500; font-size: .85rem; margin-top: 6px; }
  .form-control.invalid { border: 1.5px solid var(--err) !important; }
  .form-banner {
    background: #fdf1f1; border: 1px solid #f1c0c2; color: var(--err-text);
    font-weight: 500; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px;
  }
  .pw-help { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
  .pw-help-btn {
    width: 22px; height: 22px; padding: 0; border-radius: 50%; cursor: pointer;
    border: 1.5px solid #9ca3af; background: #fff; color: #6b7280;
    font-weight: 700; font-size: .8rem; line-height: 1;
  }
  .pw-help-btn:hover, .pw-help-btn[aria-expanded="true"] { border-color: var(--color-primary); color: var(--color-primary); }
  .pw-help-label { font-size: .8rem; color: #6b7280; }
  .pw-rules { margin: 8px 0 0; padding-left: 22px; list-style: disc; font-size: .82rem; color: #6b7280; }
  .pw-rules.is-hidden { display: none; }
  .pw-rules li.ok { color: #1a7f37; }
  .gender-option.invalid { border: 1.5px solid var(--err) !important; }
  .gender-option.selected { border-color: var(--color-primary) !important; border-width: 2px !important; }
  .dob-row { display: grid; grid-template-columns: 1fr 1.5fr 1fr; gap: 10px; }
  /* experience / qualification entries */
  .entry-row {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) 36px; gap: 12px; align-items: end;
    border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin-bottom: 12px; background: #fafafa;
  }
  .entry-row .form-group { margin-bottom: 0; }
  @media (max-width: 760px) {
    .entry-row { grid-template-columns: 1fr 1fr; }
    .entry-remove { height: 40px; border: none; background: transparent; font-size: 1.5rem; line-height: 1; color: #9ca3af; cursor: pointer; }
  }
  .entry-remove { position: absolute; top: 6px; right: 10px; border: none; background: transparent; font-size: 1.4rem; line-height: 1; color: #9ca3af; cursor: pointer; }
  .entry-remove:hover { color: var(--err-text); }
  .entry-list > .entry-row:first-child .entry-remove { visibility: hidden; }
  .fp-year-select { margin-left: 6px; padding: 2px 6px; border: 1px solid #d1d5db; border-radius: 4px; font: inherit; font-weight: 600; background: #fff; }
  .flatpickr-current-month { display: flex; justify-content: center; align-items: center; gap: 4px; }
  .add-entry {
    display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 10px;
    border: 1.5px dashed #9ca3af; border-radius: 10px; background: transparent;
    color: #6b7280; font-weight: 600; cursor: pointer;
  }
  .add-entry:hover { border-color: var(--color-primary); color: var(--color-primary); }
  .add-entry .plus { font-size: 1.3rem; line-height: 1; }
  /* header: current-page button looks pressed */
  #site-header a.is-pressed {
    transform: translateY(1px);
    box-shadow: inset 0 3px 8px rgba(0, 0, 0, .28) !important;
    filter: brightness(.92);
  }
</style>
</head>
<body>

<div id="site-header"></div>

<main>
  <div class="auth-wrapper">
    <div class="auth-card" style="max-width: 980px;">
      <h2 class="text-center">Create your account</h2>
      <p class="text-center" style="margin-bottom: 24px;">Sign up as a user to access our services, or as a company to publish opportunities.</p>

<?php if (!$pending): ?>
      <div class="role-toggle">
        <button type="button" class="<?= $type === 'User' ? 'active' : '' ?>" id="roleUserBtn">User</button>
        <button type="button" class="<?= $type === 'Company' ? 'active' : '' ?>" id="roleCompanyBtn">Company</button>
      </div>
<?php endif; ?>

      <div id="formBanner" class="form-banner" style="display:<?= $banner ? 'block' : 'none' ?>;"><?= e($banner) ?></div>

<?php if (!$pending): ?>
      <!-- ================= USER FORM ================= -->
      <form id="userForm" method="post" enctype="multipart/form-data" novalidate class="<?= $type === 'User' ? '' : 'hidden' ?>">
        <input type="hidden" name="account_type" value="User">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="uFirst">First Name <?= req() ?></label>
            <input type="text" id="uFirst" name="first_name" class="form-control<?= inv($eU, 'first_name') ?>" data-required value="<?= v($U, 'first_name') ?>">
            <?= errBox($eU, 'uFirst', 'This field is required.', 'first_name') ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="uLast">Last Name <?= req() ?></label>
            <input type="text" id="uLast" name="last_name" class="form-control<?= inv($eU, 'last_name') ?>" data-required value="<?= v($U, 'last_name') ?>">
            <?= errBox($eU, 'uLast', 'This field is required.', 'last_name') ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="uEmail"><?= icon('email') ?> Email <?= req() ?></label>
          <input type="email" id="uEmail" name="email" class="form-control<?= inv($eU, 'email') ?>" data-required data-check="email" value="<?= v($U, 'email') ?>">
          <?= errBox($eU, 'uEmail', 'This field is required.', 'email') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="uPhone"><?= icon('phone') ?> Phone <?= req() ?></label>
          <input type="tel" id="uPhone" name="phone" class="form-control<?= inv($eU, 'phone') ?>" data-required data-check="phone-user" inputmode="numeric" maxlength="10" placeholder="05XXXXXXXX" value="<?= v($U, 'phone') ?>">
          <?= errBox($eU, 'uPhone', 'This field is required.', 'phone') ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label icon-label" for="uEduStatus"><?= icon('training') ?> Educational Status <?= req() ?></label>
            <select id="uEduStatus" name="edu_status" class="form-control<?= inv($eU, 'edu_status') ?>" data-required>
              <option value="">Select&hellip;</option>
              <?php foreach ($EDU_STATUSES as $s): ?>
                <option value="<?= e($s) ?>"<?= sel($U, 'edu_status', $s) ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
            <?= errBox($eU, 'uEduStatus', 'This field is required.', 'edu_status') ?>
          </div>
          <div class="form-group">
            <label class="form-label icon-label" for="uField"><?= icon('training') ?> Field of Study <?= req() ?></label>
            <select id="uField" name="field_of_study" class="form-control<?= inv($eU, 'field_of_study') ?>">
              <option value="">Select&hellip;</option>
              <?php foreach ($FIELDS as $f): ?>
                <option value="<?= e($f) ?>"<?= sel($U, 'field_of_study', $f) ?>><?= e($f) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" id="uFieldOther" name="field_other" class="form-control hidden" placeholder="Tell us your field of study" style="margin-top:10px;" value="<?= v($U, 'field_other') ?>">
            <?= errBox($eU, 'uField', 'This field is required.', 'field_of_study') ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="uDob">Date of Birth <?= req() ?></label>
          <input type="text" id="uDob" name="dob" class="form-control<?= inv($eU, 'dob') ?>" data-required data-check="dob"
                 placeholder="Select your date of birth" autocomplete="off"
                 data-min="<?= $dobMin ?>" data-max="<?= $dobMax ?>" value="<?= v($U, 'dob') ?>">
          <?= errBox($eU, 'uDob', 'This field is required.', 'dob') ?>
        </div>

        <div class="form-group">
          <label class="form-label">Gender <?= req() ?></label>
          <div class="grid grid-2" style="gap:12px;">
            <?php foreach (['Male' => 'userMale', 'Female' => 'userFemale'] as $g => $ic): ?>
            <button type="button" class="card card-hover gender-option<?= ($U['gender'] ?? '') === $g ? ' selected' : '' ?><?= isset($eU['gender']) ? ' invalid' : '' ?>" data-gender="<?= $g ?>" style="text-align:center; padding:18px 12px; cursor:pointer;">
              <div class="flex-center" style="margin-bottom:6px;"><?= icon($ic, 'width:26px;height:26px;', 'icon-primary') ?></div>
              <div style="font-weight:600;"><?= $g ?></div>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="uGender" name="gender" data-required value="<?= v($U, 'gender') ?>">
          <?= errBox($eU, 'uGender', 'Please select a gender.', 'gender') ?>
        </div>

        <div class="form-group">
            <label class="form-label icon-label" for="uPassword"><?= icon('lock') ?> Password <?= req() ?></label>
            <input type="password" id="uPassword" name="password" class="form-control<?= inv($eU, 'password') ?>" data-required data-check="password" autocomplete="new-password">
            <?= errBox($eU, 'uPassword', 'This field is required.', 'password') ?>
            <?= pwHelp('uPassword') ?>
          </div>
          <div class="form-group">
            <label class="form-label icon-label" for="uPassword2"><?= icon('lock') ?> Confirm Password <?= req() ?></label>
            <input type="password" id="uPassword2" name="password2" class="form-control<?= inv($eU, 'password2') ?>" data-required data-check="confirm" autocomplete="new-password">
            <?= errBox($eU, 'uPassword2', 'This field is required.', 'password2') ?>
          </div>

        <div class="divider"></div>
        <p class="form-hint" style="margin-bottom:16px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:var(--color-text-faint);">
          Optional &mdash; you can add these now or later from your profile
        </p>

        <div class="form-group">
          <label class="form-label icon-label" for="uPicture"><?= icon('upload') ?> Profile Picture</label>
          <input type="file" id="uPicture" name="picture" class="form-control<?= inv($eU, 'picture') ?>" accept=".png,.jpg,.jpeg" data-check="image">
          <?= errBox($eU, 'uPicture', 'Invalid image format. Only PNG, JPG or JPEG are allowed.', 'picture') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label"><?= icon('form') ?> Experience</label>
          <div class="entry-list" id="expList">
            <?php foreach ($expView as $i => $row) echo exp_row($row, $expBad[$i] ?? []); ?>
          </div>
          <?= errBox($eU, 'expSection', EXP_ERR, 'experience') ?>
          <button type="button" class="add-entry" data-list="expList" data-template="expTemplate"><span class="plus">+</span> Add another experience</button>
          <template id="expTemplate"><?= exp_row() ?></template>
        </div>
               <br>
        <div class="form-group">
          <label class="form-label icon-label"><?= icon('goal') ?> Qualifications</label>
          <div class="entry-list" id="qualList">
            <?php foreach ($qualView as $i => $row) echo qual_row($row, $qualBad[$i] ?? []); ?>
          </div>
          <?= errBox($eU, 'qualSection', QUAL_ERR, 'qualifications') ?>
          <button type="button" class="add-entry" data-list="qualList" data-template="qualTemplate"><span class="plus">+</span> Add another qualification</button>
          <template id="qualTemplate"><?= qual_row() ?></template>
        </div>
              <br>
        <div class="form-group">
          <label class="form-label icon-label"><?= icon('goal') ?> Skills</label>
          <div id="uSkillsBox">
            <?php foreach (array_merge($allSkills, ['Other']) as $s): ?>
              <label class="filter-option">
                <input type="checkbox" name="skills[]" value="<?= e($s) ?>" class="skill-checkbox"<?= in_array($s, $postedSkills, true) ? ' checked' : '' ?>> <?= e($s) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <input type="text" id="uSkillOther" name="skill_other" class="form-control hidden" placeholder="Type your own, separated by commas" style="margin-top:10px;" value="<?= v($U, 'skill_other') ?>">
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:12px;">Register</button>
      </form>

      <!-- ================= COMPANY FORM ================= -->
      <form id="companyForm" method="post" enctype="multipart/form-data" novalidate class="<?= $type === 'Company' ? '' : 'hidden' ?>">
        <input type="hidden" name="account_type" value="Company">

        <div class="form-group">
          <label class="form-label icon-label" for="cName"><?= icon('company') ?> Company Name <?= req() ?></label>
          <input type="text" id="cName" name="company_name" class="form-control<?= inv($eC, 'company_name') ?>" data-required value="<?= v($C, 'company_name') ?>">
          <?= errBox($eC, 'cName', 'This field is required.', 'company_name') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cEmail"><?= icon('email') ?> Official Email <?= req() ?></label>
          <input type="email" id="cEmail" name="email" class="form-control<?= inv($eC, 'email') ?>" data-required data-check="email" value="<?= v($C, 'email') ?>">
          <?= errBox($eC, 'cEmail', 'This field is required.', 'email') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cPhone"><?= icon('phone') ?> Contact Phone <?= req() ?></label>
          <input type="tel" id="cPhone" name="phone" class="form-control<?= inv($eC, 'phone') ?>" data-required data-check="phone-company" inputmode="numeric" maxlength="10" placeholder="05XXXXXXXX or 01XXXXXXXX" value="<?= v($C, 'phone') ?>">
          <?= errBox($eC, 'cPhone', 'This field is required.', 'phone') ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="cCr">CR Number <?= req() ?></label>
          <input type="text" id="cCr" name="cr_number" class="form-control<?= inv($eC, 'cr_number') ?>" data-required data-check="cr" inputmode="numeric" maxlength="10" placeholder="10 digits, e.g. 1010123456" value="<?= v($C, 'cr_number') ?>">
          <?= errBox($eC, 'cCr', 'This field is required.', 'cr_number') ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="cIndustry">Industry Sector <?= req() ?></label>
            <select id="cIndustry" name="industry" class="form-control<?= inv($eC, 'industry') ?>" data-required>
              <option value="">Select&hellip;</option>
              <?php foreach ($INDUSTRIES as $s): ?>
                <option value="<?= e($s) ?>"<?= sel($C, 'industry', $s) ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
            <?= errBox($eC, 'cIndustry', 'This field is required.', 'industry') ?>
          </div>
          <div class="form-group">
            <label class="form-label icon-label" for="cLocation"><?= icon('location') ?> Location / City <?= req() ?></label>
            <select id="cLocation" name="location" class="form-control<?= inv($eC, 'location') ?>" data-required>
              <option value="">Select&hellip;</option>
              <?php foreach ($CITIES as $c): ?>
                <option value="<?= e($c) ?>"<?= sel($C, 'location', $c) ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <?= errBox($eC, 'cLocation', 'This field is required.', 'location') ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="cDescription">Company Description <?= req() ?></label>
          <textarea id="cDescription" name="description" class="form-control<?= inv($eC, 'description') ?>" rows="3" data-required data-check="desc" placeholder="At least 15 characters"><?= v($C, 'description') ?></textarea>
          <?= errBox($eC, 'cDescription', 'This field is required.', 'description') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cDoc"><?= icon('document') ?> Verification Document (CR document) <?= req() ?></label>
          <input type="file" id="cDoc" name="doc" class="form-control<?= inv($eC, 'doc') ?>" accept=".pdf,.png,.jpg,.jpeg" data-required data-check="doc">
          <?= errBox($eC, 'cDoc', 'This field is required.', 'doc') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cLogo"><?= icon('upload') ?> Company Logo <?= req() ?></label>
          <input type="file" id="cLogo" name="logo" class="form-control<?= inv($eC, 'logo') ?>" accept=".png,.jpg,.jpeg" data-required data-check="image">
          <?= errBox($eC, 'cLogo', 'This field is required.', 'logo') ?>
        </div>


        <div class="form-group">
          <label class="form-label icon-label" for="cPassword"><?= icon('lock') ?> Password <?= req() ?></label>
          <input type="password" id="cPassword" name="password" class="form-control<?= inv($eC, 'password') ?>" data-required data-check="password" autocomplete="new-password">
          <?= errBox($eC, 'cPassword', 'This field is required.', 'password') ?>
          <?= pwHelp('cPassword') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cPassword2"><?= icon('lock') ?> Confirm Password <?= req() ?></label>
          <input type="password" id="cPassword2" name="password2" class="form-control<?= inv($eC, 'password2') ?>" data-required data-check="confirm" autocomplete="new-password">
          <?= errBox($eC, 'cPassword2', 'This field is required.', 'password2') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="cWebsite"><?= icon('externalLink') ?> Website URL</label>
          <input type="url" id="cWebsite" name="website" class="form-control<?= inv($eC, 'website') ?>" placeholder="https://" value="<?= v($C, 'website') ?>">
          <?= errBox($eC, 'cWebsite', 'Please enter a valid URL (https://...).', 'website') ?>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="margin-top:12px;">Submit Registration</button>
      </form>

      <p class="text-center" style="margin-top:20px;">
        Already have an account? <a href="login.php">Log In</a>
      </p>

<?php else: ?>
      <!-- ================= COMPANY PENDING CONFIRMATION ================= -->
      <div id="companyPending" class="text-center">
        <div class="flex-center" style="margin-bottom:16px;"><?= icon('done', 'width:40px;height:40px;', 'icon-success') ?></div>
        <h3>Registration Pending</h3>
        <p>Your company account is <strong>pending admin review</strong>. You'll be able to log in once it's approved.</p>
        <a href="login.php" class="btn btn-outline" style="margin-top:8px;">Back to Log In</a>
      </div>
<?php endif; ?>
    </div>
  </div>
</main>

<div id="site-footer"></div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="../js/store.js"></script>
<script src="../js/constants.js"></script>
<script src="../js/components.js"></script>
<script>
  renderHeader({ active: "", base: "../" });
  renderFooter({ base: "../" });

  /* Header: no underline on "Opportunities" here; the Sign Up button looks pressed */
  (function () {
    const header = document.getElementById("site-header");
    if (!header) return;
    header.querySelectorAll(".active, [aria-current]").forEach(el => {
      el.classList.remove("active");
      el.removeAttribute("aria-current");
    });
    header.querySelectorAll("a").forEach(a => {
      if ((a.getAttribute("href") || "").toLowerCase().includes("signup")) {
        a.classList.add("is-pressed");
        a.setAttribute("aria-current", "page");
      }
    });
  })();

  const PHONE_USER_RE = /^05\d{8}$/;
  const PHONE_COMP_RE = /^0[15]\d{8}$/;
  const CR_RE = /^[1-9]\d{9}$/;
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const PW_MSG   = <?= json_encode(PW_MSG) ?>;
  const banner   = document.getElementById("formBanner");

  function pwChecks(p) {
    return {
      len: p.length >= 8, upper: /[A-Z]/.test(p), lower: /[a-z]/.test(p),
      num: /\d/.test(p), special: /[^A-Za-z0-9]/.test(p)
    };
  }
  const isStrong = p => Object.values(pwChecks(p)).every(Boolean);

  function showBanner(m) { banner.textContent = m; banner.style.display = "block"; }
  function hideBanner()  { banner.style.display = "none"; }

  /* ---------- Role toggle ---------- */
  const userBtn = document.getElementById("roleUserBtn");
  const compBtn = document.getElementById("roleCompanyBtn");
  const userForm = document.getElementById("userForm");
  const compForm = document.getElementById("companyForm");
  if (userBtn && compBtn) {
    userBtn.onclick = () => {
      userBtn.classList.add("active"); compBtn.classList.remove("active");
      userForm.classList.remove("hidden"); compForm.classList.add("hidden"); hideBanner();
    };
    compBtn.onclick = () => {
      compBtn.classList.add("active"); userBtn.classList.remove("active");
      compForm.classList.remove("hidden"); userForm.classList.add("hidden"); hideBanner();
    };
  }

  /* ---------- Field of study "Other" ---------- */
  const uField = document.getElementById("uField");
  const uFieldOther = document.getElementById("uFieldOther");
  if (uField) {
    const toggleOther = () => uFieldOther.classList.toggle("hidden", uField.value !== "Other");
    uField.addEventListener("change", toggleOther);
    toggleOther();
  }

  /* ---------- Skills "Other" ---------- */
  const skillOther = document.getElementById("uSkillOther");
  document.querySelectorAll(".skill-checkbox").forEach(cb => {
    if (cb.value === "Other") {
      const t = () => skillOther.classList.toggle("hidden", !cb.checked);
      cb.addEventListener("change", t); t();
    }
  });

  /* ---------- Gender picker ---------- */
  const gInput = document.getElementById("uGender");
  document.querySelectorAll(".gender-option").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".gender-option").forEach(b => b.classList.remove("selected", "invalid"));
      btn.classList.add("selected");
      gInput.value = btn.dataset.gender;
      document.getElementById("uGenderError").style.display = "none";
    });
  });

  /* ---------- Live password checklist ---------- */
  document.querySelectorAll(".pw-rules").forEach(list => {
    const input = document.getElementById(list.dataset.for);
    const update = () => {
      const r = pwChecks(input.value);
      list.querySelectorAll("li").forEach(li => li.classList.toggle("ok", r[li.dataset.rule]));
    };
    input.addEventListener("input", update); update();
  });

  /* ---------- "!" button shows / hides password requirements ---------- */
  document.querySelectorAll(".pw-help-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const list = document.getElementById(btn.dataset.target);
      const open = !list.classList.toggle("is-hidden");
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    });
  });

  /* ---------- Digits only for phone / CR ---------- */
  document.querySelectorAll('[data-check="phone-user"],[data-check="phone-company"],[data-check="cr"]').forEach(el => {
    el.addEventListener("input", () => { el.value = el.value.replace(/\D/g, "").slice(0, 10); });
  });

  /* ---------- Date of birth calendar (min age 15) ---------- */
  (function () {
    const dob = document.getElementById("uDob");
    if (!dob) return;
    const minD = dob.dataset.min, maxD = dob.dataset.max;
    if (typeof flatpickr === "undefined") {          // fallback: browser's native calendar
      dob.type = "date"; dob.min = minD; dob.max = maxD; return;
    }
    const minY = +minD.slice(0, 4), maxY = +maxD.slice(0, 4);
    const syncYear = (d, str, fp) => { if (fp._yearSel) fp._yearSel.value = fp.currentYear; };
    flatpickr(dob, {
      dateFormat: "Y-m-d", minDate: minD, maxDate: maxD, disableMobile: true,
      monthSelectorType: "dropdown",
      onReady: [function (d, str, fp) {
        const sel = document.createElement("select");
        sel.className = "fp-year-select";
        for (let y = maxY; y >= minY; y--) sel.add(new Option(y, y));
        sel.value = fp.currentYear;
        sel.addEventListener("change", () => fp.changeYear(+sel.value));
        const wrap = fp.currentYearElement.parentNode;
        wrap.style.display = "none";
        wrap.parentNode.appendChild(sel);
        fp._yearSel = sel;
      }],
      onOpen: [syncYear], onMonthChange: [syncYear], onYearChange: [syncYear]
    });
  })();

  /* ---------- Experience / qualification entries: add (+), remove ---------- */
  document.querySelectorAll(".add-entry").forEach(btn => {
    btn.addEventListener("click", () => {
      const list = document.getElementById(btn.dataset.list);
      const tpl = document.getElementById(btn.dataset.template);
      list.appendChild(tpl.content.cloneNode(true));
    });
  });
  document.querySelectorAll(".entry-list").forEach(list => {
    list.addEventListener("click", ev => {
      const rm = ev.target.closest(".entry-remove");
      if (rm) rm.closest(".entry-row").remove();
    });
  });


  /* ---------- Validation ---------- */
  function mark(el, bad, msg) {
    el.classList.toggle("invalid", bad);
    if (el.id === "uGender") document.querySelectorAll(".gender-option").forEach(b => b.classList.toggle("invalid", bad));
    const err = document.getElementById(el.id + "Error");
    if (!err) return;
    if (bad) { err.textContent = msg || err.dataset.msg; err.style.display = "block"; }
    else err.style.display = "none";
  }
  const extOf = f => f.name.split(".").pop().toLowerCase();

  function validateForm(form) {
    let anyEmpty = false, anyBad = false;

    // experience & qualification entries: optional, but a started entry must be complete
    [["expList", "expSectionError"], ["qualList", "qualSectionError"]].forEach(([listId, errId]) => {
      const list = form.querySelector("#" + listId);
      if (!list) return;
      const t = new Date();
      const today = t.getFullYear() + "-" + String(t.getMonth() + 1).padStart(2, "0") + "-" + String(t.getDate()).padStart(2, "0");
      let sectionBad = false;
      list.querySelectorAll(".entry-row").forEach(row => {
        const inputs = [...row.querySelectorAll(".entry-input")];
        inputs.forEach(i => i.classList.remove("invalid"));
        if (!inputs.some(i => i.value.trim() !== "")) return;
        inputs.forEach(i => { if (i.value.trim() === "") { i.classList.add("invalid"); sectionBad = true; } });
        if (listId === "expList") {
          const s = row.querySelector('[name="exp_start[]"]'), e = row.querySelector('[name="exp_end[]"]');
          [s, e].forEach(x => { if (x.value && x.value > today) { x.classList.add("invalid"); sectionBad = true; } });
          if (s.value && e.value && e.value < s.value) { e.classList.add("invalid"); sectionBad = true; }
        }
      });
      const err = document.getElementById(errId);
      err.textContent = err.dataset.msg;
      err.style.display = sectionBad ? "block" : "none";
      if (sectionBad) anyBad = true;
    });

    // field of study (select + optional "Other" text) is handled separately
    const fs = form.querySelector("#uField");
    if (fs) {
      const val = fs.value === "Other" ? uFieldOther.value.trim() : fs.value;
      const bad = !val;
      mark(fs, bad); if (bad) { anyEmpty = true; anyBad = true; }
    }

    form.querySelectorAll("[data-required],[data-check]").forEach(el => {
      const required = el.hasAttribute("data-required");
      const isFile = el.type === "file";
      const empty = isFile ? el.files.length === 0 : el.value.trim() === "";
      let msg = "", bad = false;

      if (empty) {
        if (required) { bad = true; anyEmpty = true; }
      } else {
        switch (el.dataset.check) {
          case "email":    if (!EMAIL_RE.test(el.value.trim())) { bad = true; msg = "Please enter a valid email address."; } break;
          case "phone-user":    if (!PHONE_USER_RE.test(el.value.trim())) { bad = true; msg = "Phone number must start with 05 and be 10 digits (e.g. 05XXXXXXXX)."; } break;
          case "phone-company": if (!PHONE_COMP_RE.test(el.value.trim())) { bad = true; msg = "Phone must be 10 digits and start with 05 (mobile) or 01 (landline)."; } break;
          case "dob": {
            const d = new Date(el.value + "T00:00:00");
            if (isNaN(d)) { bad = true; msg = "Please enter a valid date of birth."; break; }
            const now = new Date();
            let age = now.getFullYear() - d.getFullYear();
            if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) age--;
            if (age < 15) { bad = true; msg = "You must be at least 15 years old."; }
            else if (age > 100) { bad = true; msg = "Please enter a valid date of birth."; }
            break;
          }
          case "desc":          if (el.value.trim().length < 15) { bad = true; msg = "Description must be at least 15 characters."; } break;
          case "cr":            if (!CR_RE.test(el.value.trim())) { bad = true; msg = "CR number must be exactly 10 digits."; } break;
          case "password": if (!isStrong(el.value)) { bad = true; msg = PW_MSG; } break;
          case "confirm":  if (el.value !== form.querySelector('[data-check="password"]').value) { bad = true; msg = "Passwords do not match."; } break;
          case "image":    if (!["png", "jpg", "jpeg"].includes(extOf(el.files[0]))) { bad = true; msg = "Invalid image format. Only PNG, JPG or JPEG are allowed."; } break;
          case "doc":      if (!["pdf", "png", "jpg", "jpeg"].includes(extOf(el.files[0]))) { bad = true; msg = "Invalid file format. Only PDF, PNG, JPG or JPEG are allowed."; } break;
        }
      }
      mark(el, bad, msg);
      if (bad) anyBad = true;
    });

    // optional website URL
    const web = form.querySelector("#cWebsite");
    if (web && web.value.trim()) {
      let bad = false;
      try { new URL(web.value.trim()); } catch (_) { bad = true; }
      mark(web, bad); if (bad) anyBad = true;
    }

    if (anyEmpty) showBanner(form.id === "userForm"
      ? "All fields are mandatory. Please check the highlighted fields."
      : "All required fields must be filled.");
    else if (anyBad) showBanner("Please fix the highlighted fields.");
    else hideBanner();

    return !anyBad;
  }

  [userForm, compForm].forEach(f => {
    if (f) f.addEventListener("submit", e => { if (!validateForm(f)) e.preventDefault(); });
  });
</script>

</body>
</html>