<?php
session_start();
require_once dirname(__DIR__, 2) . '/Config.php'; // Adjust path dynamically
require_once dirname(__DIR__, 2) . '/functions.php'; // For MongoDB setup and other utilities

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php'); // Your user login page
    exit;
}

// Initialize variables for messages
$message = '';
$message_type = '';

// Retrieve and clear session messages (if any)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['message_type'])) {
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message_type']);
}

$user_bank_cards = [];
$userId = $_SESSION['user_id']; // Get user ID from session

$mongoClient = null; // Initialize to null
try {
    $mongoClient = new Client(MONGODB_CONNECTION_URI); // Use MONGO_URI if that's the constant
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $bankCardsCollection = $database->selectCollection('bank_cards');
    $usersCollection = $database->selectCollection('users'); // To get user name for cards

    // Convert user_id from session (string) to ObjectId
    $userObjectId = new ObjectId($userId);

    // Fetch user's name for card holder display
    $userData = $usersCollection->findOne(['_id' => $userObjectId], ['projection' => ['first_name' => 1, 'last_name' => 1]]);
    $cardHolderName = '';
    if ($userData) {
        // Use trim to handle cases where one name might be empty, and strtoupper for consistency
        $cardHolderName = trim(strtoupper(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')));
    }

    // Fetch all active cards for the current user
    $cursor = $bankCardsCollection->find(['user_id' => $userObjectId, 'is_active' => true]);
    foreach ($cursor as $cardDoc) {
        $card = (array) $cardDoc;
        $card['id'] = (string) $card['_id']; // Convert ObjectId to string for HTML forms
        $user_bank_cards[] = $card;
    }

} catch (Exception $e) {
    error_log("ERROR: Could not connect to MongoDB or fetch cards. " . $e->getMessage());
    $message = "Database error. Please try again later.";
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bank Cards - HomeTown Bank</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="user_cards.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="user-dashboard-container">
        <div class="user-header">
            <img src="/images/logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>My Bank Cards</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h2 class="section-header">Your Active Bank Cards</h2>
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (empty($user_bank_cards)): ?>
                <p>You don't have any active bank cards yet. Please contact support if you believe this is an error.</p>
            <?php else: ?>
                <div class="card-container">
                    <?php
                    // Define card type specific data (logo path, CSS class)
                    $cardTypeMap = [
                        'visa' => ['logo' => '/images/visa_logo.png', 'class' => 'visa'],
                        'mastercard' => ['logo' => '/images/mastercard_logo.png', 'class' => 'mastercard'],
                        'verve' => ['logo' => '/images/verve_logo.png', 'class' => 'verve'],
                        // Add other card types here if needed
                    ];

                    foreach ($user_bank_cards as $card):
                        $masked_card_number = substr($card['card_number'], 0, 4) . ' **** **** ' . substr($card['card_number'], -4);
                        $expiry_month_short = str_pad($card['expiry_month'], 2, '0', STR_PAD_LEFT);
                        $expiry_year_short = substr($card['expiry_year'], 2, 2); // Get last two digits of year

                        $card_type_lower = strtolower($card['card_type']);
                        $card_logo_path = $cardTypeMap[$card_type_lower]['logo'] ?? '';
                        $card_display_class = $cardTypeMap[$card_type_lower]['class'] ?? '';
                    ?>
                        <div class="card-display <?php echo htmlspecialchars($card_display_class); ?>">
                            <h4>HOMETOWN BANK</h4>
                            <div class="chip"></div>
                            <div class="card-number"><?php echo htmlspecialchars($masked_card_number); ?></div>
                            <div class="card-footer">
                                <div>
                                    <div class="label">CARD HOLDER</div>
                                    <div class="value"><?php echo htmlspecialchars($cardHolderName); ?></div>
                                </div>
                                <div>
                                    <div class="label">EXPIRES</div>
                                    <div class="value"><?php echo htmlspecialchars($expiry_month_short . '/' . $expiry_year_short); ?></div>
                                </div>
                            </div>
                            <?php if ($card_logo_path): ?>
                                <img src="<?php echo htmlspecialchars($card_logo_path); ?>" alt="<?php echo htmlspecialchars($card['card_type']); ?> Logo" class="card-logo">
                            <?php endif; ?>

                            <div class="pin-action">
                                <?php
                                // Note on PINs: Assuming 'pin' field stores a HASHED PIN or is empty if not set.
                                // NEVER store plaintext PINs in the database.
                                if (empty($card['pin'])): ?>
                                    <a href="set_card_pin.php?card_id=<?php echo htmlspecialchars($card['id']); ?>" class="pin-button">Set Card PIN</a>
                                <?php else: ?>
                                    <a href="change_card_pin.php?card_id=<?php echo htmlspecialchars($card['id']); ?>" class="pin-button pin-set">Change Card PIN</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p style="text-align: center; margin-top: 30px;"><a href="../user_dashboard.php" class="back-link">&larr; Back to Dashboard</a></p>
        </div>
    </div>
</body>
</html>

<style>
/* Save this CSS content as user_cards.css in the same directory as my_cards.php */

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
    max-width: 900px;
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
/* Basic message styles (you might have these in style.css already) */
.message {
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 5px;
    font-weight: bold;
}
.message.success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.message.error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Card Display Styles */
.card-container {
    display: flex;
    flex-wrap: wrap; /* Allow cards to wrap on smaller screens */
    gap: 30px; /* Space between cards */
    justify-content: center; /* Center cards */
    margin-top: 30px;
}
.card-display {
    color: white;
    padding: 25px;
    border-radius: 15px;
    width: 350px; /* Fixed width for card */
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    position: relative;
    font-family: 'Roboto Mono', monospace;
    background: linear-gradient(45deg, #004d40, #00796b); /* Default */
    flex-shrink: 0; /* Prevent cards from shrinking */
}
/* Specific card type gradients */
.card-display.visa { background: linear-gradient(45deg, #2a4b8d, #3f60a9); }
.card-display.mastercard { background: linear-gradient(45deg, #eb001b, #ff5f00); }
.card-display.verve { background: linear-gradient(45deg, #006633, #009933); }

.card-display h4 { margin-top: 0; font-size: 1.1em; color: rgba(255,255,255,0.8); }
.card-display .chip {
    width: 50px; height: 35px; background-color: #d4af37; border-radius: 5px; margin-bottom: 20px;
}
.card-display .card-number {
    font-size: 1.6em; letter-spacing: 2px; margin-bottom: 20px;
}
.card-display .card-footer {
    display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em;
}
.card-display .card-footer .label {
    font-size: 0.7em; opacity: 0.7; margin-bottom: 3px;
}
.card-display .card-footer .value { font-weight: bold; }
.card-logo {
    position: absolute; bottom: 25px; right: 25px; height: 40px;
}
.pin-action {
    text-align: center;
    margin-top: 20px;
}
.pin-action .pin-button { /* General style for pin action links */
    background-color: #007bff;
    color: white;
    padding: 10px 15px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    font-weight: bold;
    transition: background-color 0.3s ease;
    display: inline-block; /* Make it behave like a block for padding/margin */
}
.pin-action .pin-button:hover {
    background-color: #0056b3;
}
.pin-action .pin-button.pin-set { /* Style for when PIN is already set (e.g., "Change PIN") */
    background-color: #28a745; /* Green for 'PIN Set' or 'Change PIN' */
}
.pin-action .pin-button.pin-set:hover {
    background-color: #218838;
}

/* Responsive adjustments for cards */
@media (max-width: 768px) {
    .card-container {
        flex-direction: column; /* Stack cards vertically on small screens */
        align-items: center;
        gap: 20px; /* Reduce gap */
    }
    .card-display {
        width: 90%; /* Make cards take more width on small screens */
        max-width: 350px; /* But don't exceed max width */
    }
}
</style>