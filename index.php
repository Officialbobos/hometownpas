<?php
// This file serves as the main entry point for the HeritageBanking Admin Panel.
// It includes essential configuration, defines the base URL, and handles routing
// to different parts of the application based on the user's authentication status.

ob_start(); // Start output buffering at the very beginning to prevent "headers already sent" errors.

// Load essential configuration and constants
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php';

// Start the session if not already started. Config.php should ideally be the sole place for session_start().
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- NEW DEBUGGING LOGS FOR SESSION ---
error_log("--- index.php Start ---");
error_log("Index.php: Session ID on arrival: " . session_id());
error_log("Index.php: Session Contents on arrival: " . print_r($_SESSION, true));
// --- END NEW DEBUGGING LOGS ---


// Check if the user is logged in
$isLoggedIn = isset($_SESSION['user_id']) && isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
$userRole = $_SESSION['role'] ?? 'guest'; // Default to 'guest' if not set

// Get the requested URL path (e.g., /login, /dashboard, /admin/users)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Ensure $requestUri is a string, defaulting to '/' if parse_url returns null
if ($requestUri === null) {
    $requestUri = '/';
}

// Determine the base path of the application from BASE_URL constant
// This ensures routing works whether the app is at the root or in a subfolder (e.g., /phpfile-main)
$baseUrlPath = parse_url(BASE_URL, PHP_URL_PATH);

// Ensure $baseUrlPath is a string (even empty) before performing string operations
// This often happens if BASE_URL is just a domain like 'http://example.com' without a path
if ($baseUrlPath === null) {
    $baseUrlPath = '/';
}

// Ensure $baseUrlPath ends with a slash if it's not just '/'
if ($baseUrlPath !== '/' && substr($baseUrlPath, -1) !== '/') {
    $baseUrlPath .= '/';
}

// Remove the BASE_URL_PATH from the request URI to get the clean route
if (strpos($requestUri, $baseUrlPath) === 0) {
    $route = substr($requestUri, strlen($baseUrlPath));
} else {
    // Fallback if BASE_URL_PATH is not found at the beginning (shouldn't happen with correct config)
    $route = $requestUri;
}

// Remove leading/trailing slashes for consistent routing
$route = trim($route, '/');

// Default page for logged-in users is the dashboard
if ($isLoggedIn) {
    $defaultPage = 'dashboard';
} else {
    // Default page for guests is the login page
    $defaultPage = 'login';
}

// If the route is empty, use the default page based on login status
$route = $route === '' ? $defaultPage : $route;

// Include Composer's autoloader for MongoDB classes, etc.
require_once __DIR__ . '/vendor/autoload.php';

// --- Establish MongoDB connection once for all requests that might need it ---
// This is more efficient than connecting in every included file.
// Assuming MONGODB_CONNECTION_URI and MONGODB_DB_NAME are defined in Config.php
$mongoClient = null;
$mongoDb = null;
try {
    $mongoClient = new MongoDB\Client(MONGODB_CONNECTION_URI);
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("CRITICAL ERROR: Could not connect to MongoDB from index.php. " . $e->getMessage());
    // For production, you might want a more graceful error page or redirect
    http_response_code(500);
    die("A critical database connection error occurred. Please try again later.");
}

