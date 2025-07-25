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
    // Corrected: Use BASE_URL for redirect to login page
    header('Location: ' . BASE_URL . '/index.php'); // Assuming index.php is your login page
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
            case 'EUR': $symbol = '€'; break;
            case 'NGN': $symbol = '₦'; break; // Added NGN symbol explicitly
            default: $symbol = ''; // Default to no symbol if unknown
        }
        return $symbol . number_format($amount, 2);
    }
}

// Helper to get currency symbol (used in the new section)
if (!function_exists('get_currency_symbol')) {
    function get_currency_symbol($currency_code) {
        switch (strtoupper($currency_code)) {
            case 'GBP': return '£';
            case 'USD': return '$';
            case 'EUR': return '€';
            case 'NGN': return '₦';
            default: return '';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Accounts - HomeTown Bank Pa</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* NEW COLOR PALETTE & GLOBALS */
        :root {
            --dark-purple: #3f005c; /* Deep, rich purple */
            --light-purple: #6a0dad; /* A more vibrant purple for accents */
            --glow-color: #bb33ff; /* A bright, almost neon purple for glow */
            --white: #ffffff;
            --off-white: #f0f0f0;
            --text-color: #e0e0e0; /* Light text on dark backgrounds */
            --dark-text: #333333; /* Dark text on white backgrounds */
            --success-green: #28a745;
            --light-bg: #f4f7f6; /* Still a light background for main content area */

            /* RGB versions for box-shadows if needed, for better rgba control */
            --glow-color-rgb: 187, 51, 255;
            --light-purple-rgb: 106, 13, 173;
        }

        body.dashboard-page {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg); /* Main body background */
            color: var(--dark-text); /* Default text color */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* HEADER */
        .dashboard-header {
            background-color: var(--dark-purple); /* Dark purple header */
            color: var(--white);
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3); /* Deeper shadow */
            position: sticky; /* Make header sticky */
            top: 0;
            z-index: 1000;
        }

        .dashboard-header .logo img {
            height: 40px;
            /* Filter to make logo white/glowing, assuming original logo is dark */
            filter: brightness(0) invert(1) drop-shadow(0 0 5px var(--glow-color));
            transition: filter 0.3s ease-in-out;
        }
        .dashboard-header .logo img:hover {
            filter: brightness(0) invert(1) drop-shadow(0 0 10px var(--glow-color)) saturate(1.5);
        }

        .dashboard-header .user-info {
            display: flex;
            align-items: center;
        }

        .dashboard-header .user-info .profile-icon {
            font-size: 24px;
            margin-right: 10px;
            color: var(--glow-color); /* Glowing icon */
            text-shadow: 0 0 8px var(--glow-color); /* Text glow for icon */
        }

        .dashboard-header .user-info span {
            margin-right: 20px;
            font-weight: bold;
            color: var(--white);
        }

        .dashboard-header .user-info a {
            color: var(--white);
            text-decoration: none;
            background-color: var(--light-purple); /* Lighter purple button */
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 0 5px var(--glow-color); /* Initial button glow */
        }

        .dashboard-header .user-info a:hover {
            background-color: var(--glow-color); /* Button glows brighter on hover */
            box-shadow: 0 0 15px var(--glow-color);
            color: var(--dark-purple); /* Text becomes dark on bright glow */
        }

        .dashboard-container {
            display: flex;
            flex-grow: 1; /* Allows it to take remaining vertical space */
        }

        /* SIDEBAR */
        .sidebar {
            width: 250px;
            background-color: var(--dark-purple); /* Dark purple sidebar */
            color: var(--text-color); /* Light text */
            padding-top: 20px;
            box-shadow: 2px 0 10px rgba(0,0,0,0.3);
            flex-shrink: 0; /* Prevents sidebar from shrinking */
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
            color: var(--text-color);
            text-decoration: none;
            transition: background-color 0.3s ease, border-left 0.3s ease, color 0.3s ease, text-shadow 0.3s ease;
            border-left: 5px solid transparent; /* For active state highlight */
        }

        .sidebar ul li a i {
            margin-right: 10px;
            font-size: 18px;
            color: var(--glow-color); /* Icons glow */
            text-shadow: 0 0 5px rgba(var(--glow-color-rgb), 0.5); /* Subtle glow for icons */
        }

        .sidebar ul li a:hover,
        .sidebar ul li.active a {
            background-color: var(--light-purple); /* Lighter purple on hover/active */
            border-left: 5px solid var(--glow-color); /* Glowing border */
            padding-left: 15px;
            color: var(--white); /* White text on active/hover */
            text-shadow: 0 0 10px var(--glow-color); /* Text glow on active/hover */
        }

        /* MAIN CONTENT AREA */
        .accounts-content {
            flex-grow: 1;
            padding: 30px; /* Increased padding */
            background-color: var(--off-white); /* Off-white background for contrast */
            position: relative;
            overflow: hidden; /* To contain any card shadows */
        }

        /* CARD STYLING (General) */
        .card {
            background-color: var(--white);
            border-radius: 12px; /* More rounded corners */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15); /* Stronger, softer shadow */
            padding: 35px; /* Increased padding */
            margin-bottom: 30px; /* More space between main card and accounts */
            border: 1px solid rgba(var(--glow-color-rgb), 0.1); /* Subtle glowing border */
            transition: box-shadow 0.3s ease;
        }
        .card:hover {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2), 0 0 20px rgba(var(--glow-color-rgb), 0.4); /* Enhanced glow on hover */
        }

        .card h2 {
            color: var(--dark-purple); /* Dark purple heading */
            margin-top: 0;
            margin-bottom: 25px;
            font-size: 2em; /* Larger heading */
            border-bottom: 2px solid var(--light-purple); /* Purple line */
            padding-bottom: 10px;
            text-align: center;
        }

        /* --- NEW ACCOUNTS SECTION STYLES --- */
        .accounts-section {
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            padding: 30px;
            margin-bottom: 30px; /* Spacing below the section */
            border: 1px solid rgba(var(--glow-color-rgb), 0.1);
            transition: box-shadow 0.3s ease;
        }

        .accounts-section:hover {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2), 0 0 20px rgba(var(--glow-color-rgb), 0.4);
        }

        .accounts-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 2px solid var(--light-purple); /* Match card h2 border */
            padding-bottom: 10px;
        }

        .accounts-header-row h2 {
            margin: 0;
            color: var(--dark-purple);
            font-size: 1.8em; /* Slightly smaller than main h2, but prominent */
            text-align: left; /* Align to left */
            border-bottom: none; /* Remove duplicate border */
            padding-bottom: 0;
        }

        .accounts-header-row .view-all-link a {
            color: var(--light-purple);
            text-decoration: none;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            transition: color 0.3s ease, background-color 0.3s ease, box-shadow 0.3s ease;
        }

        .accounts-header-row .view-all-link a:hover {
            color: var(--white);
            background-color: var(--light-purple);
            box-shadow: 0 0 10px var(--glow-color);
        }

        .account-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Adjusted for summary view */
            gap: 20px; /* Slightly smaller gap for denser display */
            margin-top: 0; /* Remove top margin as header handles spacing */
        }

        .account-card {
            background: linear-gradient(145deg, var(--white), var(--off-white));
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1), 0 0 15px rgba(var(--light-purple-rgb), 0.3);
            padding: 20px; /* Smaller padding for summary cards */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 150px; /* Smaller min-height for summary cards */
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
            position: relative;
            overflow: hidden;
            border: none; /* Resetting card border */
        }

        /* Glow effect using pseudo-elements for individual account cards */
        .account-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 15px;
            border: 2px solid transparent;
            background: linear-gradient(45deg, rgba(var(--glow-color-rgb), 1) 0%, rgba(var(--light-purple-rgb), 1) 100%) border-box;
            -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: destination-out;
            mask-composite: exclude;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .account-card:hover {
            transform: translateY(-8px); /* Less pronounced lift than full page cards */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2), 0 0 20px rgba(var(--glow-color-rgb), 0.5); /* Stronger glow on hover */
        }
        .account-card:hover::before {
            opacity: 1; /* Show glowing border on hover */
        }

        .account-card .account-details {
            flex-grow: 1;
        }

        .account-card .account-type {
            font-size: 1.1em;
            font-weight: bold;
            color: var(--dark-purple);
            margin-bottom: 5px;
            font-family: 'Orbitron', sans-serif;
        }

        .account-card .account-number {
            font-size: 0.95em;
            color: #666;
            font-family: 'Roboto Mono', monospace;
            letter-spacing: 1px;
        }

        .account-card .account-balance {
            text-align: right;
            margin-top: 15px;
        }

        .account-card .balance-amount {
            font-size: 1.8em; /* Slightly smaller than full page balance */
            font-weight: bold;
            color: var(--success-green);
            margin: 0;
            font-family: 'Orbitron', sans-serif;
            text-shadow: 0 0 8px rgba(40, 167, 69, 0.3); /* Subtle glow */
        }

        .account-card .balance-status {
            font-size: 0.85em;
            color: #888;
            margin-top: 5px;
        }

        .loading-message {
            text-align: center;
            padding: 30px;
            color: var(--dark-purple);
            font-size: 1.1em;
            background-color: var(--off-white);
            border-radius: 8px;
            border: 1px dashed var(--light-purple);
        }

        /* Pagination (placeholder for now) */
        .account-pagination {
            text-align: center;
            padding-top: 20px;
            color: #777;
            font-size: 0.9em;
        }


        /* --- RESPONSIVENESS --- */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                padding: 15px;
            }
            .dashboard-header .user-info {
                margin-top: 15px;
                width: 100%;
                justify-content: center;
            }
            .dashboard-header .user-info span {
                margin-right: 10px;
            }
            .dashboard-header .user-info a {
                padding: 6px 12px;
            }

            .dashboard-container {
                flex-direction: column; /* Stack sidebar and main content */
            }

            .sidebar {
                width: 100%;
                height: auto; /* Allow sidebar height to adjust */
                padding-top: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            .sidebar ul {
                display: flex;
                flex-wrap: wrap; /* Allow navigation items to wrap */
                justify-content: center;
            }
            .sidebar ul li {
                flex: 1 1 auto; /* Items take equal width */
                max-width: 50%; /* Two items per row on small screens */
                text-align: center;
            }
            .sidebar ul li a {
                border-left: none; /* Remove left border on mobile */
                border-bottom: 3px solid transparent; /* Use bottom border for active state */
                padding: 10px 10px;
                flex-direction: column; /* Stack icon and text */
                font-size: 0.9em;
            }
            .sidebar ul li a i {
                margin-right: 0;
                margin-bottom: 5px; /* Space between icon and text */
                font-size: 1.5em;
            }
            .sidebar ul li a span {
                display: block; /* Ensure text is on new line */
            }
            .sidebar ul li a:hover,
            .sidebar ul li.active a {
                border-left: none;
                border-bottom: 3px solid var(--glow-color); /* Glowing bottom border */
                padding-left: 10px; /* Reset padding-left */
            }

            .accounts-content {
                padding: 20px;
            }

            /* Main accounts page specific section */
            .card { /* This is the `.card` div wrapping the whole accounts content */
                padding: 25px;
            }
            .card h2 {
                font-size: 1.6em;
            }
            .account-card-container { /* This is the grid for the main account cards */
                grid-template-columns: 1fr; /* Single column on mobile */
                gap: 20px;
            }
            .account-card { /* These are the individual full-page account cards */
                min-height: auto; /* Allow height to auto-adjust */
                padding: 25px;
            }
            .account-card h3 {
                font-size: 1.4em;
                justify-content: center; /* Center title and icon */
                text-align: center;
            }
            .account-card p {
                font-size: 1em;
                text-align: center; /* Center details */
            }
            .account-card .detail-label {
                display: block; /* Stack label above value */
                margin-right: 0;
                margin-bottom: 5px;
            }
            .account-card .detail-value {
                display: block; /* Make value block to appear below label */
                text-align: center;
            }
            .account-card .balance {
                font-size: 2em;
                text-align: center; /* Center balance */
            }

            /* New accounts-section styles for mobile */
            .accounts-section {
                padding: 20px;
            }
            .accounts-header-row {
                flex-direction: column;
                align-items: flex-start; /* Align left */
                margin-bottom: 20px;
            }
            .accounts-header-row h2 {
                font-size: 1.5em;
                margin-bottom: 10px;
            }
            .accounts-header-row .view-all-link {
                width: 100%;
                text-align: right; /* Keep view all link to the right */
            }
            .account-cards-container { /* This is the grid for the new summary cards */
                grid-template-columns: 1fr; /* Single column on mobile */
                gap: 15px;
            }
            .account-card .balance-amount {
                font-size: 1.6em; /* Adjust for summary view */
            }
        }

        @media (max-width: 480px) {
            .dashboard-header .logo img {
                height: 35px;
            }
            .dashboard-header .user-info span {
                font-size: 0.9em;
            }
            .sidebar ul li {
                max-width: 100%; /* One item per row on very small screens */
            }
            .sidebar ul li a {
                padding: 8px 5px;
                font-size: 0.85em;
            }
            .sidebar ul li a i {
                font-size: 1.3em;
            }
            .card h2, .accounts-header-row h2 {
                font-size: 1.4em;
            }
            .account-card h3 {
                font-size: 1.2em;
            }
            .account-card .balance-amount {
                font-size: 1.4em;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/images/logo.png" alt="HomeTown Bank Pa Logo">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="<?php echo BASE_URL; ?>/logout">Logout</a>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>/dashboard"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li class="active"><a href="<?php echo BASE_URL; ?>/accounts"><i class="fas fa-wallet"></i> <span>My Accounts</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/profile"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/statements"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/transfer"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/transactions"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/settings"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="<?php echo BASE_URL; ?>/logout"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
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
                                        switch (strtolower($account['account_type'] ?? '')) {
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
    <script src="<?php echo BASE_URL; ?>/frontend/script.js"></script>
</body>
</html>