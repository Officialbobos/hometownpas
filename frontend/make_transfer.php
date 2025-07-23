<?php
session_start(); // Only one session_start() needed
require_once '../Config.php'; // Contains DB constants (now MongoDB URI), SMTP settings etc.
require_once '../vendor/autoload.php'; // For PHPMailer and MongoDB driver
require_once '../functions.php'; // Contains sendEmail, get_currency_symbol, bcmul_precision, bcsub_precision, bcadd_precision, complete_pending_transfer, reject_pending_transfer

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Convert string user_id from session to MongoDB ObjectId
try {
    $user_id = new ObjectId($_SESSION['user_id']);
} catch (Exception $e) {
    // Handle invalid ObjectId, redirect or show error
    error_log("Invalid user ID in session: " . $_SESSION['user_id']);
    header('Location: login.php');
    exit;
}


// Initialize variables for redirection with messages
$message = '';
$message_type = '';
$post_data_for_redirect = []; // To preserve form data in case of error
$_SESSION['show_modal_on_load'] = false; // Flag to show the confirmation modal

// Only process if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initiate_transfer'])) {

    // Store POST data to pass back if there's an error
    $post_data_for_redirect = $_POST;

    // Establish MongoDB connection
    $mongoClient = null;
    $session = null; // For MongoDB transactions
    try {
        $mongoClient = new Client(MONGO_URI);
        $database = $mongoClient->selectDatabase(MONGO_DB_NAME);

        // Start a MongoDB session for transactions
        $session = $mongoClient->startSession();
        $session->startTransaction();

        $usersCollection = $database->users;
        $accountsCollection = $database->accounts;
        $transactionsCollection = $database->transactions;

        // Fetch user's email and full name
        $user_email = '';
        $full_name = 'User';
        $user_preferred_currency = ''; // New: To store user's preferred currency from DB

        $userDetails = $usersCollection->findOne(['_id' => $user_id], ['session' => $session]);
        if ($userDetails) {
            $full_name = trim(($userDetails['first_name'] ?? '') . ' ' . ($userDetails['last_name'] ?? ''));
            $user_email = $userDetails['email'] ?? '';
            $user_preferred_currency = strtoupper($userDetails['preferred_currency'] ?? ''); // Fetch preferred currency
        } else {
            throw new Exception("User details not found.");
        }

        // Fetch all accounts belonging to the logged-in user
        $user_accounts = [];
        $accountsCursor = $accountsCollection->find(
            ['user_id' => $user_id, 'status' => 'active'],
            ['session' => $session]
        );
        foreach ($accountsCursor as $account_row) {
            $account_row['id'] = (string)$account_row['_id']; // Convert ObjectId to string for consistent array key
            $user_accounts[] = $account_row;
        }

        if (empty($user_accounts)) {
            throw new Exception("You don't have any active accounts linked to your profile. Please contact support.");
        }

        // Input Sanitization and Validation
        $source_account_id_str = filter_var($_POST['source_account_id'] ?? '', FILTER_SANITIZE_STRING); // Keep as string for ObjectId conversion
        $transfer_method = trim($_POST['transfer_method'] ?? '');
        $amount_str = trim($_POST['amount'] ?? ''); // Keep as string for bcmath
        $description = trim($_POST['description'] ?? '');
        $recipient_name = trim($_POST['recipient_name'] ?? '');

        // Convert source_account_id string to ObjectId
        try {
            $source_account_id = new ObjectId($source_account_id_str);
        } catch (Exception $e) {
            throw new Exception("Invalid source account ID format.");
        }

        // Get source account details from the fetched list (DO NOT TRUST CLIENT-SIDE DATA)
        $source_account = null;
        foreach ($user_accounts as $acc) {
            if ($acc['_id'] == $source_account_id) { // Compare ObjectIds
                $source_account = $acc;
                break;
            }
        }

        if (!$source_account) {
            throw new Exception("Invalid source account selected or account not found/active for your profile.");
        }

        $sender_currency = strtoupper($source_account['currency']); // Get sender's currency from the fetched account details

        // --- NEW GLOBAL CURRENCY RESTRICTION CHECK ---
        $allowed_currencies_config = defined('ALLOWED_TRANSFER_CURRENCIES') ? ALLOWED_TRANSFER_CURRENCIES : [];
        $allowed_currencies_upper = array_map('strtoupper', $allowed_currencies_config);

        $is_allowed_by_config = in_array($sender_currency, $allowed_currencies_upper);
        $is_allowed_by_preferred_currency = ($user_preferred_currency !== '' && $sender_currency === $user_preferred_currency);

        if (!$is_allowed_by_config && !$is_allowed_by_preferred_currency) {
            $allowed_list_display = implode(', ', $allowed_currencies_upper);
            if ($user_preferred_currency) {
                $allowed_list_display .= " or your preferred currency (" . $user_preferred_currency . ")";
            }
            throw new Exception("Transfers are currently only allowed in " . $allowed_list_display . ". Your selected account is in " . htmlspecialchars($sender_currency) . ".");
        }
        // --- END NEW GLOBAL CURRENCY RESTRICTION CHECK ---

        // Common validations
        if (!is_numeric($amount_str) || bccomp($amount_str, '0', 2) <= 0) {
            throw new Exception('Please enter a positive amount.');
        }
        // Use bcsub_precision for accurate subtraction before comparison for available balance
        if (bccomp($amount_str, $source_account['balance'], 2) > 0) {
            throw new Exception('Insufficient funds in the selected source account for this transfer.');
        }
        if (empty($transfer_method)) {
            throw new Exception('Please select a transfer method.');
        }

        $sender_current_balance = $source_account['balance'];
        $sender_account_number = $source_account['account_number'];

        // Generate a unique transaction reference early
        $transaction_reference = 'HTB-' . date('YmdHis') . '-' . substr(uniqid(), -6);

        // Initialize transaction data array
        $transaction_data = [
            'user_id' => $user_id, // ObjectId
            'account_id' => $source_account_id, // ObjectId
            'amount' => $amount_str, // Stored as string for precision
            'currency' => $sender_currency,
            'transaction_type' => 'DEBIT', // Default for sender's transaction
            'description' => $description,
            'status' => 'PENDING', // All user-initiated transfers are PENDING for admin approval
            'initiated_at' => new MongoDB\BSON\UTCDateTime(), // Store as BSON Date
            'transaction_reference' => $transaction_reference,
            'recipient_name' => htmlspecialchars($recipient_name), // Sanitize for storage
            'recipient_account_number' => null,
            'recipient_iban' => null,
            'recipient_swift_bic' => null,
            'recipient_sort_code' => null,
            'recipient_external_account_number' => null,
            'recipient_user_id' => null, // For internal transfers (ObjectId)
            'recipient_bank_name' => null,
            'sender_name' => htmlspecialchars($full_name), // Sanitize sender's name
            'sender_account_number' => $sender_account_number,
            'sender_user_id' => $user_id, // ObjectId
            'converted_amount' => null,
            'converted_currency' => null,
            'exchange_rate' => null,
            'external_bank_details' => null, // JSON string for external, or nested document
            'transaction_date' => new MongoDB\BSON\UTCDateTime(strtotime(date('Y-m-d')) * 1000) // Date without time
        ];

        // Deduct amount immediately from source account (optimistic locking with balance check)
        // MongoDB update with comparison for atomic operation
        $new_sender_balance = bcsub_precision($sender_current_balance, $amount_str, 2);

        $updateResult = $accountsCollection->updateOne(
            [
                '_id' => $source_account_id,
                'user_id' => $user_id,
                'balance' => (float)$sender_current_balance // Ensure comparison is correct for float type
            ],
            ['$set' => ['balance' => (float)$new_sender_balance]], // Store as float
            ['session' => $session]
        );

        if ($updateResult->getModifiedCount() === 0) {
            // This means either the document wasn't found or the balance didn't match (concurrency issue)
            throw new Exception("Source account balance update failed or insufficient funds (concurrency issue detected).");
        }

        // Method-specific logic for recipient details and transaction type
        $is_internal_transfer = false;

        switch ($transfer_method) {
            case 'internal_self':
                $is_internal_transfer = true;
                $destination_account_id_self_str = filter_var($_POST['destination_account_id_self'] ?? '', FILTER_SANITIZE_STRING);

                try {
                    $destination_account_id_self = new ObjectId($destination_account_id_self_str);
                } catch (Exception $e) {
                    throw new Exception("Invalid destination account ID for self-transfer.");
                }

                if ($destination_account_id_self == $source_account_id) { // Compare ObjectIds
                    throw new Exception("Please select a valid different account for self-transfer.");
                }

                $destination_account = null;
                foreach ($user_accounts as $acc) {
                    if ($acc['_id'] == $destination_account_id_self) { // Compare ObjectIds
                        $destination_account = $acc;
                        break;
                    }
                }
                if (!$destination_account) {
                    throw new Exception("Invalid destination account for self-transfer.");
                }
                if (strtoupper($destination_account['currency']) !== $sender_currency) {
                    throw new Exception("Currency mismatch for self-transfer. Accounts must be in the same currency.");
                }

                $transaction_data['transaction_type'] = 'INTERNAL_SELF_TRANSFER_OUT';
                $transaction_data['recipient_user_id'] = $user_id; // Recipient is self
                $transaction_data['recipient_account_number'] = $destination_account['account_number'];
                break;

            case 'internal_heritage': // Renamed 'internal_hometown_bank' for consistency
                $is_internal_transfer = true;
                $recipient_account_number_internal = trim($_POST['recipient_account_number_internal'] ?? '');
                if (empty($recipient_account_number_internal) || empty($recipient_name)) {
                    throw new Exception("Recipient account number and name are required for HomeTown Bank Pa transfer.");
                }

                $internalRecipient = $accountsCollection->findOne(
                    ['account_number' => $recipient_account_number_internal, 'status' => 'active'],
                    ['session' => $session]
                );

                if (!$internalRecipient) {
                    throw new Exception("Recipient HomeTown Bank Pa account not found or is inactive.");
                }
                if ($internalRecipient['user_id'] == $user_id) { // Compare ObjectIds
                    throw new Exception("You cannot transfer to your own account using this method. Use 'Between My Accounts'.");
                }
                if (strtoupper($internalRecipient['currency']) !== $sender_currency) {
                    throw new Exception("Currency mismatch for HomeTown Bank Pa transfer. Accounts must be in the same currency.");
                }

                $transaction_data['transaction_type'] = 'INTERNAL_TRANSFER_OUT';
                $transaction_data['recipient_user_id'] = $internalRecipient['user_id']; // Store as ObjectId
                $transaction_data['recipient_account_number'] = $internalRecipient['account_number'];
                break;

            case 'external_iban':
                $recipient_iban = trim($_POST['recipient_iban'] ?? '');
                $recipient_swift_bic = trim($_POST['recipient_swift_bic'] ?? '');
                $recipient_bank_name_iban = trim($_POST['recipient_bank_name_iban'] ?? '');
                $recipient_country = trim($_POST['recipient_country'] ?? '');

                if (empty($recipient_iban) || empty($recipient_swift_bic) || empty($recipient_bank_name_iban) || empty($recipient_name) || empty($recipient_country)) {
                    throw new Exception("All IBAN transfer details (IBAN, SWIFT/BIC, Bank Name, Recipient Name, and Country) are required.");
                }
                if (strlen($recipient_iban) < 15 || strlen($recipient_iban) > 34 || !preg_match('/^[A-Z0-9]+$/', strtoupper($recipient_iban))) {
                    throw new Exception("Invalid IBAN format. Must be alphanumeric, 15-34 characters.");
                }
                if (strlen($recipient_swift_bic) < 8 || strlen($recipient_swift_bic) > 11 || !preg_match('/^[A-Z0-9]+$/', strtoupper($recipient_swift_bic))) {
                    throw new Exception("Invalid SWIFT/BIC format. Must be alphanumeric, 8 or 11 characters.");
                }

                // Store external_bank_details as a nested document instead of JSON string
                $transaction_data['external_bank_details'] = [
                    'iban' => strtoupper($recipient_iban),
                    'swift_bic' => strtoupper($recipient_swift_bic),
                    'bank_name' => $recipient_bank_name_iban,
                    'country' => $recipient_country,
                ];
                $transaction_data['recipient_iban'] = strtoupper($recipient_iban);
                $transaction_data['recipient_swift_bic'] = strtoupper($recipient_swift_bic);
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_iban;
                $transaction_data['transaction_type'] = 'EXTERNAL_IBAN_TRANSFER_OUT';
                break;

            case 'external_sort_code':
                $recipient_sort_code = trim($_POST['recipient_sort_code'] ?? '');
                $recipient_external_account_number = trim($_POST['recipient_external_account_number'] ?? '');
                $recipient_bank_name_sort = trim($_POST['recipient_bank_name_sort'] ?? '');

                if (empty($recipient_sort_code) || empty($recipient_external_account_number) || empty($recipient_bank_name_sort) || empty($recipient_name)) {
                    throw new Exception("All Sort Code transfer details (Sort Code, Account Number, Bank Name, Recipient Name) are required.");
                }
                if (!preg_match('/^\d{6}$/', $recipient_sort_code)) {
                    throw new Exception("Invalid UK Sort Code format (6 digits required).");
                }
                if (!preg_match('/^\d{8}$/', $recipient_external_account_number)) {
                    throw new Exception("Invalid UK Account Number format (8 digits required).");
                }

                // Store external_bank_details as a nested document
                $transaction_data['external_bank_details'] = [
                    'sort_code' => $recipient_sort_code,
                    'account_number' => $recipient_external_account_number,
                    'bank_name' => $recipient_bank_name_sort,
                ];
                $transaction_data['recipient_sort_code'] = $recipient_sort_code;
                $transaction_data['recipient_external_account_number'] = $recipient_external_account_number;
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_sort;
                $transaction_data['transaction_type'] = 'EXTERNAL_SORT_CODE_TRANSFER_OUT';
                break;

            case 'external_usa_account': // New case for USA accounts (ACH/Wire details)
                $recipient_usa_account_number = trim($_POST['recipient_usa_account_number'] ?? '');
                $recipient_usa_routing_number = trim($_POST['recipient_usa_routing_number'] ?? '');
                $recipient_bank_name_usa = trim($_POST['recipient_bank_name_usa'] ?? '');
                $recipient_address_usa = trim($_POST['recipient_address_usa'] ?? '');
                $recipient_city_usa = trim($_POST['recipient_city_usa'] ?? '');
                $recipient_state_usa = trim($_POST['recipient_state_usa'] ?? '');
                $recipient_zip_usa = trim($_POST['recipient_zip_usa'] ?? '');
                $recipient_account_type_usa = trim($_POST['recipient_account_type_usa'] ?? '');

                if (empty($recipient_usa_account_number) || empty($recipient_usa_routing_number) || empty($recipient_bank_name_usa) || empty($recipient_name) || empty($recipient_address_usa) || empty($recipient_city_usa) || empty($recipient_state_usa) || empty($recipient_zip_usa) || empty($recipient_account_type_usa)) {
                    throw new Exception("All USA account transfer details (Account Number, Routing Number, Bank Name, Recipient Name, Address, City, State, Zip, and Account Type) are required.");
                }
                if (!preg_match('/^\d{9}$/', $recipient_usa_routing_number)) {
                    throw new Exception("Invalid USA Routing Number format (9 digits required).");
                }
                if (!in_array($recipient_account_type_usa, ['Checking', 'Savings'])) {
                    throw new Exception("Invalid USA Account Type. Must be 'Checking' or 'Savings'.");
                }

                // Store external_bank_details as a nested document
                $transaction_data['external_bank_details'] = [
                    'account_number' => $recipient_usa_account_number,
                    'routing_number' => $recipient_usa_routing_number,
                    'bank_name' => $recipient_bank_name_usa,
                    'address' => $recipient_address_usa,
                    'city' => $recipient_city_usa,
                    'state' => $recipient_state_usa,
                    'zip' => $recipient_zip_usa,
                    'account_type' => $recipient_account_type_usa,
                ];
                $transaction_data['recipient_external_account_number'] = $recipient_usa_account_number;
                $transaction_data['recipient_bank_name'] = $recipient_bank_name_usa;
                $transaction_data['transaction_type'] = 'EXTERNAL_USA_TRANSFER_OUT';

                if ($sender_currency !== 'USD') {
                    throw new Exception("USA account transfers are only available for USD accounts. Your selected account is in " . htmlspecialchars($sender_currency) . ".");
                }
                break;

            default:
                throw new Exception("Invalid transfer method selected.");
        }

        // Handle currency conversion if needed (currently same currency due to global check)
        $transaction_data['converted_amount'] = (float)$amount_str;
        $transaction_data['converted_currency'] = $sender_currency;
        $transaction_data['exchange_rate'] = 1.0;

        // Insert transaction into the 'transactions' collection
        $insertResult = $transactionsCollection->insertOne(
            $transaction_data,
            ['session' => $session]
        );

        if (!$insertResult->getInsertedId()) {
            throw new Exception("Failed to insert transaction record.");
        }
        $transaction_id = $insertResult->getInsertedId(); // Get the ID of the newly inserted transaction

        // Commit the transaction if all operations were successful
        $session->commitTransaction();

        $message = 'Transfer of ' . htmlspecialchars(get_currency_symbol($sender_currency) . number_format((float)$amount_str, 2)) . ' initiated successfully! It is currently **Pending** admin approval.';
        $message_type = 'success';
        $_SESSION['show_modal_on_load'] = true; // Set flag to display modal

        // Store transfer details for the modal
        $_SESSION['transfer_success_details'] = [
            'amount' => number_format((float)$amount_str, 2),
            'currency' => $sender_currency,
            'recipient_name' => htmlspecialchars($recipient_name),
            'status' => 'Pending',
            'reference' => $transaction_reference,
            'method' => str_replace('_', ' ', $transfer_method)
        ];

        // Send Email Confirmation
        $email_subject = "HomeTown Bank Pa: Transfer Initiated - Ref: " . htmlspecialchars($transaction_reference); // Updated Bank Name

        $email_body = '
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333; background-color: #f4f4f4;">
            <tr>
                <td align="center" style="padding: 20px 0;">
                    <table border="0" cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                        <tr>
                            <td align="center" style="padding: 20px 0; background-color: #004A7F;">
                                <img src="https://i.imgur.com/YmC3kg3.png" alt="HomeTown Bank Pa Logo" style="display: block; max-width: 100px; height: auto; margin: 0 auto; padding: 10px;"> </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px 40px;">
                                <p style="font-size: 16px; font-weight: bold; color: #004A7F; margin-bottom: 20px;">Dear ' . htmlspecialchars($full_name) . ',</p>
                                <p style="margin-bottom: 20px;">Your transfer request has been successfully initiated.</p>

                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 25px; border: 1px solid #dddddd; border-collapse: collapse;">
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Amount:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars(get_currency_symbol($sender_currency) . number_format((float)$amount_str, 2)) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Recipient:</td>
                                        <td style="padding: 12px 15px; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars($recipient_name) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Transfer Method:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; border-bottom: 1px solid #eeeeee;">' . htmlspecialchars(str_replace('_', ' ', $transfer_method)) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; width: 40%; font-weight: bold; border-bottom: 1px solid #eeeeee;">Status:</td>
                                        <td style="padding: 12px 15px; border-bottom: 1px solid #eeeeee;"><strong style="color: #FFA500;">Pending</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9; width: 40%; font-weight: bold;">Reference:</td>
                                        <td style="padding: 12px 15px; background-color: #f9f9f9;">' . htmlspecialchars($transaction_reference) . '</td>
                                    </tr>
                                </table>

                                <p style="margin-top: 20px;">We will notify you once your transfer has been processed by our team. You can monitor its status in your transaction history.</p>
                                <p style="margin-top: 20px; font-weight: bold; color: #004A7F;">Thank you for banking with HomeTown Bank Pa.</p>
                                <p style="font-size: 15px; color: #555555; margin-top: 5px;">The HomeTown Bank Pa Team</p>

                                <p style="font-size: 11px; color: #888888; text-align: center; margin-top: 40px; border-top: 1px solid #eeeeee; padding-top: 20px;">
                                    This is an automated email, please do not reply.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding: 20px; background-color: #004A7F; color: #ffffff; font-size: 12px;">
                                &copy; ' . date("Y") . ' HomeTown Bank Pa. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        sendEmail($user_email, $email_subject, $email_body);

    } catch (Exception $e) {
        if ($session && $session->inTransaction()) {
            $session->abortTransaction(); // Abort transaction on error
        }
        $message = 'Transfer failed: ' . $e->getMessage();
        $message_type = 'error';
        error_log("User Transfer Error (process_transfer.php): " . $e->getMessage() . " (User ID: " . $_SESSION['user_id'] . ", Sender Account: " . ($_POST['source_account_id'] ?? 'N/A') . ", Ref: " . ($transaction_reference ?? 'N/A') . ")");

        $_SESSION['form_data'] = $post_data_for_redirect; // Preserve form data for error display
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $message_type;
        $_SESSION['show_modal_on_load'] = false; // Do not show modal on error
    } finally {
        if ($session) {
            $session->endSession(); // End the session whether successful or not
        }
    }

    // Redirect back to transfer.php with messages and optionally, old form data
    header('Location: transfer.php');
    exit;

} else {
    // If not a POST request, just redirect to transfer.php to display the form
    header('Location: transfer.php');
    exit;
}
