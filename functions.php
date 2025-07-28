<?php
// C:\xampp_lite_8_4\www\phpfile-main\functions.php (REVISED AND FIXED)

// IMPORTANT: Do NOT include ini_set, error_reporting, or require statements for autoload.php or config.php here.
// These should be handled in your main entry scripts (e.g., index.php, heritagebank_admin/index.php).

// --- Keep these 'use' statements if they are used within functions ---
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\Decimal128; // Added for financial precision
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // Aliased to prevent conflict with generic PHP Exception
use PHPMailer\PHPMailer\SMTP;
// --- End 'use' statements ---


/**
 * Returns a MongoDB Client instance (singleton pattern).
 * This ensures only one connection is made per request.
 *
 * @return MongoDB\Client
 * @throws Exception If MONGODB_CONNECTION_URI is not defined or connection fails.
 */
function getMongoDBClient(): Client
{
    static $mongoClient = null; // Use a static variable to store the client

    if ($mongoClient === null) {
        // Ensure the MONGODB_CONNECTION_URI constant is defined
        if (!defined('MONGODB_CONNECTION_URI') || !MONGODB_CONNECTION_URI) {
            // Log this as a critical configuration error
            error_log("FATAL ERROR: MONGODB_CONNECTION_URI not found. Ensure it's defined in Config.php and .env is loaded correctly.");
            throw new Exception("Database configuration error. Please contact support.");
        }

        try {
            $mongoClient = new Client(MONGODB_CONNECTION_URI);
            // Optional: Ping to verify connection. Remove in production if not needed for performance.
            // Note: ping requires replica set or sharded cluster, may not work on standalone default XAMPP setup.
            // $mongoClient->admin->command(['ping' => 1]);
            // error_log("MongoDB connected successfully via getMongoDBClient().");
        } catch (MongoDBDriverException $e) {
            error_log("FATAL ERROR in getMongoDBClient(): Failed to connect to MongoDB. " . $e->getMessage());
            throw new Exception("Database connection error. Please try again later. If the issue persists, contact support.");
        }
    }
    return $mongoClient;
}

/**
 * Helper function to get a specific MongoDB collection.
 * @param string $collectionName The name of the MongoDB collection (e.g., 'users', 'transactions').
 * @return MongoDB\Collection
 */
function getCollection(string $collectionName): MongoDB\Collection
{
    $client = getMongoDBClient(); // Get the client from the shared instance

    // Ensure the MONGODB_DB_NAME constant is defined
    if (!defined('MONGODB_DB_NAME') || !MONGODB_DB_NAME) {
        error_log("FATAL ERROR: MONGODB_DB_NAME not found. Ensure it's defined in Config.php and .env is loaded correctly.");
        throw new Exception("Database configuration error. Please contact support.");
    }

    return $client->selectDatabase(MONGODB_DB_NAME)->selectCollection($collectionName);
}

/**
 * Hides parts of an email address for privacy, showing only the first few characters
 * and the domain.
 * Example: "example@email.com" becomes "ex*****@email.com"
 *
 * @param string $email The email address to partially hide.
 * @return string The partially hidden email address.
 */
function hideEmailPartially(string $email): string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email; // Return as is if not a valid email format
    }

    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];

    $nameLength = strlen($name);
    if ($nameLength <= 3) {
        // If name is 3 characters or less, show first char and mask the rest
        return substr($name, 0, 1) . str_repeat('*', $nameLength - 1) . '@' . $domain;
    } else {
        // Show first 2 characters, mask the middle, show last char of the name part
        return substr($name, 0, 2) . str_repeat('*', $nameLength - 3) . substr($name, -1) . '@' . $domain;
    }
}

// --- Helper Functions for Email (PHPMailer) ---
/**
 * Sends an email using PHPMailer.
 * Requires SMTP constants to be defined in Config.php (SMTP_HOST, SMTP_USERNAME, etc.).
 *
 * @param string $to The recipient's email address.
 * @param string $subject The email subject.
 * @param string $body The email body (HTML allowed).
 * @param string|null $altBody Optional plain text body.
 * @return bool True on success, false on failure.
 */
