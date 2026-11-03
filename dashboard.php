<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$statement = $pdo->prepare(
    'SELECT full_name, role, email_verified_at
     FROM users WHERE id = :id'
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

$isAdmin = $user['role'] === 'admin';

$stats = [
    'lost' => 0,
    'found' => 0,
    'claims' => 0,
    'unread' => 0,
    'pending_reviews' => 0
];

$recentItems = [];
$error = '';

try {
    $statement = $pdo->prepare(
        'SELECT report_type, COUNT(*) AS total
         FROM items
         WHERE user_id = :user_id
         GROUP BY report_type'
    );
    $statement->execute(['user_id' => $userId]);

    foreach ($statement->fetchAll() as $row) {
        if (in_array($row['report_type'], ['lost', 'found'], true)) {
            $stats[$row['report_type']] = (int) $row['total'];
        }
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM claims WHERE claimant_id = :user_id'
    );
    $statement->execute(['user_id' => $userId]);
    $stats['claims'] = (int) $statement->fetchColumn();

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications
         WHERE user_id = :user_id AND is_read = 0'
    );
    $statement->execute(['user_id' => $userId]);
    $stats['unread'] = (int) $statement->fetchColumn();

    if ($isAdmin) {
        $statement = $pdo->query(
            "SELECT COUNT(*) FROM claims WHERE status = 'pending'"
        );
        $stats['pending_reviews'] = (int) $statement->fetchColumn();
    }

    $statement = $pdo->prepare(
        'SELECT id, title, report_type, location, event_date, status
         FROM items
         WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );
    $statement->execute(['user_id' => $userId]);
    $recentItems = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Some dashboard information could not be loaded. Please refresh.';
}

$statusLabels = [
    'open' => 'Open',
    'under_review' => 'Awaiting handover',
    'returned' => 'Returned',
    'closed' => 'Closed'
];

