<?php
// api/get_user_accounts.php

// 1. Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 2. Include configuration and functions to establish MongoDB connection
require_once __DIR__ . '/../Config.php';    // Assuming Config.php is one level up from 'api'
require_once __DIR__ . '/../functions.php';  // Assuming functions.php is one level up from 'api'

use MongoDB\Client; // <--- ADD THIS LINE to correctly type-hint Client
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

try { // Wrap the initial connection logic in try-catch as well for robustness

    // 3. Get MongoDB Client instance (assuming getMongoDBClient() returns a Client object)
    $mongoClientInstance = getMongoDBClient();

    // Check if MongoDB client is available and is indeed a Client object
    if (!$mongoClientInstance || !($mongoClientInstance instanceof Client)) {
        error_log("ERROR: MongoDB Client instance not available or incorrect type in api/get_user_accounts.php. Type: " . gettype($mongoClientInstance));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection error (Client initialization).']);
        exit;
    }

    // Now, select the specific database using the MONGODB_DB_NAME constant
    if (!defined('MONGODB_DB_NAME')) {
        error_log("ERROR: MONGODB_DB_NAME is not defined in Config.php or environment in api/get_user_accounts.php.");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server configuration error: Database name not set.']);
        exit;
    }
    $mongoDb = $mongoClientInstance->selectDatabase(MONGODB_DB_NAME); // <--- FIX: Select the database from the client

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        error_log("DEBUG: Session User ID: Not Set in get_user_accounts.php. Redirecting to login.");
        http_response_code(401); // Unauthorized
        echo json_encode(['success' => false, 'message' => 'User not authenticated. Please log in again.']);
        exit;
    }

    $userId = $_SESSION['user_id'];
    if (!($userId instanceof ObjectId)) {
        // Convert userId from session string to MongoDB\BSON\ObjectId for queries
        try {
            $userId = new ObjectId($userId);
            error_log("DEBUG: Converted userId to ObjectId in get_user_accounts.php: " . (string)$userId);
        } catch (Exception $e) {
            error_log("ERROR: Invalid userId format from session in get_user_accounts.php: " . $e->getMessage());
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid user ID format in session.']);
            exit;
        }
    } else {
        error_log("DEBUG: userId is already ObjectId in get_user_accounts.php: " . (string)$userId);
    }

    // Now, $mongoDb is the actual database object, so selectCollection() will work
    $accountsCollection = $mongoDb->selectCollection('accounts');

    $cursor = $accountsCollection->find(
        ['user_id' => $userId],
        ['projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]]
    );

    $accounts = [];
    foreach ($cursor as $accountDoc) {
        $accounts[] = [
            'id' => (string)$accountDoc['_id'],
            'account_type' => $accountDoc['account_type'] ?? 'Account',
            'display_account_number' => '****' . substr($accountDoc['account_number'] ?? '', -4),
            'balance' => $accountDoc['balance'] ?? 0.00,
            'currency' => $accountDoc['currency'] ?? 'USD'
        ];
    }

    http_response_code(200);
    echo json_encode(['status' => true, 'accounts' => $accounts]); // Use 'status' as per cards.js
    exit;

} catch (MongoDBDriverException $e) {
    error_log("MongoDB EXCEPTION in get_user_accounts.php: " . $e->getMessage() . " Code: " . $e->getCode());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error fetching accounts.']);
    exit;
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_accounts.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    exit;
}