function sendEmail(string $to, string $subject, string $body, ?string $altBody = null): bool {
    $mail = new PHPMailer(true); // Enable exceptions

    try {
        // Server settings - NOW USING CONSTANTS FROM Config.php
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;           // Use constant from Config.php
        $mail->SMTPAuth   = true;                // Enable SMTP authentication
        $mail->Username   = SMTP_USERNAME;       // Use constant from Config.php
        $mail->Password   = SMTP_PASSWORD;       // Use constant from Config.php

        // Correctly set SMTPSecure based on constant from Config.php
        // PHPMailer::ENCRYPTION_SMTPS for SSL, PHPMailer::ENCRYPTION_STARTTLS for TLS
        if (defined('SMTP_ENCRYPTION') && strtolower(SMTP_ENCRYPTION) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (defined('SMTP_ENCRYPTION') && strtolower(SMTP_ENCRYPTION) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // Default or no encryption if not specified, often not recommended for production
            $mail->SMTPSecure = ''; // No encryption
        }
        $mail->Port       = SMTP_PORT;           // Use constant from Config.php

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME); // Use constants from Config.php
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body); // Fallback to stripped HTML if altBody not provided

        $mail->send();
        error_log("Email sent successfully to $to. Subject: $subject");
        return true;
    } catch (PHPMailerException $e) { // Use the aliased exception here
        // Log the error for debugging. These errors are critical for email delivery.
        error_log("Email could not be sent to $to. Mailer Error: {$mail->ErrorInfo}. Exception: {$e->getMessage()}");
        return false;
    }
}


// --- Helper Functions for Currency and Financial Math (bcmath) ---

/**
 * Returns the currency symbol for a given currency code.
 *
 * @param string $currencyCode The 3-letter currency code (e.g., USD, EUR).
 * @return string The currency symbol or the code itself if not found.
 */
function get_currency_symbol(string $currencyCode): string {
    switch (strtoupper($currencyCode)) {
        case 'USD': return '$';
        case 'EUR': return '€';
        case 'GBP': return '£';
        case 'JPY': return '¥';
        case 'NGN': return '₦';
        case 'CAD': return 'C$';
        case 'AUD': return 'A$';
        case 'CHF': return 'CHF'; // Swiss Franc
        case 'CNY': return '¥';   // Chinese Yuan (same as JPY, context matters)
        case 'INR': return '₹';   // Indian Rupee
        case 'ZAR': return 'R';   // South African Rand
        // Add more as needed
        default: return $currencyCode; // Fallback to code if symbol not known
    }
}

/**
 * Multiplies two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The first number as a string.
 * @param string $num2 The second number as a string.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the multiplication as a string.
 */
function bcmul_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcmul($num1, $num2, $precision + 4), $precision);
}

/**
 * Subtracts two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The number to subtract from.
 * @param string $num2 The number to subtract.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the subtraction as a string.
 */
function bcsub_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcsub($num1, $num2, $precision + 4), $precision);
}

/**
 * Adds two numbers with a specified precision using bcmath.
 *
 * @param string $num1 The first number as a string.
 * @param string $num2 The second number as a string.
 * @param int $precision The number of decimal places to round the result to.
 * @return string The result of the addition as a string.
 */
function bcadd_precision(string $num1, string $num2, int $precision = 2): string {
    // Add a buffer for internal calculations, then round to desired precision
    return bcadd('0', bcadd($num1, $num2, $precision + 4), $precision);
}

