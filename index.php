<?php
// index.php

// --- START TEMPORARY DEBUG CODE ---
// Force display errors for debugging on Render (remove for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


error_log("--- SCRIPT START ---");
require __DIR__ . '/vendor/autoload.php';


// Check if MongoDB extension is loaded
if (!extension_loaded('mongodb')) {
    die('<h1>FATAL ERROR: MongoDB PHP extension is not loaded!</h1><p>Please check your Dockerfile, build logs, and PHP configuration.</p>');
}

// Check if MongoDB Client class exists (Composer autoloading)
if (!class_exists('MongoDB\Client')) {
    die('<h1>FATAL ERROR: MongoDB\Client class not found!</h1><p>This usually means Composer\'s autoloader failed or the MongoDB driver was not correctly installed/enabled.</p><p>Ensure `composer install` ran successfully and `docker-php-ext-enable mongodb` completed in your Dockerfile.</p>');
}

// --- END TEMPORARY DEBUG CODE ---


session_start();
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php'; // Contains getMongoDBClient()

error_log("--- After requires ---");

use MongoDB\Client;

// Global MongoDB connection
$mongoClient = null;
$mongoDb = null;
try {
    error_log("--- Attempting to get MongoDB Client in index.php ---"); // <-- NEW LOG
    $mongoClient = getMongoDBClient();
    error_log("--- MongoDB Client obtained. Attempting to select database ---"); // <-- NEW LOG
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    error_log("--- MongoDB database selected successfully ---"); // <-- NEW LOG
} catch (Exception $e) {
    error_log("Failed to connect to MongoDB in index.php: " . $e->getMessage()); // <-- MODIFIED LOG
    die("<h1>Service Unavailable: Database connection failed.</h1><p>Error: " . htmlspecialchars($e->getMessage()) . "</p>");
}

error_log("--- MongoDB connection established and DB selected. Proceeding to routing. ---"); // <-- NEW LOG

$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script_name = dirname($_SERVER['SCRIPT_NAME']);
$path = substr($request_uri, strlen($script_name));
$path = trim($path, '/');

// Authenticated routes
$authenticated_routes = [
    'dashboard',
    'profile',
    'transfer',
    'transactions',
    'bank_cards', // Add this
    'api/get_user_cards',
    'api/get_user_accounts', // Add this
    'api/order_card',        // Add this
    // Add other routes that require authentication
];

// Check authentication for authenticated routes
if (in_array($path, $authenticated_routes)) {
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
}

// Router logic
switch ($path) {
    case '':
    case 'home':
        require __DIR__ . '/frontend/home.php';
        break;
    case 'login':
        require __DIR__ . '/auth/login.php';
        break;
    case 'register':
        require __DIR__ . '/auth/register.php';
        break;
    case 'logout':
        require __DIR__ . '/auth/logout.php';
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
    case 'bank_cards': // Route for the bank cards page
        require __DIR__ . '/frontend/bank_cards.php';
        break;

    // API Routes
    case 'api/get_user_cards':
        require __DIR__ . '/api/get_user_cards.php';
        break;
    case 'api/get_user_accounts': // New API route for fetching user accounts
        require __DIR__ . '/api/get_user_accounts.php';
        break;
    case 'api/order_card': // New API route for ordering a card
        require __DIR__ . '/api/order_card.php';
        break;
    case 'api/login':
        require __DIR__ . '/api/login.php';
        break;
    case 'api/register':
        require __DIR__ . '/api/register.php';
        break;
    // ... potentially other API routes

    // Admin Panel Routes
    case 'admin':
        require __DIR__ . '/heritagebank_admin/admin_dashboard.php';
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
    // API for admin
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
        require __DIR__ . '/frontend/404.php'; // Or a simple 404 message
        break;
}
