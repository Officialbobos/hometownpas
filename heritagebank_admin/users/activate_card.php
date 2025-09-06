<?php

session_start();
require_once '../../Config.php'; // Correct path for admin folder structure
require_once '../../functions.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBException;

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = '';

$client = null;
$bankCardsCollection = null;
$usersCollection = null;

try {
    $client = getMongoDBClient();
    $bankCardsCollection = getCollection('bank_cards', $client);
    $usersCollection = getCollection('users', $client);
} catch (MongoDBException $e) {
    error_log("MongoDB connection failed in activate_card.php: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// --- LOGIC TO GET USER ID FROM URL PARAMETER ---
$user_id_str = filter_input(INPUT_GET, 'user_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
if (empty($user_id_str)) {
    // If user_id is not provided, redirect back to user management page
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_users.php');
    exit;
}

$user_id_obj = null;
try {
    $user_id_obj = new ObjectId($user_id_str);
} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    error_log("Invalid user ID passed to activate_card.php: " . $e->getMessage());
    die("Invalid user ID provided.");
}

// Check if the user exists
$user_doc = $usersCollection->findOne(['_id' => $user_id_obj]);
if (!$user_doc) {
    die("User not found.");
}

// Handle PIN setting/card activation logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $card_id_str = filter_input(INPUT_POST, 'card_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $new_pin = filter_input(INPUT_POST, 'new_pin', FILTER_SANITIZE_NUMBER_INT);
    $confirm_pin = filter_input(INPUT_POST, 'confirm_pin', FILTER_SANITIZE_NUMBER_INT);

    $card_id_obj = null;
    try {
        if (!empty($card_id_str)) {
            $card_id_obj = new ObjectId($card_id_str);
        }
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        $message = 'Invalid card ID format.';
        $message_type = 'error';
    }

    if (!$card_id_obj) {
        $message = 'Invalid card selected or ID format.';
        $message_type = 'error';
    } elseif (empty($new_pin) || empty($confirm_pin) || strlen($new_pin) != 4 || !ctype_digit($new_pin)) {
        $message = 'PIN must be a 4-digit number.';
        $message_type = 'error';
    } elseif ($new_pin !== $confirm_pin) {
        $message = 'New PIN and confirm PIN do not match.';
        $message_type = 'error';
    } else {
        $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);

        try {
            // Ensure the card belongs to the selected user
            $updateResult = $bankCardsCollection->updateOne(
                ['_id' => $card_id_obj, 'user_id' => $user_id_obj],
                ['$set' => [
                    'pin' => $hashed_pin,
                    'is_active' => true,
                    'updated_at' => new UTCDateTime(time() * 1000)
                ]]
            );

            if ($updateResult->getModifiedCount() > 0) {
                $message = 'Card activated and PIN set successfully!';
                $message_type = 'success';
            } else {
                $message = 'No changes made. Card not found for this user or already active/PIN set.';
                $message_type = 'info';
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error setting PIN: " . $e->getMessage());
            $message = "Error processing your request (DB error). Please try again later.";
            $message_type = 'error';
        }
    }
}

// Fetch user's cards for display
$cards_to_manage = [];
try {
    $cursor = $bankCardsCollection->find(
        ['user_id' => $user_id_obj],
        ['sort' => ['is_active' => 1, 'created_at' => -1]]
    );

    foreach ($cursor as $card) {
        $card['_id'] = (string)$card['_id'];
        $card['display_card_number'] = '**** **** **** ' . substr($card['card_number'], -4);
        $cards_to_manage[] = $card;
    }
} catch (MongoDBException $e) {
    error_log("MongoDB Error fetching cards for PIN management: " . $e->getMessage());
    $message = "Error loading cards for management.";
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank Admin - Manage User Cards</title>
    <link rel="stylesheet" href="../../style.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        .admin-page-container { max-width: 900px; margin: 40px auto; padding: 20px; background-color: #f4f7f9; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .admin-page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #e1e8ed; padding-bottom: 15px; }
        .admin-page-header h1 { font-size: 2em; color: #004d99; }
        .card-management-section { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card-management-section h2 { text-align: center; color: #333; margin-bottom: 20px; }
        .card-select-container, .pin-form-container { margin-bottom: 20px; }
        .card-select-container label, .pin-form-container label { font-weight: bold; color: #555; margin-bottom: 5px; display: block; }
        .card-select-container select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; }
        .pin-form-container input { width: calc(100% - 22px); padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; letter-spacing: 2px; }
        .pin-form-container button { width: 100%; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; margin-top: 10px; }
        .pin-form-container button:hover { background-color: #0056b3; }
        .message.success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .message { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="admin-page-container">
        <header class="admin-page-header">
            <h1>Manage Cards for User: <?php echo htmlspecialchars($user_doc['first_name'] . ' ' . $user_doc['last_name']); ?></h1>
            <a href="manage_users.php" class="button-secondary">Back to Manage Users</a>
        </header>

        <section class="card-management-section">
            <?php if (!empty($message)): ?>
                <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if (empty($cards_to_manage)): ?>
                <p style="text-align: center;">This user has no cards to manage.</p>
            <?php else: ?>
                <form action="activate_card.php?user_id=<?php echo htmlspecialchars($user_id_str); ?>" method="POST">
                    <div class="card-select-container">
                        <label for="card_id">Select a Card to Activate or Reset PIN:</label>
                        <select id="card_id" name="card_id" required>
                            <option value="">-- Select a Card --</option>
                            <?php foreach ($cards_to_manage as $card): ?>
                                <option value="<?php echo htmlspecialchars($card['_id']); ?>"
                                        <?php echo (isset($_POST['card_id']) && $_POST['card_id'] === $card['_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($card['card_network'] . ' ' . $card['card_type'] . ' (' . $card['display_card_number'] . ') - ' . ($card['is_active'] ? 'Active' : 'Inactive')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pin-form-container">
                        <div class="form-group">
                            <label for="new_pin">Enter New 4-Digit PIN:</label>
                            <input type="password" id="new_pin" name="new_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_pin">Confirm New 4-Digit PIN:</label>
                            <input type="password" id="confirm_pin" name="confirm_pin" minlength="4" maxlength="4" pattern="[0-9]{4}" placeholder="••••" required>
                        </div>
                        <button type="submit">Activate Card & Set PIN</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>