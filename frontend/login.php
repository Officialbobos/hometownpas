<?php
// C:\xampp\htdocs\heritagebank\frontend\login.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure session is started at the very beginning
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

error_log("--- login.php Start ---");
error_log("Session ID: " . session_id());
error_log("Session Contents (login.php initial load): " . print_r($_SESSION, true)); // CRITICAL check

// If user is already logged in (2FA previously verified), redirect to dashboard
// IMPORTANT: This check should happen AFTER session_start()
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    error_log("login.php: User already logged in (user_logged_in is true). Redirecting to dashboard.");
    $redirect_path = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '/admin' : '/dashboard';
    header('Location: ' . BASE_URL . $redirect_path);
    exit;
}

// --- Start Dotenv loading (conditional for deployment) ---
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    try {
        $dotenv->load();
    } catch (Dotenv\Exception\InvalidPathException $e) {
        error_log("Dotenv load error locally on path " . $dotenvPath . ": " . $e->getMessage());
    }
}
// --- End Dotenv loading ---

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;
use OTPHP\TOTP; // For potential 2FA secret generation or validation

$message = '';
$message_type = '';

// Check for messages from other pages (e.g., from verify_code.php if it redirects back due to session issues)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message']); // Clear message after displaying
    unset($_SESSION['message_type']);
}


