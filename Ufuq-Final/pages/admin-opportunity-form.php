<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

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
    'external_url' => '',
    'title' => '',
    'description' => '',
    'type' => '',
    'location' => '',
    'start_date' => '',
    'end_date' => '',
    'application_deadline' => ''
];

$skills = [];
$qualificationRows = [];
$experienceRows = [];
$errors = [];

if ($editing) {
    $v = [
        'company_name' => $editing['OpportunityProvider'] ?? '',
        'external_url' => $editing['ExternalURL'] ?? '',
        'title' => $editing['Title'] ?? '',
        'description' => $editing['Description'] ?? '',
        'type' => $editing['Type'] ?? '',
        'location' => $editing['Location'] ?? '',
        'start_date' => $editing['StartDate'] ?? '',
        'end_date' => $editing['EndDate'] ?? '',
        'application_deadline' => $editing['ApplicationDeadLine'] ?? ''
    ];

    $s = $pdo->prepare(
        'SELECT s.SkillName
         FROM opportunityskill os
         JOIN skill s ON s.SkillID = os.SkillID
         WHERE os.OpportunityID = ?'
    );
    $s->execute([$editId]);
    $skills = $s->fetchAll(PDO::FETCH_COLUMN);

    $q = $pdo->prepare(
        'SELECT q.QualificationID, q.FieldOfStudy, q.DegreeLevel,
                q.YearObtained, q.Duration
         FROM opportunityqualification oq
         JOIN qualification q
           ON q.QualificationID = oq.QualificationID
         WHERE oq.OpportunityID = ?'
    );
    $q->execute([$editId]);
    $qualificationRows = $q->fetchAll(PDO::FETCH_ASSOC);

    $x = $pdo->prepare(
        'SELECT ex.ExperienceID, ex.JobTitle, ex.Organization,
                ex.StartDate, ex.EndDate
         FROM opportunityexperience ox
         JOIN experience ex
           ON ex.ExperienceID = ox.ExperienceID
         WHERE ox.OpportunityID = ?'
    );
    $x->execute([$editId]);
    $experienceRows = $x->fetchAll(PDO::FETCH_ASSOC);
}

