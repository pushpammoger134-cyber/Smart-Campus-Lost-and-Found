<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['user_id'];

// Check the current database role, not a value supplied by the browser.
$statement = $pdo->prepare('SELECT role FROM users WHERE id = :id');
$statement->execute(['id' => $adminId]);

if ($statement->fetchColumn() !== 'admin') {
    http_response_code(403);
    exit('Access denied. This page is for administrators only.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function notifyUser(
    PDO $pdo,
    int $userId,
    int $itemId,
    int $claimId,
    string $message
): void {
    $statement = $pdo->prepare(
        'INSERT INTO notifications (user_id, item_id, claim_id, message)
         VALUES (:user_id, :item_id, :claim_id, :message)'
    );

    $statement->execute([
        'user_id' => $userId,
        'item_id' => $itemId,
        'claim_id' => $claimId,
        'message' => $message
    ]);
}

$error = '';
$success = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token'] : '';

    $action = is_string($_POST['action'] ?? null)
        ? $_POST['action'] : '';

    $note = is_string($_POST['review_note'] ?? null)
        ? trim($_POST['review_note']) : '';

    $rawId = is_string($_POST['claim_id'] ?? null)
        ? $_POST['claim_id'] : '';

    $claimId = filter_var(
        $rawId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Your form expired. Refresh and try again.';
    } elseif (
        $claimId === false ||
        !in_array($action, ['approve', 'reject', 'returned'], true)
    ) {
        $error = 'Invalid review request.';
    } elseif (mb_strlen($note) > 1000) {
        $error = 'Review notes must be 1,000 characters or fewer.';
    } elseif ($action === 'reject' && $note === '') {
        $error = 'Enter a reason before rejecting a claim.';
    } else {
        try {
            $pdo->beginTransaction();

            // Find the associated item.
            $statement = $pdo->prepare(
                'SELECT item_id FROM claims WHERE id = :id'
            );
            $statement->execute(['id' => $claimId]);
            $itemId = $statement->fetchColumn();

            if ($itemId === false) {
                throw new DomainException('This claim no longer exists.');
            }

            $itemId = (int) $itemId;

            // Lock the item first to prevent conflicting reviews.
            $statement = $pdo->prepare(
                'SELECT id, user_id, report_type, status
                 FROM items WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $itemId]);
            $item = $statement->fetch();

            $statement = $pdo->prepare(
                'SELECT * FROM claims WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $claimId]);
            $claim = $statement->fetch();

            if (!$item || !$claim || $item['report_type'] !== 'found') {
                throw new DomainException('The claim or found item is unavailable.');
            }

            if ((int) $claim['claimant_id'] === $adminId) {
                throw new DomainException(
                    'You cannot review your own claim. Another admin must review it.'
                );
            }

            if ($action === 'returned') {
                if (
                    $claim['status'] !== 'approved' ||
                    $item['status'] !== 'under_review'
                ) {
                    throw new DomainException(
                        'Only an approved claim awaiting handover can be marked returned.'
                    );
                }

                $statement = $pdo->prepare(
                    "UPDATE items SET status = 'returned' WHERE id = :id"
                );
                $statement->execute(['id' => $itemId]);

                $message = "Found report #$itemId has been marked as returned.";

                notifyUser(
                    $pdo, (int) $claim['claimant_id'],
                    $itemId, $claimId, $message
                );

                notifyUser(
                    $pdo, (int) $item['user_id'],
                    $itemId, $claimId, $message
                );

                $successMessage = 'Item marked as returned.';
            } else {
                if (
                    $claim['status'] !== 'pending' ||
                    $item['status'] !== 'open'
                ) {
                    throw new DomainException(
                        'This claim has already been reviewed or the item is no longer open.'
                    );
                }

                if ($action === 'approve') {
                    // An item can have only one approved owner.
                    $statement = $pdo->prepare(
                        "SELECT id FROM claims
                         WHERE item_id = :item_id AND status = 'approved'"
                    );
                    $statement->execute(['item_id' => $itemId]);

                    if ($statement->fetch()) {
                        throw new DomainException(
                            'Another claim for this item is already approved.'
                        );
                    }
                }

                $newStatus = $action === 'approve' ? 'approved' : 'rejected';

                if ($note === '') {
                    $note = 'Ownership proof reviewed. Arrange collection with the admin.';
                }

                $statement = $pdo->prepare(
                    'UPDATE claims
                     SET status = :status,
                         reviewed_by = :admin_id,
                         review_note = :note,
                         reviewed_at = NOW()
                     WHERE id = :id'
                );

                $statement->execute([
                    'status' => $newStatus,
                    'admin_id' => $adminId,
                    'note' => $note,
                    'id' => $claimId
                ]);

                notifyUser(
                    $pdo,
                    (int) $claim['claimant_id'],
                    $itemId,
                    $claimId,
                    "Your claim #$claimId was $newStatus. See My claim requests for details."
                );

                if ($action === 'approve') {
                    // Reserve the item until physical handover.
                    $statement = $pdo->prepare(
                        "UPDATE items SET status = 'under_review' WHERE id = :id"
                    );
                    $statement->execute(['id' => $itemId]);

                    // Reject competing pending claims.
                    $statement = $pdo->prepare(
                        "SELECT id, claimant_id FROM claims
                         WHERE item_id = :item_id
                           AND status = 'pending'
                         FOR UPDATE"
                    );
                    $statement->execute(['item_id' => $itemId]);
                    $otherClaims = $statement->fetchAll();

                    $rejectStatement = $pdo->prepare(
                        "UPDATE claims
                         SET status = 'rejected',
                             reviewed_by = :admin_id,
                             review_note = :note,
                             reviewed_at = NOW()
                         WHERE id = :id"
                    );

                    foreach ($otherClaims as $other) {
                        $rejectStatement->execute([
                            'admin_id' => $adminId,
                            'note' => 'Another ownership claim was approved for this item.',
                            'id' => $other['id']
                        ]);

                        notifyUser(
                            $pdo,
                            (int) $other['claimant_id'],
                            $itemId,
                            (int) $other['id'],
                            'Your claim was rejected because another ownership claim was approved.'
                        );
                    }

                    notifyUser(
                        $pdo,
                        (int) $item['user_id'],
                        $itemId,
                        $claimId,
                        "An ownership claim for found report #$itemId was approved. Arrange handover with the admin."
                    );

                    $successMessage =
                        'Claim approved. Mark the item returned only after handover.';
                } else {
                    $successMessage = 'Claim rejected. The item remains open.';
                }
            }

            $pdo->commit();

            $_SESSION['admin_success'] = $successMessage;
            header('Location: admin_claims.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof DomainException) {
                $error = $exception->getMessage();
            } else {
                error_log($exception->getMessage());
                $error = 'Could not save this review. Please try again.';
            }
        }
    }
}

