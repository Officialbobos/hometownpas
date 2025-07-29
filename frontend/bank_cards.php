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

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/hometownbank');
}

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
            $_SESSION['user_full_name'] = $user_full_name;
            $_SESSION['user_email'] = $user_email;
        } else {
            error_log("User with ID " . $user_id . " not found in database for bank_cards.php.");
            $user_full_name = 'Bank Customer';
            $user_email = 'default@example.com';
        }
    }
} catch (MongoDBDriverException $e) {
    error_log("MongoDB operation error in bank_cards.php (user data fetch): " . $e->getMessage());
    $message = "Database error fetching user details. Please try again later.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("General error during initial setup in bank_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred during page setup. Please try again later.";
    $message_type = 'error';
    header('Location: ' . BASE_URL . '/index.php?error=invalid_user_session');
    exit;
}

$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// Fetch accounts for the dropdown
$user_accounts_for_dropdown = [];
try {
    if ($userObjectId) { // Ensure $userObjectId is valid before querying
        $cursor = $accountsCollection->find(
            ['user_id' => $userObjectId],
            ['projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]]
        );
        foreach ($cursor as $accountDoc) {
            $user_accounts_for_dropdown[] = [
                'id' => (string) $accountDoc['_id'],
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

// --- NEW LOGIC: Fetch user's existing bank cards ---
$user_cards = [];
try {
    if ($userObjectId) { // Ensure $userObjectId is valid before querying
        $card_cursor = $bankCardsCollection->find(['user_id' => $userObjectId]);
        foreach ($card_cursor as $cardDoc) {
            $card_data = (array) $cardDoc;
            $card_data['id'] = (string) $cardDoc['_id'];
            $card_data['card_number_display'] = $card_data['card_number_masked'] ?? '************0000';
            $card_data['expiry_display'] = $card_data['expiry_date'] ?? 'MM/YY';
            // You might want to format the card status more user-friendly
            $card_data['status_display'] = $card_data['is_active'] ? 'Active' : (isset($card_data['status']) && $card_data['status'] === 'reported_lost_stolen' ? 'Lost/Stolen' : 'Inactive');
            $user_cards[] = $card_data;
        }
    }
} catch (MongoDBDriverException $e) {
    error_log("Error fetching user bank cards (MongoDB): " . $e->getMessage());
    $message = "Could not load your bank cards.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("Error processing user ID for card fetch (General): " . $e->getMessage());
    $message = "Error loading card data.";
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Bank Cards</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/bank_cards.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* Re-using and adapting card display styles from manage_card.php */
        .cards-display-section {
            margin-top: 40px;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .cards-display-section h2 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 2em;
        }
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            justify-content: center;
            align-items: start;
        }

        .bank-card-item {
            position: relative;
            background: linear-gradient(135deg, #004494, #0056b3); /* Heritage Bank Blue Gradient */
            border-radius: 15px;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            color: #fff;
            padding: 20px 25px;
            aspect-ratio: 1.585 / 1; /* Standard credit card aspect ratio */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'Space Mono', monospace; /* Modern, clean look for cards */
            overflow: hidden;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            cursor: pointer;
            text-decoration: none; /* Remove underline from link */
        }

        .bank-card-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.3);
        }

        .bank-card-item::before {
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

        .bank-card-item .card-header-logo {
            font-size: 1.2rem;
            font-weight: 700;
            text-align: right;
            margin-bottom: 10px;
            z-index: 2;
        }

        .bank-card-item .card-network-logo {
            position: absolute;
            top: 20px;
            left: 25px;
            width: 60px;
            height: auto;
            z-index: 2;
        }

        .bank-card-item .card-chip {
            width: 45px;
            height: 35px;
            background-color: #d4af37; /* Gold color */
            border-radius: 5px;
            position: absolute;
            top: 70px;
            left: 25px;
            box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.3);
            z-index: 2;
        }

        .bank-card-item .card-number {
            font-size: 1.6rem; /* Slightly smaller for multiple cards */
            letter-spacing: 0.1rem;
            text-align: center;
            margin-top: auto;
            margin-bottom: 10px;
            z-index: 2;
            word-break: break-all; /* Ensure long numbers wrap */
        }

        .bank-card-item .card-details-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            width: 100%;
            z-index: 2;
            font-size: 0.85em;
        }
        
        .bank-card-item .card-details-group {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .bank-card-item .card-details-group.right {
            text-align: right;
        }
        .bank-card-item .card-details-label {
            font-size: 0.7em;
            opacity: 0.8;
            margin-bottom: 2px;
        }
        .bank-card-item .card-status {
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
        .bank-card-item .card-status.active {
            background-color: rgba(60, 179, 113, 0.8); /* MediumSeaGreen */
            color: white;
        }
        .bank-card-item .card-status.inactive {
            background-color: rgba(255, 99, 71, 0.8); /* Tomato */
            color: white;
        }
        .bank-card-item .card-status.lost-stolen {
            background-color: rgba(220, 20, 60, 0.8); /* Crimson */
            color: white;
        }

        .no-cards-message {
            text-align: center;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-top: 30px;
        }
        .no-cards-message p {
            font-size: 1.1em;
            color: #555;
        }
        .no-cards-message a {
            color: #004494;
            text-decoration: none;
            font-weight: bold;
        }
        .no-cards-message a:hover {
            text-decoration: underline;
        }
        
        /* Responsive adjustments for the card grid */
        @media (max-width: 768px) {
            .cards-grid {
                grid-template-columns: 1fr; /* Stack cards vertically on smaller screens */
            }
            .bank-card-item {
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
        <h1>Your Bank Cards</h1>
        <div class="logo">
            <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
        </div>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <section class="cards-display-section">
            <h2>My Cards</h2>
            <?php if (!empty($user_cards)): ?>
                <div class="cards-grid">
                    <?php foreach ($user_cards as $card): ?>
                        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/manage_card.php?card_id=<?php echo htmlspecialchars($card['id']); ?>" class="bank-card-item">
                            <?php if (isset($card['card_network'])): ?>
                                <img src="<?php echo rtrim(BASE_URL, '/'); ?>/images/<?php echo strtolower(htmlspecialchars($card['card_network'])); ?>_logo.png" alt="<?php echo htmlspecialchars($card['card_network']); ?> Logo" class="card-network-logo" onerror="this.style.display='none'">
                            <?php endif; ?>

                            <div class="card-chip"></div>

                            <div class="card-number"><?php echo htmlspecialchars(wordwrap($card['card_number_display'], 4, ' ', true)); ?></div>

                            <div class="card-details-bottom">
                                <div class="card-details-group">
                                    <div class="card-details-label">Card Holder</div>
                                    <div class="card-holder-name"><?php echo htmlspecialchars($card['card_holder_name'] ?? $user_full_name); ?></div>
                                </div>
                                <div class="card-details-group right">
                                    <div class="card-details-label">Expires</div>
                                    <div class="card-expiry"><?php echo htmlspecialchars($card['expiry_display']); ?></div>
                                </div>
                            </div>
                            <span class="card-status <?php echo strtolower(str_replace(' ', '-', $card['status_display'])); ?>">
                                <?php echo htmlspecialchars($card['status_display']); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-cards-message">
                    <p>You currently have no bank cards linked to your account. Order one below!</p>
                </div>
            <?php endif; ?>
        </section>

        <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

        <section class="order-card-section">
            <h2>Place a New Card Order</h2>
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
            <h2>Card Activation & PIN Management</h2>
            <p>Once you receive your ordered card, you can activate it and set/change its PIN on the <a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/manage_card.php">Card Management page</a>.</p>
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
        const PHP_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/'); ?>;
        const FRONTEND_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/frontend'); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
        const currentUserEmail = <?php echo json_encode($user_email); ?>;
    </script>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/cards.js"></script>
</body>
</html>