<?php
// api/set_card_pin.php

// Set content type for JSON response
header('Content-Type: application/json');

// Initialize variables for response
$response_data = [];
$statusCode = 200; // Default to success

try {
    // Ensure MongoDB connection is available
    global $mongoDb;
    if (!$mongoDb) {
        throw new Exception('Database connection error.', 500);
    }

    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.', 401);
    }

    // Convert user ID from session string to MongoDB ObjectId
    $user_id_string = $_SESSION['user_id'];
    try {
        $userObjectId = new MongoDB\BSON\ObjectId($user_id_string);
    } catch (Exception $e) {
        error_log("ERROR: Invalid user ID format from session in set_card_pin.php: " . $e->getMessage());
        throw new Exception('Invalid user session.', 400);
    }

    // Get POST data (assuming frontend sends FormData)
    $cardId = $_POST['cardId'] ?? null;
    $newPin = $_POST['newPin'] ?? null;
    $activationCode = $_POST['activationCode'] ?? null; // For new card activation
    $currentPin = $_POST['currentPin'] ?? null; // For changing existing PIN

    // Validate essential inputs
    if (empty($cardId) || empty($newPin)) {
        throw new Exception('Card ID and new PIN are required.', 400);
    }

    // Validate new PIN format (e.g., 4 digits)
    if (!preg_match('/^\d{4}$/', $newPin)) {
        throw new Exception('New PIN must be exactly 4 digits.', 400);
    }

    // Convert card ID to ObjectId
    try {
        $cardObjectId = new MongoDB\BSON\ObjectId($cardId);
    } catch (Exception $e) {
        throw new Exception('Invalid card ID format.', 400);
    }

    $bankCardsCollection = $mongoDb->selectCollection('bank_cards');

    // Find the card, ensuring it belongs to the logged-in user
    $card = $bankCardsCollection->findOne(['_id' => $cardObjectId, 'user_id' => $userObjectId]);

    if (!$card) {
        throw new Exception('Card not found or does not belong to you.', 404);
    }

    $isCardActive = $card['is_active'] ?? false;
    $updateFields = [];
    $message = '';

    // Determine if it's an activation or PIN change
    if (!empty($activationCode)) {
        // --- Card Activation Flow ---
        if ($isCardActive) {
            throw new Exception('This card is already active. If you wish to change PIN, use the "Change PIN" option.', 400);
        }
        if (empty($card['activation_code']) || $card['activation_code'] !== $activationCode) {
            throw new Exception('Invalid activation code.', 400);
        }

        $updateFields['is_active'] = true;
        $updateFields['activation_date'] = new MongoDB\BSON\UTCDateTime();
        $updateFields['pin_hashed'] = password_hash($newPin, PASSWORD_DEFAULT); // Hash and store new PIN
        $message = 'Card activated successfully and PIN set.';

    } elseif (!empty($currentPin)) {
        // --- PIN Change Flow ---
        if (!$isCardActive) {
            throw new Exception('Card must be active to change PIN. Please activate it first.', 400);
        }
        if (empty($card['pin_hashed']) || !password_verify($currentPin, $card['pin_hashed'])) {
            throw new Exception('Incorrect current PIN.', 401);
        }

        $updateFields['pin_hashed'] = password_hash($newPin, PASSWORD_DEFAULT); // Hash and store new PIN
        $message = 'Card PIN updated successfully.';

    } else {
        // Neither activation code nor current PIN provided
        throw new Exception('Please provide either an activation code (for new card) or your current PIN (to change existing PIN).', 400);
    }

    // Perform the update
    $updateResult = $bankCardsCollection->updateOne(
        ['_id' => $cardObjectId],
        ['$set' => $updateFields]
    );

    if ($updateResult->getModifiedCount() === 1) {
        $response_data = ['success' => true, 'message' => $message];
        $statusCode = 200;
    } else {
        // If no document was modified, it might mean the data was already set
        // or the query didn't match (though we found it earlier).
        // Log for debugging.
        error_log("WARN: No card document modified for card ID " . $cardId . ". Update attempted: " . json_encode($updateFields));
        throw new Exception('No changes applied or card already in desired state.', 200); // Consider 200 if no change is benign
    }

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Catch specific MongoDB driver exceptions
    error_log("MongoDB Driver EXCEPTION in set_card_pin.php: " . $e->getMessage() . " Code: " . $e->getCode());
    $response_data = ['success' => false, 'message' => 'Database error during PIN operation.'];
    $statusCode = 500;
} catch (Exception $e) {
    // Catch any other general PHP exceptions
    error_log("GENERIC EXCEPTION in set_card_pin.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());
    
    // Use the error code from the exception if available and valid, otherwise default to 500
    $statusCode = $e->getCode();
    if ($statusCode < 100 || $statusCode >= 600) {
        $statusCode = 500;
    }
    $response_data = ['success' => false, 'message' => $e->getMessage()]; // Use exception message for client
}

// Set the HTTP status code before sending the JSON response
http_response_code($statusCode);
echo json_encode($response_data);
exit; // Ensure nothing else is outputted