$claims = [];

try {
    $statement = $pdo->query(
        "SELECT c.id, c.item_id, c.claimant_id, c.proof_details,
                c.status, c.review_note, c.created_at,
                i.title, i.status AS item_status, i.storage_location,
                u.full_name, u.email
         FROM claims c
         JOIN items i ON i.id = c.item_id
         JOIN users u ON u.id = c.claimant_id
         ORDER BY
             CASE c.status
                 WHEN 'pending' THEN 0
                 WHEN 'approved' THEN 1
                 ELSE 2
             END,
             c.created_at DESC, c.id DESC"
    );
    $claims = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Could not load claims. Please refresh.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Claim Review | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --field: #0e1729;
            --border: #2b354b;
            --text: #e8edf7;
            --muted: #a1aec5;
            --pink: #ec4899;
            --purple: #a78bfa;
            --blue: #60a5fa;
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
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .topbar {
            background: #101827;
            border-bottom: 1px solid var(--border);
        }

        .topbar-inner {
            max-width: 1080px;
            margin: auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--text);
            font-size: 16px;
            font-weight: 700;
        }

        .brand span {
            color: var(--pink);
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        nav a {
            color: #c6d1e4;
            font-size: 13px;
        }

        main {
            max-width: 1080px;
            margin: 0 auto;
            padding: 28px 20px 55px;
        }

        h1 {
            margin: 0 0 4px;
            font-size: 25px;
            font-weight: 700;
        }

        .intro {
            margin-bottom: 24px;
        }

        .intro p,
        .muted {
            color: var(--muted);
        }

        .intro p {
            margin: 0;
        }

        .section-title {
            font-size: 17px;
            margin: 0 0 14px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 14px;
            overflow-wrap: anywhere;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card h2 {
            margin: 0 0 3px;
            font-size: 17px;
        }

        .card h3 {
            font-size: 14px;
            margin: 17px 0 8px;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px 18px;
            margin: 15px 0;
        }

        .meta p {
            margin: 0;
            color: var(--muted);
        }

        .meta strong {
            color: var(--text);
        }

        .status {
            display: inline-block;
            border-radius: 5px;
            padding: 3px 9px;
            font-size: 12px;
            font-weight: 700;
        }

        .pending {
            background: #3c2d1c;
            color: #fbbf24;
        }

        .approved {
            background: #17382f;
            color: #86efac;
        }

        .rejected {
            background: #431e32;
            color: #fda4af;
        }

        .proof {
            background: var(--field);
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 12px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .note {
            white-space: pre-wrap;
        }

        label {
            display: block;
            font-weight: 600;
            margin: 14px 0 6px;
        }

        textarea {
            width: 100%;
            min-height: 80px;
            padding: 10px;
            background: var(--field);
            color: var(--text);
            border: 1px solid #45516b;
            border-radius: 7px;
            font: inherit;
            resize: vertical;
        }

        .buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        button {
            padding: 9px 15px;
            color: white;
            font: inherit;
            font-weight: 600;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
        }

        .approve {
            background: #197a61;
        }

        .reject {
            background: #b83265;
        }

        .returned {
            background: #4f46a5;
        }

        button:hover {
            filter: brightness(1.13);
        }

        .success,
        .error {
            padding: 12px 14px;
            border-radius: 7px;
            margin-bottom: 15px;
        }

        .success {
            background: #13342d;
            border: 1px solid #28745a;
            color: #b7f7d0;
        }

        .error {
            background: #401c2c;
            border: 1px solid #a43c5e;
            color: #ffd1dd;
        }

        :focus-visible {
            outline: 3px solid var(--purple);
            outline-offset: 3px;
        }

        @media (max-width: 600px) {
            main {
                padding: 22px 14px;
            }

            .card {
                padding: 15px;
            }

            .meta {
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

            <nav aria-label="Main navigation">
                <a href="dashboard.php">Dashboard</a>
                <a href="browse_items.php">Browse items</a>
                <a href="admin_items.php">Manage reports</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="intro">
            <h1>Admin Claim Review</h1>
            <p>Review ownership evidence and confirm item returns.</p>
        </div>

        <?php if ($success !== ''): ?>
            <p class="success" role="status">
                <?= escape($success) ?>
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert">
                <?= escape($error) ?>
            </p>
        <?php endif; ?>

        <h2 class="section-title">Ownership claims</h2>

        <?php if (!$claims && $error === ''): ?>
            <p class="card">No claim requests have been submitted yet.</p>
        <?php endif; ?>

        <?php foreach ($claims as $claim): ?>
            <article class="card">
                <div class="card-header">
                    <div>
                        <h2><?= escape($claim['title']) ?></h2>
                        <span class="muted">
                            Claim #<?= (int) $claim['id'] ?>
                            · Found report #<?= (int) $claim['item_id'] ?>
                        </span>
                    </div>

                    <span class="status <?= in_array($claim['status'], ['pending', 'approved', 'rejected'], true) ? escape($claim['status']) : '' ?>">
                        <?= escape(ucfirst($claim['status'])) ?>
                    </span>
                </div>

                <div class="meta">
                    <p><strong>Claimant:</strong> <?= escape($claim['full_name']) ?></p>
                    <p><strong>Email:</strong> <?= escape($claim['email']) ?></p>
                    <p><strong>Item status:</strong> <?= escape(ucwords(str_replace('_', ' ', $claim['item_status']))) ?></p>
                    <p><strong>Stored at:</strong> <?= escape($claim['storage_location'] ?? 'Not recorded') ?></p>
                </div>

                <h3>Private ownership proof</h3>
                <div class="proof"><?= escape($claim['proof_details']) ?></div>

                <?php if (!empty($claim['review_note'])): ?>
                    <p><strong>Review note:</strong></p>
                    <p class="note"><?= escape($claim['review_note']) ?></p>
                <?php endif; ?>

                <?php if ((int) $claim['claimant_id'] === $adminId): ?>
                    <p class="muted">Another admin must review your own claim.</p>
                <?php elseif ($claim['status'] === 'pending' && $claim['item_status'] === 'open'): ?>
                    <form method="post" action="admin_claims.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escape($_SESSION['csrf_token']) ?>"
                        >
                        <input
                            type="hidden"
                            name="claim_id"
                            value="<?= (int) $claim['id'] ?>"
                        >

                        <label for="note-<?= (int) $claim['id'] ?>">
                            Review note (required for rejection)
                        </label>
                        <textarea
                            id="note-<?= (int) $claim['id'] ?>"
                            name="review_note"
                            maxlength="1000"
                        ></textarea>

                        <div class="buttons">
                            <button class="approve" type="submit" name="action" value="approve">
                                Approve claim
                            </button>
                            <button class="reject" type="submit" name="action" value="reject">
                                Reject claim
                            </button>
                        </div>
                    </form>
                <?php elseif ($claim['status'] === 'approved' && $claim['item_status'] === 'under_review'): ?>
                    <form method="post" action="admin_claims.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escape($_SESSION['csrf_token']) ?>"
                        >
                        <input
                            type="hidden"
                            name="claim_id"
                            value="<?= (int) $claim['id'] ?>"
                        >

                        <p class="muted">
                            Use this button only after the item has been physically handed over.
                        </p>
                        <button class="returned" type="submit" name="action" value="returned">
                            Confirm item returned
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </main>
</body>
</html>
