<?php
// C:\xampp\htdocs\heritagebank\index.php
session_start();
// Include your database configuration and functions file
require_once 'Config.php'; // Path from project root
require_once 'functions.php'; // Path from project root

// Include MongoDB Client and ObjectId for database operations (if not already included by functions.php)
// It's good practice to ensure they are available if you interact with MongoDB directly here.
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

$message = '';
$message_type = '';

// --- Handle "Back to Login" action to clear 2FA session ---
// This action will now be handled directly by verify_code.php redirecting back here.
if (isset($_GET['action']) && $_GET['action'] === 'reset_2fa') {
    if (isset($_SESSION['temp_user_id'])) {
        try {
            $usersCollection = getCollection('users'); // Get the users collection

            // Convert temp_user_id to ObjectId if it's a string from session
            $userId = (is_string($_SESSION['temp_user_id']) && strlen($_SESSION['temp_user_id']) === 24 && ctype_xdigit($_SESSION['temp_user_id'])) ? new ObjectId($_SESSION['temp_user_id']) : $_SESSION['temp_user_id'];

            $updateResult = $usersCollection->updateOne(
                ['_id' => $userId],
                ['$set' => ['2fa_code' => null, '2fa_code_expiry' => null]]
            );

            if ($updateResult->getModifiedCount() === 0 && $updateResult->getMatchedCount() === 0) {
                // Log if user not found or no change, but don't error out publicly.
                error_log("No user found or 2FA code already null for user ID: " . ($_SESSION['temp_user_id'] ?? 'unknown') . " during reset_2fa.");
            }
        } catch (MongoDBDriverException $e) {
            error_log("MongoDB Error clearing 2FA code on reset: " . $e->getMessage());
            // Optionally, set a message to the user, but this is a background reset
        } catch (MongoDB\BSON\Exception\InvalidTypeException $e) {
            error_log("Invalid user ID format in session for reset_2fa: " . $e->getMessage());
        }
    }
    unset($_SESSION['2fa_pending']);
    unset($_SESSION['temp_user_id']);
    unset($_SESSION['temp_user_email']);
    // No need for a message here, as it's a redirect after reset.
    header('Location: index.php'); // Redirect to clean URL
    exit;
}

