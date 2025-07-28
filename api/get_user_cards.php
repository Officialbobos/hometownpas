<?php
// api/get_user_cards.php
header('Content-Type: application/json');

// This file is included by index.php, so $mongoClient, $mongoDb, and $_SESSION are already available.
// The isLoggedIn check for this route also happens in index.php before inclusion.

try {
    error_log("DEBUG: get_user_cards.php accessed for logged in user.");
    error_log("DEBUG: Session User ID: " . print_r($_SESSION['user_id'] ?? 'Not Set', true));

    // Ensure MongoDB client and database objects are available
    if (!isset($mongoClient) || !($mongoClient instanceof MongoDB\Client)) {
        error_log("ERROR: MongoDB client not available inside get_user_cards.php. Type: " . gettype($mongoClient));
        throw new Exception("MongoDB client not initialized.");
    }
    if (!isset($mongoDb) || !($mongoDb instanceof MongoDB\Database)) {
        error_log("ERROR: MongoDB database object not available inside get_user_cards.php. Type: " . gettype($mongoDb));
        throw new Exception("MongoDB database not selected.");
    }

    // --- CRITICAL FIX: Use 'bank_cards' as the collection name ---
    $cardsCollection = $mongoDb->selectCollection('bank_cards');
    // $accountsCollection is implicitly used within the aggregation pipeline's $lookup

    $userId = $_SESSION['user_id'];
    if (!($userId instanceof MongoDB\BSON\ObjectId)) {
        // Convert userId from session string to MongoDB\BSON\ObjectId for queries
        try {
            $userId = new MongoDB\BSON\ObjectId($userId);
            error_log("DEBUG: Converted userId to ObjectId: " . (string)$userId);
        } catch (Exception $e) {
            error_log("ERROR: Invalid userId format from session: " . $e->getMessage());
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid user ID format in session.']);
            exit;
        }
    } else {
        error_log("DEBUG: userId is already ObjectId: " . (string)$userId);
    }

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
                'localField' => 'account_id',   // Field from the 'cards' collection
                'foreignField' => '_id',        // Field from the 'accounts' collection
                'as' => 'accountInfo'           // The name of the new array field to add to the input documents
            ]
        ],
        [
            // Stage 3: Deconstruct the 'accountInfo' array field into a stream of documents.
            // This assumes each card is linked to exactly one account.
            '$unwind' => '$accountInfo'
        ],
        [
            // Stage 4: Project (select and reshape) the desired fields for the output
            '$project' => [
                '_id' => 0, // Exclude the original MongoDB _id for the card document
                'id' => [ '$toString' => '$_id' ], // Convert card's _id to string and rename to 'id'
                'card_holder_name' => '$card_holder_name',
                'display_card_number' => [
                    // --- FIX: Use 'card_number' instead of 'card_number_encrypted' ---
                    // Mask all but the last 4 digits of 'card_number'
                    '$concat' => [
                        'XXXX XXXX XXXX ',
                        [ '$substrCP' => [ '$card_number', [ '$subtract' => [ [ '$strLenCP' => '$card_number' ], 4 ] ], 4 ] ]
                    ]
                ],
                'display_expiry' => [
                    // --- FIX: Combine 'expiry_month' and 'expiry_year' ---
                    '$concat' => [
                        ['$cond' => [['$lt' => ['$expiry_month', 10]], '0', '']], // Add leading zero if month < 10
                        ['$toString' => '$expiry_month'],
                        '/',
                        ['$toString' => ['$mod' => ['$expiry_year', 100]]] // Get last two digits of year
                    ]
                ],
                'display_cvv' => 'XXX', // CVV should never be exposed; use a placeholder
                'card_network' => '$card_type', // --- POTENTIAL FIX: Assuming card_type is the network, based on sample
                'is_active' => '$is_active',
                'account_type' => '$accountInfo.account_type',
                'display_account_number' => '$accountInfo.account_number', // Assumes 'accounts' documents have an 'account_number' field
                'balance' => '$accountInfo.balance',
                'currency' => '$accountInfo.currency',
                // Dynamically determine the card logo source based on the card network
                'card_logo_src' => [
                    '$switch' => [
                       'branches' => [
    ['case' => ['$eq' => ['$card_type', 'Visa']], 'then' => 'https://i.imgur.com/Zua60IH.png'],
    ['case' => ['$eq' => ['$card_type', 'MasterCard']], 'then' => 'https://i.imgur.com/ze6oDQT.png'],
    ['case' => ['$eq' => ['$card_type', 'Amex']], 'then' => BASE_URL . '/assets/images/amex-logo.png'], // This one might be a local asset
    ['case' => ['$eq' => ['$card_type', 'Verve']], 'then' => 'https://i.imgur.com/Dqk4qfW.png'],
],
'default' => BASE_URL . '/assets/images/default-card-logo.png' // Fallback for local asset
                    ]
                ]
            ]
        ]
    ];

    error_log("DEBUG: Executing MongoDB aggregation pipeline for user: " . (string)$userId);
    $cards = $cardsCollection->aggregate($pipeline)->toArray();
    error_log("DEBUG: Cards fetched: " . count($cards));

    // The aggregation pipeline directly produces the desired format, so a simple re-loop
    // might not be strictly necessary, but it acts as a final safeguard/transformation layer.
    $formattedCards = [];
    foreach ($cards as $card) {
        $formattedCards[] = [
            'id' => $card['id'],
            'card_holder_name' => $card['card_holder_name'] ?? 'N/A',
            'display_card_number' => $card['display_card_number'] ?? 'XXXX XXXX XXXX XXXX',
            'display_expiry' => $card['display_expiry'] ?? 'MM/YY',
            'display_cvv' => $card['display_cvv'] ?? 'XXX', // Keep it mocked for security
            'card_network' => $card['card_network'] ?? 'Default',
            'is_active' => $card['is_active'] ?? false, // Default to false if not explicitly set
            'card_logo_src' => $card['card_logo_src'] ?? null,
            'account_type' => $card['account_type'] ?? 'N/A',
            'display_account_number' => $card['display_account_number'] ?? 'N/A',
            'balance' => $card['balance'] ?? 0.00,
            'currency' => $card['currency'] ?? 'USD'
        ];
    }

    error_log("DEBUG: Preparing to send JSON response with " . count($formattedCards) . " cards.");
    echo json_encode(['success' => true, 'cards' => $formattedCards]);
    exit;

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Catch specific MongoDB driver exceptions for more precise error logging
    error_log("MongoDB Driver EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Code: " . $e->getCode());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Database error: Could not fetch cards. Please try again later.']);
    exit;
} catch (Exception $e) {
    // Catch any other general PHP exceptions
    error_log("GENERIC EXCEPTION in get_user_cards.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Server error: An unexpected error occurred.']);
    exit;
}