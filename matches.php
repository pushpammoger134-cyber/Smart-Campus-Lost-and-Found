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

function normalise(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function keywords(string $value): array
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', normalise($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stopWords = [
        'a', 'an', 'the', 'is', 'was', 'and', 'or', 'of', 'to',
        'in', 'on', 'at', 'my', 'it', 'with', 'this', 'that',
        'lost', 'found', 'item', 'near', 'from', 'for'
    ];

    return array_values(array_unique(array_filter(
        $words,
        fn($word) => mb_strlen($word) > 1 && !in_array($word, $stopWords, true)
    )));
}

function calculateMatch(array $lost, array $found): array
{
    $score = 0;
    $reasons = [];

    if ($lost['category'] === $found['category']) {
        $score += 25;
        $reasons[] = 'Same category (+25)';
    }

    $lostColour = normalise($lost['colour'] ?? '');
    $foundColour = normalise($found['colour'] ?? '');

    if ($lostColour !== '' && $lostColour === $foundColour) {
        $score += 15;
        $reasons[] = 'Same colour (+15)';
    }

    $lostLocation = normalise($lost['location']);
    $foundLocation = normalise($found['location']);

    if ($lostLocation !== '' && $lostLocation === $foundLocation) {
        $score += 20;
        $reasons[] = 'Same location (+20)';
    } elseif (
        $lostLocation !== '' &&
        $foundLocation !== '' &&
        (
            str_contains($lostLocation, $foundLocation) ||
            str_contains($foundLocation, $lostLocation)
        )
    ) {
        $score += 10;
        $reasons[] = 'Similar location wording (+10)';
    }

    $lostWords = keywords($lost['title'] . ' ' . $lost['description']);
    $foundWords = keywords($found['title'] . ' ' . $found['description']);
    $commonWords = array_intersect($lostWords, $foundWords);
    $allWords = array_unique(array_merge($lostWords, $foundWords));

    if ($allWords && $commonWords) {
        $textScore = (int) round(30 * count($commonWords) / count($allWords));
        $score += $textScore;
        $reasons[] = 'Shared keywords (+' . $textScore . ')';
    }

    $lostDate = new DateTimeImmutable($lost['event_date']);
    $foundDate = new DateTimeImmutable($found['event_date']);
    $days = (int) $lostDate->diff($foundDate)->format('%r%a');

    if ($days >= 0 && $days <= 3) {
        $score += 10;
        $reasons[] = 'Found within 3 days (+10)';
    } elseif ($days >= 4 && $days <= 7) {
        $score += 5;
        $reasons[] = 'Found within 7 days (+5)';
    }

    return ['score' => $score, 'reasons' => $reasons];
}

$myLostItems = [];
$selectedItem = null;
$matches = [];
$error = '';