/* Load existing qualification and experience records if none are linked. */
if (!$qualificationRows) {
    $qualificationRows = $pdo->query(
        'SELECT QualificationID, FieldOfStudy, DegreeLevel,
                YearObtained, Duration
         FROM qualification
         ORDER BY QualificationID DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!$experienceRows) {
    $experienceRows = $pdo->query(
        'SELECT ExperienceID, JobTitle, Organization, StartDate, EndDate
         FROM experience
         ORDER BY ExperienceID DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/* Ensure each section has at least one row. */
if (!$qualificationRows) {
    $qualificationRows = [[
        'QualificationID' => '',
        'FieldOfStudy' => '',
        'DegreeLevel' => '',
        'YearObtained' => '',
        'Duration' => ''
    ]];
}

if (!$experienceRows) {
    $experienceRows = [[
        'ExperienceID' => '',
        'JobTitle' => '',
        'Organization' => '',
        'StartDate' => '',
        'EndDate' => ''
    ]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    foreach ($v as $k => $_) {
        $v[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $decodedSkills = json_decode($_POST['skills'] ?? '[]', true);

    $skills = array_values(array_unique(array_filter(
        array_map(
            'trim',
            is_array($decodedSkills) ? $decodedSkills : []
        )
    )));

    /* Read qualification rows submitted by the form. */
    $qualificationRows = [];

    foreach ((array)($_POST['qualifications'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $qualificationRows[] = [
            'QualificationID' => (int)($row['id'] ?? 0),
            'FieldOfStudy' => trim((string)($row['field_of_study'] ?? '')),
            'DegreeLevel' => trim((string)($row['degree_level'] ?? '')),
            'YearObtained' => trim((string)($row['year_obtained'] ?? '')),
            'Duration' => trim((string)($row['duration'] ?? ''))
        ];
    }

    /* Read experience rows submitted by the form. */
    $experienceRows = [];

    foreach ((array)($_POST['experience'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $experienceRows[] = [
            'ExperienceID' => (int)($row['id'] ?? 0),
            'JobTitle' => trim((string)($row['job_title'] ?? '')),
            'Organization' => trim((string)($row['organization'] ?? '')),
            'StartDate' => trim((string)($row['start_date'] ?? '')),
            'EndDate' => trim((string)($row['end_date'] ?? ''))
        ];
    }

    /* Keep one blank row if the user removes all rows. */
    if (!$qualificationRows) {
        $qualificationRows = [[
            'QualificationID' => 0,
            'FieldOfStudy' => '',
            'DegreeLevel' => '',
            'YearObtained' => '',
            'Duration' => ''
        ]];
    }

    if (!$experienceRows) {
        $experienceRows = [[
            'ExperienceID' => 0,
            'JobTitle' => '',
            'Organization' => '',
            'StartDate' => '',
            'EndDate' => ''
        ]];
    }

    /* Validate required opportunity fields. */
    foreach ([
        'company_name',
        'title',
        'description',
        'type',
        'location',
        'start_date',
        'end_date',
        'application_deadline',
        'external_url'
    ] as $k) {
        if ($v[$k] === '') {
            $errors[$k] = 'This field is required.';
        }
    }

    /* DESCRIPTION: minimum 15 characters. */
    if (
        $v['description'] !== '' &&
        mb_strlen($v['description'], 'UTF-8') < 15
    ) {
        $errors['description'] =
            'Description must be at least 15 characters long.';
    }

    if (!$skills) {
        $errors['skills'] = 'Add at least one required skill.';
    }

    if (
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
        !in_array($v['location'], CITIES, true)
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

    /* Validate qualification rows. */
    foreach ($qualificationRows as $i => $row) {
        $fields = [
            'field_of_study' => $row['FieldOfStudy'],
            'degree_level' => $row['DegreeLevel'],
            'year_obtained' => $row['YearObtained'],
            'duration' => $row['Duration']
        ];

        $started = count(array_filter(
            $fields,
            fn($value) => $value !== ''
        )) > 0;

        if (!$started) {
            continue;
        }

        foreach ($fields as $field => $value) {
            if ($value === '') {
                $errors["qualifications.$i.$field"] =
                    'This field is required.';
            }
        }

        if ($row['YearObtained'] !== '') {
            if (
                !ctype_digit($row['YearObtained']) ||
                (int)$row['YearObtained'] < 1900 ||
                (int)$row['YearObtained'] > (int)date('Y')
            ) {
                $errors["qualifications.$i.year_obtained"] =
                    'Enter a valid year.';
            }
        }
    }

    /* Validate experience rows. */
    foreach ($experienceRows as $i => $row) {
        $fields = [
            'job_title' => $row['JobTitle'],
            'organization' => $row['Organization'],
            'start_date' => $row['StartDate'],
            'end_date' => $row['EndDate']
        ];

        $started = count(array_filter(
            $fields,
            fn($value) => $value !== ''
        )) > 0;

        if (!$started) {
            continue;
        }

        foreach ($fields as $field => $value) {
            if ($value === '') {
                $errors["experience.$i.$field"] =
                    'This field is required.';
            }
        }

        if (
            $row['StartDate'] !== '' &&
            $row['EndDate'] !== '' &&
            $row['EndDate'] < $row['StartDate']
        ) {
            $errors["experience.$i.end_date"] =
                'End date must be after the start date.';
        }

        $today = date('Y-m-d');

        foreach ([
            'start_date' => $row['StartDate'],
            'end_date' => $row['EndDate']
        ] as $field => $date) {
            if ($date !== '' && $date > $today) {
                $errors["experience.$i.$field"] =
                    'Date cannot be in the future.';
            }
        }
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
                $v['application_deadline'],
                $v['location']
            ];

            if ($editing) {
                $pdo->prepare(
                    'UPDATE opportunity
                     SET Title = ?, Description = ?, Type = ?,
                         ApplicationMethod = ?, OpportunityProvider = ?,
                         ExternalURL = ?, StartDate = ?, EndDate = ?,
                         ApplicationDeadLine = ?, Location = ?
                     WHERE OpportunityID = ?'
                )->execute([...$params, $editId]);

                $oppId = $editId;
            } else {
                $pdo->prepare(
                    "INSERT INTO opportunity
                     (Title, Description, Type, ApplicationMethod,
                      OpportunityProvider, ExternalURL, StartDate, EndDate,
                      ApplicationDeadLine, Location, Status, CreatedByAdminID)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)"
                )->execute([...$params, $adminId]);

                $oppId = (int)$pdo->lastInsertId();
            }

            /* Sync skills. */
            $pdo->prepare(
                'DELETE FROM opportunityskill WHERE OpportunityID = ?'
            )->execute([$oppId]);

            $insSkill = $pdo->prepare(
                'INSERT IGNORE INTO skill (SkillName) VALUES (?)'
            );

            $findSkill = $pdo->prepare(
                'SELECT SkillID FROM skill WHERE SkillName = ?'
            );

            $linkSkill = $pdo->prepare(
                'INSERT INTO opportunityskill (OpportunityID, SkillID)
                 VALUES (?, ?)'
            );

            foreach ($skills as $name) {
                $insSkill->execute([$name]);
                $findSkill->execute([$name]);
                $skillId = $findSkill->fetchColumn();

                if ($skillId !== false) {
                    $linkSkill->execute([$oppId, (int)$skillId]);
                }
            }

            /* Get qualification IDs already linked to this opportunity. */
            $existingQ = $pdo->prepare(
                'SELECT QualificationID
                 FROM opportunityqualification
                 WHERE OpportunityID = ?'
            );
            $existingQ->execute([$oppId]);

            $existingQIds = array_map(
                'intval',
                $existingQ->fetchAll(PDO::FETCH_COLUMN)
            );

            $pdo->prepare(
                'DELETE FROM opportunityqualification
                 WHERE OpportunityID = ?'
            )->execute([$oppId]);

            $linkQ = $pdo->prepare(
                'INSERT INTO opportunityqualification
                 (OpportunityID, QualificationID)
                 VALUES (?, ?)'
            );

            $insertQ = $pdo->prepare(
                'INSERT INTO qualification
                 (FieldOfStudy, DegreeLevel, Institution, YearObtained, Duration)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $updateQ = $pdo->prepare(
                'UPDATE qualification
                 SET FieldOfStudy = ?, DegreeLevel = ?,
                     YearObtained = ?, Duration = ?
                 WHERE QualificationID = ?'
            );

            foreach ($qualificationRows as $row) {
                $field = trim($row['FieldOfStudy']);
                $degree = trim($row['DegreeLevel']);
                $year = trim($row['YearObtained']);
                $duration = trim($row['Duration']);

                $started = (
                    $field !== '' ||
                    $degree !== '' ||
                    $year !== '' ||
                    $duration !== ''
                );

                if (!$started) {
                    continue;
                }

                $qualificationId = (int)$row['QualificationID'];

                if (
                    $qualificationId > 0 &&
                    in_array($qualificationId, $existingQIds, true)
                ) {
                    $updateQ->execute([
                        $field,
                        $degree,
                        $year,
                        $duration,
                        $qualificationId
                    ]);
                } else {
                    $insertQ->execute([
                        $field,
                        $degree,
                        '',
                        $year,
                        $duration
                    ]);

                    $qualificationId = (int)$pdo->lastInsertId();
                }

                $linkQ->execute([$oppId, $qualificationId]);
            }

            /* Sync experience rows. */
            $existingX = $pdo->prepare(
                'SELECT ExperienceID
                 FROM opportunityexperience
                 WHERE OpportunityID = ?'
            );
            $existingX->execute([$oppId]);

            $existingXIds = array_map(
                'intval',
                $existingX->fetchAll(PDO::FETCH_COLUMN)
            );

            $pdo->prepare(
                'DELETE FROM opportunityexperience
                 WHERE OpportunityID = ?'
            )->execute([$oppId]);

            $linkX = $pdo->prepare(
                'INSERT INTO opportunityexperience
                 (OpportunityID, ExperienceID)
                 VALUES (?, ?)'
            );

            $insertX = $pdo->prepare(
                'INSERT INTO experience
                 (JobTitle, Organization, StartDate, EndDate)
                 VALUES (?, ?, ?, ?)'
            );

            $updateX = $pdo->prepare(
                'UPDATE experience
                 SET JobTitle = ?, Organization = ?,
                     StartDate = ?, EndDate = ?
                 WHERE ExperienceID = ?'
            );

            foreach ($experienceRows as $row) {
                $job = trim($row['JobTitle']);
                $organization = trim($row['Organization']);
                $start = trim($row['StartDate']);
                $end = trim($row['EndDate']);

                $started = (
                    $job !== '' ||
                    $organization !== '' ||
                    $start !== '' ||
                    $end !== ''
                );

                if (!$started) {
                    continue;
                }

                $experienceId = (int)$row['ExperienceID'];

                if (
                    $experienceId > 0 &&
                    in_array($experienceId, $existingXIds, true)
                ) {
                    $updateX->execute([
                        $job,
                        $organization,
                        $start,
                        $end,
                        $experienceId
                    ]);
                } else {
                    $insertX->execute([
                        $job,
                        $organization,
                        $start,
                        $end
                    ]);

                    $experienceId = (int)$pdo->lastInsertId();
                }

                $linkX->execute([$oppId, $experienceId]);
            }

            $pdo->commit();

            header('Location: dashboard-admin.php?tab=opportunities&msg=saved');
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
    padding: clamp(20px, 3.5vw, 34px);
    background: var(--entry-surface);
    border: 1px solid var(--entry-border);
    border-radius: 20px;
    box-shadow: 0 12px 36px rgba(40, 26, 70, .08);
    overflow: hidden;
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

  #oppForm .form-row:has(#application_deadline) {
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

    #oppForm .form-row:has(#application_deadline) {
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
    #oppForm .form-row:has(#application_deadline) {
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
</style>

<div class="page-content container"
     style="max-width:1000px;width:100%;margin:0 auto;">

  <h2 style="margin-bottom:4px;">
    <?= $editing ? 'Edit Opportunity' : 'Add Opportunity' ?>
  </h2>

  <p class="text-muted" style="margin-bottom:24px;">
    Add an opportunity on behalf of a company that isn't on the platform yet.
  </p>

  <?php if ($errors): ?>
    <div class="form-error"
         style="display:block;background:#FBE6E7;padding:10px 14px;border-radius:8px;margin-bottom:16px;">
      <?= e($errors['_form'] ?? 'Please fill in all required fields correctly.') ?>
    </div>
  <?php endif; ?>

  <form method="post" id="oppForm" class="card" novalidate>
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="skills" id="skillsJson"
           value="<?= e(json_encode($skills)) ?>">

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
      <label class="form-label icon-label">
        <?= icon('goal') ?> Required Skills <span class="req">*</span>
      </label>

      <div class="flex gap-2" style="margin-bottom:10px;">
        <input type="text" id="skillInput" class="form-control"
               placeholder="Type a skill and press Enter">

        <button type="button" class="btn btn-outline" id="skillAddBtn">
          <?= icon('add') ?> Add
        </button>
      </div>

      <div id="skillList"></div>
      <?= err($errors, 'skills') ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label icon-label" for="duration">
          <?= icon('clock') ?> Duration
        </label>

        <input type="text" id="duration" class="form-control"
               placeholder="—" readonly>

        <div class="form-hint">
          Calculated automatically from the start and end dates.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label icon-label" for="location">
          <?= icon('location') ?> Location <span class="req">*</span>
        </label>

        <select id="location" name="location" required
                class="form-control <?= inv($errors, 'location') ?>">
          <option value="">Select city</option>
          <?php foreach (CITIES as $c): ?>
            <option value="<?= e($c) ?>"
              <?= $v['location'] === $c ? 'selected' : '' ?>>
              <?= e($c) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?= err($errors, 'location') ?>
      </div>
    </div>

    <div class="form-row">
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
        <label class="form-label icon-label" for="application_deadline">
          <?= icon('calendar') ?> Application Deadline <span class="req">*</span>
        </label>

        <input type="date" id="application_deadline"
               name="application_deadline" required
               class="form-control <?= inv($errors, 'application_deadline') ?>"
               value="<?= e($v['application_deadline']) ?>">

        <?= err($errors, 'application_deadline') ?>
      </div>
    </div>

    <!-- Qualifications -->
    <section class="entry-section">
      <label class="form-label icon-label entry-section-title">
        <?= icon('goal') ?> Qualifications
      </label>

      <p class="entry-section-hint">
        Optional. If you add a qualification, complete all its fields.
      </p>

      <div class="entry-list" id="qualificationList">
        <?php foreach ($qualificationRows as $i => $q): ?>
          <div class="entry-row qualification-row">
            <input type="hidden"
                   name="qualifications[<?= $i ?>][id]"
                   value="<?= e((string)($q['QualificationID'] ?? '')) ?>">

            <div class="form-group">
              <label class="form-label">
                Field of Study <span class="req">*</span>
              </label>
              <input type="text"
                     name="qualifications[<?= $i ?>][field_of_study]"
                     class="form-control <?= rowInvalid($errors, 'qualifications', $i, 'field_of_study') ?>"
                     value="<?= e($q['FieldOfStudy'] ?? '') ?>"
                     data-row-field>
              <?= rowError($errors, 'qualifications', $i, 'field_of_study') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                Degree Level <span class="req">*</span>
              </label>
              <input type="text"
                     name="qualifications[<?= $i ?>][degree_level]"
                     class="form-control <?= rowInvalid($errors, 'qualifications', $i, 'degree_level') ?>"
                     value="<?= e($q['DegreeLevel'] ?? '') ?>"
                     placeholder="e.g. Bachelor's"
                     data-row-field>
              <?= rowError($errors, 'qualifications', $i, 'degree_level') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                Year Obtained <span class="req">*</span>
              </label>
              <input type="number" min="1900" max="<?= date('Y') ?>"
                     name="qualifications[<?= $i ?>][year_obtained]"
                     class="form-control <?= rowInvalid($errors, 'qualifications', $i, 'year_obtained') ?>"
                     value="<?= e((string)($q['YearObtained'] ?? '')) ?>"
                     data-row-field>
              <?= rowError($errors, 'qualifications', $i, 'year_obtained') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                Duration <span class="req">*</span>
              </label>
              <input type="text"
                     name="qualifications[<?= $i ?>][duration]"
                     class="form-control <?= rowInvalid($errors, 'qualifications', $i, 'duration') ?>"
                     value="<?= e((string)($q['Duration'] ?? '')) ?>"
                     placeholder="e.g. 4 years"
                     data-row-field>
              <?= rowError($errors, 'qualifications', $i, 'duration') ?>
            </div>

            <button type="button" class="entry-remove"
                    aria-label="Remove qualification"
                    title="Remove qualification">×</button>
          </div>
        <?php endforeach; ?>
      </div>

      <?= err($errors, 'qualifications') ?>

      <button type="button" class="add-entry" id="addQualification">
        + Add another qualification
      </button>
    </section>

    <!-- Experience -->
    <section class="entry-section">
      <label class="form-label icon-label entry-section-title">
        <?= icon('form') ?> Experience
      </label>

      <p class="entry-section-hint">
        Optional. If you add an experience entry, complete all its fields.
      </p>

      <div class="entry-list" id="experienceList">
        <?php foreach ($experienceRows as $i => $x): ?>
          <div class="entry-row experience-row">
            <input type="hidden"
                   name="experience[<?= $i ?>][id]"
                   value="<?= e((string)($x['ExperienceID'] ?? '')) ?>">

            <div class="form-group">
              <label class="form-label">
                Job Title <span class="req">*</span>
              </label>
              <input type="text"
                     name="experience[<?= $i ?>][job_title]"
                     class="form-control <?= rowInvalid($errors, 'experience', $i, 'job_title') ?>"
                     value="<?= e($x['JobTitle'] ?? '') ?>"
                     data-row-field>
              <?= rowError($errors, 'experience', $i, 'job_title') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                Organization <span class="req">*</span>
              </label>
              <input type="text"
                     name="experience[<?= $i ?>][organization]"
                     class="form-control <?= rowInvalid($errors, 'experience', $i, 'organization') ?>"
                     value="<?= e($x['Organization'] ?? '') ?>"
                     data-row-field>
              <?= rowError($errors, 'experience', $i, 'organization') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                Start Date <span class="req">*</span>
              </label>
              <input type="date"
                     name="experience[<?= $i ?>][start_date]"
                     class="form-control <?= rowInvalid($errors, 'experience', $i, 'start_date') ?>"
                     value="<?= e($x['StartDate'] ?? '') ?>"
                     max="<?= date('Y-m-d') ?>"
                     data-row-field>
              <?= rowError($errors, 'experience', $i, 'start_date') ?>
            </div>

            <div class="form-group">
              <label class="form-label">
                End Date <span class="req">*</span>
              </label>
              <input type="date"
                     name="experience[<?= $i ?>][end_date]"
                     class="form-control <?= rowInvalid($errors, 'experience', $i, 'end_date') ?>"
                     value="<?= e($x['EndDate'] ?? '') ?>"
                     max="<?= date('Y-m-d') ?>"
                     data-row-field>
              <?= rowError($errors, 'experience', $i, 'end_date') ?>
            </div>

            <button type="button" class="entry-remove"
                    aria-label="Remove experience"
                    title="Remove experience">×</button>
          </div>
        <?php endforeach; ?>
      </div>

      <?= err($errors, 'experience') ?>

      <button type="button" class="add-entry" id="addExperience">
        + Add another experience
      </button>
    </section>

    <div class="flex-between" style="margin-top:24px;">
      <a href="dashboard-admin.php?tab=opportunities"
         class="btn btn-ghost">Cancel</a>

      <button type="submit" class="btn btn-primary">
        <?= $editing ? 'Save Changes' : 'Publish' ?>
      </button>
    </div>
  </form>
</div>

<script>
  /* Skill chips. */
  let skills = <?= json_encode($skills) ?>;

  const list = document.getElementById("skillList");
  const input = document.getElementById("skillInput");
  const hidden = document.getElementById("skillsJson");

  function renderSkills() {
    hidden.value = JSON.stringify(skills);
    list.innerHTML = "";

    if (!skills.length) {
      list.innerHTML =
        '<span class="text-muted" style="font-size:.85rem;">None added yet.</span>';
      return;
    }

    skills.forEach((skill, i) => {
      const chip = document.createElement("span");
      chip.className = "badge badge-primary";
      chip.style.cssText =
        "display:inline-flex;align-items:center;gap:6px;margin:0 6px 6px 0;";
      chip.textContent = skill + " ";

      const remove = document.createElement("button");
      remove.type = "button";
      remove.textContent = "\u00d7";
      remove.style.cssText =
        "background:none;border:none;color:inherit;cursor:pointer;padding:0;";

      remove.onclick = () => {
        skills.splice(i, 1);
        renderSkills();
      };

      chip.appendChild(remove);
      list.appendChild(chip);
    });
  }

  function addSkill() {
    const value = input.value.trim();

    if (value && !skills.includes(value)) {
      skills.push(value);
    }

    input.value = "";
    renderSkills();
  }

  input.addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      addSkill();
    }
  });

  document.getElementById("skillAddBtn")
    .addEventListener("click", addSkill);

  renderSkills();

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

    du.value = days < 14
      ? `${days} day${days === 1 ? "" : "s"}`
      : days < 63
        ? `${Math.round(days / 7)} weeks`
        : `${Math.round(days / 30)} months`;
  }

  sd.addEventListener("input", updateDuration);
  ed.addEventListener("input", updateDuration);
  updateDuration();

  /* Repeatable qualification and experience rows. */
  const qualificationList = document.getElementById("qualificationList");
  const experienceList = document.getElementById("experienceList");

  function createEntryRow(type, index) {
    const row = document.createElement("div");

    row.className = "entry-row " +
      (type === "qualification" ? "qualification-row" : "experience-row");

    const definitions = type === "qualification"
      ? [
          { key: "field_of_study", label: "Field of Study", type: "text", placeholder: "" },
          { key: "degree_level", label: "Degree Level", type: "text", placeholder: "e.g. Bachelor's" },
          { key: "year_obtained", label: "Year Obtained", type: "number", placeholder: "" },
          { key: "duration", label: "Duration", type: "text", placeholder: "e.g. 4 years" }
        ]
      : [
          { key: "job_title", label: "Job Title", type: "text", placeholder: "" },
          { key: "organization", label: "Organization", type: "text", placeholder: "" },
          { key: "start_date", label: "Start Date", type: "date", placeholder: "" },
          { key: "end_date", label: "End Date", type: "date", placeholder: "" }
        ];

    const prefix = type === "qualification"
      ? "qualifications"
      : "experience";

    const hiddenId = document.createElement("input");
    hiddenId.type = "hidden";
    hiddenId.name = `${prefix}[${index}][id]`;
    hiddenId.value = "";
    row.appendChild(hiddenId);

    definitions.forEach(def => {
      const group = document.createElement("div");
      group.className = "form-group";

      const label = document.createElement("label");
      label.className = "form-label";
      label.textContent = def.label + " ";

      const star = document.createElement("span");
      star.className = "req";
      star.textContent = "*";
      label.appendChild(star);

      const field = document.createElement("input");
      field.type = def.type;
      field.name = `${prefix}[${index}][${def.key}]`;
      field.dataset.field = def.key;
      field.dataset.rowField = "";
      field.className = "form-control";
      field.placeholder = def.placeholder;

      if (def.key === "year_obtained") {
        field.min = "1900";
        field.max = String(new Date().getFullYear());
      }

      if (
        type === "experience" &&
        (def.key === "start_date" || def.key === "end_date")
      ) {
        field.max = new Date().toISOString().slice(0, 10);
      }

      group.appendChild(label);
      group.appendChild(field);
      row.appendChild(group);
    });

    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "entry-remove";
    remove.textContent = "×";
    remove.title = "Remove entry";
    remove.setAttribute("aria-label", "Remove entry");
    row.appendChild(remove);

    return row;
  }

  function reindexRows(container, type) {
    const prefix = type === "qualification"
      ? "qualifications"
      : "experience";

    const className = type === "qualification"
      ? "qualification-row"
      : "experience-row";

    const fields = type === "qualification"
      ? ["field_of_study", "degree_level", "year_obtained", "duration"]
      : ["job_title", "organization", "start_date", "end_date"];

    container.querySelectorAll("." + className).forEach((row, index) => {
      const hiddenInput = row.querySelector('input[type="hidden"]');

      if (hiddenInput) {
        hiddenInput.name = `${prefix}[${index}][id]`;
      }

      fields.forEach(fieldName => {
        const field =
          row.querySelector(`[name*="[${fieldName}]"]`) ||
          row.querySelector(`[data-field="${fieldName}"]`);

        if (field) {
          field.name = `${prefix}[${index}][${fieldName}]`;
        }
      });
    });
  }

  function addEntry(container, type) {
    const className = type === "qualification"
      ? "qualification-row"
      : "experience-row";

    const index = container.querySelectorAll("." + className).length;

    container.appendChild(createEntryRow(type, index));
    reindexRows(container, type);
  }

  document.getElementById("addQualification").addEventListener("click", () => {
    addEntry(qualificationList, "qualification");
  });

  document.getElementById("addExperience").addEventListener("click", () => {
    addEntry(experienceList, "experience");
  });

  document.addEventListener("click", event => {
    const button = event.target.closest(".entry-remove");

    if (!button) {
      return;
    }

    const row = button.closest(".entry-row");
    const container = row.parentElement;

    if (container.children.length > 1) {
      row.remove();

      if (container === qualificationList) {
        reindexRows(container, "qualification");
      } else {
        reindexRows(container, "experience");
      }
    } else {
      row.querySelectorAll("input").forEach(field => {
        if (field.type !== "hidden") {
          field.value = "";
        }
      });

      row.querySelectorAll(".form-error").forEach(error => error.remove());

      row.querySelectorAll(".invalid").forEach(field => {
        field.classList.remove("invalid");
      });

      const idInput = row.querySelector('input[type="hidden"]');

      if (idInput) {
        idInput.value = "";
      }
    }
  });

  /* Highlight incomplete rows before submitting. */
  document.getElementById("oppForm").addEventListener("submit", event => {
    let firstInvalid = null;

    /* Check the description minimum on the client side too. */
    const description = document.getElementById("description");

    if (description.value.trim().length < 15) {
      description.classList.add("invalid");
      firstInvalid = description;

      let error = description.parentElement.querySelector(".form-error");

      if (!error) {
        error = document.createElement("div");
        error.className = "form-error";
        error.style.display = "block";
        description.insertAdjacentElement("afterend", error);
      }

      error.textContent =
        "Description must be at least 15 characters long.";
    } else {
      description.classList.remove("invalid");

      const descriptionError =
        description.parentElement.querySelector(".form-error");

      if (descriptionError) {
        descriptionError.remove();
      }
    }

    document.querySelectorAll(".entry-list").forEach(container => {
      const rows = container.querySelectorAll(".entry-row");

      rows.forEach(row => {
        const fields = Array.from(
          row.querySelectorAll("[data-row-field]")
        );

        const started = fields.some(
          field => field.value.trim() !== ""
        );

        fields.forEach(field => {
          field.classList.remove("invalid");

          if (started && field.value.trim() === "") {
            field.classList.add("invalid");
            firstInvalid ||= field;
          }
        });

        if (row.classList.contains("experience-row")) {
          const start = row.querySelector('[name*="[start_date]"]');
          const end = row.querySelector('[name*="[end_date]"]');

          if (
            start &&
            end &&
            start.value &&
            end.value &&
            end.value < start.value
          ) {
            end.classList.add("invalid");
            firstInvalid ||= end;
          }
        }
      });
    });

    if (firstInvalid) {
      event.preventDefault();
      firstInvalid.focus();
    }
  });
</script>

<?php admin_footer(); ?>