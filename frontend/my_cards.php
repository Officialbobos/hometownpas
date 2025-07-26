<?php
session_start();
require_once '/../Config.php'; // Adjust path based on your directory structure
require_once '/../functions.php'; // For MongoDB setup and other helpers (like sendEmail if used)

use MongoDB\Client;
use MongoDB\BSON\ObjectId; // For working with MongoDB's unique IDs
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // Specific MongoDB exceptions

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Use BASE_URL for consistency
    exit;
}

$message = '';
$message_type = '';
$user_bank_cards = [];
$userId = $_SESSION['user_id']; // Get user ID from session

$mongoClient = null;
try {
    $client = getMongoDBClient(); // Use your helper function to get MongoDB client
    $database = $client->selectDatabase(MONGODB_DB_NAME); // Use MONGO_DB_NAME from Config.php
    $bankCardsCollection = $database->selectCollection('bank_cards');
    $usersCollection = $database->selectCollection('users'); // To get user name for cards

    // Convert user_id from session (string) to ObjectId
    $userObjectId = new ObjectId($userId);

    // Fetch user's name for card holder display
    $userData = $usersCollection->findOne(['_id' => $userObjectId], ['projection' => ['first_name' => 1, 'last_name' => 1]]);
    $cardHolderName = '';
    if ($userData) {
        $cardHolderName = strtoupper(trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')));
    } else {
        error_log("User with ID " . $userId . " not found in database for my_cards.php.");
        $cardHolderName = 'CARD HOLDER'; // Fallback
    }

    // Fetch all active cards for the current user
    $cursor = $bankCardsCollection->find(['user_id' => $userObjectId, 'is_active' => true]);
    foreach ($cursor as $cardDoc) {
        $card = (array) $cardDoc;
        $card['id'] = (string) $card['_id']; // Convert ObjectId to string for HTML forms
        $user_bank_cards[] = $card;
    }

} catch (MongoDBDriverException $e) { // Catch specific MongoDB driver exceptions
    error_log("MongoDB error in my_cards.php: " . $e->getMessage());
    $message = "Database connection error. Please try again later.";
    $message_type = 'error';
} catch (Exception $e) { // Catch general exceptions (e.g., ObjectId creation)
    error_log("General error in my_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again later.";
    $message_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bank Cards - HomeTown Bank</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Re-use or adapt the card-display styles from admin panel */
        /* You might want a dedicated 'user_cards.css' for these */
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
        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Card Display Styles (copied/adapted from admin panel) */
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
            display: flex; /* Make it a flex container */
            flex-direction: column; /* Stack contents vertically */
            justify-content: space-between; /* Distribute space */
            min-height: 200px; /* Ensure consistent height */
        }
        /* Specific card network gradients (adjust these if your card_network values are different) */
        .card-display.visa { background: linear-gradient(45deg, #2a4b8d, #3f60a9); }
        .card-display.mastercard { background: linear-gradient(45deg, #eb001b, #ff5f00); }
        .card-display.amex { background: linear-gradient(45deg, #0081c7, #26a5d4); } /* Added Amex */
        .card-display.verve { background: linear-gradient(45deg, #006633, #009933); }

        .card-display h4 { margin-top: 0; font-size: 1.1em; color: rgba(255,255,255,0.8); }
        .card-display .chip {
            width: 50px; height: 35px; background-color: #d4af37; border-radius: 5px; margin-bottom: 20px;
        }
        .card-display .card-number {
            font-size: 1.6em; letter-spacing: 2px; margin-bottom: 20px; word-wrap: break-word; /* Ensure long numbers wrap */
        }
        .card-display .card-footer {
            display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em;
        }
        .card-display .card-footer .label {
            font-size: 0.7em; opacity: 0.7; margin-bottom: 3px;
        }
        .card-display .card-footer .value { font-weight: bold; }
        .card-logo {
            position: absolute; bottom: 25px; right: 25px; height: 40px; /* Adjust size as needed */
            max-width: 80px; /* Prevent logos from overflowing */
        }
        .pin-action {
            text-align: center;
            margin-top: 20px;
        }
        .pin-action button, .pin-action a {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .pin-action button:hover, .pin-action a:hover {
            background-color: #0056b3;
        }
        .pin-action button[disabled] {
            background-color: #cccccc;
            cursor: not-allowed;
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
</head>
<body>
    <div class="user-dashboard-container">
        <div class="user-header">
            <img src="<?php echo BASE_URL; ?>/images/hometown_bank_logo.png" alt="HomeTown Bank Logo" class="logo">
            <h2>My Bank Cards</h2>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h2 class="section-header">Your Active Bank Cards</h2>
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (empty($user_bank_cards)): ?>
                <p>You don't have any active bank cards yet. Please contact support if you believe this is an error, or order a new one from your <a href="<?php echo BASE_URL; ?>/user_dashboard.php">Dashboard</a>.</p>
            <?php else: ?>
                <div class="card-container">
                    <?php foreach ($user_bank_cards as $card):
                        $masked_card_number = substr($card['card_number'] ?? '0000000000000000', 0, 4) . ' **** **** ' . substr($card['card_number'] ?? '0000000000000000', -4);
                        $expiry_month_short = str_pad($card['expiry_month'] ?? '', 2, '0', STR_PAD_LEFT);
                        $expiry_year_short = substr($card['expiry_year'] ?? '', 2, 2); // Get last two digits of year

                        // Determine the card network for CSS class and logo
                        $card_network_for_display = strtolower($card['card_network'] ?? 'default'); // Use 'card_network' field
                        
                        // Default logo path, useful for unknown networks or as a fallback
                        $card_logo_path = BASE_URL . '/images/card_logos/default.png'; 

                        // Assign specific logo paths based on card_network
                        if ($card_network_for_display === 'visa') {
                            $card_logo_path = BASE_URL . '/images/card_logos/visa.png';
                        } elseif ($card_network_for_display === 'mastercard') {
                            $card_logo_path = BASE_URL . '/images/card_logos/mastercard.png';
                        } elseif ($card_network_for_display === 'verve') {
                            $card_logo_path = BASE_URL . '/images/card_logos/verve.png';
                        } elseif ($card_network_for_display === 'amex') { // Assuming you might have Amex cards
                            $card_logo_path = BASE_URL . '/images/card_logos/amex.png';
                        }
                        
                        // Add a class for the card network for styling the background gradient
                        $card_network_css_class = $card_network_for_display;
                    ?>
                        <div class="card-display <?php echo htmlspecialchars($card_network_css_class); ?>">
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
                            <img src="<?php echo htmlspecialchars($card_logo_path); ?>" alt="<?php echo htmlspecialchars($card['card_network'] ?? 'Card'); ?> Logo" class="card-logo">
                            
                            <div class="pin-action">
                                <?php if (empty($card['pin']) || !isset($card['pin'])): // Check if PIN is null or not set ?>
                                    <a href="<?php echo BASE_URL; ?>/set_card_pin.php?card_id=<?php echo htmlspecialchars($card['id']); ?>">Set Card PIN</a>
                                <?php else: ?>
                                    <button disabled>PIN Set</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p style="text-align: center; margin-top: 30px;"><a href="<?php echo BASE_URL; ?>/user_dashboard.php" class="back-link">&larr; Back to Dashboard</a></p>
        </div>
    </div>
</body>
</html>