try {
    $statement = $pdo->prepare(
        "SELECT id, title, category, colour, description, location, event_date
         FROM items
         WHERE user_id = :user_id AND report_type = 'lost' AND status = 'open'
         ORDER BY created_at DESC, id DESC"
    );
    $statement->execute(['user_id' => $_SESSION['user_id']]);
    $myLostItems = $statement->fetchAll();

    $requestedId = is_string($_GET['item_id'] ?? null)
        ? $_GET['item_id']
        : '';

    if ($requestedId === '' && $myLostItems) {
        $selectedItem = $myLostItems[0];
    } else {
        foreach ($myLostItems as $item) {
            if ((string) $item['id'] === $requestedId) {
                $selectedItem = $item;
                break;
            }
        }

        if ($requestedId !== '' && !$selectedItem) {
            $error = 'Choose one of your open lost-item reports.';
        }
    }

    if ($selectedItem) {
        $statement = $pdo->prepare(
            "SELECT id, title, category, colour, description, location,
                    event_date, image_path
             FROM items
             WHERE report_type = 'found'
               AND status = 'open'
               AND user_id <> :user_id
               AND event_date >= :lost_date"
        );
        $statement->execute([
            'user_id' => $_SESSION['user_id'],
            'lost_date' => $selectedItem['event_date']
        ]);

        while ($found = $statement->fetch()) {
            $result = calculateMatch($selectedItem, $found);
            if ($result['score'] >= 40) {
                $found['score'] = $result['score'];
                $found['reasons'] = $result['reasons'];
                $matches[] = $found;
            }
        }

        usort($matches, function ($a, $b) {
            return ($b['score'] <=> $a['score']) ?: ($b['id'] <=> $a['id']);
        });
        $matches = array_slice($matches, 0, 10);
    }
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $error = 'Could not load matching suggestions. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matching Suggestions | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --field: #101827;
            --border: #29354b;
            --text: #e8edf7;
            --muted: #a1aec4;
            --pink: #ec4899;
            --purple: #a78bfa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.55 'Segoe UI', Arial, sans-serif;
        }

        a {
            color: var(--purple);
        }

        .topbar {
            border-bottom: 1px solid var(--border);
            background: #101827;
        }

        .topbar-inner {
            max-width: 1060px;
            margin: auto;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            font-weight: 700;
            color: #f5f7ff;
            text-decoration: none;
            font-size: 16px;
        }

        .brand span {
            color: var(--pink);
        }

        .topnav {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }

        .topnav a {
            text-decoration: none;
            font-size: 13px;
            color: #c6d0e2;
        }

        .topnav a:hover {
            color: var(--purple);
        }

        main {
            max-width: 1060px;
            margin: auto;
            padding: 30px 20px 60px;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 25px;
            font-weight: 700;
        }

        h2 {
            font-size: 18px;
        }

        .intro {
            margin-bottom: 22px;
        }

        .intro p,
        .muted {
            color: var(--muted);
            margin: 6px 0;
        }

        .panel,
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        .panel {
            padding: 20px;
            margin-bottom: 22px;
        }

        .panel h2 {
            margin: 0 0 6px;
        }

        .selection {
            display: flex;
            align-items: end;
            gap: 12px;
            margin-top: 16px;
        }

        .field {
            flex: 1;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        select {
            width: 100%;
            min-width: 0;
            background: var(--field);
            color: var(--text);
            border: 1px solid #43506a;
            border-radius: 7px;
            padding: 10px 12px;
            font: inherit;
        }

        button {
            background: var(--pink);
            color: #fff;
            border: 0;
            border-radius: 7px;
            padding: 11px 17px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #db2777;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
        }

        .card {
            overflow: hidden;
            overflow-wrap: anywhere;
        }

        .photo,
        .placeholder {
            width: 100%;
            height: 160px;
            background: #101827;
        }

        .photo {
            display: block;
            object-fit: contain;
        }

        .placeholder {
            display: grid;
            place-items: center;
            color: var(--muted);
        }

        .card-body {
            padding: 16px;
        }

        .card h3 {
            margin: 10px 0;
            font-size: 17px;
        }

        .score {
            display: inline-block;
            background: #34233e;
            color: #f9a8d4;
            border: 1px solid #70416d;
            border-radius: 6px;
            padding: 3px 9px;
            font-weight: 700;
            font-size: 12px;
        }

        .meta {
            margin: 6px 0;
            color: var(--muted);
            font-size: 13px;
        }

        .meta strong {
            color: var(--text);
        }

        .description {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            margin: 12px 0;
        }

        .reasons {
            background: #101827;
            border: 1px solid var(--border);
            padding: 12px;
            border-radius: 7px;
            margin-top: 12px;
        }

        .reasons strong {
            color: var(--purple);
        }

        .reasons ul {
            padding-left: 20px;
            margin: 7px 0 0;
        }

        .reasons li {
            margin: 4px 0;
        }

        .error {
            color: #fecdd3;
            border-color: #9f1239;
        }

        :focus-visible {
            outline: 3px solid var(--purple);
            outline-offset: 3px;
        }

        @media (max-width: 600px) {
            main {
                padding: 22px 14px 45px;
            }

            .selection {
                flex-direction: column;
                align-items: stretch;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="dashboard.php">
                <span>✦</span> Smart Campus Lost &amp; Found
            </a>
            <nav class="topnav" aria-label="Main navigation">
                <a href="dashboard.php">Dashboard</a>
                <a href="browse_items.php">Browse items</a>
                <a href="report_lost.php">Report lost</a>
                <a href="report_found.php">Report found</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="intro">
            <h1>Matching suggestions</h1>
            <p>Compare your lost reports with open found items from other students.</p>
            <p>Scores indicate similarity, not proof of ownership.</p>
        </div>

        <section class="panel">
            <h2>Find possible matches</h2>
            <?php if ($myLostItems): ?>
                <form class="selection" method="get" action="matches.php">
                    <div class="field">
                        <label for="item_id">Choose your lost item</label>
                        <select id="item_id" name="item_id" required>
                            <?php foreach ($myLostItems as $item): ?>
                                <option
                                    value="<?= (int) $item['id'] ?>"
                                    <?= $selectedItem && (int) $selectedItem['id'] === (int) $item['id'] ? 'selected' : '' ?>
                                >
                                    #<?= (int) $item['id'] ?> — <?= escape($item['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Find matches</button>
                </form>
            <?php elseif ($error === ''): ?>
                <p class="muted">
                    You have no open lost-item reports.
                    <a href="report_lost.php">Report a lost item first</a>.
                </p>
            <?php endif; ?>
        </section>

        <?php if ($error !== ''): ?>
            <p class="panel error" role="alert"><?= escape($error) ?></p>
        <?php elseif ($selectedItem): ?>
            <h2>Suggestions for <?= escape($selectedItem['title']) ?></h2>

            <?php if (!$matches): ?>
                <p class="panel muted">
                    No suggestions scored at least 40/100.
                    Check again after other students report found items.
                </p>
            <?php else: ?>
                <p class="muted">
                    Showing <?= count($matches) ?> suggestion(s), highest score first.
                </p>

                <div class="grid">
                    <?php foreach ($matches as $match): ?>
                        <?php
                        $imagePath = $match['image_path'] ?? '';
                        $hasImage = is_string($imagePath) &&
                            preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png)$~D', $imagePath) === 1 &&
                            is_file(__DIR__ . '/' . $imagePath);
                        ?>
                        <article class="card">
                            <?php if ($hasImage): ?>
                                <img
                                    class="photo"
                                    src="<?= escape($imagePath) ?>"
                                    alt="<?= escape('Photo of ' . $match['title']) ?>"
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <div class="placeholder">No photo provided</div>
                            <?php endif; ?>

                            <div class="card-body">
                                <span class="score">
                                    <?= (int) $match['score'] ?>/100 similarity
                                </span>
                                <h3><?= escape($match['title']) ?></h3>
                                <p class="meta">
                                    <strong>Found at:</strong>
                                    <?= escape($match['location']) ?>
                                </p>
                                <p class="meta">
                                    <strong>Date found:</strong>
                                    <?= escape($match['event_date']) ?>
                                </p>
                                <p class="meta">
                                    <strong>Category:</strong>
                                    <?= escape($match['category']) ?>
                                </p>
                                <p class="description">
                                    <?= escape($match['description']) ?>
                                </p>
                                <div class="reasons">
                                    <strong>Why this was suggested</strong>
                                    <ul>
                                        <?php foreach ($match['reasons'] as $reason): ?>
                                            <li><?= escape($reason) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <p class="meta">
                                    <strong>Found report ID:</strong>
                                    #<?= (int) $match['id'] ?>
                                </p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
