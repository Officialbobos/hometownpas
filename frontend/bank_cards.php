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
require_once __DIR__ . '/../Config.php'; // Ensure Config.php is loaded for BASE_URL and MongoDB constants
require_once __DIR__ . '/../functions.php'; // For getMongoDBClient()

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php'); // Changed to login.php for consistency
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

// Assuming getMongoDBClient() is defined in functions.php and returns a MongoDB\Client instance
try {
    $client = getMongoDBClient();
    $db = $client->selectDatabase(MONGODB_DB_NAME); // Use constant from Config.php
    $usersCollection = $db->selectCollection('users');
    $accountsCollection = $db->selectCollection('accounts');
    $bankCardsCollection = $db->selectCollection('bank_cards');
} catch (Exception $e) {
    error_log("CRITICAL ERROR: MongoDB connection not available in bank_cards.php: " . $e->getMessage());
    die("<h1>Database connection error. Please try again later.</h1>");
}

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
    header('Location: ' . BASE_URL . '/login.php?error=invalid_user_session'); // Redirect with an error indicator
    exit;
}

// Ensure full name and email are set for display, even if database lookup failed
$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// --- NEW LOGIC FOR ADMIN MODAL MESSAGE ---
$showCustomModal = false;
$modalMessageContent = '';

if (!empty($user_id)) {
    try {
        $currentUser = $usersCollection->findOne(['_id' => new ObjectId($user_id)]);
        if ($currentUser) {
            $showCustomModal = $currentUser['show_bank_cards_modal'] ?? false; // Assuming a field 'show_bank_cards_modal'
            $modalMessageContent = $currentUser['bank_cards_modal_message'] ?? ''; // Assuming a field 'bank_cards_modal_message'
        }
    } catch (Exception $e) {
        error_log("Error fetching user modal message in bank_cards.php: " . $e->getMessage());
        $showCustomModal = false;
        $modalMessageContent = '';
    }
}
// --- END NEW LOGIC ---

// --- Non-AJAX request, render the HTML page ---
// We need to fetch the accounts for the dropdown in the form
$user_accounts_for_dropdown = [];
try {
    // Re-use $userObjectId from the initial connection block
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
        /* --- NEW CSS FOR CUSTOM TRANSFER MODAL (copied from transfer.php) --- */
        .custom-modal-overlay {
            display: none; /* Hidden by default */
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex; /* Use flexbox for centering */
            align-items: center; /* Center vertically */
            justify-content: center; /* Center horizontally */
        }

        .custom-modal-content {
            background-color: #ffffff;
            padding: 30px;
            border: 1px solid #888;
            width: 90%;
            max-width: 500px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            font-family: 'Poppins', sans-serif; /* Keep Poppins or adjust to Space Mono if preferred for modals */
            position: relative;
        }

        .custom-modal-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .caution-icon {
            font-size: 2em;
            color: #ff9800; /* A strong caution color */
            margin-right: 15px;
        }

        .custom-modal-header h2 {
            color: #4B0082; /* Deep purple */
            margin: 0;
            font-weight: 700;
        }

        .custom-modal-body p {
            font-size: 1.1em;
            line-height: 1.6;
            color: #333;
        }

        .custom-modal-footer {
            margin-top: 25px;
        }

        .custom-modal-footer .btn-purple {
            background-color: #4B0082; /* Deep purple */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-size: 1em;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .custom-modal-footer .btn-purple:hover {
            background-color: #6A0DAD; /* Lighter purple on hover */
        }
        /* --- END NEW CSS --- */
    </style>
</head>
<body>
    <header class="header">
        <nav class="header-nav">
        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="contact-button homepage">
                <i class="fas fa-home"></i> Back to Dashboard </a>
        </nav>
        <h1>Manage My Cards</h1>
        <div class="logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
        </div>
    </header>

    <main class="main-content" id="mainContent">
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

        <section class="card-management-actions-section">
            <h2>Card Actions</h2>
            <p>Select a card to perform actions like freezing, reporting, or setting its PIN.</p>
            <div class="form-group">
                <label for="actionCardSelect">Select Card:</label>
                <select id="actionCardSelect" name="actionCardSelect">
                    <option value="">-- Loading Cards --</option>
                    </select>
            </div>
            <div class="action-buttons-group">
                <button id="freezeActionButton" class="action-button primary-action" disabled><i class="fas fa-snowflake"></i> Freeze/Unfreeze Card</button>
                <button id="reportActionButton" class="action-button danger-action" disabled><i class="fas fa-exclamation-triangle"></i> Report Card</button>
                <button id="setPinActionButton" class="action-button secondary-action" disabled><i class="fas fa-key"></i> Set Card PIN</button>
            </div>
        </section>

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

    <div id="bankCardsCustomModal" class="custom-modal-overlay">
        <div class="custom-modal-content">
            <div class="custom-modal-header">
                <span class="caution-icon">&#9888;</span> <h2>Important Notification</h2>
            </div>
            <div class="custom-modal-body">
                <p><?php echo htmlspecialchars($modalMessageContent); ?></p>
            </div>
            <div class="custom-modal-footer">
                <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="btn-purple">Understood</a>
            </div>
        </div>
    </div>
    <script>
        // These variables must be defined before cards.js is loaded
        const PHP_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/'); ?>;
        const FRONTEND_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/frontend'); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const currentUserEmail = <?php echo json_encode($user_email); ?>;

        // --- NEW DATA FOR CUSTOM MODAL ---
        const showBankCardsCustomModal = <?php echo $showCustomModal ? 'true' : 'false'; ?>;
        // --- END NEW DATA ---
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var customModal = document.getElementById('bankCardsCustomModal');
            var mainContent = document.getElementById('mainContent'); // Target the main content to hide it

            // Check if the custom modal should be displayed
            if (showBankCardsCustomModal) {
                // Display the custom modal
                customModal.style.display = 'flex'; // Use flex for centering

                // Hide the main content so the user can't interact with it
                if (mainContent) {
                    mainContent.style.display = 'none';
                }
            } else {
                // If the custom modal is not needed, make sure the main content is visible
                if (mainContent) {
                    mainContent.style.display = 'block'; // Or 'flex' depending on your layout
                }
            }
        });
    </script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/cards.js"></script>
</body>
</html>
