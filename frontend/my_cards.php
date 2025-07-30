<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\my_cards.php (or bank_cards.php)

// For development:
ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure session is started.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include Config.php and autoload for MongoDB classes
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../vendor/autoload.php';
// Include functions.php for getMongoDBClient() and getCollection()
require_once __DIR__ . '/../functions.php'; // Make sure this path is correct

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] != true || !isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Redirect to /login as per your router
    exit;
}

$user_id = $_SESSION['user_id']; // This is the string representation of ObjectId
$user_full_name = $_SESSION['user_full_name'] ?? 'Bank Customer'; // Fallback
$user_email = $_SESSION['user_email'] ?? 'user@example.com'; // Fallback

$message = '';
$message_type = '';

$mongoClient = null;
$mongoDb = null;
$usersCollection = null;
$bankCardsCollection = null;

try {
    // Establish MongoDB connection (using getMongoDBClient and selectDatabase from functions.php)
    $mongoClient = getMongoDBClient();
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    // Get collections (using getCollection from functions.php)
    $usersCollection = getCollection('users');
    $bankCardsCollection = getCollection('bank_cards');

} catch (MongoDBDriverException $e) {
    error_log("CRITICAL ERROR: MongoDB connection failed in my_cards.php: " . $e->getMessage());
    die("<h1>Database connection error. Please try again later.</h1>");
} catch (Exception $e) {
    error_log("CRITICAL ERROR: General error during MongoDB setup in my_cards.php: " . $e->getMessage());
    die("<h1>An unexpected error occurred. Please try again later.</h1>");
}

$userObjectId = null;
try {
    $userObjectId = new ObjectId($user_id);
} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    error_log("Invalid user ID format in session for my_cards.php: " . $e->getMessage());
    header('Location: ' . rtrim(BASE_URL, '/') . '/login?error=invalid_session_id'); // Redirect to /login
    exit;
}

