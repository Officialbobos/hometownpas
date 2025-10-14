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

require_once __DIR__ . '/../Config.php';        // Path from frontend/ to project root
require_once __DIR__ . '/../functions.php';      // Path from frontend/ to project root

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

                // REQUIRED LOGIC: Check for blocked or closed status
                $user_status = strtolower($user['status'] ?? 'active');

                if ($user_status === 'blocked' || $user_status === 'closed') {
                    // Find the most recent reason from the history collection
                    $historyCollection = getCollection('account_status_history');
                    
                    $last_status_record = $historyCollection->findOne(
                        ['user_id' => $user['_id']],
                        ['sort' => ['changed_at' => -1]] // Sort by latest change date
                    );
                    
                    $reason = $last_status_record['reason'] ?? 'Please contact customer support for details.';
                    $display_status = ucfirst($user_status);

                    $message = "Login denied. Your account status is **" . $display_status . "**.<br>Reason: " . htmlspecialchars($reason);
                    $message_type = "error";
                    error_log("Login.php: Denied login for user " . ($user['email'] ?? 'ID ' . $user['_id']) . " due to status: " . $display_status);

                } else {
                    // Account is active, proceed with login (Original successful login logic)
                    session_regenerate_id(true); // Always do this for security!
                    $_SESSION['logged_in'] = true; // Mark as fully logged in
                    $_SESSION['2fa_verified'] = true; // Bypassed 2FA, so consider it verified immediately
                    // Assuming you have a user ID or similar to store for the actual logged-in session
                    $_SESSION['user_id'] = (string)$user['_id']; 
                    // Set other necessary session vars for a logged-in user (e.g., first_name, role)
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['is_admin'] = $user['is_admin'] ?? false; // Make sure this is only set for actual admins if needed
                    $_SESSION['email'] = $user['email'] ?? '';


                    error_log("Login.php: Logging user in directly (Status Active).");
                    error_log("Login.php: Session dump before direct login redirect: " . print_r($_SESSION, true));

                    ob_end_clean(); // Discard any buffered output
                    $redirect_path = ($_SESSION['is_admin'] ?? false) ? '/admin' : '/dashboard';
                    header('Location: ' . rtrim(BASE_URL, '/') . $redirect_path);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" xintegrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
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
                    <?php echo $message; ?>
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
