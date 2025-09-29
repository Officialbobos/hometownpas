<?php
// index.php

session_start(); // Start the session at the very beginning of the script.

// --- REMOVE TEMPORARY DEBUG CODE FOR INI_SET & ERROR_REPORTING ---
// These are now handled by Config.php based on APP_DEBUG.
// ini_set('display_errors', 1); // <--- Make sure this is commented out or removed
// ini_set('display_startup_errors', 1); // <--- Make sure this is commented out or removed
// error_reporting(E_ALL); // <--- Make sure this is commented out or removed

error_log("--- SCRIPT START (index.php) ---");

// 1. Load Composer's autoloader FIRST.
// This is essential as Config.php and potentially functions.php depend on it (e.g., for Dotenv and MongoDB classes).
require __DIR__ . '/vendor/autoload.php';

// --- CONDITIONAL DOTENV LOADING (UPDATED FOR PXXL/GENERIC HOSTING) ---
// We check if a critical environment variable is ALREADY set. If it is, we assume 
// a hosting service (like pxxl) has provided it and skip loading the local .env file.

// Use getenv() to check for the presence of a critical variable.
$isLocalEnvironment = !getenv('MONGODB_CONNECTION_URI'); 

if ($isLocalEnvironment) {
    // We are NOT on a host with pre-loaded variables (likely local development), so load .env file
    try {
        // Create an immutable Dotenv instance, pointing to the root of your project
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        error_log("index.php: .env file loaded successfully locally.");
    } catch (Dotenv\Exception\InvalidPathException $e) {
        // This means the .env file wasn't found locally.
        error_log("index.php: NOTICE: .env file not found at " . __DIR__ . ". Assuming environment variables are pre-loaded or not needed for this environment. Error: " . $e->getMessage());
    }
} else {
    // We are on a hosting platform (like pxxl.app), environment variables are pre-loaded by the system.
    // No need to load a .env file.
    error_log("index.php: Running on a hosting environment (e.g., pxxl.app), environment variables are pre-loaded by the system. Skipping .env file load.");
}
// --- END CONDITIONAL DOTENV LOADING ---

// 2. Load Config.php. This will define all global constants (like BASE_URL, MONGODB_CONNECTION_URI)
// and set up error reporting based on APP_DEBUG from your .env.
// This must be AFTER the dotenv loading, so Config.php can read the variables.
require_once __DIR__ . '/Config.php';

// 3. Load functions.php. This should come after Config.php if functions rely on Config.php constants.
require_once __DIR__ . '/functions.php'; // Contains getMongoDBClient()

error_log("--- After requires in index.php (Config.php and functions.php loaded) ---");

// --- MongoDB Extension/Class Existence Checks (Keep for robust debugging, especially on Render builds) ---
// These checks are good to have early in the entry point.
if (!extension_loaded('mongodb')) {
    error_log('FATAL ERROR: MongoDB PHP extension is not loaded!');
    die('<h1>FATAL ERROR: MongoDB PHP extension is not loaded!</h1><p>Please check your Dockerfile, build logs, and PHP configuration.</p>');
}

if (!class_exists('MongoDB\Client')) {
    error_log('FATAL ERROR: MongoDB\Client class not found!');
    die('<h1>FATAL ERROR: MongoDB\Client class not found!</h1><p>This usually means Composer\'s autoloader failed or the MongoDB driver was not correctly installed/enabled.</p><p>Ensure `composer install` ran successfully and `docker-php-ext-enable mongodb` completed in your Dockerfile.</p>');
}
// --- END MongoDB Extension/Class Existence Checks ---


use MongoDB\Client;

// Global MongoDB connection
$mongoClient = null;
$mongoDb = null;
try {
    error_log("--- Attempting to get MongoDB Client in index.php ---");
    $mongoClient = getMongoDBClient(); // This function should get the client using MONGODB_CONNECTION_URI from Config.php
    error_log("--- MongoDB Client obtained. Attempting to select database ---");
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME); // MONGODB_DB_NAME is also from Config.php
    error_log("--- MongoDB database selected successfully ---");
} catch (Exception $e) {
    error_log("Failed to connect to MongoDB in index.php: " . $e->getMessage());
    // In production, consider a more generic error page instead of exposing the message.
    die("<h1>Service Unavailable: Database connection failed.</h1><p>Error: " . htmlspecialchars($e->getMessage()) . "</p>");
}

error_log("--- MongoDB connection established and DB selected. Proceeding to routing. ---");

