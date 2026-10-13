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

/* Get notifications for this user */
$stmt = $pdo->prepare(
    'SELECT
        n.NotificationID,
        n.Message,
        n.CreatedAt,
        n.IsRead,
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

.notifications-page {
    max-width: 1000px;
    margin: 0 auto;
    padding: 34px 24px 64px;
    color: var(--color-text, #253047);
}

.notifications-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 28px;
}

.notifications-heading h1 {
    margin: 0 0 8px;
    font-size: clamp(1.65rem, 3vw, 2.1rem);
    font-weight: 750;
    letter-spacing: -0.6px;
}

.notifications-heading p {
    margin: 0;
    color: var(--color-muted, #747d8d);
    font-size: 0.96rem;
    line-height: 1.7;
}

.notifications-count {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 15px;
    border: 1px solid var(--color-border, #e4e8ef);
    border-radius: 12px;
    background: var(--color-surface, #fff);
    white-space: nowrap;
    font-size: 0.88rem;
    color: var(--color-text, #253047);
}

.notifications-count strong {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 25px;
    height: 25px;
    padding: 0 7px;
    border-radius: 8px;
    background: var(--color-primary-tint, #edf1fa);
    color: var(--color-primary, #263b70);
    font-size: 0.8rem;
}

.notifications-count strong.zero {
    background: #edf7f0;
    color: #36734c;
}

.notifications-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.notification-stat {
    display: flex;
    align-items: center;
    gap: 15px;
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--color-border, #e4e8ef);
    border-radius: 16px;
    background: var(--color-surface, #fff);
    box-shadow: 0 3px 12px rgba(24, 39, 75, 0.035);
}

.notification-stat-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: var(--color-primary-tint, #edf1fa);
    color: var(--color-primary, #263b70);
}

.notification-stat-icon.unread-icon {
    background: #fff3e7;
    color: #a76523;
}

.notification-stat-label {
    margin-bottom: 5px;
    color: var(--color-muted, #747d8d);
    font-size: 0.84rem;
}

.notification-stat-value {
    font-size: 1.45rem;
    font-weight: 750;
    line-height: 1.2;
}

.notifications-list-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.notifications-list-heading h2 {
    margin: 0;
    font-size: 1.12rem;
    font-weight: 700;
}

.notifications-list-heading span {
    color: var(--color-muted, #747d8d);
    font-size: 0.83rem;
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 13px;
}

.notification-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 21px 22px;
    overflow: hidden;
    border: 1px solid var(--color-border, #e4e8ef);
    border-radius: 16px;
    background: var(--color-surface, #fff);
    box-shadow: 0 3px 12px rgba(24, 39, 75, 0.035);
    transition: border-color 0.2s ease, box-shadow 0.2s ease,
                transform 0.2s ease;
}

.notification-card:hover {
    border-color: #cbd4e7;
    box-shadow: 0 7px 20px rgba(24, 39, 75, 0.07);
    transform: translateY(-2px);
}

.notification-card.unread {
    background: linear-gradient(
        110deg,
        var(--color-primary-tint, #edf1fa) 0%,
        var(--color-surface, #fff) 65%
    );
    border-color: #d8dfef;
}

.notification-card.unread::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 4px;
    background: var(--color-primary, #263b70);
}

.notification-type-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 48px;
    width: 48px;
    height: 48px;
    border-radius: 14px;
}

.notification-type-icon.opportunity {
    color: #326b53;
    background: #e9f5ed;
}

.notification-type-icon.application {
    color: #365c9a;
    background: #eaf0fb;
}

.notification-type-icon.reminder {
    color: #9a6724;
    background: #fff2df;
}

.notification-type-icon.general {
    color: #6f5b94;
    background: #f0ebf8;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 9px;
}

.notification-type-label {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 7px;
    background: #f0f2f6;
    color: #5c6678;
    font-size: 0.73rem;
    font-weight: 650;
}

.notification-card.unread .notification-type-label {
    background: #e1e8f8;
    color: var(--color-primary, #263b70);
}

.notification-unread-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--color-primary, #263b70);
    font-size: 0.74rem;
    font-weight: 650;
}

.notification-unread-label::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}

.notification-message {
    margin: 0;
    color: var(--color-text, #253047);
    font-size: 0.94rem;
    font-weight: 450;
    line-height: 1.75;
    overflow-wrap: anywhere;
}

.notification-card.unread .notification-message {
    font-weight: 650;
}

.notification-opportunity {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 10px;
    color: var(--color-primary, #263b70);
    font-size: 0.83rem;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.notification-date {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 12px;
    color: var(--color-muted, #747d8d);
    font-size: 0.78rem;
}

.notifications-empty {
    padding: 58px 24px;
    text-align: center;
    border: 1px dashed var(--color-border, #dce2eb);
    border-radius: 18px;
    background: var(--color-surface, #fff);
}

.notifications-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 76px;
    height: 76px;
    margin: 0 auto 19px;
    border-radius: 23px;
    background: var(--color-primary-tint, #edf1fa);
    color: var(--color-primary, #263b70);
}

.notifications-empty h3 {
    margin: 0 0 9px;
    font-size: 1.15rem;
    font-weight: 700;
}

.notifications-empty p {
    max-width: 360px;
    margin: 0 auto;
    color: var(--color-muted, #747d8d);
    font-size: 0.9rem;
    line-height: 1.75;
}

@media (max-width: 640px) {
    .notifications-page {
        padding: 26px 16px 44px;
    }

    .notifications-heading {
        flex-direction: column;
        gap: 15px;
        margin-bottom: 22px;
    }

    .notifications-summary {
        gap: 10px;
        margin-bottom: 24px;
    }

    .notification-stat {
        align-items: flex-start;
        flex-direction: column;
        gap: 11px;
        padding: 15px;
    }

    .notification-stat-icon {
        width: 42px;
        height: 42px;
        flex-basis: 42px;
    }

    .notification-stat-value {
        font-size: 1.3rem;
    }

    .notification-card {
        gap: 12px;
        padding: 17px 15px;
    }

    .notification-type-icon {
        width: 40px;
        height: 40px;
        flex-basis: 40px;
        border-radius: 12px;
    }

    .notification-topline {
        align-items: flex-start;
        flex-direction: column;
    }

    .notification-message {
        font-size: 0.89rem;
    }
}
</style>

<div class="notifications-page">

    <!-- Page heading -->
    <section class="notifications-heading">
        <div>
            <h1>Notifications</h1>
            <p>
                Stay updated on training opportunities, application updates,
                and important reminders.
            </p>
        </div>

        <div class="notifications-count">
            <span>Unread notifications</span>
            <strong class="<?= $unreadCount === 0 ? 'zero' : '' ?>">
                <?= $unreadCount ?>
            </strong>
        </div>
    </section>

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

<?php admin_footer(); ?>
