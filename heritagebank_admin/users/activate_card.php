<?php
session_start();
require_once '../Config.php';
require_once '../functions.php'; // Ensure this contains getMongoDBClient() and getCollection()

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBException; // Alias for MongoDB specific exceptions

if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}
$user_id_str = $_SESSION['user_id'];
$user_id_obj = null;
try {
    $user_id_obj = new ObjectId($user_id_str);
} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    error_log("Invalid user ID in session: " . $e->getMessage());
    die("Invalid user session. Please log in again.");
}

$message = '';
$message_type = '';

$client = null;
$bankCardsCollection = null;

try {
    $client = getMongoDBClient();
    $bankCardsCollection = getCollection('bank_cards', $client);
} catch (MongoDBException $e) {
    error_log("MongoDB connection failed in activate_card.php: " . $e->getMessage());
    die("Database connection error. Please try again later.");
} catch (Exception $e) {
    error_log("General error in activate_card.php: " . $e->getMessage());
    die("An unexpected error occurred. Please try again later.");
}

// Handle PIN setting/card activation logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_id_str = filter_input(INPUT_POST, 'card_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $new_pin = filter_input(INPUT_POST, 'new_pin', FILTER_SANITIZE_NUMBER_INT);
    $confirm_pin = filter_input(INPUT_POST, 'confirm_pin', FILTER_SANITIZE_NUMBER_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $card_id_obj = null;
    try {
        if (!empty($card_id_str)) {
            $card_id_obj = new ObjectId($card_id_str);
        }
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        $message = 'Invalid card ID format.';
        $message_type = 'error';
    }

    if ($action === 'set_pin' || $action === 'activate_card') {
        if (!$card_id_obj) {
            $message = 'Invalid card selected or ID format.';
            $message_type = 'error';
        } elseif (empty($new_pin) || empty($confirm_pin) || strlen($new_pin) != 4 || !ctype_digit($new_pin)) { // Ensure it's exactly 4 digits
            $message = 'PIN must be a 4-digit number.';
            $message_type = 'error';
        } elseif ($new_pin !== $confirm_pin) {
            $message = 'New PIN and confirm PIN do not match.';
            $message_type = 'error';
        } else {
            // Hash the PIN (NEVER store plain PINs!)
            $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);

            try {
                // Update card status to active and set PIN
                // Ensure the card belongs to the logged-in user
                $updateResult = $bankCardsCollection->updateOne(
                    ['_id' => $card_id_obj, 'user_id' => $user_id_obj],
                    ['$set' => [
                        'pin' => $hashed_pin,
                        'is_active' => true, // Assuming activating the card sets it to true
                        'updated_at' => new UTCDateTime(time() * 1000) // Current UTC timestamp in milliseconds
                    ]]
                );

                if ($updateResult->getModifiedCount() > 0) {
                    $message = 'Card activated and PIN set successfully!';
                    $message_type = 'success';
                } else {
                    // This could mean the card was not found for the user, or it was already active/PIN set
                    $message = 'No changes made. Card not found for your account or already active/PIN set.';
                    $message_type = 'info';
                }
            } catch (MongoDBException $e) {
                error_log("MongoDB Error setting PIN: " . $e->getMessage());
                $message = "Error processing your request (DB error). Please try again later.";
                $message_type = 'error';
            } catch (Exception $e) {
                error_log("General Error setting PIN: " . $e->getMessage());
                $message = "An unexpected error occurred. Please try again later.";
                $message_type = 'error';
            }
        }
    }
}

// Fetch user's cards for display
$cards_to_manage = [];
try {
    $cursor = $bankCardsCollection->find(
        ['user_id' => $user_id_obj],
        ['sort' => ['is_active' => 1, 'created_at' => -1]] // Inactive first, then newest
    );

    foreach ($cursor as $card) {
        // Convert MongoDB\BSON\ObjectId to string for HTML output
        $card['_id'] = (string)$card['_id'];
        $card['display_card_number'] = '**** **** **** ' . substr($card['card_number'], -4);
        $cards_to_manage[] = $card;
    }
} catch (MongoDBException $e) {
    error_log("MongoDB Error fetching cards for PIN management: " . $e->getMessage());
    $message = "Error loading cards for management.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("General Error fetching cards for PIN management: " . $e->getMessage());
    $message = "An unexpected error occurred while loading cards.";
    $message_type = 'error';
}

// No need to close MongoDB client explicitly as PHP will handle it on script end
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Activate Card & Set PIN</title>
    <link rel="stylesheet" href="bank_cards.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add specific styles for this page if needed */
        .card-management-section {
            background-color: #f9f9f9;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-management-section h2 {
            color: #333;
            margin-bottom: 20px;
            text-align: center;
        }
        .card-select-container {
            margin-bottom: 25px;
            text-align: center;
        }
        .card-select-container label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #555;
        }
        .card-select-container select {
            width: 80%;
            max-width: 400px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
        }
        .pin-form-container {
            background-color: #e6f7ff; /* Light blue background */
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #b3e0ff;
        }
        .pin-form-container h3 {
            text-align: center;
            color: #007bff;
            margin-bottom: 20px;
        }
        .pin-form-container .form-group {
            margin-bottom: 15px;
        }
        .pin-form-container label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .pin-form-container input[type="password"] {
            width: calc(100% - 22px); /* Account for padding and border */
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1.1em;
            text-align: center;
            letter-spacing: 2px;
        }
        .pin-form-container button {
            display: block;
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5gpx;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .pin-form-container button:hover {
            background-color: #0056b3;
        }
        .message.success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
        .message.error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }
        .message.info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; }

    </style>
</head>
<body>
    <header class="header">
        <div class="logo">
            <a href="user-dashboard.php">
                <img src="https://i.imgur.com/YmC3kg3.png" alt="Heritage Bank Logo">
            </a>
        </div>
        <h1>Card Activation & PIN Management</h1>
        <nav class="header-nav">
            <a href="bank_cards.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Back to Manage Cards
            </a>
        </nav>
    </header>

    <main class="main-content">
        <section class="card-management-section">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <h2>Activate Your Card or Reset PIN</h2>
            <?php if (empty($cards_to_manage)): ?>
                <p class="no-data-message">You do not have any cards to manage at this time. <a href="bank_cards.php">Order a new card</a>.</p>
            <?php else: ?>
                <form action="activate_card.php" method="POST" class="pin-form-container">
                    <div class="card-select-container">
                        <label for="card_id">Select a Card:</label>
                        <select id="card_id" name="card_id" required>
                            <option value="">-- Select a Card --</option>
                            <?php foreach ($cards_to_manage as $card): ?>
                                <option value="<?php echo htmlspecialchars($card['_id']); ?>"
                                    <?php echo (isset($_POST['card_id']) && $_POST['card_id'] === $card['_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($card['card_network'] . ' ' . $card['card_type'] . ' (' . $card['display_card_number'] . ') - ' . ($card['is_active'] ? 'Active' : 'Inactive')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Only inactive cards will be activated upon PIN setting. You can also reset the PIN for active cards.</small>
                    </div>

                    <div class="form-group">
                        <label for="new_pin">Enter New 4-Digit PIN:</label>
                        <input type="password" id="new_pin" name="new_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Confirm New 4-Digit PIN:</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                    </div>

                    <input type="hidden" name="action" value="set_pin">
                    <button type="submit"><i class="fas fa-lock" style="margin-right: 8px;"></i> Activate Card & Set PIN</button>
                </form>
            <?php endif; ?>
        </section>
    </main>

</body>
</html>