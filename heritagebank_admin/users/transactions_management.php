<?php
// Path: C:\xampp\htdocs\heritagebank\admin\transactions_management.php

session_start();
require_once '../../Config.php'; // Contains database credentials and SMTP settings
require_once '../../functions.php'; // Contains helper functions including sendEmail, complete_pending_transfer, reject_pending_transfer
require_once '../../vendor/autoload.php'; // Include Composer's autoloader for MongoDB

// Admin authentication check
// Redirects to admin login page if not authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// --- MongoDB Connection ---
$client = null;
$database = null;
$transactionsCollection = null;
$usersCollection = null;

try {
    // MONGO_URI and MONGO_DB_NAME should be defined in Config.php
    $client = new MongoDB\Client(MONGO_URI);
    $database = $client->selectDatabase(MONGO_DB_NAME);
    $transactionsCollection = $database->selectCollection('transactions');
    $usersCollection = $database->selectCollection('users'); // Assuming 'users' collection for user details
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    $_SESSION['error_message'] = "ERROR: Could not connect to database. Please try again later.";
    // Potentially redirect or display error page if DB connection fails critical operations
}

// Define allowed transaction statuses for validation and display
$allowed_filters = ['approved', 'declined', 'completed', 'pending', 'restricted', 'failed', 'on hold', 'refunded', 'all'];
// These are the statuses an admin can actively SET a transaction to.
$settable_statuses = ['pending', 'approved', 'completed', 'declined', 'restricted', 'failed', 'refunded', 'on hold'];

// Define recommended currencies
$recommended_currencies = ['GBP', 'EUR'];

// Determine the current status filter from GET request, default to 'pending'
$status_filter = $_GET['status_filter'] ?? 'pending';
if (!in_array($status_filter, $allowed_filters)) {
    $status_filter = 'pending'; // Reset to default if filter is invalid/manipulated
}

/**
 * Helper function to construct and send the transaction update email.
 *
 * @param string $user_email The recipient's email address.
 * @param array $tx_details Transaction details array.
 * @param string $new_status The status being set.
 * @param string $admin_comment The admin's comment.
 * @return bool True on success, false on failure.
 */
function send_transaction_update_email_notification($user_email, $tx_details, $new_status, $admin_comment) {
    if (!$user_email) {
        error_log("Attempted to send email but user_email was empty for transaction ID: " . ($tx_details['_id'] ?? 'N/A'));
        return false;
    }

    $subject = 'Heritage Bank Transaction Update: ' . ucfirst($new_status);
    $amount_display = htmlspecialchars(($tx_details['currency'] ?? 'USD') . ' ' . number_format($tx_details['amount'] ?? 0, 2));
    $recipient_name_display = htmlspecialchars($tx_details['recipient_name'] ?? 'N/A');
    $transaction_ref_display = htmlspecialchars($tx_details['transaction_reference'] ?? 'N/A');
    $comment_display = !empty($admin_comment) ? htmlspecialchars($admin_comment) : 'N/A';

    $body = "
        <p>Dear Customer,</p>
        <p>This is to inform you about an update regarding your recent transaction with Heritage Bank.</p>
        <p><strong>Transaction Reference:</strong> {$transaction_ref_display}</p>
        <p><strong>Amount:</strong> {$amount_display}</p>
        <p><strong>Recipient:</strong> {$recipient_name_display}</p>
        <p><strong>New Status:</strong> <span style='font-weight: bold; color: ";

    // Apply status-specific styling for email body
    switch (strtolower($new_status)) {
        case 'approved':
        case 'completed':
            $body .= "green;";
            break;
        case 'declined':
        case 'restricted':
        case 'failed':
            $body .= "red;";
            break;
        case 'on hold':
            $body .= "orange;";
            break;
        case 'refunded':
            $body .= "teal;";
            break;
        default:
            $body .= "black;"; // For pending or other general statuses
    }
    $body .= "'>" . htmlspecialchars(ucfirst($new_status)) . "</span></p>";

    $body .= "<p><strong>Bank Comment:</strong> {$comment_display}</p>";
    
    $body .= "
        <p>If you have any questions, please do not hesitate to contact our support team.</p>
        <p>Thank you for banking with Heritage Bank.</p>
        <p>Sincerely,</p>
        <p>The Heritage Bank Team</p>
    ";

    $altBody = strip_tags($body); // Simple plain text version

    return sendEmail($user_email, $subject, $body, $altBody);
}


