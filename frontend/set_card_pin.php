<?php
session_start();
// Adjust path dynamically for Config.php and functions.php
require_once dirname(__DIR__, 2) . '/Config.php';
require_once dirname(__DIR__, 2) . '/functions.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Define userId early for consistent use
$userId = $_SESSION['user_id'];
$userObjectId = new ObjectId($userId); // User's ObjectId

// Initialize variables for messages
$message = '';
$message_type = '';

// Retrieve and clear session messages from previous redirects (e.g., from my_cards.php)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['message_type'])) {
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message_type']);
}

$card_id = $_GET['card_id'] ?? ''; // Get card ID from URL

// Establish MongoDB connection
$mongoClient = null;
try {
    $mongoClient = new Client(MONGODB_CONNECTION_URI);
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $bankCardsCollection = $database->selectCollection('bank_cards');
} catch (Exception $e) {
    error_log("ERROR: Could not connect to MongoDB. " . $e->getMessage());
    // Store error in session and redirect for critical database connection failure
    $_SESSION['message'] = "Database connection error. Please try again later.";
    $_SESSION['message_type'] = 'error';
    header('Location: my_cards.php');
    exit;
}

$card = null;

try {
    if (!empty($card_id)) {
        $cardObjectId = new ObjectId($card_id);
        // Fetch the card, ensuring it belongs to the logged-in user and PIN is NOT yet set (null)
        $card = $bankCardsCollection->findOne([
            '_id' => $cardObjectId,
            'user_id' => $userObjectId, // Crucial for security: ensure card belongs to the user
            'pin_hashed' => null // Only allow setting PIN if it's currently null
        ]);

        if (!$card) {
            // Card not found, or PIN has already been set, or it does not belong to user
            $_SESSION['message'] = 'Card not found, or PIN has already been set for this card, or it does not belong to your account.';
            $_SESSION['message_type'] = 'error';
            header('Location: my_cards.php'); // Redirect back to my_cards.php
            exit;
        }
    } else {
        $_SESSION['message'] = 'No card ID provided.';
        $_SESSION['message_type'] = 'error';
        header('Location: my_cards.php'); // Redirect back to my_cards.php
        exit;
    }
} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    // Catch specific error for invalid ObjectId format
    $_SESSION['message'] = 'Invalid card ID format provided.';
    $_SESSION['message_type'] = 'error';
    header('Location: my_cards.php');
    exit;
} catch (Exception $e) {
    error_log("Error fetching card details in set_card_pin.php for user " . $userId . ": " . $e->getMessage());
    $_SESSION['message'] = 'Error fetching card details. Please try again.';
    $_SESSION['message_type'] = 'error';
    header('Location: my_cards.php');
    exit;
}

