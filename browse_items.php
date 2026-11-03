<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function queryValue(string $key): string
{
    return is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
}

function searchPattern(string $value): string
{
    return '%' . strtr($value, [
        '!' => '!!',
        '%' => '!%',
        '_' => '!_'
    ]) . '%';
}

function pageUrl(array $filters, int $page): string
{
    $filters['page'] = $page;
    return 'browse_items.php?' . http_build_query($filters);
}

$categories = [
    'Electronics', 'ID Cards', 'Books', 'Keys',
    'Wallets', 'Bags', 'Clothing', 'Other'
];

$statusLabels = [
    'open' => 'Open',
    'under_review' => 'Under review',
    'returned' => 'Returned',
    'closed' => 'Closed'
];

$search = mb_substr(queryValue('search'), 0, 150);
$type = queryValue('type');
$category = queryValue('category');
$location = mb_substr(queryValue('location'), 0, 150);
$status = queryValue('status');
$date = queryValue('date');

if (!in_array($type, ['lost', 'found'], true)) {
    $type = '';
}
if (!in_array($category, $categories, true)) {
    $category = '';
}
if (!array_key_exists($status, $statusLabels)) {
    $status = '';
}

$dateValid = false;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateValid = $parsedDate !== false
        && $parsedDate->format('Y-m-d') === $date
        && $date >= '1000-01-01';
}
if (!$dateValid) {
    $date = '';
}

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(title LIKE :title_search ESCAPE '!'
        OR description LIKE :description_search ESCAPE '!')";
    $params['title_search'] = searchPattern($search);
    $params['description_search'] = searchPattern($search);
}
if ($type !== '') {
    $conditions[] = 'report_type = :report_type';
    $params['report_type'] = $type;
}
if ($category !== '') {
    $conditions[] = 'category = :category';
    $params['category'] = $category;
}
if ($location !== '') {
    $conditions[] = "location LIKE :location ESCAPE '!'";
    $params['location'] = searchPattern($location);
}
if ($status !== '') {
    $conditions[] = 'status = :status';
    $params['status'] = $status;
}
if ($date !== '') {
    $conditions[] = 'event_date = :event_date';
    $params['event_date'] = $date;
}

$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$pageInput = queryValue('page');
$page = ctype_digit($pageInput) ? max(1, (int) $pageInput) : 1;
$perPage = 12;
$items = [];
$total = 0;
$totalPages = 1;
$error = '';

try {
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM items' . $where);
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $pdo->prepare(
        'SELECT id, report_type, title, category, colour,
                description, location, event_date, image_path, status
         FROM items' . $where .
        " ORDER BY created_at DESC, id DESC
          LIMIT $perPage OFFSET $offset"
    );
    $statement->execute($params);
    $items = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Could not load reports. Please try again.';
}

