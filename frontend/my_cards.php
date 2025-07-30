<?php
// frontend/my_cards.php
session_start();
// Adjust path based on your directory structure relative to this file
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For getMongoDBClient() and other helpers

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime; // Added for timestamping activation
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) { // Check for empty user_id as well
    header('Location: ' . BASE_URL . '/login'); // Redirect to login route
    exit;
}

$message = ''; // For PHP-generated messages (e.g., initial DB error, form submission results)
$message_type = '';
$userId = $_SESSION['user_id']; // Get user ID from session

// Initialize variables for JavaScript and HTML display
$userFullName = 'CARD HOLDER'; // Default fallback
$userEmail = ''; // Default fallback

$mongoClient = null;
$mongoDb = null;
$usersCollection = null;
$accountsCollection = null;
$bankCardsCollection = null;

$userObjectId = null; // Will store the MongoDB ObjectId for the current user

try {
    $mongoClient = getMongoDBClient(); // Use your helper function to get MongoDB client
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    $usersCollection = $mongoDb->selectCollection('users');
    $accountsCollection = $mongoDb->selectCollection('accounts');
    $bankCardsCollection = $mongoDb->selectCollection('bank_cards');

    $userObjectId = new ObjectId($userId); // Convert user ID to MongoDB ObjectId

    // Fetch user's full name and email for initial display and JavaScript
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
    // If DB connection fails, prevent further processing
    die("<h1>Database connection error. Please try again later.</h1>");
} catch (Exception $e) {
    error_log("General error in my_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again later.";
    $message_type = 'error';
    // If user_id is invalid or other critical error, redirect
    header('Location: ' . BASE_URL . '/login?error=invalid_session');
    exit;
}

// --- PHP Logic for Card Activation (moved here from previous my_cards.php) ---
$pending_card_for_activation = null;
if ($userObjectId) { // Ensure userObjectId is valid before querying
    try {
        $pending_card_for_activation = $bankCardsCollection->findOne([
            'user_id' => $userObjectId,
            'status' => 'pending_activation',
            'is_active' => false // Ensure it's explicitly not active
        ]);
    } catch (MongoDBDriverException $e) {
        error_log("Error fetching pending card for user " . $userId . " in activation section: " . $e->getMessage());
        // $message is already set above if connection failed, otherwise don't override other messages.
    }
}


// Handle Card Activation/PIN setup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate_card') {
    $card_id_str = trim($_POST['card_id'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    $confirm_pin = trim($_POST['confirm_pin'] ?? '');

    try {
        if (empty($card_id_str)) {
            throw new Exception('Invalid card ID provided for activation.');
        }
        $card_id_obj = new ObjectId($card_id_str);

        // Verify that the card being activated belongs to the current user and is truly pending
        $card_to_activate = $bankCardsCollection->findOne([
            '_id' => $card_id_obj,
            'user_id' => $userObjectId,
            'status' => 'pending_activation',
            'is_active' => false
        ]);

        if (!$card_to_activate) {
            throw new Exception('Card not found or not eligible for activation. It might already be active or cancelled.');
        }

        if (empty($pin) || empty($confirm_pin)) {
            throw new Exception('PIN and Confirm PIN are required.');
        }
        if ($pin !== $confirm_pin) {
            throw new Exception('PINs do not match.');
        }
        // Basic PIN validation (e.g., 4-digit number)
        if (strlen($pin) !== 4 || !ctype_digit($pin)) {
            throw new Exception('PIN must be a 4-digit number.');
        }

        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT); // Hash the PIN for security

        $updateResult = $bankCardsCollection->updateOne(
            ['_id' => $card_id_obj],
            ['$set' => [
                'pin' => $hashed_pin, // Store the hashed PIN
                'is_active' => true,
                'status' => 'active', // Update status to 'active'
                'activated_at' => new UTCDateTime(time() * 1000), // Record activation time
                'updated_at' => new UTCDateTime(time() * 1000)
            ]]
        );

        if ($updateResult->getModifiedCount() === 1) {
            $message = 'Your bank card has been successfully activated and PIN set!';
            $message_type = 'success';
            // Important: After successful activation, clear the pending card so the form disappears
            $pending_card_for_activation = null;
        } else {
            $message = 'Failed to activate card. It might already be active or an internal error occurred.';
            $message_type = 'error';
        }

    } catch (MongoDBDriverException $e) {
        error_log("MongoDB operation error during card activation (user_id: " . $userId . "): " . $e->getMessage());
        $message = 'Database error during card activation. Please try again later.';
        $message_type = 'error';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}


// --- Fetch all accounts for the "Link to Account" dropdown (Order Card Form) ---
$user_accounts_for_dropdown = [];
if ($userObjectId) {
    try {
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
        error_log("Error fetching accounts for dropdown (MongoDB) for user " . $userId . ": " . $e->getMessage());
        $message_order_form = "Could not load accounts for linking cards.";
        $message_type_order_form = 'error';
    } catch (Exception $e) {
        error_log("Error processing user ID for accounts dropdown (General) for user " . $userId . ": " . $e->getMessage());
        $message_order_form = "Error loading user data for accounts.";
        $message_type_order_form = 'error';
    }
}
// If accounts couldn't be loaded, display a message in the dropdown
if (empty($user_accounts_for_dropdown) && !isset($message_order_form)) {
    $message_order_form = "No accounts available to link a card.";
    $message_type_order_form = 'info';
}


// --- Fetch all cards for the user to display in the list (including pending) ---
$all_user_cards = [];
if ($userObjectId) {
    try {
        $cursor = $bankCardsCollection->find(['user_id' => $userObjectId]);
        foreach ($cursor as $cardDoc) {
            $card = (array) $cardDoc;
            $card['card_number_display'] = '**** **** **** ' . substr($card['card_number'], -4);
            $card['expiry_date_display'] = str_pad($card['expiry_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($card['expiry_year'], -2);
            $all_user_cards[] = $card;
        }
    } catch (MongoDBDriverException $e) {
        error_log("Error fetching all cards for user " . $userId . " for display: " . $e->getMessage());
        // If there was an error fetching all cards, show a message for that section specifically
        $all_cards_message = "Could not load your bank cards for display.";
        $all_cards_message_type = 'error';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bank Cards - HomeTown Bank</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/my_cards.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add or override styles from my_cards.css if needed for consistent card display */
        .card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            padding: 20px;
            justify-content: center;
        }

        .bank-card-display {
            position: relative;
            background: linear-gradient(135deg, #004494, #0056b3); /* Default Hometown Bank Blue */
            border-radius: 15px;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            color: #fff;
            padding: 20px 25px;
            aspect-ratio: 1.585 / 1; /* Standard credit card aspect ratio */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'Roboto Mono', monospace; /* Use Roboto Mono for card numbers/details */
            overflow: hidden;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            cursor: default; /* Not clickable usually */
            box-sizing: border-box;
        }

        .bank-card-display:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 18px rgba(0, 0, 0, 0.25);
        }

        /* Card Network Specific Gradients */
        .bank-card-display.visa { background: linear-gradient(135deg, #1A3B6F, #2A569E); } /* Deep blue for Visa */
        .bank-card-display.mastercard { background: linear-gradient(135deg, #EB001B, #F79E1B); } /* Red-orange for Mastercard */
        .bank-card-display.verve { background: linear-gradient(135deg, #009245, #8BC34A); } /* Green tones for Verve */
        .bank-card-display.amex { background: linear-gradient(135deg, #2E7D32, #66BB6A); } /* Dark green for Amex */


        .card-header-logo {
            font-size: 1.1em;
            font-weight: 700;
            text-align: right;
            margin-bottom: 10px;
        }
        .card-network-logo {
            position: absolute;
            top: 20px;
            left: 25px;
            width: 70px; /* Adjust size as needed */
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 5px rgba(0,0,0,0.3));
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
            font-size: 1.7em;
            letter-spacing: 0.15em;
            text-align: center;
            margin-top: auto; /* Push it to the bottom-middle */
            margin-bottom: 15px;
            word-break: break-all;
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
        .card-status.active { background-color: rgba(60, 179, 113, 0.8); color: white; } /* MediumSeaGreen */
        .card-status.pending_activation { background-color: rgba(255, 165, 0, 0.8); color: white; } /* Orange */
        .card-status.inactive { background-color: rgba(255, 99, 71, 0.8); color: white; } /* Tomato */
        .card-status.lost-stolen { background-color: rgba(220, 20, 60, 0.8); color: white; } /* Crimson */
        .card-status.cancelled { background-color: rgba(100, 100, 100, 0.8); color: white; } /* Grey */

        /* Specific styles for activation form */
        .activation-section, .order-card-section {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-top: 40px;
        }
        .activation-section h3, .order-card-section h3 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 1.8em;
        }
        .activation-form .form-group, .order-card-form .form-group {
            margin-bottom: 20px;
        }
        .activation-form label, .order-card-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .activation-form input[type="password"],
        .activation-form input[type="text"],
        .order-card-form input[type="text"],
        .order-card-form textarea,
        .order-card-form select {
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding in width */
        }
        .activation-form input[type="text"][readonly],
        .order-card-form input[type="text"][readonly] {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        .activation-form button, .order-card-form button {
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
            display: block;
            width: 100%;
            font-weight: bold;
            margin-top: 20px;
        }
        .activation-form button:hover, .order-card-form button:hover {
            background-color: #0056b3;
        }
        .no-pending-card-message {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 1.1em;
        }
        /* Message Box Overlay from previous examples */
        .message-box-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            display: none; /* Hidden by default */
        }

        .message-box-content {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: scale(0.95);
            transition: transform 0.3s ease-out;
        }

        .message-box-overlay.active .message-box-content {
            transform: scale(1);
        }

        .message-box-content p {
            font-size: 1.1em;
            margin-bottom: 20px;
            color: #333;
        }

        .message-box-content button {
            background-color: #007bff;
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease;
        }

        .message-box-content button:hover {
            background-color: #0056b3;
        }

        @media (max-width: 768px) {
            .user-dashboard-container {
                padding: 15px;
            }
            .user-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .user-header .logo {
                margin-bottom: 10px;
            }
            .card-container {
                grid-template-columns: 1fr;
            }
            .bank-card-display {
                max-width: 350px;
                margin: 0 auto;
            }
            .activation-section, .order-card-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="user-dashboard-container">
        <div class="user-header">
            <img src="<?php echo BASE_URL; ?>/images/hometown_bank_logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>My Bank Cards</h2>
            <a href="<?php echo BASE_URL; ?>/logout" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): // Display PHP-generated messages from activation or initial load ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <section class="activation-section">
                <h3 class="section-header">Activate Your Card / Set PIN</h3>
                <?php if ($pending_card_for_activation): ?>
                    <p style="text-align: center; margin-bottom: 20px;">A new card (ending in ****<?php echo substr($pending_card_for_activation['card_number'], -4); ?>, Type: <?php echo htmlspecialchars($pending_card_for_activation['card_type']); ?>, Network: <?php echo htmlspecialchars($pending_card_for_activation['card_network']); ?>) has been issued and is awaiting your activation. Please set a 4-digit PIN.</p>
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="activation-form">
                        <input type="hidden" name="action" value="activate_card">
                        <input type="hidden" name="card_id" value="<?php echo htmlspecialchars((string)$pending_card_for_activation['_id']); ?>">

                        <div class="form-group">
                            <label for="pin">Set 4-Digit PIN:</label>
                            <input type="password" id="pin" name="pin" maxlength="4" pattern="\d{4}" title="Please enter a 4-digit number" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_pin">Confirm PIN:</label>
                            <input type="password" id="confirm_pin" name="confirm_pin" maxlength="4" pattern="\d{4}" title="Please confirm your 4-digit PIN" required>
                        </div>
                        <button type="submit">Activate Card & Set PIN</button>
                    </form>
                <?php else: ?>
                    <p class="no-pending-card-message">You currently have no bank cards awaiting activation.</p>
                <?php endif; ?>
            </section>

            <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

            <section class="all-active-cards-section">
                <h3 class="section-header">Your All Bank Cards</h3>
                <?php if (isset($all_cards_message)): // Display message specific to fetching all cards ?>
                    <p class="message <?php echo $all_cards_message_type; ?>"><?php echo htmlspecialchars($all_cards_message); ?></p>
                <?php elseif (!empty($all_user_cards)): ?>
                    <div id="userCardList" class="card-container">
                        <?php foreach ($all_user_cards as $card): ?>
                            <div class="bank-card-display <?php echo strtolower($card['card_network']); ?>">
                                <?php
                                    $network_logo_path = '';
                                    if (strtolower($card['card_network']) === 'visa') {
                                        $network_logo_path = BASE_URL . '/images/visa_logo.png'; // Assuming you have these images
                                    } elseif (strtolower($card['card_network']) === 'mastercard') {
                                        $network_logo_path = BASE_URL . '/images/mastercard_logo.png';
                                    } elseif (strtolower($card['card_network']) === 'verve') {
                                        $network_logo_path = BASE_URL . '/images/verve_logo.png';
                                    } elseif (strtolower($card['card_network']) === 'amex') {
                                        $network_logo_path = BASE_URL . '/images/amex_logo.png';
                                    }
                                ?>
                                <?php if ($network_logo_path): ?>
                                    <img src="<?php echo htmlspecialchars($network_logo_path); ?>" alt="<?php echo htmlspecialchars($card['card_network']); ?> Logo" class="card-network-logo">
                                <?php endif; ?>

                                <div class="card-chip"></div>
                                <div class="card-header-logo">HOMETOWN BANK</div>

                                <div class="card-number"><?php echo htmlspecialchars($card['card_number_display']); ?></div>

                                <div class="card-details-bottom">
                                    <div class="card-details-group">
                                        <div class="card-details-label">CARD HOLDER</div>
                                        <div class="card-details-value"><?php echo htmlspecialchars($card['card_holder_name']); ?></div>
                                    </div>
                                    <div class="card-details-group right">
                                        <div class="card-details-label">EXPIRES</div>
                                        <div class="card-details-value"><?php echo htmlspecialchars($card['expiry_date_display']); ?></div>
                                    </div>
                                </div>
                                 <div class="card-status <?php echo str_replace(' ', '_', strtolower($card['status'])); ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $card['status'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p id="noCardsMessage" style="text-align: center;">You do not have any bank cards registered with us yet. Order one below!</p>
                <?php endif; ?>
            </section>

            <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

            <div class="order-card-section">
                <h3 class="section-header">Order a New Bank Card</h3>
                <?php if (isset($message_order_form)): ?>
                    <p class="message <?php echo $message_type_order_form; ?>"><?php echo htmlspecialchars($message_order_form); ?></p>
                <?php endif; ?>
                <form id="orderCardForm" class="order-card-form">
                    <div class="form-group">
                        <label for="cardHolderName">Card Holder Name:</label>
                        <input type="text" id="cardHolderName" name="cardHolderName" value="<?= htmlspecialchars($userFullName) ?>" required readonly>
                    </div>

                    <div class="form-group">
                        <label for="accountId">Link to Account:</label>
                        <select id="accountId" name="account_id" required>
                            <option value="">-- Select an Account --</option>
                            <?php if (!empty($user_accounts_for_dropdown)): ?>
                                <?php foreach ($user_accounts_for_dropdown as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['id']); ?>">
                                        <?php echo htmlspecialchars($account['account_type'] . ' (' . $account['display_account_number'] . ') - ' . $account['currency'] . ' ' . number_format($account['balance'], 2)); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No accounts available to link</option>
                            <?php endif; ?>
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

            <p style="text-align: center; margin-top: 30px;">
                <a href="<?php echo BASE_URL; ?>/dashboard" class="back-link">&larr; Back to Dashboard</a>
            </p>
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

        // Initial rendering of cards (optional, can be done fully by JS if preferred)
        // If you keep PHP rendering cards, this JS might need adjustment to not duplicate
        // or to manage states like showing/hiding loading messages.
        // For simplicity, I'm keeping the PHP rendering for the "All Your Bank Cards" section
        // and assuming cards.js will handle the order form submission and potentially
        // dynamic updates or re-fetches.
    </script>

    <script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>