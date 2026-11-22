<?php
/**
 * Ufuq scheduled maintenance.
 * Run from the command line (CLI), not from a browser.
 * Example: php maintenance.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

// Keep date-driven status transitions out of normal page requests.
// Preserve manually closed opportunities; only transition Upcoming -> Open
// and Open -> Closed when their dates require it.
$pdo->exec("UPDATE opportunity
    SET Status = CASE
        WHEN Status = 'Upcoming'
             AND (ApplicationOpenDate IS NULL OR CURDATE() >= ApplicationOpenDate)
             AND (ApplicationDeadLine IS NULL OR CURDATE() <= ApplicationDeadLine)
            THEN 'Open'
        WHEN Status = 'Upcoming'
             AND ApplicationDeadLine IS NOT NULL
             AND CURDATE() > ApplicationDeadLine
            THEN 'Closed'
        WHEN Status = 'Open'
             AND ApplicationDeadLine IS NOT NULL
             AND CURDATE() > ApplicationDeadLine
            THEN 'Closed'
        ELSE Status
    END
    WHERE (Status = 'Upcoming' AND (
              (ApplicationOpenDate IS NULL OR CURDATE() >= ApplicationOpenDate)
              OR (ApplicationDeadLine IS NOT NULL AND CURDATE() > ApplicationDeadLine)
          ))
       OR (Status = 'Open' AND ApplicationDeadLine IS NOT NULL AND CURDATE() > ApplicationDeadLine)");

close_expired_opportunities($pdo);
send_deadline_reminders($pdo);

$elapsed = number_format(microtime(true) - $startedAt, 3);
echo "Ufuq maintenance completed in {$elapsed}s" . PHP_EOL;
