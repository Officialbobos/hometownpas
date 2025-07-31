<?php
// This is frontend/login.php

// Start output buffering as the very first thing.
// This captures any output (including PHP notices/warnings)
// so that header() calls can be made without the "headers already sent" error.
ob_start();

// session_start() is being called from index.php already, so we don't call it here.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// This error_log line is helpful for debugging and won't cause "headers already sent" due to ob_start()
error_log("--- login.php Start ---");
error_log("Session ID: " . session_id());
error_log("Session Contents (login.php initial load): " . print_r($_SESSION, true));

require_once __DIR__ . '/../vendor/autoload.php'; // Correct path to autoload.php

// --- Start Dotenv loading (conditional for deployment) ---
// This block attempts to load .env files only if they exist.
// On Render, environment variables should be set directly in the dashboard,
// so a physical .env file won't be present.
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    try {
        $dotenv->load(); // This will only run if .env file exists
    } catch (Dotenv\Exception\InvalidPathException $e) {
        // This catch is mostly for local dev if .env is missing.
        error_log("Dotenv load error locally on path " . $dotenvPath . ": " . $e->getMessage());
    }
}
// If .env doesn't exist (like on Render), the variables are assumed to be pre-loaded
// into the environment by the hosting platform (e.g., Render's Config Vars).
// --- End Dotenv loading ---

require_once __DIR__ . '/../Config.php';        // Path from frontend/ to project root
require_once __DIR__ . '/../functions.php';      // Path from frontend/ to project root

// Initialize message and message type variables
$message = '';
$message_type = '';