// --- Handle Login Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    error_log("login.php: POST request received for login.");
    error_log("Session Contents (login.php during POST): " . print_r($_SESSION, true)); // CRITICAL check

    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Passwords should not be sanitized with htmlspecialchars or similar.

    if (empty($email) || empty($password)) {
        $message = 'Please enter both email and password.';
        $message_type = 'error';
        error_log("Login attempt failed: Empty email or password.");
    } else {
        try {
            $usersCollection = getCollection('users');
            $user = $usersCollection->findOne(['email' => $email]);
            error_log("Login attempt for email: " . $email . ". User found: " . ($user ? 'YES' : 'NO'));

            if ($user && password_verify($password, $user['password'])) {
                error_log("Password verified for user: " . $email);

                // Check for 2FA status
                $two_factor_enabled = $user['two_factor_enabled'] ?? false;
                $two_factor_method = $user['two_factor_method'] ?? 'email'; // Default to email if not set

                error_log("2FA Status for user " . $email . ": Enabled=" . ($two_factor_enabled ? 'true' : 'false') . ", Method=" . $two_factor_method);

                if ($two_factor_enabled) {
                    // Store temporary user ID and authentication step in session
                    $_SESSION['auth_step'] = 'awaiting_2fa';
                    $_SESSION['temp_user_id'] = (string)$user['_id']; // Store as string for ObjectId conversion later
                    error_log("Set SESSION: auth_step='awaiting_2fa', temp_user_id='" . $_SESSION['temp_user_id'] . "'");
                    error_log("Session Contents (login.php BEFORE 2FA redirect): " . print_r($_SESSION, true)); // CRITICAL check

                    // If 2FA method is email, generate and send code
                    if ($two_factor_method === 'email') {
                        $verification_code = str_pad(random_int(0, (10**TWO_FACTOR_CODE_LENGTH) - 1), TWO_FACTOR_CODE_LENGTH, '0', STR_PAD_LEFT);
                        $expiry_time = new UTCDateTime(strtotime('+' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes') * 1000);

                        // Save temporary code and expiry to the user's document
                        $usersCollection->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => [
                                'two_factor_temp_code' => $verification_code,
                                'two_factor_code_expiry' => $expiry_time
                            ]]
                        );
                        error_log("Generated and saved email 2FA code: " . $verification_code . " for user " . $email);

                        $email_subject = "HomeTown Bank Login Verification Code";
                        $user_full_name = htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
                        $email_body_html = "
                            <!DOCTYPE html>
                            <html lang='en'>
                            <head>
                                <meta charset='UTF-8'>
                                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                                <title>HomeTown Bank - Verification Code</title>
                                <style>
                                    body { font-family: 'Roboto', sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                                    .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.05); overflow: hidden; }
                                    .header { background-color: #0056b3; padding: 20px; text-align: center; color: #ffffff; font-size: 24px; font-weight: bold; }
                                    .content { padding: 30px; color: #333333; line-height: 1.6; text-align: center; }
                                    .content p { margin-bottom: 15px; }
                                    .verification-code { display: inline-block; background-color: #e6f0fa; color: #0056b3; font-size: 32px; font-weight: bold; padding: 15px 30px; border-radius: 5px; margin: 20px 0; letter-spacing: 3px; text-align: center; }
                                    .expiry-info { font-size: 0.9em; color: #777777; margin-top: 20px; }
                                    .footer { background-color: #f0f0f0; padding: 20px; text-align: center; font-size: 0.85em; color: #666666; border-top: 1px solid #eeeeee; }
                                </style>
                            </head>
                            <body>
                                <div class='email-container'>
                                    <div class='header'>
                                        HomeTown Bank
                                    </div>
                                    <div class='content'>
                                        <p>Dear " . $user_full_name . ",</p>
                                        <p>Your verification code for HomeTown Bank Online Banking is:</p>
                                        <div class='verification-code'>" . htmlspecialchars($verification_code) . "</div>
                                        <p class='expiry-info'>This code is valid for <strong>" . TWO_FACTOR_CODE_EXPIRY_MINUTES . " minutes</strong> and should be entered on the verification page.</p>
                                        <p>If you did not attempt to log in, please ignore this email or contact us immediately if you suspect unauthorized activity on your account.</p>
                                        <p>For security reasons, do not share this code with anyone.</p>
                                    </div>
                                    <div class='footer'>
                                        <p>&copy; " . date('Y') . " HomeTown Bank. All rights reserved.</p>
                                        <p>This is an automated email, please do not reply.</p>
                                    </div>
                                </div>
                            </body>
                            </html>";

                        $email_sent = sendEmail($email, $email_subject, $email_body_html, "Your verification code for HomeTown Bank is: " . $verification_code);

                        if (!$email_sent) {
                            error_log("Failed to send 2FA email to " . $email . ". Check mailer config/logs.");
                            // Decide if you want to block login on email failure or let them proceed to verify page to resend
                            // For security, usually block or show error until email can be sent.
                            $message = "Failed to send verification email. Please try again or contact support.";
                            $message_type = 'error';
                            // Clear temp session state if we block the user here
                            unset($_SESSION['auth_step']);
                            unset($_SESSION['temp_user_id']);
                            header('Location: ' . BASE_URL . '/login'); // Redirect back to login with error
                            exit;
                        }
                    } // If Authenticator, no code is sent via email, user generates from app.

                    error_log("Redirecting to verify_code after successful 2FA initiation for user: " . $email);
                    header('Location: ' . BASE_URL . '/verify_code');
                    exit;

                } else {
                    // No 2FA enabled, log user in directly
                    error_log("2FA not enabled for user " . $email . ". Logging in directly.");
                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_id'] = (string)$user['_id'];
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['last_name'] = $user['last_name'] ?? '';
                    $_SESSION['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $_SESSION['is_admin'] = $user['is_admin'] ?? false;
                    $_SESSION['two_factor_enabled'] = $two_factor_enabled; // Store their actual 2FA setting
                    $_SESSION['two_factor_method'] = $two_factor_method;
                    $_SESSION['role'] = ($user['is_admin'] ?? false) ? 'admin' : 'user';
                    $_SESSION['2fa_verified'] = true; // Mark as true if 2FA is not enabled

                    error_log("Session Contents (login.php AFTER direct login): " . print_r($_SESSION, true)); // CRITICAL check

                    $redirect_path = (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ? '/admin' : '/dashboard';
                    error_log("Redirecting to " . BASE_URL . $redirect_path . " after direct login.");
                    header('Location: ' . BASE_URL . $redirect_path);
                    exit;
                }
            } else {
                $message = 'Invalid email or password.';
                $message_type = 'error';
                error_log("Login attempt failed: Invalid email or password for " . $email);
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            $message = "A database error occurred. Please try again later.";
            $message_type = 'error';
            error_log("MongoDB error during login: " . $e->getMessage());
        } catch (Exception $e) {
            $message = "An unexpected error occurred. Please try again.";
            $message_type = 'error';
            error_log("General error during login: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - Login</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body.login-page {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f7f6;
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }

        .login-container {
            background-color: #fff;
            padding: 40px 50px;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            box-sizing: border-box;
            text-align: center;
        }

        .login-container h1 {
            font-size: 2em;
            color: #333;
            margin-bottom: 30px;
        }

        .login-container .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .login-container label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9em;
            color: #333;
            font-weight: bold;
        }

        .login-container input[type="email"],
        .login-container input[type="password"] {
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 12px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
        }

        .login-container input[type="email"]:focus,
        .login-container input[type="password"]:focus {
            border-color: #0056b3;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 86, 179, 0.2);
        }

        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease;
            margin-top: 10px;
        }

        .button-primary:hover {
            background-color: #0056b3;
        }

        .forgot-password {
            margin-top: 15px;
            font-size: 0.9em;
        }

        .forgot-password a {
            color: #0056b3;
            text-decoration: none;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        .register-link {
            margin-top: 25px;
            font-size: 0.9em;
            color: #555;
        }

        .register-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }

        .register-link a:hover {
            text-decoration: underline;
        }
        
        /* Message styling from main style.css (or define here if not global) */
        .message {
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 0.95em;
            text-align: center;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <h1>Login to Your Account</h1>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form action="<?php echo BASE_URL; ?>/login" method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" name="login" class="button-primary">Login</button>
        </form>

        <div class="forgot-password">
            <a href="<?php echo BASE_URL; ?>/forgot_password">Forgot Password?</a>
        </div>
        <div class="register-link">
            Don't have an account? <a href="<?php echo BASE_URL; ?>/register">Register Now</a>
        </div>
    </div>
</body>
</html>