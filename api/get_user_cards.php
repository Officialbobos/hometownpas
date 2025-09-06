<?php
// Path: C:\xampp\htdocs\hometownbank\api\get_user_cards.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

$response_data = ['status' => 'error', 'message' => 'An unknown error occurred.'];
$statusCode = 500;

try {
    $mongoClientInstance = getMongoDBClient();

    if (!$mongoClientInstance || !($mongoClientInstance instanceof Client)) {
        error_log("ERROR: MongoDB Client instance not available or incorrect type in get_user_cards.php.");
        $response_data['message'] = 'Database connection error (Client initialization).';
        http_response_code(500);
        echo json_encode($response_data);
        exit;
    }

    if (!defined('MONGODB_DB_NAME')) {
        error_log("ERROR: MONGODB_DB_NAME is not defined.");
        $response_data['message'] = 'Server configuration error: Database name not set.';
        http_response_code(500);
        echo json_encode($response_data);
        exit;
    }
    $mongoDb = $mongoClientInstance->selectDatabase(MONGODB_DB_NAME);

    if (!isset($_SESSION['user_id'])) {
        error_log("DEBUG: User not authenticated in get_user_cards.php.");
        $response_data['message'] = 'User not authenticated. Please log in again.';
        $statusCode = 401;
        http_response_code(401);
        echo json_encode($response_data);
        exit;
    }

    $userId = $_SESSION['user_id'];
    try {
        if (!($userId instanceof ObjectId)) {
            $userId = new ObjectId($userId);
        }
    } catch (Exception $e) {
        error_log("ERROR: Invalid userId format from session: " . $e->getMessage());
        $response_data['message'] = 'Invalid user session data.';
        $statusCode = 400;
        http_response_code(400);
        echo json_encode($response_data);
        exit;
    }

    $cardsCollection = $mongoDb->selectCollection('bank_cards');

    // Define the MongoDB Aggregation Pipeline
    $pipeline = [
        ['$match' => ['user_id' => $userId]],
        ['$lookup' => [
            'from' => 'accounts',
            'localField' => 'account_id',
            'foreignField' => '_id',
            'as' => 'accountInfo'
        ]],
        ['$unwind' => ['path' => '$accountInfo', 'preserveNullAndEmptyArrays' => true]],
        ['$project' => [
            '_id' => 0,
            'id' => ['$toString' => '$_id'],
            'card_holder_name' => '$card_holder_name',
            'card_number' => '$card_number_masked', 
            'expiry_date_display' => '$expiry_date', 
            'card_type' => '$card_type',
            'card_network' => '$card_network', 
            'is_active' => '$is_active', 
            'status' => ['$ifNull' => ['$status', 'pending_activation']],
            'shipping_address' => '$shipping_address', 
            
            'account_type' => ['$ifNull' => ['$accountInfo.account_type', 'N/A']],
            'display_account_number' => ['$ifNull' => ['$accountInfo.account_number', 'N/A']],
            'balance' => ['$ifNull' => ['$accountInfo.balance', 0.00]],
            'currency' => ['$ifNull' => ['$accountInfo.currency', 'USD']],
            'bank_name' => ['$ifNull' => ['$accountInfo.bank_name', 'Hometown Bank PA']],
            'bank_logo_src' => ['$ifNull' => ['$accountInfo.bank_logo_src', null]],
        ]]
    ];

    error_log("DEBUG: Executing MongoDB aggregation pipeline for user: " . (string)$userId);
    $cards = $cardsCollection->aggregate($pipeline)->toArray();
    error_log("DEBUG: Cards fetched: " . count($cards));

    $formattedCards = [];
    foreach ($cards as $card) {
        $card['status_display_text'] = getCardStatusText($card['status'] ?? 'unknown');
        $card['status_display_class'] = getCardStatusClass($card['status'] ?? 'unknown');

        if (!empty($card['display_account_number']) && strlen($card['display_account_number']) > 4) {
            $card['display_account_number'] = '****' . substr($card['display_account_number'], -4);
        }

        if (!empty($card['card_number'])) {
            $card['card_number'] = implode(' ', str_split($card['card_number'], 4));
        } else {
            $card['card_number'] = '**** **** **** ****';
        }
        
        $formattedCards[] = $card;
    }

    if (empty($formattedCards)) {
        $response_data = ['status' => 'success', 'cards' => [], 'message' => 'No bank cards found. Order a new one below!'];
    } else {
        $response_data = ['status' => 'success', 'cards' => $formattedCards];
    }
    
    $statusCode = 200;
    http_response_code($statusCode);
    echo json_encode($response_data);
    exit;

} catch (MongoDBDriverException $e) {
    error_log("MongoDB Driver EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Code: " . $e->getCode());
    $response_data = ['status' => 'error', 'message' => 'Database error: Could not fetch cards. Please try again later.'];
    $statusCode = 500;
    http_response_code(500);
    echo json_encode($response_data);
    exit;
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    $response_data = ['status' => 'error', 'message' => 'Server error: An unexpected error occurred.'];
    $statusCode = 500;
    http_response_code(500);
    echo json_encode($response_data);
    exit;
}

function getCardStatusText(string $status): string {
    switch ($status) {
        case 'active': return 'Active';
        case 'frozen': return 'Frozen';
        case 'pending_delivery': return 'Pending Delivery';
        case 'pending_activation': return 'Pending Activation';
        case 'lost': return 'Reported Lost';
        case 'stolen': return 'Reported Stolen';
        case 'cancelled': return 'Cancelled';
        default: return 'Unknown Status';
    }
}

function getCardStatusClass(string $status): string {
    switch ($status) {
        case 'active': return 'status-active';
        case 'frozen': return 'status-frozen';
        case 'pending_delivery':
        case 'pending_activation':
            return 'status-pending';
        case 'lost':
        case 'stolen':
        case 'cancelled':
            return 'status-inactive';
        default: return 'status-default';
    }
}