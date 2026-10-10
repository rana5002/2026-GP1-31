<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

require_login('Company');
$companyId = (int)($_SESSION['user_id'] ?? 0);

$types = defined('OPPORTUNITY_TYPES') ? OPPORTUNITY_TYPES : ['Training', 'Course'];
$cities = defined('CITIES') ? CITIES : ['Riyadh','Jeddah','Mecca','Medina','Dammam','Khobar','Dhahran','Taif','Abha','Tabuk','Online','Other'];
$errors = [];
$success = '';
$editing = false;
$opportunity = [];
$skills = [];
$qualifications = '';
$experience = '';

function opp_form_redirect(): never {
    header('Location: dashboard-company.php?tab=opportunities');
    exit;
}
function opp_table_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $columns[strtolower($row['Field'])] = $row['Field'];
    return $columns;
}
function opp_pick_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) if (isset($columns[strtolower($candidate)])) return $columns[strtolower($candidate)];
    return null;
}
function opp_store_child_values(PDO $pdo, string $table, int $opportunityId, array $values, array $valueCandidates): void {
    $columns = opp_table_columns($pdo, $table);
    $fk = opp_pick_column($columns, ['OpportunityID', 'OpportunityId', 'opportunity_id']);
    $valueCol = opp_pick_column($columns, $valueCandidates);
    if (!$fk || !$valueCol) {
        throw new RuntimeException('The database structure for ' . $table . ' does not match the expected fields. Please check its columns.');
    }
    $stmt = $pdo->prepare('INSERT INTO `' . $table . '` (`' . $fk . '`, `' . $valueCol . '`) VALUES (?, ?)');
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') $stmt->execute([$opportunityId, $value]);
    }
}

$companyStmt = $pdo->prepare('SELECT c.CompanyID, c.CompanyName, c.IndustrySector, c.Logo FROM company c WHERE c.CompanyID = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch();
if (!$company) { http_response_code(404); exit('Company profile not found.'); }

$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
    $stmt->execute([$editId, $companyId]);
    $opportunity = $stmt->fetch() ?: [];
    if (!$opportunity) { header('Location: dashboard-company.php?tab=opportunities'); exit; }
    $editing = true;

    try {
        $stmt = $pdo->prepare('SELECT * FROM opportunityskill WHERE OpportunityID = ?');
        $stmt->execute([$editId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['SkillName','Skill','Name','SkillTitle'] as $key) if (isset($row[$key]) && trim((string)$row[$key]) !== '') { $skills[] = (string)$row[$key]; break; }
        }
        $stmt = $pdo->prepare('SELECT * FROM opportunityqualification WHERE OpportunityID = ?');
        $stmt->execute([$editId]);
        $qualificationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $qualifications = implode("\n", array_values(array_filter(array_map(static function($row) { foreach (['Qualification','QualificationName','Name','Description'] as $key) if (isset($row[$key])) return trim((string)$row[$key]); return ''; }, $qualificationRows))));
        $stmt = $pdo->prepare('SELECT * FROM opportunityexperience WHERE OpportunityID = ?');
        $stmt->execute([$editId]);
        $experienceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $experience = implode("\n", array_values(array_filter(array_map(static function($row) { foreach (['Experience','ExperienceName','Name','Description'] as $key) if (isset($row[$key])) return trim((string)$row[$key]); return ''; }, $experienceRows))));
    } catch (Throwable $ignored) {
        // Keep the form available even if optional relation tables use different column names.
    }
}