// --- Routing Logic ---
switch ($route) {
    case 'login':
        if ($isLoggedIn) {
            // If already logged in, redirect to dashboard
            header('Location: ' . rtrim(BASE_URL, '/') . '/dashboard'); // Corrected
            exit;
        }
        include 'frontend/login.php';
        break;

    case 'dashboard':
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/dashboard.php';
        break;

    case 'logout':
        // Destroy session and redirect to login
        session_unset();
        session_destroy();
        // Clear cookies if session_name() is known
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
        exit;

    case 'admin':
        // Admin dashboard or default admin view
        if (!$isLoggedIn || $userRole !== 'admin') {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'heritagebank_admin/dashboard.php';
        break;

    case 'admin/users/create_user':
    case 'admin/users/manage_users':
    case 'admin/users/edit_user':
    case 'admin/users/account_status_management':
    case 'admin/users/generate_bank_card':
    case 'admin/users/manage_user_funds':
    case 'admin/users/transactions_management':
    case 'admin/users/generate_mock_transaction':
        if (!$isLoggedIn || $userRole !== 'admin') {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        // Include admin user management pages
        include 'heritagebank_admin/users/' . str_replace('admin/users/', '', $route) . '.php';
        break;

    case 'api/admin/fetch_user_accounts':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
            exit;
        }
        include 'heritagebank_admin/users/fetch_user_accounts.php'; // Adjusted filename from fetch_user_acounts
        break;
    
    // Frontend user routes
    case 'accounts':
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/accounts.php';
        break;

    case 'my_cards': // Corrected path to point to a new my_cards.php for frontend
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/my_cards.php';
        break;

    case 'verify_code':
        // For 2FA, we expect 'auth_step' to be 'awaiting_2fa' and 'temp_user_id' to be set.
        // 'user_id' is only set *after* 2FA is successfully completed.
        // If these conditions are not met, redirect to login.
        if (!isset($_SESSION['auth_step']) || $_SESSION['auth_step'] !== 'awaiting_2fa' || !isset($_SESSION['temp_user_id'])) {
            error_log("Index.php: Verify_code route accessed without proper 2FA session state. Redirecting to login. Reason: auth_step=" . ($_SESSION['auth_step'] ?? 'NOT SET') . ", temp_user_id=" . ($_SESSION['temp_user_id'] ?? 'NOT SET'));
            $_SESSION['message'] = "Your session has expired or is invalid. Please log in again."; // Optional: set message for login page
            $_SESSION['message_type'] = "error"; // Optional: set message type
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        error_log("Index.php: 2FA session state valid. Including verify_code.php."); // Added this for clear success logging
        include 'frontend/verify_code.php';
        break;

    case 'bank_cards':
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        // This is the full HTML page for displaying and ordering cards
        include 'frontend/bank_cards.php';
        break;

    case 'set_card_pin': // New route for setting card PIN
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/set_card_pin.php';
        break;
    
    case 'transfer': // Route for transfers
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/transfer.php';
        break;

    case 'statements': // Route for statements
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/statements.php';
        break;
    
    case 'profile': // Route for user profile
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/profile.php';
        break;
    
    case 'settings': // Route for user settings
        if (!$isLoggedIn) {
            // Note: Assuming BASE_ENV_URL is defined and correct for settings page redirect
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected (was BASE_ENV_URL back to BASE_URL)
            exit;
        }
        include 'frontend/settings.php';
        break;

    case 'customer-service': // Route for customer service
        if (!$isLoggedIn) {
            header('Location: ' . rtrim(BASE_URL, '/') . '/login'); // Corrected
            exit;
        }
        include 'frontend/customer_service.php';
        break;


    // --- API Endpoints ---
    case 'api/send_two_factor_code':
        if (!isset($_SESSION['temp_user_id'])) { // Check for temp_user_id from login.php
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized. No active login attempt.']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/send_two_factor_code.php';
        break;

    case 'api/verify_two_factor_code':
        if (!isset($_SESSION['temp_user_id'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized. No active login attempt.']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/verify_two_factor_code.php';
        break;

    case 'api/submit_transfer':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/submit_transfer.php';
        break;
    
    case 'api/get_exchange_rate':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/get_exchange_rate.php';
        break;

    case 'api/transfer_history':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/transfer_history.php';
        break;
    
    case 'api/get_account_balance': // This might be used by dashboard/accounts for a single account
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/get_account_balance.php';
        break;

    // --- NEW API ENDPOINTS FOR CARDS.JS AND ACCOUNT FETCHING ---
    case 'api/get_user_accounts': // New API endpoint for fetching ALL user accounts
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/get_user_accounts.php'; // Create this file
        break;

    case 'api/get_user_cards': // New API endpoint for fetching ALL user cards
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/get_user_cards.php'; // Create this file
        break;

    case 'api/order_card': // New API endpoint for submitting a new card order
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/order_card.php'; // Create this file
        break;
    // --- END NEW API ENDPOINTS ---

    case 'api/admin/create_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/create_user.php';
        break;

    case 'api/admin/edit_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/edit_user.php';
        break;

    case 'api/admin/delete_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/delete_user.php';
        break;

    case 'api/admin/update_user_status':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/update_user_status.php';
        break;

    case 'api/admin/update_user_funds':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/update_user_funds.php';
        break;

    case 'api/admin/generate_bank_card':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/generate_bank_card.php';
        break;

    case 'api/admin/generate_mock_transaction':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/generate_mock_transaction.php';
        break;

    case 'api/admin/update_transaction_status':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/update_transaction_status.php';
        break;

    case 'api/admin/delete_transaction':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        // Pass the MongoDB object to the API handler
        include 'api/admin/delete_transaction.php';
        break;

    // --- Fallback for 404 Not Found ---
    default:
        http_response_code(404);
        include '404.php'; // Make sure you have a 404.php file
        break;
}

// Close MongoDB client connection if it was successfully opened.
// This is important to free up resources.
if ($mongoClient) {
    // In a long-running script, you might want to call $mongoClient->close();
    // For a typical web request, PHP's garbage collection will handle it,
    // but explicit unsetting can be good practice.
    unset($mongoClient);
    unset($mongoDb);
}

ob_end_flush(); // Flush the output buffer at the very end