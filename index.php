
<?php
session_start();

// Redirect logged-in users to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Smart Campus Lost & Found</title>

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #060b18;
            color: #ffffff;
            overflow-x: hidden;
        }

        /* Header */

        .site-header {
            position: relative;
            z-index: 10;
            width: 100%;
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .header-inner {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
        }

        .brand {
            color: #ffffff;
            font-size: 21px;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .brand-symbol {
            color: #ff4da6;
            margin-right: 10px;
        }

        /* Main Welcome Section */

        .hero {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 85px);
            padding: 60px 20px;
            text-align: center;
            overflow: hidden;
            isolation: isolate;
        }

        /* Background Glow */

        .hero::before {
            content: "";
            position: absolute;
            width: 600px;
            height: 600px;
            left: -250px;
            bottom: -300px;
            border-radius: 50%;
            background: radial-gradient(
                circle,
                rgba(236, 72, 153, 0.25),
                transparent 70%
            );
            z-index: -1;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 700px;
            height: 700px;
            right: -300px;
            top: -250px;
            border-radius: 50%;
            background: radial-gradient(
                circle,
                rgba(37, 99, 235, 0.25),
                transparent 70%
            );
            z-index: -1;
        }

        .welcome-label {
            display: inline-block;
            padding: 10px 20px;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.05);
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(48px, 8vw, 100px);
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -3px;
        }

        .gradient-text {
            display: block;
            background: linear-gradient(
                90deg,
                #ff4da6,
                #a78bfa,
                #60a5fa
            );
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero-description {
            max-width: 600px;
            margin: 30px auto 0;
            color: #94a3b8;
            font-size: 18px;
            line-height: 1.8;
        }

        /* Buttons */

        .hero-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-top: 40px;
        }

        .button {
            display: inline-block;
            min-width: 170px;
            padding: 15px 28px;
            border-radius: 9px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .button-primary {
            background: #ec4899;
            color: white;
        }

        .button-primary:hover {
            background: #db2777;
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(236, 72, 153, 0.25);
        }

        .button-secondary {
            background: transparent;
            color: white;
            border: 1px solid #475569;
        }

        .button-secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #94a3b8;
            transform: translateY(-3px);
        }

        /* Decorative Wave */

        .wave {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            width: 70%;
            max-width: 800px;
            height: 100px;
            opacity: 0.25;
            pointer-events: none;
        }

        .wave-line {
            width: 100%;
            height: 100%;
            border-top: 2px solid #a78bfa;
            border-radius: 50%;
            transform: perspective(300px) rotateX(60deg);
        }

        /* Accessibility */

        :focus-visible {
            outline: 3px solid #facc15;
            outline-offset: 4px;
        }

        /* Mobile Responsive */

        @media (max-width: 768px) {
            .site-header {
                padding: 20px 15px;
            }

            .brand {
                font-size: 17px;
            }

            .hero {
                min-height: calc(100vh - 70px);
                padding: 70px 20px;
            }

            .hero h1 {
                font-size: clamp(42px, 11vw, 65px);
                letter-spacing: -1px;
            }

            .hero-description {
                font-size: 16px;
            }

            .hero-actions {
                flex-direction: column;
                width: 100%;
            }

            .button {
                width: 100%;
                max-width: 300px;
            }

            .wave {
                display: none;
            }
        }
    </style>
</head>

<body>

    <!-- Centered Header -->

    <header class="site-header">

        <div class="header-inner">

            <a class="brand" href="index.php">

                <span class="brand-symbol">
                    ✦
                </span>

                Smart Campus Lost & Found

            </a>

        </div>

    </header>

    <!-- Welcome Section -->

    <main>

        <section class="hero">

            <span class="welcome-label">
                Welcome to Your Campus Portal
            </span>

            <h1>
                Lost Something?

                <span class="gradient-text">
                    Find It Together.
                </span>
            </h1>

            <p class="hero-description">
                Your trusted campus platform to report lost
                belongings, discover found items, and
                reconnect with what matters.
            </p>

            <div class="hero-actions">

                <a
                    href="login.php"
                    class="button button-primary"
                >
                    Log In
                </a>

                <a
                    href="register.php"
                    class="button button-secondary"
                >
                    Create Account
                </a>

            </div>

            <div class="wave" aria-hidden="true">
                <div class="wave-line"></div>
            </div>

        </section>

    </main>

</body>

</html>
