<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\bank_cards.php

// For development:
ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure session is started and Config/functions are available.
// In a production setup with index.php as a router, these might be handled globally.
// We'll keep them here for standalone testing flexibility, but comment out if index.php handles them.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// --- IMPORTANT: Ensure these are loaded if not handled by a global router (e.g., index.php) ---
// If your index.php or a central file loads Config.php and functions.php, keep these commented.
// If this file is accessed directly and Config.php/functions.php are not loaded globally, UNCOMMENT THEM.
// For this example, I'll assume they are loaded globally as BASE_URL and MongoDB constants are used.
// require_once __DIR__ . '/../Config.php';
// require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // For MongoDB classes, if not loaded globally

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
// Ensure BASE_URL is defined if Config.php is not globally loaded here.
if (!defined('BASE_URL')) {
    // Fallback if Config.php isn't loaded (e.g., for direct file access during testing)
    // In a production app with a router, BASE_URL should always be defined.
    // For now, let's assume it's defined globally, otherwise, include Config.php.
    // If you are absolutely sure Config.php is loaded globally, you can remove this block.
    // Otherwise, uncomment the require_once lines above.
    echo "<h1>Configuration error: BASE_URL is not defined.</h1>";
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Or wherever your login page is
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

// --- EXISTING: Check for general session messages ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'] ?? 'info'; // Default to info if type not set
    // Clear the session messages after displaying them
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- NEW ADDITION: Check for card modal session messages set by user.dashboard.js ---
$show_card_modal_from_session = false;
$card_modal_message_from_session = '';

// *** CORRECTED SESSION VARIABLE NAMES TO MATCH set_session_for_card_modal.php ***
if (isset($_SESSION['card_modal_message_for_display'])) {
    $card_modal_message_from_session = $_SESSION['card_modal_message_for_display'];
    unset($_SESSION['card_modal_message_for_display']); // Clear it after reading
}
if (isset($_SESSION['display_card_modal_on_bank_cards'])) {
    $show_card_modal_from_session = (bool)$_SESSION['display_card_modal_on_bank_cards'];
    unset($_SESSION['display_card_modal_on_bank_cards']); // Clear it after reading
}
// --- END NEW ADDITION ---

// Assuming $mongoDb object is available from index.php's scope
// If not, uncomment and initialize here:
// $mongoDb = getMongoDBClient(); // Assuming getMongoDBClient() is in functions.php
global $mongoDb; // Access the global variable set in index.php

if (!$mongoDb) {
    // If getMongoDBClient() or a global definition is missing
    // or if the connection failed to be established in index.php
    error_log("CRITICAL ERROR: MongoDB connection not available in bank_cards.php. Check index.php or getMongoDBClient().");
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

    <main class="main-content">
        <?php
        // This PHP block is for displaying messages directly on the page,
        // it's distinct from the modal message box.
        if (!empty($message) && !isset($_SESSION['message_displayed'])): // Only display once
            $_SESSION['message_displayed'] = true; // Set a flag to prevent re-display on refresh
        ?>
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
    <script>
        // These variables must be defined before cards.js is loaded
        const PHP_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/'); ?>; // *** ADDED '/' to ensure API calls are correct ***
        // Assuming 'frontend' is directly under your BASE_URL for frontend assets
        const FRONTEND_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/frontend'); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const currentUserEmail = <?php echo json_encode($user_email); ?>;

        // --- Pass PHP messages to JavaScript ---
        const initialMessage = <?php echo json_encode($message); ?>;
        const initialMessageType = <?php echo json_encode($message_type); ?>;

        // --- NEW ADDITION: Pass card modal messages from session to JavaScript ---
        // These now correctly read the session variables set by set_session_for_card_modal.php
        const initialCardModalMessage = <?php echo json_encode($card_modal_message_from_session); ?>;
        const initialShowCardModal = <?php echo json_encode($show_card_modal_from_session); ?>;
        // --- END NEW ADDITION ---
    </script>
        <script src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/cards.js"></script>
</body>
</html>
