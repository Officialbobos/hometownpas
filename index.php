<?php
echo "INDEX_LOADED_V_FINAL"; // <-- CONFIRMATION LINE
// CONFIRM_CHANGE_INDEX_20250724 // <-- CONFIRMATION LINE

// This file serves as the main entry point for the HeritageBanking Admin Panel.
// It includes essential configuration, defines the base URL, and handles routing
// to different parts of the application based on the user's authentication status.

// Load essential configuration and constants
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php';

// Start the session if not already started (Config.php handles session_start, but double check)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? 'guest'; // Default to 'guest' if not set

// Define the base path for routing
// This is relative to the document root (e.g., / for example.com, or /my_app/ for example.com/my_app)
$basePath = '/'; // Assuming the app is at the root of the domain/subdomain on Render

// Get the requested URL path (e.g., /login, /dashboard, /admin/users)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove the basePath if the app is not at the root (e.g., for localhost/phpfile-main)
// On Render, BASE_URL might be different from $requestUri prefix.
// This handles cases where BASE_URL has a subfolder path like /phpfile-main
if (BASE_URL !== 'http://localhost/phpfile-main' && strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

// Remove leading/trailing slashes for consistent routing
$requestUri = trim($requestUri, '/');

// Default page for logged-in users is the dashboard
if ($isLoggedIn) {
    $defaultPage = 'dashboard';
} else {
    // Default page for guests is the login page
    $defaultPage = 'login';
}

// Determine the requested route, defaulting based on login status
$route = $requestUri === '' ? $defaultPage : $requestUri;

// --- Routing Logic ---
switch ($route) {
    case 'login':
        if ($isLoggedIn) {
            // If already logged in, redirect to dashboard
            header('Location: ' . BASE_URL . '/dashboard');
            exit;
        }
        include 'frontend/login.php';
        break;

    case 'dashboard':
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
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
        header('Location: ' . BASE_URL . '/login');
        exit;

    case 'admin':
        // Admin dashboard or default admin view
        if (!$isLoggedIn || $userRole !== 'admin') {
            header('Location: ' . BASE_URL . '/login');
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
            header('Location: ' . BASE_URL . '/login');
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
        include 'heritagebank_admin/users/fetch_user_acounts.php'; // Adjusted filename
        break;
    
    // Frontend user routes
    case 'accounts':
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        include 'frontend/accounts.php';
        break;

    case 'my_cards': // Corrected path to point to a new my_cards.php for frontend
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        include 'frontend/my_cards.php';
        break;

    case 'verify_code':
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        include 'frontend/verify_code.php';
        break;

    case 'bank_cards':
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        include 'frontend/bank_cards.php';
        break;

    case 'set_card_pin': // New route for setting card PIN
        if (!$isLoggedIn) {
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
        include 'frontend/set_card_pin.php';
        break;


    // --- API Endpoints ---
    case 'api/send_two_factor_code':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/send_two_factor_code.php';
        break;

    case 'api/verify_two_factor_code':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/verify_two_factor_code.php';
        break;

    case 'api/submit_transfer':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/submit_transfer.php';
        break;
    
    case 'api/get_exchange_rate':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/get_exchange_rate.php';
        break;

    case 'api/transfer_history':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/transfer_history.php';
        break;
    
    case 'api/get_account_balance':
        if (!$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/get_account_balance.php';
        break;

    case 'api/admin/create_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/create_user.php';
        break;

    case 'api/admin/edit_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/edit_user.php';
        break;

    case 'api/admin/delete_user':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/delete_user.php';
        break;

    case 'api/admin/update_user_status':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/update_user_status.php';
        break;

    case 'api/admin/update_user_funds':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/update_user_funds.php';
        break;

    case 'api/admin/generate_bank_card':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/generate_bank_card.php';
        break;

    case 'api/admin/generate_mock_transaction':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/generate_mock_transaction.php';
        break;

    case 'api/admin/update_transaction_status':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/update_transaction_status.php';
        break;

    case 'api/admin/delete_transaction':
        if (!$isLoggedIn || $userRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        include 'api/admin/delete_transaction.php';
        break;

    // --- Fallback for 404 Not Found ---
    default:
        http_response_code(404);
        include '404.php'; // Make sure you have a 404.php file
        break;
}