$values = [
    'Title' => $opportunity['Title'] ?? '',
    'Description' => $opportunity['Description'] ?? '',
    'Type' => $opportunity['Type'] ?? '',
    'ApplicationMethod' => $opportunity['ApplicationMethod'] ?? 'Add Link',
    'ExternalURL' => $opportunity['ExternalURL'] ?? '',
    'Location' => $opportunity['Location'] ?? '',
    'StartDate' => $opportunity['StartDate'] ?? '',
    'EndDate' => $opportunity['EndDate'] ?? '',
    'ApplicationDeadLine' => $opportunity['ApplicationDeadLine'] ?? '',
    'Qualifications' => $qualifications,
    'Experience' => $experience,
];
$imagePath = '';
foreach (['OpportunityImage','Image','ImageURL','Photo','OpportunityPhoto'] as $imageColumn) {
    if (isset($opportunity[$imageColumn])) { $imagePath = (string)$opportunity[$imageColumn]; break; }
}

$uploadedImage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check();
        $postEditId = (int)($_POST['opportunity_id'] ?? 0);
        $isEditPost = $postEditId > 0;
        if ($isEditPost) {
            $check = $pdo->prepare('SELECT * FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
            $check->execute([$postEditId, $companyId]);
            $existing = $check->fetch();
            if (!$existing) throw new RuntimeException('You are not allowed to edit this opportunity.');
            $editing = true;
            $opportunity = $existing;
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $type = trim((string)($_POST['type'] ?? ''));
        $method = $isEditPost ? (string)($opportunity['ApplicationMethod'] ?? 'Add Link') : trim((string)($_POST['application_method'] ?? 'Add Link'));
        $externalUrl = trim((string)($_POST['external_url'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $deadline = trim((string)($_POST['application_deadline'] ?? ''));
        $skills = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['skills'] ?? [])), static fn($v) => $v !== '')));
        $qualifications = trim((string)($_POST['qualifications'] ?? ''));
        $experience = trim((string)($_POST['experience'] ?? ''));

        $values = ['Title'=>$title,'Description'=>$description,'Type'=>$type,'ApplicationMethod'=>$method,'ExternalURL'=>$externalUrl,'Location'=>$location,'StartDate'=>$startDate,'EndDate'=>$endDate,'ApplicationDeadLine'=>$deadline,'Qualifications'=>$qualifications,'Experience'=>$experience];
        if ($title === '') $errors['title'] = 'This field is required.';
        if ($description === '') $errors['description'] = 'This field is required.';
        if (!in_array($type, $types, true)) $errors['type'] = 'Please select an opportunity type.';
        if (!in_array($location, $cities, true)) $errors['location'] = 'Please select a location.';
        if (!in_array($method, ['Add Link', 'Add via the System'], true)) $errors['application_method'] = 'Please select an application method.';
        if ($method === 'Add Link' && (!filter_var($externalUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME)), ['http','https'], true))) $errors['external_url'] = 'Please enter a valid URL beginning with http:// or https://.';
        if ($startDate === '' || !$dateStart = DateTime::createFromFormat('!Y-m-d', $startDate)) $errors['start_date'] = 'Please enter a valid start date.';
        if ($endDate === '' || !$dateEnd = DateTime::createFromFormat('!Y-m-d', $endDate)) $errors['end_date'] = 'Please enter a valid end date.';
        if (empty($errors['start_date']) && empty($errors['end_date']) && $dateEnd < $dateStart) $errors['end_date'] = 'End date must be on or after the start date.';
        if ($method === 'Add via the System' && $deadline === '') $errors['application_deadline'] = 'Application deadline is required when using the system.';
        if ($deadline !== '' && !DateTime::createFromFormat('!Y-m-d', $deadline)) $errors['application_deadline'] = 'Please enter a valid deadline.';
        if ($deadline !== '' && empty($errors['start_date']) && DateTime::createFromFormat('!Y-m-d', $deadline) > $dateStart) $errors['application_deadline'] = 'Application deadline should be on or before the start date.';
        if ($qualifications === '') $errors['qualifications'] = 'This field is required.';
        if ($experience === '') $errors['experience'] = 'This field is required.';

        $uploadedImage = null;
        if (isset($_FILES['opportunity_image']) && $_FILES['opportunity_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['opportunity_image']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed. Please try again.');
            if ($_FILES['opportunity_image']['size'] > 5 * 1024 * 1024) throw new RuntimeException('Opportunity photo must be 5 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['opportunity_image']['tmp_name']);
            $mimeMap = ['image/png'=>'png','image/jpeg'=>'jpg'];
            if (!isset($mimeMap[$mime])) throw new RuntimeException('Please upload a PNG, JPG, or JPEG image.');
            $uploadDir = __DIR__ . '/../uploads/opportunities';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Could not create the opportunity image folder.');
            $fileName = 'opportunity_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $mimeMap[$mime];
            if (!move_uploaded_file($_FILES['opportunity_image']['tmp_name'], $uploadDir . '/' . $fileName)) throw new RuntimeException('Could not save the opportunity image.');
            $uploadedImage = 'uploads/opportunities/' . $fileName;
        }

        if ($errors) {
            // Re-display entered values and errors below.
        } else {
            $durationDays = (int)$dateStart->diff($dateEnd)->format('%a');
            if ($durationDays < 14) $duration = $durationDays . ' day' . ($durationDays === 1 ? '' : 's');
            elseif ($durationDays < 63) { $weeks = (int)round($durationDays / 7); $duration = $weeks . ' week' . ($weeks === 1 ? '' : 's'); }
            else { $months = (int)round($durationDays / 30); $duration = $months . ' month' . ($months === 1 ? '' : 's'); }

            $columns = opp_table_columns($pdo, 'opportunity');
            $fieldMap = [
                'Title' => ['Title'], 'Description' => ['Description'], 'Type' => ['Type'],
                'ApplicationMethod' => ['ApplicationMethod'], 'OpportunityProvider' => ['OpportunityProvider'],
                'IndustrySector' => ['IndustrySector'], 'ExternalURL' => ['ExternalURL'],
                'StartDate' => ['StartDate'], 'EndDate' => ['EndDate'],
                'ApplicationDeadLine' => ['ApplicationDeadLine','ApplicationDeadline'], 'Location' => ['Location'],
                'Status' => ['Status'], 'CreatedByCompanyID' => ['CreatedByCompanyID']
            ];
            $data = [];
            foreach ($fieldMap as $key => $candidates) {
                $column = opp_pick_column($columns, $candidates);
                if ($column === null) continue;
                $data[$column] = match ($key) {
                    'Title' => $title, 'Description' => $description, 'Type' => $type,
                    'ApplicationMethod' => $method, 'OpportunityProvider' => $company['CompanyName'],
                    'IndustrySector' => $company['IndustrySector'] ?? null, 'ExternalURL' => $method === 'Add Link' ? $externalUrl : null,
                    'StartDate' => $startDate, 'EndDate' => $endDate,
                    'ApplicationDeadLine' => $deadline !== '' ? $deadline : null, 'Location' => $location,
                    'Status' => 'Open', 'CreatedByCompanyID' => $companyId,
                };
            }
            $imageColumn = opp_pick_column($columns, ['OpportunityImage','Image','ImageURL','Photo','OpportunityPhoto']);
            if ($uploadedImage !== null && $imageColumn !== null) $data[$imageColumn] = $uploadedImage;
            if (!$data) throw new RuntimeException('Could not map opportunity fields to the database table.');

            $pdo->beginTransaction();
            if ($isEditPost) {
                unset($data[$columns['createdbycompanyid'] ?? 'CreatedByCompanyID']);
                $sets = array_map(static fn($column) => '`' . $column . '` = ?', array_keys($data));
                $params = array_values($data);
                $params[] = $postEditId; $params[] = $companyId;
                $stmt = $pdo->prepare('UPDATE opportunity SET ' . implode(', ', $sets) . ' WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
                $stmt->execute($params);
                $opportunityId = $postEditId;
                foreach (['opportunityskill','opportunityqualification','opportunityexperience'] as $table) {
                    $tableExists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
                    if ($tableExists) { $cols = opp_table_columns($pdo, $table); $fk = opp_pick_column($cols, ['OpportunityID','OpportunityId','opportunity_id']); if ($fk) { $del = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $fk . '` = ?'); $del->execute([$opportunityId]); } }
                }
            } else {
                $colNames = array_keys($data);
                $stmt = $pdo->prepare('INSERT INTO opportunity (' . implode(', ', array_map(static fn($column) => '`' . $column . '`', $colNames)) . ') VALUES (' . implode(', ', array_fill(0, count($colNames), '?')) . ')');
                $stmt->execute(array_values($data));
                $opportunityId = (int)$pdo->lastInsertId();
            }

            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('opportunityskill'))->fetchColumn();
            if ($exists) opp_store_child_values($pdo, 'opportunityskill', $opportunityId, $skills, ['SkillName','Skill','Name','SkillTitle','SkillText']);
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('opportunityqualification'))->fetchColumn();
            if ($exists) opp_store_child_values($pdo, 'opportunityqualification', $opportunityId, preg_split('/\R/', $qualifications) ?: [], ['Qualification','QualificationName','Name','Description','QualificationText']);
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('opportunityexperience'))->fetchColumn();
            if ($exists) opp_store_child_values($pdo, 'opportunityexperience', $opportunityId, preg_split('/\R/', $experience) ?: [], ['Experience','ExperienceName','Name','Description','ExperienceText']);
            $pdo->commit();
            opp_form_redirect();
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['_general'] = $ex instanceof PDOException ? 'Unable to save the opportunity. Please check your database fields and try again.' : $ex->getMessage();
    }
}

