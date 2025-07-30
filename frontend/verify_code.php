<?php
// C:\xampp\htdocs\heritagebank\frontend\verify_code.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

error_log("--- verify_code.php Start ---");
error_log("Session ID (verify_code.php entry): " . session_id()); // Added for clarity
error_log("Session Contents (verify_code.php entry): " . print_r($_SESSION, true)); // CRITICAL check on page entry

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

$message = '';
$message_type = '';

// Check for existing messages from the session (e.g., from login.php)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info';
    error_log("Verify_code.php: Displaying message from session: '" . $message . "' of type '" . $message_type . "'");
    unset($_SESSION['message']); // Clear message after displaying
    unset($_SESSION['message_type']);
}

// --- Session State Validation ---
error_log("Verify_code.php: Checking session state. auth_step: " . ($_SESSION['auth_step'] ?? 'N/A') . ", temp_user_id: " . ($_SESSION['temp_user_id'] ?? 'N/A') . ", email: " . ($_SESSION['email'] ?? 'N/A')); // Added detailed log
if (!isset($_SESSION['auth_step']) || $_SESSION['auth_step'] !== 'awaiting_2fa' || !isset($_SESSION['temp_user_id'])) {
    error_log("Verify_code.php: Invalid session state for 2FA. Redirecting to login. Reason: auth_step=" . ($_SESSION['auth_step'] ?? 'NOT SET') . ", temp_user_id=" . ($_SESSION['temp_user_id'] ?? 'NOT SET'));
    $_SESSION['message'] = "Your session has expired or is invalid. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . rtrim(BASE_URL, '/') . '/login');
    exit;
}

$user_id = $_SESSION['temp_user_id'];
// Use the email from the session for display. It should have been set in login.php
$user_email_display = $_SESSION['email'] ?? 'your email';
error_log("Verify_code.php: User ID from session: " . $user_id . ", Email for display: " . $user_email_display); // Added

