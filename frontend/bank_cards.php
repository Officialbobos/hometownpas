<?php
// Config.php should be included first to ensure session and constants are available.
require_once '../Config.php'; // This now handles session_start() and Composer autoloader.
require_once '../functions.php'; // Ensure functions.php is included if it contains getMongoDBClient() or other helpers

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Use BASE_URL for redirects
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

$client = null;
$database = null;
$usersCollection = null;
$accountsCollection = null;
$bankCardsCollection = null;

try {
    // Establish MongoDB connection using the CORRECTED CONSTANTS
    $client = getMongoDBClient(); // Use the helper function from functions.php
    $database = $client->selectDatabase(MONGO_DB_NAME); // Use constant from Config.php
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
    $bankCardsCollection = $database->selectCollection('bank_cards');

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
            // Log error if user not found, but allow page to load with generic name
            error_log("User with ID " . $user_id . " not found in database for bank_cards.php.");
            $user_full_name = 'Bank Customer'; // Fallback if DB lookup fails
            $user_email = 'default@example.com';
        }
    }
} catch (MongoDBDriverException $e) {
    error_log("MongoDB connection or initial data fetch error in bank_cards.php: " . $e->getMessage());
    $message = "Database connection error. Please try again later. (Code: MDB_INIT)";
    $message_type = 'error';
    // If we can't connect, no point proceeding with any DB ops
    // For AJAX requests, return JSON; otherwise, die with an HTML message.
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => $message]));
    } else {
        die("<h1>" . htmlspecialchars($message) . "</h1><p>Please check application logs for details.</p>");
    }
} catch (Exception $e) { // Catch for ObjectId conversion or other general errors
    error_log("General error during initial setup in bank_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred during page setup. Please try again later.";
    $message_type = 'error';
    // If user_id is invalid, it's safer to redirect to login
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => $message]));
    } else {
        header('Location: ' . BASE_URL . '/index.php?error=invalid_user_session'); // Redirect with an error indicator
        exit;
    }
}