function opp_value(array $values, string $key): string { return e((string)($values[$key] ?? '')); }
function opp_error(array $errors, string $key): void { if (isset($errors[$key])) echo '<div class="form-error server-error">' . e($errors[$key]) . '</div>'; }
$durationDisplay = '';
if (!empty($values['StartDate']) && !empty($values['EndDate'])) {
    try { $s = new DateTime((string)$values['StartDate']); $en = new DateTime((string)$values['EndDate']); $days = (int)$s->diff($en)->format('%r%a'); if ($days >= 0) { if ($days < 14) $durationDisplay = $days . ' day' . ($days === 1 ? '' : 's'); elseif ($days < 63) { $w=(int)round($days/7); $durationDisplay=$w.' week'.($w===1?'':'s'); } else { $m=(int)round($days/30); $durationDisplay=$m.' month'.($m===1?'':'s'); } } } catch (Throwable $ignored) {}
}
$displayImage = $imagePath !== '' ? '../' . ltrim($imagePath, '/') : '../images-website/default-opportunity.jpg';
if (!empty($uploadedImage)) $displayImage = '../' . ltrim($uploadedImage, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $editing ? 'Edit Opportunity' : 'Add Opportunity' ?> &mdash; Ufuq</title>
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/layout.css">
<link rel="stylesheet" href="../css/icons.css">
<style>
/* Keep the shared Ufuq dashboard design and only add small form-specific helpers. */
.server-error{display:block}
.form-error.server-error{display:block}
.required-mark{color:#c83440;margin-left:3px}
.company-header-profile{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;padding:4px;border-radius:10px;white-space:nowrap}
.company-header-profile:hover{background:var(--color-surface-alt,#f3f4f6)}
.company-header-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e9edf5;color:var(--color-primary,#1f3566);font-weight:700;border:1px solid var(--color-border,#ddd)}
.company-header-avatar img{width:100%;height:100%;object-fit:cover}
.form-banner{padding:12px 16px;border-radius:8px;margin-bottom:16px;background:#fbe6e7;color:#b4232f}
@media(max-width:700px){.page-content.container>div{max-width:100%!important}.form-row{grid-template-columns:1fr!important}.role-toggle{width:100%;max-width:none!important}}
</style>
</head>
<body>
<header id="site-header" class="site-header">
  <div class="container">
    <a href="../index.php" class="brand" style="text-decoration:none"><img src="../images-website/logo.png" alt="Ufuq" class="brand-mark" style="width:34px;height:34px;object-fit:contain;background:transparent"> Ufuq</a>
    <nav class="nav-links"><a href="dashboard-company.php?tab=overview">Dashboard</a><a href="dashboard-company.php?tab=opportunities" class="active">Opportunities</a><a href="dashboard-company.php?tab=applicants">Applicants</a></nav>
    <div class="nav-actions" style="display:flex;align-items:center;gap:10px"><a href="dashboard-company.php?tab=profile" class="company-header-profile" title="Open company profile" aria-label="Open company profile"><span class="company-header-avatar"><?php $headerLogo = trim((string)($company['Logo'] ?? '')); $headerLogoSrc = $headerLogo === '' ? '' : (str_starts_with($headerLogo, 'http') ? $headerLogo : '../' . ltrim($headerLogo, '/')); if ($headerLogoSrc !== ''): ?><img src="<?= e($headerLogoSrc) ?>" alt="Company avatar"><?php else: ?><?= e(strtoupper(substr((string)$company['CompanyName'], 0, 1))) ?><?php endif; ?></span></a><a href="logout.php" class="btn btn-ghost btn-sm">Log Out</a></div>
  </div>
</header>
<main>
  <div class="page-banner"><div class="container"><h2 style="margin:0"><?= $editing ? 'Edit Opportunity' : 'Add Opportunity' ?></h2></div></div>
  <div class="page-content container" style="padding-top:24px"><div style="max-width:960px;width:100%;margin:0 auto">
  <p class="text-muted" style="margin:0 0 16px">Fill in the details students will see.</p>
  <?php if (!empty($errors['_general'])): ?><div class="form-banner" style="display:block;"><?= e($errors['_general']) ?></div><?php endif; ?>
  <?php if ($errors && empty($errors['_general'])): ?><div class="form-banner" style="display:block;">Please review the highlighted fields and try again.</div><?php endif; ?>
  <form id="oppForm" class="card section-card" style="padding:20px" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($editing): ?><input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['OpportunityID'] ?>"><?php endif; ?>
    <div class="form-group">
      <label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/externalLink.png');mask-image:url('../images-website/icons/externalLink.png');"></span>Application Method</label>
      <div class="role-toggle" style="width:100%;max-width:none;"><button type="button" id="methodLinkBtn" class="<?= $values['ApplicationMethod'] === 'Add Link' ? 'active' : '' ?>" <?= $editing ? 'disabled' : '' ?>>Add Link</button><button type="button" id="methodSystemBtn" class="<?= $values['ApplicationMethod'] === 'Add via the System' ? 'active' : '' ?>" <?= $editing ? 'disabled' : '' ?>>Add via the System</button></div>
      <input type="hidden" id="applicationMethod" name="application_method" value="<?= opp_value($values, 'ApplicationMethod') ?>">
      <?php opp_error($errors, 'application_method'); ?>
      <?php if ($editing): ?><div class="form-hint">Application method cannot be changed after the opportunity is created.</div><?php endif; ?>
    </div>
    <div class="form-group" id="externalUrlGroup" style="<?= $values['ApplicationMethod'] === 'Add Link' ? '' : 'display:none;' ?>">
      <label class="form-label icon-label" for="externalUrl"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/externalLink.png');mask-image:url('../images-website/icons/externalLink.png');"></span>External URL</label>
      <input type="url" id="externalUrl" name="external_url" class="form-control <?= isset($errors['external_url']) ? 'invalid' : '' ?>" placeholder="https://" value="<?= opp_value($values, 'ExternalURL') ?>">
      <div class="form-error" id="externalUrlError" style="<?= isset($errors['external_url']) ? 'display:block;' : '' ?>"><?= e($errors['external_url'] ?? 'Please enter a valid URL.') ?></div>
    </div>
    <div class="form-group"><label class="form-label" for="title">Title<span class="required-mark">*</span></label><input type="text" id="title" name="title" class="form-control <?= isset($errors['title']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'Title') ?>"><div class="form-error" id="titleError"><?= e($errors['title'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'title'); ?></div>
    <div class="form-group"><label class="form-label" for="description">Description<span class="required-mark">*</span></label><textarea id="description" name="description" class="form-control <?= isset($errors['description']) ? 'invalid' : '' ?>" rows="4"><?= opp_value($values, 'Description') ?></textarea><div class="form-error" id="descriptionError"><?= e($errors['description'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'description'); ?></div>
    <div class="form-group"><label class="form-label">Opportunity Photo</label><div class="flex gap-3" style="align-items:center;"><img id="oppImagePreview" src="<?= e($displayImage) ?>" alt="Opportunity preview" style="width:64px;height:64px;object-fit:cover;border-radius:var(--radius-md);border:1px solid var(--color-border);flex:0 0 64px"><div><button type="button" class="btn btn-outline btn-sm" id="oppImageBtn"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/upload.png');mask-image:url('../images-website/icons/upload.png');"></span> Upload Photo</button><input type="file" id="oppImageInput" name="opportunity_image" class="hidden" accept=".png,.jpg,.jpeg,image/png,image/jpeg"><div class="form-hint" id="oppImageHint">Optional — a default photo is used if you skip this. PNG/JPG, max 5 MB.</div><div class="form-error" id="oppImageError">Please upload a PNG, JPG, or JPEG file.</div></div></div></div>
    <div class="form-group"><label class="form-label icon-label" for="type"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/training.png');mask-image:url('../images-website/icons/training.png');"></span>Opportunity Type<span class="required-mark">*</span></label><select id="type" name="type" class="form-control <?= isset($errors['type']) ? 'invalid' : '' ?>"><option value="">Select type</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $values['Type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select><div class="form-error" id="typeError"><?= e($errors['type'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'type'); ?></div>
    <div class="form-group"><label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/goal.png');mask-image:url('../images-website/icons/goal.png');"></span>Required Skills</label><div class="flex gap-2" style="margin-bottom:10px;"><input type="text" id="skillInput" class="form-control" placeholder="Type a skill and press Enter"><button type="button" class="btn btn-outline" id="skillAddBtn"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/add.png');mask-image:url('../images-website/icons/add.png');"></span> Add</button></div><div id="skillList"></div><div id="skillsHidden"></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label icon-label" for="duration"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/clock.png');mask-image:url('../images-website/icons/clock.png');"></span>Duration</label><input type="text" id="duration" class="form-control" placeholder="—" readonly value="<?= e($durationDisplay) ?>"><div class="form-hint">Calculated automatically from the start and end dates.</div></div><div class="form-group"><label class="form-label icon-label" for="location"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/location.png');mask-image:url('../images-website/icons/location.png');"></span>Location<span class="required-mark">*</span></label><select id="location" name="location" class="form-control <?= isset($errors['location']) ? 'invalid' : '' ?>"><option value="">Select location</option><?php foreach ($cities as $city): ?><option value="<?= e($city) ?>" <?= $values['Location'] === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?></select><div class="form-error" id="locationError"><?= e($errors['location'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'location'); ?></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label icon-label" for="startDate"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>Start Date<span class="required-mark">*</span></label><input type="date" id="startDate" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'StartDate') ?>"><div class="form-error" id="startDateError"><?= e($errors['start_date'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'start_date'); ?></div><div class="form-group"><label class="form-label icon-label" for="endDate"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>End Date<span class="required-mark">*</span></label><input type="date" id="endDate" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'EndDate') ?>"><div class="form-error" id="endDateError"><?= e($errors['end_date'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'end_date'); ?></div><div class="form-group"><label class="form-label icon-label" for="applicationDeadline"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>Application Deadline<span class="required-mark" id="deadlineRequiredMark" style="<?= $values['ApplicationMethod'] === 'Add via the System' ? '' : 'display:none;' ?>">*</span></label><input type="date" id="applicationDeadline" name="application_deadline" class="form-control <?= isset($errors['application_deadline']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'ApplicationDeadLine') ?>"><div class="form-hint" id="deadlineOptionalHint" style="<?= $values['ApplicationMethod'] === 'Add via the System' ? 'display:none;' : '' ?>">Optional when applying via an external link.</div><div class="form-error" id="applicationDeadlineError"><?= e($errors['application_deadline'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'application_deadline'); ?></div></div>
    <div class="form-group"><label class="form-label icon-label" for="qualifications"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/goal.png');mask-image:url('../images-website/icons/goal.png');"></span>Qualifications<span class="required-mark">*</span></label><textarea id="qualifications" name="qualifications" class="form-control <?= isset($errors['qualifications']) ? 'invalid' : '' ?>" rows="2" placeholder="Enter one qualification per line"><?= opp_value($values, 'Qualifications') ?></textarea><div class="form-hint">Enter each qualification on a separate line.</div><div class="form-error" id="qualificationsError"><?= e($errors['qualifications'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'qualifications'); ?></div>
    <div class="form-group"><label class="form-label icon-label" for="experience"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/form.png');mask-image:url('../images-website/icons/form.png');"></span>Experience<span class="required-mark">*</span></label><textarea id="experience" name="experience" class="form-control <?= isset($errors['experience']) ? 'invalid' : '' ?>" rows="2" placeholder="Enter each experience requirement on a separate line"><?= opp_value($values, 'Experience') ?></textarea><div class="form-hint">Enter each experience requirement on a separate line.</div><div class="form-error" id="experienceError"><?= e($errors['experience'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'experience'); ?></div>
    <div class="flex-between" style="margin-top:12px;"><a href="dashboard-company.php?tab=opportunities" class="btn btn-ghost">Cancel</a><button type="submit" class="btn btn-primary" id="submitBtn"><?= $editing ? 'Save Changes' : 'Publish' ?></button></div>
  </form>
  </div></div>
</main>
<footer class="site-footer"><div class="container"><div class="footer-bottom"><span>&copy; 2026 Ufuq &mdash; IT496 Graduation Project</span></div></div></footer>
<script>
const lockedMethod = <?= $editing ? 'true' : 'false' ?>;
const methodInput = document.getElementById('applicationMethod');
const linkBtn = document.getElementById('methodLinkBtn');
const systemBtn = document.getElementById('methodSystemBtn');
const externalGroup = document.getElementById('externalUrlGroup');
const deadlineMark = document.getElementById('deadlineRequiredMark');
const deadlineHint = document.getElementById('deadlineOptionalHint');
function setMethod(method) {
  if (lockedMethod) return;
  methodInput.value = method;
  linkBtn.classList.toggle('active', method === 'Add Link');
  systemBtn.classList.toggle('active', method === 'Add via the System');
  externalGroup.style.display = method === 'Add Link' ? 'block' : 'none';
  deadlineMark.style.display = method === 'Add via the System' ? 'inline' : 'none';
  deadlineHint.style.display = method === 'Add via the System' ? 'none' : 'block';
}
linkBtn.addEventListener('click', () => setMethod('Add Link'));
systemBtn.addEventListener('click', () => setMethod('Add via the System'));
const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');
const duration = document.getElementById('duration');
function humanizeDuration(start, end) {
  if (!start || !end) return '';
  const a = new Date(start + 'T00:00:00'); const b = new Date(end + 'T00:00:00');
  const days = Math.round((b-a)/86400000); if (!Number.isFinite(days) || days < 0) return '';
  if (days < 14) return `${days} day${days === 1 ? '' : 's'}`;
  if (days < 63) { const n=Math.round(days/7); return `${n} week${n === 1 ? '' : 's'}`; }
  const n=Math.round(days/30); return `${n} month${n === 1 ? '' : 's'}`;
}
function updateDuration(){duration.value=humanizeDuration(startDate.value,endDate.value);}
startDate.addEventListener('change',updateDuration); endDate.addEventListener('change',updateDuration);
const skills = <?= json_encode(array_values($skills), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
const skillInput = document.getElementById('skillInput'); const skillList = document.getElementById('skillList'); const skillsHidden = document.getElementById('skillsHidden');
function renderSkills(){
  skillList.replaceChildren(); skillsHidden.replaceChildren();
  skills.forEach((skill,index)=>{
    const chip=document.createElement('span');chip.className='skill-chip';chip.append(document.createTextNode(skill));
    const remove=document.createElement('button');remove.type='button';remove.setAttribute('aria-label','Remove '+skill);remove.textContent='×';remove.addEventListener('click',()=>{skills.splice(index,1);renderSkills();});chip.append(remove);skillList.append(chip);
    const hidden=document.createElement('input');hidden.type='hidden';hidden.name='skills[]';hidden.value=skill;skillsHidden.append(hidden);
  });
}
function addSkill(){const v=skillInput.value.trim();if(v && !skills.some(s=>s.toLowerCase()===v.toLowerCase()))skills.push(v);skillInput.value='';renderSkills();}
document.getElementById('skillAddBtn').addEventListener('click',addSkill);skillInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addSkill();}});renderSkills();
const imageInput=document.getElementById('oppImageInput');
document.getElementById('oppImageBtn').addEventListener('click',()=>imageInput.click());
imageInput.addEventListener('change',()=>{const f=imageInput.files[0];if(!f)return;const ok=['image/png','image/jpeg'].includes(f.type)&&f.size<=5*1024*1024;document.getElementById('oppImageError').style.display=ok?'none':'block';if(!ok){imageInput.value='';return;}document.getElementById('oppImagePreview').src=URL.createObjectURL(f);document.getElementById('oppImageHint').textContent='Selected: '+f.name;});
document.getElementById('oppForm').addEventListener('submit',e=>{
  let valid=true;
  const requiredIds=['title','description','type','location','startDate','endDate','qualifications','experience'];
  requiredIds.forEach(id=>{const el=document.getElementById(id);const err=document.getElementById(id+'Error');const bad=!el.value.trim();el.classList.toggle('invalid',bad);if(err)err.style.display=bad?'block':'none';if(bad)valid=false;});
  const method=methodInput.value;const url=document.getElementById('externalUrl');const urlErr=document.getElementById('externalUrlError');
  if(method==='Add Link'){let ok=false;try{const u=new URL(url.value.trim());ok=['http:','https:'].includes(u.protocol);}catch(_e){}url.classList.toggle('invalid',!ok);urlErr.style.display=ok?'none':'block';if(!ok)valid=false;}else{url.classList.remove('invalid');urlErr.style.display='none';}
  const deadline=document.getElementById('applicationDeadline');const deadlineErr=document.getElementById('applicationDeadlineError');const deadlineBad=method==='Add via the System'&&!deadline.value;deadline.classList.toggle('invalid',deadlineBad);deadlineErr.style.display=deadlineBad?'block':'none';if(deadlineBad)valid=false;
  if(startDate.value&&endDate.value&&endDate.value<startDate.value){endDate.classList.add('invalid');document.getElementById('endDateError').textContent='End date must be on or after the start date.';document.getElementById('endDateError').style.display='block';valid=false;}
  if(deadline.value&&startDate.value&&deadline.value>startDate.value){deadline.classList.add('invalid');deadlineErr.textContent='Application deadline should be on or before the start date.';deadlineErr.style.display='block';valid=false;}
  if(!valid){e.preventDefault();const banner=document.querySelector('.form-banner');if(banner){banner.style.display='block';banner.textContent='Please fill in all required fields correctly.';}const first=document.querySelector('.invalid');if(first)first.focus();}
});
</script>
</body>
</html>
