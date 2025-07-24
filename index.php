<?php
echo "INDEX_LOADED_V_FINAL"; // Add this line
session_start();

// Enable error reporting for development (remove/adjust for production)
// These settings are also controlled by APP_DEBUG in Config.php, but can be set here temporarily for debugging.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Load Composer's autoloader first. This makes Dotenv and PHPMailer available.
require_once __DIR__ . '/vendor/autoload.php';

// 2. Include your database and environment variable configuration.
// This file is the SINGLE SOURCE OF TRUTH for loading .env and defining constants.
require_once 'Config.php'; // Config.php handles Dotenv loading and constant definitions

// Check if critical constants are defined after Config.php is included
if (!defined('MONGODB_CONNECTION_URI') || !defined('MONGODB_DB_NAME')) {
    error_log("FATAL ERROR: MongoDB configuration constants are not defined after Config.php inclusion.");
    die("System error: Database configuration missing. Please contact support.");
}

// 3. Include your functions file.
require_once 'functions.php'; // Ensure hideEmailPartially is in here now.

// Include MongoDB Client and ObjectId for database operations
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

$message = '';
$message_type = ''; // 'success', 'error', 'warning'

// If user is already logged in (session is active)
if (isset($_SESSION['user_id'])) {
    // Redirect based on role if needed, or always to dashboard
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
        header('Location: admin/dashboard.php'); // Admin dashboard
    } else {
        header('Location: frontend/dashboard.php'); // User dashboard <--- Corrected this line
    }
    exit();
}

