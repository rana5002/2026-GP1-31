<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/lists.php';

$adminId = require_admin();

$editId = (int)($_GET['id'] ?? 0);
$editing = null;

if ($editId) {
    $st = $pdo->prepare(
        'SELECT * FROM opportunity
         WHERE OpportunityID = ? AND CreatedByAdminID IS NOT NULL'
    );
    $st->execute([$editId]);
    $editing = $st->fetch(PDO::FETCH_ASSOC);

    if (!$editing) {
        header('Location: dashboard-admin.php?tab=opportunities');
        exit;
    }
}

$v = [
    'company_name' => '',
    'industry_sector' => '',
    'industry_other' => '',
    'external_url' => '',
    'title' => '',
    'description' => '',
    'type' => '',
    'location' => '',
    'start_date' => '',
    'end_date' => '',
    'application_deadline' => '',
    'application_open_date' => '',
    'screening_date' => '',
    'interview_date' => '',
    'acceptance_date' => '',
    'price_status' => 'Unknown',
    'price' => ''
];

$skillIds = [];
$qualificationIds = [];
$experienceIds = [];
$errors = [];
$requiredEmpty = false;

/* Industry sectors and locations come from the shared lists (includes/lists.php).
   When editing an old opportunity whose value is not in the list, that value is kept as an extra choice. */
function opp_list_with(array $list, string $current): array
{
    return ($current !== '' && !in_array($current, $list, true)) ? array_merge($list, [$current]) : $list;
}

/**
 * skill / qualification / experience are shared lookup tables.
 * The form only lets the admin pick existing rows (searchable dropdowns),
 * returned as [ID => Name].
 */
function opp_lookup_options(PDO $pdo, string $table, string $idCol, string $nameCol): array
{
    $rows = $pdo->query(
        "SELECT `$idCol` AS id, `$nameCol` AS name FROM `$table` ORDER BY `$nameCol`"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['id']] = (string)$row['name'];
    }

    return $out;
}

/* Clean posted IDs. Sets $invalid when an ID is not in the lookup table. */
function opp_posted_ids($value, array $options, bool &$invalid): array
{
    $ids = [];

    foreach ((array)$value as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);

        if ($id === false || !isset($options[$id])) {
            $invalid = true;
            continue;
        }

        $ids[$id] = $id;
    }

    return array_values($ids);
}

