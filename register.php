<?php
session_start();
require_once __DIR__ . '/db.php';

$errors = [];
$success = '';
$name = '';
$email = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'This form has expired. Refresh the page and try again.';
    }
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Enter your name using 1–100 characters.';
    }
    if (
        strlen($email) > 150 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !str_ends_with($email, '@learner.manipal.edu')
    ) {
        $errors[] = 'Enter a valid @learner.manipal.edu email address.';
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Your password must contain 8–72 bytes (8–72 simple English characters).';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $statement = $pdo->prepare(
                "INSERT INTO users (full_name, email, password_hash, role)
                 VALUES (:full_name, :email, :password_hash, 'student')"
            );
            $statement->execute([
                'full_name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash
            ]);
            $_SESSION['registration_success'] =
                'Account created successfully! Email verification is still pending.';
            header('Location: register.php');
            exit;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                $errors[] = 'An account with this email already exists.';
            } else {
                error_log($error->getMessage());
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

if (isset($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Smart Campus Lost and Found</title>
    <style>
        :root {
            --background: #080d1b;
            --panel: #141c30;
            --field: #0e1628;
            --border: #303b52;
            --text: #f1f5f9;
            --muted: #a5b4c8;
            --pink: #ec4899;
            --purple: #c4b5fd;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--background);
            color: var(--text);
            font-family: 'Segoe UI', Arial, sans-serif;
            line-height: 1.5;
        }

        .site-header {
            padding: 20px 16px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            color: var(--text);
            font-size: 18px;
            font-weight: 700;
            text-decoration: none;
        }

        .brand-symbol {
            color: var(--pink);
        }

        main {
            width: min(100% - 32px, 430px);
            margin: 38px auto 50px;
        }

        .register-card {
            padding: 27px 29px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        h1 {
            margin: 0 0 5px;
            font-size: 25px;
            line-height: 1.3;
        }

        .subtitle {
            margin: 0 0 19px;
            color: var(--muted);
            font-size: 13px;
        }

        label {
            display: block;
            margin: 15px 0 6px;
            font-size: 13px;
            font-weight: 600;
        }

        input:not([type="hidden"]) {
            display: block;
            width: 100%;
            padding: 11px 12px;
            background: var(--field);
            border: 1px solid var(--border);
            border-radius: 7px;
            color: var(--text);
            font: inherit;
            font-size: 14px;
        }

        input::placeholder {
            color: #8290a7;
        }

        input:focus-visible,
        button:focus-visible,
        a:focus-visible {
            outline: 2px solid var(--purple);
            outline-offset: 3px;
        }

        .hint {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 66px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 7px;
            transform: translateY(-50%);
            padding: 6px;
            border: 0;
            background: transparent;
            color: var(--purple);
            font: inherit;
            font-size: 12px;
            cursor: pointer;
        }

        .submit-button {
            display: block;
            width: 100%;
            margin-top: 22px;
            padding: 11px 15px;
            border: 0;
            border-radius: 7px;
            background: var(--pink);
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .submit-button:hover {
            background: #db2777;
        }

        .login-link {
            margin: 19px 0 0;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .login-link a {
            color: var(--purple);
            font-weight: 600;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .error,
        .success {
            padding: 11px 13px;
            border-radius: 7px;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .error {
            border: 1px solid #be4567;
            background: #351a2a;
            color: #fecdd3;
        }

        .success {
            border: 1px solid #26856c;
            background: #10392f;
            color: #bbf7d0;
        }

        .error ul {
            margin-bottom: 0;
            padding-left: 20px;
        }

        @media (max-width: 480px) {
            main {
                margin-top: 22px;
            }

            .register-card {
                padding: 22px 19px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-symbol">✦</span>
            Smart Campus Lost &amp; Found
        </a>
    </header>

    <main>
        <section class="register-card" aria-labelledby="register-heading">
            <h1 id="register-heading">Create account</h1>
            <p class="subtitle">Register with your college email.</p>

            <?php if ($errors): ?>
                <div class="error" role="alert">
                    <strong>Please correct the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <p class="success" role="status">
                    <?= escape($success) ?>
                </p>
            <?php endif; ?>

            <form method="post" action="register.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape($_SESSION['csrf_token']) ?>"
                >

                <label for="full_name">Full name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="100"
                    autocomplete="name"
                    placeholder="Enter your full name"
                    value="<?= escape($name) ?>"
                    required
                >

                <label for="email">College email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="150"
                    autocomplete="email"
                    placeholder="name@learner.manipal.edu"
                    aria-describedby="email-hint"
                    value="<?= escape($email) ?>"
                    required
                >
                <p class="hint" id="email-hint">
                    Only @learner.manipal.edu addresses are accepted.
                </p>

                <label for="password">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        placeholder="Create a password"
                        aria-describedby="password-hint"
                        required
                    >
                    <button
                        type="button"
                        class="toggle-password"
                        data-target="password"
                        aria-controls="password"
                        aria-pressed="false"
                    >Show</button>
                </div>
                <p class="hint" id="password-hint">
                    Use 8–72 bytes. English letters, numbers and symbols work best.
                </p>

                <label for="confirm_password">Confirm password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        maxlength="72"
                        autocomplete="new-password"
                        placeholder="Re-enter your password"
                        required
                    >
                    <button
                        type="button"
                        class="toggle-password"
                        data-target="confirm_password"
                        aria-controls="confirm_password"
                        aria-pressed="false"
                    >Show</button>
                </div>

                <button class="submit-button" type="submit">
                    Create account
                </button>
            </form>

            <p class="login-link">
                Already have an account?
                <a href="login.php">Sign in</a>
            </p>
        </section>
    </main>

    <script>
        document.querySelectorAll('.toggle-password').forEach(function (button) {
            button.addEventListener('click', function () {
                const input = document.getElementById(button.dataset.target);
                const showPassword = input.type === 'password';

                input.type = showPassword ? 'text' : 'password';
                button.textContent = showPassword ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', String(showPassword));
            });
        });
    </script>
</body>
</html>