// Handle login form submission
if (isset($_POST['login'])) {
    $last_name = sanitize_input($_POST['last_name'] ?? '');
    $membership_number = sanitize_input($_POST['membership_number'] ?? '');

    if (empty($last_name) || empty($membership_number)) {
        $message = "Please enter both last name and membership number.";
        $message_type = 'error';
    } else {
        try {
            $usersCollection = getCollection('users');

            // Find user by membership number and last name
            $user = $usersCollection->findOne([
                'membership_number' => $membership_number,
                'last_name' => $last_name,
                'status' => 'active' // Ensure only active users can log in
            ]);

            if ($user) {
                // User found, now check 2FA status
                if (isset($user['two_factor_enabled']) && $user['two_factor_enabled'] === true) {
                    // 2FA is enabled, initiate 2FA verification

                    // Generate a 2FA code (e.g., 6-digit number)
                    $two_factor_code = str_pad(random_int(0, (10**TWO_FACTOR_CODE_LENGTH) - 1), TWO_FACTOR_CODE_LENGTH, '0', STR_PAD_LEFT);
                    $expiry_time = new UTCDateTime(strtotime('+' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes') * 1000);

                    // Store the code and expiry in the database for verification
                    $updateResult = $usersCollection->updateOne(
                        ['_id' => $user['_id']],
                        ['$set' => [
                            'two_factor_temp_code' => $two_factor_code,
                            'two_factor_code_expiry' => $expiry_time
                        ]]
                    );

                    if ($updateResult->getModifiedCount() === 0) {
                        throw new Exception("Failed to save 2FA code for user.");
                    }

                    // Send the 2FA code via email
                    $user_email = $user['email'] ?? null;
                    $user_full_name = $user['full_name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

                    if ($user_email && sendEmail(
                        $user_email,
                        'Your Two-Factor Authentication Code',
                        '
                        <div style="font-family: Arial, sans-serif; line-height: 1.6;">
                            <h2>Two-Factor Authentication Code</h2>
                            <p>Dear ' . htmlspecialchars($user_full_name) . ',</p>
                            <p>You have attempted to log in to your HomeTown Bank account.</p> <--- Consistent name
                            <p>Your verification code is: <strong>' . htmlspecialchars($two_factor_code) . '</strong></p>
                            <p>This code is valid for ' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes.</p>
                            <p>Please enter this code on the login screen to complete your sign-in.</p>
                            <p>If you did not attempt to log in, please secure your account immediately or contact support.</p>
                            <p>Thank you,<br>HomeTown Bank Pa. Security Team</p> <--- Consistent name
                        </div>
                        '
                    )) {
                        // Store necessary info in session to carry over to verify_code.php
                        $_SESSION['temp_user_id'] = (string)$user['_id']; // Temp ID for 2FA verification
                        $_SESSION['auth_step'] = 'awaiting_2fa';
                        $_SESSION['2fa_email_sent_to'] = hideEmailPartially($user_email); // For display

                        header('Location: frontend/verify_code.php'); // Redirect to the 2FA verification page
                        exit();
                    } else {
                        $message = "Failed to send 2FA code. Please try again or contact support.";
                        $message_type = 'error';
                        error_log("2FA: Failed to send email to " . $user_email . " for user " . (string)$user['_id']);
                    }

                } else {
                    // 2FA is NOT enabled for this user, proceed with direct login
                    $_SESSION['user_id'] = (string)$user['_id']; // Store as string for session
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['last_name'] = $user['last_name'] ?? '';
                    $_SESSION['full_name'] = $user['full_name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $_SESSION['email'] = $user['email'] ?? '';
                    $_SESSION['is_admin'] = $user['is_admin'] ?? false;

                    // Redirect to dashboard or admin panel based on role
                    if ($_SESSION['is_admin']) {
                        header('Location: admin/dashboard.php');
                    } else {
                        header('Location: frontend/dashboard.php');
                    }
                    exit();
                }

            } else {
                $message = "Invalid last name or membership number. Please try again.";
                $message_type = 'error';
            }
        } catch (Exception $e) {
            error_log("User login error: " . $e->getMessage());
            $message = "An unexpected error occurred during login. Please try again later.";
            $message_type = 'error';
        }
    }
}

// The hideEmailPartially function should be moved to functions.php
// /**
//  * Hides part of an email address for display purposes (e.g., example@domain.com -> e*****e@domain.com)
//  * @param string $email The email address.
//  * @return string The partially hidden email.
//  */
// function hideEmailPartially(string $email): string {
//     if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
//         return "Invalid Email"; // Or just return the original if it's not a valid email format
//     }
//     $parts = explode('@', $email);
//     $name = $parts[0];
//     $domain = $parts[1];

//     if (strlen($name) > 3) {
//         $name = substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1);
//     } else if (strlen($name) > 0) {
//         $name = str_repeat('*', strlen($name));
//     }

//     return $name . '@' . $domain;
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - User Login</title> <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="background-container">
    </div>

    <div class="login-card-container">
        <div class="login-card">
            <div class="bank-logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo"> </div>

            <?php if (!empty($message)): ?>
                <div id="login-message" class="message-box <?php echo htmlspecialchars($message_type); ?>" style="display: block;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div id="login-message" class="message-box" style="display: none;"></div>
            <?php endif; ?>

            <form class="login-form" id="loginForm" action="index.php" method="POST">
                <div class="form-group username-group">
                    <label for="last_name" class="sr-only">Last Name</label>
                    <p class="input-label">Last Name</p>
                    <div class="input-wrapper">
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-group password-group">
                    <label for="membership_number" class="sr-only">Membership Number</label>
                    <p class="input-label">Membership Number</p>
                    <div class="input-wrapper">
                        <input type="text" id="membership_number" name="membership_number" placeholder="" required pattern="\d{12}" title="Membership number must be 12 digits" value="<?php echo htmlspecialchars($_POST['membership_number'] ?? ''); ?>">
                    </div>
                    <a href="#" class="forgot-password-link">Forgot?</a>
                </div>

                <div class="buttons-group">
                    <button type="submit" name="login" class="btn btn-primary">Sign in</button>
                </div>
            </form>

            </div>
    </div>

    <footer>
        <p>&copy; 2025 HomeTown Bank Pa. All rights reserved.</p> <div class="footer-links">
            <a href="heritagebank_admin/index.php">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>