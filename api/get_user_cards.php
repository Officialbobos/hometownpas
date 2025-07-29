<?php
// api/get_user_cards.php

// 1. Start the session - CRITICAL FOR AJAX ENDPOINTS
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 2. Include configuration and functions to establish MongoDB connection - CRITICAL FOR AJAX ENDPOINTS
// Adjust paths if Config.php and functions.php are not in the parent directory of 'api'
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\Client; // Required for getMongoDBClient() if it returns a Client instance
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;
use MongoDB\Database; // Required for type hinting $mongoDb

// 3. Get MongoDB Client - CRITICAL FOR AJAX ENDPOINTS
$mongoDb = getMongoDBClient();

// Check if MongoDB client is available
if (!$mongoDb || !($mongoDb instanceof Database)) { // Ensure it's a Database instance
    error_log("ERROR: MongoDB database object not available in api/get_user_cards.php. Type: " . gettype($mongoDb));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("DEBUG: Session User ID: Not Set in get_user_cards.php. Redirecting to login.");
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'User not authenticated. Please log in again.']);
    exit;
}

try {
    $userId = $_SESSION['user_id'];
    if (!($userId instanceof ObjectId)) {
        // Convert userId from session string to MongoDB\BSON\ObjectId for queries
        try {
            $userId = new ObjectId($userId);
            error_log("DEBUG: Converted userId to ObjectId: " . (string)$userId);
        } catch (Exception $e) {
            error_log("ERROR: Invalid userId format from session: " . $e->getMessage());
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'Invalid user ID format in session.']);
            exit;
        }
    } else {
        error_log("DEBUG: userId is already ObjectId: " . (string)$userId);
    }

    // --- CRITICAL FIX: Use 'bank_cards' as the collection name ---
    $cardsCollection = $mongoDb->selectCollection('bank_cards');

    // Define the MongoDB Aggregation Pipeline
    // This pipeline fetches cards, joins them with account info, and formats the output.
    $pipeline = [
        [
            // Stage 1: Match cards belonging to the current user
            '$match' => [
                'user_id' => $userId
            ]
        ],
        [
            // Stage 2: Join with the 'accounts' collection
            '$lookup' => [
                'from' => 'accounts',           // The collection to join with
                'localField' => 'account_id',   // Field from the 'bank_cards' collection
                'foreignField' => '_id',        // Field from the 'accounts' collection
                'as' => 'accountInfo'           // The name of the new array field to add to the input documents
            ]
        ],
        [
            // Stage 3: Deconstruct the 'accountInfo' array field into a stream of documents.
            // This assumes each card is linked to exactly one account.
            // If a card might not have an account, use '$unwind' with preserveNullAndEmptyArrays: true
            '$unwind' => [
                'path' => '$accountInfo',
                'preserveNullAndEmptyArrays' => true // IMPORTANT: To keep cards even if no matching account is found
            ]
        ],
        [
            // Stage 4: Project (select and reshape) the desired fields for the output
            '$project' => [
                '_id' => 0, // Exclude the original MongoDB _id for the card document
                'id' => [ '$toString' => '$_id' ], // Convert card's _id to string and rename to 'id'
                'card_holder_name' => '$card_holder_name',
                'card_type_raw' => '$card_type', // Keep raw type for internal use if needed
                'card_network_raw' => '$card_network', // Keep raw network for internal use if needed
                'card_number_raw' => '$card_number', // Keep raw number for internal use if needed (e.g. for hashing)
                // --- MODIFIED: Masking the card number for display ---
                // Always mask sensitive info before sending to frontend.
                'card_number_display' => [
                    '$concat' => [
                        '**** **** **** ',
                        ['$substrCP' => ['$card_number', ['$subtract' => [ ['$strLenCP' => '$card_number'], 4 ]], 4]]
                    ]
                ],
                // --- FIX: Combine 'expiry_month' and 'expiry_year' if separate fields ---
                // Assuming 'expiry_month' and 'expiry_year' are numerical fields
                'expiry_date_display' => [
                    '$concat' => [
                        ['$cond' => [['$lt' => ['$expiry_month', 10]], '0', '']], // Add leading zero if month < 10
                        ['$toString' => '$expiry_month'],
                        '/',
                        ['$substrCP' => [['$toString' => '$expiry_year'], 2, 2]] // Get last two digits of year
                    ]
                ],
                // If you have 'expiry_date' as a single BSON Date object:
                // 'expiry_date_display' => ['$dateToString' => ['format' => '%m/%y', 'date' => '$expiry_date']],

                // --- MODIFIED: CVV should never be exposed; KEEP placeholder ---
                'display_cvv' => 'XXX',
                'status' => '$status', // Raw status for logic
                // Map card_network for logo display as per frontend's renderCard logic
                'card_network' => [ // This is what frontend uses for logo path
                    '$switch' => [
                        'branches' => [
                            ['case' => ['$eq' => ['$card_network', 'Visa']], 'then' => 'Visa'],
                            ['case' => ['$eq' => ['$card_network', 'Mastercard']], 'then' => 'Mastercard'],
                            ['case' => ['$eq' => ['$card_network', 'Amex']], 'then' => 'Amex'],
                            ['case' => ['$eq' => ['$card_network', 'Verve']], 'then' => 'Verve'],
                        ],
                        'default' => 'Default' // Default if network is not recognized
                    ]
                ],
                // Account info (will be null if preserveNullAndEmptyArrays was true and no match)
                'account_type' => '$accountInfo.account_type',
                'display_account_number' => '$accountInfo.account_number',
                'balance' => '$accountInfo.balance',
                'currency' => '$accountInfo.currency',
                'bank_name' => '$accountInfo.bank_name', // Assuming 'bank_name' exists in 'accounts'
                'bank_logo_src' => '$accountInfo.bank_logo_src' // Assuming 'bank_logo_src' exists in 'accounts'
            ]
        ]
    ];

    error_log("DEBUG: Executing MongoDB aggregation pipeline for user: " . (string)$userId);
    $cards = $cardsCollection->aggregate($pipeline)->toArray();
    error_log("DEBUG: Cards fetched: " . count($cards));

    // The aggregation pipeline directly produces the desired format.
    // The following loop is now mostly for setting default values if fields are null from $unwind or missing from documents.
    $formattedCards = [];
    foreach ($cards as $card) {
        $status_display_text = 'Unknown';
        $status_display_class = 'status-info';
        switch ($card['status'] ?? 'pending') {
            case 'active':
                $status_display_text = 'Active';
                $status_display_class = 'status-active';
                break;
            case 'pending':
                $status_display_text = 'Pending Activation';
                $status_display_class = 'status-pending';
                break;
            case 'suspended':
                $status_display_text = 'Suspended';
                $status_display_class = 'status-suspended';
                break;
            case 'cancelled':
                $status_display_text = 'Cancelled';
                $status_display_class = 'status-cancelled';
                break;
            default:
                $status_display_text = ucfirst($card['status'] ?? 'Unknown');
                $status_display_class = 'status-info';
                break;
        }

        $formattedCards[] = [
            'id' => $card['id'],
            'card_holder_name' => $card['card_holder_name'] ?? 'N/A',
            'card_number_display' => $card['card_number_display'] ?? 'ERROR: Card number missing',
            'expiry_date_display' => $card['expiry_date_display'] ?? 'MM/YY',
            'display_cvv' => 'XXX', // Always hardcode for security
            'card_network' => $card['card_network'] ?? 'Default',
            'is_active' => $card['status'] === 'active', // Derived from status for convenience
            'card_logo_src' => $card['card_logo_src'] ?? null, // From MongoDB aggregation if paths are absolute
            'bank_name' => $card['bank_name'] ?? 'HOMETOWN BANK',
            'bank_logo_src' => $card['bank_logo_src'] ?? null,
            'account_type' => $card['account_type'] ?? 'N/A',
            'display_account_number' => $card['display_account_number'] ? ('****' . substr($card['display_account_number'], -4)) : 'N/A', // Mask account number
            'balance' => $card['balance'] ?? 0.00,
            'currency' => $card['currency'] ?? 'USD',
            'status_display_text' => $status_display_text,
            'status_display_class' => $status_display_class,
            'status' => $card['status'] ?? 'pending' // Original status from DB
        ];
    }

    error_log("DEBUG: Preparing to send JSON response with " . count($formattedCards) . " cards.");
    echo json_encode(['status' => 'success', 'cards' => $formattedCards]); // Changed 'success' to 'status' as per cards.js
    exit;

} catch (MongoDBDriverException $e) {
    error_log("MongoDB Driver EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Code: " . $e->getCode());
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database error: Could not fetch cards. Please try again later.']);
    exit;
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Server error: An unexpected error occurred.']);
    exit;
}