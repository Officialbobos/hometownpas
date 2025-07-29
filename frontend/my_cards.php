<?php
// frontend/my_cards.php
session_start();
// Adjust path based on your directory structure relative to this file
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For getMongoDBClient() and other helpers

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id']) || !$_SESSION['user_id']) { // Also check for empty user_id
    header('Location: ' . BASE_URL . '/login'); // Redirect to login route
    exit;
}

$message = ''; // For PHP-generated messages (e.g., initial DB error)
$message_type = '';
$userId = $_SESSION['user_id']; // Get user ID from session

// Initialize variables for JavaScript
$userFullName = 'CARD HOLDER'; // Default fallback
$userEmail = ''; // Default fallback

$mongoClient = null;
$mongoDb = null;
try {
    $mongoClient = getMongoDBClient(); // Use your helper function to get MongoDB client
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    // Fetch user's full name and email for initial display and JavaScript
    $usersCollection = $mongoDb->selectCollection('users');
    $userObjectId = new ObjectId($userId); // Convert to ObjectId
    $userData = $usersCollection->findOne(['_id' => $userObjectId], ['projection' => ['first_name' => 1, 'last_name' => 1, 'email' => 1]]);

    if ($userData) {
        $userFullName = strtoupper(trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')));
        $userEmail = $userData['email'] ?? '';
    } else {
        // Log if user not found, but allow page to load with defaults
        error_log("User with ID " . $userId . " not found in database for my_cards.php.");
    }

} catch (MongoDBDriverException $e) {
    error_log("MongoDB connection or operation error in my_cards.php: " . $e->getMessage());
    $message = "Database connection error. Please try again later.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("General error in my_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again later.";
    $message_type = 'error';
    // If user_id is invalid, it's safer to redirect to login
    header('Location: ' . BASE_URL . '/login?error=invalid_session');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bank Cards - HomeTown Bank</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/my_cards.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="user-dashboard-container">
        <div class="user-header">
            <img src="<?php echo BASE_URL; ?>/images/hometown_bank_logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>My Bank Cards</h2>
            <a href="<?php echo BASE_URL; ?>/logout" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h2 class="section-header">Your Active Bank Cards</h2>
            <?php if (!empty($message)): // Display PHP-generated messages ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <p id="cardsLoadingMessage" style="text-align: center;">
                <i class="fas fa-spinner fa-spin"></i> Loading your cards...
            </p>
            <p id="noCardsMessage" style="text-align: center; display: none;">You don't have any active bank cards yet. Order one below!</p>

            <div id="userCardList" class="card-container">
                </div>

            <p style="text-align: center; margin-top: 30px;">
                <a href="<?php echo BASE_URL; ?>/dashboard" class="back-link">&larr; Back to Dashboard</a>
            </p>

            <div class="order-card-section">
                <h3 class="section-header">Order a New Bank Card</h3>
                <form id="orderCardForm" class="order-card-form">
                    <div class="form-group">
                        <label for="cardHolderName">Card Holder Name:</label>
                        <input type="text" id="cardHolderName" name="cardHolderName" value="<?= htmlspecialchars($userFullName) ?>" required readonly>
                    </div>

                    <div class="form-group">
                        <label for="accountId">Link to Account:</label>
                        <select id="accountId" name="account_id" required>
                            <option value="">-- Loading Accounts --</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cardNetwork">Card Network:</label>
                        <select id="cardNetwork" name="card_network" required>
                            <option value="">Select Card Network</option>
                            <option value="Visa">Visa</option>
                            <option value="Mastercard">Mastercard</option>
                            <option value="Verve">Verve</option>
                            <option value="Amex">American Express</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cardType">Card Type:</label>
                        <select id="cardType" name="card_type" required>
                            <option value="">Select Card Type</option>
                            <option value="Debit">Debit Card</option>
                            <option value="Credit">Credit Card</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="deliveryAddress">Delivery Address:</label>
                        <textarea id="deliveryAddress" name="delivery_address" placeholder="Your full delivery address" rows="3" required></textarea>
                    </div>

                    <button type="submit">Order Card</button>
                </form>
            </div>
        </div>
    </div>

    <div id="messageBoxOverlay" class="message-box-overlay">
        <div class="message-box-content">
            <p id="messageBoxContent"></p>
            <button id="messageBoxButton">OK</button>
        </div>
    </div>

    <script>
        // These variables must be defined before cards.js is loaded
        const PHP_BASE_URL = '<?php echo rtrim(BASE_URL, '/'); ?>/';
        const FRONTEND_BASE_URL = '<?php echo rtrim(BASE_URL, '/'); ?>/frontend/';
        const currentUserId = '<?php echo htmlspecialchars($userId); ?>';
        const currentUserFullName = '<?php echo htmlspecialchars($userFullName); ?>';
        const currentUserEmail = '<?php echo htmlspecialchars($userEmail); ?>'; // Pass email too, useful for some card logic
    </script>

    <script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>