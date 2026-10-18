<?php
// Member lookups used by login / forgot password / reset password.

function member_find_by_email(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT MemberID, Email, Password, Role FROM member WHERE LOWER(Email) = LOWER(?)');
    $st->execute([$email]);
    $row = $st->fetch();
    return $row ?: null;
}

function member_find_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT MemberID, Email, Password, Role FROM member WHERE MemberID = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

// Name used in emails: first name for users and admins, company name for companies.
// Falls back to "there" ("Hi there,") if the name cannot be found.
function member_display_name(PDO $pdo, array $member): string
{
    $queries = [
        'User'    => 'SELECT FirstName FROM `user` WHERE UserID = ?',
        'Admin'   => 'SELECT FirstName FROM admin WHERE AdminID = ?',
        'Company' => 'SELECT CompanyName FROM company WHERE CompanyID = ?',
    ];

    if (isset($queries[$member['Role']])) {
        try {
            $st = $pdo->prepare($queries[$member['Role']]);
            $st->execute([(int) $member['MemberID']]);
            $name = $st->fetchColumn();

            if ($name !== false && $name !== null && trim((string) $name) !== '') {
                return (string) $name;
            }
        } catch (PDOException $e) {
            // Table not created yet: just use the fallback below.
        }
    }

    return 'there';
}