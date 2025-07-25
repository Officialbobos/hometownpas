<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\dashboard.php
// Assuming dashboard.php is in the 'frontend' folder, so paths to Config and functions need to go up one level.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load Composer's autoloader FIRST.
// This is crucial because Dotenv and other libraries are loaded through it.
require_once __DIR__ . '/../vendor/autoload.php';

// --- Start Dotenv loading (conditional for deployment) ---
// This block attempts to load .env files only if they exist.
// On Render, environment variables should be set directly in the dashboard,
// so a physical .env file won't be present.
$dotenvPath = dirname(__DIR__); // Go up one level from 'frontend' to the project root (hometownbank)

// THIS IS THE CRUCIAL CONDITIONAL CHECK
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    try {
        $dotenv->load(); // This will only run if .env file exists
        error_log("DEBUG: .env file EXISTS locally. Loaded Dotenv."); // Optional: for debugging local environment
    } catch (Dotenv\Exception\InvalidPathException $e) {
        // This catch is mostly for local dev if .env is missing or unreadable.
        error_log("Dotenv load error locally on path " . $dotenvPath . ": " . $e->getMessage());
    }
} else {
    // This block will execute on Render, as .env should NOT exist there.
    // Environment variables are expected to be set via Render's Config Vars.
    error_log("DEBUG: .env file DOES NOT exist. Skipping Dotenv load. (Expected on Render)"); // Optional: for debugging Render environment
}
// If .env doesn't exist (like on Render), the variables are assumed to be pre-loaded
// into the environment by the hosting platform (e.g., Render's Config Vars).
// --- End Dotenv loading ---


// Now load your Config.php and functions.php files.
// Config.php can now safely access $_ENV variables if they're defined in your .env file
// or if they're loaded as environment variables by the hosting provider.
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For sanitize_input, and potentially other utilities


// Check if the user is logged in. If not, redirect to login page.
// Also ensure 2FA is verified if it was initiated.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id']) || !isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) {
    // Clear any lingering 2FA session variables on failed/incomplete login
    unset($_SESSION['auth_step']);
    unset($_SESSION['temp_user_id']);
    // Redirect to the base URL (login page)
    header('Location: ' . rtrim(BASE_URL, '/')); // Corrected
    exit;
}

// After successful login and 2FA verification, ensure temporary 2FA session variables are cleared.
if (isset($_SESSION['auth_step'])) {
    unset($_SESSION['auth_step']);
}
if (isset($_SESSION['temp_user_id'])) {
    unset($_SESSION['temp_user_id']);
}


$user_id = $_SESSION['user_id'];
// Fetch first_name, last_name, email from session (set during login)
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$user_email = $_SESSION['email'] ?? '';

// Generate full name for display
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    $full_name = 'User'; // Fallback if no name parts are available
}

// MongoDB Connection
$mongoClient = null;
try {
    // Connect to MongoDB
    // *** CORRECTED: Use the constant names defined in Config.php ***
    $mongoClient = new MongoDB\Client(MONGODB_CONNECTION_URI); // Use MONGODB_CONNECTION_URI
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME); // Use MONGODB_DB_NAME

    $accountsCollection = $mongoDb->accounts;
    $transactionsCollection = $mongoDb->transactions;

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Handle MongoDB connection errors
    error_log("ERROR: Could not connect to MongoDB. " . $e->getMessage()); // Log the error
    // Display a user-friendly message without exposing raw error
    die("A critical database error occurred. Please try again later.");
}


$user_accounts = []; // Array to store all accounts for the logged-in user
$recent_transactions = []; // Array to store recent transactions

