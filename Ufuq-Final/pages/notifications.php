<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/user-layout.php';
require __DIR__ . '/auth.php';

$uid = require_user();

/* Get current user's profile */
$stmt = $pdo->prepare(
    'SELECT *
     FROM `user`
     WHERE UserID = ?
     LIMIT 1'
);
$stmt->execute([$uid]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$me) {
    http_response_code(404);
    exit('User profile was not found.');
}

/* Get notifications for this user.
   IsRead belongs to the usernotification relationship table. */
$stmt = $pdo->prepare(
    'SELECT
        n.NotificationID,
        n.Message,
        n.CreatedAt,
        un.IsRead,
        n.Type,
        n.OpportunityID,
        o.Title AS OpportunityTitle
     FROM usernotification un
     JOIN notification n
        ON n.NotificationID = un.NotificationID
     LEFT JOIN opportunity o
        ON o.OpportunityID = n.OpportunityID
     WHERE un.UserID = ?
     ORDER BY n.CreatedAt DESC, n.NotificationID DESC'
);
$stmt->execute([$uid]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Count unread notifications */
$unreadCount = 0;

foreach ($notifications as $notification) {
    if ((int) $notification['IsRead'] === 0) {
        $unreadCount++;
    }
}

/* Opening this page marks everything as read.
   $notifications above was loaded first, so the cards still show which
   ones were new on this visit; the bell badge in the header is cleared. */
if ($unreadCount > 0) {
    $stmt = $pdo->prepare(
        'UPDATE usernotification
         SET IsRead = 1
         WHERE UserID = ? AND IsRead = 0'
    );
    $stmt->execute([$uid]);
}

/* Notification type labels and icons */
function notification_type_info(?string $type): array
{
    switch ($type) {
        case 'NewOpportunity':
            return [
                'label' => 'New Opportunity',
                'icon'  => 'training',
                'class' => 'opportunity'
            ];

        case 'ApplicationUpdate':
            return [
                'label' => 'Application Update',
                'icon'  => 'form',
                'class' => 'application'
            ];

        case 'Reminder':
            return [
                'label' => 'Reminder',
                'icon'  => 'notification',
                'class' => 'reminder'
            ];

        default:
            return [
                'label' => 'Notification',
                'icon'  => 'notification',
                'class' => 'general'
            ];
    }
}

/* Format notification date */
function format_notification_date(?string $date): string
{
    if (empty($date)) {
        return '';
    }

    try {
        return (new DateTime($date))->format('M j, Y · g:i A');
    } catch (Exception $ex) {
        return $date;
    }
}

user_header($me, 'Notifications', '');
?>

<style>
/* ============================
   Ufuq Notifications Page
============================ */

.user-notifications {
    --user-purple: #39265f;
    --user-purple-dark: #281a46;
    --user-lavender: #f0ebf8;
    --user-gold: #e2a94b;
    --user-bg: #faf6ef;
    --user-text: #29243a;
    --user-muted: #777184;
    --user-border: #e9e3ed;

    color: var(--user-text);
    padding-bottom: 56px;
    background: var(--user-bg);
    min-height: 70vh;
}

.user-notifications *,
.user-notifications *::before,
.user-notifications *::after {
    box-sizing: border-box;
}

/* ----- Banner ----- */
.user-notifications .notifications-hero {
    position: relative;
    overflow: hidden;
    padding: 38px 0 42px;
    margin-bottom: 28px;
    background: linear-gradient(125deg, #281a46 0%, #39265f 55%, #49316f 100%);
    color: #fff;
}

.user-notifications .notifications-hero::before,
.user-notifications .notifications-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}

.user-notifications .notifications-hero::before {
    width: 250px;
    height: 250px;
    right: 7%;
    top: -150px;
    border: 35px solid rgba(255,255,255,.07);
}

.user-notifications .notifications-hero::after {
    width: 190px;
    height: 190px;
    right: 23%;
    bottom: -145px;
    background: rgba(226,169,75,.10);
}

.user-notifications .notifications-hero-inner {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 22px;
}

.user-notifications .hero-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    margin-bottom: 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 30px;
    background: rgba(255,255,255,.08);
    font-size: .84rem;
    letter-spacing: .2px;
}

.user-notifications .notifications-hero h1 {
    margin: 0;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.15rem);
    font-weight: 750;
    line-height: 1.5;
}

.user-notifications .notifications-hero p {
    margin: 8px 0 0;
    color: rgba(255,255,255,.82);
    font-size: .96rem;
    line-height: 1.8;
}

