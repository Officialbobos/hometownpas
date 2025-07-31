<?php
session_start(); // Keep session_start() at the very top of each script that uses sessions.

// 1. Load Composer's autoloader FIRST. This makes Dotenv and MongoDB classes available.
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Now load your Config.php. This file now handles Dotenv loading and error reporting settings.
require_once __DIR__ . '/../Config.php';

// 3. Load your functions.php
require_once __DIR__ . '/../functions.php'; // If you have a sanitize_input function here

use MongoDB\Client; // Make sure this is imported if using new MongoDB\Client
use MongoDB\BSON\ObjectId; // For converting string IDs to MongoDB ObjectIds

// Check if the user is logged in. If not, redirect to login page.
// Using BASE_URL for consistency in redirects
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php'); // Assuming login.php is directly under BASE_URL
    exit;
}

// Fetch username, first_name, last_name from session (set during login)
$username = $_SESSION['username'] ?? 'User'; // Fallback if username not set in session
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';

// Generate full name for display
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    $full_name = $username;
}

$user_id_mongo = null;
try {
    // Convert session user_id to MongoDB ObjectId
    $user_id_mongo = new ObjectId($_SESSION['user_id']);
} catch (Exception $e) {
    error_log("Invalid user ID in session for statements.php: " . $_SESSION['user_id'] . " - " . $e->getMessage());
    // Handle invalid user ID, e.g., redirect to login with an error message
    $_SESSION['message'] = "Invalid session. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . BASE_URL . '/login.php'); // Redirect to login
    exit;
}

$mongoClient = null;
$user_transactions = []; // Initialize array to store fetched transactions
$user_currency_symbol = '€'; // Default for display, will be updated based on user's account currency

