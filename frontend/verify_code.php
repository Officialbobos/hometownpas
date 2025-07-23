<?php
// C:\xampp\htdocs\heritagebank\frontend\verify_code.php
session_start();
require_once '../Config.php'; // Path from frontend/ to project root
require_once '../functions.php'; // Path from frontend/ to project root
require_once __DIR__ . '/../vendor/autoload.php'; // Assuming you installed MongoDB driver via Composer

use MongoDB\Client; // Add this line based on your config.php
use MongoDB\Exception\Exception as MongoDBException; // Add this line based on your config.php

$message = '';
$message_type = '';

// Check if 2FA process is pending, otherwise redirect to login
if (!isset($_SESSION['2fa_pending']) || $_SESSION['2fa_pending'] !== true || !isset($_SESSION['temp_user_id'])) {
    header('Location: ../indx.php');
    exit;
}

$user_id = $_SESSION['temp_user_id'];

// Establish MongoDB connection
try {
    // Use MONGODB_CONNECTION_URI and MONGODB_DB_NAME from Config.php
    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME); // Use MONGODB_DB_NAME
    $usersCollection = $db->users;
} catch (MongoDBException $e) { // Catch specific MongoDB exceptions
    die("ERROR: Could not connect to MongoDB. " . $e->getMessage());
} catch (Exception $e) { // Catch any other general exceptions
    die("ERROR: An unexpected error occurred during database connection. " . $e->getMessage());
}

// Fetch user's email to resend code if needed
$user_email = '';
$user_full_name = '';
$userIdObjectId = null;

try {
    // Convert string user_id from session to MongoDB\BSON\ObjectId
    $userIdObjectId = new MongoDB\BSON\ObjectId($user_id);
    $user_data = $usersCollection->findOne(['_id' => $userIdObjectId]);

    if ($user_data) {
        $user_email = $user_data['email'] ?? '';
        $first_name = $user_data['first_name'] ?? '';
        $last_name = $user_data['last_name'] ?? '';
        $user_full_name = htmlspecialchars(trim($first_name . ' ' . $last_name));
    }
} catch (MongoDBException $e) {
    error_log("MongoDB error fetching user email: " . $e->getMessage());
    $message = "Database error fetching user details. Please try again.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("General error fetching user email: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again.";
    $message_type = 'error';
}


// --- Handle Code Resend ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_code'])) {
    if (!$userIdObjectId) {
        $message = "User ID not found for resend operation.";
        $message_type = 'error';
    } else {
        // Generate a new 6-digit verification code
        $new_verification_code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        // MongoDB stores dates as BSON Date objects or ISO 8601 strings.
        // For simplicity and consistency, let's store it as an ISO 8601 string.
        $new_expiry_time = date('Y-m-d H:i:s', strtotime('+10 minutes')); 

        try {
            // Update the database with the new code and expiry
            $updateResult = $usersCollection->updateOne(
                ['_id' => $userIdObjectId],
                ['$set' => [
                    '2fa_code' => $new_verification_code,
                    '2fa_code_expiry' => $new_expiry_time
                ]]
            );

            if ($updateResult->getModifiedCount() > 0) {
                // Send the new verification email
                $email_subject = "HomeTown Bank PA New Login Verification Code";
                $email_body = "Dear " . $user_full_name . ",\n\n";
                $email_body .= "You requested a new verification code. Your new code is: <strong>" . $new_verification_code . "</strong>\n\n";
                $email_body .= "This code will expire in 10 minutes. Please enter it on the verification page to access your account.\n\n";
                $email_body .= "Sincerely,\nHomeTown Bank"; // Corrected "HomeTwon Bank" to "HomeTown Bank"

                $email_sent = send_smtp_email($user_email, $email_subject, $email_body, strip_tags($email_body));

                if ($email_sent) {
                    $message = "A new verification code has been sent to your registered email address.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to resend verification email. Please try again or contact support.";
                    $message_type = 'error';
                    error_log("Resend 2FA email failed for user ID: " . $user_id);
                }
            } else {
                $message = "Failed to update new verification code in database. User not found or no change.";
                $message_type = 'error';
                error_log("Failed to update new 2FA code for user ID: " . $user_id . " No document modified.");
            }
        } catch (MongoDBException $e) {
            $message = "Database error during resend code update. Please try again.";
            $message_type = 'error';
            error_log("MongoDB error updating 2FA code for user ID: " . $user_id . " Error: " . $e->getMessage());
        } catch (Exception $e) {
            $message = "An unexpected error occurred during resend. Please try again.";
            $message_type = 'error';
            error_log("General error during 2FA code resend for user ID: " . $user_id . " Error: " . $e->getMessage());
        }
    }
}


