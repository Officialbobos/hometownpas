<?php
session_start();
require_once '../../Config.php'; // Adjust path based on your actual file structure
require_once '../../vendor/autoload.php'; // Include Composer's autoloader for MongoDB

// Check if the user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ../index.php'); // Redirect to login page if not logged in
    exit;
}

$user_id = $_SESSION['user_id']; // Get the logged-in user's ID

$user_cards = [];
$message = '';
$message_type = '';

// --- MongoDB Connection ---
try {
    // MONGO_URI and MONGO_DB_NAME should be defined in Config.php
    $client = new MongoDB\Client(MONGO_URI);
    $database = $client->selectDatabase(MONGO_DB_NAME);
    $bankCardsCollection = $database->selectCollection('bank_cards');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later.");
}

// Fetch all active cards for the logged-in user from the bank_cards collection
try {
    // Assuming user_id in MongoDB is stored as a string or ObjectId.
    // If it's stored as ObjectId, convert it: new MongoDB\BSON\ObjectId($user_id)
    $mongo_user_id = $user_id; // Use as is if stored as string, otherwise convert to ObjectId

    // Fetch documents where user_id matches and is_active is true, ordered by created_at descending
    $cursor = $bankCardsCollection->find(
        ['user_id' => $mongo_user_id, 'is_active' => true],
        ['sort' => ['created_at' => -1]]
    );

    foreach ($cursor as $cardDoc) {
        $card = (array) $cardDoc; // Convert MongoDB Document to array

        // MongoDB stores dates as UTCDateTime objects, convert to PHP DateTime for formatting
        if (isset($card['created_at']) && $card['created_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $card['created_at'] = $card['created_at']->toDateTime()->format('Y-m-d H:i:s');
        }
        $user_cards[] = $card;
    }
} catch (Exception $e) {
    $message = "Error fetching cards: " . $e->getMessage();
    $message_type = 'error';
    error_log("Error fetching cards from MongoDB: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - My Cards</title>
    <link rel="stylesheet" href="../style.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* This grid helps arrange multiple cards neatly */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            padding: 20px;
            justify-content: center; /* Center items in the grid */
        }

        /* Card display styles (re-used from admin, with minor adjustments for grid layout) */
        .card-display {
            color: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            position: relative;
            font-family: 'Roboto Mono', monospace;
            background: linear-gradient(45deg, #004d40, #00796b); /* Default fallback */
            aspect-ratio: 1.6 / 1; /* Standard card aspect ratio (width:height) */
            max-width: 400px; /* Max width for individual card in the grid */
            margin: auto; /* Center card within its grid cell */
            display: flex; /* Use flexbox for internal layout */
            flex-direction: column; /* Stack elements vertically */
            justify-content: space-between; /* Distribute space */
        }
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
        .card-info-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            width: 100%; /* Ensure it takes full width of the card */
            margin-top: auto; /* Pushes content to the bottom */
        }
        .card-info-column {
            display: flex;
            flex-direction: column;
        }

        .card-display .card-footer { /* Renamed to card-info-column for better structure */
            display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.9em;
        }
        .card-display .card-footer .label,
        .card-display .card-info-column .label { /* Apply label style to new column */
            font-size: 0.7em; opacity: 0.7; margin-bottom: 3px;
        }
        .card-display .card-footer .value,
        .card-display .card-info-column .value { /* Apply value style to new column */
            font-weight: bold;
        }
        .card-logo {
            position: absolute; bottom: 25px; right: 25px; height: 40px;
        }
        .no-cards-message {
            text-align: center;
            padding: 20px;
            background-color: #fff3cd; /* Light yellow background */
            border: 1px solid #ffeeba;
            border-radius: 8px;
            color: #856404; /* Dark yellow text */
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>My Bank Cards</h2>
            <a href="../logout.php" class="logout-button">Logout</a> </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (empty($user_cards)): ?>
                <div class="no-cards-message">
                    <p>You currently have no active bank cards linked to your account.</p>
                    <p>Please contact bank administration if you believe this is an error or to request a new card.</p>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($user_cards as $card):
                        // Mask all but the last 4 digits for security
                        $masked_card_number = '**** **** **** ' . substr($card['card_number'] ?? '0000', -4);
                        // Format expiry year to 2 digits for display
                        $expiry_year_short = substr($card['expiry_year'] ?? '00', -2);

                        // Format created_at date for display (assuming it's now a string from MongoDB fetch logic)
                        $issue_date_formatted = 'N/A';
                        if (isset($card['created_at'])) {
                            try {
                                $dt = new DateTime($card['created_at']);
                                $issue_date_formatted = $dt->format('Y-m-d');
                            } catch (Exception $e) {
                                error_log("Error formatting date: " . $e->getMessage());
                                // Fallback to N/A or raw value if date parsing fails
                            }
                        }
                    ?>
                        <div class="card-display <?php echo strtolower(htmlspecialchars($card['card_type'] ?? 'default')); ?>">
                            <h4>HERITAGE BANK</h4>
                            <div class="chip"></div>
                            <div class="card-number"><?php echo htmlspecialchars($masked_card_number); ?></div>

                            <div class="card-info-bottom">
                                <div class="card-info-column">
                                    <div class="label">CARD HOLDER</div>
                                    <div class="value"><?php echo htmlspecialchars($card['card_holder_name'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="card-info-column">
                                    <div class="label">ISSUE DATE</div>
                                    <div class="value"><?php echo htmlspecialchars($issue_date_formatted); ?></div>
                                </div>
                                <div class="card-info-column">
                                    <div class="label">EXPIRES</div>
                                    <div class="value"><?php echo htmlspecialchars(($card['expiry_month'] ?? 'MM') . '/' . $expiry_year_short); ?></div>
                                </div>
                            </div>
                            <img src="../../images/<?php echo strtolower(htmlspecialchars($card['card_type'] ?? 'default')); ?>_logo.png" alt="<?php echo htmlspecialchars($card['card_type'] ?? 'Card'); ?> Logo" class="card-logo">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p><a href="../dashboard.php" class="back-link">&larr; Back to Dashboard</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>