<?php
// api/order_card.php

// ALWAYS set content type first
header('Content-Type: application/json');

// Initialize a variable to hold the response data
$response_data = [];
$statusCode = 200; // Default success status code

try {
    // Ensure $mongoDb is available
    global $mongoDb;
    if (!$mongoDb) {
        throw new Exception('Database connection error.', 500);
    }

    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.', 401);
    }

    $user_id_string = $_SESSION['user_id'];
    try {
        $userObjectId = new MongoDB\BSON\ObjectId($user_id_string);
    } catch (Exception $e) {
        // Log the detailed error but send a generic message to the frontend
        error_log("ERROR: Invalid user ID format from session in order_card.php: " . $e->getMessage());
        throw new Exception('Invalid user session.', 400);
    }

    // IMPORTANT FIX: Use $_POST as your frontend is sending FormData
    $cardHolderName = $_POST['cardHolderName'] ?? null;
    $cardType = $_POST['cardType'] ?? null;
    $cardNetwork = $_POST['cardNetwork'] ?? null;
    $accountId = $_POST['accountId'] ?? null;
    $shippingAddress = $_POST['shippingAddress'] ?? null;

    // Basic validation
    if (empty($cardHolderName) || empty($cardType) || empty($cardNetwork) || empty($accountId) || empty($shippingAddress)) {
        throw new Exception('All fields are required.', 400);
    }

    // Validate account ID format and existence
    try {
        $accountObjectId = new MongoDB\BSON\ObjectId($accountId);
        $accountsCollection = $mongoDb->selectCollection('accounts');
        $account = $accountsCollection->findOne(['_id' => $accountObjectId, 'user_id' => $userObjectId]);

        if (!$account) {
            throw new Exception('Selected account not found or does not belong to you.', 400);
        }
    } catch (Exception $e) {
        // Catch ObjectId conversion errors or DB errors specifically for account ID
        error_log("ERROR: Account ID validation failed in order_card.php: " . $e->getMessage());
        throw new Exception('Invalid account ID format or account lookup failed.', 400);
    }

    // Generate card details (functions are assumed to be defined elsewhere or copied here)
    // --- Start of functions (copy if not in a separate included file) ---
    function generateCardNumber($network) {
        $prefix = '';
        switch ($network) {
            case 'Visa': $prefix = '4'; break;
            case 'Mastercard': $prefix = '5'; break;
            case 'Amex': $prefix = '3'; break;
            case 'Verve': $prefix = '5061'; break;
            default: $prefix = '9';
        }
        $length = ($network === 'Amex') ? 15 : 16;
        $number = $prefix;
        while (strlen($number) < $length - 1) {
            $number .= mt_rand(0, 9);
        }
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
        $expiryMonth = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $expiryYear = $currentYear + mt_rand(3, 7);
        return $expiryMonth . '/' . $expiryYear;
    }

    function generateCVV() {
        return str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    }

    function generatePIN() {
        return str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    // --- End of functions ---

    $bankCardsCollection = $mongoDb->selectCollection('bank_cards');

    $cardNumber = generateCardNumber($cardNetwork);
    $expiryDate = generateExpiryDate();
    $cvv = generateCVV();
    $pin = generatePIN();

    $newCard = [
        'user_id' => $userObjectId,
        'account_id' => $accountObjectId,
        'card_holder_name' => $cardHolderName,
        'card_type' => $cardType,
        'card_network' => $cardNetwork,
        'card_number_encrypted' => $cardNumber, // Store the full generated number
        'expiry_date' => $expiryDate, // MM/YY format
        'cvv_encrypted' => hash('sha256', $cvv), // Hash CVV (should not be reversible, or generated on demand)
        'pin_hashed' => password_hash($pin, PASSWORD_DEFAULT), // Hash PIN (for verification during activation/use)
        'shipping_address' => $shippingAddress,
        'order_date' => new MongoDB\BSON\UTCDateTime(), // Timestamp of order
        'status' => 'pending_delivery',
        'is_active' => false, // New cards are inactive until activated by user
        'activation_code' => bin2hex(random_bytes(8)), // Simple activation code for demo (e.g., 16 hex chars)
        'activation_date' => null,
        'delivery_date_estimate' => (new DateTime('+7 business days'))->format('Y-m-d H:i:s'), // Estimate
    ];

    $insertResult = $bankCardsCollection->insertOne($newCard);

    if ($insertResult->getInsertedCount() === 1) {
        $response_data = [
            'success' => true,
            'message' => 'Your bank card has been successfully ordered and will be delivered to your address within 7 business days. Please activate it upon delivery.',
            'cardId' => (string)$insertResult->getInsertedId()
        ];
        $statusCode = 200;
    } else {
        error_log("Failed to insert new card for user " . $userObjectId . ". Insert count: " . $insertResult->getInsertedCount());
        throw new Exception('Failed to place card order. Please try again.', 500);
    }

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Catch specific MongoDB driver exceptions
    error_log("MongoDB Driver EXCEPTION in order_card.php: " . $e->getMessage() . " Code: " . $e->getCode());
    $response_data = ['success' => false, 'message' => 'Database error during card order.'];
    $statusCode = 500;
} catch (Exception $e) {
    // Catch any other general PHP exceptions
    error_log("GENERIC EXCEPTION in order_card.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    // Use the error code from the exception if available and valid, otherwise default to 500
    $statusCode = $e->getCode();
    if ($statusCode < 100 || $statusCode >= 600) { // Ensure it's a valid HTTP status code
        $statusCode = 500;
    }
    $response_data = ['success' => false, 'message' => 'An unexpected server error occurred during card order.'];
}

// Set the HTTP status code before sending the JSON response
http_response_code($statusCode);
echo json_encode($response_data);
exit; // Ensure nothing else is outputted