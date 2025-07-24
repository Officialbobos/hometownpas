<?php
session_start();

// Ensure error reporting is on for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Load Composer's autoloader FIRST. This makes Dotenv and MongoDB classes available.
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Load environment variables using Dotenv.
// The path should be the directory containing your .env file.
// dirname(__DIR__) correctly points to the 'phpfile-main' directory from 'frontend/accounts.php'.
try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    error_log("FATAL CONFIG ERROR: .env file not found or not readable in " . dirname(__DIR__) . ". Error: " . $e->getMessage());
    die("Application configuration error. Please contact support. (Ref: ENV_LOAD_FAIL_ACC)");
} catch (Exception $e) {
    error_log("FATAL CONFIG ERROR: Unexpected error during .env load in accounts.php: " . $e->getMessage());
    die("Application configuration error: Unexpected .env load error. Check server logs.");
}

// 3. Now load your Config.php. It can now safely access $_ENV variables.
require_once __DIR__ . '/../Config.php'; // Your database configuration and other constants
require_once __DIR__ . '/../functions.php'; // If you have a sanitize_input function here

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ../indx.php'); // Redirect to the main login page (e.g., indx.php)
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = ''; // Initialize, will be fetched from DB
$user_email = ''; // Initialize, will be fetched from DB
$user_accounts = []; // Array to store user's accounts

// --- MongoDB Connection ---
$client = null;
$database = null;
$usersCollection = null;
$accountsCollection = null;

try {
    // Connect to MongoDB using the constants defined in Config.php
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error in accounts.php: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later. (Code: MDB_CONN_FAIL)");
}

// Fetch user's name and email for display in header/sidebar
try {
    // Convert session user_id to MongoDB\BSON\ObjectId for database query
    $userObjectId = new MongoDB\BSON\ObjectId($user_id);
    $user_doc = $usersCollection->findOne(
        ['_id' => $userObjectId],
        ['projection' => ['first_name' => 1, 'last_name' => 1, 'username' => 1, 'email' => 1]]
    );

    if ($user_doc) {
        $first_name = $user_doc['first_name'] ?? '';
        $last_name = $user_doc['last_name'] ?? '';
        $user_email = $user_doc['email'] ?? '';
        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) { // Fallback to username if first/last name are empty
            $full_name = $user_doc['username'] ?? 'User';
        }
    } else {
        // Fallback if user data not found in DB (should ideally not happen if user_id is valid)
        $full_name = $_SESSION['username'] ?? 'User';
        $user_email = $_SESSION['temp_user_email'] ?? ''; // Assuming you might have a temporary email in session
        error_log("User with ID " . $user_id . " not found in database when fetching profile details for accounts.php.");
    }
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB user fetch error in accounts.php: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback to session username
    $user_email = $_SESSION['temp_user_email'] ?? '';
} catch (Exception $e) { // Catch for potential ObjectId conversion error if $user_id is malformed
    error_log("Invalid user ID format in accounts.php: " . $e->getMessage());
    $full_name = $_SESSION['username'] ?? 'User'; // Fallback
    $user_email = $_SESSION['temp_user_email'] ?? '';
}


// Fetch user's accounts
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
    error_log("MongoDB accounts fetch error in accounts.php: " . $e->getMessage());
    // Optionally display an error message to the user, e.g.:
    // echo "<p class='error-message'>Failed to load accounts. Please try again.</p>";
}

// Helper to format currency (moved here to ensure it's defined only once if functions.php doesn't have it)
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
        body.dashboard-page {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6; /* Light grey background */
            color: #333;
        }

        .dashboard-header {
            background-color: #007bff; /* Heritage Bank Blue */
            color: white;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .dashboard-header .logo img {
            height: 40px; /* Adjust logo size */
        }

        .dashboard-header .user-info {
            display: flex;
            align-items: center;
        }

        .dashboard-header .user-info .profile-icon {
            font-size: 24px;
            margin-right: 10px;
        }

        .dashboard-header .user-info span {
            margin-right: 20px;
            font-weight: bold;
        }

        .dashboard-header .user-info a {
            color: white;
            text-decoration: none;
            background-color: #0056b3;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .dashboard-header .user-info a:hover {
            background-color: #004085;
        }

        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 70px); /* Adjust based on header height */
        }

        .sidebar {
            width: 250px;
            background-color: #2c3e50; /* Darker sidebar */
            color: white;
            padding-top: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar ul li {
            margin-bottom: 5px;
        }

        .sidebar ul li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .sidebar ul li a i {
            margin-right: 10px;
            font-size: 18px;
        }

        .sidebar ul li a:hover,
        .sidebar ul li.active a {
            background-color: #34495e; /* Slightly lighter dark for hover/active */
            border-left: 5px solid #007bff;
            padding-left: 15px; /* Adjust padding due to border */
        }

        .accounts-content {
            flex-grow: 1;
            padding: 20px;
            background-color: #f4f7f6;
        }

        .card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 20px;
        }

        .card h2 {
            color: #007bff;
            margin-top: 0;
            margin-bottom: 25px;
            font-size: 1.8em;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .account-card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px; /* Increased gap */
            margin-top: 20px;
        }

        .account-card {
            background-color: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 10px; /* Slightly more rounded */
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05); /* Softer shadow */
            padding: 25px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 250px; /* Adjusted height to accommodate more details */
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            position: relative; /* For potential future badges/icons */
        }

        .account-card:hover {
            transform: translateY(-8px); /* More pronounced lift */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12); /* Stronger shadow on hover */
        }

        .account-card h3 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.4em; /* Slightly larger title */
            display: flex;
            align-items: center;
            border-bottom: 1px dashed #e9ecef; /* Subtle separator */
            padding-bottom: 10px;
        }

        .account-card h3 i {
            margin-right: 12px; /* More space for icon */
            color: #007bff;
            font-size: 1.2em;
        }

        .account-card p {
            margin: 10px 0; /* More vertical spacing */
            font-size: 1.05em; /* Slightly larger text */
            color: #444;
        }

        .account-card .detail-label {
            font-weight: bold;
            color: #555;
            margin-right: 8px; /* More space */
        }

        .account-card .detail-value {
            font-family: 'Roboto Mono', monospace; /* Modern monospace font */
            background-color: #e9ecef; /* Lighter background */
            padding: 5px 10px; /* More padding */
            border-radius: 5px; /* More rounded */
            display: inline-block;
            letter-spacing: 0.8px; /* More legible */
            color: #333;
            word-break: break-all; /* Ensures long numbers wrap */
        }

        .account-card .balance {
            font-size: 2em; /* Larger balance */
            font-weight: bold;
            color: #28a745; /* Green for positive balance */
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0; /* Separator for balance */
            text-align: right; /* Align balance to the right */
        }

        .no-accounts {
            text-align: center;
            padding: 60px; /* More padding */
            font-size: 1.2em; /* Larger text */
            color: #6c757d; /* Muted grey */
            background-color: #e9f7ef; /* Light green background for positive message */
            border: 1px solid #d4edda; /* Subtle border */
            border-radius: 8px;
            margin-top: 30px;
        }
    </style>
</head>
<body class="dashboard-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/images/logo.png" alt="Heritage Bank Logo" class="logo-barclays">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="../logout.php">Logout</a> </div>
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
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li> </ul>
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