<?php
// Config.php should be included first to ensure session and constants are available.
// NOTE: In the current setup with index.php as a router, Config.php and session_start()
// are handled by index.php before bank_cards.php is included.
// So, the explicit session_start() and require_once __DIR__ . '/../Config.php'; are
// technically redundant here if index.php handles them, but harmless if done carefully.

// For development:
ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Assuming these are included by index.php
// require_once __DIR__ . '/../Config.php';
// require_once __DIR__ . '/../functions.php';

use MongoDB\Client; // May not be needed if $mongoDb is passed down
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
// This check is duplicated with index.php's routing, but harmless as a secondary check.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Use BASE_URL for redirects
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

// Assuming $mongoDb object is available from index.php's scope
global $mongoDb; // Access the global variable set in index.php

if (!$mongoDb) {
    error_log("CRITICAL ERROR: MongoDB connection not available in bank_cards.php. Check index.php.");
    die("<h1>Database connection error. Please try again later.</h1>");
}

$usersCollection = $mongoDb->selectCollection('users');
$accountsCollection = $mongoDb->selectCollection('accounts');
$bankCardsCollection = $mongoDb->selectCollection('bank_cards');

try {
    // Convert user_id from session string to MongoDB ObjectId
    $userObjectId = new ObjectId($user_id);

    // Fetch user's full name and email from the database if not already in session
    // This provides a fallback if session data is somehow lost or incomplete.
    if (empty($user_full_name) || empty($user_email)) {
        $user_doc = $usersCollection->findOne(['_id' => $userObjectId]);
        if ($user_doc) {
            $user_full_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
            $user_email = $user_doc['email'] ?? '';
            // Update session with fetched data for future requests
            $_SESSION['user_full_name'] = $user_full_name;
            $_SESSION['user_email'] = $user_email;
        } else {
            error_log("User with ID " . $user_id . " not found in database for bank_cards.php.");
            $user_full_name = 'Bank Customer'; // Fallback if DB lookup fails
            $user_email = 'default@example.com';
        }
    }
} catch (MongoDBDriverException $e) {
    error_log("MongoDB operation error in bank_cards.php (user data fetch): " . $e->getMessage());
    $message = "Database error fetching user details. Please try again later.";
    $message_type = 'error';
} catch (Exception $e) { // Catch for ObjectId conversion or other general errors
    error_log("General error during initial setup in bank_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred during page setup. Please try again later.";
    $message_type = 'error';
    // If user_id is invalid, it's safer to redirect to login
    header('Location: ' . BASE_URL . '/index.php?error=invalid_user_session'); // Redirect with an error indicator
    exit;
}

// Ensure full name and email are set for display, even if database lookup failed
$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// --- Non-AJAX request, render the HTML page ---
// We need to fetch the accounts for the dropdown in the form
$user_accounts_for_dropdown = [];
try {
    // Re-use $userObjectId from the initial connection block
    $cursor = $accountsCollection->find(
        ['user_id' => $userObjectId],
        ['projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]] // Added balance and currency for display in JS
    );
    foreach ($cursor as $accountDoc) {
        $user_accounts_for_dropdown[] = [
            'id' => (string) $accountDoc['_id'], // Convert ObjectId to string for HTML value
            'account_type' => $accountDoc['account_type'] ?? 'Account',
            'display_account_number' => '****' . substr($accountDoc['account_number'] ?? '', -4),
            'balance' => $accountDoc['balance'] ?? 0.00, // Make sure to fetch balance
            'currency' => $accountDoc['currency'] ?? 'USD' // Make sure to fetch currency
        ];
    }
} catch (MongoDBDriverException $e) {
    error_log("Error fetching accounts for dropdown (MongoDB): " . $e->getMessage());
    $message = "Could not load accounts for linking cards.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("Error processing user ID for accounts dropdown (General): " . $e->getMessage());
    $message = "Error loading user data.";
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Manage Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for cards.html - Now inline in bank_cards.php */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(45deg, #6a11cb, #2575fc); /* Glow purple gradient */
            padding: 20px 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #eee;
        }

        .header .logo img {
            max-height: 45px; /* Reduced logo size */
            width: auto;
        }

        .header h1 {
            color: #ffffff; /* White text for header title */
            font-size: 1.8rem; /* Reduced font size for "Manage My Cards" */
            margin: 0;
            flex-grow: 1;
            text-align: center;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* Back to Dashboard Button Styling */
        .back-to-dashboard {
            display: inline-flex;
            align-items: center;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2); /* Semi-transparent white */
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
        }

        .back-to-dashboard i {
            margin-right: 8px;
        }

        .back-to-dashboard:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .main-content {
            flex-grow: 1;
            padding: 30px 20px;
            max-width: 900px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #e0e0e0;
            width: calc(100% - 40px);
            box-sizing: border-box;
        }

        h2 {
            color: #4a0d93;
            font-size: 2rem;
            margin-bottom: 30px;
            text-align: center;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .cards-section, .order-card-section {
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .cards-section:last-child, .order-card-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .card-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Adjusted min card width for better visuals */
            gap: 25px; /* Adjusted gap */
            margin-top: 25px; /* Adjusted margin */
            justify-content: center; /* Center cards in grid */
        }

        .card-item {
            background: linear-gradient(135deg, #f8f8f8, #e0e0e0);
            border: 1px solid #dcdcdc;
            border-radius: 18px; /* Slightly larger border-radius */
            padding: 25px; /* Adjusted padding */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); /* Adjusted shadow */
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 200px; /* Adjusted min-height */
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }

        /* Specific card network styles for background, if desired */
        .card-item.visa { background: linear-gradient(135deg, #2a4b8d, #3f60a9); color: white;}
        .card-item.mastercard { background: linear-gradient(135deg, #eb001b, #ff5f00); color: white;}
        .card-item.amex { background: linear-gradient(135deg, #0081c7, #26a5d4); color: white;}
        .card-item.verve { background: linear-gradient(135deg, #006633, #009933); color: white;}
        /* Ensure text is readable on dark backgrounds */
        .card-item.visa h4, .card-item.visa .card-number, .card-item.visa .card-footer .label, .card-item.visa .card-footer .value, .card-item.visa .card-status { color: white; }
        .card-item.mastercard h4, .card-item.mastercard .card-number, .card-item.mastercard .card-footer .label, .card-item.mastercard .card-footer .value, .card-item.mastercard .card-status { color: white; }
        .card-item.amex h4, .card-item.amex .card-number, .card-item.amex .card-footer .label, .card-item.amex .card-footer .value, .card-item.amex .card-status { color: white; }
        .card-item.verve h4, .card-item.verve .card-number, .card-item.verve .card-footer .label, .card-item.verve .card-footer .value, .card-item.verve .card-status { color: white; }


        .card-item h4 {
            margin-top: 0;
            font-size: 1.1em;
            color: #333; /* Default text color for card issuer name */
        }
        .card-item.visa h4, .card-item.mastercard h4, .card-item.amex h4, .card-item.verve h4 {
            color: rgba(255,255,255,0.9); /* Lighter color for issuer name on colored cards */
        }

        .card-logo-img { /* This is the actual logo image from the backend */
            position: absolute;
            bottom: 20px;
            right: 20px;
            height: 45px; /* Slightly larger for better visibility */
            width: auto;
            max-width: 80px; /* Ensure it doesn't get too big */
        }

        .card-item .chip {
            width: 50px; /* Standard chip size */
            height: 35px;
            background-color: #d4af37;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .card-number {
            font-family: 'Space Mono', monospace;
            font-size: 1.6em; /* Adjusted font size for impact */
            letter-spacing: 2px;
            margin-bottom: 20px;
            color: #444; /* Default card number color */
            text-align: center;
            word-break: break-all;
        }
        .card-item.visa .card-number, .card-item.mastercard .card-number, .card-item.amex .card-number, .card-item.verve .card-number {
            color: #fff; /* White for card numbers on colored cards */
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 0.9em;
            width: 100%;
        }

        .card-footer .label {
            font-size: 0.7em;
            opacity: 0.7;
            margin-bottom: 3px;
            color: #666; /* Default label color */
        }
        .card-item.visa .card-footer .label, .card-item.mastercard .card-footer .label, .card-item.amex .card-footer .label, .card-item.verve .card-footer .label {
            color: rgba(255,255,255,0.7); /* Lighter label color on colored cards */
        }

        .card-footer .value {
            font-weight: bold;
            color: #333; /* Default value color */
        }
        .card-item.visa .card-footer .value, .card-item.mastercard .card-footer .value, .card-item.amex .card-footer .value, .card-item.verve .card-footer .value {
            color: #fff; /* White value color on colored cards */
        }

        p.card-cvv-mock { /* Styling for the CVV mock text */
            font-size: 0.8em;
            text-align: center;
            margin-top: 10px;
            color: #888;
        }
        .card-item.visa p.card-cvv-mock, .card-item.mastercard p.card-cvv-mock, .card-item.amex p.card-cvv-mock, .card-item.verve p.card-cvv-mock {
            color: rgba(255,255,255,0.7); /* Lighter CVV text on colored cards */
        }

        .card-status {
            font-size: 0.9em;
            text-align: right;
            margin-top: 10px;
            opacity: 0.9;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block; /* Allows padding to apply correctly */
        }
        .card-status.active { background-color: #d4edda; color: #155724; }
        .card-status.inactive { background-color: #f8d7da; color: #721c24; }
        /* For colored cards, status text should also be white */
        .card-item.visa .card-status, .card-item.mastercard .card-status, .card-item.amex .card-status, .card-item.verve .card-status {
            color: white; /* Make status text white on dark cards */
        }
        .card-item.visa .card-status.active { background-color: rgba(100, 255, 100, 0.3); border: 1px solid rgba(100, 255, 100, 0.5); }
        .card-item.visa .card-status.inactive { background-color: rgba(255, 100, 100, 0.3); border: 1px solid rgba(255, 100, 100, 0.5); }
        /* Apply similar rgba backgrounds for other colored cards if needed, or keep original if current contrast is good */
        .card-item.mastercard .card-status.active, .card-item.amex .card-status.active, .card-item.verve .card-status.active {
            background-color: rgba(100, 255, 100, 0.3); border: 1px solid rgba(100, 255, 100, 0.5);
        }
        .card-item.mastercard .card-status.inactive, .card-item.amex .card-status.inactive, .card-item.verve .card-status.inactive {
            background-color: rgba(255, 100, 100, 0.3); border: 1px solid rgba(255, 100, 100, 0.5);
        }

        /* Order New Card Section - Form and button styling */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #444; font-size: 0.95rem; }
        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd0d5;
            border-radius: 8px;
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: #fcfcfc;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #6a11cb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(106, 17, 203, 0.2);
            background-color: #ffffff;
        }

        .submit-button {
            padding: 14px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(45deg, #2ecc71, #27ae60);
            color: #ffffff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            width: auto;
            min-width: 220px;
            margin-top: 20px; /* Added margin for separation */
        }

        .submit-button:hover {
            background: linear-gradient(45deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(46, 204, 113, 0.4);
        }

        /* Loading and No Data Messages */
        .loading-message, .no-data-message {
            text-align: center;
            font-style: italic;
            color: #666;
            margin: 20px 0;
            font-size: 1.0rem;
        }

        .loading-message i {
            margin-right: 8px;
        }

        /* Custom Message Box */
        .message-box-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .message-box-overlay.show { /* Changed to 'show' as per cards.js */
            opacity: 1;
            visibility: visible;
        }

        .message-box-content {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            border: 1px solid #eee;
        }

        .message-box-overlay.show .message-box-content { /* Changed to 'show' as per cards.js */
            transform: translateY(0);
        }

        .message-box-content p {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 25px;
        }

        .message-box-content button {
            background: linear-gradient(45deg, #2575fc, #6a11cb);
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.0rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .message-box-content button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px 20px;
                align-items: center;
            }
            .header .logo {
                margin-bottom: 10px;
            }
            .header h1 {
                font-size: 1.5rem; /* Further reduced for smaller screens */
                text-align: center;
                margin-top: 10px;
            }
            .header-nav {
                width: 100%;
                text-align: center;
                margin-top: 15px;
            }
            .back-to-dashboard {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            .main-content {
                margin: 20px 10px;
                padding: 20px;
                border-radius: 12px;
                width: calc(100% - 20px);
            }
            h2 {
                font-size: 1.8rem;
                margin-bottom: 25px;
            }
            .card-list {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .card-item {
                min-height: auto;
                padding: 20px;
            }
            .card-logo-img {
                height: 40px;
                bottom: 15px;
                right: 15px;
            }
            .card-item h4 {
                font-size: 1em;
            }
            .card-item .chip {
                width: 45px;
                height: 30px;
                margin-bottom: 15px;
            }
            .card-number {
                font-size: 1.4rem;
                letter-spacing: 1.5px;
                margin-bottom: 15px;
            }
            .card-footer {
                font-size: 0.85em;
            }
            p.card-cvv-mock {
                font-size: 0.75em;
            }
            .card-status {
                font-size: 0.8em;
                padding: 4px 8px;
            }

            .order-card-section input,
            .order-card-section select,
            .order-card-section textarea {
                padding: 10px;
                font-size: 0.9rem;
            }
            .order-card-section .submit-button {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
            .message-box-content {
                max-width: 350px;
                padding: 25px;
            }
            .message-box-content p {
                font-size: 1rem;
            }
            .message-box-content button {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
        }

        /* Specific styles for very small mobile devices (e.g., iPhone SE) */
        @media (max-width: 400px) {
            .header h1 {
                font-size: 1.3rem;
            }
            .main-content {
                margin: 15px 5px;
                padding: 15px;
                width: calc(100% - 10px);
            }
            h2 {
                font-size: 1.6rem;
                margin-bottom: 20px;
            }
            .card-item {
                padding: 15px;
            }
            .card-logo-img {
                height: 35px;
            }
            .card-item .chip {
                width: 40px;
                height: 25px;
            }
            .card-number {
                font-size: 1.2rem;
            }
            .card-footer {
                font-size: 0.8em;
            }
            p.card-cvv-mock {
                font-size: 0.7em;
            }
            .card-status {
                font-size: 0.75em;
            }
            .order-card-section label {
                font-size: 0.85rem;
            }
            .order-card-section input,
            .order-card-section select,
            .order-card-section textarea {
                padding: 8px;
                font-size: 0.85rem;
            }
            .order-card-section .submit-button {
                padding: 10px 12px;
                font-size: 0.85rem;
            }
            .message-box-content {
                max-width: 280px;
                padding: 15px;
            }
            .message-box-content p {
                font-size: 0.9rem;
            }
            .message-box-content button {
                padding: 6px 15px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <nav class="header-nav">
            <a href="<?php echo BASE_URL; ?>/dashboard" class="back-to-dashboard">
                <i class="fas fa-arrow-circle-left"></i> Back to Dashboard
            </a>
        </nav>
        <h1>Manage My Cards</h1>
        <div class="logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
        </div>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <section class="cards-section">
            <h2>Your Current Cards</h2>
            <p id="cardsLoadingMessage" class="loading-message">
                <i class="fas fa-spinner fa-spin"></i> Loading your cards...
            </p>
            <div id="userCardList" class="card-list">
                <p class="no-data-message" id="noCardsMessage" style="display:none;">No bank cards found. Order a new one below!</p>
            </div>
        </section>

        <section class="order-card-section">
            <h2>Order a New Card</h2>
            <form id="orderCardForm">
                <div class="form-group">
                    <label for="cardHolderName">Card Holder Name:</label>
                    <input type="text" id="cardHolderName" name="cardHolderName" value="<?= htmlspecialchars($user_full_name) ?>" required readonly>
                </div>

                <div class="form-group">
                    <label for="cardType">Card Type:</label>
                    <select id="cardType" name="cardType" required>
                        <option value="">Select Card Type</option>
                        <option value="Debit">Debit Card</option>
                        <option value="Credit">Credit Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cardNetwork">Card Network:</label>
                    <select id="cardNetwork" name="cardNetwork" required>
                        <option value="">Select Card Network</option>
                        <option value="Visa">Visa</option>
                        <option value="Mastercard">Mastercard</option>
                        <option value="Amex">American Express</option>
                        <option value="Verve">Verve</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="accountId">Link to Account:</label>
                    <select id="accountId" name="accountId" required>
                        <option value="">-- Select an Account --</option>
                        <?php foreach ($user_accounts_for_dropdown as $account): ?>
                            <option value="<?php echo htmlspecialchars($account['id']); ?>">
                                <?php echo htmlspecialchars($account['account_type'] . ' (' . $account['display_account_number'] . ') - ' . $account['currency'] . sprintf('%.2f', $account['balance'])); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($user_accounts_for_dropdown)): ?>
                            <option value="" disabled>No accounts available to link</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="shippingAddress">Shipping Address:</label>
                    <textarea id="shippingAddress" name="shippingAddress" placeholder="Your full shipping address" rows="3" required></textarea>
                </div>

                <button type="submit" class="submit-button">
                    <i class="fas fa-credit-card" style="margin-right: 8px;"></i> Place Card Order
                </button>
            </form>
        </section>

        <section class="manage-pin-section">
            <h2>Manage Card PIN & Activation</h2>
            <p>To activate a new card or set/change your existing card's PIN, please visit the <a href="<?php echo BASE_URL; ?>/my_cards">Card Activation & PIN Management page</a>.</p>
        </section>
    </main>

    <div class="message-box-overlay" id="messageBoxOverlay">
        <div class="message-box-content" id="messageBoxContentWrapper">
            <p id="messageBoxContent"></p>
            <button id="messageBoxButton">OK</button>
        </div>
    </div>
    <script>
        // These variables must be defined before cards.js is loaded
        const PHP_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        // Assuming 'frontend' is directly under your BASE_URL for frontend assets
        const FRONTEND_BASE_URL = <?php echo json_encode(BASE_URL . '/frontend/'); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const currentUserEmail = <?php echo json_encode($user_email); ?>; // Add current user email
    </script>
    <script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>