// Try-catch block for database operations and general errors
try {
    $usersCollection = getCollection('users');
    // Fetch the user details using the temp_user_id from session
    $user = $usersCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($user_id)]);

    if (!$user) {
        error_log("Verify_code.php: User not found in DB for temp_user_id: " . $user_id . ". Clearing session and redirecting to login.");
        $_SESSION['message'] = "User data not found. Please log in again.";
        $_SESSION['message_type'] = "error";
        unset($_SESSION['auth_step']);
        unset($_SESSION['temp_user_id']);
        session_destroy(); // Destroy session if user not found for security
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
    $twoFactorEnabled = $user['two_factor_enabled'] ?? false;
    $twoFactorMethod = $user['two_factor_method'] ?? 'email';

    // IMPORTANT: If 2FA was enabled but somehow became disabled or method changed to 'none'
    // after the user initiated login, log them in directly.
    if (!$twoFactorEnabled || $twoFactorMethod === 'none') {
        error_log("Verify_code.php: User " . $user_id . " 2FA status changed or is none. Logging in directly.");
        $_SESSION['user_logged_in'] = true;
        $_SESSION['2fa_verified'] = true; // No 2FA, so consider it verified immediately
        // Also set the actual user_id in the session for the now-logged-in state
        $_SESSION['user_id'] = (string)$user['_id'];
        // Ensure other relevant user details are in session for full login
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['email'] = $user['email'] ?? '';
        $_SESSION['is_admin'] = $user['is_admin'] ?? false; // Only if applicable

        unset($_SESSION['auth_step']);
        unset($_SESSION['temp_user_id']);

        $redirect_path = ($_SESSION['is_admin'] ?? false) ? '/admin' : '/dashboard';
        header('Location: ' . BASE_URL . $redirect_path);
        exit;
    }

    // --- Handle POST request for 2FA code verification ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
        error_log("Verify_code.php: POST request received for code verification from user " . $user_id);
        $submittedCode = sanitize_input(trim($_POST['two_factor_code'] ?? ''));

        if (empty($submittedCode)) {
            $message = "Please enter the verification code.";
            $message_type = "error";
            error_log("Verify_code.php: Submitted 2FA code is empty.");
        } else {
            $storedCode = $user['two_factor_temp_code'] ?? null;
            $expiryTime = $user['two_factor_code_expiry'] ?? null;

            if ($storedCode === null || $expiryTime === null) {
                $message = "No pending verification code found or it has been cleared. Please try logging in again.";
                $message_type = "error";
                error_log("Verify_code.php: No stored 2FA code or expiry for user " . $user_id . ". Forcing re-login.");
                // If no code is pending in DB, force user to re-initiate login
                unset($_SESSION['auth_step']);
                unset($_SESSION['temp_user_id']);
                // It's crucial to add the message to session BEFORE redirecting if you want it displayed on login.php
                $_SESSION['message'] = $message;
                $_SESSION['message_type'] = $message_type;
                header('Location: ' . BASE_URL . '/login');
                exit;
            }

            $currentTime = new MongoDB\BSON\UTCDateTime(time() * 1000); // Current time in UTCDateTime object
            $expiryDateTime = $expiryTime->toDateTime(); // Convert BSON UTCDateTime to PHP DateTime
            $currentDateTime = $currentTime->toDateTime();

            error_log("Verify_code.php: Comparing. Stored Code: " . $storedCode . ", Submitted Code: " . $submittedCode);
            error_log("Verify_code.php: Expiry Time: " . $expiryDateTime->format('Y-m-d H:i:s') . ", Current Time: " . $currentDateTime->format('Y-m-d H:i:s'));

            if ($submittedCode === $storedCode && $currentDateTime < $expiryDateTime) {
                // Code is correct and not expired - SUCCESS!
                $_SESSION['user_logged_in'] = true;
                $_SESSION['2fa_verified'] = true;
                $_SESSION['user_id'] = (string)$user['_id']; // Store actual user ID in session for full access
                $_SESSION['first_name'] = $user['first_name'] ?? ''; // Example of storing more user data
                $_SESSION['email'] = $user['email'] ?? ''; // Keep actual email if needed for session
                $_SESSION['is_admin'] = $user['is_admin'] ?? false; // Only if applicable

                unset($_SESSION['auth_step']); // Clear the 2FA state
                unset($_SESSION['temp_user_id']); // Clear temp user ID

                // Clear the temporary code from the database for security
                $usersCollection->updateOne(
                    ['_id' => $user['_id']],
                    ['$unset' => ['two_factor_temp_code' => '', 'two_factor_code_expiry' => '']]
                );

                error_log("Verify_code.php: 2FA code verified successfully for user " . $user_id . ". Redirecting to dashboard/admin.");
                $redirect_path = ($_SESSION['is_admin'] ?? false) ? '/admin' : '/dashboard';
            header('Location: ' . rtrim(BASE_URL, '/') . $redirect_path);
                exit;
            } else {
                // Code is incorrect or expired
                if ($currentDateTime >= $expiryDateTime) {
                    $message = "Verification code has expired. Please try logging in again to generate a new code.";
                    error_log("Verify_code.php: Code expired for user " . $user_id . ".");
                    // Optionally, clear the expired code from the DB here
                    $usersCollection->updateOne(
                        ['_id' => $user['_id']],
                        ['$unset' => ['two_factor_temp_code' => '', 'two_factor_code_expiry' => '']]
                    );
                    // If code expired, force re-initiate login
                    unset($_SESSION['auth_step']);
                    unset($_SESSION['temp_user_id']);
                    $_SESSION['message'] = $message;
                    $_SESSION['message_type'] = "error";
                    header('Location: ' . BASE_URL . '/login');
                    exit;
                } else {
                    $message = "Invalid verification code. Please try again.";
                    error_log("Verify_code.php: Invalid code submitted for user " . $user_id . ".");
                }
                $message_type = "error";
            }
        }
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("Verify_code.php: MongoDB Error: " . $e->getMessage());
    $message = "A database error occurred. Please try again later.";
    $message_type = "error";
    // For critical DB errors, redirect to login as state might be inconsistent
    unset($_SESSION['auth_step']);
    unset($_SESSION['temp_user_id']);
    session_destroy();
    $_SESSION['message'] = $message; // Store message for login.php
    $_SESSION['message_type'] = $message_type;
    header('Location: ' . BASE_URL . '/login');
    exit;
} catch (Exception $e) {
    error_log("Verify_code.php: General Error: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again.";
    $message_type = "error";
    // For general errors, redirect to login
    unset($_SESSION['auth_step']);
    unset($_SESSION['temp_user_id']);
    session_destroy();
    $_SESSION['message'] = $message; // Store message for login.php
    $_SESSION['message_type'] = $message_type;
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// Helper function to mask email for display (if not already in functions.php)
// This should ideally be in functions.php
if (!function_exists('maskEmail')) {
    function maskEmail($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email; // Return as is if not a valid email format
        }
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        // Mask half of the name
        $masked_name = substr($name, 0, floor(strlen($name) / 2)) . str_repeat('*', ceil(strlen($name) / 2));

        // Mask half of the domain before the dot
        $domain_parts = explode('.', $domain);
        $top_level_domain = array_pop($domain_parts); // e.g., 'com', 'org'
        $main_domain = implode('.', $domain_parts); // e.g., 'example'

        $masked_main_domain = substr($main_domain, 0, floor(strlen($main_domain) / 2)) . str_repeat('*', ceil(strlen($main_domain) / 2));

        return $masked_name . '@' . $masked_main_domain . '.' . $top_level_domain;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - Verify Code</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="background-container"></div>
    <div class="login-card-container">
        <div class="login-card">
            <div class="bank-logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
            </div>

            <h2 class="form-title">Enter Verification Code</h2>
            <p class="form-description">A verification code has been sent to: <strong><?php echo htmlspecialchars(maskEmail($user_email_display)); ?></strong>.</p>
            <p class="form-description small-text">Please enter the code below to complete your login. This code is valid for <?php echo TWO_FACTOR_CODE_EXPIRY_MINUTES; ?> minutes.</p>

            <?php if (!empty($message)): ?>
                <div id="login-message" class="message-box <?php echo htmlspecialchars($message_type); ?>" style="display: block;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div id="login-message" class="message-box" style="display: none;"></div>
            <?php endif; ?>

                <form class="login-form" id="verifyCodeForm" action="<?php echo rtrim(BASE_URL, '/'); ?>/verify_code" method="POST">
                <div class="form-group">
                    <label for="two_factor_code" class="sr-only">Verification Code</label>
                    <p class="input-label">Verification Code</p>
                    <div class="input-wrapper">
                        <input type="text" id="two_factor_code" name="two_factor_code" required pattern="\d{<?php echo TWO_FACTOR_CODE_LENGTH; ?>}" title="Please enter the <?php echo TWO_FACTOR_CODE_LENGTH; ?> digit code." maxlength="<?php echo TWO_FACTOR_CODE_LENGTH; ?>">
                    </div>
                </div>

                <div class="buttons-group">
                    <button type="submit" name="verify_code" class="btn btn-primary">Verify Code</button>
                <a href="<?php echo rtrim(BASE_URL, '/'); ?>/login" class="btn btn-secondary cancel-button">Cancel Login</a>
                </div>
            </form>

            <div class="resend-code-section">
                <p>Didn't receive the code?</p>
                <form action="<?php echo rtrim(BASE_URL, '/'); ?>/resend_code" method="POST">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
                    <button type="submit" name="resend_code" class="btn btn-link">Resend Code</button>
                </form>
            </div>

        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> HomeTown Bank Pa. All rights reserved.</p>
        <div class="footer-links">
            <a href="#">Privacy Policy</a> <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>