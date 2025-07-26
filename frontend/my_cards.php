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
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login'); // Redirect to login route
    exit;
}

$message = ''; // For PHP-generated messages (e.g., initial DB error)
$message_type = '';
$userId = $_SESSION['user_id']; // Get user ID from session

// PHP won't fetch cards here directly, cards.js will do that via API
// However, we still need to establish the MongoDB connection for other potential uses
// and to ensure $mongoDb is available for included API files if any are added later directly.

$mongoClient = null;
$mongoDb = null; // Declare $mongoDb here to be available globally if needed by included files
try {
    $mongoClient = getMongoDBClient(); // Use your helper function to get MongoDB client
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME); // Make it available for API calls
} catch (MongoDBDriverException $e) {
    error_log("MongoDB connection error in my_cards.php: " . $e->getMessage());
    $message = "Database connection error. Please try again later.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("General error in my_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred. Please try again later.";
    $message_type = 'error';
}

// User's name for card holder display (still useful for UI elements, or if you pre-fill forms)
$cardHolderName = 'CARD HOLDER'; // Default
if ($mongoDb && isset($_SESSION['user_id'])) {
    try {
        $usersCollection = $mongoDb->selectCollection('users');
        $userData = $usersCollection->findOne(['_id' => new ObjectId($_SESSION['user_id'])], ['projection' => ['first_name' => 1, 'last_name' => 1]]);
        if ($userData) {
            $cardHolderName = strtoupper(trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')));
        }
    } catch (MongoDBDriverException $e) {
        error_log("MongoDB error fetching user name in my_cards.php: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("General error fetching user name in my_cards.php: " . $e->getMessage());
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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
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
        .card-item { /* Changed from .card-display to .card-item for JS rendering consistency */
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
        .card-item.visa { background: linear-gradient(45deg, #2a4b8d, #3f60a9); }
        .card-item.mastercard { background: linear-gradient(45deg, #eb001b, #ff5f00); }
        .card-item.amex { background: linear-gradient(45deg, #0081c7, #26a5d4); } /* Added Amex */
        .card-item.verve { background: linear-gradient(45deg, #006633, #009933); }

        .card-item h4 { margin-top: 0; font-size: 1.1em; color: rgba(255,255,255,0.8); }
        .card-item .chip {
            width: 50px; height: 35px; background-color: #d4af37; border-radius: 5px; margin-bottom: 20px;
        }
        .card-item .card-number {
            font-size: 1.6em; letter-spacing: 2px; margin-bottom: 20px; word-wrap: break-word; /* Ensure long numbers wrap */
        }
        .card-item .card-footer {
            display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em;
        }
        .card-item .card-footer .label {
            font-size: 0.7em; opacity: 0.7; margin-bottom: 3px;
        }
        .card-item .card-footer .value { font-weight: bold; }
        .card-logo-img { /* Changed from .card-logo to .card-logo-img for JS consistency */
            position: absolute; bottom: 25px; right: 25px; height: 40px; /* Adjust size as needed */
            max-width: 80px; /* Prevent logos from overflowing */
        }
        .card-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: bold;
        }
        .card-status.active {
            background-color: #28a745; /* Green */
            color: white;
        }
        .card-status.inactive {
            background-color: #dc3545; /* Red */
            color: white;
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
            .card-item {
                width: 90%; /* Make cards take more width on small screens */
                max-width: 350px; /* But don't exceed max width */
            }
        }

        /* Order Card Form Styles */
        .order-card-section {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #eee;
        }
        .order-card-section h3 {
            font-size: 1.5em;
            color: #333;
            margin-bottom: 20px;
        }
        .order-card-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .order-card-form select,
        .order-card-form input[type="text"],
        .order-card-form input[type="email"],
        .order-card-form input[type="submit"],
        .order-card-form button {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
            box-sizing: border-box; /* Include padding in element's total width and height */
        }
        .order-card-form select:focus,
        .order-card-form input[type="text"]:focus,
        .order-card-form input[type="email"]:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.25);
        }
        .order-card-form button[type="submit"] {
            background-color: #28a745; /* Green submit button */
            color: white;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .order-card-form button[type="submit"]:hover {
            background-color: #218838;
        }
        .order-card-form button[type="submit"]:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        
        /* Message Box Overlay Styles */
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

        .message-box-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .message-box-content {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
            position: relative;
        }

        .message-box-base {
            padding: 20px; /* Base padding */
            border-radius: 8px; /* Base border radius */
            margin-bottom: 20px;
        }

        .message-box-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message-box-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message-box-overlay button {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 20px;
            transition: background-color 0.3s ease;
        }

        .message-box-overlay button:hover {
            background-color: #0056b3;
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
            <h2 class="section-header">Your Active Bank Cards</h2>
            <?php if (!empty($message)): // Display PHP-generated messages ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <p id="cardsLoadingMessage" style="text-align: center; display: none;">Loading your cards...</p>
            <p id="noCardsMessage" style="text-align: center; display: none;">You don't have any active bank cards yet.</p>

            <div id="userCardList" class="card-container">
                </div>
            
            <p style="text-align: center; margin-top: 30px;">
                <a href="<?php echo BASE_URL; ?>/dashboard" class="back-link">&larr; Back to Dashboard</a>
            </p>

            <div class="order-card-section">
                <h3 class="section-header">Order a New Bank Card</h3>
                <form id="orderCardForm" class="order-card-form">
                    <div class="form-group">
                        <label for="accountId">Link to Account:</label>
                        <select id="accountId" name="account_id" required>
                            <option value="">-- Loading Accounts --</option>
                            </select>
                    </div>

                    <div class="form-group">
                        <label for="cardNetwork">Card Network:</label>
                        <select id="cardNetwork" name="card_network" required>
                            <option value="">Select Card Type</option>
                            <option value="Visa">Visa</option>
                            <option value="Mastercard">Mastercard</option>
                            <option value="Verve">Verve</option>
                            <option value="Amex">Amex</option>
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
                        <input type="text" id="deliveryAddress" name="delivery_address" placeholder="e.g., 123 Main St, City, State, Zip" required>
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
        const PHP_BASE_URL = '<?php echo rtrim(BASE_URL, '/'); ?>/';
        const FRONTEND_BASE_URL = '<?php echo rtrim(BASE_URL, '/'); ?>/frontend/'; // If you need a separate frontend base
    </script>
    
    <script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>