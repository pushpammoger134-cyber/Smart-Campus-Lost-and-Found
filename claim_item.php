<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Confirm the account still exists.
$statement = $pdo->prepare('SELECT id FROM users WHERE id = :id');
$statement->execute(['id' => $_SESSION['user_id']]);

if (!$statement->fetch()) {
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

$userId = (int) $_SESSION['user_id'];
$errors = [];
$proof = '';
$selectedId = '';
$availableItems = [];
$myClaims = [];

$success = $_SESSION['claim_success'] ?? '';
unset($_SESSION['claim_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedId = is_string($_POST['item_id'] ?? null)
        ? $_POST['item_id'] : '';

    $proof = is_string($_POST['proof_details'] ?? null)
        ? trim($_POST['proof_details']) : '';

    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Your form expired. Refresh the page and try again.';
    }

    $itemId = filter_var(
        $selectedId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($itemId === false) {
        $errors[] = 'Choose a valid found item.';
    }

    if (mb_strlen($proof) < 15 || mb_strlen($proof) > 3000) {
        $errors[] = 'Enter proof details between 15 and 3,000 characters.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the item while checking and submitting the claim.
            $statement = $pdo->prepare(
                'SELECT id, user_id, report_type, status
                 FROM items WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $itemId]);
            $item = $statement->fetch();

            if (
                !$item ||
                $item['report_type'] !== 'found' ||
                $item['status'] !== 'open'
            ) {
                $errors[] = 'This item is no longer available for claims.';
            } elseif ((int) $item['user_id'] === $userId) {
                $errors[] = 'You cannot claim an item you reported as found.';
            } else {
                $statement = $pdo->prepare(
                    'SELECT id FROM claims
                     WHERE item_id = :item_id AND claimant_id = :user_id'
                );
                $statement->execute([
                    'item_id' => $itemId,
                    'user_id' => $userId
                ]);

                if ($statement->fetch()) {
                    $errors[] = 'You already submitted a claim for this item.';
                }
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO claims
                        (item_id, claimant_id, proof_details, status)
                     VALUES
                        (:item_id, :claimant_id, :proof_details, 'pending')"
                );

                $statement->execute([
                    'item_id' => $itemId,
                    'claimant_id' => $userId,
                    'proof_details' => $proof
                ]);

                $claimId = (int) $pdo->lastInsertId();

                // Store notifications for the finder and any admins.
                // Proof details are not included in notifications.
                $statement = $pdo->prepare(
                    "INSERT INTO notifications
                        (user_id, item_id, claim_id, message)
                     SELECT id, :item_id, :claim_id, :message
                     FROM users
                     WHERE (id = :finder_id OR role = 'admin')
                       AND id <> :claimant_id"
                );

                $statement->execute([
                    'item_id' => $itemId,
                    'claim_id' => $claimId,
                    'message' => "A new claim was submitted for found report #$itemId.",
                    'finder_id' => $item['user_id'],
                    'claimant_id' => $userId
                ]);

                $pdo->commit();

                $_SESSION['claim_success'] =
                    'Your claim has been submitted for review. '
                    . 'It does not yet confirm ownership.';

                header('Location: claim_item.php');
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            if (
                $exception instanceof PDOException &&
                (int) ($exception->errorInfo[1] ?? 0) === 1062
            ) {
                $errors[] = 'You already submitted a claim for this item.';
            } else {
                $errors[] = 'Could not submit your claim. Please try again.';
            }
        }
    }
}

try {
    // List open found items posted by other users.
    // Exclude items for which this student already submitted a claim.
    $statement = $pdo->prepare(
        "SELECT i.id, i.title, i.location, i.event_date
         FROM items i
         WHERE i.report_type = 'found'
           AND i.status = 'open'
           AND i.user_id <> :finder_exclusion
           AND NOT EXISTS (
               SELECT 1 FROM claims c
               WHERE c.item_id = i.id
                 AND c.claimant_id = :claimant_id
           )
         ORDER BY i.created_at DESC, i.id DESC"
    );

    $statement->execute([
        'finder_exclusion' => $userId,
        'claimant_id' => $userId
    ]);

    $availableItems = $statement->fetchAll();

    // Students can see only their own claims here.
    $statement = $pdo->prepare(
        'SELECT c.id, c.item_id, c.status, c.review_note,
                c.created_at, i.title
         FROM claims c
         JOIN items i ON i.id = c.item_id
         WHERE c.claimant_id = :user_id
         ORDER BY c.created_at DESC, c.id DESC'
    );

    $statement->execute(['user_id' => $userId]);
    $myClaims = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $errors[] = 'Could not load items or claim history. Please refresh.';
}

