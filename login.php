<?php
session_start();
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null)
        ? strtolower(trim($_POST['email'])) : '';

    $password = is_string($_POST['password'] ?? null)
        ? $_POST['password'] : '';

    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Your session expired. Refresh this page and try again.';
    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        $error = 'Enter a valid email and password.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT id, password_hash
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Incorrect email or password.';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Login is temporarily unavailable. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Smart Campus Lost and Found</title>
    <style>
        :root {
            --background: #080d1b;
            --panel: #141c30;
            --border: #29354b;
            --text: #f1f5f9;
            --muted: #9aaac4;
            --pink: #ec4899;
            --purple: #a78bfa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: var(--background);
            color: var(--text);
            line-height: 1.5;
        }

        .header {
            padding: 22px 20px;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        .brand {
            color: var(--text);
            text-decoration: none;
            font-size: 17px;
            font-weight: 700;
        }

        .brand span {
            color: var(--pink);
            margin-right: 6px;
        }

        .page {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 74px);
            padding: 30px 16px;
        }

        .login-card {
            width: 100%;
            max-width: 390px;
            padding: 30px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        h1 {
            font-size: 25px;
            margin: 0 0 6px;
        }

        .subtitle {
            margin: 0 0 25px;
            color: var(--muted);
            font-size: 14px;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            font-size: 13px;
            font-weight: 600;
        }

        input:not([type="hidden"]) {
            width: 100%;
            padding: 11px 12px;
            background: #0d1526;
            color: var(--text);
            border: 1px solid #39465d;
            border-radius: 7px;
            font: inherit;
            font-size: 14px;
        }

        input::placeholder {
            color: #8190aa;
        }

        input:focus {
            border-color: var(--purple);
            outline: 2px solid rgba(167, 139, 250, 0.2);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 65px;
        }

        .show-password {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #c4b5fd;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            padding: 7px;
        }

        .login-button {
            width: 100%;
            margin-top: 24px;
            padding: 12px;
            background: var(--pink);
            border: 0;
            border-radius: 7px;
            color: white;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .login-button:hover {
            background: #db2777;
        }

        .register {
            margin: 22px 0 0;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        .register a {
            color: #c4b5fd;
            font-weight: 600;
            text-decoration: none;
        }

        .register a:hover {
            text-decoration: underline;
        }

        .error {
            padding: 11px 12px;
            border: 1px solid #be185d;
            border-radius: 7px;
            background: #3b142a;
            color: #fecdd3;
            font-size: 13px;
        }

        :focus-visible {
            outline: 2px solid var(--purple);
            outline-offset: 3px;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <a class="brand" href="index.php">
            <span>✦</span> Smart Campus Lost &amp; Found
        </a>
    </header>

    <main class="page">
        <section class="login-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Welcome back</h1>
            <p class="subtitle">Sign in to your campus account.</p>

            <?php if ($error !== ''): ?>
                <p class="error" role="alert">
                    <?= escape($error) ?>
                </p>
            <?php endif; ?>

            <form method="post" action="login.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escape($_SESSION['csrf_token']) ?>"
                >

                <label for="email">College email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your college email"
                    value="<?= escape($email) ?>"
                    autocomplete="username"
                    maxlength="150"
                    required
                >

                <label for="password">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        maxlength="72"
                        required
                    >
                    <button
                        type="button"
                        class="show-password"
                        id="togglePassword"
                        aria-controls="password"
                        aria-pressed="false"
                    >Show</button>
                </div>

                <button class="login-button" type="submit">
                    Sign in
                </button>
            </form>

            <p class="register">
                Don't have an account?
                <a href="register.php">Create account</a>
            </p>
        </section>
    </main>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');

        togglePassword.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';

            passwordInput.type = isHidden ? 'text' : 'password';
            togglePassword.textContent = isHidden ? 'Hide' : 'Show';
            togglePassword.setAttribute('aria-pressed', String(isHidden));
        });
    </script>
</body>
</html>