// Ensure REQUEST_URI is a string, even if not set. Default to '/'
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';

// Parse the path component of the URL.
$request_uri_path = parse_url($request_uri, PHP_URL_PATH);

// Ensure parse_url returns a string or default to '/' if malformed/null
$request_uri_path = ($request_uri_path !== false && $request_uri_path !== null) ? $request_uri_path : '/';

// Get the directory name of the script (e.g., / or /subdir).
// Ensure $_SERVER['SCRIPT_NAME'] is handled if it's null.
$script_name = dirname($_SERVER['SCRIPT_NAME'] ?? '');

// Calculate the path relative to the script's directory.
// Handle cases where script_name might not be at the start of request_uri_path (e.g., root access).
if ($script_name !== '/' && strpos($request_uri_path, $script_name) === 0) {
    $path = substr($request_uri_path, strlen($script_name));
} else {
    // If script_name is '/', or not found at the beginning of the path, use the full path.
    $path = $request_uri_path;
}

// Trim leading/trailing slashes for cleaner routing logic (e.g., 'login' instead of '/login/').
$path = trim($path, '/');

// Define all routes that require authentication (both frontend and API)
$authenticated_routes = [
    'dashboard',
    'profile',
    'transfer',
    'transactions',
    'bank_cards',
    'accounts',
    'customer-service',
    'make_transfer',
    'manage_card',
    'my_cards',
    'set_card_pin',
    'deposit',
    'statements',
    // 'verify_code', // Removed from authenticated_routes as it has its own distinct auth check below
    'api/get_user_cards',
    'api/get_user_accounts',
    'api/order_card',
    'api/set_card_pin',
    'api/deposit_check',
    'api/set_session_for_card_modal',
    'api/clear_card_modal_session.php',
    // ... potentially other API/frontend routes that require authentication
];

