<?php

session_start();

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Kolkata');

// Require login.

if (empty($_SESSION['user_id'])) {

    header('Location: login.php');

    exit;

}

// Check that the account still exists.

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

$categories = [

    'Electronics', 'ID Cards', 'Books', 'Keys',

    'Wallets', 'Bags', 'Clothing', 'Other'

];

$values = [

    'title' => '',

    'category' => '',

    'colour' => '',

    'description' => '',

    'location' => '',

    'event_date' => '',

    'storage_location' => ''

];

$errors = [];

$success = $_SESSION['found_success'] ?? '';

unset($_SESSION['found_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($values as $field => $value) {

        $values[$field] = is_string($_POST[$field] ?? null)

            ? trim($_POST[$field])

            : '';

    }

    $token = is_string($_POST['csrf_token'] ?? null)

        ? $_POST['csrf_token']

        : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {

        $errors[] =

            'The form expired or the upload was too large. '

            . 'Refresh and try again with a photo under 2 MB.';

    }

    // Validate required text fields.

    $textFields = [

        'title' => ['Item title', 150],

        'description' => ['Description', 3000],

        'location' => ['Location found', 150],

        'storage_location' => ['Current storage location', 150]

    ];

    foreach ($textFields as $field => [$label, $limit]) {

        if (

            $values[$field] === '' ||

            mb_strlen($values[$field]) > $limit

        ) {

            $errors[] = "$label is required and must be $limit characters or fewer.";

        }

    }

    if (!in_array($values['category'], $categories, true)) {

        $errors[] = 'Choose a valid category.';

    }

    if (mb_strlen($values['colour']) > 50) {

        $errors[] = 'Colour must be 50 characters or fewer.';

    }

    // Validate the date.

    $dateIsValid = false;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['event_date'])) {

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

    // Validate the optional photo.

    $photo = $_FILES['photo'] ?? null;

    $extension = null;

    $imagePath = null;

    $savedFile = null;

    if ($photo !== null) {

        if (

            !is_array($photo) ||

            !isset($photo['error']) ||

            !is_int($photo['error'])

        ) {

            $errors[] = 'Invalid photo upload.';

        } elseif ($photo['error'] !== UPLOAD_ERR_NO_FILE) {

            if ($photo['error'] !== UPLOAD_ERR_OK) {

                $errors[] = 'Photo upload failed. Choose a JPG or PNG under 2 MB.';

            } elseif (

                !isset($photo['tmp_name']) ||

                !is_string($photo['tmp_name']) ||

                !is_uploaded_file($photo['tmp_name'])

            ) {

                $errors[] = 'Invalid uploaded file.';

            } else {

                $size = filesize($photo['tmp_name']);

                if (

                    $size === false ||

                    $size === 0 ||

                    $size > 2 * 1024 * 1024

                ) {

                    $errors[] = 'The photo must be no larger than 2 MB.';

                } else {

                    $fileInfo = new finfo(FILEINFO_MIME_TYPE);

                    $mime = $fileInfo->file($photo['tmp_name']);

                    $imageDetails = @getimagesize($photo['tmp_name']);

                    $allowedTypes = [

                        'image/jpeg' => 'jpg',

                        'image/png' => 'png'

                    ];

                    if (

                        !is_string($mime) ||

                        !isset($allowedTypes[$mime]) ||

                        $imageDetails === false ||

                        ($imageDetails['mime'] ?? '') !== $mime

                    ) {

                        $errors[] = 'Only valid JPG and PNG images are allowed.';

                    } else {

                        $extension = $allowedTypes[$mime];

                    }

                }

            }

        }

    }

    if (!$errors) {

        try {

            if ($extension !== null) {

                $uploadDirectory = __DIR__ . '/uploads';

                if (

                    !is_dir($uploadDirectory) &&

                    !mkdir($uploadDirectory, 0755, true) &&

                    !is_dir($uploadDirectory)

                ) {

                    throw new RuntimeException('Cannot create uploads folder.');

                }

                $filename = bin2hex(random_bytes(16)) . '.' . $extension;

                $imagePath = 'uploads/' . $filename;

                $savedFile = __DIR__ . '/' . $imagePath;

                if (!move_uploaded_file($photo['tmp_name'], $savedFile)) {

                    throw new RuntimeException('Cannot save uploaded photo.');

                }

            }

            // Both lost and found reports use the items table.

            $statement = $pdo->prepare(

                "INSERT INTO items

                (

                    user_id, report_type, title, category, colour,

                    description, location, event_date, image_path,

                    storage_location, status

                )

                VALUES

                (

                    :user_id, 'found', :title, :category, :colour,

                    :description, :location, :event_date, :image_path,

                    :storage_location, 'open'

                )"

            );

            $statement->execute([

                'user_id' => $_SESSION['user_id'],

                'title' => $values['title'],

                'category' => $values['category'],

                'colour' => $values['colour'] !== ''

                    ? $values['colour'] : null,

                'description' => $values['description'],

                'location' => $values['location'],

                'event_date' => $values['event_date'],

                'image_path' => $imagePath,

                'storage_location' => $values['storage_location']

            ]);

        } catch (Throwable $exception) {

            if ($savedFile !== null && is_file($savedFile)) {

                if (!@unlink($savedFile)) {

                    error_log('Could not remove unused upload: ' . $savedFile);

                }

            }

            error_log($exception->getMessage());

            $errors[] = 'Could not save your report. Please try again.';

        }

        if (!$errors) {

            $_SESSION['found_success'] =

                'Your found item report has been saved successfully!';

            header('Location: report_found.php');

            exit;

        }

    }

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Found Item | Smart Campus Lost and Found</title>
    <style>
        :root {
            --bg: #080d1b;
            --panel: #141c30;
            --input: #101827;
            --border: #2a354b;
            --text: #edf2fa;
            --muted: #a1aec4;
            --pink: #ec4899;
            --purple: #a78bfa;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.5 'Segoe UI', Arial, sans-serif;
        }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .topbar {
            padding: 15px max(18px, calc((100% - 1020px) / 2));
            background: #101827;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .brand { font-weight: 700; color: var(--text); }
        .topbar nav { display: flex; flex-wrap: wrap; gap: 18px; font-size: 13px; }
        main { max-width: 790px; margin: 30px auto 60px; padding: 0 18px; }
        h1 { margin: 0 0 5px; font-size: 26px; }
        .intro { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
        }
        .fields { display: grid; grid-template-columns: 1fr 1fr; column-gap: 18px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-weight: 600; font-size: 13px; margin: 15px 0 6px; }
        input:not([type="hidden"]), select, textarea {
            display: block;
            width: 100%;
            min-width: 0;
            background: var(--input);
            border: 1px solid #39465f;
            color: var(--text);
            border-radius: 6px;
            padding: 10px 11px;
            font: inherit;
        }
        input::placeholder, textarea::placeholder { color: #8996ad; }
        input[type="file"] { padding: 10px; }
        select option { background: var(--input); }
        textarea { min-height: 100px; resize: vertical; }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--purple);
            outline-offset: 1px;
        }
        .hint { font-size: 12px; color: var(--muted); margin: 6px 0 0; }
        .submit {
            margin-top: 22px;
            background: var(--pink);
            color: white;
            border: 0;
            border-radius: 6px;
            padding: 11px 20px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .submit:hover { background: #db2777; }
        .notice { border-radius: 7px; padding: 12px 15px; margin-bottom: 15px; }
        .success { color: #a7f3d0; background: #103b32; border: 1px solid #276858; }
        .errors { color: #fecdd3; background: #421c2a; border: 1px solid #9f3a59; }
        .errors ul { margin: 7px 0 0; padding-left: 20px; }
        :focus-visible { outline: 2px solid var(--purple); outline-offset: 3px; }
        @media (max-width: 570px) {
            .fields { grid-template-columns: 1fr; }
            .panel { padding: 18px; }
            main { margin-top: 22px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="dashboard.php">Smart Campus Lost &amp; Found</a>
        <nav aria-label="Main navigation">
            <a href="dashboard.php">Dashboard</a>
            <a href="browse_items.php">Browse items</a>
            <a href="report_lost.php">Report lost</a>
        </nav>
    </header>

    <main>
        <h1>Report a found item</h1>
        <p class="intro">Provide the item details below. Colour and photo are optional.</p>

        <?php if ($success !== ''): ?>
            <div class="notice success" role="status">
                <?= escape($success) ?>
            </div>
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

        <section class="panel">
            <form method="post" action="report_found.php" enctype="multipart/form-data">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape($_SESSION['csrf_token']) ?>"
                >

                <div class="fields">
                    <div class="full">
                        <label for="title">Item title *</label>
                        <input
                            id="title"
                            name="title"
                            type="text"
                            maxlength="150"
                            placeholder="Example: Black umbrella"
                            value="<?= escape($values['title']) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= escape($category) ?>"
                                    <?= $values['category'] === $category ? 'selected' : '' ?>
                                ><?= escape($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="colour">Colour (optional)</label>
                        <input
                            id="colour"
                            name="colour"
                            type="text"
                            maxlength="50"
                            placeholder="Example: Black"
                            value="<?= escape($values['colour']) ?>"
                        >
                    </div>

                    <div class="full">
                        <label for="description">Description *</label>
                        <textarea
                            id="description"
                            name="description"
                            maxlength="3000"
                            aria-describedby="description-hint"
                            placeholder="Describe the item without revealing private ownership details"
                            required
                        ><?= escape($values['description']) ?></textarea>
                        <p class="hint" id="description-hint">
                            Keep private identifying details for ownership verification.
                        </p>
                    </div>

                    <div>
                        <label for="location">Location found *</label>
                        <input
                            id="location"
                            name="location"
                            type="text"
                            maxlength="150"
                            placeholder="Example: Outside Central Library"
                            value="<?= escape($values['location']) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="event_date">Date found *</label>
                        <input
                            id="event_date"
                            name="event_date"
                            type="date"
                            min="1000-01-01"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= escape($values['event_date']) ?>"
                            required
                        >
                    </div>

                    <div class="full">
                        <label for="storage_location">Where is the item currently kept? *</label>
                        <input
                            id="storage_location"
                            name="storage_location"
                            type="text"
                            maxlength="150"
                            placeholder="Example: Main gate security desk"
                            value="<?= escape($values['storage_location']) ?>"
                            aria-describedby="storage-hint"
                            required
                        >
                        <p class="hint" id="storage-hint">
                            Use a campus collection point or “With finder”. Avoid private addresses.
                        </p>
                    </div>

                    <div class="full">
                        <label for="photo">Item photo (optional)</label>
                        <input
                            id="photo"
                            name="photo"
                            type="file"
                            accept="image/jpeg,image/png"
                            aria-describedby="photo-hint"
                        >
                        <p class="hint" id="photo-hint">
                            JPG or PNG, maximum 2 MB. Select the photo again after a form error.
                        </p>
                    </div>
                </div>

                <button class="submit" type="submit">Submit found item report</button>
            </form>
        </section>
    </main>
</body>
</html>