function opp_selected_ids(PDO $pdo, string $linkTable, string $idCol, int $opportunityId): array
{
    $stmt = $pdo->prepare("SELECT `$idCol` FROM `$linkTable` WHERE OpportunityID = ?");
    $stmt->execute([$opportunityId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/* Replace the link rows of one opportunity (shared lookup rows are never edited or deleted here). */
function opp_sync_links(PDO $pdo, int $opportunityId, string $linkTable, string $idCol, array $ids): void
{
    $pdo->prepare("DELETE FROM `$linkTable` WHERE OpportunityID = ?")->execute([$opportunityId]);
    $link = $pdo->prepare("INSERT IGNORE INTO `$linkTable` (OpportunityID, `$idCol`) VALUES (?, ?)");

    foreach ($ids as $id) {
        $link->execute([$opportunityId, (int)$id]);
    }
}

/* Timeline stages are columns of `opportunity`: ApplicationOpenDate, ApplicationDeadLine,
   ScreeningDate, InterviewDate and AcceptanceDate. An empty date is saved as NULL.
   The order matches the CHECK constraints in the database:
   open <= deadline <= screening / interview / acceptance <= start date. */
const TIMELINE_FIELDS = [
    'application_open_date' => 'Application opening',
    'application_deadline'  => 'Application deadline',
    'screening_date'        => 'Initial screening',
    'interview_date'        => 'Interview',
    'acceptance_date'       => 'Acceptance',
];

function opp_valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

/* Returns [field => message]. $required lists the stages that must not be empty. */
function opp_timeline_errors(array $dates, string $start, array $required): array
{
    $errors = [];

    foreach (TIMELINE_FIELDS as $key => $_) {
        $date = $dates[$key] ?? '';
        if ($date === '') {
            if (in_array($key, $required, true)) {
                $errors[$key] = 'This field is required.';
            }
        } elseif (!opp_valid_date($date)) {
            $errors[$key] = 'Please enter a valid date.';
        }
    }

    $has = function (string $key) use (&$errors, $dates): bool {
        return ($dates[$key] ?? '') !== '' && !isset($errors[$key]);
    };
    $start = opp_valid_date($start) ? $start : '';

    if ($has('application_deadline') && $start !== '' && $dates['application_deadline'] > $start) {
        $errors['application_deadline'] = 'Application deadline should be on or before the start date.';
    }

    if ($has('application_open_date')) {
        if ($has('application_deadline') && $dates['application_open_date'] > $dates['application_deadline']) {
            $errors['application_open_date'] = 'Applications must open on or before the application deadline.';
        } elseif ($start !== '' && $dates['application_open_date'] > $start) {
            $errors['application_open_date'] = 'Applications must open on or before the start date.';
        }
    }

    $prev = null;
    foreach (['screening_date', 'interview_date', 'acceptance_date'] as $key) {
        if (!$has($key)) {
            continue;
        }
        $label = TIMELINE_FIELDS[$key];
        if ($has('application_deadline') && $dates[$key] < $dates['application_deadline']) {
            $errors[$key] = "$label must be on or after the application deadline.";
        } elseif ($start !== '' && $dates[$key] > $start) {
            $errors[$key] = "$label must be on or before the start date.";
        } elseif ($prev !== null && $dates[$key] < $dates[$prev]) {
            $errors[$key] = $label . ' must be on or after the ' . strtolower(TIMELINE_FIELDS[$prev]) . '.';
        }
        if (!isset($errors[$key])) {
            $prev = $key;
        }
    }

    return $errors;
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

$skillOptions = opp_lookup_options($pdo, 'skill', 'SkillID', 'SkillName');
$qualificationOptions = opp_lookup_options($pdo, 'qualification', 'QualificationID', 'QualificationName');
$experienceOptions = opp_lookup_options($pdo, 'experience', 'ExperienceID', 'ExperienceName');

if ($editing) {
    $v = [
        'company_name' => $editing['OpportunityProvider'] ?? '',
        'industry_sector' => $editing['IndustrySector'] ?? '',
        'industry_other' => '',
        'external_url' => $editing['ExternalURL'] ?? '',
        'title' => $editing['Title'] ?? '',
        'description' => $editing['Description'] ?? '',
        'type' => $editing['Type'] ?? '',
        'location' => $editing['Location'] ?? '',
        'start_date' => $editing['StartDate'] ?? '',
        'end_date' => $editing['EndDate'] ?? '',
        'application_deadline' => $editing['ApplicationDeadLine'] ?? '',
        'application_open_date' => $editing['ApplicationOpenDate'] ?? '',
        'screening_date' => $editing['ScreeningDate'] ?? '',
        'interview_date' => $editing['InterviewDate'] ?? '',
        'acceptance_date' => $editing['AcceptanceDate'] ?? '',
        /* Price status is stored in PriceStatus and the amount in Retail. */
        'price_status' => in_array($editing['PriceStatus'] ?? '', ['Paid', 'Free', 'Unknown'], true)
            ? $editing['PriceStatus']
            : 'Unknown',
        'price' => ($editing['PriceStatus'] ?? '') === 'Paid'
            ? (string)($editing['Retail'] ?? '')
            : (($editing['PriceStatus'] ?? '') === 'Free' ? '0' : '')
    ];

    /* A sector that is not in the list was typed under "Other": show it that way. */
    if ($v['industry_sector'] !== '' && !in_array($v['industry_sector'], INDUSTRIES, true)) {
        $v['industry_other'] = $v['industry_sector'];
        $v['industry_sector'] = 'Other';
    }

    $skillIds = opp_selected_ids($pdo, 'opportunityskill', 'SkillID', $editId);
    $qualificationIds = opp_selected_ids($pdo, 'opportunityqualification', 'QualificationID', $editId);
    $experienceIds = opp_selected_ids($pdo, 'opportunityexperience', 'ExperienceID', $editId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($v['price_status'] === '') {
        $v['price_status'] = 'Unknown';
    }

    /* Free = price 0; Unknown = price stays empty. */
    if ($v['price_status'] === 'Free') {
        $v['price'] = '0';
    } elseif ($v['price_status'] === 'Unknown') {
        $v['price'] = '';
    }

    $badSkill = $badQualification = $badExperience = false;
    $skillIds = opp_posted_ids($_POST['skills'] ?? [], $skillOptions, $badSkill);
    $qualificationIds = opp_posted_ids($_POST['qualifications'] ?? [], $qualificationOptions, $badQualification);
    $experienceIds = opp_posted_ids($_POST['experience'] ?? [], $experienceOptions, $badExperience);

    /* Validate required opportunity fields. */
    foreach ([
        'company_name',
        'industry_sector',
        'title',
        'description',
        'type',
        'location',
        'start_date',
        'end_date',
        'external_url'
    ] as $k) {
        if ($v[$k] === '') {
            $errors[$k] = 'This field is required.';
            $requiredEmpty = true;
        }
    }

    /* Length limits that match the database columns. */
    $limits = [
        'company_name' => 100,
        'industry_sector' => 50,
        'title' => 100,
        'external_url' => 255
    ];

    foreach ($limits as $k => $max) {
        if (empty($errors[$k]) && mb_strlen($v[$k], 'UTF-8') > $max) {
            $errors[$k] = "Must not exceed $max characters.";
        }
    }

    /* DESCRIPTION: minimum 15, maximum 1000 characters. */
    if (empty($errors['description']) && $v['description'] !== '') {
        $len = mb_strlen($v['description'], 'UTF-8');

        if ($len < 15) {
            $errors['description'] =
                'Description must be at least 15 characters long.';
        } elseif ($len > 1000) {
            $errors['description'] =
                'Description must not exceed 1000 characters.';
        }
    }

    /* Skills are required; qualifications and experience are optional. */
    if ($badSkill) {
        $errors['skills'] = 'Please select skills from the list.';
    } elseif (!$skillIds) {
        $errors['skills'] = 'This field is required.';
        $requiredEmpty = true;
    }

    if ($badQualification) {
        $errors['qualifications'] = 'Please select qualifications from the list.';
    }

    if ($badExperience) {
        $errors['experience'] = 'Please select experience from the list.';
    }

    if (
        empty($errors['external_url']) &&
        $v['external_url'] !== '' &&
        (
            !filter_var($v['external_url'], FILTER_VALIDATE_URL) ||
            !preg_match('#^https?://#i', $v['external_url'])
        )
    ) {
        $errors['external_url'] =
            'Please enter a valid URL starting with http:// or https://.';
    }

    if (
        $v['type'] !== '' &&
        !in_array($v['type'], OPPORTUNITY_TYPES, true)
    ) {
        $errors['type'] = 'Invalid type.';
    }

    if (
        $v['location'] !== '' &&
        !in_array($v['location'], opp_list_with(OPPORTUNITY_LOCATIONS, (string)($editing['Location'] ?? '')), true)
    ) {
        $errors['location'] = 'Please select a location from the list.';
    }

    /* Industry sector: from the list; "Other" means the admin types the sector. */
    $industryValue = $v['industry_sector'];

    if ($v['industry_sector'] !== '' && !in_array($v['industry_sector'], INDUSTRIES, true)) {
        $errors['industry_sector'] = 'Please select an industry sector from the list.';
    } elseif ($v['industry_sector'] === 'Other') {
        $industryValue = $v['industry_other'];

        if ($industryValue === '') {
            $errors['industry_other'] = 'This field is required.';
            $requiredEmpty = true;
        } elseif (mb_strlen($industryValue, 'UTF-8') > 50) {
            $errors['industry_other'] = 'Must not exceed 50 characters.';
        }
    }

    if (
        $v['start_date'] !== '' &&
        $v['end_date'] !== '' &&
        $v['end_date'] < $v['start_date']
    ) {
        $errors['end_date'] = 'End date must be after the start date.';
    }

    /* Timeline: Application Opens and Application Deadline are required; the other stages are optional.
       The dates must follow the order of the stages. */
    $timelineDates = [];
    foreach (TIMELINE_FIELDS as $k => $_) {
        $timelineDates[$k] = $v[$k];
    }
    if ($v['application_open_date'] === '' || $v['application_deadline'] === '') {
        $requiredEmpty = true;
    }
    $errors += opp_timeline_errors($timelineDates, $v['start_date'], ['application_open_date', 'application_deadline']);

    /* Paid needs an amount (0 = paid, price not known yet); Free is stored as 0; Unknown as NULL (the price field stays empty). */
    $price = null;

    if (!in_array($v['price_status'], ['Paid', 'Free', 'Unknown'], true)) {
        $errors['price_status'] = 'Please select a price status.';
    } elseif ($v['price_status'] === 'Paid') {
        if (
            !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v['price'])
        ) {
            $errors['price'] =
                'Enter the price when the status is Paid, or 0 if the price is not known yet.';
        } else {
            $price = number_format((float)$v['price'], 2, '.', '');
        }
    } elseif ($v['price_status'] === 'Free') {
        $price = '0.00';
    }

    /* Save only if there are no validation errors. */
    if (!$errors) {
        $pdo->beginTransaction();

        try {
            $params = [
                $v['title'],
                $v['description'],
                $v['type'],
                'External Link',
                $v['company_name'],
                $v['external_url'],
                $v['start_date'],
                $v['end_date'],
                /* Empty timeline dates are saved as NULL. */
                $v['application_open_date'] ?: null,
                $v['application_deadline'] ?: null,
                $v['screening_date'] ?: null,
                $v['interview_date'] ?: null,
                $v['acceptance_date'] ?: null,
                $v['location'],
                $industryValue,
                $v['price_status'],
                $price
            ];

            if ($editing) {
                /* Status and view count are intentionally untouched. */
                $pdo->prepare(
                    'UPDATE opportunity
                     SET Title = ?, Description = ?, Type = ?,
                         ApplicationMethod = ?, OpportunityProvider = ?,
                         ExternalURL = ?, StartDate = ?, EndDate = ?,
                         ApplicationOpenDate = ?, ApplicationDeadLine = ?,
                         ScreeningDate = ?, InterviewDate = ?, AcceptanceDate = ?,
                         Location = ?, IndustrySector = ?, PriceStatus = ?, Retail = ?
                     WHERE OpportunityID = ? AND CreatedByAdminID IS NOT NULL'
                )->execute([...$params, $editId]);

                $oppId = $editId;
            } else {
                $pdo->prepare(
                    "INSERT INTO opportunity
                     (Title, Description, Type, ApplicationMethod,
                      OpportunityProvider, ExternalURL, StartDate, EndDate,
                      ApplicationOpenDate, ApplicationDeadLine,
                      ScreeningDate, InterviewDate, AcceptanceDate,
                      Location, IndustrySector, PriceStatus, Retail,
                      Status, CreatedByAdminID)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
                )->execute([...$params, $adminId]);

                $oppId = (int)$pdo->lastInsertId();
            }

            /* Re-link skills / qualifications / experience.
               Only the link rows are replaced; the shared lookup rows stay. */
            opp_sync_links($pdo, $oppId, 'opportunityskill', 'SkillID', $skillIds);
            opp_sync_links($pdo, $oppId, 'opportunityqualification', 'QualificationID', $qualificationIds);
            opp_sync_links($pdo, $oppId, 'opportunityexperience', 'ExperienceID', $experienceIds);

            $pdo->commit();

            /* User Story 37: a new opportunity notifies the users whose skills match it. */
            if (!$editing) {
                notify_matching_users($pdo, $oppId);
            }

            header('Location: dashboard-admin.php?tab=opportunities&msg=' . ($editing ? 'updated' : 'added'));
            exit;

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Opportunity save failed: ' . $ex->getMessage());

            $errors['_form'] =
                'Something went wrong while saving. Please try again.';
        }
    }
}

function err(array $errors, string $k): string {
    return isset($errors[$k])
        ? '<div class="form-error" style="display:block;">'
            . e($errors[$k]) . '</div>'
        : '';
}

function inv(array $errors, string $k): string {
    return isset($errors[$k]) ? 'invalid' : '';
}

function rowError(
    array $errors,
    string $prefix,
    int $i,
    string $field
): string {
    return err($errors, "$prefix.$i.$field");
}

function rowInvalid(
    array $errors,
    string $prefix,
    int $i,
    string $field
): string {
    return inv($errors, "$prefix.$i.$field");
}

$extraCss = '../css/admin-opportunity-form.css';
admin_header(
    $editing ? 'Edit Opportunity' : 'Add Opportunity',
    'opportunities'
);
?>



<section class="dashboard-hero">
  <div class="container dashboard-hero-inner">
    <div>
      <div class="welcome-label"><span aria-hidden="true">✦</span><span>Ufuq Administration Space</span></div>
      <h1><?= $editing ? 'Edit Opportunity' : 'Add Opportunity' ?></h1>
      <p>Add an opportunity on behalf of a company that isn't on the platform yet.</p>
    </div>
    <div class="hero-icon" aria-hidden="true"><?php if ($editing): ?><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="M14.5 7.5l3 3"/></svg><?php else: ?><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 3.5H14l5 5V20a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 6 20V5a1.5 1.5 0 0 1 1.5-1.5Z"/><path d="M14 3.5V9h5"/><path d="M12.5 12v6M9.5 15h6"/></svg><?php endif; ?></div>
  </div>
</section>

<div class="page-content container" style="padding-top:24px;">
<div style="max-width:960px;width:100%;margin:0 auto;">
  <h2 class="opp-section-heading">Opportunity Details</h2>
  <p class="text-muted" style="margin:0 0 16px;">Fill in the details students will see.</p>

  <?php if ($errors): ?>
    <div class="form-banner" style="display:block;">
      <?= e($errors['_form'] ?? ($requiredEmpty
          ? 'Mandatory fields cannot be empty. Please fill in all required fields.'
          : 'Please review the highlighted fields and try again.')) ?>
    </div>
  <?php endif; ?>

  <form method="post" id="oppForm" class="card" novalidate>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="form-group">
      <label class="form-label icon-label" for="external_url">
        <?= icon('externalLink') ?> External URL <span class="req">*</span>
      </label>
      <input type="url" id="external_url" name="external_url"
             required
             class="form-control <?= inv($errors, 'external_url') ?>"
             placeholder="https://"
             value="<?= e($v['external_url']) ?>">
      <?= err($errors, 'external_url') ?>
    </div>

    <div class="form-row">
    <div class="form-group">
      <label class="form-label icon-label" for="company_name">
        <?= icon('company') ?> Company Name <span class="req">*</span>
      </label>
      <input type="text" id="company_name" name="company_name"
             required
             class="form-control <?= inv($errors, 'company_name') ?>"
             value="<?= e($v['company_name']) ?>">
      <?= err($errors, 'company_name') ?>
    </div>
    <div class="form-group">
      <label class="form-label" for="industry_sector">
        Industry Sector <span class="req">*</span>
      </label>
      <!-- From the INDUSTRIES list, as a drop-down list with search -->
      <select id="industry_sector" name="industry_sector" required data-search
              class="form-control <?= inv($errors, 'industry_sector') ?>">
        <option value="">Select industry sector</option>
        <?php foreach (INDUSTRIES as $sector): ?>
          <option value="<?= e($sector) ?>" <?= $v['industry_sector'] === $sector ? 'selected' : '' ?>><?= e($sector) ?></option>
        <?php endforeach; ?>
      </select>
      <?= err($errors, 'industry_sector') ?>

      <!-- Shown only when "Other" is selected (same idea as the sign-up page) -->
      <input type="text" id="industry_other" name="industry_other" maxlength="50"
             class="form-control <?= inv($errors, 'industry_other') ?>"
             placeholder="Enter the industry sector"
             style="margin-top:10px;<?= $v['industry_sector'] === 'Other' ? '' : 'display:none;' ?>"
             value="<?= e($v['industry_other']) ?>">
      <?= err($errors, 'industry_other') ?>
    </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="title">
        Title <span class="req">*</span>
      </label>
      <input type="text" id="title" name="title" required
             class="form-control <?= inv($errors, 'title') ?>"
             value="<?= e($v['title']) ?>">
      <?= err($errors, 'title') ?>
    </div>

    <!-- Description: minimum 15 characters -->
    <div class="form-group">
      <label class="form-label" for="description">
        Description <span class="req">*</span>
      </label>

      <textarea
        id="description"
        name="description"
        required
        minlength="15"
        placeholder="At least 15 characters."
        class="form-control <?= inv($errors, 'description') ?>"
        rows="4"
      ><?= e($v['description']) ?></textarea>

      <?= err($errors, 'description') ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label icon-label" for="type">
          <?= icon('training') ?> Opportunity Type <span class="req">*</span>
        </label>
        <select id="type" name="type" required
                class="form-control <?= inv($errors, 'type') ?>">
          <option value="">Select type</option>
          <?php foreach (OPPORTUNITY_TYPES as $t): ?>
            <option value="<?= e($t) ?>"
              <?= $v['type'] === $t ? 'selected' : '' ?>>
              <?= e($t) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?= err($errors, 'type') ?>
      </div>

      <div class="form-group">
        <label class="form-label icon-label" for="location">
          <?= icon('location') ?> Location <span class="req">*</span>
        </label>

        <!-- Drop-down list with search -->
        <select id="location" name="location" required data-search
                class="form-control <?= inv($errors, 'location') ?>">
          <option value="">Select location</option>
          <?php foreach (opp_list_with(OPPORTUNITY_LOCATIONS, (string)($editing['Location'] ?? '')) as $c): ?>
            <option value="<?= e($c) ?>"
              <?= $v['location'] === $c ? 'selected' : '' ?>>
              <?= e($c) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?= err($errors, 'location') ?>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label icon-label">
        <?= icon('goal') ?> Required Skills <span class="req">*</span>
      </label>

      <?= lookup_picker('skills', 'Select skills', $skillOptions, $skillIds, isset($errors['skills']), true, '') ?>
      <?= err($errors, 'skills') ?>
    </div>

    <!-- Qualifications -->
    <div class="form-group">
      <label class="form-label icon-label">
        <?= icon('goal') ?> Qualifications
      </label>

      <?= lookup_picker('qualifications', 'Select qualifications', $qualificationOptions, $qualificationIds, isset($errors['qualifications']), false, '') ?>
      <?= err($errors, 'qualifications') ?>
    </div>

    <!-- Experience -->
    <div class="form-group">
      <label class="form-label icon-label">
        <?= icon('form') ?> Experience
      </label>

      <?= lookup_picker('experience', 'Select experience', $experienceOptions, $experienceIds, isset($errors['experience']), false, '') ?>
      <?= err($errors, 'experience') ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label icon-label" for="price_status">
          <?= icon('goal') ?> Price Status
        </label>
        <select id="price_status" name="price_status"
                class="form-control <?= inv($errors, 'price_status') ?>">
          <?php foreach (['Paid', 'Free', 'Unknown'] as $ps): ?>
            <option value="<?= e($ps) ?>"
              <?= $v['price_status'] === $ps ? 'selected' : '' ?>>
              <?= e($ps) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">If the opportunity is paid but the price is not known, enter 0 as the price.</div>
        <?= err($errors, 'price_status') ?>
      </div>

      <div class="form-group" id="priceGroup">
        <label class="form-label icon-label" for="price">
          <?= icon('goal') ?> Price (SAR) <span class="req" id="priceRequiredMark" style="<?= $v['price_status'] === 'Paid' ? '' : 'display:none;' ?>">*</span>
        </label>
        <input type="number" id="price" name="price" min="0" step="0.01"
               inputmode="decimal" placeholder="0"
               <?= $v['price_status'] === 'Paid' ? '' : 'disabled' ?>
               class="form-control <?= inv($errors, 'price') ?>"
               value="<?= e($v['price']) ?>">
        <?= err($errors, 'price') ?>
      </div>
    </div>

    <div class="form-row form-row-3">
      <div class="form-group">
        <label class="form-label icon-label" for="start_date">
          <?= icon('calendar') ?> Start Date <span class="req">*</span>
        </label>

        <input type="date" id="start_date" name="start_date" required
               class="form-control <?= inv($errors, 'start_date') ?>"
               value="<?= e($v['start_date']) ?>">

        <?= err($errors, 'start_date') ?>
      </div>

      <div class="form-group">
        <label class="form-label icon-label" for="end_date">
          <?= icon('calendar') ?> End Date <span class="req">*</span>
        </label>

        <input type="date" id="end_date" name="end_date" required
               class="form-control <?= inv($errors, 'end_date') ?>"
               value="<?= e($v['end_date']) ?>">

        <?= err($errors, 'end_date') ?>
      </div>

      <div class="form-group">
        <label class="form-label icon-label" for="duration">
          <?= icon('clock') ?> Duration
        </label>

        <input type="text" id="duration" class="form-control"
               placeholder="—" readonly>

        <div class="form-hint">
          Calculated automatically.
        </div>
      </div>
    </div>

    <!-- Timeline (optional), in stage order -->
    <div class="form-group" id="timelineGroup" style="margin-bottom:0;">
      <label class="form-label icon-label">
        <?= icon('clock') ?> Timeline
      </label>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="application_open_date">Application Opens <span class="req">*</span></label>
          <input type="date" id="application_open_date" name="application_open_date"
                 class="form-control <?= inv($errors, 'application_open_date') ?>"
                 value="<?= e($v['application_open_date']) ?>">
          <?= err($errors, 'application_open_date') ?>
        </div>

        <div class="form-group">
          <label class="form-label icon-label" for="application_deadline">
            <?= icon('calendar') ?> Application Deadline <span class="req">*</span>
          </label>

          <input type="date" id="application_deadline"
                 name="application_deadline"
                 class="form-control <?= inv($errors, 'application_deadline') ?>"
                 value="<?= e($v['application_deadline']) ?>">

          <?= err($errors, 'application_deadline') ?>
        </div>
      </div>

      <div class="form-row form-row-3">
        <div class="form-group">
          <label class="form-label" for="screening_date">Initial Screening</label>
          <input type="date" id="screening_date" name="screening_date"
                 class="form-control <?= inv($errors, 'screening_date') ?>"
                 value="<?= e($v['screening_date']) ?>">
          <?= err($errors, 'screening_date') ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="interview_date">Interview</label>
          <input type="date" id="interview_date" name="interview_date"
                 class="form-control <?= inv($errors, 'interview_date') ?>"
                 value="<?= e($v['interview_date']) ?>">
          <?= err($errors, 'interview_date') ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="acceptance_date">Acceptance</label>
          <input type="date" id="acceptance_date" name="acceptance_date"
                 class="form-control <?= inv($errors, 'acceptance_date') ?>"
                 value="<?= e($v['acceptance_date']) ?>">
          <?= err($errors, 'acceptance_date') ?>
        </div>
      </div>
    </div>

    <div class="flex-between" style="margin-top:24px;">
      <a href="dashboard-admin.php?tab=opportunities"
         class="btn btn-ghost">Cancel</a>

      <button type="submit" class="btn btn-primary">
        <?= $editing ? 'Save Changes' : 'Publish' ?>
      </button>
    </div>
  </form>
</div>
</div>

<script>
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

  /* One-choice drop-down with search: <select data-search> (Industry Sector, Location).
     The real <select> stays in the form (hidden), so saving and the checks below work the same. */
  document.querySelectorAll('select[data-search]').forEach(function (select) {
    const box = document.createElement('div');
    box.className = 'lookup-picker search-single';
    box.innerHTML =
      '<button type="button" class="form-control lookup-toggle" aria-haspopup="listbox" aria-expanded="false">' +
        '<span class="lookup-toggle-text"></span><span class="lookup-caret" aria-hidden="true">&#9662;</span>' +
      '</button>' +
      '<div class="lookup-panel" hidden>' +
        '<input type="text" class="form-control lookup-search" placeholder="Search..." autocomplete="off">' +
        '<div class="lookup-list" role="listbox"></div>' +
        '<div class="lookup-empty" hidden>No matches found</div>' +
      '</div>';
    select.insertAdjacentElement('beforebegin', box);
    select.style.display = 'none';

    const toggle = box.querySelector('.lookup-toggle');
    const text = box.querySelector('.lookup-toggle-text');
    const panel = box.querySelector('.lookup-panel');
    const search = box.querySelector('.lookup-search');
    const list = box.querySelector('.lookup-list');
    const empty = box.querySelector('.lookup-empty');
    const placeholder = (select.options[0] && select.options[0].value === '') ? select.options[0].textContent : 'Select...';

    /* The label now points to the drop-down button. */
    toggle.id = select.id + 'Toggle';
    const label = document.querySelector('label[for="' + select.id + '"]');
    if (label) label.htmlFor = toggle.id;

    Array.from(select.options).forEach(function (opt) {
      if (opt.value === '') return;
      const row = document.createElement('div');
      row.className = 'lookup-option';
      row.setAttribute('role', 'option');
      row.dataset.value = opt.value;
      row.dataset.text = opt.textContent.trim().toLowerCase();
      row.textContent = opt.textContent.trim();
      row.addEventListener('click', function () {
        select.value = opt.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        toggle.focus();
      });
      list.appendChild(row);
    });

    function refresh() {
      const chosen = select.value !== '' ? select.options[select.selectedIndex].textContent.trim() : '';
      text.textContent = chosen || placeholder;
      text.classList.toggle('is-placeholder', chosen === '');
      list.querySelectorAll('.lookup-option').forEach(function (row) {
        row.classList.toggle('is-selected', row.dataset.value === select.value);
      });
      if (chosen) {
        select.classList.remove('invalid');
        const err = select.nextElementSibling;
        if (err && err.classList.contains('form-error')) err.remove();
      }
    }

    function filter() {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      list.querySelectorAll('.lookup-option').forEach(function (row) {
        const match = row.dataset.text.indexOf(q) !== -1;
        row.hidden = !match;
        if (match) shown++;
      });
      empty.hidden = shown !== 0;
    }

    function close() {
      panel.hidden = true;
      box.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      const willOpen = panel.hidden;
      document.querySelectorAll('.lookup-picker .lookup-panel').forEach(function (p) {
        if (p !== panel) { p.hidden = true; p.closest('.lookup-picker').classList.remove('is-open'); }
      });
      if (!willOpen) { close(); return; }
      panel.hidden = false;
      box.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      search.value = '';
      filter();
      search.focus();
    });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = list.querySelector('.lookup-option:not([hidden])');
        if (first) first.click();
      }
      if (e.key === 'Escape') { close(); toggle.focus(); }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) close(); });

    /* Red border on the button when the hidden <select> is marked invalid by the checks. */
    new MutationObserver(function () {
      toggle.classList.toggle('invalid', select.classList.contains('invalid'));
    }).observe(select, { attributes: true, attributeFilter: ['class'] });
    toggle.classList.toggle('invalid', select.classList.contains('invalid'));
    select._searchToggle = toggle;

    select.addEventListener('change', refresh);
    refresh();
  });

  /* Industry Sector "Other": show the text field to type the sector. */
  const industrySelect = document.getElementById("industry_sector");
  const industryOther = document.getElementById("industry_other");

  function toggleIndustryOther() {
    const isOther = industrySelect.value === "Other";
    industryOther.style.display = isOther ? "" : "none";
    if (!isOther) {
      industryOther.classList.remove("invalid");
      const err = industryOther.nextElementSibling;
      if (err && err.classList.contains("form-error")) err.remove();
    } else if (industryOther.value === "") {
      industryOther.focus();
    }
  }

  industrySelect.addEventListener("change", toggleIndustryOther);
  industryOther.addEventListener("input", () => {
    if (industryOther.value.trim() !== "") {
      industryOther.classList.remove("invalid");
      const err = industryOther.nextElementSibling;
      if (err && err.classList.contains("form-error")) err.remove();
    }
  });

  /* Duration display. */
  const sd = document.getElementById("start_date");
  const ed = document.getElementById("end_date");
  const du = document.getElementById("duration");

  function updateDuration() {
    const start = new Date(sd.value);
    const end = new Date(ed.value);
    const days = Math.round((end - start) / 86400000);

    if (!sd.value || !ed.value || isNaN(days) || days < 0) {
      du.value = "";
      return;
    }

    du.value = `${days} day${days === 1 ? "" : "s"}`;
  }

  sd.addEventListener("input", updateDuration);
  ed.addEventListener("input", updateDuration);
  updateDuration();

  /* Price: Paid = price required (0 = price not known yet); Free = 0; Unknown = empty. Free and Unknown lock the field (grey). */
  const priceStatus = document.getElementById("price_status");
  const priceGroup = document.getElementById("priceGroup");
  const priceField = document.getElementById("price");
  const priceMark = document.getElementById("priceRequiredMark");

  function syncPrice(changed) {
    const st = priceStatus.value;
    priceField.disabled = st !== "Paid";
    priceMark.style.display = st === "Paid" ? "" : "none";

    if (st === "Free") {
      priceField.value = "0";
    } else if (st === "Unknown") {
      priceField.value = "";
    } else if (changed && priceField.value === "0") {
      priceField.value = "";
    }

    if (st !== "Paid") {
      priceField.classList.remove("invalid");
      const pe = priceGroup.querySelector(".form-error");
      if (pe) pe.remove();
    }
  }

  priceStatus.addEventListener("change", () => syncPrice(true));
  syncPrice(false);

  /* Timeline dates, in stage order: [element id, label]. Same rules as the server:
     open <= deadline <= screening <= interview <= acceptance <= start date. */
  const TL_FIELDS = [
    ['application_open_date', 'Application opening'],
    ['application_deadline', 'Application deadline'],
    ['screening_date', 'Initial screening'],
    ['interview_date', 'Interview'],
    ['acceptance_date', 'Acceptance']
  ];

  /* Returns {elementId: message}. `required` lists the ids that must not be empty. */
  function timelineErrors(fields, required, start) {
    const val = i => document.getElementById(fields[i][0]).value;
    const errs = {};
    fields.forEach(([id], i) => { if (!val(i) && required.includes(id)) errs[id] = 'This field is required.'; });
    const has = i => val(i) !== '' && !errs[fields[i][0]];
    const openId = fields[0][0], deadlineId = fields[1][0];

    if (has(1) && start && val(1) > start) errs[deadlineId] = 'Application deadline should be on or before the start date.';
    if (has(0)) {
      if (has(1) && val(0) > val(1)) errs[openId] = 'Applications must open on or before the application deadline.';
      else if (start && val(0) > start) errs[openId] = 'Applications must open on or before the start date.';
    }

    let prev = -1;
    for (let i = 2; i < fields.length; i++) {
      if (!has(i)) continue;
      const [id, label] = fields[i];
      if (has(1) && val(i) < val(1)) errs[id] = label + ' must be on or after the application deadline.';
      else if (start && val(i) > start) errs[id] = label + ' must be on or before the start date.';
      else if (prev >= 0 && val(i) < val(prev)) errs[id] = label + ' must be on or after the ' + fields[prev][1].toLowerCase() + '.';
      if (!errs[id]) prev = i;
    }
    return errs;
  }

  /* Client-side checks before submitting. */
  document.getElementById("oppForm").addEventListener("submit", event => {
    let firstInvalid = null;

    /* Mandatory fields must not be empty (checked in page order). */
    let emptyRequired = false;

    const setError = (el, message) => {
      el.classList.add("invalid");

      let error = el.parentElement.querySelector(".form-error");

      if (!error) {
        error = document.createElement("div");
        error.className = "form-error";
        el.insertAdjacentElement("afterend", error);
      }

      error.style.display = "block";
      error.textContent = message;

      if (!firstInvalid) {
        firstInvalid = el;
      }
    };

    const clearError = el => {
      el.classList.remove("invalid");

      const error = el.parentElement.querySelector(".form-error");

      if (error) {
        error.remove();
      }
    };

    [
      "external_url", "company_name", "industry_sector", "title",
      "description", "type", "location",
      "start_date", "end_date"
    ].forEach(id => {
      const el = document.getElementById(id);
      const value = el.value.trim();

      if (value === "") {
        emptyRequired = true;
        setError(el, "This field is required.");
      } else if (id === "description" && value.length < 15) {
        setError(el, "Description must be at least 15 characters long.");
      } else if (id === "external_url") {
        let ok = false;

        try {
          ok = ["http:", "https:"].includes(new URL(value).protocol);
        } catch (_e) {}

        if (ok) {
          clearError(el);
        } else {
          setError(el, "Please enter a valid URL starting with http:// or https://.");
        }
      } else {
        clearError(el);
      }
    });

    if (industrySelect.value === "Other" && industryOther.value.trim() === "") {
      emptyRequired = true;
      industryOther.classList.add("invalid");
      let otherErr = industryOther.nextElementSibling;
      if (!otherErr || !otherErr.classList.contains("form-error")) {
        otherErr = document.createElement("div");
        otherErr.className = "form-error";
        industryOther.insertAdjacentElement("afterend", otherErr);
      }
      otherErr.style.display = "block";
      otherErr.textContent = "This field is required.";
      if (!firstInvalid) firstInvalid = industryOther;
    }

    const endEl = document.getElementById("end_date");

    if (
      sd.value && ed.value && ed.value < sd.value &&
      !endEl.classList.contains("invalid")
    ) {
      setError(endEl, "End date must be after the start date.");
    }

    /* At least one skill must be selected. */
    const skillsPicker = document.querySelector("[data-lookup-picker][data-required]");
    if (skillsPicker && !skillsPicker.querySelector("input:checked")) {
      emptyRequired = true;
      skillsPicker.querySelector(".lookup-toggle").classList.add("invalid");
      if (!firstInvalid) {
        firstInvalid = skillsPicker.querySelector(".lookup-toggle");
      }
    }

    /* Paid requires a price (0 is allowed: paid, price not known yet). */
    const priceInput = document.getElementById("price");
    const priceValue = priceInput.value.trim();
    const priceBad = priceStatus.value === "Paid" &&
      !/^\d{1,8}(\.\d{1,2})?$/.test(priceValue);

    let priceError = priceGroup.querySelector(".form-error");

    if (priceBad) {
      priceInput.classList.add("invalid");
      if (!firstInvalid) {
        firstInvalid = priceInput;
      }
      if (!priceError) {
        priceError = document.createElement("div");
        priceError.className = "form-error";
        priceInput.insertAdjacentElement("afterend", priceError);
      }
      priceError.style.display = "block";
      priceError.textContent =
        "Enter the price when the status is Paid, or 0 if the price is not known yet.";
    } else {
      priceInput.classList.remove("invalid");
      if (priceError) {
        priceError.remove();
      }
    }

    /* Timeline: Application Opens and Application Deadline are required; the other stages are optional. The dates must be in order. */
    const tlRequired = ["application_open_date", "application_deadline"];
    const tlErrs = timelineErrors(TL_FIELDS, tlRequired, sd.value);
    if (tlRequired.some(id => document.getElementById(id).value === "")) {
      emptyRequired = true;
    }
    TL_FIELDS.forEach(([id]) => {
      const el = document.getElementById(id);
      if (tlErrs[id]) {
        setError(el, tlErrs[id]);
      } else {
        clearError(el);
      }
    });

    /* Banner, same wording as the server-side one. */
    if (emptyRequired) {
      let banner = document.querySelector(".form-banner");

      if (!banner) {
        banner = document.createElement("div");
        banner.className = "form-banner";
        document.getElementById("oppForm").insertAdjacentElement("beforebegin", banner);
      }

      banner.style.display = "block";
      banner.textContent =
        "Mandatory fields cannot be empty. Please fill in all required fields.";
    }

    if (firstInvalid) {
      event.preventDefault();
      (firstInvalid._searchToggle || firstInvalid).focus();
    }
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>