/**
 * Sanitizes input string to prevent XSS. MongoDB handles injection via BSON conversion.
 *
 * @param string|null $data The input string to sanitize.
 * @return string The sanitized string.
*/
function sanitize_input(?string $data): string {
    if ($data === null) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Generates a unique reference number.
 *
 * @param int $length The length of the random part of the string.
 * @return string The generated unique reference number prefixed with 'TRF-'.
 */
if (!function_exists('generateUniqueReferenceNumber')) {
    function generateUniqueReferenceNumber($length = 12) {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return 'TRF-' . $randomString; // Prefix for clarity
    }
}


// --- Database-dependent Helper Functions ---

/**
 * Fetches user details (specifically email and full_name) from the database.
 *
 * @param string|ObjectId $user_id The ID of the user. Can be string or ObjectId.
 * @return array|null An associative array with user details, or null if not found.
 */
function get_user_details($user_id): ?array {
    $usersCollection = getCollection('users'); // Assuming 'users' collection
    try {
        // If the user_id is a string, convert it to ObjectId for lookup
        $filterId = (is_string($user_id) && strlen($user_id) === 24 && ctype_xdigit($user_id)) ? new ObjectId($user_id) : $user_id;

        $user = $usersCollection->findOne(['_id' => $filterId], ['projection' => ['email' => 1, 'full_name' => 1, 'last_name' => 1, 'first_name' => 1]]); // Added first_name and last_name
        
        if ($user) {
            $userArray = $user->toArray();
            // Ensure 'full_name' exists, create if not
            if (!isset($userArray['full_name'])) {
                $userArray['full_name'] = trim(($userArray['first_name'] ?? '') . ' ' . ($userArray['last_name'] ?? ''));
            }
            return $userArray;
        }
        return null;
    } catch (MongoDBDriverException $e) {
        error_log("Error fetching user details: " . $e->getMessage());
        return null;
    } catch (MongoDB\BSON\Exception\InvalidTypeException $e) {
        error_log("Invalid user ID format for get_user_details: " . $e->getMessage());
        return null;
    }
}

/**
 * Processes the completion of a pending transfer, crediting the recipient and updating transaction status.
 * This function is intended for admin use after a user initiates a PENDING transfer.
 *
 * @param string|ObjectId $transaction_id The ID of the pending transaction to complete.
 * @return array An associative array with 'success' (bool) and 'message' (string), and 'transaction_details' (array|null).
 */
function complete_pending_transfer($transaction_id): array {
    $client = getMongoDBClient(); // Get the client via the revised function
    $transactionsCollection = getCollection('transactions');
    $accountsCollection = getCollection('accounts');

    $transaction_details = null; // To store details for email notification

    // Convert transaction_id to ObjectId if it's a string
    try {
        $transactionObjectId = (is_string($transaction_id) && strlen($transaction_id) === 24 && ctype_xdigit($transaction_id)) ? new ObjectId($transaction_id) : $transaction_id;
    } catch (MongoDB\BSON\Exception\InvalidTypeException $e) {
        error_log("Invalid transaction ID format for completion: " . $e->getMessage());
        return ['success' => false, 'message' => "Invalid transaction ID format.", 'transaction_details' => null];
    }

    // Start a session for the transaction (requires a replica set or sharded cluster)
    // For local development on a standalone server, transactions might not be supported.
    // Ensure your MongoDB instance is a replica set for this to work.
    //$session = $client->startSession(); // Use the client from getMongoDBClient()
    //$session->startTransaction();

    try {
        // 1. Fetch the pending transaction details
        $transaction = $transactionsCollection->findOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['session' => $session] // Pass session for transactional read
        );

        if (!$transaction) {
            $session->abortTransaction();
            return ['success' => false, 'message' => "Pending transaction not found or already processed.", 'transaction_details' => null];
        }
        $transaction_details = $transaction->toArray(); // Store for return

        // Determine the actual amount and currency to credit for the recipient (or for external reporting)
        // Convert to string for bcmath operations, but fetch as Decimal128 if stored as such
        $credit_amount_str = (string)($transaction_details['converted_amount'] ?? $transaction_details['amount']);
        $credit_currency = (string)($transaction_details['converted_currency'] ?? $transaction_details['currency']);

        // 2. Process based on transaction type
        if (strpos($transaction_details['transaction_type'], 'INTERNAL') !== false) {
            // This is an Internal Transfer (either self-transfer or to another Heritage Bank user)

            // Fetch recipient account details for crediting
            $recipient_account = $accountsCollection->findOne(
                [
                    'user_id' => (is_string($transaction_details['recipient_user_id']) && strlen($transaction_details['recipient_user_id']) === 24 && ctype_xdigit($transaction_details['recipient_user_id'])) ? new ObjectId($transaction_details['recipient_user_id']) : $transaction_details['recipient_user_id'],
                    'account_number' => $transaction_details['recipient_account_number'],
                    'status' => 'active'
                ],
                ['session' => $session] // Pass session for transactional read
            );

            if (!$recipient_account) {
                $session->abortTransaction();
                throw new Exception("Recipient internal account not found or is inactive for transaction {$transactionObjectId}.");
            }

            // CRITICAL CHECK: Ensure recipient's account currency matches the transaction's credit currency
            if (strtoupper($recipient_account['currency']) !== strtoupper($credit_currency)) {
                $session->abortTransaction();
                throw new Exception("Currency mismatch for internal transfer credit. Expected " . $credit_currency . ", got " . $recipient_account['currency'] . " for transaction {$transactionObjectId}.");
            }

            $recipient_account_id = $recipient_account['_id']; // MongoDB _id
            // Get current balance as string for bcmath, then convert result back to Decimal128
            $current_recipient_balance_str = (string)$recipient_account['balance'];
            $new_recipient_balance_str = bcadd_precision($current_recipient_balance_str, $credit_amount_str, 2);

            // Credit recipient's account
            $updateResult = $accountsCollection->updateOne(
                ['_id' => $recipient_account_id],
                // Store as Decimal128
                ['$set' => ['balance' => new Decimal128($new_recipient_balance_str)]],
                ['session' => $session] // Pass session for transactional write
            );

            if ($updateResult->getModifiedCount() === 0) {
                $session->abortTransaction();
                throw new Exception("Failed to credit recipient account for transaction {$transactionObjectId}. Possible concurrency issue or invalid account ID.");
            }

            // Insert recipient's credit transaction record
            $recipient_tx_type = (strpos($transaction_details['transaction_type'], 'SELF') !== false) ? 'INTERNAL_SELF_TRANSFER_IN' : 'INTERNAL_TRANSFER_IN';

            $recipientTransactionData = [
                'user_id'                         => (is_string($transaction_details['recipient_user_id']) && strlen($transaction_details['recipient_user_id']) === 24 && ctype_xdigit($transaction_details['recipient_user_id'])) ? new ObjectId($transaction_details['recipient_user_id']) : $transaction_details['recipient_user_id'],
                'account_id'                      => $recipient_account_id, // This should be ObjectId
                'amount'                          => new Decimal128($credit_amount_str), // Store as Decimal128
                'currency'                        => $credit_currency,
                'transaction_type'                => $recipient_tx_type,
                'description'                     => $transaction_details['description'],
                'status'                          => 'COMPLETED',
                'initiated_at'                    => $transaction_details['initiated_at'], // Use original initiated_at
                'transaction_reference'           => $transaction_details['transaction_reference'] . '_IN', // Unique ref for incoming
                'recipient_name'                  => $transaction_details['recipient_name'],
                'recipient_account_number'        => $transaction_details['recipient_account_number'],
                'recipient_iban'                  => $transaction_details['recipient_iban'] ?? null,
                'recipient_swift_bic'             => $transaction_details['recipient_swift_bic'] ?? null,
                'recipient_sort_code'             => $transaction_details['recipient_sort_code'] ?? null,
                'recipient_external_account_number' => $transaction_details['recipient_external_account_number'] ?? null,
                'recipient_user_id'               => (is_string($transaction_details['recipient_user_id']) && strlen($transaction_details['recipient_user_id']) === 24 && ctype_xdigit($transaction_details['recipient_user_id'])) ? new ObjectId($transaction_details['recipient_user_id']) : $transaction_details['recipient_user_id'],
                'recipient_bank_name'             => $transaction_details['recipient_bank_name'] ?? null,
                'sender_name'                     => $transaction_details['sender_name'],
                'sender_account_number'           => $transaction_details['sender_account_number'],
                'sender_user_id'                  => (is_string($transaction_details['sender_user_id']) && strlen($transaction_details['sender_user_id']) === 24 && ctype_xdigit($transaction_details['sender_user_id'])) ? new ObjectId($transaction_details['sender_user_id']) : $transaction_details['sender_user_id'],
                'converted_amount'                => new Decimal128($credit_amount_str), // Store as Decimal128
                'converted_currency'              => $credit_currency,
                'exchange_rate'                   => new Decimal128((string)($transaction_details['exchange_rate'] ?? 1.0)), // Store as Decimal128
                'external_bank_details'           => $transaction_details['external_bank_details'] ?? null,
                'transaction_date'                => new UTCDateTime(strtotime('today') * 1000), // Date without time
                'completed_at'                    => new UTCDateTime(), // Current UTC timestamp
            ];

            $insertResult = $transactionsCollection->insertOne($recipientTransactionData, ['session' => $session]);
            if ($insertResult->getInsertedCount() === 0) {
                $session->abortTransaction();
                throw new Exception("Failed to insert recipient transaction record for transaction {$transactionObjectId}.");
            }

        } else {
            // External Transfer (Bank to Bank, ACH, Wire, International Wire)
            // For external transfers, no internal recipient account is credited.
            // The funds are assumed to be remitted to the external bank by the admin.
            // All necessary details are already in the `transactions` table.
            // No further internal financial actions are needed on internal accounts for completion.
        }

        // 3. Update the status of the original pending transaction to 'COMPLETED'
        $updateOriginalTxResult = $transactionsCollection->updateOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['$set' => ['status' => 'COMPLETED', 'completed_at' => new UTCDateTime()]],
            ['session' => $session] // Pass session for transactional write
        );

        if ($updateOriginalTxResult->getModifiedCount() === 0) {
            $session->abortTransaction();
            throw new Exception("Transaction status update failed for transaction {$transactionObjectId}. Transaction might no longer be PENDING or ID is incorrect.");
        }

        // If all operations succeed, commit the transaction
        $session->commitTransaction();

        // --- Email Notification for Sender ---
        // Pass original transaction ID (from MySQL) if needed for email history lookup.
        // Assuming get_user_details can now handle MongoDB _id for users if updated.
        $sender_user = get_user_details($transaction_details['user_id']); // Use the user_id from the fetched transaction

        if ($sender_user && $sender_user['email']) {
            $subject = "Transaction Completed - Reference: {$transaction_details['transaction_reference']}";
            $body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                    <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #004d40; color: #ffffff;">
                                <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px;">
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name'] ?? ($sender_user['first_name'] . ' ' . $sender_user['last_name'])) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction has been <strong style="color: #28a745;">successfully completed</strong>.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['transaction_reference']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . get_currency_symbol((string)$transaction_details['currency']) . number_format((float)(string)$transaction_details['amount'], 2) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Recipient:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['recipient_name']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Date & Time:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; color: #555;"><strong>Status:</strong></td>
                                        <td style="padding: 8px 0; text-align: right;"><strong style="color: #28a745;">COMPLETED</strong></td>
                                    </tr>
                                </table>
                                <p style="margin-bottom: 15px;">If you have any questions, please contact us.</p>
                                <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </div>
            ';
            sendEmail($sender_user['email'], $subject, $body);
        } else {
            error_log("Could not send completion email for transaction ID {$transactionObjectId}: Sender user details (email) not found.");
        }
        // --- Email Notification for Recipient (if internal transfer) ---
        if (strpos($transaction_details['transaction_type'], 'INTERNAL') !== false && isset($transaction_details['recipient_user_id'])) {
            $recipient_user = get_user_details($transaction_details['recipient_user_id']);
            if ($recipient_user && $recipient_user['email']) {
                $subject = "Funds Received - Reference: {$transaction_details['transaction_reference']}";
                $body = '
                    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                        <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                            <tr>
                                <td style="padding: 20px; text-align: center; background-color: #004d40; color: #ffffff;">
                                    <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px;">
                                    <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($recipient_user['full_name'] ?? ($recipient_user['first_name'] . ' ' . $recipient_user['last_name'])) . ',</p>
                                    <p style="margin-bottom: 15px;">You have received funds!</p>
                                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['transaction_reference'] . '_IN') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount Received:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;"><strong style="color: #28a745;">' . get_currency_symbol((string)$credit_currency) . number_format((float)(string)$credit_amount_str, 2) . '</strong></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>From:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['sender_name']) . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Date & Time:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; color: #555;"><strong>Status:</strong></td>
                                            <td style="padding: 8px 0; text-align: right;"><strong style="color: #28a745;">COMPLETED</strong></td>
                                        </tr>
                                    </table>
                                    <p style="margin-bottom: 15px;">Your account balance has been updated accordingly.</p>
                                    <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                    &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                                </td>
                            </tr>
                        </table>
                    </div>
                ';
                sendEmail($recipient_user['email'], $subject, $body);
            } else {
                error_log("Could not send completion email for transaction ID {$transactionObjectId}: Recipient user details (email) not found.");
            }
        }

        return ['success' => true, 'message' => "Transaction {$transaction_details['transaction_reference']} (ID: {$transactionObjectId}) completed successfully.", 'transaction_details' => $transaction_details];

    } catch (Exception $e) {
        // Rollback the transaction on any error
        if ($session->isInTransaction()) {
            $session->abortTransaction();
        }
        // Log the error for debugging
        error_log("Failed to complete transaction ID {$transactionObjectId}: " . $e->getMessage());
        return ['success' => false, 'message' => "Transaction completion failed: " . $e->getMessage(), 'transaction_details' => null];
    } finally {
        $session->endSession(); // Always end the session
    }
}

