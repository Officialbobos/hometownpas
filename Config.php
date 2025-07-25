<?php
echo "CONFIG_LOADED_V_FINAL"; // <-- ADD THIS LINE

/**
 * MongoDB Database Configuration File for HeritageBanking Admin Panel
 *
 * This file contains the MongoDB connection parameters.
 *
 * IMPORTANT:
 * 1. For PRODUCTION and secure local development: NEVER hardcode sensitive information
 * (like database passwords, Gmail App Passwords, API Keys) directly into this file
 * or commit them to version control.
 * 2. Instead, always set them as ENVIRONMENT VARIABLES.
 * - For local development: Use a `.env` file (which is ignored by Git).
 * - For live deployment: Set them in your hosting platform's dashboard (e.g., Railway, Render).
 * 3. The `getenv()` calls are already set up to read from these environment variables.
 * 4. Ensure this file is not directly accessible via the web server (e.g., place it outside the web root
 * or configure your web server to deny direct access).
 */

// --- MongoDB Database Configuration ---
// MONGODB_URI should be set as an environment variable (in .env locally, or hosting platform).
define('MONGODB_CONNECTION_URI', getenv('MONGODB_URI'));

// The default database name your application will use within the MongoDB cluster.
// This can be overridden by an environment variable 'MONGODB_DATABASE'.
// It defaults to 'HometownBankPA' if not explicitly set in the environment.
define('MONGODB_DB_NAME', getenv('MONGODB_DATABASE') ?: 'HometownBankPA');


// Ensure the MongoDB PHP driver is installed and enabled in your php.ini.
// Composer's autoloader needs to be included in your main application entry point (e.g., indx.php).
// For .env files to work locally, 'vlucas/phpdotenv' should be installed and loaded early.
use MongoDB\Client;
use MongoDB\Exception\Exception as MongoDBException;

try {
    // Create a new MongoDB client instance using the URI from environment variables.
    $mongoClient = new Client(MONGODB_CONNECTION_URI);

    // Select the database using the defined database name.
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    // Optional: Ping the server to verify the connection.
    $mongoClient->admin->ping();
    // echo "Successfully connected to MongoDB database: " . MONGODB_DB_NAME . "<br>"; // For debugging, remove for production

} catch (MongoDBException $e) {
    error_log("FATAL ERROR: Failed to connect to MongoDB. " . $e->getMessage());
    die("Database connection error. Please try again later. If the issue persists, contact support.");
} catch (Exception $e) {
    error_log("FATAL ERROR: An unexpected error occurred during MongoDB connection. " . $e->getMessage());
    die("An unexpected database error occurred. Please try again later.");
}

// --- END MongoDB Database Configuration ---


// --- START: Required for Email and Admin Notifications ---
// Admin Email for notifications - Get from environment variable ADMIN_EMAIL or default.
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'hometownbankpa@gmail.com');

// Base URL of your project - Get from environment variable BASE_URL or platform specific env vars.
define('BASE_URL', getenv('RAILWAY_PUBLIC_DOMAIN') ? 'https://' . getenv('RAILWAY_PUBLIC_DOMAIN') : (getenv('BASE_URL') ?: 'http://localhost/heritagebank'));

// SMTP Settings for Email Sending (using Gmail)
// IMPORTANT: Store these in environment variables for production and local .env file!
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com'); // Can default for common services like Gmail
define('SMTP_USERNAME', getenv('SMTP_USERNAME')); // Your full Gmail address - MUST be from env
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD')); // Your Gmail App Password - MUST be from env
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587); // Default to 587 for TLS
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // Default to 'tls'
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME')); // Fallback to SMTP_USERNAME
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'HomeTown Bank PA');
// --- END: Required for Email and Admin Notifications ---


// Optional: Error Reporting (adjust for production)
// Control via APP_DEBUG environment variable: set to 'true' for full errors, or 'false'
ini_set('display_errors', getenv('APP_DEBUG') ? 1 : 0);
ini_set('display_startup_errors', getenv('APP_DEBUG') ? 1 : 0);
error_reporting(getenv('APP_DEBUG') ? E_ALL : 0);

// For production: it's highly recommended to disable display errors and log errors instead
// ini_set('display_errors', 'Off');
// ini_set('log_errors', 'On');
// ini_set('error_log', '/path/to/your/php-error.log'); // Specify your error log file path on server

// --- START: Required for Currency Exchange and Transfer Rules ---

// Currency Exchange Rate API Configuration
// IMPORTANT: Get API key from environment variable for production and local .env file!
define('EXCHANGE_RATE_API_BASE_URL', getenv('EXCHANGE_RATE_API_BASE_URL') ?: 'https://v6.exchangerate-api.com/v6/');
define('EXCHANGE_RATE_API_KEY', getenv('EXCHANGE_RATE_API_KEY')); // This MUST be from env

// --- IMPORTANT CHANGE: Define explicitly the allowed currencies for ALL transfers. ---
// This enforces that all transfers (internal and external) can ONLY be made in GBP or EUR.
define('ALLOWED_TRANSFER_CURRENCIES', ['GBP', 'EUR', 'USD']);

// Optional: Define a list of all currencies your bank internally supports for accounts.
// This can be useful for dropdowns or validation across your application.
define('SUPPORTED_CURRENCIES', ['EUR', 'USD', 'GBP', 'CAD', 'AUD', 'JPY']);

// --- END: Required for Currency Exchange and Transfer Rules ---