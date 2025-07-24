<?php
// C:\xampp_lite_8_4\www\phpfile-main\Config.php (FINAL CORRECTED VERSION)

// IMPORTANT: This file is the SINGLE SOURCE OF TRUTH for loading .env and defining constants.
// Do NOT load Dotenv directly in other entry scripts (e.g., index.php, admin/index.php).

// Load environment variables from .env file FIRST!
// This requires the 'vlucas/phpdotenv' Composer package.
// Ensure 'vendor/autoload.php' path is correct relative to Config.php.
require_once __DIR__ . '/vendor/autoload.php';

$dotenvDir = __DIR__;
try {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvDir);
    $dotenv->load();

    // Configure Dotenv to also load into $_ENV and $_SERVER superglobals
    // This is often default, but explicitly setting it is good.
    // However, if getenv() isn't working, relying on $_ENV directly is the way to go.
    // $dotenv->safeLoad(); // Consider safeLoad() if you want to avoid overwriting existing env vars
} catch (Dotenv\Exception\InvalidPathException $e) {
    error_log("FATAL CONFIG ERROR: .env file not found or not readable in " . $dotenvDir . ". Error: " . $e->getMessage());
    die("Application configuration error. Please contact support. (Ref: ENV_LOAD_FAIL)");
} catch (Exception $e) {
    error_log("FATAL CONFIG ERROR: Unexpected error during .env load: " . $e->getMessage());
    die("Application configuration error: Unexpected .env load error. Please check server logs.");
}


/**
 * MongoDB Database Configuration File for HeritageBanking Admin Panel
 * ... (rest of the comments) ...
 */

// MongoDB Settings - USE $_ENV INSTEAD OF GETENV()
// Use null coalescing operator (??) for robustness if the variable might not exist
define('MONGODB_CONNECTION_URI', $_ENV['MONGODB_URI'] ?? null);
define('MONGODB_DB_NAME', $_ENV['MONGODB_DATABASE'] ?? 'HometownBankPA'); // Default if not set

define('TWO_FACTOR_CODE_LENGTH', 6); // Standard length for most authenticator apps
define('TWO_FACTOR_CODE_EXPIRY_MINUTES', 5); // Example: Code valid for 5 minutes

// Add a final check to ensure the URI is actually set after trying to define it
if (!defined('MONGODB_CONNECTION_URI') || empty(MONGODB_CONNECTION_URI)) {
    error_log("FATAL ERROR: MONGODB_CONNECTION_URI constant is still empty after Config.php execution. Check .env and access via _ENV.");
    die("Critical configuration error. MongoDB connection string missing.");
}

// --- END MongoDB Database Configuration (Only constants defined here) ---


// --- START: Required for Email and Admin Notifications ---
// Admin Email for notifications - Get from environment variable ADMIN_EMAIL or default.
define('ADMIN_EMAIL', $_ENV['ADMIN_EMAIL'] ?? 'hometownbankpa@gmail.com');

// Base URL of your project - Get from environment variable BASE_URL or platform specific env vars.
define('BASE_URL', $_ENV['BASE_URL'] ?? 'http://localhost/phpfile-main');

// SMTP Settings for Email Sending (using Gmail)
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? null);
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? null);
define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_ENCRYPTION', $_ENV['SMTP_ENCRYPTION'] ?? 'tls');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? ($_ENV['SMTP_USERNAME'] ?? null));
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Control application debugging via APP_DEBUG environment variable: set to 'true' for full errors, or 'false'
define('APP_DEBUG', ($_ENV['APP_DEBUG'] ?? 'false') === 'true'); // Default to false if not set

// Set PHP error reporting based on APP_DEBUG
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}


// --- START: Required for Currency Exchange and Transfer Rules ---
// Currency Exchange Rate API Configuration
define('EXCHANGE_RATE_API_BASE_URL', $_ENV['EXCHANGE_RATE_API_BASE_URL'] ?? 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', $_ENV['EXCHANGE_RATE_API_KEY'] ?? null);

// IMPORTANT CHANGE: Define explicitly the allowed currencies for ALL transfers.
define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);

// Optional: Define a list of all currencies your bank internally supports for accounts.
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY', 'NGN']);

// Session settings (important for login) - Ensure session_start() is called once per request
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax'); // Or 'Strict' for higher security
    session_start();
}

// Set default timezone - from .env or default
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Lagos');

// --- END: Required for Currency Exchange and Transfer Rules ---