// 1. Fetch user's accounts from MongoDB
if ($mongoClient) {
    try {
        // MongoDB stores _id as ObjectId by default. If your user_id is an integer or string, adjust the query.
        // You mentioned the _id was '6881f2fa549401e932055a2d' in which is an ObjectId.
        // So, use new MongoDB\BSON\ObjectId($user_id);
        $filter = ['user_id' => new MongoDB\BSON\ObjectId($user_id)];

        $accounts_cursor = $accountsCollection->find($filter);
        foreach ($accounts_cursor as $account_data) {
            // Convert MongoDB BSON Document to PHP array
            $user_accounts[] = $account_data->getArrayCopy();
        }
    } catch (MongoDB\Driver\Exception\Exception $e) {
        error_log("Error fetching accounts from MongoDB: " . $e->getMessage());
        // You might want to display a user-friendly message or redirect
        // echo "Error fetching accounts: " . $e->getMessage(); // For debugging
    }

    // 2. Fetch recent transactions for the user from MongoDB
    try {
        // Query for transactions where user_id matches
        // Also use ObjectId for user_id in transactions if consistent with users collection
        $filter_transactions = ['user_id' => new MongoDB\BSON\ObjectId($user_id)];
        // Sort by 'initiated_at' in descending order and limit to 10
        $options_transactions = [
            'sort' => ['initiated_at' => -1],
            'limit' => 10,
        ];

        $transactions_cursor = $transactionsCollection->find($filter_transactions, $options_transactions);
        foreach ($transactions_cursor as $transaction_data) {
            // Convert MongoDB BSON Document to PHP array
            $recent_transactions[] = $transaction_data->getArrayCopy();
        }
    } catch (MongoDB\Driver\Exception\Exception $e) {
        error_log("Error fetching transactions from MongoDB: " . $e->getMessage());
        // You might want to display a user-friendly message or redirect
        // echo "Error fetching transactions: " . $e->getMessage(); // For debugging
    }
}

// MongoDB connection does not need an explicit close like MySQLi;
// the client connection is managed by the driver or garbage collection.
// However, if you explicitly want to unset it:
// unset($mongoClient);

