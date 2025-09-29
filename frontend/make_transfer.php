<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\make_transfer.php

// Ensure session is started FIRST.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Load Config.php first. It handles Composer's autoload.php and defines global constants.
require_once __DIR__ . '/../Config.php';

// Then load functions.php, which might depend on constants or autoloader from Config.php.
require_once __DIR__ . '/../functions.php'; // Contains get_currency_symbol, generateUniqueReferenceNumber, sanitize_input, and sendEmail

// Composer's autoloader is now available due to Config.php
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception as MongoDBException;

// Enable error display based on APP_DEBUG in Config.php
if (defined('APP_DEBUG') && APP_DEBUG === true) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0); // Turn off all error reporting in production
}

// Redirect to login if user is not logged in or session is invalid
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    error_log("make_transfer.php: Unauthorized access attempt. User not logged in.");
    $_SESSION['message'] = "You must be logged in to make a transfer.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id']; // Get user ID from session

// Establish MongoDB connection
try {
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
    $usersCollection = $db->users; // To update user modal status and get user email
} catch (MongoDBException $e) {
    error_log("make_transfer.php: MongoDB connection error: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: Could not connect to the database. Please try again later.";
    $_SESSION['message_type'] = "error";
    // Store form data to re-populate the form
    $_SESSION['form_data'] = $_POST;
    header('Location: ' . BASE_URL . '/frontend/transfer.php');
    exit;
} catch (Exception $e) {
    error_log("make_transfer.php: General database connection error: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: An unexpected error occurred during database connection.";
    $_SESSION['message_type'] = "error";
    $_SESSION['form_data'] = $_POST;
    header('Location: ' . BASE_URL . '/frontend/transfer.php');
    exit;
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate_transfer'])) {

    // ----------------------------------------------------------------------
    // 🔥 FIX START: Decode the transfer data payload from the PIN form
    // ----------------------------------------------------------------------
    if (isset($_POST['transfer_data_payload']) && !empty($_POST['transfer_data_payload'])) {
        $payload_data = json_decode($_POST['transfer_data_payload'], true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($payload_data)) {
            // Merge the decoded payload into the $_POST array.
            // This makes the original form data available to the script 
            // under the correct keys (e.g., $_POST['source_account_id']).
            $_POST = array_merge($_POST, $payload_data);
        } else {
            // Log JSON decoding error and terminate
            error_log("make_transfer.php: Error decoding transfer_data_payload JSON. Error: " . json_last_error_msg());
            $_SESSION['message'] = "A critical data submission error occurred during security verification. Please try again.";
            $_SESSION['message_type'] = "error";
            header('Location: ' . BASE_URL . '/frontend/transfer.php');
            exit;
        }
    }
    // ----------------------------------------------------------------------
    // 🔥 FIX END
    // ----------------------------------------------------------------------

    // Sanitize and validate common inputs (These now correctly read from the merged $_POST)
    $source_account_id = sanitize_input($_POST['source_account_id'] ?? '');
    $amount = filter_var($_POST['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $description = sanitize_input($_POST['description'] ?? '');
    $transfer_method = sanitize_input($_POST['transfer_method'] ?? '');
    $recipient_name = sanitize_input($_POST['recipient_name'] ?? '');
    // Get the submitted Transfer PIN (this is sent directly, not in the payload)
    $transfer_pin = sanitize_input($_POST['transfer_pin'] ?? ''); 

    // Store current form data for re-population in case of error
    $_SESSION['form_data'] = $_POST;

    // Basic validation (This validation block now succeeds because of the fix above)
    if (empty($source_account_id) || $amount === false || $amount <= 0 || empty($transfer_method)) {
        $_SESSION['message'] = "Invalid transfer details. Please ensure all required fields are filled correctly.";
        $_SESSION['message_type'] = "error";
        error_log("make_transfer.php: Validation error - missing or invalid basic fields after payload merge.");
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }
    
    // START SECURE PIN VALIDATION BLOCK 
    
    // 1. Validate Transfer PIN format
    if (empty($transfer_pin) || !preg_match('/^\d{4}$/', $transfer_pin)) {
        $_SESSION['message'] = "A valid 4-digit Transfer PIN is required to complete the transfer.";
        $_SESSION['message_type'] = "error";
        error_log("make_transfer.php: Validation error - invalid or missing Transfer PIN format.");
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }

    // 2. Fetch the user's record to get the stored PIN hash
    try {
        $user_data = $usersCollection->findOne(['_id' => new ObjectId($user_id)], ['projection' => ['transfer_pin_hash' => 1]]);
    } catch (MongoDBException $e) {
        error_log("make_transfer.php: Failed to fetch user data for PIN validation: " . $e->getMessage());
        $_SESSION['message'] = "A security check error occurred. Please try again.";
        $_SESSION['message_type'] = "error";
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }


    if (!$user_data || !isset($user_data['transfer_pin_hash']) || empty($user_data['transfer_pin_hash'])) {
        $_SESSION['message'] = "Transfer PIN is not set up for your account. Please contact support.";
        $_SESSION['message_type'] = "error";
        // ADDED ERROR LOG FOR PIN HASH MISSING
        error_log("make_transfer.php: Security error - User record or PIN hash missing for user: " . $user_id); 
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }

    $stored_pin_hash = $user_data['transfer_pin_hash'];

    // 3. Verify the submitted PIN against the stored hash
    if (!password_verify($transfer_pin, $stored_pin_hash)) {
        // Failure: The entered PIN does not match the stored hash
        $_SESSION['message'] = "Invalid Transfer PIN. The transaction cannot be completed.";
        $_SESSION['message_type'] = "error";
        // ADDED ERROR LOG FOR INVALID PIN
        error_log("make_transfer.php: Security failure - Invalid Transfer PIN submitted by user: " . $user_id);
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }
    
    // END SECURE PIN VALIDATION BLOCK

    // Convert source_account_id to ObjectId
    try {
        $sourceAccountIdObject = new ObjectId($source_account_id);
    } catch (MongoDBException $e) {
        $_SESSION['message'] = "Invalid source account ID format.";
        $_SESSION['message_type'] = "error";
        error_log("make_transfer.php: Invalid source account ObjectId: " . $e->getMessage());
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }

    // Fetch the source account details
    $sourceAccount = $accountsCollection->findOne([
        '_id' => $sourceAccountIdObject,
        'user_id' => new ObjectId($user_id), // Ensure the account belongs to the logged-in user
        'status' => 'active'
    ]);

    if (!$sourceAccount) {
        $_SESSION['message'] = "Source account not found or does not belong to you, or is inactive.";
        $_SESSION['message_type'] = "error";
        error_log("make_transfer.php: Source account not found/owned/active for user_id: " . $user_id . ", account_id: " . $source_account_id);
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }

    // *** REMOVED NON-ATOMIC BALANCE CHECK HERE ***
    // The check is moved inside the transaction block for security against race conditions.

    $transaction_data = [
        'user_id' => new ObjectId($user_id),
        'source_account_id' => $sourceAccountIdObject,
        'source_account_number' => $sourceAccount['account_number'],
        'source_account_type' => $sourceAccount['account_type'],
        'amount' => $amount,
        'currency' => $sourceAccount['currency'], // Use source account's currency
        'description' => $description,
        'transfer_method' => $transfer_method,
        'status' => 'pending', // All transfers initially pending approval
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime(),
        'reference_number' => generateUniqueReferenceNumber(),
        'recipient_name' => $recipient_name,
        'type' => 'debit' // Mark as debit from source account
    ];

    $destination_account_display = null; // Initialize for use in modal details and email

    // Handle different transfer methods
    switch ($transfer_method) {
        case 'internal_self':
            $destination_account_id_self = sanitize_input($_POST['destination_account_id_self'] ?? '');
            if (empty($destination_account_id_self)) {
                $_SESSION['message'] = "Please select a destination account for internal self-transfer.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            try {
                $destinationAccountIdObject = new ObjectId($destination_account_id_self);
            } catch (MongoDBException $e) {
                $_SESSION['message'] = "Invalid destination account ID format.";
                $_SESSION['message_type'] = "error";
                error_log("make_transfer.php: Invalid destination account ObjectId for internal_self: " . $e->getMessage());
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            // Ensure source and destination are different
            if ($source_account_id === $destination_account_id_self) {
                $_SESSION['message'] = "You cannot transfer money to the same account.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            $destinationAccount = $accountsCollection->findOne([
                '_id' => $destinationAccountIdObject,
                'user_id' => new ObjectId($user_id), // Must belong to the same user
                'status' => 'active'
            ]);

            if (!$destinationAccount) {
                $_SESSION['message'] = "Destination account not found or does not belong to you, or is inactive.";
                $_SESSION['message_type'] = "error";
                error_log("make_transfer.php: Destination account not found/owned/active for internal_self.");
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            // Add destination account details to transaction data
            $transaction_data['destination_account_id'] = $destinationAccountIdObject;
            $transaction_data['destination_account_number'] = $destinationAccount['account_number'];
            $transaction_data['destination_account_type'] = $destinationAccount['account_type'];
            // Auto-fill recipient for self-transfer
            $transaction_data['recipient_name'] = $sourceAccount['first_name'] . ' ' . $sourceAccount['last_name'];
            $destination_account_display = $destinationAccount['account_type'] . ' (****' . substr($destinationAccount['account_number'], -4) . ')';
            break;

        case 'internal_heritage':
            $recipient_account_number_internal = sanitize_input($_POST['recipient_account_number_internal'] ?? '');

            if (empty($recipient_account_number_internal)) {
                $_SESSION['message'] = "Recipient account number is required for HomeTown Bank Pa transfers.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            // Find the recipient account in our system
            $destinationAccount = $accountsCollection->findOne([
                'account_number' => $recipient_account_number_internal,
                'status' => 'active'
            ]);

            if (!$destinationAccount) {
                $_SESSION['message'] = "Recipient HomeTown Bank Pa account not found or is inactive.";
                $_SESSION['message_type'] = "error";
                error_log("make_transfer.php: Internal Heritage destination account not found: " . $recipient_account_number_internal);
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            // Prevent transfer to own account if it's the same as source (though handled by `internal_self` usually)
            if ((string)$sourceAccount['_id'] === (string)$destinationAccount['_id']) {
                $_SESSION['message'] = "You cannot transfer money to the same account (please use 'Between My Accounts' if intended).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            // Add destination account details to transaction data
            $transaction_data['destination_account_id'] = $destinationAccount['_id'];
            $transaction_data['destination_account_number'] = $destinationAccount['account_number'];
            $transaction_data['destination_account_type'] = $destinationAccount['account_type'];
            // If recipient_name was provided, use it, else try to get from destination account's user
            if (empty($recipient_name) && isset($destinationAccount['user_id'])) {
                $recipientUser = $usersCollection->findOne(['_id' => $destinationAccount['user_id']]);
                if ($recipientUser) {
                    $recipient_name = trim(($recipientUser['first_name'] ?? '') . ' ' . ($recipientUser['last_name'] ?? ''));
                }
            }
            $transaction_data['recipient_name'] = $recipient_name; // Set it back after potential update
            $destination_account_display = $destinationAccount['account_type'] . ' (****' . substr($destinationAccount['account_number'], -4) . ')';
            break;

        case 'external_iban':
            $recipient_bank_name_iban = sanitize_input($_POST['recipient_bank_name_iban'] ?? '');
            $recipient_iban = sanitize_input($_POST['recipient_iban'] ?? '');
            $recipient_swift_bic = sanitize_input($_POST['recipient_swift_bic'] ?? '');
            $recipient_country = sanitize_input($_POST['recipient_country'] ?? '');

            if (empty($recipient_bank_name_iban) || empty($recipient_iban) || empty($recipient_swift_bic) || empty($recipient_country) || empty($recipient_name)) {
                $_SESSION['message'] = "All recipient international bank details (Name, IBAN, SWIFT/BIC, Country, Recipient Name) are required.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            $transaction_data['recipient_bank_name'] = $recipient_bank_name_iban;
            $transaction_data['recipient_iban'] = $recipient_iban;
            $transaction_data['recipient_swift_bic'] = $recipient_swift_bic;
            $transaction_data['recipient_country'] = $recipient_country;
            $destination_account_display = "IBAN: " . htmlspecialchars($recipient_iban) . " (SWIFT: " . htmlspecialchars($recipient_swift_bic) . ")";
            break;

        case 'external_sort_code':
            $recipient_bank_name_sort = sanitize_input($_POST['recipient_bank_name_sort'] ?? '');
            $recipient_sort_code = sanitize_input($_POST['recipient_sort_code'] ?? '');
            $recipient_external_account_number = sanitize_input($_POST['recipient_external_account_number'] ?? '');

            // Basic validation for UK Sort Code/Account
            if (empty($recipient_bank_name_sort) || empty($recipient_sort_code) || empty($recipient_external_account_number) || empty($recipient_name)) {
                $_SESSION['message'] = "All recipient UK bank details (Name, Bank Name, Sort Code, Account Number, Recipient Name) are required.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            if (!preg_match('/^\d{6}$/', $recipient_sort_code)) {
                $_SESSION['message'] = "Invalid UK Sort Code format (must be 6 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            if (!preg_match('/^\d{8}$/', $recipient_external_account_number)) {
                $_SESSION['message'] = "Invalid UK Account Number format (must be 8 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            $transaction_data['recipient_bank_name'] = $recipient_bank_name_sort;
            $transaction_data['recipient_sort_code'] = $recipient_sort_code;
            $transaction_data['recipient_external_account_number'] = $recipient_external_account_number;
            $destination_account_display = "UK Sort Code: " . htmlspecialchars($recipient_sort_code) . " Acc: " . htmlspecialchars($recipient_external_account_number);
            break;

        case 'external_usa_account':
            $recipient_bank_name_usa = sanitize_input($_POST['recipient_bank_name_usa'] ?? '');
            $recipient_usa_routing_number = sanitize_input($_POST['recipient_usa_routing_number'] ?? '');
            $recipient_usa_account_number = sanitize_input($_POST['recipient_usa_account_number'] ?? '');
            $recipient_account_type_usa = sanitize_input($_POST['recipient_account_type_usa'] ?? '');
            $recipient_address_usa = sanitize_input($_POST['recipient_address_usa'] ?? '');
            $recipient_city_usa = sanitize_input($_POST['recipient_city_usa'] ?? '');
            $recipient_state_usa = sanitize_input($_POST['recipient_state_usa'] ?? '');
            $recipient_zip_usa = sanitize_input($_POST['recipient_zip_usa'] ?? '');

            if (empty($recipient_bank_name_usa) || empty($recipient_usa_routing_number) || empty($recipient_usa_account_number) || empty($recipient_account_type_usa) || empty($recipient_name)) {
                $_SESSION['message'] = "All recipient USA bank details (Name, Bank Name, Routing No, Account No, Account Type, Recipient Name) are required.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            if (!preg_match('/^\d{9}$/', $recipient_usa_routing_number)) {
                $_SESSION['message'] = "Invalid USA Routing Number format (must be 9 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            $transaction_data['recipient_bank_name'] = $recipient_bank_name_usa;
            $transaction_data['recipient_usa_routing_number'] = $recipient_usa_routing_number;
            $transaction_data['recipient_usa_account_number'] = $recipient_usa_account_number;
            $transaction_data['recipient_account_type_usa'] = $recipient_account_type_usa;
            $transaction_data['recipient_address_usa'] = $recipient_address_usa;
            $transaction_data['recipient_city_usa'] = $recipient_city_usa;
            $transaction_data['recipient_state_usa'] = $recipient_state_usa;
            $transaction_data['recipient_zip_usa'] = $recipient_zip_usa;
            $destination_account_display = "USA Bank: " . htmlspecialchars($recipient_usa_routing_number) . " Acc: " . htmlspecialchars($recipient_usa_account_number);
            break;

        case 'external_canada_eft': // Canadian Electronic Funds Transfer (EFT)
            $recipient_bank_name_canada = sanitize_input($_POST['recipient_bank_name_canada'] ?? '');
            $recipient_transit_number_canada = sanitize_input($_POST['recipient_transit_number_canada'] ?? '');
            $recipient_institution_number_canada = sanitize_input($_POST['recipient_institution_number_canada'] ?? '');
            $recipient_external_account_number_canada = sanitize_input($_POST['recipient_external_account_number_canada'] ?? '');

            // Basic validation for Canadian EFT
            if (empty($recipient_bank_name_canada) || empty($recipient_transit_number_canada) || empty($recipient_institution_number_canada) || empty($recipient_external_account_number_canada) || empty($recipient_name)) {
                $_SESSION['message'] = "All recipient Canadian bank details (Bank Name, Transit No, Institution No, Account No, Recipient Name) are required.";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            // Canadian Transit Number (5 digits)
            if (!preg_match('/^\d{5}$/', $recipient_transit_number_canada)) {
                $_SESSION['message'] = "Invalid Canadian Transit Number format (must be 5 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            // Canadian Institution Number (3 digits)
            if (!preg_match('/^\d{3}$/', $recipient_institution_number_canada)) {
                $_SESSION['message'] = "Invalid Canadian Institution Number format (must be 3 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }
            // Canadian Account Number (usually 7-12 digits)
            if (!preg_match('/^\d{7,12}$/', $recipient_external_account_number_canada)) {
                $_SESSION['message'] = "Invalid Canadian Account Number format (must be 7 to 12 digits).";
                $_SESSION['message_type'] = "error";
                header('Location: ' . BASE_URL . '/frontend/transfer.php');
                exit;
            }

            $transaction_data['recipient_bank_name'] = $recipient_bank_name_canada;
            $transaction_data['recipient_transit_number_canada'] = $recipient_transit_number_canada;
            $transaction_data['recipient_institution_number_canada'] = $recipient_institution_number_canada;
            $transaction_data['recipient_external_account_number'] = $recipient_external_account_number_canada;
            $destination_account_display = "Canada EFT: Transit " . htmlspecialchars($recipient_transit_number_canada) . " Inst: " . htmlspecialchars($recipient_institution_number_canada) . " Acc: " . htmlspecialchars($recipient_external_account_number_canada);
            break;
            
        default:
            $_SESSION['message'] = "Invalid transfer method selected.";
            $_SESSION['message_type'] = "error";
            error_log("make_transfer.php: Invalid transfer method: " . $transfer_method);
            header('Location: ' . BASE_URL . '/frontend/transfer.php');
            exit;
    }


    try {
        // Start a MongoDB session for ACID properties on critical updates
        $session = $client->startSession();
        $session->startTransaction();

        // 1. Decrement source account balance ATOMICALLY.
        // CRITICAL CORRECTION: Use $gte in the filter to prevent race conditions and over-debiting.
        $updateResult = $accountsCollection->updateOne(
            [
                '_id' => $sourceAccountIdObject,
                // Atomic balance check
                'balance' => ['$gte' => $amount] 
            ],
            ['$inc' => ['balance' => -$amount]],
            ['session' => $session]
        );

        if ($updateResult->getModifiedCount() !== 1) {
            $session->abortTransaction();
            $_SESSION['message_type'] = "error";
            
            // Check if the source account was found but NOT modified (i.e., balance insufficient due to race condition or initial insufficient funds)
            if ($updateResult->getMatchedCount() === 1 && $updateResult->getModifiedCount() === 0) {
                $_SESSION['message'] = "Insufficient balance in the selected account. The balance may have changed during processing.";
                error_log("make_transfer.php: Atomic check failed - Insufficient balance during update for user " . $user_id);
            } else {
                $_SESSION['message'] = "Failed to update source account balance. Please try again.";
                error_log("make_transfer.php: Failed to decrement source account " . $source_account_id . " for user " . $user_id . ". Matched: " . $updateResult->getMatchedCount() . ", Modified: " . $updateResult->getModifiedCount());
            }

            header('Location: ' . BASE_URL . '/frontend/transfer.php');
            exit;
        }

        // 2. Insert the transaction record
        $insertResult = $transactionsCollection->insertOne($transaction_data, ['session' => $session]);

        if (!$insertResult->getInsertedId()) {
            $session->abortTransaction();
            $_SESSION['message'] = "Failed to record the transfer. Please try again.";
            $_SESSION['message_type'] = "error";
            error_log("make_transfer.php: Failed to insert transaction record.");
            header('Location: ' . BASE_URL . '/frontend/transfer.php');
            exit;
        }

        // 3. Update the user's show_transfer_modal to false, so the admin message is only shown once
        $usersCollection->updateOne(
            ['_id' => new ObjectId($user_id)],
            ['$set' => ['show_transfer_modal' => false]],
            ['session' => $session]
        );
        // Error logging for this specific update is optional, as it's not critical to the transfer itself

        $session->commitTransaction();

        // Transfer successful, prepare message and details for modal
        $_SESSION['message'] = "Transfer request submitted successfully! It is currently awaiting approval.";
        $_SESSION['message_type'] = "success";
        $_SESSION['show_modal_on_load'] = true;
        $_SESSION['transfer_success_details'] = [
            'amount' => number_format($amount, 2),
            'currency' => get_currency_symbol($sourceAccount['currency']),
            'recipient' => $transaction_data['recipient_name'] . ' (' . ($destination_account_display ?? 'External Bank') . ')',
            'status' => 'Pending',
            'reference_number' => $transaction_data['reference_number'],
            'transfer_method' => str_replace('_', ' ', $transfer_method)
        ];

        // --- Start of Email Notification for User ---
        // Fetch user's email for notification
        // Note: The user data was already fetched once for the PIN, but refetching/re-projecting here for robustness is fine.
        $user = $usersCollection->findOne(['_id' => new ObjectId($user_id)], ['projection' => ['email' => 1, 'first_name' => 1, 'last_name' => 1]]);
        if ($user && isset($user['email'])) {
            $user_email = $user['email'];
            $user_full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if (empty($user_full_name)) {
                $user_full_name = "Valued Customer"; // Fallback name
            }

            $email_subject = "Your Transfer Request Confirmation - Ref: " . $transaction_data['reference_number'];

$email_body = '
<div style="font-family: Arial, sans-serif; background-color: #1a1532; color: #FFFFFF; padding: 30px; line-height: 1.6;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #2e285a; padding: 25px; border-radius: 12px; border: 1px solid #4a4087; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);">
        <div style="text-align: center; margin-bottom: 25px;">
            <h1 style="color: #FFFFFF; font-size: 28px; text-shadow: 0 0 8px #9f91d0;">HomeTown Bank Pa</h1>
            <p style="color: #d0c8e2; font-size: 16px;">Transfer Request Confirmation</p>
        </div>

        <p style="color: #e0dced; margin-bottom: 20px;">Dear <strong style="color: #FFFFFF;">' . htmlspecialchars($user_full_name) . '</strong>,</p>

        <p style="color: #e0dced; margin-bottom: 20px;">Thank you for initiating a transfer. Your request has been successfully submitted and is currently <strong style="color: #c7baf1; text-shadow: 0 0 5px #c7baf1;">awaiting approval</strong>.</p>

        <div style="background-color: #3b3472; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
            <h2 style="color: #FFFFFF; margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid #5a5193; padding-bottom: 10px;">Transfer Details</h2>
            <ul style="list-style: none; padding-left: 0; color: #d0c8e2;">
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Reference Number:</strong> <span style="color: #FFFFFF;">' . htmlspecialchars($transaction_data['reference_number']) . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Amount:</strong> <span style="color: #FFFFFF;">' . get_currency_symbol($sourceAccount['currency']) . ' ' . number_format($amount, 2) . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">From Account:</strong> <span style="color: #FFFFFF;">' . htmlspecialchars($sourceAccount['account_type'] . ' (' . $sourceAccount['account_number'] . ')') . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">To Recipient:</strong> <span style="color: #FFFFFF;">' . htmlspecialchars($transaction_data['recipient_name']) . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Recipient Details:</strong> <span style="color: #FFFFFF;">' . htmlspecialchars($destination_account_display) . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Description:</strong> <span style="color: #FFFFFF;">' . htmlspecialchars($description) . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Initiated On:</strong> <span style="color: #FFFFFF;">' . date('M d, Y H:i:s T') . '</span></li>
                <li style="margin-bottom: 10px;"><strong style="color: #c7baf1;">Current Status:</strong> <span style="color: #c7baf1; text-shadow: 0 0 5px #c7baf1;">Pending Approval</span></li>
            </ul>
        </div>

        <p style="color: #e0dced; margin-bottom: 20px;">We will notify you once your transfer has been reviewed and its status changes. You can also track your transfer status in your dashboard.</p>

        <p style="color: #e0dced; margin-bottom: 15px;">If you have any questions, please do not hesitate to contact our support team.</p>

        <p style="color: #e0dced;">Sincerely,</p>
        <p style="color: #FFFFFF; font-weight: bold;">The HomeTown Bank Pa Team</p>
    </div>
    <div style="text-align: center; margin-top: 25px; color: #8880a1; font-size: 12px;">
        <p>This is an automated confirmation email. Please do not reply.</p>
    </div>
</div>
';

        // Assuming sendEmail function exists in functions.php
        // The sendEmail function should handle proper email headers (e.g., Content-Type for HTML)
        $email_sent = sendEmail($user_email, $email_subject, $email_body);

        if (!$email_sent) {
            error_log("make_transfer.php: Failed to send transfer receipt email to " . $user_email . " for Ref: " . $transaction_data['reference_number']);
        }
    } else {
        error_log("make_transfer.php: User email not found for notification for user_id: " . $user_id);
    }
    // --- End of Email Notification for User ---

    // Clear form data from session after success
    unset($_SESSION['form_data']);

    error_log("make_transfer.php: Transfer successful for user " . $user_id . ", Reference: " . $transaction_data['reference_number']);
    header('Location: ' . BASE_URL . '/frontend/transfer.php');
    exit;

    } catch (MongoDBException $e) {
        if (isset($session) && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("make_transfer.php: MongoDB transaction error: " . $e->getMessage());
        $_SESSION['message'] = "A database error occurred during the transfer process. Please try again.";
        $_SESSION['message_type'] = "error";
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    } catch (Exception $e) {
        if (isset($session) && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("make_transfer.php: General error during transfer: " . $e->getMessage());
        $_SESSION['message'] = "An unexpected error occurred during the transfer. Please try again.";
        $_SESSION['message_type'] = "error";
        header('Location: ' . BASE_URL . '/frontend/transfer.php');
        exit;
    }

} else {
    // If accessed directly without POST data
    $_SESSION['message'] = "Invalid request. Please use the transfer form.";
    $_SESSION['message_type'] = "error";
    error_log("make_transfer.php: Direct access or invalid request method.");
    header('Location: ' . BASE_URL . '/frontend/transfer.php');
    exit;
}

?>