<?php
session_start();
require_once '../Config.php'; // Your database configuration (will need MONGO_URI and MONGO_DB_NAME)
require_once '../functions.php'; // If you have a sanitize_input function here
require_once '../vendor/autoload.php'; // Include Composer's autoloader for MongoDB

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = ''; // Will be fetched from DB
$user_accounts = []; // Array to store user's accounts

// --- MongoDB Connection ---
$client = null;
$database = null;
$usersCollection = null;
$accountsCollection = null;

try {
    // MONGO_URI and MONGO_DB_NAME should be defined in Config.php
    $client = new MongoDB\Client(MONGO_URI);
    $database = $client->selectDatabase(MONGO_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    // In a real application, you might redirect to an error page or show a user-friendly message
    die("ERROR: Could not connect to database. Please try again later.");
}

// Fetch user's name for display in header
try {
    // Convert session user_id to MongoDB\BSON\ObjectId
    $userObjectId = new MongoDB\BSON\ObjectId($user_id);
    $user_doc = $usersCollection->findOne(['_id' => $userObjectId], ['projection' => ['first_name' => 1, 'last_name' => 1]]);

    if ($user_doc) {
        $full_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
    } else {
        // Fallback if user data not found, though session check should prevent this
        $full_name = $_SESSION['username'] ?? 'User';
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB user fetch error: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback
} catch (Exception $e) { // Catch for ObjectId conversion error
    error_log("Invalid user ID format: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback
}


// Fetch user's accounts - UPDATED to include sort_code, iban, swift_bic
try {
    $cursor = $accountsCollection->find(
        ['user_id' => $userObjectId], // Query for accounts belonging to the user
        [
            'sort' => ['account_type' => 1, 'account_number' => 1] // Sort by type then number
        ]
    );

    foreach ($cursor as $accountDoc) {
        $user_accounts[] = (array) $accountDoc; // Convert BSON document to PHP array
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB accounts fetch error: " . $e->getMessage());
    // Optionally display an error message to the user
}

// Helper to format currency (remains the same as it doesn't interact with DB)
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $currency_code) {
        $symbol = '';
        switch (strtoupper($currency_code)) {
            case 'GBP': $symbol = '£'; break;
            case 'USD': $symbol = '$'; break;
            case 'EUR':
            default: $symbol = '€'; break;
        }
        return $symbol . number_format($amount, 2);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accounts - Heritage Bank</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Specific styles for the accounts page */
        .accounts-content {
            padding: 20px;
        }

        .account-card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .account-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 25px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 220px; /* Increased height to accommodate new fields */
            transition: transform 0.2s ease-in-out;
        }

        .account-card:hover {
            transform: translateY(-5px);
        }

        .account-card h3 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.3em;
            display: flex;
            align-items: center;
        }

        .account-card h3 i {
            margin-right: 10px;
            color: #007bff;
        }

        .account-card p {
            margin: 8px 0;
            font-size: 1.1em;
            color: #333;
        }

        .account-card .detail-label {
            font-weight: bold;
            color: #555;
            margin-right: 5px;
        }

        .account-card .detail-value {
            font-family: 'Courier New', Courier, monospace;
            background-color: #f0f0f0;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
            letter-spacing: 0.5px;
        }

        .account-card .balance {
            font-size: 1.8em;
            font-weight: bold;
            color: #28a745; /* Green for positive balance */
            margin-top: 15px;
        }

        .no-accounts {
            text-align: center;
            padding: 40px;
            font-size: 1.1em;
            color: #777;
            background-color: #f9f9f9;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body class="dashboard-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="../images/logo.png" alt="Heritage Bank Logo" class="logo-barclays">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li class="active"><a href="accounts.php"><i class="fas fa-wallet"></i> <span>My Accounts</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="accounts-content">
            <div class="card">
                <h2>Your Bank Accounts</h2>

                <?php if (!empty($user_accounts)): ?>
                    <div class="account-card-container">
                        <?php foreach ($user_accounts as $account): ?>
                            <div class="account-card">
                                <h3>
                                    <?php
                                        // Display icon based on account type
                                        switch (strtolower($account['account_type'] ?? '')) { // Added null coalesce for robustness
                                            case 'checking':
                                                echo '<i class="fas fa-money-check-alt"></i>';
                                                break;
                                            case 'savings':
                                                echo '<i class="fas fa-piggy-bank"></i>';
                                                break;
                                            case 'current':
                                                echo '<i class="fas fa-hand-holding-usd"></i>';
                                                break;
                                            default:
                                                echo '<i class="fas fa-wallet"></i>';
                                                break;
                                        }
                                    ?>
                                    <?php echo htmlspecialchars(ucwords($account['account_type'] ?? 'N/A')) . ' Account'; ?>
                                </h3>
                                <p><span class="detail-label">Account Number:</span> <span class="detail-value"><?php echo htmlspecialchars($account['account_number'] ?? 'N/A'); ?></span></p>
                                <p><span class="detail-label">Currency:</span> <span class="detail-value"><?php echo htmlspecialchars(strtoupper($account['currency'] ?? 'N/A')); ?></span></p>

                                <?php if (!empty($account['sort_code'])): ?>
                                    <p><span class="detail-label">Sort Code:</span> <span class="detail-value"><?php echo htmlspecialchars($account['sort_code']); ?></span></p>
                                <?php endif; ?>

                                <?php if (!empty($account['iban'])): ?>
                                    <p><span class="detail-label">IBAN:</span> <span class="detail-value"><?php echo htmlspecialchars($account['iban']); ?></span></p>
                                <?php endif; ?>

                                <?php if (!empty($account['swift_bic'])): ?>
                                    <p><span class="detail-label">SWIFT/BIC:</span> <span class="detail-value"><?php echo htmlspecialchars($account['swift_bic']); ?></span></p>
                                <?php endif; ?>

                                <p class="balance">Balance: <?php echo formatCurrency($account['balance'] ?? 0, $account['currency'] ?? 'USD'); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="no-accounts">You currently have no bank accounts linked to your profile.</p>
                <?php endif; ?>

            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>