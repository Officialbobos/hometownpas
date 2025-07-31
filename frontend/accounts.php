<?php
// Path: C:\xampp\htdocs\hometownbank\accounts.php

session_start(); // Keep session_start() at the very top of each script that uses sessions.

// NO ini_set('display_errors', ...) or error_reporting() here.
// Config.php will handle error display based on APP_DEBUG.

// 1. Load Composer's autoloader FIRST. This makes Dotenv and MongoDB classes available.
// This is required before Config.php if Config.php uses Composer-loaded classes (like Dotenv).
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Now load your Config.php. This file now handles Dotenv loading and error reporting settings.
// This ensures that all constants like BASE_URL, MONGODB_CONNECTION_URI, and APP_DEBUG are defined.
require_once __DIR__ . '/../Config.php';

// 3. Load your functions.php
require_once __DIR__ . '/../functions.php'; // If you have a sanitize_input function here

use MongoDB\Client; // Make sure this is imported if using new MongoDB\Client
use MongoDB\BSON\ObjectId; // For converting string IDs to MongoDB ObjectIds

// Check if the user is logged in. If not, redirect to login page.
// *** CORRECTION: Use $_SESSION['logged_in'] for consistency with dashboard.php ***
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    // Corrected: Use BASE_URL for redirect to login page
    // Assuming index.php is your login page. Adjust if your login page is e.g., login.php
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = ''; // Initialize, will be fetched from DB
$user_email = ''; // Initialize, will be fetched from DB
$user_accounts = []; // Array to store user's accounts

// --- MongoDB Connection ---
$client = null;
$database = null;
$usersCollection = null;
$accountsCollection = null;

try {
    // OPTION 1: Using the helper function (recommended for consistency)
    $client = getMongoDBClient(); // Assuming getMongoDBClient() exists in functions.php and returns a MongoDB\Client instance

    // OPTION 2: Direct connection (if you prefer, but less consistent with make_transfer.php)
    // $client = new MongoDB\Client(MONGODB_CONNECTION_URI);

    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error in accounts.php: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later. (Code: MDB_CONN_FAIL)");
}

// Fetch user's name and email for display in header/sidebar
try {
    // Convert session user_id to MongoDB\BSON\ObjectId for database query
    $userObjectId = new ObjectId($user_id);
    $user_doc = $usersCollection->findOne(
        ['_id' => $userObjectId],
        ['projection' => ['first_name' => 1, 'last_name' => 1, 'username' => 1, 'email' => 1]]
    );

    if ($user_doc) {
        $first_name = $user_doc['first_name'] ?? '';
        $last_name = $user_doc['last_name'] ?? '';
        $user_email = $user_doc['email'] ?? '';
        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) { // Fallback to username if first/last name are empty
            $full_name = $user_doc['username'] ?? 'User';
        }
        // Update session with more complete name/email if available from DB
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['email'] = $user_email;
    } else {
        // Fallback if user data not found in DB (should ideally not happen if user_id is valid)
        $full_name = $_SESSION['username'] ?? 'User';
        $user_email = $_SESSION['email'] ?? $_SESSION['temp_user_email'] ?? ''; // Prioritize 'email', then 'temp_user_email'
        error_log("User with ID " . $user_id . " not found in database when fetching profile details for accounts.php.");
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB user fetch error in accounts.php: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback to session username
    $user_email = $_SESSION['email'] ?? $_SESSION['temp_user_email'] ?? '';
} catch (Exception $e) { // Catch for potential ObjectId conversion error if $user_id is malformed
    error_log("Invalid user ID format in accounts.php: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback
    $user_email = $_SESSION['email'] ?? $_SESSION['temp_user_email'] ?? '';
}


// Fetch user's accounts
try {
    $cursor = $accountsCollection->find(
        ['user_id' => $userObjectId], // Query for accounts belonging to the user
        [
            'sort' => ['account_type' => 1, 'account_number' => 1] // Sort by type then number
        ]
    );

    foreach ($cursor as $accountDoc) {
        $user_accounts[] = (array) $accountDoc; // Convert BSON document to PHP array
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB accounts fetch error in accounts.php: " . $e->getMessage());
    // Optionally display an error message to the user, e.g.:
    $_SESSION['message'] = "Failed to load your accounts. Please try again later.";
    $_SESSION['message_type'] = "error";
    // No redirect here, just show a message on the current page.
}

// Helper to format currency (moved here to ensure it's defined only once if functions.php doesn't have it)
// It's generally better to define these in functions.php and then include functions.php AFTER Config.php
// if they rely on Config.php constants. If they are truly standalone, their location is fine.
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $currency_code) {
        $symbol = '';
        switch (strtoupper($currency_code)) {
            case 'GBP': $symbol = '£'; break;
            case 'USD': $symbol = '$'; break;
            case 'EUR': $symbol = '€'; break;
            case 'NGN': $symbol = '₦'; break; // Added NGN symbol explicitly
            default: $symbol = ''; // Default to no symbol if unknown
        }
        return $symbol . number_format($amount, 2);
    }
}

