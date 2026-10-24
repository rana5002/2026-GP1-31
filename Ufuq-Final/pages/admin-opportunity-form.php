<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

/* Existing sectors, offered as suggestions for the Industry Sector field. */
try {
    $sectorOptions = $pdo->query(
        'SELECT IndustrySector FROM company
         UNION
         SELECT IndustrySector FROM opportunity WHERE IndustrySector IS NOT NULL
         ORDER BY 1'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ignored) {
    $sectorOptions = [];
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
        !in_array($v['location'], OPPORTUNITY_LOCATIONS, true)
    ) {
        $errors['location'] = 'Invalid location.';
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
                $v['industry_sector'],
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

admin_header(
    $editing ? 'Edit Opportunity' : 'Add Opportunity',
    'opportunities'
);
?>

<style>
  :root {
    --admin-purple: #39265f;
    --admin-purple-dark: #281a46;
    --admin-purple-deep: #211638;
    --admin-purple-light: #f4f0fa;
    --admin-purple-border: #ddd2ee;
    --admin-gold: #e2a94b;
    --admin-gold-dark: #c48b2c;
    --entry-error: #d94b56;
    --entry-error-text: #c43643;
    --entry-border: #e5dfed;
    --entry-muted: #777184;
    --entry-surface: #ffffff;
    --entry-background: #f8f6fb;
  }

  .page-content.container {
    max-width: 1000px !important;
    width: 100%;
    margin: 0 auto;
    padding: 28px 22px 42px;
    box-sizing: border-box;
  }

  .page-content h2 {
    color: var(--admin-purple-dark);
    font-size: clamp(1.65rem, 3vw, 2.1rem);
    font-weight: 800;
    letter-spacing: -0.035em;
    line-height: 1.25;
    margin: 0 0 8px;
  }

  .page-content > p.text-muted {
    color: var(--entry-muted);
    font-size: .96rem;
    line-height: 1.7;
    margin-bottom: 26px !important;
  }

  #oppForm.card {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: clamp(20px, 3.5vw, 30px);
    background: var(--entry-surface);
    border: 1px solid #e9e3ed;
    border-radius: 16px;
    box-shadow: 0 5px 18px rgba(40, 26, 70, .045);
    overflow: visible;
  }

  #oppForm .form-group {
    min-width: 0;
    margin-bottom: 21px;
  }

  #oppForm .form-label {
    display: block;
    margin-bottom: 8px;
    color: #35264f;
    font-size: .92rem;
    font-weight: 700;
    line-height: 1.5;
  }

  #oppForm .icon-label {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
  }

  #oppForm .icon-label svg,
  #oppForm .icon-label i {
    color: var(--admin-purple);
  }

  #oppForm .req {
    display: inline;
    color: var(--entry-error);
    font-weight: 800;
    margin-left: 2px;
  }

  #oppForm .form-control {
    display: block;
    width: 100%;
    min-width: 0;
    min-height: 46px;
    box-sizing: border-box;
    padding: 11px 13px;
    border: 1px solid #dcd5e8;
    border-radius: 10px;
    outline: none;
    background: #fff;
    color: #2c233b;
    font: inherit;
    font-size: .94rem;
    line-height: 1.5;
    box-shadow: 0 1px 2px rgba(40, 26, 70, .025);
    transition:
      border-color .18s ease,
      box-shadow .18s ease,
      background-color .18s ease;
  }

  #oppForm textarea.form-control {
    min-height: 125px;
    resize: vertical;
  }

  #oppForm .form-control::placeholder {
    color: #a19aaa;
    opacity: 1;
  }

  #oppForm .form-control:hover {
    border-color: #b9a8d3;
  }

  #oppForm .form-control:focus {
    border-color: var(--admin-purple) !important;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(57, 38, 95, .11);
  }

  #oppForm select.form-control {
    cursor: pointer;
  }

  #oppForm .form-control[readonly] {
    color: #675b78;
    background: #f4f1f8;
    border-color: #e3dcec;
    cursor: default;
  }

  /* Timeline rows and locked (grey) price field */
  #oppForm .timeline-rows{display:flex;flex-direction:column;gap:10px;margin-bottom:10px}
  #oppForm .timeline-row{display:grid;grid-template-columns:minmax(0,1fr) 190px 46px;gap:10px;align-items:center}
  #oppForm .timeline-remove{min-width:0!important;width:46px;padding:0!important;font-size:1.3rem;line-height:1}
  @media(max-width:700px){#oppForm .timeline-row{grid-template-columns:minmax(0,1fr) 46px}#oppForm .timeline-row input[type=text]{grid-column:1 / -1}}
  #oppForm .form-control:disabled{color:#8a8a93;background:#efeff1;border-color:#dddde2;cursor:not-allowed;-webkit-text-fill-color:#8a8a93;opacity:1}

  #oppForm .form-control.invalid {
    border: 1.5px solid var(--entry-error) !important;
    background-color: #fff7f7 !important;
    box-shadow: 0 0 0 3px rgba(217, 75, 86, .08);
  }

  #oppForm .form-error {
    display: block;
    color: var(--entry-error-text);
    font-size: .84rem;
    line-height: 1.5;
    margin-top: 7px;
  }

  #oppForm .form-hint {
    margin-top: 7px;
    color: var(--entry-muted);
    font-size: .82rem;
    line-height: 1.5;
  }

  #oppForm .form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    align-items: start;
  }

  #oppForm .form-row.form-row-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  #oppForm .flex.gap-2 {
    display: flex;
    align-items: stretch;
    gap: 10px;
  }

  #oppForm #skillInput {
    flex: 1;
  }

  #oppForm .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 43px;
    padding: 10px 17px;
    border: 1px solid transparent;
    border-radius: 10px;
    font-size: .91rem;
    font-weight: 700;
    line-height: 1.3;
    text-decoration: none;
    cursor: pointer;
    transition:
      background-color .18s ease,
      border-color .18s ease,
      color .18s ease,
      box-shadow .18s ease,
      transform .18s ease;
  }

  #oppForm .btn:hover {
    transform: translateY(-1px);
  }

  #oppForm .btn-primary {
    color: #fff;
    background: var(--admin-purple);
    border-color: var(--admin-purple);
    box-shadow: 0 5px 13px rgba(57, 38, 95, .18);
  }

  #oppForm .btn-primary:hover {
    color: #fff;
    background: var(--admin-purple-dark);
    border-color: var(--admin-purple-dark);
    box-shadow: 0 7px 17px rgba(40, 26, 70, .22);
  }

  #oppForm .btn-outline {
    flex-shrink: 0;
    color: var(--admin-purple);
    background: #fff;
    border-color: #cfc1e3;
  }

  #oppForm .btn-outline:hover {
    color: var(--admin-purple-dark);
    background: var(--admin-purple-light);
    border-color: #ad98ce;
  }

  #oppForm .btn-ghost {
    color: #5e536d;
    background: #fff;
    border-color: #ded7e8;
  }

  #oppForm .btn-ghost:hover {
    color: var(--admin-purple);
    background: var(--admin-purple-light);
    border-color: #cfc1e3;
  }

  #oppForm .flex-between {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 21px;
    border-top: 1px solid #eee8f4;
  }

  #oppForm .flex-between .btn {
    min-width: 125px;
  }

  #skillList {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    min-height: 10px;
  }

  #skillList .badge {
    display: inline-flex !important;
    align-items: center;
    gap: 8px !important;
    margin: 0 !important;
    padding: 7px 11px;
    border: 1px solid #d9cbea;
    border-radius: 999px;
    background: #f2ecfa;
    color: var(--admin-purple-dark);
    font-size: .84rem;
    font-weight: 700;
  }

  #skillList .badge button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 19px;
    height: 19px;
    border-radius: 50%;
    color: var(--admin-purple);
    font-size: 1rem;
    line-height: 1;
  }

  #skillList .badge button:hover {
    color: var(--entry-error);
    background: rgba(217, 75, 86, .09);
  }

  #skillList .text-muted {
    color: var(--entry-muted);
    font-size: .85rem;
  }

  .entry-section {
    margin-top: 30px;
    padding-top: 25px;
    border-top: 1px solid #eee8f4;
  }

  .entry-section-title {
    margin-bottom: 6px;
    color: var(--admin-purple-dark);
    font-size: 1.04rem !important;
    font-weight: 800 !important;
  }

  .entry-section-hint {
    color: var(--entry-muted);
    font-size: .87rem;
    line-height: 1.6;
    margin: 0 0 15px;
  }

  .entry-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .entry-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr)) 38px;
    gap: 12px;
    align-items: end;
    padding: 18px;
    border: 1px solid #e5ddef;
    border-radius: 14px;
    background: #faf8fd;
    transition:
      border-color .18s ease,
      box-shadow .18s ease,
      background-color .18s ease;
  }

  .entry-row:hover {
    border-color: #d0c0e4;
    box-shadow: 0 4px 14px rgba(40, 26, 70, .045);
  }

  .entry-row .form-group {
    min-width: 0;
    margin-bottom: 0 !important;
  }

  .entry-row .form-label {
    display: block;
    margin-bottom: 7px !important;
    color: #49395f !important;
    font-size: .84rem !important;
    font-weight: 700 !important;
  }

  .entry-row .form-control {
    width: 100%;
    min-width: 0;
    min-height: 42px !important;
    padding: 9px 10px !important;
    border-radius: 8px !important;
    font-size: .88rem !important;
  }

  .entry-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 40px;
    padding: 0;
    border: 1px solid #efc4c7;
    border-radius: 9px;
    color: #c6404a;
    background: #fff6f6;
    font-size: 23px;
    line-height: 1;
    cursor: pointer;
    transition:
      background-color .18s ease,
      border-color .18s ease,
      transform .18s ease;
  }

  .entry-remove:hover {
    background: #ffe7e8;
    border-color: #e7a5aa;
    transform: translateY(-1px);
  }

  .add-entry {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 45px;
    margin-top: 13px;
    padding: 11px 14px;
    border: 1px dashed #b9a5d5;
    border-radius: 10px;
    background: #fbf9fe;
    color: var(--admin-purple);
    font: inherit;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    transition:
      background-color .18s ease,
      border-color .18s ease,
      color .18s ease;
  }

  .add-entry:hover {
    background: #f0e9f9;
    border-color: var(--admin-purple);
    color: var(--admin-purple-dark);
  }

  #oppForm > .form-error {
    padding: 13px 15px;
    border: 1px solid #f1c8cb;
    border-radius: 10px;
    background: #fff3f3;
    color: #b82f3b;
    line-height: 1.6;
  }

  @media (max-width: 1000px) {
    .entry-row {
      grid-template-columns: repeat(2, minmax(0, 1fr)) 38px;
    }

    .entry-row .entry-remove {
      grid-column: 3;
      grid-row: 1;
    }

    #oppForm .form-row.form-row-3 {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 700px) {
    .page-content.container {
      padding: 20px 14px 32px;
    }

    #oppForm.card {
      padding: 20px 16px;
      border-radius: 15px;
    }

    #oppForm .form-row,
    #oppForm .form-row.form-row-3 {
      grid-template-columns: minmax(0, 1fr);
      gap: 0;
    }

    .entry-row {
      grid-template-columns: minmax(0, 1fr) 36px;
      gap: 12px 10px;
      padding: 14px;
    }

    .entry-row .form-group {
      grid-column: 1;
    }

    .entry-row .entry-remove {
      grid-column: 2;
      grid-row: 1;
      align-self: start;
    }

    #oppForm .flex.gap-2 {
      gap: 8px;
    }

    #oppForm .flex-between {
      align-items: stretch;
    }

    #oppForm .flex-between .btn {
      flex: 1;
    }
  }

  @media (max-width: 420px) {
    #oppForm .flex.gap-2 {
      flex-direction: column;
    }

    #oppForm .btn-outline {
      width: 100%;
    }

    #oppForm .flex-between .btn {
      min-width: 0;
      padding-inline: 12px;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    #oppForm *,
    .entry-row,
    .entry-remove,
    .add-entry {
      transition: none !important;
      scroll-behavior: auto !important;
    }
  }

  .entry-row.entry-row-single {
    grid-template-columns: minmax(0, 1fr) 38px;
  }

  .entry-row.entry-row-single .entry-remove {
    grid-column: 2;
    grid-row: 1;
  }
  /* Searchable dropdowns (same look as the company page). */
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
  #oppForm .lookup-option:hover{background:var(--admin-purple-light)}
  #oppForm .lookup-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border:1px solid #d9cbea;border-radius:999px;background:#f2ecfa;color:var(--admin-purple-dark);font-size:.84rem;font-weight:700}
  #oppForm .lookup-chip button{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;padding:0;border:0;border-radius:50%;background:none;color:var(--admin-purple);font-size:1rem;line-height:1;cursor:pointer}
  #oppForm .lookup-chip button:hover{color:var(--entry-error);background:rgba(217,75,86,.09)}
  /* Hero + heading + banner: same look as the company page. */
  .form-banner{padding:12px 16px;border-radius:8px;margin-bottom:16px;background:#fbe6e7;color:#b4232f}
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
  .form-banner{border:1px solid #f1c8cb;background:#fff3f3!important;color:#b82f3b!important;border-radius:10px!important;line-height:1.6}
  .page-content h2.opp-section-heading{margin:0 0 7px;color:#29243a;font-size:1.18rem;font-weight:750;letter-spacing:normal;line-height:1.5}
</style>

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
      <input type="text" id="industry_sector" name="industry_sector"
             required maxlength="50" list="sectorOptions"
             class="form-control <?= inv($errors, 'industry_sector') ?>"
             value="<?= e($v['industry_sector']) ?>">
      <datalist id="sectorOptions">
        <?php foreach ($sectorOptions as $sector): ?>
          <option value="<?= e($sector) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <?= err($errors, 'industry_sector') ?>
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

        <select id="location" name="location" required
                class="form-control <?= inv($errors, 'location') ?>">
          <option value="">Select Location</option>
          <?php foreach (OPPORTUNITY_LOCATIONS as $c): ?>
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
      firstInvalid.focus();
    }
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
