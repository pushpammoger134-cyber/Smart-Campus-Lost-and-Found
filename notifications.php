<?php
session_start();
require_once __DIR__ . '/db.php';

// Require login.
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Check the account and retrieve its current role.
$statement = $pdo->prepare(
    'SELECT role FROM users WHERE id = :id'
);
$statement->execute(['id' => $userId]);
$user = $statement->fetch();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = $_SESSION['notification_success'] ?? '';
unset($_SESSION['notification_success']);

// Mark notifications as read.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token'] : '';

    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['read_one', 'read_all'], true)) {
        $error = 'Invalid action.';
    } else {
        try {
            if ($action === 'read_all') {
                // Only update the logged-in user's notifications.
                $statement = $pdo->prepare(
                    'UPDATE notifications
                     SET is_read = 1
                     WHERE user_id = :user_id AND is_read = 0'
                );
                $statement->execute(['user_id' => $userId]);

                $success = 'All your notifications are marked as read.';
            } else {
                $rawId = is_string($_POST['notification_id'] ?? null)
                    ? $_POST['notification_id'] : '';

                $notificationId = filter_var(
                    $rawId,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($notificationId === false) {
                    $error = 'Invalid notification.';
                } else {
                    // Ownership check prevents editing someone else's alerts.
                    $statement = $pdo->prepare(
                        'UPDATE notifications
                         SET is_read = 1
                         WHERE id = :id
                           AND user_id = :user_id
                           AND is_read = 0'
                    );

                    $statement->execute([
                        'id' => $notificationId,
                        'user_id' => $userId
                    ]);

                    $success = $statement->rowCount() > 0
                        ? 'Notification marked as read.'
                        : 'Notification is already read or unavailable.';
                }
            }

            if ($error === '') {
                $_SESSION['notification_success'] = $success;
                header('Location: notifications.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Could not update notifications. Please try again.';
        }
    }
}

// Load only this user's notifications, with pagination.
$notifications = [];
$unreadCount = 0;
$total = 0;
$totalPages = 1;

$pageInput = is_string($_GET['page'] ?? null)
    ? $_GET['page'] : '';

$page = ctype_digit($pageInput)
    ? max(1, (int) $pageInput)
    : 1;

$perPage = 15;

try {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(is_read = 0), 0) AS unread_count
         FROM notifications
         WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);
    $counts = $statement->fetch();

    $total = (int) $counts['total'];
    $unreadCount = (int) $counts['unread_count'];

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // LIMIT and OFFSET are calculated integers.
    $statement = $pdo->prepare(
        "SELECT id, item_id, claim_id, message, is_read, created_at
         FROM notifications
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $statement->execute(['user_id' => $userId]);
    $notifications = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Could not load notifications. Please refresh.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --input: #101827;
            --line: #29354b;
            --text: #f1f5f9;
            --muted: #a4b1c5;
            --pink: #ec4899;
            --purple: #a78bfa;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.55 'Segoe UI', Arial, sans-serif;
        }

        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .topbar {
            background: #101827;
            border-bottom: 1px solid var(--line);
        }

        .topbar-inner {
            max-width: 980px;
            margin: auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .brand { font-weight: 700; color: var(--text); font-size: 16px; }
        .brand span { color: var(--pink); }
        .topbar nav { display: flex; flex-wrap: wrap; gap: 16px; }
        .topbar nav a { color: #cbd5e1; font-size: 13px; }

        main { max-width: 980px; margin: 0 auto; padding: 28px 20px 55px; }
        h1 { font-size: 25px; margin: 0 0 5px; }
        .muted, .date, .reference { color: var(--muted); }
        .summary { display: flex; gap: 16px; flex-wrap: wrap; margin: 14px 0; }
        .summary strong { color: var(--purple); }
        .page-head { margin-bottom: 24px; }
        .page-head p { margin: 6px 0; }

        .notification {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 16px 18px;
            margin-bottom: 11px;
            overflow-wrap: anywhere;
        }

        .notification.unread { border-left: 3px solid var(--pink); }
        .notification-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .notification-message { margin: 11px 0; white-space: pre-wrap; }
        .date, .reference { font-size: 12px; margin: 4px 0; }
        .badge { color: #cbd5e1; font-size: 12px; font-weight: 600; }
        .unread .badge { color: #f9a8d4; }

        button {
            background: var(--pink);
            color: white;
            border: 0;
            border-radius: 7px;
            padding: 9px 15px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { background: #db2777; }
        .secondary-button { background: #29354b; color: #e2e8f0; margin-top: 8px; }
        .secondary-button:hover { background: #394a68; }
        .success, .error, .empty { padding: 14px 16px; border-radius: 8px; margin-bottom: 15px; }
        .success { background: #10352c; color: #a7f3d0; }
        .error { background: #421b30; color: #fecdd3; }
        .empty { background: var(--panel); border: 1px solid var(--line); color: var(--muted); }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 18px; margin-top: 25px; flex-wrap: wrap; }
        :focus-visible { outline: 2px solid var(--purple); outline-offset: 3px; }

        @media (max-width: 600px) {
            main { padding: 22px 14px 40px; }
            .topbar-inner { padding: 13px 14px; }
            .notification { padding: 14px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">
                <span>✦</span> Smart Campus Lost &amp; Found
            </a>

            <nav aria-label="Main navigation">
                <a href="dashboard.php">Dashboard</a>
                <a href="browse_items.php">Browse items</a>
                <a href="claim_item.php">My claims</a>

                <?php if ($user['role'] === 'admin'): ?>
                    <a href="admin_claims.php">Admin claim review</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main>
        <section class="page-head">
            <h1>Notifications</h1>
            <p class="muted">Updates on claims, reports and item returns.</p>

            <div class="summary" aria-label="Notification counts">
                <span><strong><?= $unreadCount ?></strong> unread</span>
                <span><strong><?= $total ?></strong> total</span>
            </div>

            <?php if ($unreadCount > 0 && $error === ''): ?>
                <form method="post" action="notifications.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape($_SESSION['csrf_token']) ?>"
                    >
                    <button type="submit" name="action" value="read_all">
                        Mark all as read
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($success !== ''): ?>
            <p class="success" role="status"><?= escape($success) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert"><?= escape($error) ?></p>
        <?php endif; ?>

        <?php if (!$notifications && $error === ''): ?>
            <p class="empty">No notifications yet. Updates will appear here when there is activity on your reports or claims.</p>
        <?php endif; ?>

        <?php foreach ($notifications as $notification): ?>
            <?php $isUnread = (int) $notification['is_read'] === 0; ?>

            <article class="notification <?= $isUnread ? 'unread' : '' ?>">
                <div class="notification-top">
                    <span class="badge"><?= $isUnread ? '● Unread' : 'Read' ?></span>
                    <span class="date"><?= escape($notification['created_at']) ?></span>
                </div>

                <p class="notification-message">
                    <?= escape($notification['message']) ?>
                </p>

                <?php if ($notification['item_id'] !== null): ?>
                    <p class="reference">
                        Report #<?= (int) $notification['item_id'] ?>

                        <?php if ($notification['claim_id'] !== null): ?>
                            · Claim #<?= (int) $notification['claim_id'] ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($isUnread): ?>
                    <form method="post" action="notifications.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escape($_SESSION['csrf_token']) ?>"
                        >
                        <input
                            type="hidden"
                            name="notification_id"
                            value="<?= (int) $notification['id'] ?>"
                        >
                        <button
                            class="secondary-button"
                            type="submit"
                            name="action"
                            value="read_one"
                        >
                            Mark as read
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Notification pages">
                <?php if ($page > 1): ?>
                    <a href="notifications.php?page=<?= $page - 1 ?>">Previous</a>
                <?php endif; ?>

                <span>Page <?= $page ?> of <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="notifications.php?page=<?= $page + 1 ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</body>
</html>
