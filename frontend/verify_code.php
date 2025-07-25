<?php
// C:\xampp\htdocs\heritagebank\frontend\verify_code.php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
        // On Render, this block won't be hit because file_exists is false.
        error_log("Dotenv load error locally on path " . $dotenvPath . ": " . $e->getMessage());
    }
}
// If .env doesn't exist (like on Render), the variables are assumed to be pre-loaded
// into the environment by the hosting platform (e.g., Render's Config Vars).
// --- End Dotenv loading ---

require_once __DIR__ . '/../Config.php';          // Path from frontend/ to project root
require_once __DIR__ . '/../functions.php';       // Path from frontend/ to project root

use MongoDB\Client;
use MongoDB\BSON\ObjectId; // Ensure ObjectId is used
use MongoDB\BSON\UTCDateTime; // Ensure UTCDateTime is used for date handling
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // For general MongoDB driver exceptions
use OTPHP\TOTP; // For TOTP (Time-based One-Time Password) functionality
use BaconQrCode\Writer; // For QR code generation
use BaconQrCode\Renderer\Image\Png; // For rendering QR code as PNG

$message = '';
$message_type = '';

// Check if 2FA process is pending and temporary user ID exists
// Ensure consistent session state: 'auth_step' should be set in login.php
if (!isset($_SESSION['auth_step']) || $_SESSION['auth_step'] !== 'awaiting_2fa' || !isset($_SESSION['temp_user_id'])) {
    session_unset();
    session_destroy();
    header('Location: ../index.php'); // Redirect to your main login page
    exit;
}

$temp_user_id_str = $_SESSION['temp_user_id'];
$user_id_obj = null;
$user_email = '';
$user_full_name = '';
$masked_user_email = '';