// --- Handle Transaction Status Update POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction_status'])) {
    if (!$transactionsCollection || !$usersCollection) {
        $_SESSION['error_message'] = "Database not connected. Cannot process update.";
        header("Location: transactions_management.php?status_filter=" . urlencode($status_filter));
        exit;
    }

    // Sanitize and validate input from the form
    $transaction_id_str = filter_var($_POST['transaction_id'], FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Keep as string for ObjectId conversion
    $new_status = filter_var($_POST['new_status'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $admin_comment_message = filter_var($_POST['message'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    
    // Get admin user details from session for logging the action
    $admin_username = $_SESSION['admin_username'] ?? 'Admin'; 

    // Basic input validation
    if (empty($transaction_id_str) || empty($new_status)) {
        $_SESSION['error_message'] = "Transaction ID and New Status are required.";
    } elseif (!in_array($new_status, $settable_statuses)) { // Check against statuses admin can set
        $_SESSION['error_message'] = "Invalid status provided for update.";
    } else {
        try {
            // Convert transaction_id_str to MongoDB\BSON\ObjectId
            $transaction_objectId = new MongoDB\BSON\ObjectId($transaction_id_str);

            // Fetch original transaction details and user email for notification BEFORE any updates
            $original_tx_details = $transactionsCollection->findOne(['_id' => $transaction_objectId]);

            if (!$original_tx_details) {
                $_SESSION['error_message'] = "Transaction not found for ID: " . htmlspecialchars($transaction_id_str) . ".";
            } else {
                $original_tx_details = (array) $original_tx_details; // Convert to array for consistent access

                // Fetch user email
                $user_doc = $usersCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($original_tx_details['user_id'])]);
                $user_email = $user_doc['email'] ?? null;
                
                $current_db_status = $original_tx_details['status']; // The status currently in the DB
                $transaction_currency = $original_tx_details['currency']; // Get the transaction currency

                $result_action = ['success' => false, 'message' => 'An unexpected error occurred.', 'transaction_details' => null];

                // Add a warning if the transaction currency is not recommended
                if (!in_array(strtoupper($transaction_currency), $recommended_currencies)) {
                    $_SESSION['info_message'] = (isset($_SESSION['info_message']) ? $_SESSION['info_message'] . ' ' : '') . 
                                                "Warning: This transaction's currency (" . htmlspecialchars($transaction_currency) . ") is not one of the recommended currencies (GBP, EUR).";
                }

                // Decide which helper function to call based on the new_status
                // These functions (complete_pending_transfer, reject_pending_transfer)
                // must be updated to use MongoDB operations and accept the collection objects.
                if ($new_status === 'completed' && $current_db_status === 'pending') {
                    // Assuming complete_pending_transfer function is updated to take MongoDB collection
                    $result_action = complete_pending_transfer($transactionsCollection, $transaction_objectId);
                } elseif ($new_status === 'declined' && $current_db_status === 'pending') {
                    // Assuming reject_pending_transfer function is updated to take MongoDB collection
                    $result_action = reject_pending_transfer($transactionsCollection, $transaction_objectId, $admin_comment_message);
                } else {
                    // For other status changes (e.g., pending -> approved, approved -> restricted, etc.)
                    // or if changing from/to 'completed'/'declined' when it wasn't pending
                    $update_result = $transactionsCollection->updateOne(
                        ['_id' => $transaction_objectId],
                        [
                            '$set' => [
                                'status' => $new_status,
                                'Heritage_comment' => $admin_comment_message,
                                'admin_action_by' => $admin_username,
                                'action_at' => new MongoDB\BSON\UTCDateTime() // Set current UTC date/time
                            ]
                        ]
                    );

                    if ($update_result->getModifiedCount() > 0 || $update_result->getMatchedCount() > 0) {
                        $result_action['success'] = true;
                        $result_action['message'] = "Transaction status updated directly to " . ucfirst($new_status) . ".";
                        
                        // Re-fetch transaction details after update for email notification, or update the array
                        $updated_tx_details = $transactionsCollection->findOne(['_id' => $transaction_objectId]);
                        $result_action['transaction_details'] = (array) $updated_tx_details;
                    } else {
                        $result_action['message'] = "Transaction update had no effect (status might already be " . ucfirst($new_status) . ").";
                    }
                }

                if ($result_action['success']) {
                    $_SESSION['success_message'] = (isset($_SESSION['success_message']) ? $_SESSION['success_message'] . ' ' : '') . $result_action['message']; // Append success messages
                    
                    // Send email notification using the transaction details obtained
                    if ($user_email && $result_action['transaction_details']) {
                        if (send_transaction_update_email_notification($user_email, $result_action['transaction_details'], $new_status, $admin_comment_message)) {
                            $_SESSION['info_message'] = (isset($_SESSION['info_message']) ? $_SESSION['info_message'] . ' ' : '') . "Email notification sent to " . htmlspecialchars($user_email) . ".";
                        } else {
                            $_SESSION['error_message'] = (isset($_SESSION['error_message']) ? $_SESSION['error_message'] . ' ' : '') . "Failed to send email notification to user.";
                        }
                    } else {
                        $_SESSION['error_message'] = (isset($_SESSION['error_message']) ? $_SESSION['error_message'] . ' ' : '') . "User email not found or transaction details incomplete for notification.";
                    }
                } else {
                    $_SESSION['error_message'] = (isset($_SESSION['error_message']) ? $_SESSION['error_message'] . ' ' : '') . $result_action['message']; // Append error messages
                }
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            $_SESSION['error_message'] = "Database error during update: " . $e->getMessage();
            error_log("MongoDB update error: " . $e->getMessage());
        } catch (Exception $e) { // Catch for ObjectId conversion or other general errors
            $_SESSION['error_message'] = "Error processing request: " . $e->getMessage();
            error_log("Transaction update general error: " . $e->getMessage());
        }
    }
    // Redirect back to the transaction management page to prevent form resubmission
    header("Location: transactions_management.php?status_filter=" . urlencode($status_filter));
    exit;
}

// --- Fetch Transactions for Display ---
$transactions = [];
if ($transactionsCollection && $usersCollection) {
    try {
        $filter = [];
        if ($status_filter !== 'all') {
            $filter['status'] = $status_filter;
        }

        $options = [
            'sort' => ['initiated_at' => -1]
        ];

        $cursor = $transactionsCollection->find($filter, $options);

        foreach ($cursor as $txDoc) {
            $tx = (array) $txDoc;

            // Fetch sender's first_name, last_name, and email from the users collection
            $sender_details = $usersCollection->findOne(
                ['_id' => new MongoDB\BSON\ObjectId($tx['user_id'])], // Assuming user_id is stored as ObjectId
                ['projection' => ['first_name' => 1, 'last_name' => 1, 'email' => 1]]
            );

            if ($sender_details) {
                $tx['sender_fname'] = $sender_details['first_name'] ?? '';
                $tx['sender_lname'] = $sender_details['last_name'] ?? '';
                $tx['sender_email'] = $sender_details['email'] ?? '';
            } else {
                $tx['sender_fname'] = 'Unknown';
                $tx['sender_lname'] = 'User';
                $tx['sender_email'] = 'N/A';
            }

            // Convert MongoDB\BSON\UTCDateTime objects to formatted strings for display
            if (isset($tx['initiated_at']) && $tx['initiated_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $tx['initiated_at'] = $tx['initiated_at']->toDateTime()->format('Y-m-d H:i:s');
            }
            if (isset($tx['action_at']) && $tx['action_at'] instanceof MongoDB\BSON\UTCDateTime) {
                $tx['action_at'] = $tx['action_at']->toDateTime()->format('Y-m-d H:i:s');
            }

            $transactions[] = $tx;
        }
    } catch (MongoDB\Driver\Exception\Exception $e) {
        $_SESSION['error_message'] = "Failed to fetch transactions from database: " . $e->getMessage();
        error_log("MongoDB fetch error: " . $e->getMessage());
    }
} else {
    $_SESSION['error_message'] = (isset($_SESSION['error_message']) ? $_SESSION['error_message'] . ' ' : '') . "Database connection not established for fetching transactions.";
}

// No need to explicitly close MongoDB connection, it's managed by PHP when script ends
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Transaction Management</title>
    <link rel="stylesheet" href="admin_style.css"> 
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* --- General Admin Panel Styles (Can be moved to admin_style.css) --- */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .admin-header {
            background-color: #2c3e50; /* Dark blue-grey */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .admin-header .logo {
            height: 35px;
            filter: brightness(0) invert(1); /* Makes logo white if it's dark */
        }
        .admin-header .admin-info {
            font-size: 1.1em;
        }
        .admin-header .admin-info a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            padding: 8px 15px;
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }
        .admin-header .admin-info a:hover {
            background-color: rgba(255,255,255,0.2);
        }
        .admin-container {
            display: flex;
            flex-grow: 1;
        }
        .admin-sidebar {
            width: 250px;
            background-color: #34495e; /* Slightly lighter dark blue-grey */
            color: white;
            padding-top: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        .admin-sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .admin-sidebar ul li a {
            display: block;
            padding: 15px 30px;
            color: white;
            text-decoration: none;
            font-size: 1.05em;
            border-left: 5px solid transparent;
            transition: background-color 0.3s ease, border-left-color 0.3s ease;
        }
        .admin-sidebar ul li a:hover,
        .admin-sidebar ul li a.active {
            background-color: #4a667f; /* Darker hover */
            border-left-color: #3498db; /* Bright blue highlight */
        }
        .admin-sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        .admin-main-content {
            flex-grow: 1;
            padding: 30px;
            background-color: #f4f7f6;
        }
        .section-header {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.8em;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        /* Message styles */
        .message {
            padding: 10px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 1em;
            display: flex;
            align-items: center;
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
        .message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Table styles */
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden; /* Ensures rounded corners apply to content */
        }
        .transaction-table th,
        .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top; /* Align content to top */
        }
        .transaction-table th {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.9em;
        }
        .transaction-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .transaction-table tbody tr:hover {
            background-color: #e6f0fa;
        }

        /* Button styles */
        .button-small {
            padding: 6px 12px;
            font-size: 0.9em;
            border-radius: 4px;
            cursor: pointer;
            border: none;
            transition: background-color 0.3s ease;
        }
        .button-edit {
            background-color: #3498db; /* Blue */
            color: white;
        }
        .button-edit:hover {
            background-color: #217dbb;
        }

        /* --- Status-Specific Styling (NEW and Existing) --- */
        .status-pending { 
            color: orange; 
            font-weight: bold; 
            background-color: #FFF8E1;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-approved { 
            color: green; 
            font-weight: bold; 
            background-color: #E8F5E9;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-completed { 
            color: darkgreen; 
            font-weight: bold; 
            background-color: #E0F2F1;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-declined { 
            color: red; 
            font-weight: bold; 
            background-color: #FFEBEE;
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-restricted { 
            color: #8B0000; /* Dark red */
            font-weight: bold; 
            background-color: #FBE9E7;
            padding: 3px 8px;
            border-radius: 4px;
        }
        /* NEW Status Styles */
        .status-failed {
            color: #DC3545; /* Red */
            font-weight: bold;
            background-color: #FADBD8; /* Light red background */
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-on-hold {
            color: #FFC107; /* Yellow/Orange */
            font-weight: bold;
            background-color: #FFF3CD; /* Light yellow background */
            padding: 3px 8px;
            border-radius: 4px;
        }
        .status-refunded {
            color: #17A2B8; /* Teal/Cyan */
            font-weight: bold;
            background-color: #D1ECF1; /* Light blue background */
            padding: 3px 8px;
            border-radius: 4px;
        }

        /* Styles for currency recommendation */
        .currency-warning {
            color: #E6B300; /* Darker yellow/orange for warning */
            font-weight: bold;
            font-size: 0.85em;
            margin-left: 5px;
            white-space: nowrap; /* Prevent wrapping */
            background-color: #FFFDE7; /* Very light yellow background */
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid #FFECB3; /* Light orange border */
        }

        /* --- Responsive Adjustments --- */
        @media (max-width: 992px) {
            .admin-container {
                flex-direction: column;
            }
            .admin-sidebar {
                width: 100%;
                padding-top: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .admin-sidebar ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
            }
            .admin-sidebar ul li {
                flex: 1 1 auto;
                text-align: center;
            }
            .admin-sidebar ul li a {
                border-left: none;
                border-bottom: 3px solid transparent;
                padding: 10px 15px;
            }
            .admin-sidebar ul li a:hover,
            .admin-sidebar ul li a.active {
                border-left-color: transparent;
                border-bottom-color: #3498db;
            }
            .admin-main-content {
                padding: 20px;
            }
        }
        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .admin-header .admin-info {
                margin-top: 10px;
            }
            .transaction-table th,
            .transaction-table td {
                padding: 8px 10px;
                font-size: 0.9em;
            }
            .transaction-table thead {
                display: none; /* Hide table headers on small screens */
            }
            .transaction-table, .transaction-table tbody, .transaction-table tr, .transaction-table td {
                display: block; /* Make table elements act as blocks */
                width: 100%;
            }
            .transaction-table tr {
                margin-bottom: 15px;
                border: 1px solid #eee;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .transaction-table td {
                text-align: right;
                padding-left: 50%;
                position: relative;
                border: none;
            }
            .transaction-table td::before {
                content: attr(data-label); /* Use data-label for pseudo-elements */
                position: absolute;
                left: 10px;
                width: calc(50% - 20px);
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                color: #555;
            }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <img src="../../images/logo.png" alt="Heritage Bank Admin Logo" class="logo">
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
            // Display success/error/info messages stored in session
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
                    <option value="failed" <?php echo ($status_filter == 'failed') ? 'selected' : ''; ?>>Failed</option>    <option value="on hold" <?php echo ($status_filter == 'on hold') ? 'selected' : ''; ?>>On Hold</option>   <option value="refunded" <?php echo ($status_filter == 'refunded') ? 'selected' : ''; ?>>Refunded</option> </select>
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
                                            // Handle cases where initiated_at might be missing or not a valid date
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
                                            // Handle cases where action_at might be missing or not a valid date
                                            echo (isset($tx['action_at']) && !empty($tx['action_at'])) ? date('M d, Y H:i', strtotime($tx['action_at'])) : 'N/A';
                                        ?>
                                    </td>
                                    <td data-label="Actions">
                                        <form action="transactions_management.php?status_filter=<?php echo htmlspecialchars($status_filter); ?>" method="POST" style="display:inline-block;">
                                            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($tx['_id'] ?? ''); ?>">
                                            <select name="new_status" style="padding: 5px; margin-right: 5px; margin-bottom: 5px;">
                                                <option value="">Set Status</option>
                                                <?php
                                                // These are the statuses an admin can set
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