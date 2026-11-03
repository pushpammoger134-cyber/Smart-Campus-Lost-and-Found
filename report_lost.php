<?php

session_start();

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$accountStatement = $pdo->prepare('SELECT id FROM users WHERE id = :id');

$accountStatement->execute(['id' => $_SESSION['user_id']]);

if (!$accountStatement->fetch()) { $_SESSION = []; session_destroy(); header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$categories = ['Electronics','ID Cards','Books','Keys','Wallets','Bags','Clothing','Other'];

$values = ['title'=>'','category'=>'','colour'=>'','description'=>'','location'=>'','event_date'=>''];

$errors = [];

$success = $_SESSION['lost_success'] ?? '';

unset($_SESSION['lost_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($values as $field => $value) $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';

    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) $errors[] = 'The form expired or the upload was too large. Refresh and try again with a photo under 2 MB.';

    if ($values['title'] === '' || mb_strlen($values['title']) > 150) $errors[] = 'Enter an item title of up to 150 characters.';

    if (!in_array($values['category'], $categories, true)) $errors[] = 'Choose a valid category.';

    if (mb_strlen($values['colour']) > 50) $errors[] = 'Colour must be 50 characters or fewer.';

    if ($values['description'] === '' || mb_strlen($values['description']) > 3000) $errors[] = 'Enter a description of up to 3,000 characters.';

    if ($values['location'] === '' || mb_strlen($values['location']) > 150) $errors[] = 'Enter a location of up to 150 characters.';

    // Accept real calendar dates from 1000-01-01 through today.
    $dateIsValid = false;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['event_date']) === 1) {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $values['event_date']
        );

        $dateIsValid =
            $date !== false &&
            $date->format('Y-m-d') === $values['event_date'] &&
            $values['event_date'] >= '1000-01-01' &&
            $values['event_date'] <= date('Y-m-d');
    }

    if (!$dateIsValid) {
        $errors[] = 'Choose a valid date that is not in the future.';
    }

    $imagePath = null; $savedFile = null; $extension = null; $photo = $_FILES['photo'] ?? null;

    if ($photo !== null) {

        if (!is_array($photo) || !isset($photo['error']) || !is_int($photo['error'])) $errors[] = 'Invalid photo upload.';

        elseif ($photo['error'] !== UPLOAD_ERR_NO_FILE) {

            if ($photo['error'] !== UPLOAD_ERR_OK) $errors[] = 'Photo upload failed. Choose a JPG or PNG under 2 MB.';

            elseif (!isset($photo['tmp_name']) || !is_string($photo['tmp_name']) || !is_uploaded_file($photo['tmp_name'])) $errors[] = 'Invalid uploaded file.';

            else {

                $size = filesize($photo['tmp_name']);

                if ($size === false || $size === 0 || $size > 2 * 1024 * 1024) $errors[] = 'The photo must be no larger than 2 MB.';

                else {

                    $fileInfo = new finfo(FILEINFO_MIME_TYPE);

                    $mime = $fileInfo->file($photo['tmp_name']);

                    $allowedTypes = ['image/jpeg'=>'jpg','image/png'=>'png'];

                    $imageDetails = @getimagesize($photo['tmp_name']);

                    if (!is_string($mime) || !isset($allowedTypes[$mime]) || $imageDetails === false || ($imageDetails['mime'] ?? '') !== $mime) $errors[] = 'Only valid JPG and PNG images are allowed.';

                    else $extension = $allowedTypes[$mime];

                }

            }

        }

    }

    if (!$errors) {

        try {

            if ($extension !== null) {

                $uploadDirectory = __DIR__ . '/uploads';

                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) throw new RuntimeException('Could not create the uploads folder.');

                $filename = bin2hex(random_bytes(16)) . '.' . $extension;

                $imagePath = 'uploads/' . $filename;

                $savedFile = __DIR__ . '/' . $imagePath;

                if (!move_uploaded_file($photo['tmp_name'], $savedFile)) throw new RuntimeException('Could not save the uploaded photo.');

            }

            $statement = $pdo->prepare("INSERT INTO items (user_id,report_type,title,category,colour,description,location,event_date,image_path,status) VALUES (:user_id,'lost',:title,:category,:colour,:description,:location,:event_date,:image_path,'open')");

            $statement->execute(['user_id'=>$_SESSION['user_id'],'title'=>$values['title'],'category'=>$values['category'],'colour'=>$values['colour'] !== '' ? $values['colour'] : null,'description'=>$values['description'],'location'=>$values['location'],'event_date'=>$values['event_date'],'image_path'=>$imagePath]);

        } catch (Throwable $exception) {

            if ($savedFile !== null && is_file($savedFile) && !@unlink($savedFile)) error_log('Could not remove unused upload: ' . $savedFile);

            error_log($exception->getMessage());

            $errors[] = 'Could not save your report. Please try again.';

        }

        if (!$errors) { $_SESSION['lost_success'] = 'Your lost item report has been saved successfully!'; header('Location: report_lost.php'); exit; }

    }

}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Lost Item | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --field: #10182a;
            --border: #2b3650;
            --text: #edf2fc;
            --muted: #a4b1c8;
            --pink: #ec4899;
            --purple: #a78bfa;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 'Segoe UI', Arial, sans-serif;
        }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            padding: 15px max(20px, calc((100vw - 1000px) / 2));
            border-bottom: 1px solid var(--border);
            background: #101729;
        }
        .brand { font-size: 16px; font-weight: 700; color: var(--text); }
        .brand span { color: var(--pink); }
        .topbar nav { display: flex; flex-wrap: wrap; gap: 18px; }
        .wrap { max-width: 760px; margin: 28px auto 55px; padding: 0 18px; }
        h1 { font-size: 25px; margin: 0 0 5px; }
        .subtitle { color: var(--muted); margin: 0 0 20px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 23px;
        }
        .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        .full { grid-column: 1 / -1; }
        label { display: block; margin: 15px 0 6px; font-weight: 600; }
        input:not([type="hidden"]), select, textarea {
            width: 100%;
            min-width: 0;
            padding: 10px 11px;
            border: 1px solid #3b4861;
            border-radius: 6px;
            background: var(--field);
            color: var(--text);
            font: inherit;
        }
        input::placeholder, textarea::placeholder { color: #8795ae; }
        textarea { min-height: 105px; resize: vertical; }
        input[type="file"] { padding: 10px; }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--purple);
            outline-offset: 1px;
        }
        .hint { font-size: 12px; color: var(--muted); margin: 5px 0 0; }
        .submit {
            margin-top: 20px;
            padding: 11px 18px;
            border: 0;
            border-radius: 6px;
            background: var(--pink);
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .submit:hover { background: #db2777; }
        .notice { border-radius: 7px; padding: 12px 15px; margin-bottom: 16px; }
        .success { background: #11352e; color: #a7f3d0; border: 1px solid #28765c; }
        .errors { background: #3a1829; color: #fecdd3; border: 1px solid #9f3759; }
        .errors ul { margin: 5px 0 0; padding-left: 20px; }
        .back { display: inline-block; margin-top: 15px; }
        :focus-visible { outline: 2px solid var(--purple); outline-offset: 3px; }
        @media (max-width: 560px) {
            .fields { grid-template-columns: 1fr; }
            .card { padding: 17px; }
            .topbar { padding: 14px 18px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span>✦</span> Smart Campus Lost &amp; Found</a>
        <nav aria-label="Main navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="browse_items.php">Browse items</a>
            <a href="report_found.php">Report found</a>
        </nav>
    </header>

    <main class="wrap">
        <h1>Report a lost item</h1>
        <p class="subtitle">Provide the details of the item you lost.</p>

        <?php if ($success !== ''): ?>
            <div class="notice success" role="status"><?= escape($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="notice errors" role="alert">
                <strong>Please check the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card" aria-label="Lost item details">
            <form method="post" action="report_lost.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <div class="fields">
                    <div class="full">
                        <label for="title">Item title *</label>
                        <input type="text" id="title" name="title" maxlength="150"
                            placeholder="Example: Blue water bottle"
                            value="<?= escape($values['title']) ?>" required>
                    </div>
                    <div>
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= escape($category) ?>"
                                    <?= $values['category'] === $category ? 'selected' : '' ?>>
                                    <?= escape($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="colour">Colour (optional)</label>
                        <input type="text" id="colour" name="colour" maxlength="50"
                            placeholder="Example: Blue" value="<?= escape($values['colour']) ?>">
                    </div>
                    <div class="full">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" maxlength="3000"
                            placeholder="Describe the item, brand, size or distinguishing features"
                            aria-describedby="description-hint" required><?= escape($values['description']) ?></textarea>
                        <p class="hint" id="description-hint">Keep private ownership details for verification.</p>
                    </div>
                    <div>
                        <label for="location">Last seen location *</label>
                        <input type="text" id="location" name="location" maxlength="150"
                            placeholder="Example: Central Library"
                            value="<?= escape($values['location']) ?>" required>
                    </div>
                    <div>
                        <label for="event_date">Date lost *</label>
                        <input type="date" id="event_date" name="event_date" min="1000-01-01"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= escape($values['event_date']) ?>" required>
                    </div>
                    <div class="full">
                        <label for="photo">Item photo (optional)</label>
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png"
                            aria-describedby="photo-hint">
                        <p class="hint" id="photo-hint">JPG or PNG, up to 2 MB. Select the photo again if submission fails.</p>
                    </div>
                </div>
                <button class="submit" type="submit">Submit lost item report</button>
            </form>
        </section>
        <a class="back" href="dashboard.php">Back to dashboard</a>
    </main>
</body>
</html>
