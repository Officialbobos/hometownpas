<?php
// index.php

session_start(); // Start the session at the very beginning of the script.

// --- REMOVE TEMPORARY DEBUG CODE FOR INI_SET & ERROR_REPORTING ---
// These are now handled by Config.php based on APP_DEBUG.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

error_log("--- SCRIPT START (index.php) ---");

// 1. Load Composer's autoloader FIRST.
// This is essential as Config.php and potentially functions.php depend on it (e.g., for Dotenv and MongoDB classes).
require __DIR__ . '/vendor/autoload.php';

// 2. Load Config.php. This will define all global constants (like BASE_URL, MONGODB_CONNECTION_URI)
// and set up error reporting based on APP_DEBUG from your .env.
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
    'statements',
    // 'verify_code', // Removed from authenticated_routes as it has its own distinct auth check below
    'api/get_user_cards',
    'api/get_user_accounts',
    'api/order_card',
    'api/set_card_pin',
    // ... potentially other API/frontend routes that require authentication
];

// Check authentication for authenticated routes
if (in_array($path, $authenticated_routes) && !str_starts_with($path, 'admin/')) {
    // This block handles routes that require a user to be fully logged in (after 2FA)
    if (
        !isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true ||
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true
    ) {
        // Log the redirection for debugging
        error_log("Authentication failed for path: " . $path . ". Redirecting to login.");
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
    // ... potentially other API routes

    // --- Admin Panel Routes ---
    case 'admin':
        require __DIR__ . '/heritagebank_admin/admin_dashboard.php';
        break;
    case 'admin/login': // Explicit admin login route if separate
        require __DIR__ . '/heritagebank_admin/admin_login.php'; // Create this file
        break;
    case 'admin/users':
        require __DIR__ . '/heritagebank_admin/manage_users.php';
        break;
    case 'admin/accounts':
        require __DIR__ . '/heritagebank_admin/manage_accounts.php';
        break;
    case 'admin/cards':
        require __DIR__ . '/heritagebank_admin/manage_cards.php';
        break;
    case 'admin/transactions':
        require __DIR__ . '/heritagebank_admin/manage_transactions.php';
        break;

    // --- Admin API Routes ---
    case 'api/admin/get_users':
        require __DIR__ . '/heritagebank_admin/api/get_users.php';
        break;
    case 'api/admin/create_user':
        require __DIR__ . '/heritagebank_admin/api/create_user.php';
        break;
    case 'api/admin/update_user':
        require __DIR__ . '/heritagebank_admin/api/update_user.php';
        break;
    case 'api/admin/delete_user':
        require __DIR__ . '/heritagebank_admin/api/delete_user.php';
        break;
    case 'api/admin/get_user_accounts':
        require __DIR__ . '/heritagebank_admin/api/get_user_accounts.php';
        break;
    case 'api/admin/get_accounts':
        require __DIR__ . '/heritagebank_admin/api/get_accounts.php';
        break;
    case 'api/admin/create_account':
        require __DIR__ . '/heritagebank_admin/api/create_account.php';
        break;
    case 'api/admin/update_account':
        require __DIR__ . '/heritagebank_admin/api/update_account.php';
        break;
    case 'api/admin/delete_account':
        require __DIR__ . '/heritagebank_admin/api/delete_account.php';
        break;
    case 'api/admin/get_cards':
        require __DIR__ . '/heritagebank_admin/api/get_cards.php';
        break;
    case 'api/admin/create_card':
        require __DIR__ . '/heritagebank_admin/api/create_card.php';
        break;
    case 'api/admin/update_card':
        require __DIR__ . '/heritagebank_admin/api/update_card.php';
        break;
    case 'api/admin/delete_card':
        require __DIR__ . '/heritagebank_admin/api/delete_card.php';
        break;
    case 'api/admin/get_transactions':
        require __DIR__ . '/heritagebank_admin/api/get_transactions.php';
        break;
    case 'api/admin/create_transaction':
        require __DIR__ . '/heritagebank_admin/api/create_transaction.php';
        break;
    case 'api/admin/update_transaction':
        require __DIR__ . '/heritagebank_admin/api/update_transaction.php';
        break;
    case 'api/admin/delete_transaction':
        require __DIR__ . '/heritagebank_admin/api/delete_transaction.php';
        break;

    default:
        http_response_code(404);
        // It's crucial to have an actual 404.php file or a robust error page.
        // For development, simply echoing "404 Not Found" is fine.
        // For production, create frontend/404.php for a user-friendly error.
        if (file_exists(__DIR__ . '/frontend/404.php')) {
            require __DIR__ . '/frontend/404.php';
        } else {
            echo "<h1>404 Not Found</h1><p>The page you requested could not be found.</p>";
        }
        break;
}
error_log("--- SCRIPT END ---");