// --- Card Activation/PIN Setup Logic ---
// Handle card activation/PIN setup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate_card') {
    $card_id_str = trim($_POST['card_id'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    $confirm_pin = trim($_POST['confirm_pin'] ?? '');

    try {
        if (empty($card_id_str) || !($card_id_obj = new ObjectId($card_id_str))) {
            throw new Exception('Invalid card ID provided.');
        }

        // Verify that the card being activated belongs to the current user and is pending
        $card_to_activate = $bankCardsCollection->findOne([
            '_id' => $card_id_obj,
            'user_id' => $userObjectId,
            'status' => 'pending_activation',
            'is_active' => false
        ]);

        if (!$card_to_activate) {
            throw new Exception('Card not found or not eligible for activation. It might already be active or pending approval from administration.');
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
        } else {
            $message = 'Failed to activate card. It might already be active or an internal error occurred.';
            $message_type = 'error';
        }

    } catch (MongoDBDriverException $e) {
        error_log("MongoDB operation error during card activation: " . $e->getMessage());
        $message = 'Database error during card activation. Please try again later.';
        $message_type = 'error';
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Re-fetch user's pending card(s) and all cards after any POST operations to reflect current state
$pending_card = null;
$user_cards = [];
try {
    $pending_card = $bankCardsCollection->findOne([
        'user_id' => $userObjectId,
        'status' => 'pending_activation',
        'is_active' => false
    ]);

    $cursor = $bankCardsCollection->find(['user_id' => $userObjectId]);
    foreach ($cursor as $cardDoc) {
        $card = (array) $cardDoc;
        $card['card_number_display'] = '**** **** **** ' . substr($card['card_number'], -4);
        $card['expiry_date_display'] = str_pad($card['expiry_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($card['expiry_year'], -2);
        $user_cards[] = $card;
    }
} catch (MongoDBDriverException $e) {
    error_log("Error fetching cards for user " . $user_id . " after activation attempt: " . $e->getMessage());
    // Only set message if it wasn't already set by a more specific error above
    if (empty($message)) {
        $message = "Database error fetching card details for display. Please try again.";
        $message_type = 'error';
    }
}

// --- Check if user can order a new card ---
$can_order_new_card = true; // Assume they can unless conditions prevent it
// Example: Prevent ordering if they have too many active cards or a pending order
$active_cards_count = 0;
foreach ($user_cards as $card) {
    if ($card['status'] === 'active') {
        $active_cards_count++;
    }
}

// You can define your own rules here.
// For example, if you only allow 2 active cards and no pending activations:
if ($active_cards_count >= 2 || $pending_card) {
    $can_order_new_card = false;
}
// You might also check if there's a recent order for a new card in the past X days.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Card Activation & Management</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/bank_cards.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General Layout */
        body {
            font-family: 'Roboto', sans-serif; /* Assuming Roboto for general text */
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .header {
            background-color: #004494; /* Dark blue */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .header .logo img {
            height: 40px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.8em;
        }
        .header-nav .homepage {
            background-color: #ffcc29; /* Heritage accent */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .header-nav .homepage:hover {
            background-color: #e0b821;
        }

        .main-content {
            padding: 30px;
            width: 100%;
            max-width: 900px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box;
        }

        /* Messages */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
        }
        .message.success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border-color: #bee5eb; }


        /* Section Styling */
        .activation-section, .all-cards-section, .order-card-section {
            margin-bottom: 40px;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .activation-section h2, .all-cards-section h2, .order-card-section h2 {
            text-align: center;
            color: #333;
            margin-bottom: 25px;
            font-size: 2em;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input[type="password"],
        .form-group input[type="text"],
        .form-group select {
            width: calc(100% - 20px);
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
        }
        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease;
            width: 100%;
            display: block; /* Ensure it takes full width */
            text-align: center;
        }
        .button-primary:hover {
            background-color: #0056b3;
        }
        .no-pending-message, .order-card-info {
            text-align: center;
            padding: 20px;
            color: #555;
            font-size: 1.1em;
        }

        /* Card Display (reusing styles from bank_cards.php for consistency) */
        .card-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            justify-content: center;
            padding: 20px;
        }
        .bank-card-display {
            position: relative;
            background: linear-gradient(135deg, #004494, #0056b3);
            border-radius: 15px;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            color: #fff;
            padding: 20px 25px;
            aspect-ratio: 1.585 / 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'Space Mono', monospace;
            overflow: hidden;
            box-sizing: border-box;
        }
        .bank-card-display.visa { background: linear-gradient(135deg, #1A3B6F, #2A569E); }
        .bank-card-display.mastercard { background: linear-gradient(135deg, #EB001B, #F79E1B); }
        .bank-card-display.verve { background: linear-gradient(135deg, #009245, #8BC34A); }
        .bank-card-display.amex { background: linear-gradient(135deg, #2E7D32, #66BB6A); } /* Example for Amex */

        .card-header-logo {
            font-size: 1.2rem;
            font-weight: 700;
            text-align: right;
            margin-bottom: 10px;
        }
        .card-network-logo {
            position: absolute;
            top: 20px;
            left: 25px;
            width: 70px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 5px rgba(0,0,0,0.3));
        }
        .card-chip {
            width: 50px;
            height: 40px;
            background-color: #d4af37;
            border-radius: 6px;
            position: absolute;
            top: 90px;
            left: 25px;
            box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.3);
        }
        .card-number {
            font-size: 1.8em;
            letter-spacing: 0.15em;
            text-align: center;
            margin-top: auto;
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
        .card-status.active { background-color: rgba(60, 179, 113, 0.8); color: white; }
        .card-status.pending_activation { background-color: rgba(255, 165, 0, 0.8); color: white; } /* Orange for pending */
        .card-status.inactive { background-color: rgba(255, 99, 71, 0.8); color: white; }
        .card-status.lost-stolen { background-color: rgba(220, 20, 60, 0.8); color: white; }

        @media (max-width: 768px) {
            .card-list {
                grid-template-columns: 1fr;
            }
            .bank-card-display {
                max-width: 350px;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <nav class="header-nav">
            <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="homepage">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
        </nav>
        <h1>Card Activation & Management</h1>
        <div class="logo">
            <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
        </div>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <section class="activation-section">
            <h2>Activate Your Card / Set PIN</h2>
            <?php if ($pending_card): ?>
                <p>A new card ending in ****<?php echo substr($pending_card['card_number'], -4); ?> (Type: <?php echo htmlspecialchars($pending_card['card_type']); ?>) has been issued and is awaiting your activation.</p>
                <form action="<?php echo rtrim(BASE_URL, '/') . '/my_cards'; ?>" method="POST"> <input type="hidden" name="action" value="activate_card">
                    <input type="hidden" name="card_id" value="<?php echo htmlspecialchars((string)$pending_card['_id']); ?>">

                    <div class="form-group">
                        <label for="new_pin">Set 4-Digit PIN</label>
                        <input type="password" id="new_pin" name="pin" maxlength="4" pattern="\d{4}" title="Please enter a 4-digit number" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Confirm PIN</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" maxlength="4" pattern="\d{4}" title="Please confirm your 4-digit PIN" required>
                    </div>
                    <button type="submit" class="button-primary">Activate Card & Set PIN</button>
                </form>
            <?php else: ?>
                <p class="no-pending-message">You currently have no bank cards awaiting activation.</p>
            <?php endif; ?>
        </section>

        <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

        <section class="order-card-section">
            <h2>Order a New Bank Card</h2>
            <?php if ($can_order_new_card): ?>
                <p class="order-card-info">Need a new card? Click the button below to request one. Your card will be issued and appear here for activation soon.</p>
                <button type="button" id="orderNewCardBtn" class="button-primary">Order New Card</button>
                <div id="orderCardMessage" class="message" style="display: none; margin-top: 20px;"></div>
            <?php else: ?>
                <p class="order-card-info message info">
                    You cannot order a new card at this time.
                    <?php if ($pending_card): ?>
                        You have a card awaiting activation. Please activate it first.
                    <?php elseif ($active_cards_count >= 2): ?>
                        You have reached the maximum number of active cards allowed.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </section>

        <hr style="margin: 50px 0; border: 0; border-top: 1px solid #eee;">

        <section class="all-cards-section">
            <h2>All Your Bank Cards</h2>
            <?php if (!empty($user_cards)): ?>
                <div class="card-list">
                    <?php foreach ($user_cards as $card): ?>
                        <div class="bank-card-display <?php echo strtolower($card['card_type']); ?>">
                            <?php
                                $network_logo_path = '';
                                if (strtolower($card['card_type']) === 'visa') {
                                    $network_logo_path = 'https://i.imgur.com/x69sY3k.png'; // Visa Logo
                                } elseif (strtolower($card['card_type']) === 'mastercard') {
                                    $network_logo_path = 'https://i.imgur.com/139Suh3.png'; // MasterCard Logo
                                } elseif (strtolower($card['card_type']) === 'verve') {
                                    $network_logo_path = 'https://i.imgur.com/dhW5pdv.png'; // Verve Logo
                                }
                                // Add more conditions for other networks like Amex if you support them
                                elseif (strtolower($card['card_type']) === 'amex') {
                                    $network_logo_path = 'https://i.imgur.com/YourAmexLogo.png'; // Placeholder for Amex - REPLACE WITH ACTUAL
                                }
                            ?>
                            <?php if ($network_logo_path): ?>
                                <img src="<?php echo htmlspecialchars($network_logo_path); ?>" alt="<?php echo htmlspecialchars($card['card_type']); ?> Logo" class="card-network-logo">
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
                <p class="no-pending-message">You do not have any bank cards registered with us yet.</p>
            <?php endif; ?>
        </section>
    </main>

    <script>
        document.getElementById('orderNewCardBtn').addEventListener('click', async () => {
            const orderCardMessageDiv = document.getElementById('orderCardMessage');
            orderCardMessageDiv.style.display = 'none'; // Hide previous messages

            try {
                // Fetch to your API endpoint
                const response = await fetch('<?php echo rtrim(BASE_URL, '/') . '/api/order_card'; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    // Send user_id if your API needs it explicitly, though session should handle it
                    body: JSON.stringify({ user_id: '<?php echo $user_id; ?>' })
                });

                const result = await response.json();

                orderCardMessageDiv.classList.remove('success', 'error');
                orderCardMessageDiv.style.display = 'block';

                if (response.ok && result.success) {
                    orderCardMessageDiv.classList.add('success');
                    orderCardMessageDiv.textContent = result.message || 'Card order placed successfully! Please refresh to see your new pending card.';
                    // Optionally, reload the page or update the card list via AJAX
                    setTimeout(() => {
                        window.location.reload(); // Simple reload to show the new card
                    }, 2000);
                } else {
                    orderCardMessageDiv.classList.add('error');
                    orderCardMessageDiv.textContent = result.message || 'Failed to place card order. Please try again.';
                }
            } catch (error) {
                console.error('Error ordering card:', error);
                orderCardMessageDiv.classList.add('error');
                orderCardMessageDiv.textContent = 'An unexpected error occurred while ordering the card.';
                orderCardMessageDiv.style.display = 'block';
            }
        });
    </script>
</body>
</html>