// --- Handle Code Verification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    $entered_code = trim($_POST['verification_code'] ?? '');

    if (empty($entered_code)) {
        $message = 'Please enter the verification code.';
        $message_type = 'error';
    } else {
        if (!$userIdObjectId) {
            $message = "User ID not found for verification operation.";
            $message_type = 'error';
        } else {
            try {
                // Fetch the stored 2FA code and its expiry from the database
                $user_data_for_verification = $usersCollection->findOne(
                    ['_id' => $userIdObjectId],
                    ['projection' => ['2fa_code' => 1, '2fa_code_expiry' => 1]]
                );

                if ($user_data_for_verification) {
                    $stored_code = $user_data_for_verification['2fa_code'] ?? null;
                    $stored_expiry = $user_data_for_verification['2fa_code_expiry'] ?? null;
                    $current_time = date('Y-m-d H:i:s');

                    if ($stored_code && $stored_expiry && $entered_code === $stored_code && $current_time < $stored_expiry) {
                        // Code is valid and not expired
                        $_SESSION['user_logged_in'] = true; // Mark user as fully logged in
                        $_SESSION['user_id'] = $user_id;     // Store actual user ID in session
                        // Clear 2FA specific session variables
                        unset($_SESSION['2fa_pending']);
                        unset($_SESSION['temp_user_id']);

                        // Optionally, clear the 2fa_code in the database after successful login
                        try {
                            $usersCollection->updateOne(
                                ['_id' => $userIdObjectId],
                                ['$set' => [
                                    '2fa_code' => null,
                                    '2fa_code_expiry' => null
                                ]]
                            );
                        } catch (MongoDBException $e) {
                            error_log("MongoDB error clearing 2FA code for user ID: " . $user_id . " Error: " . $e->getMessage());
                            // This is not critical, so don't block login, just log.
                        }

                        header('Location: dashboard.php'); // Redirect to dashboard
                        exit;
                    } else if ($stored_expiry && $current_time >= $stored_expiry) {
                        $message = 'Verification code has expired. Please request a new one.';
                        $message_type = 'error';
                    } else {
                        $message = 'Invalid verification code. Please try again.';
                        $message_type = 'error';
                    }
                } else {
                    $message = 'No verification code found for your account. Please log in again to generate one.';
                    $message_type = 'error';
                }
            } catch (MongoDBException $e) {
                $message = "Database error during code fetch. Please try again.";
                $message_type = 'error';
                error_log("MongoDB error fetching 2FA code for user ID: " . $user_id . " Error: " . $e->getMessage());
            } catch (Exception $e) {
                $message = "An unexpected error occurred during verification. Please try again.";
                $message_type = 'error';
                error_log("General error during 2FA code verification for user ID: " . $user_id . " Error: " . $e->getMessage());
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
    <title>Heritage Bank - Verify Code</title>
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
        }

        .resend-link button:hover {
            color: #007bff;
        }

        /* Message styling from main style.css */
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
        <h1>Verify Your Login</h1>
        <p>A 6-digit verification code has been sent to your registered email address (<?php echo htmlspecialchars($user_email); ?>). Please enter it below.</p>

        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form action="verify_code.php" method="POST">
            <div class="form-group">
                <label for="verification_code">Verification Code</label>
                <input type="text" id="verification_code" name="verification_code" required maxlength="6" pattern="\d{6}" title="Please enter a 6-digit code">
            </div>
            <button type="submit" name="verify_code" class="button-primary">Verify Code</button>
        </form>

        <p class="resend-link">
            Didn't receive the code?
            <form action="verify_code.php" method="POST" style="display:inline;">
                <button type="submit" name="resend_code">Resend Code</button>
            </form>
        </p>
    </div>
</body>
</html>