// Check if the login form was submitted via POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Sanitize and retrieve input data
    $lastName = sanitize_input(trim($_POST['last_name'] ?? '')); // Using sanitize_input from functions.php
    $membershipNumber = sanitize_input(trim($_POST['membership_number'] ?? ''));

    // Basic validation (can be expanded)
    if (empty($lastName) || empty($membershipNumber)) {
        $message = "Please enter both last name and membership number.";
        $message_type = "error";
        error_log("Login.php: Missing last name or membership number.");
    } else {
        try {
            // Establish MongoDB connection and get the 'users' collection
            // Assuming getCollection() is defined in functions.php and handles DB connection
            $usersCollection = getCollection('users');

            // Find the user by last_name and membership_number
            // IMPORTANT: For production, membership_number should be hashed in the DB
            // and you would compare the hash here. For this example, direct comparison.
            $user = $usersCollection->findOne([
                'last_name' => $lastName,
                'membership_number' => $membershipNumber // Direct comparison - HASH THIS IN PRODUCTION!
            ]);

            if ($user) {
                // User found and credentials match
                $twoFactorEnabled = $user['two_factor_enabled'] ?? false;
                $twoFactorMethod = $user['two_factor_method'] ?? 'email'; // Default to 'email' if not set

                // --- IMPORTANT SECURITY CHANGE for USER-ONLY LOGIN ---
                // If this login.php is ONLY for regular users, we should NOT be checking or setting
                // $_SESSION['is_admin'] here, as an admin should log in via a separate portal/page.
                // For a user login, the 'role' would typically be 'user' or 'customer'.
                // If a user *happens* to have 'is_admin' true in the DB, this frontend login should still
                // treat them as a regular user for this interface, or prevent them from logging in here.

                // For the purpose of separating user/admin login flows:
                // Let's assume ANY user successfully logging in via this specific frontend/login.php
                // will be treated as a standard user for this frontend.
                // If you *must* block admin accounts from logging in here, you'd add:
                // if (($user['role'] ?? 'user') === 'admin' || ($user['is_admin'] ?? false)) {
                //      $message = "Administrators must log in via the admin portal.";
                //      $message_type = "error";
                //      // Maybe log this attempt
                // } else { ... proceed with user login }


                if ($twoFactorEnabled && $twoFactorMethod !== 'none') {
                    // 2FA is enabled for this user. Redirect to verification page.
                    $_SESSION['auth_step'] = 'awaiting_2fa';
                    // temp_user_id is already set as user_id above, but explicitly setting for clarity in 2FA flow.
                    // This is for verify_code.php to pick up the user.
                    $_SESSION['temp_user_id'] = (string) $user['_id'];
                    $_SESSION['email'] = $user['email'] ?? ''; // Store user's email for display in verify_code.php

                    error_log("Login.php: 2FA enabled for user: " . $user['email'] . ". Setting session vars for 2FA.");
                    error_log("Login.php: Session dump before 2FA code generation: " . print_r($_SESSION, true));


                    // If 2FA method is email, generate and send code
                    if ($twoFactorMethod === 'email') {
                        $verificationCode = str_pad(random_int(0, (10**TWO_FACTOR_CODE_LENGTH) - 1), TWO_FACTOR_CODE_LENGTH, '0', STR_PAD_LEFT);
                        $expiryTime = new MongoDB\BSON\UTCDateTime(strtotime('+' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes') * 1000);

                        // Update user document with the temporary code and expiry
                        $usersCollection->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => [
                                'two_factor_temp_code' => $verificationCode,
                                'two_factor_code_expiry' => $expiryTime
                            ]]
                        );
                        error_log("Login.php: Generated code and updated DB for user " . $user['_id'] . ". Code: " . $verificationCode);

                        // Send the email (assuming sendEmail function exists in functions.php)
                        $emailSubject = "HomeTown Bank Login Verification Code";
                        $emailBodyHtml = "
                            <!DOCTYPE html>
                            <html lang='en'>
                            <head>
                                <meta charset='UTF-8'>
                                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                                <title>HomeTown Bank - Verification Code</title>
                                <style>
                                    body { font-family: sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                                    .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.05); overflow: hidden; }
                                    .header { background-color: #0056b3; padding: 20px; text-align: center; color: #ffffff; font-size: 24px; font-weight: bold; }
                                    .content { padding: 30px; color: #333333; line-height: 1.6; text-align: center; }
                                    .verification-code { display: inline-block; background-color: #e6f0fa; color: #0056b3; font-size: 32px; font-weight: bold; padding: 15px 30px; border-radius: 5px; margin: 20px 0; letter-spacing: 3px; text-align: center; }
                                    .expiry-info { font-size: 0.9em; color: #777777; margin-top: 20px; }
                                    .footer { background-color: #f0f0f0; padding: 20px; text-align: center; font-size: 0.85em; color: #666666; border-top: 1px solid #eeeeee; }
                                </style>
                            </head>
                            <body>
                                <div class='email-container'>
                                    <div class='header'>HomeTown Bank</div>
                                    <div class='content'>
                                        <p>Dear " . htmlspecialchars($user['first_name'] ?? '') . ",</p>
                                        <p>Your verification code for HomeTown Bank Online Banking is:</p>
                                        <div class='verification-code'>" . htmlspecialchars($verificationCode) . "</div>
                                        <p class='expiry-info'>This code is valid for <strong>" . TWO_FACTOR_CODE_EXPIRY_MINUTES . " minutes</strong>.</p>
                                        <p>If you did not request this, please ignore this email.</p>
                                    </div>
                                    <div class='footer'>&copy; " . date('Y') . " HomeTown Bank.</div>
                                </div>
                            </body>
                            </html>";
                        sendEmail($user['email'], $emailSubject, $emailBodyHtml, "Your verification code for HomeTown Bank is: " . $verificationCode);
                        error_log("Login.php: Verification email sent to " . $user['email']);
                    }

                    ob_end_clean(); // Discard any buffered output
                    error_log("Login.php: Redirecting to verify_code. Current session state: " . print_r($_SESSION, true)); // CRITICAL LOG
                    header('Location: ' . rtrim(BASE_URL, '/') . '/verify_code');
                    exit;

                } else {
                    // No 2FA enabled for this user or method is 'none', log them in directly
                    $_SESSION['logged_in'] = true; // Mark as fully logged in
                    $_SESSION['2fa_verified'] = true; // No 2FA, so consider it verified immediately
                    // Assuming you have a user ID or similar to store for the actual logged-in session
                    $_SESSION['user_id'] = (string)$user['_id']; 
                    // Set other necessary session vars for a logged-in user (e.g., first_name, role)
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['is_admin'] = $user['is_admin'] ?? false; // Make sure this is only set for actual admins if needed
                    $_SESSION['email'] = $user['email'] ?? '';


                    error_log("Login.php: 2FA NOT enabled for user " . $user['email'] . ". Logging in directly.");
                    error_log("Login.php: Session dump before direct login redirect: " . print_r($_SESSION, true));

                    ob_end_clean(); // Discard any buffered output
                    $redirect_path = ($_SESSION['is_admin'] ?? false) ? '/admin' : '/dashboard';
                    header('Location: ' . BASE_URL . $redirect_path);
                    exit;
                }
            } else {
                // User not found or credentials do not match
                $message = "Invalid last name or membership number.";
                $message_type = "error";
                error_log("Login.php: Invalid credentials for last name: " . $lastName);
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            // Log the MongoDB error for debugging
            error_log("MongoDB Error during login: " . $e->getMessage());
            $message = "A database error occurred. Please try again later.";
            $message_type = "error";
        } catch (Exception $e) {
            // Catch any other unexpected errors
            error_log("General Error during login: " . $e->getMessage());
            $message = "An unexpected error occurred. Please try again.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - User Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="background-container">
    </div>

    <div class="login-card-container">
        <div class="login-card">
            <div class="bank-logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
            </div>

            <?php if (!empty($message)): ?>
                <div id="login-message" class="message-box <?php echo htmlspecialchars($message_type); ?>" style="display: block;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div id="login-message" class="message-box" style="display: none;"></div>
            <?php endif; ?>

            <form class="login-form" id="loginForm" action="<?php echo rtrim(BASE_URL, '/') . '/login'; ?>" method="POST">
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
        <p>&copy; 2025 HomeTown Bank Pa. All rights reserved.</p>
        <div class="footer-links">
            <a href="heritagebank_admin/index.php">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>
<?php
// Ensure any buffered output is sent to the browser if the script finishes without a redirect.
// This is important for when the login fails and the form is displayed with an error message.
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>
