<?php
// api/order_card.php
header('Content-Type: application/json');

// Ensure $mongoClient and $mongoDb are available from the router (index.php)
global $mongoDb;
if (!$mongoDb) {
    error_log("ERROR: MongoDB connection not available in api/order_card.php.");
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    http_response_code(500);
    exit;
}

// Ensure user is logged in (redundant if router handles it, but good for safety)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    http_response_code(401);
    exit;
}

$user_id_string = $_SESSION['user_id']; // Get user ID from session
try {
    $userObjectId = new MongoDB\BSON\ObjectId($user_id_string);
} catch (Exception $e) {
    error_log("ERROR: Invalid user ID format from session in order_card.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Invalid user session.']);
    http_response_code(400);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$cardHolderName = $input['cardHolderName'] ?? null;
$cardType = $input['cardType'] ?? null;      // Debit or Credit
$cardNetwork = $input['cardNetwork'] ?? null; // Visa, Mastercard, Amex, Verve
$accountId = $input['accountId'] ?? null;
$shippingAddress = $input['shippingAddress'] ?? null;
$requestingUserId = $input['user_id'] ?? null; // User ID sent from frontend (should match session)

// Basic validation
if (empty($cardHolderName) || empty($cardType) || empty($cardNetwork) || empty($accountId) || empty($shippingAddress)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    http_response_code(400);
    exit;
}

// Validate account ID format and existence
try {
    $accountObjectId = new MongoDB\BSON\ObjectId($accountId);
    $accountsCollection = $mongoDb->selectCollection('accounts');
    $account = $accountsCollection->findOne(['_id' => $accountObjectId, 'user_id' => $userObjectId]);

    if (!$account) {
        echo json_encode(['success' => false, 'message' => 'Selected account not found or does not belong to you.']);
        http_response_code(400);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Invalid account ID format.']);
    http_response_code(400);
    exit;
}

// Generate card details
function generateCardNumber($network) {
    // Basic prefix based on network (for demo, not cryptographically secure)
    $prefix = '';
    switch ($network) {
        case 'Visa': $prefix = '4'; break;
        case 'Mastercard': $prefix = '5'; break;
        case 'Amex': $prefix = '3'; break; // Typically 15 digits
        case 'Verve': $prefix = '5061'; break; // Common Verve prefix
        default: $prefix = '9'; // Generic or unknown
    }

    $length = ($network === 'Amex') ? 15 : 16;
    $number = $prefix;
    while (strlen($number) < $length - 1) {
        $number .= mt_rand(0, 9);
    }

    // Luhn algorithm (checksum for validity, not security) - Simplified for demo
    $sum = 0;
    $double = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $digit = (int)$number[$i];
        if ($double) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
        $double = !$double;
    }
    $checksum = (10 - ($sum % 10)) % 10;
    return $number . $checksum;
}

function generateExpiryDate() {
    $currentYear = date('y');
    $currentMonth = date('m');
    $expiryMonth = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
    // Card usually valid for 3-5 years. Let's make it up to 4 years from now.
    $expiryYear = $currentYear + mt_rand(3, 7); // e.g., current 25, expiry 28-32
    return $expiryMonth . '/' . $expiryYear;
}

function generateCVV() {
    return str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
}

// Note: PINs should ideally be set by the user during activation or sent securely,
// never stored in plaintext or generated here to be sent over plain channels.
// For this demo, we'll generate a dummy one.
function generatePIN() {
    return str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
}


try {
    $bankCardsCollection = $mongoDb->selectCollection('bank_cards'); // Or 'cards' if you renamed it

    // Check if user already has too many cards of this type/network for the same account (optional logic)
    // $existingCardsCount = $bankCardsCollection->countDocuments([
    //     'user_id' => $userObjectId,
    //     'account_id' => $accountObjectId,
    //     'card_type' => $cardType,
    //     'card_network' => $cardNetwork,
    // ]);
    // if ($existingCardsCount >= 2) { // Example limit
    //     echo json_encode(['success' => false, 'message' => 'You already have too many cards of this type linked to this account.']);
    //     http_response_code(400);
    //     exit;
    // }

    $cardNumber = generateCardNumber($cardNetwork);
    $expiryDate = generateExpiryDate();
    $cvv = generateCVV(); // This is the actual CVV. In a real system, it would be encrypted or not stored.
    $pin = generatePIN(); // This is the actual PIN. Should be handled with extreme care.

    $newCard = [
        'user_id' => $userObjectId,
        'account_id' => $accountObjectId,
        'card_holder_name' => $cardHolderName,
        'card_type' => $cardType, // Debit/Credit
        'card_network' => $cardNetwork, // Visa/Mastercard/Amex/Verve
        'card_number_encrypted' => $cardNumber, // Store the full generated number
        'expiry_date' => $expiryDate, // MM/YY format
        'cvv_encrypted' => hash('sha256', $cvv), // Hash CVV (should not be reversible, or generated on demand)
        'pin_hashed' => password_hash($pin, PASSWORD_DEFAULT), // Hash PIN (for verification during activation/use)
        'shipping_address' => $shippingAddress,
        'order_date' => new MongoDB\BSON\UTCDateTime(), // Timestamp of order
        'status' => 'pending_delivery', // e.g., 'pending_delivery', 'active', 'inactive', 'blocked'
        'is_active' => false, // New cards are inactive until activated by user
        'activation_code' => bin2hex(random_bytes(8)), // Simple activation code for demo (e.g., 16 hex chars)
        'activation_date' => null,
        'delivery_date_estimate' => (new DateTime('+7 business days'))->format('Y-m-d H:i:s'), // Estimate
    ];

    $insertResult = $bankCardsCollection->insertOne($newCard);

    if ($insertResult->getInsertedCount() === 1) {
        echo json_encode([
            'success' => true,
            'message' => 'Your bank card has been successfully ordered and will be delivered to your address within 7 business days. Please activate it upon delivery.',
            'cardId' => (string)$insertResult->getInsertedId()
        ]);
        http_response_code(200);
    } else {
        error_log("Failed to insert new card for user " . $userObjectId . ". Insert count: " . $insertResult->getInsertedCount());
        echo json_encode(['success' => false, 'message' => 'Failed to place card order. Please try again.']);
        http_response_code(500);
    }

} catch (MongoDBDriverException $e) {
    error_log("MongoDB EXCEPTION in order_card.php: " . $e->getMessage() . " Code: " . $e->getCode());
    echo json_encode(['success' => false, 'message' => 'Database error during card order.']);
    http_response_code(500);
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in order_card.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred during card order.']);
    http_response_code(500);
}