// Helper function to get currency symbol (moved here for local scope if not in functions.php)
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
    <title>HomeTown Bank Pa - Dashboard</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="menu-icon" id="menuIcon">
                <i class="fas fa-bars"></i>
            </div>
            <div class="greeting">
                <h1 data-user-first-name="<?php echo htmlspecialchars($first_name); ?>">Hi, <?php echo htmlspecialchars($first_name); ?></h1>
            </div>
        </header>

        <section class="accounts-section">
            <div class="accounts-header-row">
                <h2>Accounts</h2>
                <div class="view-all-link">
                    <a href="<?php echo rtrim(BASE_URL, '/'); ?>/accounts">View all</a>
                </div>
            </div>
            <div class="account-cards-container">
                <?php if (empty($user_accounts)): ?>
                    <p class="loading-message" id="accountsLoadingMessage">No accounts found. Please contact support.</p>
                <?php else: ?>
                    <?php foreach ($user_accounts as $account): ?>
                        <?php
                            // Add a special class for savings accounts to target with CSS
                            $card_class = 'account-card';
                            if (strtolower($account['account_type'] ?? '') === 'savings') {
                                $card_class .= ' savings-account-card';
                            }
                        ?>
                        <div class="<?php echo htmlspecialchars($card_class); ?>">
                            <div class="account-details">
                                <p class="account-type"><?php echo htmlspecialchars(strtoupper($account['account_type'] ?? 'N/A')); ?></p>
                                <p class="account-number">**** **** **** <?php echo htmlspecialchars(substr($account['account_number'] ?? 'N/A', -4)); ?></p>
                            </div>
                            <div class="account-balance">
                                <p class="balance-amount">
                                    <?php
                                    echo get_currency_symbol($account['currency'] ?? 'USD');
                                    ?> <?php echo number_format($account['balance'] ?? 0, 2); ?>
                                </p>
                                <p class="balance-status">Available</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="account-pagination">
            </div>
        </section>

        <section class="actions-section">
            <div class="action-button" id="transferButton">
                <i class="fas fa-exchange-alt"></i>
                <p>Transfer</p>
            </div>
            <div class="action-button" id="depositButton">
                <i class="fas fa-download"></i>
                <p>Deposit</p>
            </div>
            <div class="action-button">
                <i class="fas fa-dollar-sign"></i>
                <p>Pay</p>
            </div>
            <div class="action-button" id="messageButton" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/customer-service'">
                <i class="fas fa-headset"></i> <p>Customer Service</p>
            </div>
        </section>

        <section class="bank-cards-section">
            <h2>My Cards</h2>
            <div class="view-all-link">
        <a href="<?php echo rtrim(BASE_URL, '/'); ?>/bank_cards" id="viewMyCardsButton"> <i class="fas fa-credit-card"></i> View My Card
                </a>
            </div>
            <div class="card-list-container" id="userCardList" style="display: none;">
                <p class="loading-message" id="cardsLoadingMessage">No cards found. Go to "Manage All Cards" to add one.</p>
            </div>
        </section>

        <section class="activity-section">
            <div class="transactions-header">
                <div class="transactions-header">
                <h2>Transactions</h2>
                <span class="more-options" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/statements'">...</span></div>
            <div class="transaction-list">
                <?php if (empty($recent_transactions)): ?>
                    <p class="loading-message" id="transactionsLoadingMessage">No recent transactions to display.</p>
                <?php else: ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="transaction-item">
                            <div class="transaction-details">
                                <span class="transaction-description"><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></span>
                                <span class="transaction-account">
                                    <?php
                                    echo htmlspecialchars($transaction['account_type'] ?? 'N/A');
                                    if (isset($transaction['account_number'])) {
                                        echo ' x' . htmlspecialchars(substr($transaction['account_number'], -4));
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="transaction-amount-date">
                                <span class="transaction-amount <?php echo (($transaction['transaction_type'] ?? '') == 'Credit') ? 'credit' : 'debit'; ?>">
                                    <?php echo (($transaction['transaction_type'] ?? '') == 'Credit' ? '+' : '-'); ?>
                                    <?php
                                    echo get_currency_symbol($transaction['currency'] ?? 'USD');
                                    ?> <?php echo number_format($transaction['amount'] ?? 0, 2); ?>
                                </span>
                                <span class="transaction-date">
                                    <?php
                                    if (isset($transaction['initiated_at']) && $transaction['initiated_at'] instanceof MongoDB\BSON\UTCDateTime) {
                                        echo htmlspecialchars(date('M d', $transaction['initiated_at']->toDateTime()->getTimestamp()));
                                    } else if (isset($transaction['initiated_at'])) {
                                        echo htmlspecialchars(date('M d', strtotime($transaction['initiated_at'])));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button class="see-more-button" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/statements'">See more</button>
        </section>

    </div>

    <div class="transfer-modal-overlay" id="transferModalOverlay">
        <div class="transfer-modal-content">
            <h3>Choose Transfer Type</h3>
            <div class="transfer-options-list">
                <button class="transfer-option" data-transfer-type="Own Account" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=own_account'">
                    <i class="fas fa-wallet"></i> <p>Transfer to My Other Account</p>
                </button>

                <button class="transfer-option" data-transfer-type="Bank to Bank" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=bank_to_bank'">
                    <i class="fas fa-university"></i>
                    <p>Bank to Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="ACH" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=ach'">
                    <i class="fas fa-exchange-alt"></i>
                    <p>ACH Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Wire" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=wire'">
                    <i class="fas fa-ethernet"></i>
                    <p>Wire Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="International Bank" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=international_bank'">
                    <i class="fas fa-globe"></i>
                    <p>International Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Domestic Wire" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/transfer?type=domestic_wire'">
                    <i class="fas fa-home"></i>
                    <p>Domestic Wire Transfer</p>
                </button>
            </div>
            <button class="close-modal-button" id="closeTransferModal">Close</button>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar-button" id="closeSidebarBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-profile">
                <img src="<?php echo rtrim(BASE_URL, '/'); ?>/images/default-profile.png" alt="Profile Picture" class="sidebar-profile-pic">
                <h3><span id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></span></h3>
                <p><span id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></span></p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/accounts"><i class="fas fa-wallet"></i> Accounts</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/transfer"><i class="fas fa-exchange-alt"></i> Transfers</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/statements"><i class="fas fa-file-invoice"></i> Statements</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/profile"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/settings"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/bank_cards"><i class="fas fa-credit-card"></i> Bank Cards</a></li>
            </ul>
        </nav>
        <button class="logout-button" id="logoutButton" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/logout'">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>
    <script src="<?php echo BASE_URL; ?>/frontend/user.dashboard.js"></script>
</body>
</html>