// Handle POST request for setting the PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // This check is now mostly redundant if the initial GET request validation redirects,
    // but kept as an extra safeguard in case of direct POST attempts or race conditions.
    if (!$card) {
        $message = 'Invalid card operation. Please go back to My Cards and try again.';
        $message_type = 'error';
    } else {
        $new_pin = trim($_POST['new_pin'] ?? '');
        $confirm_pin = trim($_POST['confirm_pin'] ?? '');

        if (empty($new_pin) || empty($confirm_pin)) {
            $message = 'Please enter and confirm your new PIN.';
            $message_type = 'error';
        } elseif ($new_pin !== $confirm_pin) {
            $message = 'PINs do not match.';
            $message_type = 'error';
        } elseif (!preg_match('/^\d{4}$/', $new_pin)) { // Assuming 4-digit PIN
            $message = 'PIN must be exactly 4 digits.';
            $message_type = 'error';
        } else {
            try {
                // Hash the PIN before storing! IMPORTANT SECURITY MEASURE
                $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);

                // Update the card document with the hashed PIN
                $updateResult = $bankCardsCollection->updateOne(
                    // Double-check ownership and ensure PIN is still null before updating
                    ['_id' => $cardObjectId, 'user_id' => $userObjectId, 'pin_hashed' => null], // Corrected to pin_hashed
                    ['$set' => ['pin_hashed' => $hashed_pin, 'updated_at' => new UTCDateTime()]]
                );

                if ($updateResult->getModifiedCount() === 1) {
                    $_SESSION['message'] = 'Your card PIN has been set successfully!';
                    $_SESSION['message_type'] = 'success';
                    header('Location: my_cards.php'); // Redirect after successful PIN set
                    exit;
                } else {
                    // This scenario means the update didn't happen (e.g., PIN was set by another process
                    // between findOne and updateOne, or card ownership changed, or no match found)
                    $message = 'Failed to set PIN. It might already be set, or an error occurred. Please try again from My Cards.';
                    $message_type = 'error';
                }
            } catch (Exception $e) {
                error_log("Error setting card PIN for user " . $userId . ": " . $e->getMessage()); // Corrected $userId
                $message = 'A database error occurred while trying to set the PIN. Please try again later.';
                $message_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Card PIN - HomeTown Bank</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="set_card_pin.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="user-dashboard-container">
        <div class="user-header">
        <img src="<?php echo BASE_URL; ?>/images/hometown_bank_logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>Set Card PIN</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h2 class="section-header">Set Your Bank Card PIN</h2>
            <?php if (!empty($message)): ?>
                <p class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if ($card): // Only show form if a valid card is found and PIN is null ?>
                <form action="set_card_pin.php?card_id=<?php echo htmlspecialchars($card_id); ?>" method="POST" class="form-standard">
                    <div class="form-group">
                        <label for="new_pin">New 4-Digit PIN</label>
                        <input type="password" id="new_pin" name="new_pin"
                               pattern="\d{4}" maxlength="4" required
                               title="PIN must be exactly 4 digits"
                               autocomplete="new-password" inputmode="numeric">
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Confirm New PIN</label>
                        <input type="password" id="confirm_pin" name="confirm_pin"
                               pattern="\d{4}" maxlength="4" required
                               title="PIN must be exactly 4 digits"
                               autocomplete="new-password" inputmode="numeric">
                    </div>
                    <button type="submit" class="button-primary">Set PIN</button>
                </form>
            <?php else: ?>
                <p>Unable to display the PIN setting form. Please ensure you are trying to set a PIN for a valid card that does not already have one set.</p>
            <?php endif; ?>
            <p><a href="my_cards.php" class="back-link">&larr; Back to My Cards</a></p>
        </div>
    </div>
</body>
</html>

<style>
/* Basic styling for the PIN form */
body {
    font-family: 'Roboto', sans-serif;
    background-color: #f4f7f6;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}
.user-header { /* Example user header */
    background-color: #004494; /* Darker blue */
    color: white;
    padding: 15px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    width: 100%;
}
.user-header .logo { height: 40px; }
.user-header h2 { margin: 0; font-size: 1.8em; }
.user-header .logout-button {
    background-color: #ffcc29;
    color: #004494;
    padding: 8px 15px;
    border-radius: 5px;
    text-decoration: none;
    font-weight: bold;
    transition: background-color 0.3s ease;
}
.user-header .logout-button:hover { background-color: #e0b821; }

.dashboard-content {
    padding: 30px;
    width: 100%;
    max-width: 600px; /* Smaller max-width for PIN form */
    margin: 20px auto;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    box-sizing: border-box;
}
.section-header {
    color: #333;
    margin-bottom: 20px;
    font-size: 1.8em;
    border-bottom: 2px solid #eee;
    padding-bottom: 10px;
}
.form-standard .form-group {
    margin-bottom: 15px;
}
.form-standard label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: #333;
}
.form-standard input[type="password"] {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
    font-size: 1em;
}
.form-standard button.button-primary {
    background-color: #007bff;
    color: white;
    padding: 12px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 1.1em;
    font-weight: bold;
    transition: background-color 0.3s ease;
}
.form-standard button.button-primary:hover {
    background-color: #0056b3;
}
.message { /* General message styles */
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 5px;
    font-weight: bold;
}
.message.success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}
.message.error {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}
.back-link {
    display: inline-block;
    margin-top: 20px;
    color: #004494;
    text-decoration: none;
    font-weight: bold;
    transition: color 0.3s ease;
}
.back-link:hover {
    color: #0056b3;
}
</style>
