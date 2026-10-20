<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

require_login('Company');
$companyId = (int)($_SESSION['user_id'] ?? 0);

$types = defined('OPPORTUNITY_TYPES') ? OPPORTUNITY_TYPES : ['Training', 'Course'];
$cities = defined('CITIES') ? CITIES : ['Riyadh','Jeddah','Mecca','Medina','Dammam','Khobar','Dhahran','Taif','Abha','Tabuk','Online','Other'];

/* ApplicationMethod values as stored in the database. The buttons keep their labels. */
const METHOD_LINK   = 'External Link';
const METHOD_SYSTEM = 'Apply Through Platform';

$errors = [];
$success = '';
$editing = false;
$opportunity = [];
$skillIds = [];
$qualificationIds = [];
$experienceIds = [];

function opp_form_redirect(): never {
    header('Location: dashboard-company.php?tab=opportunities');
    exit;
}

/* skill / qualification / experience are shared lookup tables.
   The form only lets the company pick existing rows (searchable dropdowns), returned as [ID => Name]. */
function opp_lookup_options(PDO $pdo, string $table, string $idCol, string $nameCol): array {
    $rows = $pdo->query("SELECT `$idCol` AS id, `$nameCol` AS name FROM `$table` ORDER BY `$nameCol`")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) $out[(int)$row['id']] = (string)$row['name'];
    return $out;
}
/* Clean posted IDs. Sets $invalid when an ID is not in the lookup table. */
function opp_posted_ids($value, array $options, bool &$invalid): array {
    $ids = [];
    foreach ((array)$value as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if ($id === false || !isset($options[$id])) { $invalid = true; continue; }
        $ids[$id] = $id;
    }
    return array_values($ids);
}
function opp_selected_ids(PDO $pdo, string $linkTable, string $idCol, int $opportunityId): array {
    $stmt = $pdo->prepare("SELECT `$idCol` FROM `$linkTable` WHERE OpportunityID = ?");
    $stmt->execute([$opportunityId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
/* Replace the link rows of one opportunity (the shared lookup rows are never edited or deleted here). */
function opp_sync_links(PDO $pdo, int $opportunityId, string $linkTable, string $idCol, array $ids): void {
    $pdo->prepare("DELETE FROM `$linkTable` WHERE OpportunityID = ?")->execute([$opportunityId]);
    $link = $pdo->prepare("INSERT IGNORE INTO `$linkTable` (OpportunityID, `$idCol`) VALUES (?, ?)");
    foreach ($ids as $id) $link->execute([$opportunityId, (int)$id]);
}
/* Searchable multi-select dropdown. Selected items are shown as chips below it. */
function lookup_picker(string $name, string $placeholder, array $options, array $selectedIds, bool $invalid = false, bool $required = false, string $chipClass = ''): string
{
    ob_start(); ?>
    <div class="lookup-picker" data-lookup-picker data-placeholder="<?= e($placeholder) ?>" data-chip-class="<?= e($chipClass) ?>" <?= $required ? 'data-required' : '' ?>>
        <button type="button" class="form-control lookup-toggle <?= $invalid ? 'invalid' : '' ?>" aria-haspopup="listbox" aria-expanded="false">
            <span class="lookup-toggle-text"><?= e($placeholder) ?></span>
            <span class="lookup-caret" aria-hidden="true">&#9662;</span>
        </button>
        <div class="lookup-panel" hidden>
            <input type="text" class="form-control lookup-search" placeholder="Search..." autocomplete="off">
            <div class="lookup-list">
                <?php foreach ($options as $optId => $optName): ?>
                    <label class="lookup-option" data-text="<?= e(mb_strtolower((string)$optName, 'UTF-8')) ?>">
                        <input type="checkbox" name="<?= e($name) ?>[]" value="<?= (int)$optId ?>" data-label="<?= e($optName) ?>" <?= in_array((int)$optId, $selectedIds, true) ? 'checked' : '' ?>>
                        <span><?= e($optName) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="lookup-empty" hidden>No matches found</div>
            </div>
        </div>
        <div class="lookup-chips"></div>
    </div>
    <?php
    return (string)ob_get_clean();
}

$companyStmt = $pdo->prepare('SELECT c.CompanyID, c.CompanyName, c.IndustrySector, c.Logo FROM company c WHERE c.CompanyID = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch();
if (!$company) { http_response_code(404); exit('Company profile not found.'); }

$skillOptions = opp_lookup_options($pdo, 'skill', 'SkillID', 'SkillName');
$qualificationOptions = opp_lookup_options($pdo, 'qualification', 'QualificationID', 'QualificationName');
$experienceOptions = opp_lookup_options($pdo, 'experience', 'ExperienceID', 'ExperienceName');

$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM opportunity WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
    $stmt->execute([$editId, $companyId]);
    $opportunity = $stmt->fetch() ?: [];
    if (!$opportunity) { header('Location: dashboard-company.php?tab=opportunities'); exit; }
    $editing = true;

    $skillIds = opp_selected_ids($pdo, 'opportunityskill', 'SkillID', $editId);
    $qualificationIds = opp_selected_ids($pdo, 'opportunityqualification', 'QualificationID', $editId);
    $experienceIds = opp_selected_ids($pdo, 'opportunityexperience', 'ExperienceID', $editId);
}

$values = [
    'Title' => $opportunity['Title'] ?? '',
    'Description' => $opportunity['Description'] ?? '',
    'Type' => $opportunity['Type'] ?? '',
    'ApplicationMethod' => $opportunity['ApplicationMethod'] ?? METHOD_LINK,
    'ExternalURL' => $opportunity['ExternalURL'] ?? '',
    'Location' => $opportunity['Location'] ?? '',
    'StartDate' => $opportunity['StartDate'] ?? '',
    'EndDate' => $opportunity['EndDate'] ?? '',
    'ApplicationDeadLine' => $opportunity['ApplicationDeadLine'] ?? '',
    'Price' => isset($opportunity['Price']) ? (string)$opportunity['Price'] : '0',
];
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
        $method = $isEditPost ? (string)($opportunity['ApplicationMethod'] ?? METHOD_LINK) : trim((string)($_POST['application_method'] ?? METHOD_LINK));
        $externalUrl = trim((string)($_POST['external_url'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $deadline = trim((string)($_POST['application_deadline'] ?? ''));
        $priceRaw = trim((string)($_POST['price'] ?? ''));
        if ($priceRaw === '') $priceRaw = '0';
        $badSkill = $badQualification = $badExperience = false;
        $skillIds = opp_posted_ids($_POST['skills'] ?? [], $skillOptions, $badSkill);
        $qualificationIds = opp_posted_ids($_POST['qualifications'] ?? [], $qualificationOptions, $badQualification);
        $experienceIds = opp_posted_ids($_POST['experience'] ?? [], $experienceOptions, $badExperience);

        $values = ['Title'=>$title,'Description'=>$description,'Type'=>$type,'ApplicationMethod'=>$method,'ExternalURL'=>$externalUrl,'Location'=>$location,'StartDate'=>$startDate,'EndDate'=>$endDate,'ApplicationDeadLine'=>$deadline,'Price'=>$priceRaw];
        if ($title === '') $errors['title'] = 'This field is required.';
        elseif (mb_strlen($title, 'UTF-8') > 100) $errors['title'] = 'Title must not exceed 100 characters.';
        if ($description === '') $errors['description'] = 'This field is required.';
        elseif (mb_strlen($description, 'UTF-8') < 15) $errors['description'] = 'Description must be at least 15 characters long.';
        elseif (mb_strlen($description, 'UTF-8') > 1000) $errors['description'] = 'Description must not exceed 1000 characters.';
        if (!in_array($type, $types, true)) $errors['type'] = 'Please select an opportunity type.';
        if (!in_array($location, $cities, true)) $errors['location'] = 'Please select a location.';
        if (!in_array($method, [METHOD_LINK, METHOD_SYSTEM], true)) $errors['application_method'] = 'Please select an application method.';
        if ($method === METHOD_LINK && (!filter_var($externalUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME)), ['http','https'], true))) $errors['external_url'] = 'Please enter a valid URL beginning with http:// or https://.';
        elseif ($method === METHOD_LINK && mb_strlen($externalUrl, 'UTF-8') > 255) $errors['external_url'] = 'URL must not exceed 255 characters.';
        if ($startDate === '' || !$dateStart = DateTime::createFromFormat('!Y-m-d', $startDate)) $errors['start_date'] = 'Please enter a valid start date.';
        if ($endDate === '' || !$dateEnd = DateTime::createFromFormat('!Y-m-d', $endDate)) $errors['end_date'] = 'Please enter a valid end date.';
        if (empty($errors['start_date']) && empty($errors['end_date']) && $dateEnd < $dateStart) $errors['end_date'] = 'End date must be on or after the start date.';
        /* ApplicationDeadLine is NOT NULL in the database, so it is required for both methods. */
        if ($deadline === '') $errors['application_deadline'] = 'This field is required.';
        elseif (!DateTime::createFromFormat('!Y-m-d', $deadline)) $errors['application_deadline'] = 'Please enter a valid deadline.';
        elseif (empty($errors['start_date']) && DateTime::createFromFormat('!Y-m-d', $deadline) > $dateStart) $errors['application_deadline'] = 'Application deadline should be on or before the start date.';
        /* Price is DECIMAL(10,2) with CHECK (Price >= 0); 0 means free. */
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $priceRaw)) $errors['price'] = 'Please enter a valid price (0 or more, up to 2 decimals).';
        $price = preg_match('/^\d{1,8}(\.\d{1,2})?$/', $priceRaw) ? number_format((float)$priceRaw, 2, '.', '') : '0.00';
        /* Skills are required; qualifications and experience are optional. */
        if ($badSkill) $errors['skills'] = 'Please select skills from the list.';
        elseif (!$skillIds) $errors['skills'] = 'This field is required.';
        if ($badQualification) $errors['qualifications'] = 'Please select qualifications from the list.';
        if ($badExperience) $errors['experience'] = 'Please select experience from the list.';

        if ($errors) {
            // Re-display entered values and errors below.
        } else {
            $pdo->beginTransaction();
            if ($isEditPost) {
                /* Status and view count are left untouched on edit; Duration is a generated column. */
                $params = [$title, $description, $type, $method, $company['CompanyName'], $company['IndustrySector'] ?? null, $method === METHOD_LINK ? $externalUrl : null, $startDate, $endDate, $deadline, $location, $price];
                $params[] = $postEditId; $params[] = $companyId;
                $stmt = $pdo->prepare('UPDATE opportunity SET Title = ?, Description = ?, Type = ?, ApplicationMethod = ?, OpportunityProvider = ?, IndustrySector = ?, ExternalURL = ?, StartDate = ?, EndDate = ?, ApplicationDeadLine = ?, Location = ?, Price = ? WHERE OpportunityID = ? AND CreatedByCompanyID = ?');
                $stmt->execute($params);
                $opportunityId = $postEditId;
            } else {
                $cols = 'Title, Description, Type, ApplicationMethod, OpportunityProvider, IndustrySector, ExternalURL, StartDate, EndDate, ApplicationDeadLine, Location, Price, Status, CreatedByCompanyID';
                $params = [$title, $description, $type, $method, $company['CompanyName'], $company['IndustrySector'] ?? null, $method === METHOD_LINK ? $externalUrl : null, $startDate, $endDate, $deadline, $location, $price, 'Open', $companyId];
                $marks = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
                $stmt = $pdo->prepare('INSERT INTO opportunity (' . $cols . ') VALUES (' . $marks . ')');
                $stmt->execute($params);
                $opportunityId = (int)$pdo->lastInsertId();
            }

            opp_sync_links($pdo, $opportunityId, 'opportunityskill', 'SkillID', $skillIds);
            opp_sync_links($pdo, $opportunityId, 'opportunityqualification', 'QualificationID', $qualificationIds);
            opp_sync_links($pdo, $opportunityId, 'opportunityexperience', 'ExperienceID', $experienceIds);
            $pdo->commit();
            opp_form_redirect();
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Opportunity save failed: ' . $ex->getMessage());
        $errors['_general'] = $ex instanceof PDOException ? 'Unable to save the opportunity. Please try again.' : $ex->getMessage();
    }
}

function opp_value(array $values, string $key): string { return e((string)($values[$key] ?? '')); }
function opp_error(array $errors, string $key): void { if (isset($errors[$key])) echo '<div class="form-error server-error">' . e($errors[$key]) . '</div>'; }
$durationDisplay = '';
if (!empty($values['StartDate']) && !empty($values['EndDate'])) {
    try { $s = new DateTime((string)$values['StartDate']); $en = new DateTime((string)$values['EndDate']); $days = (int)$s->diff($en)->format('%r%a'); if ($days >= 0) { if ($days < 14) $durationDisplay = $days . ' day' . ($days === 1 ? '' : 's'); elseif ($days < 63) { $w=(int)round($days/7); $durationDisplay=$w.' week'.($w===1?'':'s'); } else { $m=(int)round($days/30); $durationDisplay=$m.' month'.($m===1?'':'s'); } } } catch (Throwable $ignored) {}
}
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
/* Searchable multi-select pickers */
#oppForm .lookup-picker{position:relative}
#oppForm .lookup-toggle{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;text-align:left;cursor:pointer}
#oppForm .lookup-toggle-text.is-placeholder{color:#8a8396}
#oppForm .lookup-caret{flex-shrink:0;color:#8a8396;font-size:.8rem;transition:transform .18s ease}
#oppForm .lookup-picker.is-open .lookup-caret{transform:rotate(180deg)}
#oppForm .lookup-panel{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:40;padding:10px;border:1px solid #e5ddef;border-radius:12px;background:#fff;box-shadow:0 12px 30px rgba(40,26,70,.14)}
#oppForm .lookup-panel[hidden]{display:none!important}
#oppForm .lookup-list{max-height:210px;margin-top:8px;overflow-y:auto}
#oppForm .lookup-option{display:flex;align-items:flex-start;gap:9px;padding:8px;border-radius:8px;font-size:.9rem;line-height:1.5;cursor:pointer;overflow-wrap:anywhere}
#oppForm .lookup-option:hover{background:var(--color-surface-alt,#f3f4f6)}
#oppForm .lookup-option[hidden]{display:none!important}
#oppForm .lookup-option input{width:16px;height:16px;flex-shrink:0;margin:3px 0 0;accent-color:var(--color-primary,#39265f);cursor:pointer}
#oppForm .lookup-empty{padding:10px 8px;color:#8a8396;font-size:.85rem;text-align:center}
#oppForm .lookup-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
/* ===== Same look as the other Ufuq pages (purple theme) ===== */
:root{--admin-purple:#39265f;--admin-purple-dark:#281a46;--admin-purple-light:#f4f0fa;--admin-gold:#e2a94b;--entry-error:#d94b56;--entry-error-text:#c43643;--entry-border:#e5dfed;--entry-muted:#777184}
main{background:#faf6ef;min-height:70vh;padding-bottom:48px}
.dashboard-hero{position:relative;overflow:hidden;padding:38px 0 42px;margin-bottom:28px;background:linear-gradient(125deg,#281a46 0%,#39265f 55%,#49316f 100%);color:#fff}
.dashboard-hero::before,.dashboard-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.dashboard-hero::before{width:250px;height:250px;right:7%;top:-150px;border:35px solid rgba(255,255,255,.07)}
.dashboard-hero::after{width:190px;height:190px;right:23%;bottom:-145px;background:rgba(226,169,75,.10)}
.dashboard-hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:22px}
.dashboard-hero .welcome-label{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;margin-bottom:12px;border:1px solid rgba(255,255,255,.2);border-radius:30px;background:rgba(255,255,255,.08);font-size:.84rem;letter-spacing:.2px}
.dashboard-hero h1{margin:0;color:#fff;font-size:clamp(1.65rem,3vw,2.15rem);font-weight:750;line-height:1.5}
.dashboard-hero p{margin:8px 0 0;color:rgba(255,255,255,.82);font-size:.96rem;line-height:1.8}
.dashboard-hero .hero-icon{display:flex;align-items:center;justify-content:center;width:78px;height:78px;flex-shrink:0;border:1px solid rgba(255,255,255,.2);border-radius:24px;background:rgba(255,255,255,.08);box-shadow:0 10px 28px rgba(15,8,30,.16);font-size:2rem;animation:oppFloat 4s ease-in-out infinite}
.opp-section-heading{display:flex;align-items:center;gap:10px;margin:0 0 7px;color:#29243a;font-size:1.18rem;font-weight:750}
.opp-section-heading::before{content:"";display:block;width:5px;height:23px;flex-shrink:0;border-radius:5px;background:#e2a94b}
.page-content.container>div>p.text-muted{color:var(--entry-muted);font-size:.9rem;line-height:1.7;margin-bottom:18px!important}
@keyframes oppFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
@media(max-width:620px){.dashboard-hero{padding:30px 0}.dashboard-hero .hero-icon{width:60px;height:60px;border-radius:18px;font-size:1.6rem}}
#oppForm.card{display:block;width:100%;box-sizing:border-box;padding:clamp(20px,3.5vw,30px)!important;background:#fff;border:1px solid #e9e3ed;border-radius:16px;box-shadow:0 5px 18px rgba(40,26,70,.045);overflow:visible}
#oppForm .form-group{min-width:0;margin-bottom:21px}
#oppForm .form-label{display:block;margin-bottom:8px;color:#35264f;font-size:.92rem;font-weight:700;line-height:1.5}
#oppForm .icon-label{display:flex;align-items:center;flex-wrap:wrap;gap:7px;color:var(--admin-purple)}
#oppForm .icon-label .icon{color:var(--admin-purple);background-color:var(--admin-purple)}
#oppForm .required-mark{color:var(--entry-error);font-weight:800;margin-left:2px}
#oppForm .form-control{display:block;width:100%;min-width:0;min-height:46px;box-sizing:border-box;padding:11px 13px;border:1px solid #dcd5e8;border-radius:10px;outline:none;background:#fff;color:#2c233b;font:inherit;font-size:.94rem;line-height:1.5;box-shadow:0 1px 2px rgba(40,26,70,.025);transition:border-color .18s ease,box-shadow .18s ease,background-color .18s ease}
#oppForm textarea.form-control{min-height:125px;resize:vertical}
#oppForm .form-control::placeholder{color:#a19aaa;opacity:1}
#oppForm .form-control:hover{border-color:#b9a8d3}
#oppForm .form-control:focus{border-color:var(--admin-purple)!important;box-shadow:0 0 0 4px rgba(57,38,95,.11)}
#oppForm select.form-control{cursor:pointer}
#oppForm .form-control[readonly]{color:#675b78;background:#f4f1f8;border-color:#e3dcec;cursor:default}
#oppForm .form-control.invalid{border:1.5px solid var(--entry-error)!important;background-color:#fff7f7!important;box-shadow:0 0 0 3px rgba(217,75,86,.08)}
#oppForm .form-error{color:var(--entry-error-text);font-size:.84rem;line-height:1.5;margin-top:7px}
#oppForm .form-hint{margin-top:7px;color:var(--entry-muted);font-size:.82rem;line-height:1.5}
#oppForm .form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}
#oppForm .form-row:has(#applicationDeadline){grid-template-columns:repeat(3,minmax(0,1fr))}
#oppForm .role-toggle{display:flex;gap:6px;padding:5px;border:1px solid #e5ddef;border-radius:12px;background:var(--admin-purple-light)}
#oppForm .role-toggle button{flex:1;min-height:40px;padding:9px 14px;border:0;border-radius:9px;background:transparent;color:#5e536d;font:inherit;font-size:.9rem;font-weight:700;cursor:pointer;transition:background-color .18s ease,color .18s ease,box-shadow .18s ease}
#oppForm .role-toggle button:hover:not(:disabled){color:var(--admin-purple)}
#oppForm .role-toggle button.active{background:var(--admin-purple);color:#fff;box-shadow:0 4px 12px rgba(57,38,95,.18)}
#oppForm .role-toggle button:disabled{cursor:not-allowed;opacity:.75}
#oppForm .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:43px;padding:10px 17px;border:1px solid transparent;border-radius:10px;font:inherit;font-size:.91rem;font-weight:700;line-height:1.3;text-decoration:none;cursor:pointer;transition:background-color .18s ease,border-color .18s ease,color .18s ease,box-shadow .18s ease,transform .18s ease}
#oppForm .btn:hover{transform:translateY(-1px)}
#oppForm .btn-primary{color:#fff;background:var(--admin-purple);border-color:var(--admin-purple);box-shadow:0 5px 13px rgba(57,38,95,.18)}
#oppForm .btn-primary:hover{color:#fff;background:var(--admin-purple-dark);border-color:var(--admin-purple-dark);box-shadow:0 7px 17px rgba(40,26,70,.22)}
#oppForm .btn-outline{color:var(--admin-purple);background:#fff;border-color:#cfc1e3}
#oppForm .btn-outline:hover{background:var(--admin-purple-light);border-color:#ad98ce}
#oppForm .btn-ghost{color:#5e536d;background:#fff;border-color:#ded7e8}
#oppForm .btn-ghost:hover{color:var(--admin-purple);background:var(--admin-purple-light);border-color:#cfc1e3}
#oppForm .flex-between{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:24px!important;padding-top:21px;border-top:1px solid #eee8f4}
#oppForm .flex-between .btn{min-width:125px}
#oppForm .lookup-option:hover{background:var(--admin-purple-light)}
#oppForm .lookup-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border:1px solid #d9cbea;border-radius:999px;background:#f2ecfa;color:var(--admin-purple-dark);font-size:.84rem;font-weight:700}
#oppForm .lookup-chip button{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;padding:0;border:0;border-radius:50%;background:none;color:var(--admin-purple);font-size:1rem;line-height:1;cursor:pointer}
#oppForm .lookup-chip button:hover{color:var(--entry-error);background:rgba(217,75,86,.09)}
.form-banner{border:1px solid #f1c8cb;background:#fff3f3!important;color:#b82f3b!important;border-radius:10px!important;line-height:1.6}
@media(max-width:1000px){#oppForm .form-row:has(#applicationDeadline){grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){#oppForm .form-row,#oppForm .form-row:has(#applicationDeadline){grid-template-columns:minmax(0,1fr);gap:0}}
@media(prefers-reduced-motion:reduce){#oppForm *{transition:none!important}}
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
  <section class="dashboard-hero">
    <div class="container dashboard-hero-inner">
      <div>
        <div class="welcome-label"><span aria-hidden="true">✦</span><span>Ufuq Company Space</span></div>
        <h1><?= $editing ? 'Edit Opportunity' : 'Add Opportunity' ?></h1>
        <p>Publish a training program or course for students to discover and apply to.</p>
      </div>
      <div class="hero-icon" aria-hidden="true">✦</div>
    </div>
  </section>
  <div class="page-content container" style="padding-top:24px"><div style="max-width:960px;width:100%;margin:0 auto">
  <h2 class="opp-section-heading">Opportunity Details</h2>
  <p class="text-muted" style="margin:0 0 16px">Fill in the details students will see.</p>
  <?php if (!empty($errors['_general'])): ?><div class="form-banner" style="display:block;"><?= e($errors['_general']) ?></div><?php endif; ?>
  <?php if ($errors && empty($errors['_general'])): ?><div class="form-banner" style="display:block;">Please review the highlighted fields and try again.</div><?php endif; ?>
  <form id="oppForm" class="card section-card" style="padding:20px" method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($editing): ?><input type="hidden" name="opportunity_id" value="<?= (int)$opportunity['OpportunityID'] ?>"><?php endif; ?>
    <div class="form-group">
      <label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/externalLink.png');mask-image:url('../images-website/icons/externalLink.png');"></span>Application Method</label>
      <div class="role-toggle" style="width:100%;max-width:none;"><button type="button" id="methodLinkBtn" class="<?= $values['ApplicationMethod'] === METHOD_LINK ? 'active' : '' ?>" <?= $editing ? 'disabled' : '' ?>>Add Link</button><button type="button" id="methodSystemBtn" class="<?= $values['ApplicationMethod'] === METHOD_SYSTEM ? 'active' : '' ?>" <?= $editing ? 'disabled' : '' ?>>Add via the Platform</button></div>
      <input type="hidden" id="applicationMethod" name="application_method" value="<?= opp_value($values, 'ApplicationMethod') ?>">
      <?php opp_error($errors, 'application_method'); ?>
      <?php if ($editing): ?><div class="form-hint">Application method cannot be changed after the opportunity is created.</div><?php endif; ?>
    </div>
    <div class="form-group" id="externalUrlGroup" style="<?= $values['ApplicationMethod'] === METHOD_LINK ? '' : 'display:none;' ?>">
      <label class="form-label icon-label" for="externalUrl"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/externalLink.png');mask-image:url('../images-website/icons/externalLink.png');"></span>External URL<span class="required-mark">*</span></label>
      <input type="url" id="externalUrl" name="external_url" class="form-control <?= isset($errors['external_url']) ? 'invalid' : '' ?>" placeholder="https://" value="<?= opp_value($values, 'ExternalURL') ?>">
      <div class="form-error" id="externalUrlError" style="<?= isset($errors['external_url']) ? 'display:block;' : '' ?>"><?= e($errors['external_url'] ?? 'Please enter a valid URL.') ?></div>
    </div>
    <div class="form-group"><label class="form-label" for="title">Title <span class="required-mark">*</span></label><input type="text" id="title" name="title" maxlength="100" class="form-control <?= isset($errors['title']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'Title') ?>"><div class="form-error" id="titleError"><?= e($errors['title'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'title'); ?></div>
    <div class="form-group"><label class="form-label" for="description">Description<span class="required-mark">*</span></label><textarea id="description" name="description" maxlength="1000" class="form-control <?= isset($errors['description']) ? 'invalid' : '' ?>" rows="4"><?= opp_value($values, 'Description') ?></textarea><div class="form-error" id="descriptionError"><?= e($errors['description'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'description'); ?></div>
    <div class="form-row"><div class="form-group"><label class="form-label icon-label" for="type"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/training.png');mask-image:url('../images-website/icons/training.png');"></span>Opportunity Type<span class="required-mark">*</span></label><select id="type" name="type" class="form-control <?= isset($errors['type']) ? 'invalid' : '' ?>"><option value="">Select type</option><?php foreach ($types as $type): ?><option value="<?= e($type) ?>" <?= $values['Type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select><div class="form-error" id="typeError"><?= e($errors['type'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'type'); ?></div><div class="form-group"><label class="form-label icon-label" for="price"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/goal.png');mask-image:url('../images-website/icons/goal.png');"></span>Price (SAR)</label><input type="number" id="price" name="price" min="0" step="0.01" inputmode="decimal" placeholder="0" class="form-control <?= isset($errors['price']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'Price') ?>"><div class="form-hint">Enter 0 if the opportunity is free.</div><div class="form-error" id="priceError"><?= e($errors['price'] ?? 'Please enter a valid price.') ?></div><?php opp_error($errors, 'price'); ?></div></div>
    <div class="form-group"><label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/goal.png');mask-image:url('../images-website/icons/goal.png');"></span>Required Skills<span class="required-mark">*</span></label><?= lookup_picker('skills', 'Select skills', $skillOptions, $skillIds, isset($errors['skills']), true, '') ?><div class="form-error" id="skillsError"><?= e($errors['skills'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'skills'); ?></div>
    <div class="form-row"><div class="form-group"><label class="form-label icon-label" for="duration"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/clock.png');mask-image:url('../images-website/icons/clock.png');"></span>Duration</label><input type="text" id="duration" class="form-control" placeholder="—" readonly value="<?= e($durationDisplay) ?>"><div class="form-hint">Calculated automatically from the start and end dates.</div></div><div class="form-group"><label class="form-label icon-label" for="location"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/location.png');mask-image:url('../images-website/icons/location.png');"></span>Location<span class="required-mark">*</span></label><select id="location" name="location" class="form-control <?= isset($errors['location']) ? 'invalid' : '' ?>"><option value="">Select location</option><?php foreach ($cities as $city): ?><option value="<?= e($city) ?>" <?= $values['Location'] === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?></select><div class="form-error" id="locationError"><?= e($errors['location'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'location'); ?></div></div>
    <div class="form-row"><div class="form-group"><label class="form-label icon-label" for="startDate"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>Start Date<span class="required-mark">*</span></label><input type="date" id="startDate" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'StartDate') ?>"><div class="form-error" id="startDateError"><?= e($errors['start_date'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'start_date'); ?></div><div class="form-group"><label class="form-label icon-label" for="endDate"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>End Date<span class="required-mark">*</span></label><input type="date" id="endDate" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'EndDate') ?>"><div class="form-error" id="endDateError"><?= e($errors['end_date'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'end_date'); ?></div><div class="form-group"><label class="form-label icon-label" for="applicationDeadline"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/calendar.png');mask-image:url('../images-website/icons/calendar.png');"></span>Application Deadline<span class="required-mark" id="deadlineRequiredMark">*</span></label><input type="date" id="applicationDeadline" name="application_deadline" class="form-control <?= isset($errors['application_deadline']) ? 'invalid' : '' ?>" value="<?= opp_value($values, 'ApplicationDeadLine') ?>"><div class="form-error" id="applicationDeadlineError"><?= e($errors['application_deadline'] ?? 'This field is required.') ?></div><?php opp_error($errors, 'application_deadline'); ?></div></div>
    <div class="form-group"><label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/goal.png');mask-image:url('../images-website/icons/goal.png');"></span>Qualifications</label><?= lookup_picker('qualifications', 'Select qualifications', $qualificationOptions, $qualificationIds, isset($errors['qualifications']), false, '') ?><?php opp_error($errors, 'qualifications'); ?></div>
    <div class="form-group"><label class="form-label icon-label"><span class="icon" style="-webkit-mask-image:url('../images-website/icons/form.png');mask-image:url('../images-website/icons/form.png');"></span>Experience</label><?= lookup_picker('experience', 'Select experience', $experienceOptions, $experienceIds, isset($errors['experience']), false, '') ?><?php opp_error($errors, 'experience'); ?></div>
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
function setMethod(method) {
  if (lockedMethod) return;
  methodInput.value = method;
  linkBtn.classList.toggle('active', method === 'External Link');
  systemBtn.classList.toggle('active', method === 'Apply Through Platform');
  externalGroup.style.display = method === 'External Link' ? 'block' : 'none';
}
linkBtn.addEventListener('click', () => setMethod('External Link'));
systemBtn.addEventListener('click', () => setMethod('Apply Through Platform'));
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
/* Searchable multi-select pickers (skills / qualifications / experience). */
document.querySelectorAll('[data-lookup-picker]').forEach(function (picker) {
  const toggle = picker.querySelector('.lookup-toggle');
  const toggleText = picker.querySelector('.lookup-toggle-text');
  const panel = picker.querySelector('.lookup-panel');
  const search = picker.querySelector('.lookup-search');
  const chips = picker.querySelector('.lookup-chips');
  const options = picker.querySelectorAll('.lookup-option');
  const boxes = picker.querySelectorAll('input[type="checkbox"]');
  const empty = picker.querySelector('.lookup-empty');

  function render() {
    chips.replaceChildren();
    let count = 0;
    boxes.forEach(function (box) {
      if (!box.checked) return;
      count++;
      const chip = document.createElement('span');
      chip.className = 'lookup-chip ' + (picker.dataset.chipClass || '');
      chip.append(document.createTextNode(box.dataset.label + ' '));
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = '\u00d7';
      remove.setAttribute('aria-label', 'Remove ' + box.dataset.label);
      remove.addEventListener('click', function () { box.checked = false; render(); });
      chip.append(remove);
      chips.append(chip);
    });
    toggleText.textContent = count ? count + ' selected' : picker.dataset.placeholder;
    toggleText.classList.toggle('is-placeholder', count === 0);
    if (count) toggle.classList.remove('invalid');
  }

  function closePanel() {
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    picker.classList.remove('is-open');
  }

  toggle.addEventListener('click', function () {
    const willOpen = panel.hidden;
    document.querySelectorAll('[data-lookup-picker] .lookup-panel').forEach(function (p) {
      if (p !== panel) { p.hidden = true; p.closest('[data-lookup-picker]').classList.remove('is-open'); }
    });
    panel.hidden = !willOpen;
    toggle.setAttribute('aria-expanded', String(willOpen));
    picker.classList.toggle('is-open', willOpen);
    if (willOpen) search.focus();
  });

  search.addEventListener('input', function () {
    const q = search.value.trim().toLowerCase();
    let visible = 0;
    options.forEach(function (opt) {
      const match = opt.dataset.text.indexOf(q) !== -1;
      opt.hidden = !match;
      if (match) visible++;
    });
    empty.hidden = visible !== 0;
  });
  search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
  picker.addEventListener('change', render);
  picker.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePanel(); toggle.focus(); } });
  document.addEventListener('click', function (e) { if (!picker.contains(e.target)) closePanel(); });
  render();
});
document.getElementById('oppForm').addEventListener('submit',e=>{
  let valid=true;
  const requiredIds=['title','description','type','location','startDate','endDate'];
  requiredIds.forEach(id=>{const el=document.getElementById(id);const err=document.getElementById(id+'Error');const bad=!el.value.trim();el.classList.toggle('invalid',bad);if(err)err.style.display=bad?'block':'none';if(bad)valid=false;});
  document.querySelectorAll('[data-lookup-picker][data-required]').forEach(p=>{const n=p.querySelector('input[type="checkbox"]').name.replace('[]','');const bad=!p.querySelector('input:checked');p.querySelector('.lookup-toggle').classList.toggle('invalid',bad);const err=document.getElementById(n+'Error');if(err)err.style.display=bad?'block':'none';if(bad)valid=false;});
  const descEl=document.getElementById('description');const descErr=document.getElementById('descriptionError');if(descEl.value.trim()&&descEl.value.trim().length<15){descEl.classList.add('invalid');descErr.textContent='Description must be at least 15 characters long.';descErr.style.display='block';valid=false;}
  const method=methodInput.value;const url=document.getElementById('externalUrl');const urlErr=document.getElementById('externalUrlError');
  if(method==='External Link'){let ok=false;try{const u=new URL(url.value.trim());ok=['http:','https:'].includes(u.protocol);}catch(_e){}url.classList.toggle('invalid',!ok);urlErr.style.display=ok?'none':'block';if(!ok)valid=false;}else{url.classList.remove('invalid');urlErr.style.display='none';}
  const priceEl=document.getElementById('price');const priceErr=document.getElementById('priceError');const priceBad=priceEl.value.trim()!==''&&!/^\d{1,8}(\.\d{1,2})?$/.test(priceEl.value.trim());priceEl.classList.toggle('invalid',priceBad);priceErr.style.display=priceBad?'block':'none';if(priceBad)valid=false;
  /* The deadline is required for both methods (NOT NULL in the database). */
  const deadline=document.getElementById('applicationDeadline');const deadlineErr=document.getElementById('applicationDeadlineError');const deadlineBad=!deadline.value;deadline.classList.toggle('invalid',deadlineBad);deadlineErr.style.display=deadlineBad?'block':'none';if(deadlineBad)valid=false;
  if(startDate.value&&endDate.value&&endDate.value<startDate.value){endDate.classList.add('invalid');document.getElementById('endDateError').textContent='End date must be on or after the start date.';document.getElementById('endDateError').style.display='block';valid=false;}
  if(deadline.value&&startDate.value&&deadline.value>startDate.value){deadline.classList.add('invalid');deadlineErr.textContent='Application deadline should be on or before the start date.';deadlineErr.style.display='block';valid=false;}
  if(!valid){e.preventDefault();const banner=document.querySelector('.form-banner');if(banner){banner.style.display='block';banner.textContent='Please fill in all required fields correctly.';}const first=document.querySelector('.invalid');if(first)first.focus();}
});
</script>
</body>
</html>
