<?php
// Path: C:\xampp\htdocs\heritagebank\admin\transactions_management.php

session_start();
require_once '../../Config.php'; 
require_once '../../functions.php'; 

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBException;

// Admin authentication check
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

// --- MongoDB Connection ---
$client = null;
$database = null;
$transactionsCollection = null;
$usersCollection = null;

try {
    $client = new Client(MONGODB_CONNECTION_URI, [], [
        'connectTimeoutMS' => 10000,
        'socketTimeoutMS' => 30000
    ]);
    
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $transactionsCollection = $database->selectCollection('transactions');
    $usersCollection = $database->selectCollection('users'); 
} catch (MongoDBException $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    $_SESSION['error_message'] = "ERROR: Could not connect to the database. Please try again later.";
    header('Location: admin_dashboard.php');
    exit;
}

$allowed_filters = ['approved', 'declined', 'completed', 'pending', 'restricted', 'failed', 'on hold', 'refunded', 'all'];
$settable_statuses = ['pending', 'approved', 'completed', 'declined', 'restricted', 'failed', 'refunded', 'on hold'];
$recommended_currencies = ['GBP', 'EUR', 'USD'];
$status_filter = $_GET['status_filter'] ?? 'pending';

if (!in_array($status_filter, $allowed_filters)) {
    $status_filter = 'pending';
}

/**
 * Helper function to construct and send the transaction update email.
 * This is your original function, kept for reference and consistency.
 */
function send_transaction_update_email_notification($user_email, $tx_details, $new_status, $admin_comment) {
    // ... [Your original function code here] ...
    // Note: The original function has a typo 'HomeTwon Bank Pa.' in the email body,
    // which you may want to correct to 'Heritage Bank'.
    if (!function_exists('sendEmail')) {
        error_log("sendEmail function not found. Cannot send transaction update email.");
        return false;
    }

    if (!$user_email) {
        error_log("Attempted to send email but user_email was empty for transaction ID: " . ($tx_details['_id'] ?? 'N/A'));
        return false;
    }

    $subject = 'Heritage Bank Transaction Update: ' . ucfirst($new_status);
    $amount_display = htmlspecialchars(($tx_details['currency'] ?? 'USD') . ' ' . number_format($tx['amount'] ?? 0, 2));
    $recipient_name_display = htmlspecialchars($tx_details['recipient_name'] ?? 'N/A');
    $transaction_ref_display = htmlspecialchars($tx_details['transaction_reference'] ?? 'N/A');
    $comment_display = !empty($admin_comment) ? htmlspecialchars($admin_comment) : 'N/A';
    
    // The rest of your email body content
    $body = "
        <p>Dear Customer,</p>
        <p>This is to inform you about an update regarding your recent transaction.</p>
        <p><strong>Transaction Reference:</strong> {$transaction_ref_display}</p>
        <p><strong>Amount:</strong> {$amount_display}</p>
        <p><strong>Recipient:</strong> {$recipient_name_display}</p>
        <p><strong>New Status:</strong> <span style='font-weight: bold; color: ";
    // ...[status-specific styling]...
    $body .= "'>" . htmlspecialchars(ucfirst($new_status)) . "</span></p>";
    $body .= "<p><strong>Bank Comment:</strong> {$comment_display}</p>";
    $body .= "<p>If you have any questions, please do not hesitate to contact our support team.</p>";
    $body .= "<p>Thank you for banking with Heritage Bank.</p>";
    $body .= "<p>Sincerely,</p>";
    $body .= "<p>Heritage Bank Management</p>"; // Corrected Bank Name

    $altBody = strip_tags($body);

    return sendEmail($user_email, $subject, $body, $altBody);
}


