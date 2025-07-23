<?php

// Ensure Composer autoload is at the very top for PHPMailer
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Include the MongoDB configuration file.
// It's crucial that this file is loaded before any functions attempt to connect to MongoDB.
require_once __DIR__ . '/config.php'; // Adjust path if config.php is in a different directory

// Include MongoDB Client and ObjectId for database operations
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // Alias for specific exceptions


/**
 * Global variable to hold the MongoDB database object.
 * This provides a simple way to access the database connection throughout your functions.
 * For larger applications, consider a more robust dependency injection pattern.
 */
global $mongoDb;

// 1. Establish MongoDB Connection (typically once per request)
// This ensures that $mongoDb is available for all functions that need it.
if (!isset($mongoDb)) {
    try {
        $mongoClient = new Client(MONGODB_CONNECTION_URI);
        // Select the database using the name defined in config.php
        $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

        // Optional: Ping to verify connection. Remove in production if not needed.
        // $mongoClient->admin->ping();
        // error_log("MongoDB connected successfully to DB: " . MONGODB_DB_NAME);

    } catch (MongoDBDriverException $e) {
        // Log the error for debugging. These errors are critical.
        error_log("FATAL ERROR in functions.php: Failed to connect to MongoDB. " . $e->getMessage());
        // In a real application, you'd show a user-friendly error page or halt execution.
        die("Database connection error. Please try again later. If the issue persists, contact support.");
    }
}

/**
 * Helper function to get a specific MongoDB collection.
 * @param string $collectionName The name of the MongoDB collection (e.g., 'users', 'transactions').
 * @return MongoDB\Collection
 */
function getCollection(string $collectionName) {
    global $mongoDb;
    if (!isset($mongoDb)) {
        // This indicates a critical error where the global $mongoDb object is not set.
        // The connection logic above should prevent this, but it's a safeguard.
        error_log("MongoDB connection not established when trying to get collection: " . $collectionName);
        throw new Exception("Database connection not initialized.");
    }
    return $mongoDb->$collectionName;
}

// --- Helper Functions for Email (PHPMailer - NO DATABASE DEPENDENCY) ---

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
    } catch (Exception $e) {
        // Log the error for debugging. These errors are critical for email delivery.
        error_log("Email could not be sent to $to. Mailer Error: {$mail->ErrorInfo}. Exception: {$e->getMessage()}");
        return false;
    }
}

