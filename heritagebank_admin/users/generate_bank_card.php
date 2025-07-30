<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '/../../Config.php'; // Adjust path if necessary, depending on file location

// Use MongoDB PHP Library
require_once '/../../functions.php'; // This is good to have for future database operations
use MongoDB\Client;
use MongoDB\BSON\ObjectId; // For working with MongoDB's unique IDs
use MongoDB\BSON\UTCDateTime; // For handling dates

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = '';
$generated_card_info = null; // To display the mock card details
$display_account_selection = false; // Flag for the two-step process
$user_for_card_generation = null; // Stores user data after search
$accounts_for_card_generation = []; // Stores accounts for the selected user

// Establish MongoDB connection
$mongoClient = null;
try {
   $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
   $database = $client->selectDatabase(MONGODB_DB_NAME);
   $usersCollection = $database->selectCollection('users');
   $accountsCollection = $database->selectCollection('accounts');
   $bankCardsCollection = $database->selectCollection('bank_cards'); // New collection for cards

} catch (Exception $e) {
    error_log("ERROR: Could not connect to MongoDB. " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'find_user') {
        $user_identifier = trim($_POST['user_identifier'] ?? '');

        if (empty($user_identifier)) {
            $message = 'Please provide a user email or membership number.';
            $message_type = 'error';
        } else {
            try {
                // Find the user by email or membership_number
                $user_for_card_generation = $usersCollection->findOne([
                    '$or' => [
                        ['email' => $user_identifier],
                        ['membership_number' => $user_identifier]
                    ]
                ]);

                if ($user_for_card_generation) {
                    // Check if an active card already exists for this user
                    $existing_card = $bankCardsCollection->findOne(['user_id' => $user_for_card_generation['_id'], 'is_active' => true]);

                    if ($existing_card) {
                        $message = 'This user already has an active bank card. Cannot generate a new one.';
                        $message_type = 'error';
                        // Keep the form in step 1 by not setting $display_account_selection to true
                    } else {
                        // User found, now fetch their accounts
                        $user_for_card_generation = (array) $user_for_card_generation;
                        $user_id_obj = $user_for_card_generation['_id'];
                        $cursorAccounts = $accountsCollection->find(['user_id' => $user_id_obj]);

                        foreach ($cursorAccounts as $accountDoc) {
                            $account = (array) $accountDoc;
                            $account['account_id'] = (string) $account['_id'];
                            $accounts_for_card_generation[] = $account;
                        }

                        if (empty($accounts_for_card_generation)) {
                            $message = 'User found, but has no associated bank accounts. Cannot generate card.';
                            $message_type = 'error';
                        } else {
                            $display_account_selection = true; // Show the second part of the form
                            $message = 'User found. Please select an account to link the card to.';
                            $message_type = 'success';
                        }
                    }
                } else {
                    $message = 'User not found with the provided identifier.';
                    $message_type = 'error';
                }
            } catch (MongoDB\Driver\Exception\Exception $e) {
                error_log("Error finding user or accounts (MongoDB): " . $e->getMessage());
                $message = 'Database error during user lookup: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    } elseif ($action === 'generate_card') {
        $user_id_str = trim($_POST['user_id_hidden'] ?? '');
        $account_id_str = trim($_POST['account_id'] ?? '');
        $admin_created_at_str = trim($_POST['created_at'] ?? '');

        $user_id_obj = null;
        $account_id_obj = null;

        try {
            if (!empty($user_id_str)) {
                $user_id_obj = new ObjectId($user_id_str);
            }
            if (!empty($account_id_str)) {
                $account_id_obj = new ObjectId($account_id_str);
            }
        } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
            $message = 'Invalid user or account ID format. Please try again.';
            $message_type = 'error';
        }

        if (!$user_id_obj || !$account_id_obj) {
            $message = 'Invalid user or account selected. Please try again.';
            $message_type = 'error';
            if ($user_id_obj) {
                $display_account_selection = true;
                $user_for_card_generation = (array) $usersCollection->findOne(['_id' => $user_id_obj]);
                $cursorAccounts = $accountsCollection->find(['user_id' => $user_id_obj]);
                foreach ($cursorAccounts as $accountDoc) {
                    $account = (array) $accountDoc;
                    $account['account_id'] = (string) $account['_id'];
                    $accounts_for_card_generation[] = $account;
                }
            }
        } else {
            try {
                // Check again for an existing active card to prevent race conditions
                $existing_card_for_user = $bankCardsCollection->findOne(['user_id' => $user_id_obj, 'is_active' => true]);
                if ($existing_card_for_user) {
                    $message = 'This user already has an active bank card. Cannot generate a new one.';
                    $message_type = 'error';
                    // Re-fetch user/account data to keep the form populated for better UX
                    $display_account_selection = true;
                    $user_for_card_generation = (array) $usersCollection->findOne(['_id' => $user_id_obj]);
                    $cursorAccounts = $accountsCollection->find(['user_id' => $user_id_obj]);
                    foreach ($cursorAccounts as $accountDoc) {
                        $account = (array) $accountDoc;
                        $account['account_id'] = (string) $account['_id'];
                        $accounts_for_card_generation[] = $account;
                    }
                } else {
                    $user_data_for_name = $usersCollection->findOne(['_id' => $user_id_obj], [
                        'projection' => ['first_name' => 1, 'last_name' => 1]
                    ]);
                    $card_holder_name = '';
                    if ($user_data_for_name) {
                        $card_holder_name = strtoupper($user_data_for_name['first_name'] . ' ' . $user_data_for_name['last_name']);
                    }

                    if (empty($card_holder_name)) {
                        $message = 'Could not retrieve user name for card generation.';
                        $message_type = 'error';
                    } else {
                        // --- Simulate Card Generation with Type and Prefixes ---
                        $card_types = ['Visa', 'MasterCard', 'Verve'];
                        $card_type = $card_types[array_rand($card_types)];

                        $prefix = '';
                        if ($card_type === 'Visa') {
                            $prefix = '4';
                        } elseif ($card_type === 'MasterCard') {
                            $mc_prefixes = ['51', '52', '53', '54', '55'];
                            $prefix = $mc_prefixes[array_rand($mc_prefixes)];
                        } elseif ($card_type === 'Verve') {
                            $prefix = '5061';
                        }

                        $remaining_digits_length = 16 - strlen($prefix);
                        $random_digits = '';
                        for ($i = 0; $i < $remaining_digits_length; $i++) {
                            $random_digits .= mt_rand(0, 9);
                        }
                        $card_number_raw = $prefix . $random_digits;
                        $card_number_display = wordwrap($card_number_raw, 4, ' ', true);

                        $expiry_month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
                        $expiry_year_full = date('Y') + mt_rand(3, 7);
                        $expiry_year_short = date('y', strtotime($expiry_year_full . '-01-01'));
                        $cvv = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

                        $created_at_mongo = null;
                        try {
                            $dateTimeObj = new DateTime($admin_created_at_str);
                            $created_at_mongo = new UTCDateTime($dateTimeObj->getTimestamp() * 1000);
                        } catch (Exception $e) {
                            $created_at_mongo = new UTCDateTime(time() * 1000);
                            error_log("Warning: Invalid 'created_at' date format, using current time for card. " . $e->getMessage());
                        }

                        // Insert card details into the database with 'pending_activation' status
                        $insert_data = [
                            'user_id' => $user_id_obj,
                            // As per your request, the card is for the user, not just one account.
                            // However, we link it to one account for initial association.
                            'primary_account_id' => $account_id_obj,
                            'card_number' => $card_number_raw,
                            'card_type' => $card_type,
                            'expiry_month' => (int)$expiry_month,
                            'expiry_year' => (int)$expiry_year_full,
                            'card_holder_name' => $card_holder_name,
                            'is_active' => false, // Set to false for pending activation
                            'status' => 'pending_activation', // New field to track status
                            'pin' => null,
                            'created_at' => $created_at_mongo,
                            'updated_at' => new UTCDateTime(time() * 1000)
                        ];

                        $insertResult = $bankCardsCollection->insertOne($insert_data);

                        if ($insertResult->getInsertedCount() === 1) {
                            $message = "Mock {$card_type} card generated and stored successfully for " . $card_holder_name . ". It is now awaiting user activation.";
                            $message_type = 'success';
                            $generated_card_info = [
                                'holder_name' => $card_holder_name,
                                'card_number' => $card_number_display,
                                'expiry_date' => $expiry_month . '/' . $expiry_year_short,
                                'cvv' => 'XXX', // Do not show the real CVV to the admin
                                'card_type' => $card_type
                            ];
                            $_POST = array();
                            $display_account_selection = false;
                            $user_for_card_generation = null;
                            $accounts_for_card_generation = [];
                        } else {
                            $message = "Error storing card details: Card not inserted.";
                            $message_type = 'error';
                        }
                    }
                }
            } catch (MongoDB\Driver\Exception\Exception $e) {
                error_log("Error during card generation/storage (MongoDB): " . $e->getMessage());
                $message = "Database error during card generation: " . $e->getMessage();
                $message_type = 'error';
            } catch (Exception $e) {
                error_log("General error during card generation: " . $e->getMessage());
                $message = "An unexpected error occurred during card generation: " . $e->getMessage();
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
    <title>HomeTown Bank - Generate Bank Card</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* General body and container styling */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            align-items: center;
        }

        /* Dashboard Header */
        .dashboard-header {
            background-color: #004494; /* Darker blue for header */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header .logo {
            height: 40px; /* Adjust logo size */
        }

        .dashboard-header h2 {
            margin: 0;
            color: white;
            font-size: 1.8em;
        }

        .dashboard-header .logout-button {
            background-color: #ffcc29; /* Heritage accent color */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .dashboard-header .logout-button:hover {
            background-color: #e0b821;
        }

        /* Main Content Area */
        .dashboard-content {
            padding: 30px;
            width: 100%;
            max-width: 900px; /* Adjusted max-width for forms */
            margin: 20px auto; /* Center the content */
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box; /* Include padding in width */
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

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Form styling */
        .form-standard .form-group {
            margin-bottom: 15px;
        }

        .form-standard label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-standard input[type="text"],
        .form-standard input[type="email"],
        .form-standard input[type="tel"],
        .form-standard input[type="date"],
        .form-standard input[type="datetime-local"],
        .form-standard input[type="number"],
        .form-standard input[type="password"],
        .form-standard select,
        .form-standard textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
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

        .form-standard .form-group small {
            color: #666;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }

        /* General card display styles */
        .card-display {
            color: white;
            padding: 25px;
            border-radius: 15px;
            width: 320px;
            margin: 30px auto;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            position: relative;
            font-family: 'Roboto Mono', monospace;
            background: linear-gradient(45deg, #004d40, #00796b); /* Default: Dark teal gradient */
        }
        /* Specific card type gradients */
        .card-display.visa {
            background: linear-gradient(45deg, #2a4b8d, #3f60a9); /* Blue tones for Visa */
        }
        .card-display.mastercard {
            background: linear-gradient(45deg, #eb001b, #ff5f00); /* Red-orange tones for MasterCard */
        }
        .card-display.verve {
            background: linear-gradient(45deg, #006633, #009933); /* Green tones for Verve */
        }

        /* **UPDATED**: HomeTown Bank text/logo on card */
        .card-display h4 {
            margin-top: 0;
            font-size: 1.2em; /* Slightly larger for prominence */
            color: white; /* Ensure it stands out on dark backgrounds */
            text-transform: uppercase; /* Common for bank names */
            font-weight: 700; /* Make it bold */
            letter-spacing: 1px; /* Add some spacing */
            margin-bottom: 20px; /* Provide space below it */
            text-shadow: 0 1px 2px rgba(0,0,0,0.3); /* Subtle shadow for depth */
        }

        /* New: Styles for an actual bank logo image if you decide to use one */
        .bank-logo-on-card {
            position: absolute;
            top: 25px;   /* Distance from the top */
            left: 25px;  /* Distance from the left */
            height: 35px; /* Adjust size to fit well */
            object-fit: contain; /* Ensures logo scales without distortion */
            /* Uncomment the next line if your logo is dark and needs to be white on the card */
            /* filter: brightness(0) invert(1); */
        }


        .card-display .chip {
            width: 50px;
            height: 35px;
            background-color: #d4af37; /* Gold color */
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .card-display .card-number {
            font-size: 1.6em;
            letter-spacing: 2px;
            margin-bottom: 20px;
        }
        .card-display .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 0.9em;
        }
        .card-display .card-footer .label {
            font-size: 0.7em;
            opacity: 0.7;
            margin-bottom: 3px;
        }
        .card-display .card-footer .value {
            font-weight: bold;
        }
        /* **UPDATED**: Network card logo placement */
        .card-logo {
            position: absolute;
            bottom: 20px; /* Slightly adjusted for more clear space */
            right: 20px; /* Slightly adjusted for more clear space */
            height: 45px; /* Slightly larger for prominence */
            filter: drop-shadow(0 2px 2px rgba(0,0,0,0.2)); /* Add a subtle shadow */
            object-fit: contain; /* Ensure the logo scales properly */
        }
        /* Styles for the two-step form sections */
        .form-section {
            border: 1px solid #eee;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            background-color: #f9f9f9;
        }
        .user-info-display {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-weight: bold;
            color: #333;
        }
        /* Message styles (retained/consistent with your current setup) */
        .message.success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .message.error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 15px; }
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
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo" class="logo">
            <h2>Generate Bank Card</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (!$display_account_selection): // Step 1: Find User ?>
                <div class="form-section">
                    <h3>Find User to Generate Card For</h3>
                    <form action="generate_bank_card.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="find_user">
                        <div class="form-group">
                            <label for="user_identifier">User Email or Membership Number</label>
                            <input type="text" id="user_identifier" name="user_identifier" value="<?php echo htmlspecialchars($_POST['user_identifier'] ?? ''); ?>" placeholder="e.g., user@example.com or 123456789012" required>
                            <small>Enter the user's email or 12-digit numeric membership number to find their accounts.</small>
                        </div>
                        <button type="submit" class="button-primary">Find User</button>
                    </form>
                </div>
            <?php else: // Step 2: Select Account and Generate Card ?>
                <div class="form-section">
                    <h3>Generate Card for:
                        <?php echo htmlspecialchars($user_for_card_generation['first_name'] . ' ' . $user_for_card_generation['last_name']); ?>
                        (<?php echo htmlspecialchars($user_for_card_generation['email']); ?>)
                    </h3>
                    <div class="user-info-display">
                        Membership No: <?php echo htmlspecialchars($user_for_card_generation['membership_number']); ?>
                    </div>
                    <form action="generate_bank_card.php" method="POST" class="form-standard">
                        <input type="hidden" name="action" value="generate_card">
                        <input type="hidden" name="user_id_hidden" value="<?php echo htmlspecialchars((string)$user_for_card_generation['_id']); ?>">

                        <div class="form-group">
                            <label for="account_id">Select Account to Link Card to</label>
                            <select id="account_id" name="account_id" required>
                                <option value="">-- Select an Account --</option>
                                <?php foreach ($accounts_for_card_generation as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['account_id']); ?>"
                                        <?php echo (($_POST['account_id'] ?? '') == $account['account_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_type'] . ' (' . $account['account_number'] . ') - ' . $account['currency'] . ' ' . number_format($account['balance'], 2)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>The card will be linked to this primary account.</small>
                        </div>

                        <div class="form-group">
                            <label for="created_at">Card Creation Date/Time</label>
                            <input type="datetime-local" id="created_at" name="created_at"
                                value="<?php echo htmlspecialchars(date('Y-m-d\TH:i')); ?>" required>
                            <small>Set the exact date and time this card is considered 'created'.</small>
                        </div>

                        <button type="submit" class="button-primary">Generate Card</button>
                    </form>
                    <p><a href="generate_bank_card.php" class="back-link">Start a New Card Generation</a></p>
                </div>
            <?php endif; ?>

            <?php if ($generated_card_info): ?>
                <div class="card-display <?php echo strtolower($generated_card_info['card_type']); ?>">
                    <h4>HOMETOWN BANK</h4>
                    <div class="chip"></div>
                    <div class="card-number"><?php echo htmlspecialchars($generated_card_info['card_number']); ?></div>
                    <div class="card-footer">
                        <div>
                            <div class="label">CARD HOLDER</div>
                            <div class="value"><?php echo htmlspecialchars($generated_card_info['holder_name']); ?></div>
                        </div>
                        <div>
                            <div class="label">EXPIRES</div>
                            <div class="value"><?php echo htmlspecialchars($generated_card_info['expiry_date']); ?></div>
                        </div>
                    </div>
                    <p style="font-size: 0.8em; text-align: center; margin-top: 10px;">
                        CVV: XXX
                    </p>
                    <?php
                        $card_logo_path = '';
                        if (strtolower($generated_card_info['card_type']) === 'visa') {
                            $card_logo_path = 'https://i.imgur.com/x69sY3k.png';
                        } elseif (strtolower($generated_card_info['card_type']) === 'mastercard') {
                            $card_logo_path = 'https://i.imgur.com/139Suh3.png';
                        } elseif (strtolower($generated_card_info['card_type']) === 'verve') {
                            $card_logo_path = 'https://i.imgur.com/dhW5pdv.png';
                        }
                    ?>
                    <?php if ($card_logo_path): ?>
                        <img src="<?php echo htmlspecialchars($card_logo_path); ?>" alt="<?php echo htmlspecialchars($generated_card_info['card_type']); ?> Logo" class="card-logo">
                    <?php endif; ?>
                </div>
                <p style="text-align: center; font-size: 0.9em; color: #666;">
                    The user's card has been generated and is awaiting activation.
                </p>
            <?php endif; ?>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>