$statusLabels = [
    'pending' => 'Pending review',
    'approved' => 'Approved',
    'rejected' => 'Rejected'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claims | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --input: #101827;
            --border: #29354b;
            --text: #f1f5f9;
            --muted: #a3afc4;
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
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 14px;
            line-height: 1.55;
        }

        a {
            color: var(--purple);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        header {
            background: #101827;
            border-bottom: 1px solid var(--border);
        }

        .topbar {
            max-width: 1000px;
            margin: auto;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand {
            color: var(--text);
            font-weight: 700;
            font-size: 16px;
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        nav a {
            font-size: 13px;
        }

        main {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        h1 {
            font-size: 25px;
            margin: 0 0 5px;
        }

        h2 {
            font-size: 18px;
            margin: 0 0 12px;
        }

        h3 {
            font-size: 15px;
            margin: 0 0 7px;
        }

        .subtitle, .hint, .muted {
            color: var(--muted);
        }

        .subtitle {
            margin: 0 0 22px;
        }

        .panel, .claim {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 20px;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 26px;
        }

        label {
            display: block;
            font-weight: 600;
            margin: 15px 0 6px;
        }

        select, textarea {
            width: 100%;
            min-width: 0;
            background: var(--input);
            border: 1px solid #41506a;
            border-radius: 6px;
            padding: 10px 12px;
            color: var(--text);
            font: inherit;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .hint {
            font-size: 12px;
            margin: 6px 0 0;
        }

        button {
            margin-top: 17px;
            background: var(--pink);
            color: white;
            border: 0;
            border-radius: 6px;
            padding: 10px 18px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #db2777;
        }

        .success, .errors {
            padding: 12px 15px;
            border-radius: 6px;
            margin: 14px 0;
        }

        .success {
            background: #103b31;
            border: 1px solid #287b62;
            color: #b4f4d9;
        }

        .errors {
            background: #3b1a2b;
            border: 1px solid #a53b60;
            color: #fecdd3;
        }

        .errors ul {
            margin: 0;
            padding-left: 20px;
        }

        .claims-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .claim p {
            margin: 7px 0;
            color: var(--muted);
        }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 700;
        }

        .pending {
            background: #46341b;
            color: #fcd58b;
        }

        .approved {
            background: #153d32;
            color: #a7f3d0;
        }

        .rejected {
            background: #481d30;
            color: #fda4af;
        }

        .review-note {
            white-space: pre-wrap;
        }

        :focus-visible {
            outline: 2px solid var(--purple);
            outline-offset: 3px;
        }

        @media (max-width: 600px) {
            main {
                padding: 22px 14px 45px;
            }

            .topbar {
                padding: 14px;
            }

            .claims-grid {
                grid-template-columns: 1fr;
            }

            .panel, .claim {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="topbar">
            <a class="brand" href="dashboard.php">Smart Campus Lost &amp; Found</a>
            <nav aria-label="Main navigation">
                <a href="dashboard.php">Dashboard</a>
                <a href="browse_items.php">Browse items</a>
                <a href="matches.php">Matching suggestions</a>
            </nav>
        </div>
    </header>

    <main>
        <h1>Claims &amp; Ownership Verification</h1>
        <p class="subtitle">Submit a claim and check its review status.</p>

        <section class="panel">
            <h2>Claim a found item</h2>
            <p class="muted">Select a found report and provide details that verify your ownership.</p>

            <?php if ($success !== ''): ?>
                <p class="success" role="status"><?= escape($success) ?></p>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="errors" role="alert">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($availableItems): ?>
                <form method="post" action="claim_item.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escape($_SESSION['csrf_token']) ?>"
                    >

                    <label for="item_id">Found item</label>
                    <select id="item_id" name="item_id" required>
                        <option value="">Choose a found item</option>
                        <?php foreach ($availableItems as $item): ?>
                            <option
                                value="<?= (int) $item['id'] ?>"
                                <?= $selectedId === (string) $item['id'] ? 'selected' : '' ?>
                            >
                                #<?= (int) $item['id'] ?>
                                — <?= escape($item['title']) ?>
                                — <?= escape($item['location']) ?>
                                — <?= escape($item['event_date']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="proof_details">Proof of ownership</label>
                    <textarea
                        id="proof_details"
                        name="proof_details"
                        minlength="15"
                        maxlength="3000"
                        aria-describedby="proof-hint"
                        required
                    ><?= escape($proof) ?></textarea>

                    <p id="proof-hint" class="hint">
                        Describe a private identifying detail, such as a sticker,
                        engraving, or the contents of a bag. Do not enter passwords
                        or PINs. These details are not displayed publicly.
                    </p>

                    <button type="submit">Submit claim request</button>
                </form>
            <?php elseif (!$errors): ?>
                <p class="muted">
                    No new items are available for you to claim.
                    Only open found reports from other users appear here,
                    and each item can be claimed once per account.
                </p>
            <?php endif; ?>
        </section>

        <section aria-labelledby="claims-heading">
            <h2 id="claims-heading">My claim requests</h2>

            <?php if (!$myClaims): ?>
                <p class="panel muted">You have no claim requests to display.</p>
            <?php else: ?>
                <div class="claims-grid">
                    <?php foreach ($myClaims as $claim): ?>
                        <article class="claim">
                            <h3><?= escape($claim['title']) ?></h3>
                            <p>
                                Claim #<?= (int) $claim['id'] ?>
                                · Found report #<?= (int) $claim['item_id'] ?>
                            </p>
                            <span class="badge <?= escape($claim['status']) ?>">
                                <?= escape($statusLabels[$claim['status']] ?? $claim['status']) ?>
                            </span>
                            <p>
                                <strong>Submitted:</strong>
                                <?= escape($claim['created_at']) ?>
                            </p>
                            <?php if (!empty($claim['review_note'])): ?>
                                <p><strong>Review note:</strong></p>
                                <p class="review-note"><?= escape($claim['review_note']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