// Check if user is already fully logged in (after 2FA)
if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
    header('Location: frontend/dashboard.php'); // Assuming dashboard.php is in frontend/
    exit;
}
// Check if 2FA is pending and redirect to verify_code.php
elseif (isset($_SESSION['2fa_pending']) && $_SESSION['2fa_pending'] === true && isset($_SESSION['temp_user_id'])) {
    // If 2FA is pending, redirect them to the dedicated 2FA verification page
    header('Location: frontend/verify_code.php');
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // This is exclusively for the initial login form submission
    if (isset($_POST['login'])) {
        $last_name = sanitize_input($_POST['last_name'] ?? ''); // Using the sanitize_input from functions.php
        $membership_number = sanitize_input($_POST['membership_number'] ?? '');

        if (empty($last_name) || empty($membership_number)) {
            $message = 'Please enter both your Last Name and Membership Number.';
            $message_type = 'error';
        } else {
            try {
                $usersCollection = getCollection('users'); // Get the users collection

                // Find the user by last_name and membership_number
                $user = $usersCollection->findOne([
                    'last_name' => $last_name,
                    'membership_number' => $membership_number
                ]);

                if ($user) {
                    // User found, now initiate 2FA
                    // MongoDB _id is an object, convert to string for session storage
                    $user_id = (string)$user['_id'];
                    $user_email = $user['email'];
                    $user_full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);

                    // Generate a 6-digit verification code
                    $verification_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    // MongoDB stores dates as UTCDateTime objects. Add 10 minutes from now.
                    $expiry_time = new UTCDateTime(strtotime('+10 minutes') * 1000);

                    // Store the code and expiry in the database
                    $updateResult = $usersCollection->updateOne(
                        ['_id' => new ObjectId($user_id)], // Convert string ID back to ObjectId for query
                        ['$set' => ['2fa_code' => $verification_code, '2fa_code_expiry' => $expiry_time]]
                    );

                    if ($updateResult->getModifiedCount() > 0) {

                        // --- START OF EMAIL BODY DESIGN --- (No change from previous)
                        $email_subject = "Hometown Bank Login Verification Code";
                        $email_plain_body = "Dear " . $user_full_name . ",\n\n";
                        $email_plain_body .= "Your verification code for Hometown Bank login is: " . $verification_code . "\n\n";
                        $email_plain_body .= "This code will expire in 10 minutes. Please enter it on the verification page to access your account.\n\n";
                        $email_plain_body .= "If you did not attempt to log in, please secure your account immediately.\n\n";
                        $email_plain_body .= "Sincerely,\nHometown Bank";

                        $email_html_body = '
                        <!DOCTYPE html>
                        <html lang="en">
                        <head>
                            <meta charset="UTF-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <title>Hometown Bank - Verification Code</title>
                            <style>
                                body { font-family: "Inter", sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; width: 100% !important; }
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
                            <div class="email-container">
                                <div class="header">
                                    Hometown Bank
                                </div>
                                <div class="content">
                                    <p>Dear ' . $user_full_name . ',</p>
                                    <p>Thank you for logging in to Hometown Bank Online Banking. To complete your login, please use the following verification code:</p>
                                    <div class="verification-code">' . $verification_code . '</div>
                                    <p class="expiry-info">This code is valid for <strong>10 minutes</strong> and should be entered on the verification page.</p>
                                    <p>If you did not attempt to log in or request this code, please ignore this email or contact us immediately if you suspect unauthorized activity on your account.</p>
                                    <p>For security reasons, do not share this code with anyone.</p>
                                </div>
                                <div class="footer">
                                    <p>&copy; ' . date('Y') . ' Hometown Bank. All rights reserved.</p>
                                    <p>This is an automated email, please do not reply.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ';
                        // --- END OF EMAIL BODY DESIGN ---

                        // Using sendEmail function from functions.php
                        $email_sent = sendEmail($user_email, $email_subject, $email_html_body, $email_plain_body);

                        if ($email_sent) {
                            // Set session variables for the 2FA pending state
                            $_SESSION['2fa_pending'] = true;
                            $_SESSION['temp_user_id'] = $user_id; // Store user ID temporarily
                            $_SESSION['temp_user_email'] = $user_email; // Store email to display if needed

                            // REDIRECT TO THE DEDICATED VERIFY PAGE
                            header('Location: frontend/verify_code.php');
                            exit;
                        } else {
                            $message = "Failed to send verification email. Please try again or contact support.";
                            $message_type = 'error';
                            error_log("2FA email send failed for user ID: " . $user_id . " - Error: " . error_get_last()['message'] ?? 'Unknown SMTP error');
                        }

                    } else {
                        $message = "Failed to store verification code. Please try again.";
                        $message_type = 'error';
                        error_log("Failed to update 2FA code for user ID: " . $user_id . " - No document modified or matched.");
                    }

                } else {
                    $message = 'Invalid Last Name or Membership Number.';
                    $message_type = 'error';
                }

            } catch (MongoDBDriverException $e) {
                // Catch any MongoDB driver exceptions during the process
                error_log("MongoDB Login Error: " . $e->getMessage());
                $message = "A system error occurred during login. Please try again later.";
                $message_type = 'error';
            } catch (Exception $e) {
                // Catch other general exceptions (e.g., from sendEmail)
                error_log("General Login Error: " . $e->getMessage());
                $message = "An unexpected error occurred. Please try again later.";
                $message_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank</title>
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
                <img src="https://i.imgur.com/YmC3kg3.png" alt="Hometown Bank Logo">
            </div>

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
        <p>&copy; 2025 Hometown Bank. All rights reserved.</p>
        <div class="footer-links">
            <a href="/heritagebank_admin/index.php">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>