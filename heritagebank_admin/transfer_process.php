<?php
// admin/transfer_process.php
session_start();
require_once __DIR__ . '/../Config.php'; // Path from admin/ to Config.php
require_once __DIR__ . '/../vendor/autoload.php'; // For PHPMailer and MongoDB driver
require_once __DIR__ . '/../functions.php'; // Assuming sendEmail, getMongoDBClient, getCollection, get_currency_symbol, and bcmath functions are here

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Ensure admin is logged in and has appropriate permissions
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !isset($_SESSION['admin_id'])) {
    header('Location: index.php'); // Redirect to admin login
    exit;
}

// Convert admin_id from session (string) to MongoDB ObjectId
try {
    $admin_id = new ObjectId($_SESSION['admin_id']);
} catch (Exception $e) {
    error_log("Invalid admin ID in session: " . $_SESSION['admin_id']);
    $_SESSION['admin_message'] = "Invalid admin ID. Please log in again.";
    $_SESSION['admin_message_type'] = "error";
    header('Location: index.php');
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $client = null;
    $session = null; // Initialize session variable
    try {
        $client = getMongoDBClient(); // Get the MongoDB client instance

        $transaction_id_str = trim($_POST['transaction_id'] ?? '');
        $action = trim($_POST['action']); // 'complete', 'restrict', 'deliver', 'fail'
        $reason = trim($_POST['reason'] ?? '');

        // Validate transaction_id is a valid ObjectId string
        if (empty($transaction_id_str) || !ObjectId::isValid($transaction_id_str)) {
            throw new Exception("Invalid transaction ID format.");
        }
        $transaction_id = new ObjectId($transaction_id_str);

        $session = $client->startSession();
        $session->startTransaction();

        $transactionsCollection = getCollection('transactions', $client);
        $usersCollection = getCollection('users', $client);
        $accountsCollection = getCollection('accounts', $client);
        $transferApprovalsCollection = getCollection('transfer_approvals', $client);

        // Fetch transaction details
        $transaction = $transactionsCollection->findOne(['_id' => $transaction_id], ['session' => $session]);

        if (!$transaction) {
            throw new Exception("Transaction not found or already processed.");
        }

        // Prevent processing if status is not PENDING
        if (($transaction['status'] ?? '') !== 'PENDING') {
            throw new Exception("Transaction is not in PENDING status. Current status: " . htmlspecialchars($transaction['status']));
        }

        $new_status = '';
        $user_email = '';
        $user_full_name = '';

        // Fetch user's email and name for notification
        $user_id_from_tx = $transaction['user_id']; // This should be a MongoDB\BSON\ObjectId from process_transfer.php
        if (!($user_id_from_tx instanceof ObjectId)) {
             // If it's stored as a string, convert it. This handles potential inconsistencies.
            $user_id_from_tx = new ObjectId($user_id_from_tx);
        }

        $user = $usersCollection->findOne(['_id' => $user_id_from_tx], ['session' => $session]);

        if ($user) {
            $user_email = $user['email'] ?? '';
            $user_full_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        } else {
            error_log("User not found for transaction ID: " . $transaction_id_str);
            // Decide how critical this is. For now, it's logged, and processing continues.
            // You might want to throw an exception if user not found means something is fundamentally wrong.
        }

        switch ($action) {
            case 'complete':
                $new_status = 'completed';

                // Handle internal transfers: credit recipient account and create a recipient transaction
                if (in_array($transaction['transaction_type'], ['INTERNAL_SELF_TRANSFER_OUT', 'INTERNAL_TRANSFER_OUT'])) {
                    if (isset($transaction['recipient_user_id']) && isset($transaction['recipient_account_number'])) {
                        $recipient_user_id = $transaction['recipient_user_id']; // Should already be ObjectId
                        $recipient_account_number = $transaction['recipient_account_number'];

                        // Ensure recipient_user_id is an ObjectId for the query
                        if (!($recipient_user_id instanceof ObjectId)) {
                            $recipient_user_id = new ObjectId($recipient_user_id);
                        }

                        // Find the recipient account
                        $recipientAccount = $accountsCollection->findOne([
                            'user_id' => $recipient_user_id,
                            'account_number' => $recipient_account_number,
                            'status' => 'active' // Ensure recipient account is active
                        ], ['session' => $session]);

                        if ($recipientAccount) {
                            $current_recipient_balance = $recipientAccount['balance'];
                            $transfer_amount = $transaction['amount']; // Amount is stored as float, use it directly or bcmul for safety

                            // Credit recipient account using bcmath for precision, then cast to float for MongoDB storage
                            $new_recipient_balance = bcadd_precision($current_recipient_balance, $transfer_amount, 2);

                            $updateAccountResult = $accountsCollection->updateOne(
                                ['_id' => $recipientAccount['_id']],
                                ['$set' => ['balance' => (float)$new_recipient_balance]],
                                ['session' => $session]
                            );

                            if ($updateAccountResult->getModifiedCount() === 0) {
                                throw new Exception("Failed to credit recipient account (no document modified or found during update).");
                            }

                            // Insert a CREDIT transaction for the recipient
                            $recipient_credit_desc = "Transfer from " . htmlspecialchars($transaction['sender_name']) . " (Acc: " . htmlspecialchars($transaction['sender_account_number']) . ")";
                            if (!empty($transaction['description'])) {
                                $recipient_credit_desc .= ": " . htmlspecialchars($transaction['description']);
                            }
                            $recipient_tx_ref = 'CR-' . $transaction['transaction_reference']; // Derived from sender's ref

                            $recipientTransactionData = [
                                'user_id' => $recipient_user_id,
                                'account_id' => $recipientAccount['_id'],
                                'amount' => (float)$transfer_amount,
                                'currency' => $transaction['currency'],
                                'transaction_type' => 'CREDIT',
                                'description' => $recipient_credit_desc,
                                'status' => 'completed',
                                'initiated_at' => new UTCDateTime(),
                                'completed_at' => new UTCDateTime(), // Mark completion time
                                'transaction_reference' => $recipient_tx_ref,
                                'sender_name' => $transaction['sender_name'],
                                'sender_account_number' => $transaction['sender_account_number'],
                                'sender_user_id' => $user_id_from_tx, // This is the original sender's user_id
                                'recipient_name' => $transaction['recipient_name'],
                                'recipient_account_number' => $recipient_account_number,
                                'transaction_date' => new UTCDateTime(strtotime(date('Y-m-d')) * 1000),
                                'admin_approved' => true,
                                'admin_id' => $admin_id // Store admin ID who approved
                            ];

                            $insertRecipientTxResult = $transactionsCollection->insertOne($recipientTransactionData, ['session' => $session]);
                            if (!$insertRecipientTxResult->getInsertedId()) {
                                throw new Exception("Failed to insert recipient credit transaction.");
                            }
                        } else {
                            throw new Exception("Recipient account not found or inactive for internal credit.");
                        }
                    } else {
                        throw new Exception("Recipient user ID or account number missing for internal transfer credit.");
                    }
                }
                break;

            case 'restrict':
                $new_status = 'restricted';
                // Refund the sender's account if the transfer is restricted.
                // This is crucial because the amount was debited during initiation.
                $sender_account = $accountsCollection->findOne(
                    ['_id' => $transaction['account_id'], 'user_id' => $user_id_from_tx],
                    ['session' => $session]
                );

                if ($sender_account) {
                    $current_sender_balance = $sender_account['balance'];
                    $amount_to_refund = $transaction['amount'];
                    $new_sender_balance = bcadd_precision($current_sender_balance, $amount_to_refund, 2);

                    $refundUpdateResult = $accountsCollection->updateOne(
                        ['_id' => $sender_account['_id']],
                        ['$set' => ['balance' => (float)$new_sender_balance]],
                        ['session' => $session]
                    );

                    if ($refundUpdateResult->getModifiedCount() === 0) {
                        error_log("Failed to refund sender account balance for restricted transfer: " . $transaction_id_str);
                        // Don't throw a critical error here, as the status update is more important, but log it.
                    }

                    // Create a refund transaction for the sender
                    $refund_description = "Refund for restricted transfer (Ref: " . htmlspecialchars($transaction['transaction_reference']) . ")";
                    $refund_tx_ref = 'RF-' . $transaction['transaction_reference'];

                    $refundTransactionData = [
                        'user_id' => $user_id_from_tx,
                        'account_id' => $transaction['account_id'],
                        'amount' => (float)$amount_to_refund,
                        'currency' => $transaction['currency'],
                        'transaction_type' => 'CREDIT_REFUND',
                        'description' => $refund_description,
                        'status' => 'completed',
                        'initiated_at' => new UTCDateTime(),
                        'completed_at' => new UTCDateTime(),
                        'transaction_reference' => $refund_tx_ref,
                        'sender_name' => 'HomeTown Bank Pa', // The bank is refunding
                        'recipient_name' => $transaction['sender_name'], // The original sender is recipient of refund
                        'recipient_account_number' => $transaction['sender_account_number'],
                        'transaction_date' => new UTCDateTime(strtotime(date('Y-m-d')) * 1000),
                        'admin_approved' => true,
                        'admin_id' => $admin_id
                    ];

                    $transactionsCollection->insertOne($refundTransactionData, ['session' => $session]);

                } else {
                    error_log("Sender account not found for refund during restriction: " . $transaction_id_str);
                }
                break;

            case 'fail':
                $new_status = 'failed';
                // Refund the sender's account if the transfer failed.
                // This is crucial because the amount was debited during initiation.
                $sender_account = $accountsCollection->findOne(
                    ['_id' => $transaction['account_id'], 'user_id' => $user_id_from_tx],
                    ['session' => $session]
                );

                if ($sender_account) {
                    $current_sender_balance = $sender_account['balance'];
                    $amount_to_refund = $transaction['amount'];
                    $new_sender_balance = bcadd_precision($current_sender_balance, $amount_to_refund, 2);

                    $refundUpdateResult = $accountsCollection->updateOne(
                        ['_id' => $sender_account['_id']],
                        ['$set' => ['balance' => (float)$new_sender_balance]],
                        ['session' => $session]
                    );

                    if ($refundUpdateResult->getModifiedCount() === 0) {
                        error_log("Failed to refund sender account balance for failed transfer: " . $transaction_id_str);
                    }

                    // Create a refund transaction for the sender
                    $refund_description = "Refund for failed transfer (Ref: " . htmlspecialchars($transaction['transaction_reference']) . ")";
                    $refund_tx_ref = 'RF-' . $transaction['transaction_reference'];

                    $refundTransactionData = [
                        'user_id' => $user_id_from_tx,
                        'account_id' => $transaction['account_id'],
                        'amount' => (float)$amount_to_refund,
                        'currency' => $transaction['currency'],
                        'transaction_type' => 'CREDIT_REFUND',
                        'description' => $refund_description,
                        'status' => 'completed',
                        'initiated_at' => new UTCDateTime(),
                        'completed_at' => new UTCDateTime(),
                        'transaction_reference' => $refund_tx_ref,
                        'sender_name' => 'HomeTown Bank Pa',
                        'recipient_name' => $transaction['sender_name'],
                        'recipient_account_number' => $transaction['sender_account_number'],
                        'transaction_date' => new UTCDateTime(strtotime(date('Y-m-d')) * 1000),
                        'admin_approved' => true,
                        'admin_id' => $admin_id
                    ];

                    $transactionsCollection->insertOne($refundTransactionData, ['session' => $session]);

                } else {
                    error_log("Sender account not found for refund during failure: " . $transaction_id_str);
                }
                break;

            case 'delivered':
                // 'delivered' status is typically only for external transfers after 'completed'
                if (!in_array($transaction['transaction_type'], ['EXTERNAL_IBAN_TRANSFER_OUT', 'EXTERNAL_SORT_CODE_TRANSFER_OUT', 'EXTERNAL_USA_TRANSFER_OUT'])) {
                    throw new Exception("Status 'delivered' is only applicable to external transfers. Internal transfers are 'completed'.");
                }
                $new_status = 'delivered';
                break;

            default:
                throw new Exception("Invalid action provided: " . htmlspecialchars($action));
        }

        // Update main transaction status and completion time
        $updateFields = [
            'status' => $new_status,
            'last_updated' => new UTCDateTime(),
        ];
        if ($new_status === 'completed' || $new_status === 'restricted' || $new_status === 'failed' || $new_status === 'delivered') {
            $updateFields['completed_at'] = new UTCDateTime();
        }

        $updateTxResult = $transactionsCollection->updateOne(
            ['_id' => $transaction_id],
            ['$set' => $updateFields],
            ['session' => $session]
        );

        if ($updateTxResult->getModifiedCount() === 0 && $updateTxResult->getMatchedCount() === 0) {
            throw new Exception("Failed to update main transaction status (no document modified or found).");
        }

        // Insert into transfer_approvals (new document)
        $approvalData = [
            'transaction_id' => $transaction_id,
            'admin_id' => $admin_id,
            'status' => $new_status,
            'reason' => !empty($reason) ? htmlspecialchars($reason) : 'N/A',
            'approved_at' => new UTCDateTime()
        ];

        $insertApprovalResult = $transferApprovalsCollection->insertOne($approvalData, ['session' => $session]);
        if (!$insertApprovalResult->getInsertedId()) {
            throw new Exception("Failed to insert approval record.");
        }

        // Commit the transaction if all operations were successful
        $session->commitTransaction();

        // --- Start Email Notification ---
        $email_subject = "HomeTown Bank Pa: Transfer Status Update - Ref: " . htmlspecialchars($transaction['transaction_reference']);

        $user_name_display = htmlspecialchars($user_full_name);
        $amount_display = get_currency_symbol($transaction['currency'] ?? 'USD') . number_format((float)$transaction['amount'], 2);
        $recipient_display = htmlspecialchars($transaction['recipient_name'] ?? 'N/A');
        $new_status_display = htmlspecialchars(ucfirst($new_status));

        $reason_section = '';
        if (!empty($reason)) {
            $reason_section = '<p style="font-size: 14px; color: #555555; line-height: 1.6;"><strong>Reason/Comment:</strong> ' . htmlspecialchars($reason) . '</p>';
        }

        $status_color = '#004A7F'; // Default Heritage Blue
        if ($new_status == 'completed' || $new_status == 'delivered') {
            $status_color = '#28a745'; // Green for success
        } elseif ($new_status == 'restricted' || $new_status == 'failed') {
            $status_color = '#dc3545'; // Red for failure/restriction
        }

        $email_html_body = '
        <div style="font-family: \'Arial\', sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px 0; color: #333333;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" style="padding: 20px 0;">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); overflow: hidden;">
                            <tr>
                                <td align="center" style="background-color: #004A7F; padding: 25px 0 20px 0; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                                    <img src="https://i.imgur.com/YmC3kg3.png" alt="HomeTown Bank Pa Logo" style="height: 50px; display: block; margin: 0 auto;">
                                    <h1 style="color: #ffffff; font-size: 28px; margin: 15px 0 0 0; font-weight: 700;">Transfer Status Update</h1>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 30px 40px; text-align: left;">
                                    <p style="font-size: 16px; color: #333333; margin-bottom: 20px;">Dear ' . $user_name_display . ',</p>
                                    <p style="font-size: 15px; color: #555555; line-height: 1.6; margin-bottom: 20px;">
                                        The status of your transfer request with reference <strong>' . $transaction_ref_display . '</strong> has been updated.
                                    </p>
                                    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-bottom: 25px; border-collapse: collapse;">
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; width: 40%; font-weight: bold;">Amount:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: #004A7F; width: 60%; font-weight: bold;">' . $amount_display . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; font-weight: bold;">Recipient:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: #555555;">' . $recipient_display . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 8px 0; font-size: 15px; color: #333333; font-weight: bold;">New Status:</td>
                                            <td style="padding: 8px 0; font-size: 15px; color: ' . $status_color . '; font-weight: bold;">' . $new_status_display . '</td>
                                        </tr>
                                    </table>'
                                    . $reason_section . '
                                    <p style="font-size: 15px; color: #555555; line-height: 1.6; margin-top: 30px;">
                                        If you have any questions, please do not hesitate to contact our customer support.
                                    </p>
                                    <p style="font-size: 15px; color: #333333; margin-top: 20px;">Thank you for banking with HomeTown Bank Pa.</p>
                                    <p style="font-size: 15px; color: #333333; font-weight: bold; margin-top: 5px;">The HomeTown Bank Pa Team</p>
                                </td>
                            </tr>
                            <tr>
                                <td align="center" style="background-color: #f8f8f8; padding: 20px 40px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; font-size: 12px; color: #777777;">
                                    <p>&copy; ' . date('Y') . ' HomeTown Bank Pa. All rights reserved.</p>
                                    <p>This is an automated email, please do not reply.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
        ';
        // Only send email if a user email was found
        if (!empty($user_email)) {
            sendEmail($user_email, $email_subject, $email_html_body);
        } else {
            error_log("No email address found for user_id: " . $user_id_from_tx . ". Email notification skipped for transaction " . $transaction_id_str);
        }
        // --- End Email Notification ---

        $_SESSION['admin_message'] = "Transfer (Ref: " . htmlspecialchars($transaction['transaction_reference']) . ") status updated to " . ucfirst($new_status) . ".";
        $_SESSION['admin_message_type'] = "success";

    } catch (MongoDBDriverException $e) {
        if ($session && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("MongoDB Transfer processing error for Admin ID " . ($admin_id ?? 'N/A') . ": " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        $_SESSION['admin_message'] = "Database error processing transfer: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "error";
    } catch (PHPMailerException $e) {
        error_log("Email sending failed for transaction ID " . $transaction_id_str . " (User ID: " . ($user_id_from_tx ?? 'N/A') . "): " . $e->getMessage());
        $_SESSION['admin_message'] = "Transfer status updated, but failed to send email notification to user: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "warning";
    } catch (Exception $e) {
        if ($session && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("General Transfer processing error for Admin ID " . ($admin_id ?? 'N/A') . ": " . $e->getMessage());
        $_SESSION['admin_message'] = "Error processing transfer: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "error";
    } finally {
        if ($session) {
            $session->endSession();
        }
    }

    // Redirect back to admin approval list
    header('Location: transfer_approvals.php');
    exit;
}

// If accessed directly without POST, redirect
header('Location: transfer_approvals.php');
exit;