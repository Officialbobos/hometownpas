<?php
// admin/transfer_process.php
session_start();
require_once '../Config.php';
require_once '../functions.php'; // Assuming sendEmail and getCollection are here

// Include PHPMailer if not in functions.php - ensure these paths are correct relative to THIS file
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // Alias Exception to avoid conflict with MongoDB Exception

// MongoDB specific includes
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;


// Ensure admin is logged in and has appropriate permissions
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php'); // Redirect to admin login
    exit;
}

$admin_id = $_SESSION['admin_id']; // Assuming admin ID is stored in session (MongoDB ObjectId string)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $client = getMongoDBClient(); // Get the MongoDB client instance

    $transaction_id_str = trim($_POST['transaction_id'] ?? '');
    $action = trim($_POST['action']); // 'complete', 'restrict', 'deliver', 'fail'
    $reason = trim($_POST['reason'] ?? '');

    // Validate transaction_id is a valid ObjectId string
    if (empty($transaction_id_str) || !ObjectId::isValid($transaction_id_str)) {
        $_SESSION['admin_message'] = "Invalid transaction ID format.";
        $_SESSION['admin_message_type'] = "error";
        header('Location: transfer_approvals.php');
        exit;
    }
    $transaction_id = new ObjectId($transaction_id_str);

    $session = null; // Initialize session variable
    try {
        $session = $client->startSession();
        $session->startTransaction();

        $transactionsCollection = getCollection('transactions', $client);
        $usersCollection = getCollection('users', $client);
        $accountsCollection = getCollection('accounts', $client);
        $transferApprovalsCollection = getCollection('transfer_approvals', $client);

        // Fetch transaction details
        $transaction = $transactionsCollection->findOne(['_id' => $transaction_id], ['session' => $session]);

        if (!$transaction) {
            throw new Exception("Transaction not found.");
        }

        $new_status = 'pending'; // Default
        $user_email = '';
        $user_full_name = '';

        // Fetch user's email and name for notification
        // Ensure user_id in transaction is stored as ObjectId if it points to _id in users collection
        $user_id_from_tx = $transaction['user_id'];
        $user = $usersCollection->findOne(['_id' => new ObjectId($user_id_from_tx)], ['session' => $session]);

        if ($user) {
            $user_email = $user['email'];
            $user_full_name = trim($user['first_name'] . ' ' . $user['last_name']);
        } else {
            error_log("User not found for transaction ID: " . $transaction_id_str);
            // Continue processing, but email notification might fail
        }

        switch ($action) {
            case 'complete':
                $new_status = 'completed';
                // For internal transfers, this is where the recipient account is credited
                if ($transaction['transaction_type'] === 'internal_self_transfer' || $transaction['transaction_type'] === 'internal_transfer') {
                    // Check if recipient_user_id and recipient_account_number exist in the transaction
                    if (isset($transaction['recipient_user_id']) && isset($transaction['recipient_account_number'])) {
                        $recipient_user_id = new ObjectId($transaction['recipient_user_id']);
                        $recipient_account_number = $transaction['recipient_account_number'];

                        // Find the recipient account
                        $recipientAccount = $accountsCollection->findOne([
                            'user_id' => $recipient_user_id,
                            'account_number' => $recipient_account_number
                        ], ['session' => $session]);

                        if ($recipientAccount) {
                            // Credit recipient account
                            $updateAccountResult = $accountsCollection->updateOne(
                                ['_id' => $recipientAccount['_id']],
                                ['$inc' => ['balance' => (float)$transaction['amount']]], // Use $inc for atomic increment
                                ['session' => $session]
                            );
                            if ($updateAccountResult->getModifiedCount() === 0) {
                                throw new Exception("Failed to credit recipient account (no document modified).");
                            }

                            // Insert a CREDIT transaction for the recipient
                            $recipient_credit_desc = "Transfer from " . htmlspecialchars($transaction['sender_name']) . " (" . htmlspecialchars($transaction['sender_account_number']) . "): " . htmlspecialchars($transaction['description']);
                            $recipient_tx_ref = 'CR-' . $transaction['transaction_reference']; // Derived from sender's ref

                            $recipientTransactionData = [
                                'user_id' => $recipient_user_id,
                                'account_id' => $recipientAccount['_id'], // Store ObjectId
                                'amount' => (float)$transaction['amount'],
                                'transaction_type' => 'credit',
                                'description' => $recipient_credit_desc,
                                'status' => 'completed',
                                'initiated_at' => new UTCDateTime(),
                                'currency' => $transaction['currency'],
                                'transaction_reference' => $recipient_tx_ref,
                                'sender_name' => $transaction['sender_name'],
                                'sender_account_number' => $transaction['sender_account_number'],
                                'sender_user_id' => new ObjectId($transaction['sender_user_id']), // Assuming sender_user_id is also ObjectId
                                'transaction_date' => new UTCDateTime(strtotime(date('Y-m-d')) * 1000), // Date part only for consistency
                                'recipient_name' => $transaction['recipient_name'],
                                'recipient_account_number' => $recipient_account_number,
                                'admin_approved' => true, // Mark as admin approved
                                'admin_id' => new ObjectId($admin_id) // Store admin ID who approved
                            ];

                            $insertRecipientTxResult = $transactionsCollection->insertOne($recipientTransactionData, ['session' => $session]);
                            if (!$insertRecipientTxResult->getInsertedId()) {
                                throw new Exception("Failed to insert recipient credit transaction.");
                            }
                        } else {
                            throw new Exception("Recipient account not found for internal credit.");
                        }
                    } else {
                        throw new Exception("Recipient user ID or account number missing for internal transfer credit.");
                    }
                }
                // For external transfers, 'completed' means it's sent from our bank. 'delivered' comes later.
                break;

            case 'restricted':
            case 'failed':
                $new_status = $action;
                // If money was already deducted from sender's account,
                // you might need to implement a refund mechanism here depending on your business logic.
                // For this migration, we're assuming the initial deduction happened during the user's transfer initiation
                // and a 'failed' or 'restricted' status implies no immediate refund via this process.
                break;

            case 'delivered':
                if ($transaction['transaction_type'] === 'internal_self_transfer' || $transaction['transaction_type'] === 'internal_transfer') {
                    throw new Exception("Status 'delivered' is only applicable to external transfers. Internal transfers are 'completed'.");
                }
                $new_status = 'delivered';
                break;

            default:
                throw new Exception("Invalid action provided: " . htmlspecialchars($action));
        }

        // Update transaction status
        $updateTxResult = $transactionsCollection->updateOne(
            ['_id' => $transaction_id],
            ['$set' => [
                'status' => $new_status,
                'last_updated' => new UTCDateTime()
            ]],
            ['session' => $session]
        );
        if ($updateTxResult->getModifiedCount() === 0 && $updateTxResult->getMatchedCount() === 0) {
            throw new Exception("Failed to update main transaction status (no document modified or found).");
        }


        // Insert into transfer_approvals (new document)
        $approvalData = [
            'transaction_id' => $transaction_id, // Store ObjectId
            'admin_id' => new ObjectId($admin_id), // Convert admin ID to ObjectId
            'status' => $new_status,
            'reason' => $reason,
            'approved_at' => new UTCDateTime() // Current timestamp
        ];

        $insertApprovalResult = $transferApprovalsCollection->insertOne($approvalData, ['session' => $session]);
        if (!$insertApprovalResult->getInsertedId()) {
            throw new Exception("Failed to insert approval record.");
        }

        // If all operations succeed, commit the transaction
        $session->commitTransaction();

        // --- Start Email Notification Design ---
        $email_subject = "HomeTown Bank: Transfer Status Update - Ref: " . $transaction['transaction_reference'];

        // Prepare variables for email content, ensuring HTML special characters are escaped
        $transaction_ref_display = htmlspecialchars($transaction['transaction_reference']);
        $user_name_display = htmlspecialchars($user_full_name);
        $amount_display = htmlspecialchars($transaction['currency'] ?? 'USD') . " " . number_format($transaction['amount'], 2); // Add default currency if not set
        $recipient_display = htmlspecialchars($transaction['recipient_name'] ?? 'N/A');
        $new_status_display = htmlspecialchars(ucfirst($new_status)); // Capitalize first letter

        $reason_section = '';
        if (!empty($reason)) {
            $reason_section = '<p style="font-size: 14px; color: #555555; line-height: 1.6;"><strong>Reason/Comment:</strong> ' . htmlspecialchars($reason) . '</p>';
        }

        $status_color = '#004494'; // Default Heritage Blue
        if ($new_status == 'completed' || $new_status == 'delivered') {
            $status_color = '#28a745'; // Green for success
        } elseif ($new_status == 'restricted' || $new_status == 'failed') {
            $status_color = '#dc3545'; // Red for failure/restriction
        } elseif ($new_status == 'pending') {
            $status_color = '#ffc107'; // Yellow/Orange for pending (though admin processes shouldn't set to pending)
        }

        $email_html_body = '
        <div style="font-family: \'Roboto\', sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px 0; color: #333333;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center" style="padding: 20px 0;">
                        <table width="600" border="0" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08); overflow: hidden;">
                            <tr>
                                <td align="center" style="background-color:rgb(224, 226, 230); padding: 25px 0 20px 0; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                                    <img src="https://i.imgur.com/YmC3kg3.png" alt="Heritage Bank Logo" style="height: 50px; display: block; margin: 0 auto;">
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
                                            <td style="padding: 8px 0; font-size: 15px; color: #004494; width: 60%; font-weight: bold;">' . $amount_display . '</td>
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
                                    <p style="font-size: 15px; color: #333333; margin-top: 20px;">Thank you for banking with HomeTown Bank.</p>
                                    <p style="font-size: 15px; color: #333333; font-weight: bold; margin-top: 5px;">The HomeTown Bank Team</p>
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
        sendEmail($user_email, $email_subject, $email_html_body);
        // --- End Email Notification Design ---

        $_SESSION['admin_message'] = "Transfer (Ref: " . htmlspecialchars($transaction['transaction_reference']) . ") status updated to " . ucfirst($new_status) . ".";
        $_SESSION['admin_message_type'] = "success";

    } catch (MongoDBDriverException $e) {
        // Handle MongoDB specific errors (e.g., transaction commit failed)
        if ($session && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("MongoDB Transfer processing error: " . $e->getMessage() . " Code: " . $e->getCode());
        $_SESSION['admin_message'] = "Database error processing transfer: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "error";
    } catch (PHPMailerException $e) {
        // Catch PHPMailer exceptions separately if sendEmail throws them directly
        error_log("Email sending failed for transaction ID " . $transaction_id_str . ": " . $e->getMessage());
        $_SESSION['admin_message'] = "Transfer status updated, but failed to send email notification: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "warning"; // Use warning as status update succeeded
    } catch (Exception $e) {
        // Catch other general exceptions (e.g., validation, business logic)
        if ($session && $session->inTransaction()) {
            $session->abortTransaction();
        }
        error_log("General Transfer processing error: " . $e->getMessage());
        $_SESSION['admin_message'] = "Error processing transfer: " . $e->getMessage();
        $_SESSION['admin_message_type'] = "error";
    } finally {
        if ($session) {
            $session->endSession();
        }
    }

    header('Location: transfer_approvals.php'); // Redirect back to admin approval list
    exit;
}

// If accessed directly without POST, redirect
header('Location: transfer_approvals.php');
exit;