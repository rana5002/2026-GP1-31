<?php
declare(strict_types=1);

/*
  Shared lookup manager for Experience, Qualification and Skill.
 */

function lookup_config(string $type): array
{
    $config = [
        'experience' => [
            'table' => 'experience',
            'id'    => 'ExperienceID',
            'name'  => 'ExperienceName',
            'max'   => 100,
        ],
        'qualification' => [
            'table' => 'qualification',
            'id'    => 'QualificationID',
            'name'  => 'QualificationName',
            'max'   => 100,
        ],
        'skill' => [
            'table' => 'skill',
            'id'    => 'SkillID',
            'name'  => 'SkillName',
            'max'   => 50,
        ],
    ];

    if (!isset($config[$type])) {
        throw new InvalidArgumentException('Invalid lookup type.');
    }

    return $config[$type];
}

/*
  Normalize spaces and validate a user-entered lookup name.
 */
function lookup_clean_name(string $type, $value): string
{
    $cfg = lookup_config($type);

    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid value.');
    }

    $name = preg_replace('/\s+/u', ' ', trim($value));

    if (!is_string($name) || $name === '') {
        throw new InvalidArgumentException('Please enter a value.');
    }

    if (mb_strlen($name, 'UTF-8') > $cfg['max']) {
        throw new InvalidArgumentException(
            'The value must not exceed ' . $cfg['max'] . ' characters.'
        );
    }

    /*
     Allow common characters used in skills and qualification names:
     letters, numbers, spaces, ., &, +, #, apostrophe, /, ( ), -
     */
    if (!preg_match(
        '/^[\p{L}\p{N}][\p{L}\p{N}\s.&()+#\'\/-]*$/u',
        $name
    )) {
        throw new InvalidArgumentException(
            'Please use letters, numbers and common punctuation only.'
        );
    }

    return $name;
}

/*
  Resolve a new name:
  1. Search for an existing value.
  2. Return its ID if found.
  3. Otherwise insert it and return the new ID.
 
  GET_LOCK prevents two simultaneous requests from inserting the same
  normalized name at the same time.
 */
function lookup_resolve_name(PDO $pdo, string $type, $rawName): int
{
    $cfg = lookup_config($type);
    $name = lookup_clean_name($type, $rawName);

    $normalized = mb_strtolower($name, 'UTF-8');

    $lockName = 'ufuq_' . substr(
        hash('sha256', $type . ':' . $normalized),
        0,
        48
    );

    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute([$lockName]);

    if ((int)$lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'The value is being processed. Please try again.'
        );
    }

    try {
        $find = $pdo->prepare(
            "SELECT `{$cfg['id']}`
             FROM `{$cfg['table']}`
             WHERE LOWER(TRIM(`{$cfg['name']}`)) = LOWER(?)
             LIMIT 1"
        );

        $find->execute([$name]);
        $existingId = $find->fetchColumn();

        if ($existingId !== false) {
            return (int)$existingId;
        }

        $insert = $pdo->prepare(
            "INSERT INTO `{$cfg['table']}` (`{$cfg['name']}`)
             VALUES (?)"
        );

        $insert->execute([$name]);

        return (int)$pdo->lastInsertId();

    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

/*
  Resolve a submitted choice:
  - A positive numeric ID must already exist.
  - A text value is treated as a new/custom name.
 */
function lookup_resolve_choice(PDO $pdo, string $type, $choice): int
{
    $cfg = lookup_config($type);

    if (is_int($choice) || (is_string($choice) && ctype_digit($choice))) {
        $id = (int)$choice;

        if ($id < 1) {
            throw new InvalidArgumentException('Please select a valid option.');
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM `{$cfg['table']}`
             WHERE `{$cfg['id']}` = ?
             LIMIT 1"
        );

        $stmt->execute([$id]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('The selected option is invalid.');
        }

        return $id;
    }

    return lookup_resolve_name($pdo, $type, $choice);
}

/*
  Resolve a list of custom names and remove duplicate IDs.
 */
function lookup_resolve_names(PDO $pdo, string $type, $values, int $maxItems): array
{
    if (!is_array($values)) {
        throw new InvalidArgumentException('Invalid list of values.');
    }

    if (count($values) > $maxItems) {
        throw new InvalidArgumentException(
            'You cannot add more than ' . $maxItems . ' values.'
        );
    }

    $ids = [];

    foreach ($values as $value) {
        $name = lookup_clean_name($type, $value);
        $id = lookup_resolve_name($pdo, $type, $name);
        $ids[$id] = $id;
    }

    return array_values($ids);
}