// --- Helper Functions for Currency and Financial Math (bcmath - NO DATABASE DEPENDENCY) ---

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

        $user = $usersCollection->findOne(['_id' => $filterId], ['projection' => ['email' => 1, 'full_name' => 1]]);
        return $user ? $user->toArray() : null;
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
    global $mongoClient; // Access the MongoDB Client for transactions
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
    $session = $mongoClient->startSession();
    $session->startTransaction();

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
            $new_recipient_balance_str = bcadd_precision((string)$recipient_account['balance'], $credit_amount_str, 2);

            // Credit recipient's account
            $updateResult = $accountsCollection->updateOne(
                ['_id' => $recipient_account_id],
                ['$set' => ['balance' => $new_recipient_balance_str]],
                ['session' => $session] // Pass session for transactional write
            );

            if ($updateResult->getModifiedCount() === 0) {
                $session->abortTransaction();
                throw new Exception("Failed to credit recipient account for transaction {$transactionObjectId}. Possible concurrency issue or invalid account ID.");
            }

            // Insert recipient's credit transaction record
            $recipient_tx_type = (strpos($transaction_details['transaction_type'], 'SELF') !== false) ? 'INTERNAL_SELF_TRANSFER_IN' : 'INTERNAL_TRANSFER_IN';

            $recipientTransactionData = [
                'user_id'                      => (is_string($transaction_details['recipient_user_id']) && strlen($transaction_details['recipient_user_id']) === 24 && ctype_xdigit($transaction_details['recipient_user_id'])) ? new ObjectId($transaction_details['recipient_user_id']) : $transaction_details['recipient_user_id'],
                'account_id'                   => $recipient_account_id, // This should be ObjectId
                'amount'                       => (float)$credit_amount_str, // Store as float or Decimal128
                'currency'                     => $credit_currency,
                'transaction_type'             => $recipient_tx_type,
                'description'                  => $transaction_details['description'],
                'status'                       => 'COMPLETED',
                'initiated_at'                 => $transaction_details['initiated_at'], // Use original initiated_at
                'transaction_reference'        => $transaction_details['transaction_reference'] . '_IN', // Unique ref for incoming
                'recipient_name'               => $transaction_details['recipient_name'],
                'recipient_account_number'     => $transaction_details['recipient_account_number'],
                'recipient_iban'               => $transaction_details['recipient_iban'] ?? null,
                'recipient_swift_bic'          => $transaction_details['recipient_swift_bic'] ?? null,
                'recipient_sort_code'          => $transaction_details['recipient_sort_code'] ?? null,
                'recipient_external_account_number' => $transaction_details['recipient_external_account_number'] ?? null,
                'recipient_user_id'            => (is_string($transaction_details['recipient_user_id']) && strlen($transaction_details['recipient_user_id']) === 24 && ctype_xdigit($transaction_details['recipient_user_id'])) ? new ObjectId($transaction_details['recipient_user_id']) : $transaction_details['recipient_user_id'],
                'recipient_bank_name'          => $transaction_details['recipient_bank_name'] ?? null,
                'sender_name'                  => $transaction_details['sender_name'],
                'sender_account_number'        => $transaction_details['sender_account_number'],
                'sender_user_id'               => (is_string($transaction_details['sender_user_id']) && strlen($transaction_details['sender_user_id']) === 24 && ctype_xdigit($transaction_details['sender_user_id'])) ? new ObjectId($transaction_details['sender_user_id']) : $transaction_details['sender_user_id'],
                'converted_amount'             => (float)$credit_amount_str,
                'converted_currency'           => $credit_currency,
                'exchange_rate'                => (float)($transaction_details['exchange_rate'] ?? 1.0),
                'external_bank_details'        => $transaction_details['external_bank_details'] ?? null,
                'transaction_date'             => new UTCDateTime(strtotime(date('Y-m-d')) * 1000), // Date without time
                'completed_at'                 => new UTCDateTime(), // Current UTC timestamp
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
        $sender_user = get_user_details($transaction_details['sender_user_id']); // Use the user_id from the fetched transaction

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
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name']) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction has been <strong style="color: #28a745;">successfully completed</strong>.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['transaction_reference']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . get_currency_symbol($transaction_details['currency']) . number_format((float)$transaction_details['amount'], 2) . '</td>
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
                                    <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($recipient_user['full_name']) . ',</p>
                                    <p style="margin-bottom: 15px;">You have received funds!</p>
                                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['transaction_reference'] . '_IN') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount Received:</strong></td>
                                            <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;"><strong style="color: #28a745;">' . get_currency_symbol($credit_currency) . number_format((float)$credit_amount_str, 2) . '</strong></td>
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
    global $mongoClient; // Access the MongoDB Client for transactions
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

    // Start a session for the transaction
    $session = $mongoClient->startSession();
    $session->startTransaction();

    try {
        // 1. Fetch the pending transaction details
        $transaction = $transactionsCollection->findOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['session' => $session] // Pass session for transactional read
        );

        if (!$transaction) {
            $session->abortTransaction();
            return ['success' => false, 'message' => "Pending transaction not found or already processed for rejection.", 'transaction_details' => null];
        }
        $transaction_details = $transaction->toArray(); // Store for return

        // 2. Credit the amount back to the sender's account
        // Assuming sender_user_id and sender_account_id are stored in transaction document
        $sender_account = $accountsCollection->findOne(
            [
                'user_id' => (is_string($transaction_details['sender_user_id']) && strlen($transaction_details['sender_user_id']) === 24 && ctype_xdigit($transaction_details['sender_user_id'])) ? new ObjectId($transaction_details['sender_user_id']) : $transaction_details['sender_user_id'],
                'account_number' => $transaction_details['sender_account_number'],
                'status' => 'active'
            ],
            ['session' => $session] // Pass session for transactional read
        );

        if (!$sender_account) {
            $session->abortTransaction();
            throw new Exception("Sender account not found or is inactive for transaction {$transactionObjectId}. Funds cannot be returned.");
        }

        // CRITICAL CHECK: Ensure sender's account currency matches the original transaction currency for refund
        if (strtoupper($sender_account['currency']) !== strtoupper($transaction_details['currency'])) {
            $session->abortTransaction();
            throw new Exception("Currency mismatch for refund during rejection. Expected " . $transaction_details['currency'] . ", got " . ($sender_account['currency'] ?? 'NULL/EMPTY') . " for transaction {$transactionObjectId}.");
        }

        $new_sender_balance_str = bcadd_precision((string)$sender_account['balance'], (string)$transaction_details['amount'], 2);

        $updateResult = $accountsCollection->updateOne(
            ['_id' => $sender_account['_id']], // Use sender account's _id for update
            ['$set' => ['balance' => $new_sender_balance_str]],
            ['session' => $session] // Pass session for transactional write
        );

        if ($updateResult->getModifiedCount() === 0) {
            $session->abortTransaction();
            throw new Exception("Sender account update failed during rejection for transaction {$transactionObjectId}.");
        }

        // 3. Update the status of the original pending transaction to 'DECLINED'
        $updateOriginalTxResult = $transactionsCollection->updateOne(
            ['_id' => $transactionObjectId, 'status' => 'PENDING'],
            ['$set' => ['status' => 'DECLINED', 'HometownBank_comment' => $reason, 'completed_at' => new UTCDateTime()]],
            ['session' => $session] // Pass session for transactional write
        );

        if ($updateOriginalTxResult->getModifiedCount() === 0) {
            $session->abortTransaction();
            throw new Exception("Transaction status update failed for transaction {$transactionObjectId}. Transaction might no longer be PENDING or ID is incorrect.");
        }

        // If all operations succeed, commit the transaction
        $session->commitTransaction();

        // --- Email Notification for Sender (Transaction Rejected) ---
        // Assuming get_user_details can now handle MongoDB _id for users if updated.
        $sender_user = get_user_details($transaction_details['sender_user_id']);

        if ($sender_user && $sender_user['email']) {
            $subject = "Transaction Rejected - Reference: {$transaction_details['transaction_reference']}";
            $body = '
                <div style="font-family: Arial, sans-serif; font-size: 14px; color: #333; background-color: #f8f8f8; padding: 20px;">
                    <table style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
                        <tr>
                            <td style="padding: 20px; text-align: center; background-color: #dc3545; color: #ffffff;">
                                <h2 style="margin: 0; color: #ffffff; font-weight: normal;">' . htmlspecialchars(SMTP_FROM_NAME) . ' Notification</h2>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 30px;">
                                <p style="margin-bottom: 15px;">Dear ' . htmlspecialchars($sender_user['full_name']) . ',</p>
                                <p style="margin-bottom: 15px;">Your transaction has been <strong style="color: #dc3545;">rejected</strong> and the funds have been returned to your account.</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Transaction Reference:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;">' . htmlspecialchars($transaction_details['transaction_reference']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #555;"><strong>Amount Refunded:</strong></td>
                                        <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right;"><strong style="color: #28a745;">' . get_currency_symbol($transaction_details['currency']) . number_format((float)$transaction_details['amount'], 2) . '</strong></td>
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
                                        <td style="padding: 8px 0; color: #555;"><strong>Date & Time:</strong></td>
                                        <td style="padding: 8px 0; text-align: right;">' . date('Y-m-d H:i:s') . '</td>
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
            error_log("Could not send rejection email for transaction ID {$transactionObjectId}: Sender user details (email) not found.");
        }

        return ['success' => true, 'message' => "Transaction {$transaction_details['transaction_reference']} (ID: {$transactionObjectId}) successfully declined. Funds returned to sender.", 'transaction_details' => $transaction_details];

    } catch (Exception $e) {
        if ($session->isInTransaction()) {
            $session->abortTransaction();
        }
        error_log("Failed to reject transaction ID {$transactionObjectId}: " . $e->getMessage());
        return ['success' => false, 'message' => "Transaction rejection failed: " . $e->getMessage(), 'transaction_details' => null];
    } finally {
        $session->endSession(); // Always end the session
    }
}