// Ensure full name and email are set for display, even if database lookup failed
$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// --- Handle AJAX requests (fetching cards or ordering new card) ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_cards') {
        $bank_cards = [];
        try {
            $cursor = $bankCardsCollection->find(['user_id' => $userObjectId]);

            foreach ($cursor as $row) {
                $row_arr = (array) $row; // Convert BSON document to PHP array
                $row_arr['id'] = (string) $row_arr['_id']; // Convert MongoDB _id to string for consistency
                $row_arr['display_card_number'] = '**** **** **** ' . substr($row_arr['card_number'] ?? '', -4);
                $row_arr['card_holder_name'] = strtoupper($user_full_name);
                $row_arr['display_expiry'] = str_pad($row_arr['expiry_month'] ?? '', 2, '0', STR_PAD_LEFT) . '/' . substr($row_arr['expiry_year'] ?? '', 2, 2);

                // Determine the card logo path based on the network
                $card_network_lower = strtolower($row_arr['card_network'] ?? 'default');
                $card_logo_path = BASE_URL . '/images/card_logos/' . $card_network_lower . '.png';
                // Check if the logo file actually exists (optional, but good for debugging)
                // In a real scenario, you might have default images or robust error handling
                if (!file_exists(__DIR__ . '/../images/card_logos/' . $card_network_lower . '.png')) {
                     $card_logo_path = BASE_URL . '/images/card_logos/default.png'; // Fallback if specific logo not found
                }
                $row_arr['card_logo_src'] = $card_logo_path;


                // DANGER: For mock only - DO NOT send real CVV/PIN to frontend in production
                // For a real application, remove this line entirely or mock it with '***' from backend.
                // $row_arr['display_cvv'] = $row_arr['cvv'] ?? '***'; // Keep for now as per original code, but strongly advise against.

                // Unset sensitive data before sending to frontend
                unset($row_arr['card_number']);
                unset($row_arr['cvv']);
                unset($row_arr['pin']); // Assuming 'pin' field might exist
                unset($row_arr['_id']); // Remove internal MongoDB ID from frontend
                unset($row_arr['user_id']); // Remove internal MongoDB ID from frontend
                unset($row_arr['account_id']); // Remove internal MongoDB ID from frontend

                $bank_cards[] = $row_arr;
            }
            echo json_encode(['success' => true, 'cards' => $bank_cards]);
        } catch (MongoDBDriverException $e) {
            error_log("Error fetching bank cards (AJAX): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error fetching cards: " . $e->getMessage()]);
        }
        exit; // IMPORTANT: Exit after sending JSON
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'order_card') {
        $cardType = filter_input(INPUT_POST, 'cardType', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cardNetwork = filter_input(INPUT_POST, 'cardNetwork', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $shippingAddress = filter_input(INPUT_POST, 'shippingAddress', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $accountId = filter_input(INPUT_POST, 'accountId', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Account ID will also be ObjectId

        if (empty($cardType) || empty($cardNetwork) || empty($shippingAddress) || empty($accountId)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required for ordering a new card, including the linked bank account.']);
            exit;
        }

        try {
            $accountObjectId = new ObjectId($accountId);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Invalid linked account ID format.']);
            exit;
        }

        // Generate mock card details
        $prefix = '';
        if ($cardNetwork === 'Visa') {
            $prefix = '4';
        } elseif ($cardNetwork === 'Mastercard') {
            $mc_prefixes = ['51', '52', '53', '54', '55'];
            $prefix = $mc_prefixes[array_rand($mc_prefixes)];
        } elseif ($cardNetwork === 'Amex') {
            $prefix = (mt_rand(0, 1) === 0) ? '34' : '37';
        } elseif ($cardNetwork === 'Verve') {
            $prefix = '5061';
        } else {
            $prefix = '9'; // Default for unknown networks
        }

        $card_number_length = ($cardNetwork === 'Amex') ? 15 : 16;
        $remaining_digits_length = $card_number_length - strlen($prefix);
        $random_digits = '';
        for ($i = 0; $i < $remaining_digits_length; $i++) {
            $random_digits .= mt_rand(0, 9);
        }
        $mock_card_number = $prefix . $random_digits;

        $mock_expiry_month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $mock_expiry_year = date('Y') + mt_rand(3, 7);
        $mock_cvv = str_pad(mt_rand(0, ($cardNetwork === 'Amex' ? 9999 : 999)), ($cardNetwork === 'Amex' ? 4 : 3), '0', STR_PAD_LEFT);
        $mock_card_holder_name = strtoupper($user_full_name);
        $initial_pin_hash = null; // Stored as null in MongoDB, PIN set on activation

        try {
            // Verify the linked account belongs to the user
            $account_data = $accountsCollection->findOne([
                '_id' => $accountObjectId,
                'user_id' => $userObjectId
            ]);

            if (!$account_data) {
                echo json_encode(['success' => false, 'message' => "Selected account not found or does not belong to your profile."]);
                exit;
            }

            $linked_account_type = $account_data['account_type'] ?? 'N/A';
            $linked_account_number = $account_data['account_number'] ?? 'N/A';

            // Insert new card into MongoDB
            $insertOneResult = $bankCardsCollection->insertOne([
                'user_id' => $userObjectId, // Store as ObjectId
                'account_id' => $accountObjectId, // Store as ObjectId
                'card_number' => $mock_card_number,
                'card_type' => $cardType,
                'expiry_month' => $mock_expiry_month,
                'expiry_year' => $mock_expiry_year,
                'cvv' => $mock_cvv, // DANGER: Remove in production or encrypt. Only for mock purposes.
                'card_holder_name' => $mock_card_holder_name,
                'is_active' => false, // New cards are inactive until activated by user
                'card_network' => $cardNetwork,
                'shipping_address' => $shippingAddress,
                'pin' => $initial_pin_hash, // Will be null initially
                'created_at' => new MongoDB\BSON\UTCDateTime(), // Timestamp
                'updated_at' => new MongoDB\BSON\UTCDateTime()  // Timestamp
            ]);

            if ($insertOneResult->getInsertedCount() === 1) {
                $inserted_card_id = (string) $insertOneResult->getInsertedId(); // Get string representation of new ObjectId

                // Email sending logic - using sendEmail function
                $subject = "Your HomeTown Bank Card Order Confirmation";
                $body = "Dear " . htmlspecialchars($user_full_name) . ",\n\n"
                    . "Thank you for ordering a new " . htmlspecialchars($cardNetwork) . " " . htmlspecialchars($cardType) . " card linked to your " . htmlspecialchars($linked_account_type) . " account (" . htmlspecialchars($linked_account_number) . ") from HomeTown Bank PA.\n\n"
                    . "Your order for a new card (ID: " . $inserted_card_id . ") has been successfully placed.\n"
                    . "Card Type: " . htmlspecialchars($cardType) . "\n"
                    . "Card Network: " . htmlspecialchars($cardNetwork) . "\n"
                    . "Linked Account: " . htmlspecialchars($linked_account_type) . " (No: " . htmlspecialchars($linked_account_number) . ")\n"
                    . "Shipping Address: " . htmlspecialchars($shippingAddress) . "\n\n"
                    . "Your card is currently being processed and will be shipped to the address provided. You will receive it within 5-7 business days.\n\n"
                    . "Once you receive your card, please log in to your dashboard to activate it and set your PIN.\n\n"
                    . "If you have any questions, please contact our customer support.\n\n"
                    . "Sincerely,\n"
                    . "The HomeTown Bank PA Team";

                if (sendEmail($user_email, $subject, nl2br($body), true)) { // Use nl2br for simple HTML formatting, true for isHtml
                    $email_status = "Email sent successfully.";
                } else {
                    $email_status = "Failed to send email. Please check server logs.";
                    error_log("Failed to send card order confirmation email to " . $user_email . " for user_id: " . $user_id);
                }

                echo json_encode(['success' => true, 'message' => 'Your card order has been placed successfully! ' . $email_status]);

            } else {
                throw new Exception("Failed to save card order to database.");
            }
        } catch (MongoDBDriverException $e) {
            error_log("Error ordering new card (MongoDB): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Database error placing order: " . $e->getMessage()]);
        } catch (Exception $e) {
            error_log("Error ordering new card (General): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error placing order: " . $e->getMessage()]);
        }
        exit; // IMPORTANT: Exit after sending JSON
    }
    // If an AJAX request comes in but no specific action is matched
    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action provided to bank_cards.php.']);
    exit;
}

// --- Non-AJAX request, render the HTML page ---
// We need to fetch the accounts for the dropdown in the form
$user_accounts_for_dropdown = [];
try {
    // Re-use $userObjectId from the initial connection block
    $cursor = $accountsCollection->find(
        ['user_id' => $userObjectId],
        ['projection' => ['account_number' => 1, 'account_type' => 1]]
    );
    foreach ($cursor as $accountDoc) {
        $user_accounts_for_dropdown[] = [
            'id' => (string) $accountDoc['_id'], // Convert ObjectId to string for HTML value
            'display_name' => ($accountDoc['account_type'] ?? 'Account') . ' (****' . substr($accountDoc['account_number'] ?? '', -4) . ')'
        ];
    }
} catch (MongoDBDriverException $e) {
    error_log("Error fetching accounts for dropdown: " . $e->getMessage());
    $message = "Could not load accounts for linking cards.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("Error processing user ID for accounts dropdown: " . $e->getMessage());
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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Your inline CSS remains here */
        /* It's generally better to move this to an external CSS file for maintainability */
        /* For this task, keeping it inline as per your original provided code. */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f7f6; color: #333; }
        .header { background-color: #007bff; color: white; padding: 15px 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .header .logo img { height: 40px; }
        .header h1 { margin: 0; font-size: 1.8em; }
        .header-nav a { color: white; text-decoration: none; background-color: #0056b3; padding: 8px 15px; border-radius: 5px; transition: background-color 0.3s ease; display: inline-flex; align-items: center; }
        .header-nav a:hover { background-color: #004085; }
        .header-nav i { margin-right: 8px; }

        .main-content { padding: 20px; max-width: 1200px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .main-content h2 { color: #007bff; margin-top: 0; margin-bottom: 25px; font-size: 1.8em; border-bottom: 2px solid #eee; padding-bottom: 10px; }

        .cards-section, .order-card-section, .manage-pin-section { margin-bottom: 40px; }

        .card-item { background: linear-gradient(45deg, #004d40, #00796b); color: white; padding: 25px; border-radius: 15px; width: 350px; margin: 20px auto; box-shadow: 0 10px 20px rgba(0,0,0,0.2); position: relative; font-family: 'Roboto Mono', monospace; display: flex; flex-direction: column; justify-content: space-between; min-height: 200px; }
        .card-item.visa { background: linear-gradient(45deg, #2a4b8d, #3f60a9); }
        .card-item.mastercard { background: linear-gradient(45deg, #eb001b, #ff5f00); }
        .card-item.amex { background: linear-gradient(45deg, #0081c7, #26a5d4); }
        .card-item.verve { background: linear-gradient(45deg, #006633, #009933); }
        .card-item h4 { margin-top: 0; font-size: 1.1em; color: rgba(255,255,255,0.8); }
        .card-item .chip { width: 50px; height: 35px; background-color: #d4af37; border-radius: 5px; margin-bottom: 20px; }
        .card-item .card-number { font-size: 1.6em; letter-spacing: 2px; margin-bottom: 20px; word-break: break-all; }
        .card-item .card-footer { display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em; width: 100%; }
        .card-item .card-footer .label { font-size: 0.7em; opacity: 0.7; margin-bottom: 3px; }
        .card-item .card-footer .value { font-weight: bold; }
        /* Removed .card-item .card-logo as it will be injected by JS now */
        .card-logo-img { position: absolute; bottom: 25px; right: 25px; height: 40px; } /* New class for the image itself */
        .card-status { font-size: 0.9em; text-align: right; margin-top: 10px; opacity: 0.9; }
        .card-status.active { color: #d4edda; }
        .card-status.inactive { color: #f8d7da; }
        .loading-message, .no-data-message { text-align: center; padding: 20px; color: #555; font-size: 1.1em; }
        .card-list { display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; padding: 20px 0; }
        /* Form and button styling */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: calc(100% - 20px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
        }
        .submit-button {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: auto;
            min-width: 200px;
        }
        .submit-button:hover { background-color: #0056b3; }
        .message { padding: 10px; margin-bottom: 20px; border-radius: 5px; text-align: center; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        /* Message box overlay */
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
        .message-box-overlay.show { opacity: 1; visibility: visible; }
        .message-box-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        .message-box-overlay.show .message-box-content { transform: translateY(0); }
        .message-box-content p { font-size: 1.1em; margin-bottom: 20px; color: #333; }
        .message-box-content button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease;
        }
        .message-box-content button:hover { background-color: #0056b3; }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <a href="<?php echo BASE_URL; ?>/dashboard">
                <img src="<?php echo htmlspecialchars(BASE_URL); ?>/images/hometown_bank_logo.png" alt="Hometown Bank PA Logo">
            </a>
        </div>
        <h1>Manage My Cards</h1>
        <nav class="header-nav">
            <a href="<?php echo BASE_URL; ?>/dashboard">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </nav>
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
                <input type="hidden" name="action" value="order_card">
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
                        <option value="">Select Account</option>
                        <?php foreach ($user_accounts_for_dropdown as $account): ?>
                            <option value="<?php echo htmlspecialchars($account['id']); ?>">
                                <?php echo htmlspecialchars($account['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
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
            <p>To activate a new card or set/change your existing card's PIN, please visit the <a href="<?php echo BASE_URL; ?>/activate_card">Card Activation & PIN Management page</a>.</p>
        </section>
    </main>

    <div class="message-box-overlay" id="messageBoxOverlay">
        <div class="message-box-content" id="messageBoxContentWrapper">
            <p id="messageBoxContent"></p>
            <button id="messageBoxButton">OK</button>
        </div>
    </div>

    <script>
        const BASE_URL_JS = <?php echo json_encode(BASE_URL); ?>;
        const currentUserId = <?php echo json_encode($user_id); ?>;
        const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>