<?php
session_start();
require_once '../../Config.php'; // Adjust path
require_once '../../functions.php'; // For MongoDB setup
use MongoDB\Client;
use MongoDB\BSON\ObjectId; // For working with MongoDB's unique IDs

// Check if the user is NOT logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php'); // Your user login page
    exit;
}

$message = '';
$message_type = '';
$user_bank_cards = [];
$userId = $_SESSION['user_id']; // Get user ID from session

$mongoClient = null;
try {
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $bankCardsCollection = $database->selectCollection('bank_cards');
    $usersCollection = $database->selectCollection('users'); // To get user name for cards

    // Convert user_id from session (string) to ObjectId
    $userObjectId = new ObjectId($userId);

    // Fetch user's name for card holder display
    $userData = $usersCollection->findOne(['_id' => $userObjectId], ['projection' => ['first_name' => 1, 'last_name' => 1]]);
    $cardHolderName = '';
    if ($userData) {
        $cardHolderName = strtoupper($userData['first_name'] . ' ' . $userData['last_name']);
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
    <link rel="stylesheet" href="../style.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
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
        .message { /* ... your message styles ... */ }

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
            <img src="../../images/logo.png" alt="HomeTown Bank Logo" class="logo">
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
                    <?php foreach ($user_bank_cards as $card):
                        $masked_card_number = substr($card['card_number'], 0, 4) . ' **** **** ' . substr($card['card_number'], -4);
                        $expiry_month_short = str_pad($card['expiry_month'], 2, '0', STR_PAD_LEFT);
                        $expiry_year_short = substr($card['expiry_year'], 2, 2); // Get last two digits of year

                        $card_logo_path = '';
                        if (strtolower($card['card_type']) === 'visa') {
                            $card_logo_path = '../../images/visa_logo.png';
                        } elseif (strtolower($card['card_type']) === 'mastercard') {
                            $card_logo_path = '../../images/mastercard_logo.png';
                        } elseif (strtolower($card['card_type']) === 'verve') {
                            $card_logo_path = '../../images/verve_logo.png';
                        }
                    ?>
                        <div class="card-display <?php echo strtolower($card['card_type']); ?>">
                            <h4>HOMETOWN BANK</h4>
                            <div class="chip"></div>
                            <div class="card-number"><?php echo $masked_card_number; ?></div>
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
                                <img src="<?php echo $card_logo_path; ?>" alt="<?php echo $card['card_type']; ?> Logo" class="card-logo">
                            <?php endif; ?>

                            <div class="pin-action">
                                <?php if (empty($card['pin'])): ?>
                                    <a href="set_card_pin.php?card_id=<?php echo htmlspecialchars($card['id']); ?>">Set Card PIN</a>
                                <?php else: ?>
                                    <button disabled>PIN Set</button>
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