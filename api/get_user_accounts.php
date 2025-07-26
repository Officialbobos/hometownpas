<?php
// api/get_user_accounts.php
header('Content-Type: application/json');

global $mongoDb;
if (!$mongoDb) {
    error_log("ERROR: MongoDB connection not available in api/get_user_accounts.php.");
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    http_response_code(500);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    http_response_code(401);
    exit;
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

    echo json_encode(['success' => true, 'accounts' => $accounts]);
    http_response_code(200);

} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error fetching accounts.']);
    http_response_code(500);
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_accounts.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    http_response_code(500);
}