try {
    $user_id_obj = new ObjectId($temp_user_id_str); // Convert to ObjectId for MongoDB queries
} catch (MongoDB\BSON\Exception\InvalidTypeException $e) {
    error_log("Invalid temp_user_id in session: " . $e->getMessage());
    $message = "Session error: Your login session is invalid. Please try logging in again.";
    $message_type = 'error';
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Establish MongoDB connection and get collection
$usersCollection = getCollection('users'); // This function should be defined in functions.php

// Fetch user's email, name, and 2FA settings for display and functionality
$user_data = null;
try {
    $user_data = $usersCollection->findOne(['_id' => $user_id_obj]);

    if ($user_data) {
        $user_email = $user_data['email'] ?? '';
        $first_name = $user_data['first_name'] ?? '';
        $last_name = $user_data['last_name'] ?? '';
        $user_full_name = htmlspecialchars(trim($first_name . ' ' . $last_name));
        $masked_user_email = hideEmailPartially($user_email); // Use the helper function
    } else {
        // User not found in DB for the session temp_user_id
        error_log("User data not found for temp_user_id: " . $temp_user_id_str . ". Possible session mismatch or DB issue.");
        $message = "Your account details could not be retrieved. Please try logging in again.";
        $message_type = 'error';
        session_unset();
        session_destroy();
        header('Location: ../index.php'); // Redirect to login if user data isn't found
        exit;
    }
} catch (MongoDBDriverException $e) {
    error_log("MongoDB error fetching user data for 2FA verification: " . $e->getMessage());
    $message = "A database error occurred. Please try again later.";
    $message_type = 'error';
    // For critical failures like this, it's safer to redirect to login after a brief message.
    // However, for this example, we'll let the error message display.
} catch (Exception $e) {
    error_log("General error fetching user data for 2FA verification: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again.";
    $message_type = 'error';
}

// --- Determine 2FA Type and Setup/Verification State ---
$two_factor_enabled = $user_data['two_factor_enabled'] ?? false;
$two_factor_secret = $user_data['two_factor_secret'] ?? null;
$two_factor_method = $user_data['two_factor_method'] ?? 'email'; // Default to 'email' if not set

$show_authenticator_setup = false;
$qr_code_data_url = ''; // Changed from $qr_code_url to $qr_code_data_url to store data URI
$manual_secret = '';

if ($two_factor_enabled && $two_factor_method === 'authenticator' && !$two_factor_secret) {
    // User has 2FA enabled, method is authenticator, but no secret is set (first-time setup)
    $show_authenticator_setup = true;
    
    // Generate a new secret using OTPHP\TOTP
    $totp = TOTP::generate(); // Creates a new TOTP object with a new secret
    $manual_secret = $totp->getSecret(); // Get the generated secret string

    // Generate the QR code URL (otpauth URI)
    $issuer = SMTP_FROM_NAME; // Use directly, urlencode done internally by library
    $account_name = $user_email; // Use directly
    $totp->setLabel($account_name); // Set the account name
    $totp->setIssuer($issuer); // Set the issuer (your bank name)
    $otp_auth_uri = $totp->getProvisioningUri(); // Get the URI

    // Generate the QR code image as a Data URI (Base64 encoded PNG)
    try {
        $writer = new Writer(new Png());
        $qr_code_data_url = 'data:image/png;base64,' . base64_encode($writer->writeString($otp_auth_uri));
    } catch (Exception $e) {
        error_log("Error generating QR code: " . $e->getMessage());
        $message = "Error generating QR code. Please try again or contact support.";
        $message_type = 'error';
        $show_authenticator_setup = false; // Prevent display if QR code fails
    }

    // Save the generated secret to the user's document for future verification IF it's not already there.
    if (($user_data['two_factor_secret'] ?? null) === null) {
        try {
            $usersCollection->updateOne(
                ['_id' => $user_id_obj],
                ['$set' => ['two_factor_secret' => $manual_secret]]
            );
            $message = "Please scan the QR code with your authenticator app and enter the code below to complete setup.";
            $message_type = 'info';
        } catch (MongoDBDriverException $e) {
            error_log("MongoDB error saving new 2FA secret for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
            $message = "A database error occurred during 2FA setup. Please try again later.";
            $message_type = 'error';
            $show_authenticator_setup = false; // Prevent display if error
        }
    }
}


// --- Handle Code Resend ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_code'])) {
    if (!$user_id_obj || empty($user_email)) {
        $message = "Unable to resend code: Missing user information. Please try logging in again.";
        $message_type = 'error';
    } elseif ($two_factor_method === 'authenticator') {
        $message = "Resending codes is not applicable for Authenticator app 2FA. Please generate a new code using your app.";
        $message_type = 'info';
    } else { // Handle email-based code resend
        // Implement rate limiting here if desired to prevent abuse
        $last_resend_time = $_SESSION['last_resend_time'] ?? 0;
        if (time() - $last_resend_time < 60) { // 60 seconds cooldown
            $message = "Please wait before requesting another code. You can request again in " . (60 - (time() - $last_resend_time)) . " seconds.";
            $message_type = 'error';
        } else {
            // Generate a new verification code using constants
            $new_verification_code = str_pad(random_int(0, (10**TWO_FACTOR_CODE_LENGTH) - 1), TWO_FACTOR_CODE_LENGTH, '0', STR_PAD_LEFT);
            $new_expiry_time = new UTCDateTime(strtotime('+' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes') * 1000);

            try {
                // Update the database with the new code and expiry
                $updateResult = $usersCollection->updateOne(
                    ['_id' => $user_id_obj],
                    ['$set' => [
                        'two_factor_temp_code' => $new_verification_code,
                        'two_factor_code_expiry' => $new_expiry_time
                    ]]
                );

                if ($updateResult->getModifiedCount() > 0) {
                    // Send the new verification email
                    $email_subject = "HomeTown Bank Login Verification Code";
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
                                <p>You requested a new verification code for your HomeTown Bank Online Banking account. Here is your new code:</p>
                                <div class='verification-code'>" . htmlspecialchars($new_verification_code) . "</div>
                                <p class='expiry-info'>This code is valid for <strong>" . TWO_FACTOR_CODE_EXPIRY_MINUTES . " minutes</strong> and should be entered on the verification page.</p>
                                <p>If you did not request this, please ignore this email or contact us immediately if you suspect unauthorized activity on your account.</p>
                                <p>For security reasons, do not share this code with anyone.</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " HomeTown Bank. All rights reserved.</p>
                                <p>This is an automated email, please do not reply.</p>
                            </div>
                        </div>
                    </body>
                    </html>";

                    $email_sent = sendEmail($user_email, $email_subject, $email_body_html, "Your new verification code for HomeTown Bank is: " . $new_verification_code); // Also provide plain text
                    $_SESSION['last_resend_time'] = time(); // Update last resend time

                    if ($email_sent) {
                        $message = "A new verification code has been sent to your registered email address.";
                        $message_type = 'success';
                    } else {
                        $message = "Failed to resend verification email. Please try again or contact support.";
                        $message_type = 'error';
                        error_log("Resend 2FA email failed for user ID: " . $temp_user_id_str . " (Email: " . $user_email . ")");
                    }
                } else {
                    $message = "Failed to update new verification code in the database. Please try again.";
                    $message_type = 'error';
                    error_log("Failed to update new 2FA code for user ID: " . $temp_user_id_str . ". No document modified.");
                }
            } catch (MongoDBDriverException $e) {
                $message = "A database error occurred during code resend. Please try again.";
                $message_type = 'error';
                error_log("MongoDB error updating 2FA code for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
            } catch (Exception $e) {
                $message = "An unexpected error occurred during resend. Please try again.";
                $message_type = 'error';
                error_log("General error during 2FA code resend for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
            }
        }
    }
}


// --- Handle Code Verification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $entered_code = sanitize_input($_POST['verification_code'] ?? ''); // Sanitize input

    if (empty($entered_code)) {
        $message = 'Please enter the verification code.';
        $message_type = 'error';
    } else {
        if (!$user_id_obj || !$user_data) {
            $message = "Missing user information for verification. Please try logging in again.";
            $message_type = 'error';
            // Potentially redirect if critical data is missing
            // header('Location: ../index.php'); exit;
        } else {
            try {
                // Re-fetch user data to ensure we have the absolute latest 2FA state (especially for secret/temp_code)
                // This protects against race conditions if other processes modify the user document.
                $user_data_for_verification = $usersCollection->findOne(['_id' => $user_id_obj]);

                if (!$user_data_for_verification) {
                    $message = "User account not found for verification. Please try logging in again.";
                    $message_type = 'error';
                    session_unset(); session_destroy(); header('Location: ../index.php'); exit;
                }

                $two_factor_method_current = $user_data_for_verification['two_factor_method'] ?? 'email'; // Get current method from DB
                $is_code_valid = false;
                $clear_temp_code_from_db = false; // Flag to decide if two_factor_temp_code should be unset

                if ($two_factor_method_current === 'authenticator') {
                    $secret_to_verify = $user_data_for_verification['two_factor_secret'] ?? null;
                    if ($secret_to_verify) {
                        // Recreate TOTP object using the stored secret for verification
                        $totp_verifier = TOTP::create($secret_to_verify);
                        // Check the code. The verify method has a second parameter for clock drift (e.g., 1 for +/- 30s)
                        $is_code_valid = $totp_verifier->verify($entered_code);
                    } else {
                        $message = 'Authenticator app not fully set up or secret is missing. Please contact support.';
                        $message_type = 'error';
                        error_log("2FA Authenticator: No secret found for user " . $temp_user_id_str . " during verification.");
                    }
                } else { // Default to email verification (or if method is explicitly 'email')
                    $stored_code = $user_data_for_verification['two_factor_temp_code'] ?? null;
                    $stored_expiry_utc = $user_data_for_verification['two_factor_code_expiry'] ?? null;

                    $current_timestamp = time();
                    $expiry_timestamp = $stored_expiry_utc instanceof UTCDateTime ? $stored_expiry_utc->toDateTime()->getTimestamp() : 0;

                    if ($stored_code && $stored_expiry_utc && $entered_code === $stored_code && $current_timestamp < $expiry_timestamp) {
                        $is_code_valid = true;
                        $clear_temp_code_from_db = true; // Mark for clearing temp fields
                    } else if ($stored_expiry_utc && $current_timestamp >= $expiry_timestamp) {
                        $message = 'The verification code has expired. Please request a new one.';
                        $message_type = 'error';
                        error_log("2FA: Code expired for user " . $temp_user_id_str . ". Entered: " . $entered_code . ", Stored: " . $stored_code);
                    }
                }

                if ($is_code_valid) {
                    // Code is valid, user is fully authenticated
                    $_SESSION['user_logged_in'] = true; // Set flag used by login.php to redirect
                    $_SESSION['user_id'] = $temp_user_id_str; // Store actual user ID in session
                    $_SESSION['first_name'] = $user_data_for_verification['first_name'] ?? '';
                    $_SESSION['last_name'] = $user_data_for_verification['last_name'] ?? '';
                    $_SESSION['full_name'] = $user_data_for_verification['full_name'] ?? trim(($user_data_for_verification['first_name'] ?? '') . ' ' . ($user_data_for_verification['last_name'] ?? ''));
                    $_SESSION['email'] = $user_data_for_verification['email'] ?? '';
                    $_SESSION['is_admin'] = $user_data_for_verification['is_admin'] ?? false;
                    $_SESSION['two_factor_enabled'] = $two_factor_enabled; // Keep 2FA status in session
                    $_SESSION['two_factor_method'] = $two_factor_method_current; // Keep 2FA method in session

                    // Clear 2FA specific temporary session variables
                    unset($_SESSION['auth_step']);
                    unset($_SESSION['temp_user_id']);
                    unset($_SESSION['last_resend_time']); // Clear resend cooldown
                    // If you had a session temp_2fa_secret for *setup verification*, clear it here
                    unset($_SESSION['temp_2fa_secret']);

                    // Clear temporary code/expiry from DB if it was an email verification
                    if ($clear_temp_code_from_db) {
                        try {
                            $usersCollection->updateOne(
                                ['_id' => $user_id_obj],
                                ['$unset' => ['two_factor_temp_code' => '', 'two_factor_code_expiry' => '']]
                            );
                        } catch (MongoDBDriverException $e) {
                            error_log("MongoDB error clearing 2FA temp code for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
                            // This is not critical to login, but important to log.
                        }
                    }

                    // Redirect to dashboard based on role
                    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
                        header('Location: ../admin/dashboard.php'); // Admin dashboard
                    } else {
                        header('Location: dashboard.php'); // User dashboard in frontend/
                    }
                    exit;

                } else if ($message_type === '') { // Only set generic error if not already set by expiry check
                    $message = 'The verification code is incorrect. Please try again.';
                    $message_type = 'error';
                    error_log("2FA: Invalid code for user " . $temp_user_id_str . ". Entered: " . $entered_code . ". Method: " . $two_factor_method_current);
                }

            } catch (MongoDBDriverException $e) {
                $message = "A database error occurred during verification. Please try again.";
                $message_type = 'error';
                error_log("MongoDB error during 2FA verification for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
            } catch (Exception $e) {
                $message = "An unexpected error occurred during verification. Please try again.";
                $message_type = 'error';
                error_log("General error during 2FA code verification for user ID: " . $temp_user_id_str . " Error: " . $e->getMessage());
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
    <title>HomeTown Bank - Verify Code</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for the verify code page, extending from style.css */
        body.verify-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center; /* Center vertically */
            min-height: 100vh;
            background-color: #f4f7f6; /* Match main body background */
            font-family: 'Roboto', sans-serif;
        }

        .verify-container {
            background-color: #fff;
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px; /* Slightly smaller than login container */
            box-sizing: border-box;
            text-align: center;
        }

        .verify-container h1 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 20px;
        }

        .verify-container p {
            color: #555;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .verify-container .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .verify-container label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9em;
            color: #333;
            font-weight: bold;
        }

        .verify-container input[type="text"] {
            width: calc(100% - 22px);
            padding: 12px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1.1em;
            text-align: center; /* Center the code input */
            letter-spacing: 2px; /* Space out characters for code readability */
            box-sizing: border-box;
        }

        .verify-container input[type="text"]:focus {
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
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease;
            margin-bottom: 15px; /* Space before resend */
        }

        .button-primary:hover {
            background-color: #0056b3;
        }

        .resend-link {
            font-size: 0.9em;
            color: #555;
            margin-top: 15px; /* Added margin for separation */
        }

        .resend-link button {
            background: none;
            border: none;
            color: #0056b3;
            text-decoration: underline;
            cursor: pointer;
            font-size: 1em;
            font-family: 'Roboto', sans-serif;
            padding: 0;
            margin-left: 5px; /* Added space between text and button */
        }

        .resend-link button:hover {
            color: #007bff;
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

        /* Additional styles for QR code display */
        .qr-code-section {
            text-align: center;
            margin-top: 20px;
            margin-bottom: 25px;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px dashed #ddd;
            border-radius: 5px;
        }
        .qr-code-section img {
            max-width: 200px;
            height: auto;
            border: 1px solid #eee;
            margin-bottom: 15px;
        }
        .qr-code-section p {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 10px;
        }
        .qr-code-section strong {
            color: #333;
        }


        @media (max-width: 600px) {
            .verify-container {
                padding: 20px 25px;
                margin: 20px; /* Add margin on smaller screens */
            }
            .verify-container h1 {
                font-size: 1.5em;
            }
            .verify-container input[type="text"] {
                padding: 10px;
                font-size: 1em;
            }
        }
    </style>
</head>
<body class="verify-page">
    <div class="verify-container">
        <h1>2-Factor Authentication</h1>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($show_authenticator_setup): ?>
            <div class="qr-code-section">
                <p>Scan this QR code with your Authenticator app (e.g., Google Authenticator, Authy).</p>
                <img src="<?php echo htmlspecialchars($qr_code_data_url); ?>" alt="QR Code">
                <p>Alternatively, enter this secret key manually:</p>
                <strong><?php echo htmlspecialchars($manual_secret); ?></strong>
            </div>
            <p>Then, enter the 6-digit code generated by your app below to complete the setup.</p>
        <?php elseif ($two_factor_method === 'authenticator'): ?>
            <p>Enter the 6-digit code from your authenticator app.</p>
        <?php else: // Email method ?>
            <p>A 6-digit verification code has been sent to **<?php echo htmlspecialchars($masked_user_email); ?>**.</p>
            <p>Please enter the code below to complete your login.</p>
        <?php endif; ?>

        <form action="verify_code.php" method="POST">
            <div class="form-group">
                <label for="verification_code">Verification Code</label>
                <input type="text" id="verification_code" name="verification_code" required maxlength="6" pattern="\d{6}" title="Please enter the 6-digit code.">
            </div>
            <button type="submit" name="verify_code" class="button-primary">Verify Code</button>
        </form>

        <?php if ($two_factor_method === 'email' && !$show_authenticator_setup): ?>
            <div class="resend-link">
                Didn't receive the code?
                <form action="verify_code.php" method="POST" style="display:inline;">
                    <button type="submit" name="resend_code">Resend Code</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="resend-link" style="margin-top: 25px;">
            <a href="../index.php">Return to Login</a>
        </div>
    </div>
</body>
</html>