/**
 * Rejects a pending transfer, crediting the original amount back to the sender's account.
 * This function is intended for admin use.
 *
 * @param string|ObjectId $transaction_id The ID of the pending transaction to reject.
 * @param string $reason Optional reason for rejection.
 * @return array An associative array with 'success' (bool) and 'message' (string), and 'transaction_details' (array|null).
 */
function reject_pending_transfer($transaction_id, string $reason = 'Rejected by Admin'): array {
    $client = getMongoDBClient(); // Use the client from the revised function
    $transactionsCollection = getCollection('transactions');
    $accountsCollection = getCollection('accounts');

    $transaction_details = null; // To store details for email notification

    // Convert transaction_id to ObjectId if it's a string
    try {
        $transactionObjectId = (is_string($transaction_id) && strlen($transaction_id) === 24 && ctype_xdigit($transaction_id)) ? new ObjectId($transaction_id) : $transaction_id;
    } catch (MongoDB\BSON\Exception\InvalidTypeException $e) {
        error_log("Invalid transaction ID format for rejection: " . $e->getMessage());
        return ['success' => false, 'message' => "Invalid transaction ID format.", 'transaction_details' => null];
    }

    //$session = $client->startSession();
    //$session->startTransaction();

    try {
        // 1. Fetch the pending transaction details
        $transaction = $transactionsCollection->findOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['session' => $session]
        );

        if (!$transaction) {
            $session->abortTransaction();
            return ['success' => false, 'message' => "Pending transaction not found or already processed.", 'transaction_details' => null];
        }
        $transaction_details = $transaction->toArray();

        // 2. Credit the original amount back to the sender's account
        // Ensure account_id and sender_user_id are ObjectId types for queries
        $sender_account_id = (is_string($transaction_details['account_id']) && strlen($transaction_details['account_id']) === 24 && ctype_xdigit($transaction_details['account_id'])) ? new ObjectId($transaction_details['account_id']) : $transaction_details['account_id'];
        $sender_user_id_obj = (is_string($transaction_details['sender_user_id']) && strlen($transaction_details['sender_user_id']) === 24 && ctype_xdigit($transaction_details['sender_user_id'])) ? new ObjectId($transaction_details['sender_user_id']) : $transaction_details['sender_user_id'];


        $sender_account = $accountsCollection->findOne(
            ['_id' => $sender_account_id, 'user_id' => $sender_user_id_obj],
            ['session' => $session]
        );

        if (!$sender_account) {
            $session->abortTransaction();
            throw new Exception("Sender account not found or does not belong to the sender for transaction {$transactionObjectId}.");
        }

        // Use the original amount and currency from the transaction for the refund
        // Convert to string for bcmath operations, but fetch as Decimal128 if stored as such
        $refund_amount_str = (string)$transaction_details['amount'];
        $refund_currency = (string)$transaction_details['currency'];

        // CRITICAL CHECK: Ensure sender's account currency matches the transaction's original currency for refund
        if (strtoupper($sender_account['currency']) !== strtoupper($refund_currency)) {
            $session->abortTransaction();
            throw new Exception("Currency mismatch for refund. Expected " . $refund_currency . ", got " . $sender_account['currency'] . " for transaction {$transactionObjectId}.");
        }
        
        // Get current balance as string for bcmath, then convert result back to Decimal128
        $current_sender_balance_str = (string)$sender_account['balance'];
        $new_sender_balance_str = bcadd_precision($current_sender_balance_str, $refund_amount_str, 2);

        $updateResult = $accountsCollection->updateOne(
            ['_id' => $sender_account_id],
            // Store as Decimal128
            ['$set' => ['balance' => new Decimal128($new_sender_balance_str)]],
            ['session' => $session]
        );

        if ($updateResult->getModifiedCount() === 0) {
            $session->abortTransaction();
            throw new Exception("Failed to credit sender account during rejection for transaction {$transactionObjectId}. Possible concurrency issue or invalid account ID.");
        }

        // 3. Update the status of the original pending transaction to 'REJECTED'
        $updateOriginalTxResult = $transactionsCollection->updateOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['$set' => ['status' => 'REJECTED', 'rejected_at' => new UTCDateTime(), 'rejection_reason' => $reason]],
            ['session' => $session]
        );

        if ($updateOriginalTxResult->getModifiedCount() === 0) {
            $session->abortTransaction();
            throw new Exception("Transaction status update to REJECTED failed for transaction {$transactionObjectId}. Transaction might no longer be PENDING or ID is incorrect.");
        }

        // If all operations succeed, commit the transaction
        $session->commitTransaction();

        // --- Email Notification for Sender (Rejection) ---
        $sender_user = get_user_details($transaction_details['user_id']);
        if ($sender_user && $sender_user['email']) {
            $subject = "Transaction Rejected - Reference: {$transaction_details['transaction_reference']}";
            $body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                    <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #cc0000; color: #ffffff;">
                                <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px;">
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name'] ?? ($sender_user['first_name'] . ' ' . $sender_user['last_name'])) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction with reference <strong style="color: #cc0000;">' . htmlspecialchars($transaction_details['transaction_reference']) . '</strong> has been <strong style="color: #cc0000;">rejected</strong>.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . get_currency_symbol((string)$transaction_details['currency']) . number_format((float)(string)$transaction_details['amount'], 2) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Recipient:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['recipient_name']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Rejection Reason:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($reason) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; color: #555;"><strong>Status:</strong></td>
                                        <td style="padding: 8px 0; text-align: right;"><strong style="color: #cc0000;">REJECTED</strong></td>
                                    </tr>
                                </table>
                                <p style="margin-bottom: 15px;">The amount of ' . get_currency_symbol((string)$transaction_details['currency']) . number_format((float)(string)$transaction_details['amount'], 2) . ' has been credited back to your account.</p>
                                <p style="margin-top: 20px; font-size: 12px; color: #777;">If you have any questions, please contact us.</p>
                                <p style="margin-top: 20px; font-size: 12px; color: #777;">Thank you for banking with ' . htmlspecialchars(SMTP_FROM_NAME) . '.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 15px; text-align: center; font-size: 12px; color: #999; background-color: #f1f1f1; border-top: 1px solid #eee;">
                                &copy; ' . date("Y") . ' ' . htmlspecialchars(SMTP_FROM_NAME) . '. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </div>
            ';
            sendEmail($sender_user['email'], $subject, $body);
        } else {
            error_log("Could not send rejection email for transaction ID {$transactionObjectId}: Sender user details (email) not found.");
        }

        return ['success' => true, 'message' => "Transaction {$transaction_details['transaction_reference']} (ID: {$transactionObjectId}) rejected successfully. Amount refunded to sender.", 'transaction_details' => $transaction_details];

    } catch (Exception $e) {
        // Rollback the transaction on any error
        if ($session->isInTransaction()) {
            $session->abortTransaction();
        }
        // Log the error for debugging
        error_log("Failed to reject transaction ID {$transactionObjectId}: " . $e->getMessage());
        return ['success' => false, 'message' => "Transaction rejection failed: " . $e->getMessage(), 'transaction_details' => null];
    } finally {
        $session->endSession(); // Always end the session
    }
}