$features = [
    [
        'url' => 'report_lost.php',
        'title' => 'Report lost item',
        'description' => 'Describe something you lost and upload a photo.'
    ],
    [
        'url' => 'report_found.php',
        'title' => 'Report found item',
        'description' => 'Help someone recover an item you found.'
    ],
    [
        'url' => 'browse_items.php',
        'title' => 'Browse and search',
        'description' => 'Find reports using keywords, categories and filters.'
    ],
    [
        'url' => 'matches.php',
        'title' => 'Matching suggestions',
        'description' => 'Compare your lost reports with possible found items.'
    ],
    [
        'url' => 'claim_item.php',
        'title' => 'Claims and claim status',
        'description' => 'Submit ownership proof and check your claim requests.'
    ],
    [
        'url' => 'notifications.php',
        'title' => 'Notifications',
        'description' => 'Read updates about claims, returns and removed reports.'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Smart Campus Lost and Found</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #060b18;
            --panel: #111a2d;
            --panel-hover: #19243c;
            --border: #293650;
            --text: #f8fafc;
            --muted: #a5b4ce;
            --pink: #ec4899;
            --purple: #a78bfa;
            --blue: #60a5fa;
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
        a { color: var(--blue); }
        .skip-link { position: absolute; top: -100px; left: 15px; background: white; color: #111827; padding: 12px; z-index: 20; }
        .skip-link:focus { top: 12px; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 228px; flex-shrink: 0; background: #0b1223; border-right: 1px solid var(--border); padding: 20px 13px; }
        .brand { margin: 0 8px 20px; font-weight: 800; font-size: 17px; line-height: 1.35; }
        .brand .star { color: var(--pink); }
        .brand small { display: block; margin-top: 7px; color: var(--muted); font-size: 12px; font-weight: 400; }
        .side-label { margin: 22px 12px 9px; color: #8293b2; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; }
        .side-link { display: block; padding: 9px 11px; margin: 2px 0; border-radius: 6px; color: #c5d1e7; text-decoration: none; font-size: 14px; }
        .side-link:hover, .side-link.active { background: #202a46; color: white; }
        .side-link.active { border-left: 3px solid var(--pink); }
        .content { flex: 1; min-width: 0; }
        .topbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding: 12px 24px; background: #0b1223; border-bottom: 1px solid var(--border); }
        .top-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 18px; }
        .top-actions a { text-decoration: none; font-size: 14px; }
        .top-actions form { margin: 0; }
        .logout { padding: 10px 17px; border: 1px solid #45516b; border-radius: 9px; background: #1d2841; color: white; cursor: pointer; font-weight: 700; }
        .logout:hover { background: #304061; }
        main { max-width: 1240px; margin: auto; padding: 22px 24px; }
        .hero { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 19px 22px; border-radius: 9px; background: radial-gradient(circle at 85% 20%, #243b78 0%, transparent 45%), linear-gradient(115deg, #17162e, #101b38); border: 1px solid #394267; }
        .hero h1 { font-size: clamp(21px, 2.5vw, 27px); margin: 0 0 8px; }
        .hero h1 .accent { color: #f9a8d4; }
        .hero p { color: #c4cce1; margin: 0; }
        .role { display: inline-block; padding: 5px 11px; border: 1px solid #9868ba; border-radius: 30px; background: #a78bfa22; color: #e9d5ff; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .notice, .error { padding: 10px 13px; margin-top: 12px; border-radius: 7px; }
        .notice { background: #3c2c16; color: #fde68a; border: 1px solid #b45309; }
        .error { background: #421c30; color: #fecdd3; border: 1px solid #be345b; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 16px 0 20px; }
        .stat { padding: 13px 16px; background: var(--panel); border: 1px solid var(--border); border-radius: 8px; }
        .stat-label { font-size: 13px; color: var(--muted); font-weight: 600; }
        .stat strong { display: block; margin-top: 7px; font-size: 25px; color: var(--blue); }
        .stat:nth-child(1) strong { color: #f472b6; }
        .stat:nth-child(3) strong { color: #c4b5fd; }
        .section-heading { margin: 18px 0 10px; }
        .section-heading h2 { margin: 0; font-size: 18px; }
        .admin-panel { margin-top: 18px; padding: 16px 18px; border: 1px solid #493b62; border-radius: 8px; background: #201a37; }
        .admin-panel h2 { margin: 0 0 8px; }
        .admin-panel p { color: #c8c5df; }
        .admin-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
        .button { display: inline-block; padding: 8px 13px; border-radius: 6px; background: var(--pink); color: white; text-decoration: none; font-size: 14px; font-weight: 700; }
        .button:hover { background: #db2777; }
        .manage-button { background: #43376e; }
        .manage-button:hover { background: #5b4994; }
        .table-wrapper { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); }
        table { width: 100%; min-width: 680px; border-collapse: collapse; }
        caption { padding: 14px 18px; text-align: left; color: var(--muted); font-size: 13px; }
        th, td { padding: 10px 13px; border-bottom: 1px solid var(--border); text-align: left; font-size: 14px; }
        th { background: #1c2840; color: #c5d2e8; font-size: 12px; text-transform: uppercase; }
        tbody tr:last-child td { border-bottom: 0; }
        .status { display: inline-block; padding: 5px 11px; border-radius: 25px; background: #2b344b; color: #dbe3f3; font-size: 12px; font-weight: 700; }
        .status-open { background: #1b3557; color: #93c5fd; }
        .status-under_review { background: #47351e; color: #fcd34d; }
        .status-returned { background: #153c36; color: #86efac; }
        .empty { padding: 15px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); color: var(--muted); }
        footer { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; }
        :focus-visible { outline: 3px solid #f9a8d4; outline-offset: 3px; }
        @media (max-width: 1100px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .layout { display: block; } .sidebar { width: 100%; padding: 15px; } .brand { margin-bottom: 15px; } .side-label { display: none; } .side-links { display: flex; gap: 6px; overflow-x: auto; } .side-link { white-space: nowrap; } .topbar { padding: 16px 20px; } main { padding: 22px 16px; } .hero { flex-direction: column; align-items: flex-start; padding: 25px; } }
        @media (max-width: 420px) { .stats { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="layout">
        <aside class="sidebar" aria-label="Sidebar navigation">
            <p class="brand">
                <span class="star">✦</span> Smart Campus Lost &amp; Found
                <small>Campus Portal</small>
            </p>

            <p class="side-label">Navigation</p>
            <nav class="side-links" aria-label="Main navigation">
                <a class="side-link active" href="dashboard.php" aria-current="page">Dashboard</a>

                <?php foreach ($features as $feature): ?>
                    <a class="side-link" href="<?= escape($feature['url']) ?>">
                        <?= escape($feature['title']) ?>
                    </a>
                <?php endforeach; ?>

                <?php if ($isAdmin): ?>
                    <a class="side-link" href="admin_claims.php">Review claims</a>
                    <a class="side-link" href="admin_items.php">Manage reports</a>
                <?php endif; ?>
            </nav>
        </aside>

        <div class="content">
            <header class="topbar">
                <strong>Overview</strong>

                <div class="top-actions">
                    <a href="notifications.php">
                        Notifications
                        <?php if ($error === ''): ?>
                            (<?= $stats['unread'] ?> unread)
                        <?php endif; ?>
                    </a>

                    <form method="post" action="logout.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escape($_SESSION['csrf_token']) ?>"
                        >
                        <button class="logout" type="submit">Log out</button>
                    </form>
                </div>
            </header>

            <main id="main-content" tabindex="-1">
                <section class="hero">
                    <div>
                        <h1>Welcome, <span class="accent"><?= escape($user['full_name']) ?></span>!</h1>
                        <p>Manage reports, discover matches, and track your claims.</p>
                    </div>
                    <span class="role"><?= $isAdmin ? 'Administrator' : 'Student' ?></span>
                </section>

                <?php if ($user['email_verified_at'] === null): ?>
                    <p class="notice">
                        Email verification is pending.
                        This local demo currently allows access before verification.
                    </p>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <p class="error" role="alert"><?= escape($error) ?></p>
                <?php else: ?>
                    <section class="stats" aria-label="Your activity counts">
                        <div class="stat">
                            <span class="stat-label">Lost reports</span>
                            <strong><?= $stats['lost'] ?></strong>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Found reports</span>
                            <strong><?= $stats['found'] ?></strong>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Claim requests</span>
                            <strong><?= $stats['claims'] ?></strong>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Unread notifications</span>
                            <strong><?= $stats['unread'] ?></strong>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <section class="admin-panel">
                        <h2>Admin tools</h2>
                        <?php if ($error === ''): ?>
                            <p><strong><?= $stats['pending_reviews'] ?></strong> claim(s) awaiting review.</p>
                        <?php endif; ?>
                        <p>Review ownership claims, confirm handovers, and remove spam or duplicate reports.</p>
                        <div class="admin-actions">
                            <a class="button" href="admin_claims.php">Open claim review</a>
                            <a class="button manage-button" href="admin_items.php">Manage reports</a>
                        </div>
                    </section>
                <?php endif; ?>

                <section aria-labelledby="recent-heading">
                    <div class="section-heading">
                        <h2 id="recent-heading">My recent reports</h2>
                    </div>

                    <?php if ($error !== ''): ?>
                        <p class="empty">Recent reports are temporarily unavailable.</p>
                    <?php elseif (!$recentItems): ?>
                        <p class="empty">
                            You have not posted any reports yet.
                            Use the sidebar to report a lost or found item.
                        </p>
                    <?php else: ?>
                        <div
                            class="table-wrapper"
                            tabindex="0"
                            role="region"
                            aria-label="Recent reports table, scroll horizontally on small screens"
                        >
                            <table>
                                <caption>Your five most recent reports</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">Item</th>
                                        <th scope="col">Type</th>
                                        <th scope="col">Location</th>
                                        <th scope="col">Date lost/found</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentItems as $item): ?>
                                        <tr>
                                            <td>#<?= (int) $item['id'] ?></td>
                                            <td><?= escape($item['title']) ?></td>
                                            <td><?= escape(ucfirst($item['report_type'])) ?></td>
                                            <td><?= escape($item['location']) ?></td>
                                            <td><?= escape($item['event_date']) ?></td>
                                            <td>
                                                <span class="status status-<?= escape(in_array($item['status'], ['open', 'under_review', 'returned', 'closed'], true) ? $item['status'] : 'closed') ?>">
                                                    <?= escape($statusLabels[$item['status']] ?? $item['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </main>

            <footer>Smart Campus Lost and Found Portal</footer>
        </div>
    </div>
</body>
</html>
