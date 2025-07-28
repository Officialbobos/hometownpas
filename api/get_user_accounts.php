<?php
// api/get_user_accounts.php

// Ensure NO spaces, newlines, or other characters before this <?php tag.
// Also, ensure this file is saved as "UTF-8 without BOM" in your editor.

header('Content-Type: application/json');

global $mongoDb;
if (!$mongoDb) {
    error_log("ERROR: MongoDB connection not available in api/get_user_accounts.php.");
    http_response_code(500); // <-- Set code BEFORE echoing JSON
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit; // Crucial to stop execution
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // <-- Set code BEFORE echoing JSON
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit; // Crucial to stop execution
}

try {
    $userId = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
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

    http_response_code(200); // <-- MOVED THIS LINE UP
    echo json_encode(['success' => true, 'accounts' => $accounts]);
    exit; // <-- ADDED THIS to prevent any further output

} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    http_response_code(500); // <-- Set code BEFORE echoing JSON
    echo json_encode(['success' => false, 'message' => 'Database error fetching accounts.']);
    exit; // Crucial to stop execution
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    http_response_code(500); // <-- Set code BEFORE echoing JSON
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    exit; // Crucial to stop execution
}