.user-notifications .notifications-count {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 14px;
    background: rgba(255,255,255,.08);
    color: #fff;
    white-space: nowrap;
    font-size: .88rem;
}

.user-notifications .notifications-count strong {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 27px;
    height: 27px;
    padding: 0 8px;
    border-radius: 9px;
    background: var(--user-gold);
    color: #3a2a0a;
    font-size: .82rem;
    font-weight: 800;
}

.user-notifications .notifications-count strong.zero {
    background: rgba(255,255,255,.2);
    color: #fff;
}

.user-notifications .notifications-content {
    max-width: 1000px;
    animation: notifFadeUp .5s ease both;
}

/* ----- Summary cards ----- */
.user-notifications .notifications-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.user-notifications .notification-stat {
    display: flex;
    align-items: center;
    gap: 15px;
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--user-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.045);
}

.user-notifications .notification-stat-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.user-notifications .notification-stat-icon.unread-icon {
    background: #fbf1dc;
    color: #8a5d12;
}

.user-notifications .notification-stat-label {
    margin-bottom: 5px;
    color: var(--user-muted);
    font-size: .84rem;
}

.user-notifications .notification-stat-value {
    color: var(--user-purple);
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1.2;
}

/* ----- List ----- */
.user-notifications .notifications-list-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.user-notifications .notifications-list-heading h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: var(--user-text);
    font-size: 1.18rem;
    font-weight: 750;
}

.user-notifications .notifications-list-heading h2::before {
    content: "";
    display: block;
    width: 5px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--user-gold);
}

.user-notifications .notifications-list-heading span {
    color: var(--user-muted);
    font-size: .83rem;
}

.user-notifications .notifications-list {
    display: flex;
    flex-direction: column;
    gap: 13px;
}

.user-notifications .notification-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 21px 22px;
    overflow: hidden;
    border: 1px solid var(--user-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(40,26,70,.04);
    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
}

.user-notifications .notification-card:hover {
    border-color: #cfc1e2;
    box-shadow: 0 10px 26px rgba(40,26,70,.08);
    transform: translateY(-2px);
}

.user-notifications .notification-card.unread {
    background: linear-gradient(110deg, #f4effb 0%, #fff 65%);
    border-color: #ddd0ee;
}

.user-notifications .notification-card.unread::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
    background: var(--user-gold);
}

.user-notifications .notification-type-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    border-radius: 14px;
}

.user-notifications .notification-type-icon.opportunity {
    color: #24653c;
    background: #e8f5ec;
}

.user-notifications .notification-type-icon.application {
    color: var(--user-purple);
    background: #eee8f8;
}

.user-notifications .notification-type-icon.reminder {
    color: #8a5d12;
    background: #fbf1dc;
}

.user-notifications .notification-type-icon.general {
    color: #6f5b94;
    background: var(--user-lavender);
}

.user-notifications .notification-content {
    flex: 1;
    min-width: 0;
}

.user-notifications .notification-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 9px;
}

.user-notifications .notification-type-label {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 30px;
    background: #f3f0f7;
    color: #62597a;
    font-size: .74rem;
    font-weight: 700;
}

.user-notifications .notification-card.unread .notification-type-label {
    background: #e9e0f6;
    color: var(--user-purple);
}

.user-notifications .notification-unread-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #8a5d12;
    font-size: .75rem;
    font-weight: 700;
}

.user-notifications .notification-unread-label::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--user-gold);
    box-shadow: 0 0 0 4px #fbf4e7;
}

.user-notifications .notification-message {
    margin: 0;
    color: var(--user-text);
    font-size: .94rem;
    font-weight: 450;
    line-height: 1.75;
    overflow-wrap: anywhere;
}

.user-notifications .notification-card.unread .notification-message {
    font-weight: 700;
}

.user-notifications .notification-opportunity {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 10px;
    color: var(--user-purple);
    font-size: .83rem;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.user-notifications .notification-date {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 12px;
    color: var(--user-muted);
    font-size: .78rem;
}

/* ----- Empty state ----- */
.user-notifications .notifications-empty {
    padding: 58px 24px;
    text-align: center;
    border: 1px dashed #d8cde7;
    border-radius: 18px;
    background: #fbf9fd;
}

.user-notifications .notifications-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    margin: 0 auto 19px;
    border-radius: 23px;
    background: var(--user-lavender);
    color: var(--user-purple);
}

.user-notifications .notifications-empty h3 {
    margin: 0 0 9px;
    color: var(--user-text);
    font-size: 1.15rem;
    font-weight: 750;
}

