<?php
// PHP error reporting - MUST be at the very top
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// session_start(); // REMOVED - This should be called by the file that includes this one.

require_once '../Config.php'; // Path to Config.php from heritagebank/users/

// Use MongoDB PHP Library
require_once __DIR__ . '/vendor/autoload.php'; // Adjust path if Composer's autoload is elsewhere
use MongoDB\Client;
use MongoDB\BSON\ObjectId; // For working with MongoDB's unique IDs

header('Content-Type: application/json');

// Check if the user is NOT logged in or if it's not an AJAX request
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access or invalid request.']);
    exit;
}

// Get the user ID from the session.
// IMPORTANT: Ensure $_SESSION['user_id'] stores the string representation of the MongoDB ObjectId.
$user_id_str = $_SESSION['user_id'];
$user_objectId = null;

try {
    // Validate and convert the session user_id string to a MongoDB ObjectId
    $user_objectId = new ObjectId($user_id_str);
} catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
    // If the user_id from session is not a valid ObjectId, this is a critical error.
    error_log("ERROR: Invalid user ID in session (not a valid MongoDB ObjectId): " . $user_id_str);
    echo json_encode(['success' => false, 'message' => 'Invalid user session ID format.']);
    exit;
}


// Establish MongoDB connection
$mongoClient = null; // Initialize to null
try {
    // Assuming MONGO_URI and MONGO_DB are defined in Config.php
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $database->selectCollection('accounts');

    $user_accounts = [];
    // Fetch accounts for the logged-in user using the ObjectId
    $cursor = $accountsCollection->find(['user_id' => $user_objectId]);

    foreach ($cursor as $accountDoc) {
        // Convert BSON document to associative array for easier handling
        $account = (array) $accountDoc;

        // Ensure these keys exist, provide defaults if they might not
        $account_id = $account['_id'] ?? null;
        $account_name = $account['account_name'] ?? 'N/A'; // Assuming 'account_name' exists in your MongoDB schema
        $account_number = $account['account_number'] ?? 'N/A';
        $account_type = $account['account_type'] ?? 'N/A';
        $balance = $account['balance'] ?? 0.00;

        // You might want to format account_number for display, e.g., mask some digits
        $display_account_number = '...' . substr($account_number, -4);

        // Add relevant account data to the array
        $user_accounts[] = [
            'id' => (string) $account_id, // Convert ObjectId to string for JSON output
            'account_name' => $account_name,
            'account_number' => $account_number, // Full number for internal use if needed
            'display_account_number' => $display_account_number, // Masked for display
            'account_type' => $account_type,
            'balance' => $balance
        ];
    }

    echo json_encode(['success' => true, 'accounts' => $user_accounts]);

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Catch any MongoDB driver specific exceptions
    error_log("Error fetching user accounts (MongoDB AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    // Catch any other general exceptions
    error_log("Error fetching user accounts (General AJAX): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => "An unexpected error occurred: " . $e->getMessage()]);
} finally {
    // In modern PHP, connection pooling often handles this, but explicitly closing can be done if needed
    // However, the MongoDB PHP driver doesn't typically require an explicit close() method on the Client object
    // as it manages connections internally.
}
exit;
?>