$filters = [
    'search' => $search,
    'type' => $type,
    'category' => $category,
    'location' => $location,
    'status' => $status,
    'date' => $date
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Items | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --field: #101827;
            --border: #2b354b;
            --text: #e8edf7;
            --muted: #a4aec2;
            --pink: #ec4899;
            --purple: #a78bfa;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.5;
        }
        a { color: var(--purple); }
        header { background: #101827; border-bottom: 1px solid var(--border); }
        .topbar {
            max-width: 1100px;
            margin: auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }
        .brand { color: #f8fafc; font-weight: 700; text-decoration: none; font-size: 16px; }
        .topnav { display: flex; gap: 18px; flex-wrap: wrap; }
        .topnav a { font-size: 13px; text-decoration: none; }
        .topnav a:hover { text-decoration: underline; }
        main { max-width: 1100px; margin: auto; padding: 28px 20px 55px; }
        h1 { font-size: 25px; margin: 0 0 4px; }
        .intro { color: var(--muted); margin: 0 0 22px; font-size: 14px; }
        .filters {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            padding: 19px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
        }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
        input, select {
            width: 100%;
            min-width: 0;
            padding: 10px 11px;
            background: var(--field);
            color: var(--text);
            border: 1px solid #40506b;
            border-radius: 6px;
            font: inherit;
            font-size: 13px;
            color-scheme: dark;
        }
        input::placeholder { color: #8c98af; }
        input:focus, select:focus { outline: 2px solid var(--purple); outline-offset: 1px; }
        .actions { grid-column: 1 / -1; display: flex; align-items: center; gap: 16px; }
        button, .page-link {
            padding: 10px 17px;
            border: 0;
            border-radius: 6px;
            background: var(--pink);
            color: white;
            font: inherit;
            font-size: 13px;
            font-weight: 650;
            cursor: pointer;
            text-decoration: none;
        }
        button:hover, .page-link:hover { background: #db2777; }
        .clear { font-size: 13px; text-decoration: none; }
        .results-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin: 27px 0 14px; }
        .results-head h2 { font-size: 18px; margin: 0; }
        .count { color: var(--muted); font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 15px; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 9px; overflow: hidden; overflow-wrap: anywhere; }
        .photo, .no-photo { width: 100%; height: 165px; object-fit: contain; background: #101827; }
        .photo { display: block; }
        .no-photo { display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 13px; }
        .details { padding: 15px; }
        .badges { display: flex; gap: 7px; flex-wrap: wrap; }
        .badge { font-size: 11px; font-weight: 650; padding: 3px 9px; border-radius: 5px; }
        .lost { color: #fda4af; background: #4c2239; }
        .found { color: #86efac; background: #183d36; }
        .status-open { color: #93c5fd; background: #203453; }
        .status-under_review { color: #fde68a; background: #4a3b20; }
        .status-returned { color: #86efac; background: #183d36; }
        .status-closed { color: #cbd5e1; background: #334155; }
        .details h3 { margin: 11px 0; font-size: 16px; }
        .details p { margin: 7px 0; color: var(--muted); font-size: 13px; }
        .details strong { color: var(--text); }
        .description { white-space: pre-wrap; border-top: 1px solid var(--border); padding-top: 10px; margin-top: 12px !important; }
        .report-id { font-size: 11px !important; }
        .message { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 18px; }
        .error { color: #fda4af; }
        .pagination { display: flex; justify-content: center; align-items: center; gap: 14px; margin-top: 25px; flex-wrap: wrap; font-size: 13px; }
        :focus-visible { outline: 2px solid var(--purple); outline-offset: 3px; }
        @media (max-width: 850px) {
            .filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            main { padding: 22px 14px; }
            .filters, .grid { grid-template-columns: 1fr; }
            .topbar { padding: 14px; }
        }
    </style>
</head>
<body>
<header>
    <div class="topbar">
        <a class="brand" href="dashboard.php">Smart Campus Lost &amp; Found</a>
        <nav class="topnav" aria-label="Main navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="report_lost.php">Report lost item</a>
            <a href="report_found.php">Report found item</a>
        </nav>
    </div>
</header>
<main>
    <h1>Browse lost &amp; found items</h1>
    <p class="intro">Search campus reports using the filters below.</p>

    <form class="filters" method="get" action="browse_items.php">
        <div>
            <label for="search">Keyword</label>
            <input type="search" id="search" name="search" maxlength="150"
                   placeholder="Example: bottle" value="<?= escape($search) ?>">
        </div>
        <div>
            <label for="type">Report type</label>
            <select id="type" name="type">
                <option value="">Lost and found</option>
                <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>Lost</option>
                <option value="found" <?= $type === 'found' ? 'selected' : '' ?>>Found</option>
            </select>
        </div>
        <div>
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $option): ?>
                    <option value="<?= escape($option) ?>"
                        <?= $category === $option ? 'selected' : '' ?>>
                        <?= escape($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="location">Location</label>
            <input type="text" id="location" name="location" maxlength="150"
                   placeholder="Example: Library" value="<?= escape($location) ?>">
        </div>
        <div>
            <label for="date">Date lost or found</label>
            <input type="date" id="date" name="date" min="1000-01-01"
                   value="<?= escape($date) ?>">
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusLabels as $value => $label): ?>
                    <option value="<?= escape($value) ?>"
                        <?= $status === $value ? 'selected' : '' ?>>
                        <?= escape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions">
            <button type="submit">Search items</button>
            <a class="clear" href="browse_items.php">Clear filters</a>
        </div>
    </form>

    <?php if ($error !== ''): ?>
        <p class="message error" role="alert"><?= escape($error) ?></p>
    <?php else: ?>
        <div class="results-head">
            <h2>Campus reports</h2>
            <span class="count"><?= $total ?> matching report(s)</span>
        </div>

        <?php if (!$items): ?>
            <p class="message">No items match your search. Try another keyword or clear the filters.</p>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($items as $item): ?>
                    <?php
                    $imagePath = $item['image_path'] ?? '';
                    $hasImage = is_string($imagePath)
                        && preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png)$~D', $imagePath) === 1
                        && is_file(__DIR__ . '/' . $imagePath);
                    $isLost = $item['report_type'] === 'lost';
                    $itemStatus = (string) $item['status'];
                    ?>
                    <article class="card">
                        <?php if ($hasImage): ?>
                            <img class="photo" src="<?= escape($imagePath) ?>"
                                 alt="<?= escape('Photo of ' . $item['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="no-photo">No photo provided</div>
                        <?php endif; ?>
                        <div class="details">
                            <div class="badges">
                                <span class="badge <?= $isLost ? 'lost' : 'found' ?>">
                                    <?= $isLost ? 'Lost' : 'Found' ?>
                                </span>
                                <span class="badge <?= escape('status-' . $itemStatus) ?>">
                                    <?= escape($statusLabels[$itemStatus] ?? $itemStatus) ?>
                                </span>
                            </div>
                            <h3><?= escape($item['title']) ?></h3>
                            <p><strong>Category:</strong> <?= escape($item['category']) ?></p>
                            <?php if (!empty($item['colour'])): ?>
                                <p><strong>Colour:</strong> <?= escape($item['colour']) ?></p>
                            <?php endif; ?>
                            <p>
                                <strong><?= $isLost ? 'Last seen:' : 'Found at:' ?></strong>
                                <?= escape($item['location']) ?>
                            </p>
                            <p><strong>Date:</strong> <?= escape($item['event_date']) ?></p>
                            <p class="description"><?= escape($item['description']) ?></p>
                            <p class="report-id">Report ID: #<?= (int) $item['id'] ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Results pages">
                    <?php if ($page > 1): ?>
                        <a class="page-link" href="<?= escape(pageUrl($filters, $page - 1)) ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="page-link" href="<?= escape(pageUrl($filters, $page + 1)) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
