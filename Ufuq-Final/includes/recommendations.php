<?php
/**
 * recommendations.php - asks the recommender for the best opportunities for one student.
 *
 * How it works, in simple words:
 *   1. The Python recommender runs on the side (a small service on port 8000).
 *   2. This file asks it: "which opportunities fit student number 7?"
 *   3. It answers with a list of opportunity IDs, best first (for example 12, 40, 3, 25, 8).
 *   4. We read those opportunities from MySQL and give them back in the SAME order.
 *
 * If anything goes wrong (service is off, slow, or has nothing for this student)
 * the function returns an empty list [] and the page can simply show its normal list.
 * So the website never breaks because of the recommender.
 *
 * How to use it in a page:
 *     require_once __DIR__ . '/../includes/recommendations.php';
 *     $items = get_recommended_opportunities($pdo, $userId);   // 5 opportunities, best first
 */

require_once __DIR__ . '/env.php';   // gives us env('NAME', 'default') to read the .env file

function get_recommended_opportunities(PDO $pdo, int $userId, int $limit = 5): array
{
    // Where the service lives. RECO_URL in .env, or this default if the line is missing.
    $base = rtrim((string) env('RECO_URL', 'http://127.0.0.1:8000'), '/');
    $url  = $base . '/recommend/' . $userId . '?k=' . max(1, min(50, $limit));

    // Ask the service. We wait at most 1 second to connect and 3 seconds in total,
    // so the page never hangs when the service is off.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // No answer, or an error answer (for example 404 = this user is not a student): show nothing special.
    if ($body === false || $code !== 200) {
        return [];
    }

    // The answer looks like: {"recommendations": [{"opportunity_id": 12, "title": "...", "score": 0.61}, ...]}
    // We only need the IDs.
    $data = json_decode($body, true);
    $ids  = array_map('intval', array_column($data['recommendations'] ?? [], 'opportunity_id'));
    if (!$ids) {
        return [];
    }

    // Read these opportunities from the database. The ? marks are filled safely by PDO.
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $pdo->prepare("SELECT * FROM opportunity WHERE OpportunityID IN ($marks)");
    $stmt->execute($ids);

    // MySQL does not keep our order, so put the rows in a box labelled by ID first...
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int) $row['OpportunityID']] = $row;
    }

    // ...then take them out in the order the recommender chose (best first).
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }
    return $ordered;
}

/* Tells the recommender to read the database again, so a change (a saved favorite, for example)
   shows at once. If the service is off or slow, nothing breaks: its 30-second refresh catches up. */
if (!function_exists('recommender_reload')) {
    function recommender_reload(): void
    {
        require_once __DIR__ . '/env.php';

        $base = rtrim((string)env('RECO_URL', 'http://127.0.0.1:8000'), '/');

        $ch = curl_init($base . '/reload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,   // service off: give up after 1 second
            CURLOPT_TIMEOUT        => 5,   // wait for the reload to finish, then continue
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}