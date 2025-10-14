<?php
// IMPORTANT: This file is the SINGLE SOURCE OF TRUTH for loading .env and defining constants.
// Do NOT load Dotenv directly in other entry scripts (e.g., index.php, admin/index.php).

// NOTE: Composer autoloader is now expected to be loaded by the entry script (e.g., index.php).

// Define the directory where your .env file might be located.
// Assuming Config.php and .env are in the root where your Dockerfile is.
$dotenvDir = __DIR__;

// Load environment variables using phpdotenv ONLY if a .env file exists.
// On hosting platforms like Render, environment variables are often injected directly
// into the process environment (e.g., $_ENV, $_SERVER, getenv()),
// and a physical .env file is not present or needed.
if (file_exists($dotenvDir . '/.env')) {
    try {
        // Dotenv\Dotenv is available because index.php loaded the autoloader.
        $dotenv = Dotenv\Dotenv::createImmutable($dotenvDir);
        $dotenv->load();
    } catch (Dotenv\Exception\InvalidPathException $e) {
        error_log("WARNING: .env file found but not readable or path invalid in " . $dotenvDir . ". Error: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("WARNING: Unexpected error during .env file load: " . $e->getMessage());
    }
} else {
    error_log("NOTICE: .env file not found at " . $dotenvDir . "/.env. Assuming environment variables are pre-loaded by the system.");
}

// --- START: DEBUGGING ENVIRONMENT VARIABLES (Leaving this block untouched) ---
error_log("--- PHP Environment Variable Debug Start ---");
error_log("Listing contents of \$_ENV:");
foreach ($_ENV as $key => $value) {
    if (strpos($key, 'PASS') !== false || strpos($key, 'URI') !== false || strpos($key, 'KEY') !== false || strpos($key, 'SECRET') !== false) {
        error_log("    _ENV: " . $key . " = [SENSITIVE VALUE]");
    } else {
        error_log("    _ENV: " . $key . " = " . $value);
    }
}

error_log("Listing selected variables via getenv():");
$varsToCheck = [
    'MONGODB_CONNECTION_URI',
    'MONGODB_DB_NAME',
    'BASE_URL',
    'ADMIN_EMAIL',
    'SMTP_USERNAME',
    'SMTP_PASSWORD',
    'EXCHANGE_RATE_API_KEY',
    'APP_DEBUG',
    'APP_TIMEZONE',
    'B2_APPLICATION_KEY_ID',
    'B2_APPLICATION_KEY',
    'B2_BUCKET_NAME',
    'B2_REGION',
    'B2_ENDPOINT'
];
foreach ($varsToCheck as $varName) {
    $value = getenv($varName);
    if ($value !== false) {
        if (strpos($varName, 'PASS') !== false || strpos($varName, 'URI') !== false || strpos($varName, 'KEY') !== false || strpos($varName, 'SECRET') !== false) {
            error_log("    getenv(): " . $varName . " = [SENSITIVE VALUE]");
        } else {
            error_log("    getenv(): " . $varName . " = " . $value);
        }
    } else {
        error_log("    getenv(): " . $varName . " = NOT SET");
    }
}
error_log("--- PHP Environment Variable Debug End ---");
// --- END: DEBUGGING ENVIRONMENT VARIABLES ---


/**
 * MongoDB Database Configuration File for HeritageBanking Admin Panel
 */

// MongoDB Settings - USE $_ENV or getenv() with Null Coalesce (??)
define('MONGODB_CONNECTION_URI', $_ENV['MONGODB_CONNECTION_URI'] ?? getenv('MONGODB_CONNECTION_URI') ?? null);
define('MONGODB_DB_NAME', $_ENV['MONGODB_DB_NAME'] ?? getenv('MONGODB_DB_NAME') ?? 'HometownBankPA'); // Default if not set

// --- REMOVED TWO_FACTOR_CODE_LENGTH AND TWO_FACTOR_CODE_EXPIRY_MINUTES ---

if (!defined('MONGODB_CONNECTION_URI') || empty(MONGODB_CONNECTION_URI)) {
    error_log("FATAL ERROR: MONGODB_CONNECTION_URI constant is still empty after Config.php execution. Check environment variables (e.g., on Render dashboard).");
    die("Critical configuration error. MongoDB connection string missing. Please contact support.");
}


// --- START: Required for Email and Admin Notifications ---
define('ADMIN_EMAIL', $_ENV['ADMIN_EMAIL'] ?? getenv('ADMIN_EMAIL') ?? 'hometownbankpa@gmail.com');
define('BASE_URL', $_ENV['BASE_URL'] ?? getenv('BASE_URL') ?? 'http://localhost/phpfile-main');

define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?? 'smtp.gmail.com');
define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME') ?? null);
define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD') ?? null);
define('SMTP_PORT', (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?? 587));
define('SMTP_ENCRYPTION', $_ENV['SMTP_ENCRYPTION'] ?? getenv('SMTP_ENCRYPTION') ?? 'tls');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL') ?? ($_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME') ?? null));
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?? 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Control application debugging via APP_DEBUG environment variable: set to 'true' for full errors, or 'false'
define('APP_DEBUG', ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'false') === 'true'); // Default to false if not set

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
define('EXCHANGE_RATE_API_BASE_URL', $_ENV['EXCHANGE_RATE_API_BASE_URL'] ?? getenv('EXCHANGE_RATE_API_BASE_URL') ?? 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', $_ENV['EXCHANGE_RATE_API_KEY'] ?? getenv('EXCHANGE_RATE_API_KEY') ?? null);

define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD', 'CAD']);
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY', 'NGN']);

// REMOVED SESSION_START() AND RELATED INI_SET FROM HERE.

// Set default timezone - from .env or default
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'Africa/Lagos');

// --- END: Required for Currency Exchange and Transfer Rules ---

// --- START: Backblaze B2 S3 Compatible API Configuration ---
define('B2_APPLICATION_KEY_ID', $_ENV['B2_APPLICATION_KEY_ID'] ?? getenv('B2_APPLICATION_KEY_ID') ?? null);
define('B2_APPLICATION_KEY', $_ENV['B2_APPLICATION_KEY'] ?? getenv('B2_APPLICATION_KEY') ?? null);
define('B2_BUCKET_NAME', $_ENV['B2_BUCKET_NAME'] ?? getenv('B2_BUCKET_NAME') ?? null);
define('B2_REGION', $_ENV['B2_REGION'] ?? getenv('B2_REGION') ?? null);
define('B2_ENDPOINT', $_ENV['B2_ENDPOINT'] ?? getenv('B2_ENDPOINT') ?? null);

if (empty(B2_APPLICATION_KEY_ID) || empty(B2_APPLICATION_KEY) || empty(B2_BUCKET_NAME) || empty(B2_REGION) || empty(B2_ENDPOINT)) {
    error_log("WARNING: One or more Backblaze B2 constants are not set. Profile image functionality may be impaired.");
}
// --- END: Backblaze B2 S3 Compatible API Configuration ---