.user-notifications .notifications-empty p {
    max-width: 360px;
    margin: 0 auto;
    color: var(--user-muted);
    font-size: .9rem;
    line-height: 1.75;
}

@keyframes notifFadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

@media (max-width: 640px) {
    .user-notifications .notifications-hero {
        padding: 30px 0;
    }

    .user-notifications .notifications-summary {
        gap: 10px;
        margin-bottom: 24px;
    }

    .user-notifications .notification-stat {
        align-items: flex-start;
        flex-direction: column;
        gap: 11px;
        padding: 15px;
    }

    .user-notifications .notification-stat-icon {
        width: 42px;
        height: 42px;
        flex-basis: 42px;
    }

    .user-notifications .notification-stat-value {
        font-size: 1.3rem;
    }

    .user-notifications .notification-card {
        gap: 12px;
        padding: 17px 15px;
    }

    .user-notifications .notification-type-icon {
        width: 40px;
        height: 40px;
        flex-basis: 40px;
        border-radius: 12px;
    }

    .user-notifications .notification-topline {
        align-items: flex-start;
        flex-direction: column;
    }

    .user-notifications .notification-message {
        font-size: .89rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .user-notifications *,
    .user-notifications *::before,
    .user-notifications *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>

<div class="user-notifications">

    <!-- Page banner -->
    <section class="notifications-hero">
        <div class="container notifications-hero-inner">

            <div>
                <div class="hero-label">
                    <span aria-hidden="true">✦</span>
                    <span>Ufuq Learning Space</span>
                </div>

                <h1>Notifications</h1>

                <p>
                    Stay updated on training opportunities, application updates,
                    and important reminders.
                </p>
            </div>

        </div>
    </section>

    <div class="container notifications-content">

    <!-- Summary cards -->
    <section class="notifications-summary">

        <div class="notification-stat">
            <div class="notification-stat-icon">
                <?= icon('notification', 23) ?>
            </div>

            <div>
                <div class="notification-stat-label">
                    Total Notifications
                </div>
                <div class="notification-stat-value">
                    <?= count($notifications) ?>
                </div>
            </div>
        </div>

        <div class="notification-stat">
            <div class="notification-stat-icon unread-icon">
                <?= icon('notification', 23) ?>
            </div>

            <div>
                <div class="notification-stat-label">
                    Waiting for Your Attention
                </div>
                <div class="notification-stat-value">
                    <?= $unreadCount ?>
                </div>
            </div>
        </div>

    </section>

    <!-- Notifications list -->
    <section>
        <div class="notifications-list-heading">
            <h2>Recent Activity</h2>
            <span>
                <?= count($notifications) ?>
                <?= count($notifications) === 1
                    ? 'notification'
                    : 'notifications' ?>
            </span>
        </div>

        <?php if (empty($notifications)): ?>

            <div class="notifications-empty">
                <div class="notifications-empty-icon">
                    <?= icon('notification', 38) ?>
                </div>

                <h3>You're all caught up!</h3>

                <p>
                    You don't have any notifications yet.
                    Updates about your training opportunities and applications
                    will appear here.
                </p>
            </div>

        <?php else: ?>

            <div class="notifications-list">

                <?php foreach ($notifications as $n): ?>
                    <?php
                    $isUnread = (int) $n['IsRead'] === 0;
                    $typeInfo = notification_type_info($n['Type'] ?? null);
                    ?>

                    <article class="notification-card <?= $isUnread ? 'unread' : '' ?>">

                        <div class="notification-type-icon <?= e($typeInfo['class']) ?>">
                            <?= icon($typeInfo['icon'], 23) ?>
                        </div>

                        <div class="notification-content">

                            <div class="notification-topline">
                                <span class="notification-type-label">
                                    <?= e($typeInfo['label']) ?>
                                </span>

                                <?php if ($isUnread): ?>
                                    <span class="notification-unread-label">
                                        New
                                    </span>
                                <?php endif; ?>
                            </div>

                            <p class="notification-message">
                                <?= e($n['Message']) ?>
                            </p>

                            <?php if (!empty($n['OpportunityTitle'])): ?>
                                <div class="notification-opportunity">
                                    <?= icon('training', 16) ?>
                                    <span>
                                        <?= e($n['OpportunityTitle']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($n['CreatedAt'])): ?>
                                <div class="notification-date">
                                    <?= icon('notification', 14) ?>
                                    <span>
                                        <?= e(format_notification_date($n['CreatedAt'])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

    </div>
</div>

<?php admin_footer(); ?>
