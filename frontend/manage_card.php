<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\bank_cards.php

// For development:
ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure session is started and Config/functions are available.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Define BASE_URL if not already defined (e.g., from Config.php)
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/hometownbank'); // Adjust as per your actual setup
}

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] != true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Or wherever your login page is
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

global $mongoDb; // Access the global variable set in index.php

if (!$mongoDb) {
    error_log("CRITICAL ERROR: MongoDB connection not available in bank_cards.php. Check index.php or getMongoDBClient().");
    die("<h1>Database connection error. Please try again later.</h1>");
}

$usersCollection = $mongoDb->selectCollection('users');
$accountsCollection = $mongoDb->selectCollection('accounts');
$bankCardsCollection = $mongoDb->selectCollection('bank_cards');

$userObjectId = null; // Initialize to null

try {
    // Convert user_id from session string to MongoDB ObjectId
    $userObjectId = new ObjectId($user_id);

    // Fetch user's full name and email from the database if not already in session
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
    if ($userObjectId) {
        $cursor = $accountsCollection->find(
            ['user_id' => $userObjectId],
            ['projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]]
        );
        foreach ($cursor as $accountDoc) {
            $user_accounts_for_dropdown[] = [
                'id' => (string) $accountDoc['_id'], // Convert ObjectId to string for HTML value
                'account_type' => $accountDoc['account_type'] ?? 'Account',
                'display_account_number' => '****' . substr($accountDoc['account_number'] ?? '', -4),
                'balance' => $accountDoc['balance'] ?? 0.00,
                'currency' => $accountDoc['currency'] ?? 'USD'
            ];
        }
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
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/bank_cards.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* Basic styles for the card container and individual cards */
        .cards-section {
            margin-top: 40px;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .cards-section h2 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 2em;
        }
        .card-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); /* Adjust minmax as needed */
            gap: 30px;
            justify-content: center;
            padding: 20px;
        }

        .bank-card-display {
            position: relative;
            background: linear-gradient(135deg, #004494, #0056b3); /* Example: Hometown Bank Blue */
            border-radius: 15px;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            color: #fff;
            padding: 20px 25px;
            aspect-ratio: 1.585 / 1; /* Standard credit card aspect ratio */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'Space Mono', monospace;
            overflow: hidden;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            cursor: pointer;
            text-decoration: none; /* For the anchor tag wrapping the card */
            box-sizing: border-box; /* Include padding in element's total width and height */
        }

        .bank-card-display:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.3);
        }

        /* Subtle overlay for visual depth */
        .bank-card-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 15px;
            z-index: 1;
        }

        .bank-card-display > * {
            z-index: 2; /* Ensure content is above the overlay */
        }

        .card-header-logo {
            font-size: 1.2rem;
            font-weight: 700;
            text-align: right;
            margin-bottom: 10px;
        }

        .card-network-logo {
            position: absolute; /* Position relative to .bank-card-display */
            top: 20px;
            left: 25px;
            width: 70px; /* Adjust size as needed */
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 5px rgba(0,0,0,0.3)); /* Add subtle shadow to logo */
        }

        .card-chip {
            width: 50px;
            height: 40px;
            background-color: #d4af37; /* Gold color for chip */
            border-radius: 6px;
            position: absolute;
            top: 90px; /* Adjust vertically */
            left: 25px;
            box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.3);
        }

        .card-number {
            font-size: 1.8em;
            letter-spacing: 0.15em;
            text-align: center;
            margin-top: auto; /* Push it to the bottom-middle */
            margin-bottom: 15px;
            word-break: break-all; /* Ensure long numbers wrap if necessary */
        }

        .card-details-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            width: 100%;
            font-size: 0.85em;
        }
        
        .card-details-group {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .card-details-group.right {
            text-align: right;
        }
        .card-details-label {
            font-size: 0.7em;
            opacity: 0.8;
            margin-bottom: 2px;
        }
        .card-status {
            position: absolute;
            bottom: 20px;
            right: 25px;
            font-size: 0.9em;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 10px;
            border-radius: 5px;
            z-index: 2;
        }
        .card-status.active {
            background-color: rgba(60, 179, 113, 0.8); /* MediumSeaGreen */
            color: white;
        }
        .card-status.inactive {
            background-color: rgba(255, 99, 71, 0.8); /* Tomato */
            color: white;
        }
        .card-status.lost-stolen {
            background-color: rgba(220, 20, 60, 0.8); /* Crimson */
            color: white;
        }

        .loading-message, .no-data-message {
            text-align: center;
            padding: 20px;
            font-size: 1.1em;
            color: #555;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        .loading-message .fa-spinner {
            margin-right: 10px;
        }

        @media (max-width: 768px) {
            .card-list {
                grid-template-columns: 1fr; /* Stack cards vertically on smaller screens */
            }
            .bank-card-display {
                max-width: 350px; /* Limit width for single column display */
                margin: 0 auto; /* Center individual cards */
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="header-nav">
            <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="contact-button homepage">
                <i class="fas fa-home"></i> Back to Dashboard
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

        <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

        <section class="order-card-section">
            <h2>Order a Card</h2>
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
            <p>To activate a new card or set/change your existing card's PIN, please visit the <a href="<?php echo rtrim(BASE_URL, '/'); ?>/my_cards">Card Activation & PIN Management page</a>.</p>
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
        const PHP_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
        const FRONTEND_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/frontend'); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const currentUserEmail = <?php echo json_encode($user_email); ?>;
    </script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/cards.js"></script>
</body>
</html>