// --- Handle Transaction Status Update POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction_status'])) {
    if (!$transactionsCollection || !$usersCollection) {
        $_SESSION['error_message'] = "Database not connected. Cannot process update.";
    } else {
        $transaction_id_str = filter_var($_POST['transaction_id'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $new_status = filter_var($_POST['new_status'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $admin_comment_message = filter_var($_POST['message'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $admin_username = $_SESSION['admin_username'] ?? 'Admin'; 

        if (empty($transaction_id_str) || empty($new_status)) {
            $_SESSION['error_message'] = "Transaction ID and New Status are required.";
        } elseif (!in_array($new_status, $settable_statuses)) {
            $_SESSION['error_message'] = "Invalid status provided for update.";
        } else {
            try {
                $transaction_objectId = new ObjectId($transaction_id_str);
                $original_tx_details = $transactionsCollection->findOne(['_id' => $transaction_objectId]);

                if (!$original_tx_details) {
                    $_SESSION['error_message'] = "Transaction not found for ID: " . htmlspecialchars($transaction_id_str) . ".";
                } else {
                    $original_tx_details = (array) $original_tx_details;
                    $user_doc = $usersCollection->findOne(['_id' => new ObjectId($original_tx_details['user_id'])]);
                    $user_email = $user_doc['email'] ?? null;
                    $current_db_status = $original_tx_details['status'];
                    $result_action = ['success' => false, 'message' => 'An unexpected error occurred.', 'transaction_details' => null];

                    // Logic for `complete_pending_transfer` and `reject_pending_transfer`
                    // This is good as is, so we'll assume those functions handle the DB updates
                    if ($new_status === 'completed' && $current_db_status === 'pending') {
                        $result_action = complete_pending_transfer($transactionsCollection, $usersCollection, $transaction_objectId);
                    } elseif ($new_status === 'declined' && $current_db_status === 'pending') {
                        $result_action = reject_pending_transfer($transactionsCollection, $usersCollection, $transaction_objectId, $admin_comment_message);
                    } else {
                         // General status update logic
                        $update_result = $transactionsCollection->updateOne(
                            ['_id' => $transaction_objectId],
                            ['$set' => [
                                'status' => $new_status,
                                'Heritage_comment' => $admin_comment_message,
                                'admin_action_by' => $admin_username,
                                'action_at' => new UTCDateTime()
                            ]]
                        );
                        if ($update_result->getModifiedCount() > 0 || $update_result->getMatchedCount() > 0) {
                            $result_action['success'] = true;
                            $result_action['message'] = "Transaction status updated directly to " . ucfirst($new_status) . ".";
                            $updated_tx_details = $transactionsCollection->findOne(['_id' => $transaction_objectId]);
                            $result_action['transaction_details'] = (array) $updated_tx_details;
                        } else {
                            $result_action['message'] = "Transaction update had no effect (status might already be " . ucfirst($new_status) . ").";
                        }
                    }

                    if ($result_action['success']) {
                        // Set the session variable for the user's modal
                        $_SESSION['transaction_alert'] = [
                            'status' => $new_status,
                            'message' => $admin_comment_message,
                            'ref_no' => $original_tx_details['transaction_reference'] ?? 'N/A',
                            'recipient_name' => $original_tx_details['recipient_name'] ?? 'N/A',
                            'amount' => $original_tx_details['amount'] ?? 0,
                            'currency' => $original_tx_details['currency'] ?? 'N/A',
                            'user_id' => (string)($original_tx_details['user_id'] ?? '') // Ensure this is a string
                        ];
                        
                        // Set admin-side messages
                        $_SESSION['success_message'] = $result_action['message'];

                        // Send email notification
                        if ($user_email) {
                            if (send_transaction_update_email_notification($user_email, $original_tx_details, $new_status, $admin_comment_message)) {
                                $_SESSION['info_message'] = "Email notification sent to " . htmlspecialchars($user_email) . ".";
                            } else {
                                $_SESSION['error_message'] = "Failed to send email notification to user.";
                            }
                        }
                    } else {
                        $_SESSION['error_message'] = $result_action['message'];
                    }
                }
            } catch (MongoDBException | Exception $e) {
                $_SESSION['error_message'] = "Error processing request: " . $e->getMessage();
                error_log("Transaction update error: " . $e->getMessage());
            }
        }
    }
    header("Location: transactions_management.php?status_filter=" . urlencode($status_filter));
    exit;
}

// ... [Your existing code for fetching and displaying transactions] ...
// The rest of the file (HTML, CSS, etc.) remains the same.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Transaction Management</title>
    <link rel="stylesheet" href="admin_style.css">
    <link rel="stylesheet" href="transaction.css"> 
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <header class="admin-header">
        <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo" class="logo">
        <div class="admin-info">
            <span>Welcome, Admin!</span> <a href="admin_logout.php">Logout</a>
        </div>
    </header>

    <div class="admin-container">
        <nav class="admin-sidebar">
            <ul>
                <li><a href="create_user.php">Create New User</a></li>
                <li><a href="manage_users.php">Manage Users (Edit/Delete)</a></li>
                <li><a href="manage_user_funds.php">Manage User Funds (Credit/Debit)</a></li>
                <li><a href="account_status_management.php">Manage Account Status</a></li>
                <li><a href="transactions_management.php" class="active">Transactions Management</a></li>
                <li><a href="generate_bank_card.php">Generate Bank Card (Mock)</a></li>
                <li><a href="generate_mock_transaction.php">Generate Mock Transaction</a></li>
            </ul>
        </nav>

        <main class="admin-main-content">
            <h1 class="section-header">Transaction Management</h1>

            <?php
            // Display success/error/info messages from session
            if (isset($_SESSION['success_message'])) {
                echo '<div class="message success">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                unset($_SESSION['success_message']);
            }
            if (isset($_SESSION['error_message'])) {
                echo '<div class="message error">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
                unset($_SESSION['error_message']);
            }
            if (isset($_SESSION['info_message'])) {
                echo '<div class="message info">' . htmlspecialchars($_SESSION['info_message']) . '</div>';
                unset($_SESSION['info_message']);
            }
            ?>

            <form action="transactions_management.php" method="GET" style="margin-bottom: 20px;">
                <label for="filter_status" style="font-weight: bold; margin-right: 10px;">Filter by Status:</label>
                <select name="status_filter" id="filter_status" onchange="this.form.submit()" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All</option>
                    <option value="pending" <?php echo ($status_filter == 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo ($status_filter == 'approved') ? 'selected' : ''; ?>>Approved</option>
                    <option value="declined" <?php echo ($status_filter == 'declined') ? 'selected' : ''; ?>>Declined</option>
                    <option value="completed" <?php echo ($status_filter == 'completed') ? 'selected' : ''; ?>>Completed</option>
                    <option value="restricted" <?php echo ($status_filter == 'restricted') ? 'selected' : ''; ?>>Restricted</option>
                    <option value="failed" <?php echo ($status_filter == 'failed') ? 'selected' : ''; ?>>Failed</option>
                    <option value="on hold" <?php echo ($status_filter == 'on hold') ? 'selected' : ''; ?>>On Hold</option>
                    <option value="refunded" <?php echo ($status_filter == 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                </select>
            </form>

            <div class="table-responsive" style="overflow-x: auto;">
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>Ref. No.</th>
                            <th>Sender</th>
                            <th>Recipient</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Initiated At</th>
                            <th>Status</th>
                            <th>Message</th>
                            <th>Action By</th>
                            <th>Action At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 20px;">No transactions found for the selected filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td data-label="Ref. No."><?php echo htmlspecialchars($tx['transaction_reference'] ?? 'N/A'); ?></td>
                                    <td data-label="Sender"><?php echo htmlspecialchars(($tx['sender_fname'] ?? 'Unknown') . ' ' . ($tx['sender_lname'] ?? 'User')); ?></td>
                                    <td data-label="Recipient"><?php echo htmlspecialchars(($tx['recipient_name'] ?? 'N/A') . ' (' . ($tx['recipient_account_number'] ?? 'N/A') . ')'); ?></td>
                                    <td data-label="Amount">
                                        <?php echo htmlspecialchars(($tx['currency'] ?? 'N/A') . ' ' . number_format($tx['amount'] ?? 0, 2)); ?>
                                        <?php 
                                        if (!in_array(strtoupper($tx['currency'] ?? ''), $recommended_currencies)) {
                                            echo ' <span class="currency-warning" title="Not a recommended currency">!</span>';
                                        }
                                        ?>
                                    </td>
                                    <td data-label="Description"><?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?></td>
                                    <td data-label="Initiated At">
                                        <?php 
                                            echo (isset($tx['initiated_at']) && !empty($tx['initiated_at'])) ? date('M d, Y H:i', strtotime($tx['initiated_at'])) : 'N/A';
                                        ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($tx['status'] ?? 'default'))); ?>">
                                            <?php echo htmlspecialchars(ucfirst($tx['status'] ?? 'N/A')); ?>
                                        </span>
                                    </td>
                                    <td data-label="Admin Comment">
                                        <?php echo !empty($tx['Heritage_comment']) ? htmlspecialchars($tx['Heritage_comment']) : 'N/A'; ?>
                                    </td>
                                    <td data-label="Action By">
                                        <?php echo !empty($tx['admin_action_by']) ? htmlspecialchars($tx['admin_action_by']) : 'N/A'; ?>
                                    </td>
                                    <td data-label="Action At">
                                        <?php 
                                            echo (isset($tx['action_at']) && !empty($tx['action_at'])) ? date('M d, Y H:i', strtotime($tx['action_at'])) : 'N/A';
                                        ?>
                                    </td>
                                    <td data-label="Actions">
                                        <form action="transactions_management.php?status_filter=<?php echo htmlspecialchars($status_filter); ?>" method="POST" style="display:inline-block;">
                                            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($tx['_id'] ?? ''); ?>">
                                            <select name="new_status" style="padding: 5px; margin-right: 5px; margin-bottom: 5px;">
                                                <option value="">Set Status</option>
                                                <?php
                                                foreach ($settable_statuses as $status_option) {
                                                    $selected = (isset($tx['status']) && $tx['status'] == $status_option) ? 'selected' : '';
                                                    echo "<option value=\"" . htmlspecialchars($status_option) . "\" " . $selected . ">" . htmlspecialchars(ucfirst($status_option)) . "</option>";
                                                }
                                                ?>
                                            </select>
                                            <textarea name="message" rows="5" placeholder="Reason/Comment (required for decline/restrict)" style="width: 95%; max-width: 250px; vertical-align: top; margin-right: 5px; margin-bottom: 5px;"><?php echo htmlspecialchars($tx['Heritage_comment'] ?? ''); ?></textarea>
                                            <button type="submit" name="update_transaction_status" class="button-small button-edit">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>