// Check authentication for authenticated routes
if (in_array($path, $authenticated_routes) && !str_starts_with($path, 'admin/')) {
    // This block handles routes that require a user to be fully logged in (after 2FA)
    if (
        !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true || // CORRECT: Checking 'logged_in'
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] != true
    ) {
        // Log the redirection for debugging
        error_log("Authentication failed for path: " . $path . ". Redirecting to login.");
        // Add more specific session state logging here to confirm the exact values at this point
        error_log("DEBUG SESSION STATE for auth fail (index.php): ");
        error_log("  logged_in: " . (isset($_SESSION['logged_in']) ? var_export($_SESSION['logged_in'], true) : 'NOT SET')); // CORRECT: Logging 'logged_in'
        error_log("  user_id: " . (isset($_SESSION['user_id']) ? var_export($_SESSION['user_id'], true) : 'NOT SET'));
        error_log("  2fa_verified: " . (isset($_SESSION['2fa_verified']) ? var_export($_SESSION['2fa_verified'], true) : 'NOT SET'));


        header('Location: ' . BASE_URL . '/login');
        exit;
    }
} elseif ($path === 'verify_code') {
    // This block specifically handles the 2FA verification page
    // It checks if the user is in the 'awaiting_2fa' state, meaning they passed initial login
    if (!isset($_SESSION['auth_step']) || $_SESSION['auth_step'] !== 'awaiting_2fa' || !isset($_SESSION['temp_user_id'])) {
        // If not in the correct 2FA pending state, redirect them back to login
        error_log("Attempted to access verify_code without being in 'awaiting_2fa' state. Redirecting to login.");
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
}

// Admin authentication check (if different from user authentication)
// It's highly recommended to implement a robust admin authentication system.
if (str_starts_with($path, 'admin') || str_starts_with($path, 'api/admin')) {
    // Example: If an admin login page is 'admin/login'
    if ($path !== 'admin/login' && (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)) {
        error_log("Admin authentication failed for path: " . $path . ". Redirecting to admin login.");
        header('Location: ' . BASE_URL . '/admin/login'); // Redirect to admin login page
        exit;
    }
}

// --- ALL ROUTER LOGIC IN ONE SWITCH STATEMENT ---
switch ($path) {
    // --- Frontend Routes ---
    case '': // Default route for the root URL
    case 'login':
        require __DIR__ . '/frontend/login.php';
        break;
    case 'logout':
        require __DIR__ . '/frontend/logout.php';
        break;
    case 'dashboard':
        require __DIR__ . '/frontend/dashboard.php';
        break;
    case 'profile':
        require __DIR__ . '/frontend/profile.php';
        break;
    case 'transfer':
        require __DIR__ . '/frontend/transfer.php';
        break;
    case 'transactions':
        require __DIR__ . '/frontend/transactions.php';
        break;
    case 'bank_cards':
        require __DIR__ . '/frontend/bank_cards.php';
        break;
    case 'accounts':
        require __DIR__ . '/frontend/accounts.php';
        break;
    case 'customer-service':
        require __DIR__ . '/frontend/customer-service.php';
        break;
    case 'make_transfer':
        require __DIR__ . '/frontend/make_transfer.php';
        break;
    case 'manage_card':
        require __DIR__ . '/frontend/manage_card.php';
        break;
    case 'my_cards':
        require __DIR__ . '/frontend/my_cards.php';
        break;
    case 'set_card_pin':
        require __DIR__ . '/frontend/set_card_pin.php';
        break;
    case 'statements':
        require __DIR__ . '/frontend/statements.php';
        break;
    case 'verify_code':
        require __DIR__ . '/frontend/verify_code.php';
        break;
    case 'deposit':
        require __DIR__ . '/frontend/deposit.php';
        break;

    // --- API Routes ---
    case 'api/get_user_cards':
        require __DIR__ . '/api/get_user_cards.php';
        break;
    case 'api/get_user_accounts':
        require __DIR__ . '/api/get_user_accounts.php';
        break;
    case 'api/order_card':
        require __DIR__ . '/api/order_card.php';
        break;
    case 'api/login':
        require __DIR__ . '/api/login.php';
        break;
    case 'api/register':
        require __DIR__ . '/api/register.php';
        break;
    case 'api/deposit_check':
        require __DIR__ . '/api/deposit_check.php';
        break;
    case 'api/set_session_for_card_modal':
        require __DIR__ . '/api/set_session_for_card_modal.php';
        break;
        case 'api/clear_card_modal_session':
        require __DIR__ . '/api/clear_card_modal_session.php';
        break;

    // --- ADMIN PANEL ROUTES ---
    case 'admin':
        require __DIR__ . '/heritagebank_admin/dashboard.php';
        break;
    case 'admin/login':
        require __DIR__ . '/heritagebank_admin/index.php';
        break;
    case 'admin/logout':
        require __DIR__ . '/heritagebank_admin/logout.php';
        break;
    case 'admin/manage_modals':
        require __DIR__ . '/heritagebank_admin/manage_modals.php';
        break;
    case 'admin/transfer_process':
        require __DIR__ . '/heritagebank_admin/transfer_process.php';
        break;
    case 'admin/users':
        require __DIR__ . '/heritagebank_admin/users/users_management.php';
        break;
    case 'admin/create_user':
        require __DIR__ . '/heritagebank_admin/users/create_user.php';
        break;
    case 'admin/manage_users':
        require __DIR__ . '/heritagebank_admin/users/manage_users.php';
        break;
    case 'admin/manage_user_funds':
        require __DIR__ . '/heritagebank_admin/users/manage_user_funds.php';
        break;
    case 'admin/account_status_management':
        require __DIR__ . '/heritagebank_admin/users/account_status_management.php';
        break;
    case 'admin/transactions_management':
        require __DIR__ . '/heritagebank_admin/users/transactions_management.php';
        break;
    case 'admin/generate_bank_card':
        require __DIR__ . '/heritagebank_admin/users/generate_bank_card.php';
        break;
    case 'admin/generate_mock_transaction':
        require __DIR__ . '/heritagebank_admin/users/generate_mock_transaction.php';
        break;
    case 'admin/edit_users':
        require __DIR__ . '/heritagebank_admin/users/edit_users.php';
        break;
    case 'admin/fetch_user_accounts':
        require __DIR__ . '/heritagebank_admin/users/fetch_user_accounts.php';
        break;
    case 'admin/my_cards':
        require __DIR__ . '/heritagebank_admin/users/my_cards.php';
        break;
    case 'admin/activate_card':
        require __DIR__ . '/heritagebank_admin/users/activate_card.php';
        break;
    case 'admin/deposit_check_approval':
        require __DIR__ . '/heritagebank_admin/users/DepositCheckApproval.php';
        break;

    default:
        http_response_code(404);
        if (file_exists(__DIR__ . '/frontend/404.php')) {
            require __DIR__ . '/frontend/404.php';
        } else {
            echo "<h1>404 Not Found</h1><p>The page you requested could not be found.</p>";
        }
        break;
}
error_log("--- SCRIPT END ---");
?>