// Helper to get currency symbol (used in the new section) - Redundant if formatCurrency handles it, but kept for explicit use if needed elsewhere.
if (!function_exists('get_currency_symbol')) {
    function get_currency_symbol($currency_code) {
        switch (strtoupper($currency_code)) {
            case 'GBP': return '£';
            case 'USD': return '$';
            case 'EUR': return '€';
            case 'NGN': return '₦';
            default: return '';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accounts - HomeTown Bank Pa</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/accounts.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <style>
        /* This style block should ideally be moved to accounts.css */
        .back-to-dashboard {
            margin-bottom: 20px;
            text-align: left; /* Align the button to the left */
        }

        .back-to-dashboard a {
            display: inline-block;
            background-color: #007bff;
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

        /* Added style for balance amount font size */
        .balance-amount {
            font-size: 1.2em; /* Adjust as needed, 'em' is relative to parent font size */
            font-weight: bold;
        }
    </style>
</head>
<body class="dashboard-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/images/logo.png" alt="HomeTown Bank Pa Logo">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="<?php echo BASE_URL; ?>/logout">Logout</a>
        </div>
    </header>
    <main class="accounts-content">
        <?php
        // Display messages (e.g., from failed account fetch)
        if (isset($_SESSION['message'])) {
            $message_type = $_SESSION['message_type'] ?? 'info';
            echo '<div class="alert ' . htmlspecialchars($message_type) . '">' . htmlspecialchars($_SESSION['message']) . '</div>';
            unset($_SESSION['message']); // Clear the message after displaying
            unset($_SESSION['message_type']);
        }
        ?>
        <div class="card">
            <div class="back-to-dashboard">
        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="contact-button homepage">
        <i class="fas fa-home"></i> Back to Dashboard
                </a>
            </div>

            <h2>Your Bank Accounts</h2>

            <?php if (!empty($user_accounts)): ?>
                <div class="account-card-container"> <?php foreach ($user_accounts as $account): ?>
                        <div class="account-card">
                            <h3>
                                <?php
                                    switch (strtolower($account['account_type'] ?? '')) {
                                        case 'checking':
                                            echo '<i class="fas fa-money-check-alt"></i>';
                                            break;
                                        case 'savings':
                                            echo '<i class="fas fa-piggy-bank"></i>';
                                            break;
                                        case 'current':
                                            echo '<i class="fas fa-hand-holding-usd"></i>';
                                            break;
                                        default:
                                            echo '<i class="fas fa-wallet"></i>';
                                            break;
                                    }
                                ?>
                                <?php echo htmlspecialchars(ucwords($account['account_type'] ?? 'N/A')) . ' Account'; ?>
                            </h3>
                            <p><span class="detail-label">Account Number:</span> <span class="detail-value"><?php echo htmlspecialchars($account['account_number'] ?? 'N/A'); ?></span></p>
                            <p><span class="detail-label">Currency:</span> <span class="detail-value"><?php echo htmlspecialchars(strtoupper($account['currency'] ?? 'N/A')); ?></span></p>

                            <?php if (!empty($account['sort_code'])): ?>
                                <p><span class="detail-label">Sort Code:</span> <span class="detail-value"><?php echo htmlspecialchars($account['sort_code']); ?></span></p>
                            <?php endif; ?>

                            <?php if (!empty($account['iban'])): ?>
                                <p><span class="detail-label">IBAN:</span> <span class="detail-value"><?php echo htmlspecialchars($account['iban']); ?></span></p>
                            <?php endif; ?>

                            <?php if (!empty($account['swift_bic'])): ?>
                                <p><span class="detail-label">SWIFT/BIC:</span> <span class="detail-value"><?php echo htmlspecialchars($account['swift_bic']); ?></span></p>
                            <?php endif; ?>

                            <?php if (!empty($account['routing_number'])): // Added for Routing Number ?>
                                <p><span class="detail-label">Routing Number:</span> <span class="detail-value"><?php echo htmlspecialchars($account['routing_number']); ?></span></p>
                            <?php endif; ?>

                            <p class="balance">
                                <span class="balance-label">Current Balance:</span><br>
                                <span class="balance-amount"><?php echo formatCurrency($account['balance'] ?? 0, $account['currency'] ?? 'USD'); ?></span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-accounts">You currently have no bank accounts linked to your profile.</p>
            <?php endif; ?>
        </div>
    </main>
    </div>
</body>
</html>
