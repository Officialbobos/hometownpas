<?php
// C:\xampp\htdocs\heritagebank\admin\transactions_management.php

session_start(); // Ensure session is started at the very beginning

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CORRECTED PATHS: Use __DIR__ to build a reliable path
require_once __DIR__ . '/../../Config.php'; 
require_once __DIR__ . '/../../functions.php'; 
require_once __DIR__ . '/../../vendor/autoload.php'; // Make sure PHPMailer is loaded

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
$accountsCollection = null; // Also need accounts collection for pending transfers

try {
    $client = new Client(MONGODB_CONNECTION_URI, [], [
        'connectTimeoutMS' => 10000,
        'socketTimeoutMS' => 30000
    ]);
    
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $transactionsCollection = $database->selectCollection('transactions');
    $usersCollection = $database->selectCollection('users'); 
    $accountsCollection = $database->selectCollection('accounts');
} catch (MongoDBException $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    $_SESSION['error_message'] = "ERROR: Could not connect to the database. Please try again later.";
    header('Location: admin_dashboard.php'); // Redirect to a suitable admin page
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
 * This function must be defined or included before it's called.
 */
function send_transaction_update_email_notification($user_email, $tx_details, $new_status, $admin_comment) {
    if (!function_exists('sendEmail')) {
        error_log("sendEmail function not found. Cannot send transaction update email.");
        return false;
    }

    if (!$user_email) {
        error_log("Attempted to send email but user_email was empty for transaction ID: " . ($tx_details['_id'] ?? 'N/A'));
        return false;
    }

    $subject = 'Heritage Bank Transaction Update: ' . ucfirst($new_status);
    $amount_display = htmlspecialchars(($tx_details['currency'] ?? 'USD') . ' ' . number_format($tx_details['amount'] ?? 0, 2));
    $recipient_name_display = htmlspecialchars($tx_details['recipient_name'] ?? 'N/A');
    $transaction_ref_display = htmlspecialchars($tx_details['transaction_reference'] ?? 'N/A');
    $comment_display = !empty($admin_comment) ? htmlspecialchars($admin_comment) : 'N/A';
    
    // Status-specific styling for the email body
    $status_color = '';
    switch ($new_status) {
        case 'completed': $status_color = '#28a745'; break; // Green
        case 'approved': $status_color = '#007bff'; break; // Blue
        case 'declined': 
        case 'failed': $status_color = '#dc3545'; break; // Red
        case 'pending': 
        case 'on hold': 
        case 'restricted': $status_color = '#ffc107'; break; // Yellow/Orange
        default: $status_color = '#6c757d'; break; // Grey
    }
    
   $body = <<<EOT
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaction Update from Heritage Bank</title>
    <style type="text/css">
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e0e0;
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #eeeeee;
            margin-bottom: 25px;
        }
        .header img {
            max-width: 150px;
            height: auto;
            margin-bottom: 10px;
        }
        .header h1 {
            color: #004d40; /* Heritage Bank primary color example */
            font-size: 24px;
            margin: 0;
            padding: 0;
        }
        .content p {
            margin-bottom: 15px;
            font-size: 16px;
        }
        .content strong {
            color: #004d40; /* Match header color for strong tags */
        }
        .transaction-details {
            background-color: #f9f9f9;
            border-left: 4px solid #004d40;
            padding: 15px 20px;
            margin: 25px 0;
            border-radius: 4px;
        }
        .transaction-details p {
            margin: 8px 0;
            font-size: 15px;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #eeeeee;
            margin-top: 25px;
            font-size: 14px;
            color: #777777;
        }
        .footer p {
            margin: 5px 0;
        }
        .status-text {
            font-weight: bold;
        }
        .green-status { color: #28a745; }
        .red-status { color: #dc3545; }
        .orange-status { color: #ffc107; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Heritage Bank</h1>
        </div>
        <div class="content">
            <p>Dear {$customer_name},</p>
            <p>This is to inform you about an update regarding your recent transaction:</p>

            <div class="transaction-details">
                <p><strong>Transaction Reference:</strong> {$transaction_ref_display}</p>
                <p><strong>Amount:</strong> {$amount_display}</p>
                <p><strong>Recipient:</strong> {$recipient_name_display}</p>
                <p><strong>New Status:</strong> <span class="status-text" style="color: {$status_color};">
                    EOT;

                    // Dynamically set the status text color using the $status_color variable
                    $body .= htmlspecialchars(ucfirst($new_status));

                    $body .= <<<EOT
                </span></p>
            </div>
EOT;

if (!empty($admin_comment)) {
    $body .= "<p><strong>Bank Comment:</strong> {$comment_display}</p>";
}

$body .= <<<EOT
            <p>If you have any questions or require further assistance, please do not hesitate to contact our support team directly. We are always here to help.</p>
            <p>Thank you for banking with Heritage Bank.</p>
            <p>Sincerely,</p>
            <p>Heritage Bank Management Team</p>
        </div>
        <div class="footer">
            <p>&copy; 2025 Heritage Bank. All rights reserved.</p>
            <p>123 Bank Street, City, Country</p>
        </div>
    </div>
</body>
</html>
EOT;

$altBody = strip_tags($body); // This generates a plain-text version for email clients that don't display HTML.

return sendEmail($user_email, $subject, $body, $altBody);

// --- Handle Transaction Status Update POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction_status'])) {
    if (!$transactionsCollection || !$usersCollection || !$accountsCollection) {
        $_SESSION['error_message'] = "Database collections not connected. Cannot process update.";
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
                    // Ensure these functions exist in functions.php and handle DB updates and returns correctly
                    if ($new_status === 'completed' && $current_db_status === 'pending') {
                        $result_action = complete_pending_transfer($transactionsCollection, $accountsCollection, $original_tx_details);
                    } elseif ($new_status === 'declined' && $current_db_status === 'pending') {
                        $result_action = reject_pending_transfer($transactionsCollection, $accountsCollection, $original_tx_details, $admin_comment_message);
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
                        // Set the session variable for the user's modal (assuming this is picked up by frontend/dashboard.php or similar)
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

// --- Fetch Transactions for Display ---
$filter_query = [];
if ($status_filter !== 'all') {
    $filter_query['status'] = $status_filter;
}

$transactions = [];
try {
    if ($transactionsCollection) {
        // Fetch transactions from MongoDB
        $cursor = $transactionsCollection->find(
            $filter_query,
            ['sort' => ['transaction_date' => -1]] // Sort by latest transactions first
        );
        $transactions = $cursor->toArray();

        // Populate sender details for each transaction
        foreach ($transactions as $key => $tx) {
            if (isset($tx['user_id'])) {
                try {
                    $user = $usersCollection->findOne(['_id' => new ObjectId($tx['user_id'])]);
                    $transactions[$key]['sender_fname'] = $user['first_name'] ?? 'Unknown';
                    $transactions[$key]['sender_lname'] = $user['last_name'] ?? 'User';
                } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
                    error_log("Invalid user ID for transaction " . ($tx['_id'] ?? 'N/A') . ": " . $e->getMessage());
                    $transactions[$key]['sender_fname'] = 'Invalid';
                    $transactions[$key]['sender_lname'] = 'User ID';
                }
            }
            // Ensure dates are formatted correctly for display
            if (isset($tx['initiated_at']) && $tx['initiated_at'] instanceof UTCDateTime) {
                $transactions[$key]['initiated_at'] = $tx['initiated_at']->toDateTime()->format('Y-m-d H:i:s');
            } else {
                $transactions[$key]['initiated_at'] = null; // Or 'N/A'
            }
            if (isset($tx['action_at']) && $tx['action_at'] instanceof UTCDateTime) {
                $transactions[$key]['action_at'] = $tx['action_at']->toDateTime()->format('Y-m-d H:i:s');
            } else {
                $transactions[$key]['action_at'] = null; // Or 'N/A'
            }
        }
    } else {
        $_SESSION['error_message'] = "Transactions collection not available.";
    }
} catch (MongoDBException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $_SESSION['error_message'] = "Error fetching transactions: " . $e->getMessage();
} catch (Exception $e) {
    error_log("General error fetching transactions: " . $e->getMessage());
    $_SESSION['error_message'] = "An unexpected error occurred while fetching transactions.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Transaction Management</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/admin_style.css'; ?>">
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/transaction.css'; ?>">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Body and Container Styling */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .admin-container {
            display: flex;
            flex: 1; /* Allows it to take up remaining space */
            width: 100%;
            max-width: 1400px; /* Max width for content */
            margin: 0 auto;
            box-sizing: border-box;
        }

        /* Admin Header */
        .admin-header {
            background-color: #004494; /* Heritage Blue */
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .admin-header .logo {
            height: 35px; /* Adjusted logo size */
            width: auto;
        }
        .admin-header h1 {
            margin: 0;
            font-size: 1.5em; /* Adjusted font size */
            color: white;
        }
        .admin-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .admin-info span {
            font-size: 1em;
        }
        .admin-info a {
            background-color: #ffcc29; /* Heritage Gold */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .admin-info a:hover {
            background-color: #e0b821;
        }

        /* Admin Sidebar */
        .admin-sidebar {
            width: 250px;
            background-color: #ffffff;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
            flex-shrink: 0; /* Prevent shrinking */
        }
        .admin-sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .admin-sidebar ul li {
            margin-bottom: 10px;
        }
        .admin-sidebar ul li a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .admin-sidebar ul li a:hover {
            background-color: #e9ecef;
            color: #004494;
        }
        .admin-sidebar ul li a.active {
            background-color: #004494;
            color: white;
        }

        /* Main Content Area */
        .admin-main-content {
            flex-grow: 1; /* Takes up remaining space */
            padding: 30px;
            background-color: #f4f7f6;
        }

        .section-header {
            color: #004494;
            margin-bottom: 25px;
            font-size: 2em;
            font-weight: 700;
        }

        /* Messages */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            word-wrap: break-word;
            border: 1px solid transparent;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Filter Form */
        form {
            margin-bottom: 20px;
            background-color: #ffffff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        form label {
            font-weight: bold;
            color: #333;
        }
        form select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
            min-width: 150px;
        }

        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            margin-top: 20px;
        }
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .transaction-table th,
        .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        .transaction-table th {
            background-color: #e9ecef;
            color: #333;
            font-weight: 600;
            white-space: nowrap; /* Prevent headers from wrapping */
        }
        .transaction-table tbody tr:hover {
            background-color: #f2f2f2;
        }

        /* Status Badges */
        .status-pending { background-color: #ffc107; color: #333; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Yellow */
        .status-approved { background-color: #007bff; color: white; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Blue */
        .status-completed { background-color: #28a745; color: white; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Green */
        .status-declined, .status-failed, .status-restricted { background-color: #dc3545; color: white; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Red */
        .status-on-hold { background-color: #6c757d; color: white; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Gray */
        .status-refunded { background-color: #17a2b8; color: white; padding: 5px 8px; border-radius: 4px; font-size: 0.85em; font-weight: bold; } /* Teal */
        .currency-warning {
            color: #dc3545;
            font-weight: bold;
            cursor: help;
            font-size: 1.2em;
        }

        /* Action Forms in Table Cells */
        .transaction-table td form {
            display: flex;
            flex-direction: column; /* Stack elements vertically */
            gap: 5px; /* Space between elements */
            margin: 0; /* Remove default form margin */
            padding: 0;
            background-color: transparent; /* Override parent form background */
            box-shadow: none; /* Override parent form shadow */
        }
        .transaction-table td select,
        .transaction-table td textarea {
            width: 100%; /* Take full width of cell */
            box-sizing: border-box; /* Include padding/border in width */
            margin: 0; /* Remove default margin */
        }
        .transaction-table td textarea {
            resize: vertical; /* Allow vertical resizing */
            min-height: 50px; /* Minimum height for textarea */
            max-height: 150px; /* Maximum height for textarea */
            overflow-y: auto;
        }
        .button-small {
            padding: 6px 12px;
            font-size: 0.9em;
            border-radius: 4px;
            cursor: pointer;
            border: none;
            white-space: nowrap; /* Prevent button text from wrapping */
        }
        .button-edit {
            background-color: #007bff; /* Blue */
            color: white;
            transition: background-color 0.2s ease;
        }
        .button-edit:hover {
            background-color: #0056b3;
        }

        /* --- Responsive Design --- */
        @media (max-width: 992px) { /* Tablets and smaller desktops */
            .admin-container {
                flex-direction: column; /* Stack sidebar and main content */
            }
            .admin-sidebar {
                width: 100%; /* Sidebar takes full width */
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Shadow on bottom */
                padding: 10px 0;
            }
            .admin-sidebar ul {
                display: flex; /* Arrange links horizontally */
                flex-wrap: wrap; /* Allow wrapping */
                justify-content: center; /* Center links */
                gap: 5px; /* Space between links */
            }
            .admin-sidebar ul li {
                margin-bottom: 0; /* Remove vertical margin */
            }
            .admin-sidebar ul li a {
                padding: 8px 12px; /* Smaller padding for links */
                font-size: 0.9em;
            }
            .admin-main-content {
                padding: 20px;
            }
        }

        @media (max-width: 768px) { /* Smaller tablets and mobile */
            .admin-header {
                flex-direction: column;
                padding: 10px 15px;
                gap: 10px;
            }
            .admin-header h1 {
                font-size: 1.3em;
            }
            .admin-info {
                width: 100%;
                justify-content: center;
            }
            .admin-info a {
                padding: 6px 10px;
                font-size: 0.9em;
            }

            /* Table responsive behavior */
            .transaction-table, .transaction-table tbody, .transaction-table tr, .transaction-table td {
                display: block;
                width: 100%;
            }
            .transaction-table thead {
                display: none; /* Hide table headers on small screens */
            }
            .transaction-table tr {
                margin-bottom: 15px;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                overflow: hidden; /* For rounded corners */
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .transaction-table td {
                text-align: right;
                padding-left: 50%; /* Space for the data-label */
                position: relative;
                border: none; /* Remove individual cell borders */
            }
            .transaction-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                text-align: left;
                font-weight: bold;
                color: #555;
            }

            .transaction-table td:last-child {
                border-bottom: none;
            }

            /* Adjust action forms within responsive table */
            .transaction-table td form {
                align-items: flex-end; /* Align elements to the right */
            }
            .transaction-table td select,
            .transaction-table td textarea,
            .transaction-table td .button-small {
                width: auto; /* Allow elements to size naturally */
                max-width: 100%; /* Prevent overflow */
                margin-left: auto; /* Push to right */
                margin-right: 0;
            }
            .transaction-table td textarea {
                text-align: left; /* Keep text aligned left within textarea */
            }
        }

        @media (max-width: 480px) { /* Extra small devices */
            .admin-main-content {
                padding: 15px;
            }
            .section-header {
                font-size: 1.6em;
            }
            form {
                flex-direction: column;
                align-items: stretch;
            }
            form select {
                width: 100%;
                min-width: unset;
            }
            .admin-sidebar ul {
                flex-direction: column; /* Stack links vertically on tiny screens */
            }
            .admin-sidebar ul li a {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <img src="https://i.imgur.com/UeqGGSn.png" alt="Heritage Bank Logo" class="logo">
        <h1>Admin Dashboard</h1> <div class="admin-info">
            <span>Welcome, Admin!</span> <a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/admin_logout.php'; ?>">Logout</a>
        </div>
    </header>

    <div class="admin-container">
        <nav class="admin-sidebar">
            <ul>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/create_user.php'; ?>">Create New User</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_users.php'; ?>">Manage Users (Edit/Delete)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_user_funds.php'; ?>">Manage User Funds (Credit/Debit)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/account_status_management.php'; ?>">Manage Account Status</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/transactions_management.php'; ?>" class="active">Transactions Management</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/generate_bank_card.php'; ?>">Generate Bank Card (Mock)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/generate_mock_transaction.php'; ?>">Generate Mock Transaction</a></li>
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

            <form action="transactions_management" method="GET">
                <label for="filter_status">Filter by Status:</label>
                <select name="status_filter" id="filter_status" onchange="this.form.submit()">
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

            <div class="table-responsive">
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
                            <th>Comment</th>
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
                                    <td data-label="Comment">
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
                                        <form action="transactions_management?status_filter=<?php echo htmlspecialchars($status_filter); ?>" method="POST">
                                            <input type="hidden" name="transaction_id" value="<?php echo htmlspecialchars($tx['_id'] ?? ''); ?>">
                                            <select name="new_status">
                                                <option value="">Set Status</option>
                                                <?php
                                                foreach ($settable_statuses as $status_option) {
                                                    $selected = (isset($tx['status']) && $tx['status'] == $status_option) ? 'selected' : '';
                                                    echo "<option value=\"" . htmlspecialchars($status_option) . "\" " . $selected . ">" . htmlspecialchars(ucfirst($status_option)) . "</option>";
                                                }
                                                ?>
                                            </select>
                                            <textarea name="message" rows="3" placeholder="Reason/Comment (optional)" class="admin-comment-textarea"><?php echo htmlspecialchars($tx['Heritage_comment'] ?? ''); ?></textarea>
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
    <script>
    // Optional: JavaScript to dynamically adjust textarea height if needed
    document.querySelectorAll('.admin-comment-textarea').forEach(textarea => {
        // Event listener for dynamic resizing on input
        textarea.addEventListener('input', function() {
            // 'this' inside the event listener correctly refers to the textarea element
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Adjust height on load for *this specific textarea*
        // Use the 'textarea' variable which correctly refers to the current element in the forEach loop
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight) + 'px';
    });
</script>
</body>
</html>
