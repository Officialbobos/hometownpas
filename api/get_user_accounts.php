<?php
// api/get_user_accounts.php

// 1. Start the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 2. Include configuration and functions to establish MongoDB connection
require_once __DIR__ . '/../Config.php';      // Assuming Config.php is one level up from 'api'
require_once __DIR__ . '/../functions.php';   // Assuming functions.php is one level up from 'api'

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// 3. Get MongoDB Client
$mongoDb = getMongoDBClient(); // This function should be in functions.php and return the MongoDB\Client instance

if (!$mongoDb) {
    error_log("ERROR: MongoDB connection not available in api/get_user_accounts.php.");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

try {
    $userId = new ObjectId($_SESSION['user_id']);
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
    error_log("MongoDB EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error fetching accounts.']);
    exit;
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    exit;
}