try {
    // Establish MongoDB connection
    // OPTION 1: Using the helper function (recommended for consistency)
    $mongoClient = getMongoDBClient(); // Assuming getMongoDBClient() exists in functions.php

    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    // Fetch user's primary account currency
    $accountsCollection = $database->accounts;
    $account = $accountsCollection->findOne(['user_id' => $user_id_mongo], ['projection' => ['currency' => 1]]);

    if ($account && isset($account['currency'])) {
        $user_currency_code = strtoupper($account['currency']);
        switch ($user_currency_code) {
            case 'GBP': $user_currency_symbol = '£'; break;
            case 'USD': $user_currency_symbol = '$'; break;
            case 'NGN': $user_currency_symbol = '₦'; break; // Assuming Naira
            case 'EUR':
            default: $user_currency_symbol = '€'; break;
        }
    }

    // Fetch user's transactions from the 'transactions' collection
    $transactionsCollection = $database->transactions;

    $filter = [
        '$or' => [
            ['user_id' => $user_id_mongo],
            ['recipient_user_id' => $user_id_mongo]
        ]
    ];
    $options = [
        'sort' => ['initiated_at' => -1], // Sort by initiated_at descending
        'projection' => [
            'initiated_at' => 1,
            'description' => 1,
            'amount' => 1,
            'transaction_type' => 1,
            'status' => 1,
            'transaction_reference' => 1,
            'user_id' => 1, // Include to check if sender/receiver
            'recipient_user_id' => 1, // Include to check if sender/receiver
        ]
    ];

    $transactionsCursor = $transactionsCollection->find($filter, $options);

    foreach ($transactionsCursor as $transaction) {
        // Convert MongoDB\BSON\UTCDateTime to PHP DateTime object
        if ($transaction['initiated_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $transaction['initiated_at'] = $transaction['initiated_at']->toDateTime()->format('Y-m-d H:i:s');
        }

        // Determine actual flow type (debit/credit) from *this user's perspective*
        if (isset($transaction['user_id']) && $transaction['user_id'] == $user_id_mongo) {
            // This user initiated the transaction (likely a debit/outflow)
            $transaction['flow_type'] = 'debit';
        } elseif (isset($transaction['recipient_user_id']) && $transaction['recipient_user_id'] == $user_id_mongo) {
            // This user is the recipient (likely a credit/inflow)
            $transaction['flow_type'] = 'credit';
        } else {
            // Fallback, use the stored transaction_type, or default to debit if ambiguous
            $transaction['flow_type'] = strtolower($transaction['transaction_type'] ?? 'debit');
            if ($transaction['flow_type'] == 'deposit') $transaction['flow_type'] = 'credit'; // Treat deposits as credit
        }

        $user_transactions[] = (array) $transaction; // Cast to array for consistent access
    }

} catch (Exception $e) {
    error_log("MongoDB Error in statements.php: " . $e->getMessage());
    // Display a user-friendly error message or redirect
    $_SESSION['message'] = "Error fetching statements. Please try again later.";
    $_SESSION['message_type'] = "error";
    // Optional: header('Location: ' . BASE_URL . '/dashboard.php'); // Redirect to dashboard
} finally {
    $mongoClient = null; // Let PHP's garbage collection handle the client connection.
}

// Organize transactions into 'statements' by month/year for display
$grouped_transactions = [];
foreach ($user_transactions as $transaction) {
    // Ensure 'initiated_at' is a string compatible with DateTime constructor
    $date = new DateTime($transaction['initiated_at']);
    $period = $date->format('F Y'); // e.g., "July 2025"

    if (!isset($grouped_transactions[$period])) {
        $grouped_transactions[$period] = [];
    }
    $grouped_transactions[$period][] = $transaction;
}

// Ensure the periods are sorted from most recent to oldest
// uksort preserves keys (month names) while sorting by values (timestamps)
uksort($grouped_transactions, function($a, $b) {
    // Convert "Month Year" to a timestamp for comparison
    return strtotime($b . " 01") - strtotime($a . " 01");
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statements - HomeTown Bank Pa</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/accounts.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <style>
        /* General styling for the main content area */
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #FFFFFF; /* Changed to white */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .dashboard-header {
            background-color: #3F0071; /* Changed to dark purple */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            position: fixed; /* Make header fixed */
            width: 100%;
            top: 0;
            left: 0;
            z-index: 1000;
        }

        .dashboard-header .logo-barclays {
            height: 40px;
            filter: brightness(0) invert(1);
        }

        .user-info {
            display: flex;
            align-items: center;
            font-size: 1.1em;
        }

        .user-info .profile-icon {
            margin-right: 10px;
            font-size: 1.5em;
            color: #ffcc00; /* Gold color for icon */
        }

        .user-info span {
            margin-right: 20px;
        }

        .user-info a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .user-info a:hover {
            background-color: rgba(255,255,255,0.2);
        }

        .dashboard-container {
            display: flex;
            flex-grow: 1;
            padding-top: 70px; /* Space for fixed header */
        }

        .sidebar {
            width: 250px;
            background-color: #FFFFFF; /* Changed to white */
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
            padding-top: 20px;
            flex-shrink: 0;
            position: fixed; /* Make sidebar fixed */
            top: 70px; /* Below the header */
            bottom: 0;
            left: 0;
            overflow-y: auto; /* Enable scrolling for many menu items */
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar ul li a {
            display: block;
            padding: 15px 30px;
            color: #333;
            text-decoration: none;
            font-size: 1.05em;
            border-left: 5px solid transparent;
            transition: background-color 0.3s ease, border-left-color 0.3s ease, color 0.3s ease;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background-color: #e6f0fa;
            border-left-color: #3F0071; /* Accent color to match new header */
            color: #3F0071;
        }

        .sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* General styling for the main content area */
        .statements-content {
            flex-grow: 1; /* Allow content to take remaining space */
            padding: 20px;
            background-color: #FFFFFF; /* Changed to white */
            margin-left: 0; /* No sidebar on this page */
            padding-top: 20px; /* No need for extra padding-top due to fixed header and padding-top on dashboard-container */
        }
        /* Adjusted margin for main content if sidebar is used */
        body.has-sidebar .statements-content {
            margin-left: 250px; /* Offset for fixed sidebar */
        }


        .statements-card {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .statements-card h2 {
            color: #333;
            margin-bottom: 25px;
            font-size: 1.8em;
            border-bottom: 2px solid #3F0071; /* Accent color to match header */
            padding-bottom: 10px;
        }

        /* Styling for monthly grouping */
        .month-group {
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            background-color: #fbfbfb;
        }

        .month-group h3 {
            background-color: #3F0071; /* Changed to dark purple to match header */
            color: white;
            padding: 15px 20px;
            margin: 0;
            font-size: 1.4em;
            border-bottom: 1px solid #000000; /* Darker border for contrast */
            cursor: pointer; /* Indicate it's clickable for toggle */
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .month-group h3 .toggle-icon {
            transition: transform 0.3s ease;
        }

        .month-group h3.collapsed .toggle-icon {
            transform: rotate(-90deg); /* Icon points down when collapsed */
        }

        .transactions-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 0; /* Hidden by default */
            overflow: hidden;
            transition: max-height 0.5s ease-out; /* Smooth collapse/expand */
        }

        .transactions-list.expanded {
            max-height: 1000px; /* Large enough to show all items, adjust if needed */
        }

        .transaction-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .transaction-item-details .description {
            font-weight: bold;
            color: #333;
            font-size: 1.1em;
        }

        .transaction-item-details .date-ref {
            font-size: 0.9em;
            color: #777;
            margin-top: 3px;
        }

        .transaction-item-amount {
            font-size: 1.2em;
            font-weight: bold;
            text-align: right;
            min-width: 120px; /* Ensure space for amounts */
        }

        /* Applying specific colors based on transaction type for amounts */
        .transaction-item-amount.credit {
            color: #28a745; /* Green for credit/deposit */
        }

        .transaction-item-amount.debit {
            color: #dc3545; /* Red for debit and all transfer types */
        }

        .transaction-item-status {
            font-size: 0.85em;
            margin-left: 15px;
            padding: 5px 8px;
            border-radius: 4px;
            color: white;
        }

        .transaction-item-status.Completed {
            background-color: #28a745;
        }
        .transaction-item-status.Pending { /* Added Pending status style */
            background-color: #ffc107; /* Yellow/Orange */
            color: #343a40; /* Darker text for contrast */
        }
        .transaction-item-status.Failed,
        .transaction-item-status.Cancelled { /* Added Failed/Cancelled status style */
            background-color: #dc3545;
        }

        .no-transactions-message {
            text-align: center;
            color: #666;
            padding: 40px 20px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px dashed #ddd;
            margin-top: 20px;
        }

        /* Back to dashboard button styling */
        .back-to-dashboard {
            margin-bottom: 20px;
            text-align: left;
        }

        .back-to-dashboard a {
            display: inline-block;
            background-color: #007bff; /* A standard blue for buttons */
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .back-to-dashboard a:hover {
            background-color: #0056b3;
        }


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                padding: 10px 15px;
            }
            .dashboard-header .logo {
                margin-bottom: 10px;
            }
            .user-info {
                width: 100%;
                justify-content: center;
            }
            .dashboard-container {
                flex-direction: column; /* Stack sidebar and main content */
                padding-top: 120px; /* Adjust for stacked header */
            }
            .sidebar {
                position: relative; /* Make sidebar relative for stacking */
                width: 100%;
                top: auto;
                bottom: auto;
                left: auto;
                box-shadow: none;
                padding-top: 10px;
            }
            .sidebar ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
            }
            .sidebar ul li {
                flex: 1 1 auto;
                text-align: center;
            }
            .sidebar ul li a {
                border-left: none;
                border-bottom: 3px solid transparent;
                padding: 10px 15px;
            }
            .sidebar ul li a:hover,
            .sidebar ul li a.active {
                border-left-color: transparent;
                border-bottom-color: #3F0071;
            }
            .statements-content {
                margin-left: 0; /* No offset for collapsed sidebar */
                padding: 15px;
            }

            .statements-card {
                padding: 15px;
            }

            .month-group h3 {
                font-size: 1.2em;
            }

            .transaction-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .transaction-item-amount {
                margin-top: 10px;
                text-align: left;
                width: 100%;
            }

            .transaction-item-status {
                margin-left: 0;
                margin-top: 10px;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header .logo-barclays {
                height: 30px;
            }
            .user-info span {
                display: none; /* Hide name on very small screens to save space */
            }
            .user-info .profile-icon {
                margin-right: 5px;
            }
            .sidebar ul li a i {
                margin-right: 0;
            }
            .sidebar ul li a span {
                font-size: 0.8em; /* Smaller text for menu items */
            }
            .sidebar ul li a {
                padding: 10px 5px;
            }
            .statements-card h2 {
                font-size: 1.5em;
            }
            .month-group h3 {
                font-size: 1em;
                padding: 10px 15px;
            }
            .transaction-item {
                padding: 10px 15px;
            }
            .transaction-item-details .description {
                font-size: 1em;
            }
            .transaction-item-details .date-ref {
                font-size: 0.8em;
            }
            .transaction-item-amount {
                font-size: 1em;
            }
            .transaction-item-status {
                font-size: 0.75em;
                padding: 3px 6px;
            }
        }
    </style>
</head>
<body class="statements-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/images/logo.png" alt="HomeTown Bank Pa Logo" class="logo-barclays">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="<?php echo BASE_URL; ?>/logout.php">Logout</a> </div>
    </header>

    <div class="dashboard-container">
        <main class="statements-content">
            <div class="statements-card">
                <div class="back-to-dashboard">
                    <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard">
                        <i class="fas fa-home"></i> Back to Dashboard
                    </a>
                </div>

                <h2>Your Transaction History & Statements</h2>

                <?php
                // Display messages (e.g., from failed account fetch or invalid session)
                if (isset($_SESSION['message'])) {
                    $message_type = $_SESSION['message_type'] ?? 'info';
                    echo '<div class="alert ' . htmlspecialchars($message_type) . '">' . htmlspecialchars($_SESSION['message']) . '</div>';
                    unset($_SESSION['message']); // Clear the message after displaying
                    unset($_SESSION['message_type']);
                }
                ?>

                <?php if (empty($grouped_transactions)): ?>
                    <p class="no-transactions-message">No transactions available at this time. Please check back later.</p>
                <?php else: ?>
                    <?php foreach ($grouped_transactions as $period => $transactions): ?>
                        <div class="month-group">
                            <h3 class="collapsed" onclick="toggleTransactions(this)">
                                <?php echo htmlspecialchars($period); ?> Transactions
                                <i class="fas fa-chevron-down toggle-icon"></i>
                            </h3>
                            <ul class="transactions-list">
                                <?php foreach ($transactions as $transaction):
                                    // Determine the amount class and sign based on calculated flow_type
                                    $amount_class = '';
                                    $amount_sign = '';

                                    if ($transaction['flow_type'] == 'credit') {
                                        $amount_class = 'credit';
                                        $amount_sign = '+';
                                    } else { // 'debit' or any other outgoing flow
                                        $amount_class = 'debit';
                                        $amount_sign = '-';
                                    }
                                ?>
                                    <li class="transaction-item">
                                        <div class="transaction-item-details">
                                            <span class="description"><?php echo htmlspecialchars($transaction['description']); ?></span>
                                            <span class="date-ref">
                                                <?php
                                                // Ensure $transaction['initiated_at'] is a string or DateTime object for format()
                                                echo (new DateTime($transaction['initiated_at']))->format('M d, Y H:i');
                                                ?>
                                                (Ref: <?php echo htmlspecialchars($transaction['transaction_reference']); ?>)
                                            </span>
                                        </div>
                                        <span class="transaction-item-amount <?php echo $amount_class; ?>">
                                            <?php echo $amount_sign . $user_currency_symbol . number_format($transaction['amount'], 2); ?>
                                        </span>
                                        <span class="transaction-item-status <?php echo htmlspecialchars(ucfirst($transaction['status'])); ?>">
                                            <?php echo htmlspecialchars(ucfirst($transaction['status'])); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // JavaScript for toggling monthly transaction lists
        function toggleTransactions(header) {
            const list = header.nextElementSibling; // The <ul> element
            const icon = header.querySelector('.toggle-icon');

            header.classList.toggle('collapsed');
            list.classList.toggle('expanded');
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up'); // Change icon direction
        }

        // Expand the most recent month by default on page load
        document.addEventListener('DOMContentLoaded', (event) => {
            const firstMonthGroupHeader = document.querySelector('.month-group h3');
            if (firstMonthGroupHeader) {
                // Ensure it's not already expanded if it was a cached page state
                if (firstMonthGroupHeader.classList.contains('collapsed')) {
                    toggleTransactions(firstMonthGroupHeader);
                }
            }
        });
    </script>
</body>
</html>
