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
use MongoDB\BSON\UTCDateTime; // Added for explicit UTCDateTime usage
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // Specific exception for driver errors
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
    $user_full_name = $_SESSION['username'] ?? 'Customer'; // Fallback if first/last name not in session
}

// Convert user_id from session (string) to MongoDB ObjectId
try {
    $userObjectId = new ObjectId($user_id);
} catch (Exception $e) {
    error_log("Invalid user ID in session: " . $user_id);
    $_SESSION['message'] = "Invalid user ID. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// 2. Establish MongoDB Connection and Fetch Collections
$client = null;
$session = null; // Initialize session for MongoDB transactions
try {
    $client = getMongoDBClient(); // Use the helper function for consistency
    $db = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $db->accounts;
    $transactionsCollection = $db->transactions;
    $usersCollection = $db->users;
} catch (MongoDBDriverException $e) { // Catch specific MongoDB driver exceptions
    error_log("MongoDB connection error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: Database connection failed. Please try again later. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST; // Make sure to preserve form data on DB connection error
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
} catch (Exception $e) { // Catch general exceptions (e.g., from getMongoDBClient if it throws)
    error_log("General database connection error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: An unexpected error occurred during database connection. Please try again later. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST; // Make sure to preserve form data on general DB connection error
    header('Location: ' . BASE_URL . '/transfer'); // Redirect back to transfer.php in frontend
    exit;
}

// 3. Fetch User's Card Activation Status (for external transfer restriction)
$has_active_card = false;
try {
    $user_data = $usersCollection->findOne(['_id' => $userObjectId]);

    if (!$user_data) {
        throw new Exception("Your user data could not be found. Please contact support.");
    }
    // Assumes 'has_active_card' is a boolean field in the users collection
    $has_active_card = $user_data['has_active_card'] ?? false;
    error_log("User {$user_id} has_active_card: " . ($has_active_card ? 'true' : 'false'));

} catch (MongoDBDriverException $e) {
    error_log("MongoDB error fetching user for card activation check: " . $e->getMessage());
    $_SESSION['message'] = "Error verifying your account status for transfer. Please try again.";
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST;
    header('Location: ' . BASE_URL . '/transfer');
    exit;
} catch (Exception $e) {
    error_log("User data error for card activation check: " . $e->getMessage());
    $_SESSION['message'] = "Transfer failed: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST;
    header('Location: ' . BASE_URL . '/transfer');
    exit;
}


// 4. Validate and Sanitize Input
$form_data = $_POST; // Store all POST data for re-population on error

$source_account_id_str = filter_input(INPUT_POST, 'source_account_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$transfer_method = filter_input(INPUT_POST, 'transfer_method', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$recipient_name = filter_input(INPUT_POST, 'recipient_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

$errors = [];

// Validate source_account_id and convert to ObjectId
$source_account_id = null;
if (empty($source_account_id_str) || !ObjectId::isValid($source_account_id_str)) {
    $errors[] = "Invalid source account selected.";
} else {
    try {
        $source_account_id = new ObjectId($source_account_id_str);
    } catch (Exception $e) {
        $errors[] = "Invalid source account ID format.";
    }
}

if (empty($transfer_method)) {
    $errors[] = "Transfer method is required.";
}
if ($amount === false || $amount <= 0) {
    $errors[] = "Valid amount is required (must be greater than 0).";
}

// Specific validation based on transfer method
$destination_account_details = []; // Will store details of the destination for logging and display
$recipientAccount = null; // To store recipient account for internal transfers

// Flag to check if the transfer is an external type
$is_external_transfer = in_array($transfer_method, ['external_iban', 'external_sort_code', 'external_usa_account']);

// Card activation check for external transfers - moved here for immediate validation feedback
if ($is_external_transfer && !$has_active_card) {
    $errors[] = "External transfers (International, UK, USA) require a physical bank card to be activated on your account. Please activate your card to proceed.";
}

switch ($transfer_method) {
    case 'internal_self':
        $destination_account_id_self_str = filter_input(INPUT_POST, 'destination_account_id_self', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($destination_account_id_self_str)) {
            $errors[] = "Destination account is required for transfers between your accounts.";
        } elseif ($source_account_id_str === $destination_account_id_self_str) {
            $errors[] = "Source and destination accounts cannot be the same.";
        } else {
            // Fetch destination account details for logging
            try {
                $destination_account_id_self = new ObjectId($destination_account_id_self_str);
                $recipientAccount = $accountsCollection->findOne(['_id' => $destination_account_id_self, 'user_id' => $userObjectId, 'status' => 'active']);
                if (!$recipientAccount) {
                    $errors[] = "Invalid or inactive destination account selected for self-transfer.";
                } else {
                    $destination_account_details = [
                        'type' => 'Internal (Self)',
                        'account_number' => $recipientAccount['account_number'],
                        'account_type' => $recipientAccount['account_type'],
                        'recipient_name' => $user_full_name // It's still the user
                    ];
                }
            } catch (MongoDBDriverException $e) {
                error_log("MongoDB error fetching internal self destination account: " . $e->getMessage());
                $errors[] = "Error validating destination account.";
            } catch (Exception $e) {
                $errors[] = "Invalid destination account ID format for self-transfer.";
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
                    if ($recipientAccount['user_id'] == $userObjectId) { // Compare ObjectIds directly
                        $errors[] = "Cannot transfer to your own account using 'To Another HomeTown Bank Pa Account' method. Please use 'Between My Accounts'.";
                    } else {
                        $recipientUser = $usersCollection->findOne(['_id' => $recipientAccount['user_id']]);
                        $recipientFullName = trim(($recipientUser['first_name'] ?? '') . ' ' . ($recipientUser['last_name'] ?? ''));

                        // Basic check for name match (can be more sophisticated or a warning)
                        if (strtolower($recipientFullName) !== strtolower($recipient_name)) {
                            $errors[] = "Recipient name does not match the account holder. Please confirm recipient details.";
                        }
                        $destination_account_details = [
                            'type' => 'Internal (Heritage)',
                            'account_number' => $recipientAccount['account_number'], // Store full number
                            'account_type' => $recipientAccount['account_type'],
                            'recipient_name' => $recipientFullName
                        ];
                    }
                }
            } catch (MongoDBDriverException $e) {
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
        // Basic IBAN validation (can be more robust with checksum validation)
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


// 5. Fetch Source Account Details and Check Balance (now inside transaction block)
try {
    // Start a MongoDB session for transaction
    $session = $client->startSession();
    $session->startTransaction();

    $sourceAccount = $accountsCollection->findOne(
        ['_id' => $source_account_id, 'user_id' => $userObjectId, 'status' => 'active'],
        ['session' => $session] // Pass session to findOne
    );

    if (!$sourceAccount) {
        throw new Exception("Source account not found or is not active for your user.");
    }

    // Ensure numeric balance for calculations
    $sourceAccountBalance = (float)($sourceAccount['balance'] ?? 0);

    if ($sourceAccountBalance < $amount) {
        throw new Exception("Insufficient balance in source account. Available: " . get_currency_symbol($sourceAccount['currency']) . number_format($sourceAccountBalance, 2));
    }

    // 6. Deduct Amount and Record Transaction within the transaction
    $current_time = new UTCDateTime(); // Current timestamp in BSON format
    $transaction_reference = generateUniqueReferenceNumber(); // Make sure this function exists in functions.php

    // Debit amount from source account
    $updateResult = $accountsCollection->updateOne(
        ['_id' => $source_account_id],
        ['$inc' => ['balance' => -$amount]],
        ['session' => $session]
    );

    if ($updateResult->getModifiedCount() === 0) {
        throw new Exception("Failed to deduct amount from source account. Account might have been modified concurrently.");
    }

    $initial_status = 'pending'; // Default for most transfers
    $completed_at = null;
    $transaction_type_db = 'DEBIT'; // Default, will be refined below

    // Handle internal self-transfer (instant completion)
    if ($transfer_method === 'internal_self') {
        $updateDestAccountResult = $accountsCollection->updateOne(
            ['_id' => $destination_account_id_self],
            ['$inc' => ['balance' => $amount]],
            ['session' => $session]
        );
        if ($updateDestAccountResult->getModifiedCount() === 0) {
            throw new Exception("Failed to credit destination account for self-transfer. Account might be locked or not found during update.");
        }
        $initial_status = 'completed';
        $completed_at = $current_time;
        $transaction_type_db = 'INTERNAL_SELF_TRANSFER_OUT'; // Specific type
    } elseif ($transfer_method === 'internal_heritage') {
        $transaction_type_db = 'INTERNAL_TRANSFER_OUT';
    } elseif ($transfer_method === 'external_iban') {
        $transaction_type_db = 'EXTERNAL_IBAN_TRANSFER_OUT';
    } elseif ($transfer_method === 'external_sort_code') {
        $transaction_type_db = 'EXTERNAL_SORT_CODE_TRANSFER_OUT';
    } elseif ($transfer_method === 'external_usa_account') {
        $transaction_type_db = 'EXTERNAL_USA_TRANSFER_OUT';
    }

    // Record the sender's debit transaction
    $transaction_data = [
        'user_id' => $userObjectId,
        'account_id' => $source_account_id, // The account from which funds are debited
        'transaction_type' => $transaction_type_db,
        'amount' => (float)$amount,
        'currency' => $sourceAccount['currency'],
        'description' => $description,
        'status' => $initial_status,
        'transaction_reference' => $transaction_reference,
        'initiated_at' => $current_time,
        'completed_at' => $completed_at, // Only set for instant transfers (internal_self)
        'sender_name' => $user_full_name,
        'sender_account_number' => $sourceAccount['account_number'],
        'recipient_name' => $recipient_name,
        'recipient_account_number' => $destination_account_details['account_number'] ?? ($recipient_account_number_internal ?? null), // For internal transfers
        'recipient_user_id' => ($transfer_method === 'internal_self' || $transfer_method === 'internal_heritage') ? $recipientAccount['user_id'] : null,
        'recipient_account_id' => ($transfer_method === 'internal_self' || $transfer_method === 'internal_heritage') ? $recipientAccount['_id'] : null,
        'meta_data' => $destination_account_details // Store recipient details
    ];

    $insertResult = $transactionsCollection->insertOne($transaction_data, ['session' => $session]);
    if (!$insertResult->getInsertedId()) {
        throw new Exception("Failed to record the transfer transaction.");
    }

    // Commit the transaction if all database operations were successful
    $session->commitTransaction();

    // --- New: Set session variable for the outstanding payment modal ---
    if ($is_external_transfer && $has_active_card) {
        $_SESSION['show_outstanding_payment_modal'] = true;
        $_SESSION['outstanding_payment_modal_user_name'] = $user_full_name;
    }
    // --- End New ---

    // 7. Send Email Notification (after successful database operations)
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
        $mail->Subject = 'Transfer Initiated - HomeTown Bank Pa - Ref: ' . $transaction_reference;
        $email_status_text = ($initial_status === 'completed') ? 'completed successfully' : 'initiated and is now pending approval';

        $email_body = "
            <p>Dear {$user_full_name},</p>
            <p>Your transfer request has been successfully {$email_status_text}.</p>
            <p><strong>Transfer Details:</strong></p>
            <ul>
                <li>Amount: " . get_currency_symbol($sourceAccount['currency']) . number_format($amount, 2) . "</li>
                <li>From Account: {$sourceAccount['account_type']} (****" . substr($sourceAccount['account_number'], -4) . ")</li>
                <li>To: " . htmlspecialchars($recipient_name) . "</li>
                <li>Method: ";

        // Enhance email body based on transfer method and sanitize output
        switch ($transfer_method) {
            case 'internal_self':
                $email_body .= "Between My Accounts";
                // $recipientAccount is already fetched and contains details
                if ($recipientAccount) {
                    $email_body .= " ({$recipientAccount['account_type']} ****" . substr($recipientAccount['account_number'], -4) . ")";
                }
                break;
            case 'internal_heritage':
                $email_body .= "To Another HomeTown Bank Pa Account (Account No: ****" . substr($recipient_account_number_internal, -4) . ")";
                break;
            case 'external_iban':
                $email_body .= "International Bank Transfer (IBAN: " . htmlspecialchars($destination_account_details['iban']) . ", SWIFT: " . htmlspecialchars($destination_account_details['swift_bic']) . ")";
                break;
            case 'external_sort_code':
                $email_body .= "UK Bank Transfer (Sort Code: " . htmlspecialchars($destination_account_details['sort_code']) . ", Account No: " . htmlspecialchars($destination_account_details['account_number']) . ")";
                break;
            case 'external_usa_account':
                $email_body .= "USA Bank Transfer (Routing No: " . htmlspecialchars($destination_account_details['routing_number']) . ", Account No: " . htmlspecialchars($destination_account_details['account_number']) . ")";
                break;
            default:
                $email_body .= "Unknown Method"; // Fallback
                break;
        }

        $email_body .= "</li>
                <li>Description: " . (!empty($description) ? htmlspecialchars($description) : 'N/A') . "</li>
                <li>Status: " . ucfirst($initial_status) . "</li>
                <li>Reference Number: {$transaction_reference}</li>
            </ul>
            <p>We will notify you once the transfer has been fully processed.</p>
            <p>Thank you for banking with HomeTown Bank Pa.</p>
            ";
        $mail->Body = $email_body;

        $mail->send();
        error_log("Transfer confirmation email sent to " . $user_email . " for reference " . $transaction_reference);
    } catch (PHPMailerException $e) {
        error_log("Error sending transfer confirmation email to {$user_email}: " . $mail->ErrorInfo);
        // Do not die, as the transfer itself was recorded. Just log the email failure.
        $_SESSION['message'] = "Your transfer has been initiated, but we failed to send a confirmation email. Error: " . $e->getMessage();
        $_SESSION['message_type'] = "warning"; // Set as warning instead of success
        // Do not return here, continue to final redirect to show the warning message
    }


    // 8. Success: Redirect back to transfer page with success message
    // If email failed, the session message would have been updated to 'warning'
    if (!isset($_SESSION['message_type']) || $_SESSION['message_type'] !== 'warning') {
        $_SESSION['message'] = "Your transfer of " . get_currency_symbol($sourceAccount['currency']) . number_format($amount, 2) . " has been successfully initiated!";
        $_SESSION['message_type'] = "success";
    }

    $_SESSION['show_modal_on_load'] = true;
    $_SESSION['transfer_success_details'] = [
        'amount' => number_format($amount, 2),
        'currency' => get_currency_symbol($sourceAccount['currency']),
        'recipient' => htmlspecialchars($recipient_name),
        'status' => ucfirst($initial_status),
        'reference' => $transaction_reference,
        'method' => $destination_account_details['type'] // Use the structured type for modal
    ];

    header('Location: ' . BASE_URL . '/dashboard'); // Redirect to dashboard or transfer page
    exit;

} catch (MongoDBDriverException $e) { // Catch specific MongoDB driver exceptions for transaction issues
    if ($session && $session->inTransaction()) {
        $session->abortTransaction(); // Rollback if any error occurs within the transaction
    }
    error_log("MongoDB Transaction error during transfer operations in frontend/make_transfer.php: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
    $_SESSION['message'] = "A database error occurred during the transfer. Your funds have not been debited. Please try again. Detail: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer');
    exit;
} catch (Exception $e) { // Catch general exceptions (e.g., balance insufficient, invalid account, or other logic errors)
    if ($session && $session->inTransaction()) {
        $session->abortTransaction(); // Rollback if any custom error occurs before commit
    }
    error_log("General transfer processing error in frontend/make_transfer.php: " . $e->getMessage());
    $_SESSION['message'] = "An error occurred during the transfer: " . $e->getMessage();
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $form_data;
    header('Location: ' . BASE_URL . '/transfer');
    exit;
} finally {
    if ($session) {
        $session->endSession(); // Always end the session
    }
}
?>