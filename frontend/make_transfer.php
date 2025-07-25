<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\make_transfer.php

// Start the session at the very beginning to ensure it's available for all subsequent code.
// This also prevents potential "headers already sent" errors if output happens before session_start().
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// !!! IMPORTANT: Load Config.php first. It should handle autoload.php. !!!
// It's in frontend/, so it needs to go up two directories to reach Config.php and functions.php in the root.
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // functions.php may depend on Config.php's settings or autoloader

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception as MongoDBException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// --- Debugging: Log incoming POST data and session info ---
error_log("--- frontend/make_transfer.php initiated ---");
error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'Not set'));
error_log("Session User Logged In: " . (($_SESSION['user_logged_in'] ?? false) ? 'true' : 'false'));
error_log("POST Data: " . print_r($_POST, true));
error_log("--- End frontend/make_transfer.php debug ---");
// --- End Debugging ---


// 1. Authentication and Authorization Check
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    $_SESSION['message'] = "You must be logged in to make a transfer.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_email = $_SESSION['email'] ?? $_SESSION['temp_user_email'] ?? '';
$user_full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (empty($user_full_name)) {
    $user_full_name = $_SESSION['username'] ?? 'Customer';
}


// 2. Establish MongoDB Connection
try {
    // These checks are good, but ideally, Config.php should ensure these are defined.
    // If Config.php is robust, these checks might be redundant here.
    if (!defined('MONGODB_CONNECTION_URI') || empty(MONGODB_CONNECTION_URI)) {
        throw new Exception("MONGODB_CONNECTION_URI is not defined or empty.");
    }
    if (!defined('MONGODB_DB_NAME') || empty(MONGODB_DB_NAME)) {
        throw new Exception("MONGODB_DB_NAME is not defined or empty.");
    }

    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $db->accounts;
    $transactionsCollection = $db->transactions;
    $usersCollection = $db->users; // Needed to get recipient info for internal transfers
} catch (MongoDBException $e) {
    error_log("MongoDB connection error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: Database connection failed. Please try again later. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST; // Make sure to preserve form data on DB connection error
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
} catch (Exception $e) {
    error_log("General database connection error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: An unexpected error occurred during database connection. Please try again later. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST; // Make sure to preserve form data on general DB connection error
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
}


// 3. Validate and Sanitize Input
$form_data = $_POST; // Store all POST data for re-population on error

$source_account_id = filter_input(INPUT_POST, 'source_account_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$transfer_method = filter_input(INPUT_POST, 'transfer_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$recipient_name = filter_input(INPUT_POST, 'recipient_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

$errors = [];

if (empty($source_account_id)) {
    $errors[] = "Source account is required.";
}
if (empty($transfer_method)) {
    $errors[] = "Transfer method is required.";
}
if ($amount === false || $amount <= 0) {
    $errors[] = "Valid amount is required (must be greater than 0).";
}

// Specific validation based on transfer method
$destination_account_details = []; // Will store details of the destination for logging and display

switch ($transfer_method) {
    case 'internal_self':
        $destination_account_id_self = filter_input(INPUT_POST, 'destination_account_id_self', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($destination_account_id_self)) {
            $errors[] = "Destination account is required for transfers between your accounts.";
        } elseif ($source_account_id === $destination_account_id_self) {
            $errors[] = "Source and destination accounts cannot be the same.";
        } else {
            // Fetch destination account details for logging
            try {
                $destAccount = $accountsCollection->findOne(['_id' => new ObjectId($destination_account_id_self), 'user_id' => new ObjectId($user_id)]);
                if (!$destAccount) {
                    $errors[] = "Invalid destination account selected.";
                } else {
                    $destination_account_details = [
                        'type' => 'Internal (Self)',
                        'account_number' => substr($destAccount['account_number'], -4),
                        'account_type' => $destAccount['account_type'],
                        'recipient_name' => $user_full_name // It's still the user
                    ];
                }
            } catch (MongoDBException $e) {
                error_log("MongoDB error fetching internal self destination account: " . $e->getMessage());
                $errors[] = "Error validating destination account.";
            }
        }
        break;

    case 'internal_heritage':
        $recipient_account_number_internal = filter_input(INPUT_POST, 'recipient_account_number_internal', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($recipient_account_number_internal)) {
            $errors[] = "Recipient HomeTown Bank Pa Account Number is required.";
        } elseif (!preg_match('/^\d{10,12}$/', $recipient_account_number_internal)) { // Example regex, adjust as needed
             $errors[] = "Recipient HomeTown Bank Pa Account Number format is invalid (e.g., 10-12 digits).";
        }
        if (empty($recipient_name)) {
            $errors[] = "Recipient Name is required for HomeTown Bank Pa transfers.";
        } else {
            // Try to find the recipient account and user
            try {
                $recipientAccount = $accountsCollection->findOne(['account_number' => $recipient_account_number_internal, 'status' => 'active']);
                if (!$recipientAccount) {
                    $errors[] = "Recipient account not found or is not active.";
                } else {
                    // Prevent self-transfer to other Hometown accounts if it's the same user
                    if ((string)$recipientAccount['user_id'] === (string)(new ObjectId($user_id))) {
                        $errors[] = "Cannot transfer to your own account using 'To Another HomeTown Bank Pa Account' method. Please use 'Between My Accounts'.";
                    } else {
                        $recipientUser = $usersCollection->findOne(['_id' => $recipientAccount['user_id']]);
                        $recipientFullName = trim(($recipientUser['first_name'] ?? '') . ' ' . ($recipientUser['last_name'] ?? ''));

                        // Basic check for name match (can be more sophisticated)
                        if (strtolower($recipientFullName) !== strtolower($recipient_name)) {
                           // Consider this a soft warning or strict error based on bank policy
                           // For now, let's make it a strict error to ensure correct recipient
                           $errors[] = "Recipient name does not match the account holder. Please confirm recipient details.";
                        }
                        $destination_account_details = [
                            'type' => 'Internal (Heritage)',
                            'account_number' => substr($recipientAccount['account_number'], -4),
                            'account_type' => $recipientAccount['account_type'],
                            'recipient_name' => $recipientFullName
                        ];
                    }
                }
            } catch (MongoDBException $e) {
                error_log("MongoDB error fetching internal heritage recipient: " . $e->getMessage());
                $errors[] = "Error validating recipient account details.";
            }
        }
        break;

    case 'external_iban':
        $recipient_bank_name_iban = filter_input(INPUT_POST, 'recipient_bank_name_iban', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_iban = filter_input(INPUT_POST, 'recipient_iban', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_swift_bic = filter_input(INPUT_POST, 'recipient_swift_bic', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_country = filter_input(INPUT_POST, 'recipient_country', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (empty($recipient_name) || empty($recipient_bank_name_iban) || empty($recipient_iban) || empty($recipient_swift_bic) || empty($recipient_country)) {
            $errors[] = "All International Bank Transfer (IBAN/SWIFT) fields are required.";
        }
        // Basic IBAN validation (can be more robust)
        if (!empty($recipient_iban) && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', strtoupper($recipient_iban))) {
            $errors[] = "Invalid IBAN format.";
        }
        // Basic SWIFT/BIC validation
        if (!empty($recipient_swift_bic) && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/i', $recipient_swift_bic)) {
            $errors[] = "Invalid SWIFT/BIC format.";
        }
        $destination_account_details = [
            'type' => 'External (IBAN/SWIFT)',
            'bank_name' => $recipient_bank_name_iban,
            'iban' => $recipient_iban,
            'swift_bic' => $recipient_swift_bic,
            'country' => $recipient_country,
            'recipient_name' => $recipient_name
        ];
        break;

    case 'external_sort_code':
        $recipient_bank_name_sort = filter_input(INPUT_POST, 'recipient_bank_name_sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_sort_code = filter_input(INPUT_POST, 'recipient_sort_code', FILTER_SANITIZE_NUMBER_INT);
        $recipient_external_account_number = filter_input(INPUT_POST, 'recipient_external_account_number', FILTER_SANITIZE_NUMBER_INT);

        if (empty($recipient_name) || empty($recipient_bank_name_sort) || empty($recipient_sort_code) || empty($recipient_external_account_number)) {
            $errors[] = "All UK Bank Transfer (Sort Code/Account No) fields are required.";
        }
        if (!empty($recipient_sort_code) && !preg_match('/^\d{6}$/', $recipient_sort_code)) {
            $errors[] = "Sort Code must be 6 digits.";
        }
        if (!empty($recipient_external_account_number) && !preg_match('/^\d{8}$/', $recipient_external_account_number)) {
            $errors[] = "Account Number must be 8 digits for UK transfers.";
        }
        $destination_account_details = [
            'type' => 'External (UK Sort Code)',
            'bank_name' => $recipient_bank_name_sort,
            'sort_code' => $recipient_sort_code,
            'account_number' => $recipient_external_account_number,
            'recipient_name' => $recipient_name
        ];
        break;

    case 'external_usa_account':
        $recipient_bank_name_usa = filter_input(INPUT_POST, 'recipient_bank_name_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_usa_routing_number = filter_input(INPUT_POST, 'recipient_usa_routing_number', FILTER_SANITIZE_NUMBER_INT);
        $recipient_usa_account_number = filter_input(INPUT_POST, 'recipient_usa_account_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_account_type_usa = filter_input(INPUT_POST, 'recipient_account_type_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_address_usa = filter_input(INPUT_POST, 'recipient_address_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_city_usa = filter_input(INPUT_POST, 'recipient_city_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_state_usa = filter_input(INPUT_POST, 'recipient_state_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $recipient_zip_usa = filter_input(INPUT_POST, 'recipient_zip_usa', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (empty($recipient_name) || empty($recipient_bank_name_usa) || empty($recipient_usa_routing_number) || empty($recipient_usa_account_number) || empty($recipient_account_type_usa) || empty($recipient_address_usa) || empty($recipient_city_usa) || empty($recipient_state_usa) || empty($recipient_zip_usa)) {
            $errors[] = "All USA Bank Transfer fields are required.";
        }
        if (!empty($recipient_usa_routing_number) && !preg_match('/^\d{9}$/', $recipient_usa_routing_number)) {
            $errors[] = "Routing Number must be 9 digits for USA transfers.";
        }
        $destination_account_details = [
            'type' => 'External (USA Account)',
            'bank_name' => $recipient_bank_name_usa,
            'routing_number' => $recipient_usa_routing_number,
            'account_number' => $recipient_usa_account_number,
            'account_type' => $recipient_account_type_usa,
            'address' => [
                'street' => $recipient_address_usa,
                'city' => $recipient_city_usa,
                'state' => $recipient_state_usa,
                'zip' => $recipient_zip_usa
            ],
            'recipient_name' => $recipient_name
        ];
        break;

    default:
        $errors[] = "Invalid transfer method selected.";
        break;
}

if (!empty($errors)) {
    $_SESSION['message'] = "Please correct the following errors: <br>" . implode("<br>", $errors);
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data; // Persist form data
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
}


// 4. Fetch Source Account Details and Check Balance
try {
    $sourceAccount = $accountsCollection->findOne([
        '_id' => new ObjectId($source_account_id),
        'user_id' => new ObjectId($user_id),
        'status' => 'active'
    ]);

    if (!$sourceAccount) {
        throw new Exception("Source account not found or is not active for this user.");
    }

    if ($sourceAccount['balance'] < $amount) {
        throw new Exception("Insufficient balance in source account. Available: " . get_currency_symbol($sourceAccount['currency']) . number_format((float)$sourceAccount['balance'], 2));
    }
} catch (MongoDBException $e) {
    error_log("MongoDB error fetching source account: " . $e->getMessage());
    $_SESSION['message'] = "Error fetching your account details. Please try again.";
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
} catch (Exception $e) {
    error_log("Account validation error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "Transfer failed: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
}

// 5. Generate Reference Number
$reference_number = generateUniqueReferenceNumber(); // Make sure this function exists in functions.php

// 6. Begin Transaction (Conceptual - for a real system, this involves proper database transactions)
// For MongoDB, single operations are atomic, multi-document transactions require replica set.
// Here, we're doing separate updates/inserts, so order and error handling are critical.

try {
    $current_time = new MongoDB\BSON\UTCDateTime(); // Current timestamp in BSON format

    // Deduct amount from source account
    $accountsCollection->updateOne(
        ['_id' => new ObjectId($source_account_id)],
        ['$inc' => ['balance' => -$amount]]
    );

    // If internal self-transfer, add to destination account
    if ($transfer_method === 'internal_self') {
        $accountsCollection->updateOne(
            ['_id' => new ObjectId($destination_account_id_self)],
            ['$inc' => ['balance' => $amount]]
        );
    }

    // Record transaction
    $transaction_data = [
        'user_id' => new ObjectId($user_id),
        'transaction_type' => 'Transfer',
        'method' => $transfer_method,
        'source_account_id' => new ObjectId($source_account_id),
        'amount' => (float)$amount,
        'currency' => $sourceAccount['currency'],
        'description' => $description,
        'status' => 'pending', // All transfers require approval in this system
        'reference_number' => $reference_number,
        'timestamp' => $current_time,
        'recipient_name' => $recipient_name,
        'meta_data' => $destination_account_details // Store recipient details
    ];

    if ($transfer_method === 'internal_self') {
        $transaction_data['destination_account_id'] = new ObjectId($destination_account_id_self);
    } elseif ($transfer_method === 'internal_heritage') {
        $transaction_data['meta_data']['recipient_account_number'] = $recipient_account_number_internal;
    }

    $transactionsCollection->insertOne($transaction_data);

    // 7. Send Email Notification
    $mail = new PHPMailer(true);
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user_email, $user_full_name); // User's email

        //Content
        $mail->isHTML(true);
        $mail->Subject = 'Transfer Initiated - HomeTown Bank Pa';
        $email_body = "
            <p>Dear {$user_full_name},</p>
            <p>Your transfer request has been successfully initiated and is now **pending approval**.</p>
            <p><strong>Transfer Details:</strong></p>
            <ul>
                <li>Amount: " . get_currency_symbol($sourceAccount['currency']) . number_format($amount, 2) . "</li>
                <li>From Account: {$sourceAccount['account_type']} (****" . substr($sourceAccount['account_number'], -4) . ")</li>
                <li>To: {$recipient_name}</li>
                <li>Method: ";

        // Enhance email body based on transfer method
        switch ($transfer_method) {
            case 'internal_self':
                $email_body .= "Between My Accounts";
                $destAccountForEmail = $accountsCollection->findOne(['_id' => new ObjectId($destination_account_id_self)]);
                if ($destAccountForEmail) {
                    $email_body .= " ({$destAccountForEmail['account_type']} ****" . substr($destAccountForEmail['account_number'], -4) . ")";
                }
                break;
            case 'internal_heritage':
                $email_body .= "To Another HomeTown Bank Pa Account (Account No: ****" . substr($recipient_account_number_internal, -4) . ")";
                break;
            case 'external_iban':
                $email_body .= "International Bank Transfer (IBAN: " . $destination_account_details['iban'] . ", SWIFT: " . $destination_account_details['swift_bic'] . ")";
                break;
            case 'external_sort_code':
                $email_body .= "UK Bank Transfer (Sort Code: " . $destination_account_details['sort_code'] . ", Account No: " . $destination_account_details['account_number'] . ")";
                break;
            case 'external_usa_account':
                $email_body .= "USA Bank Transfer (Routing No: " . $destination_account_details['routing_number'] . ", Account No: " . $destination_account_details['account_number'] . ")";
                break;
        }

        $email_body .= "</li>
                <li>Description: " . ($description ?: 'N/A') . "</li>
                <li>Status: Pending Approval</li>
                <li>Reference Number: {$reference_number}</li>
            </ul>
            <p>We will notify you once the transfer has been processed.</p>
            <p>Thank you for banking with HomeTown Bank Pa.</p>
            ";
        $mail->Body = $email_body;

        $mail->send();
        error_log("Transfer confirmation email sent to " . $user_email . " for reference " . $reference_number);
    } catch (PHPMailerException $e) {
        error_log("Error sending transfer confirmation email to {$user_email}: " . $mail->ErrorInfo);
        // Do not die, as the transfer itself was recorded. Just log the email failure.
    }


    // 8. Success: Redirect back to transfer page with success message
    $_SESSION['message'] = "Your transfer of " . get_currency_symbol($sourceAccount['currency']) . number_format($amount, 2) . " has been successfully initiated!";
    $_SESSION['message_type'] = "success";
    $_SESSION['show_modal_on_load'] = true;
    $_SESSION['transfer_success_details'] = [
        'amount' => number_format($amount, 2),
        'currency' => get_currency_symbol($sourceAccount['currency']),
        'recipient' => $recipient_name,
        'status' => 'Pending Approval',
        'reference' => $reference_number,
        'method' => $destination_account_details['type'] // Use the structured type for modal
    ];

    header('Location: ' . BASE_URL . '/dashboard'); // Redirect back to transfer.php in frontend
    exit;

} catch (MongoDBException $e) {
    // If database operations fail after initial deduction (rollback would be needed in a real system)
    error_log("Critical MongoDB error during transfer operations in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "A critical error occurred during the transfer. Please contact support. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
} catch (Exception $e) {
    // General error during transfer process
    error_log("Unexpected error during transfer in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "An unexpected error occurred during the transfer. Please try again. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
}