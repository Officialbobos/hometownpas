<?php
// api/set_card_pin.php

// Crucial: Start the session at the very beginning to access $_SESSION
session_start();

// Adjust paths dynamically for Config.php and functions.php
// Be absolutely sure these paths are correct for your deployment.
// If 'api' is directly under your web root, and 'Config.php' is in a 'config' folder
// at the root, it might be: require_once __DIR__ . '/../config/Config.php';
// The 'dirname(__DIR__, 2)' goes up two levels. If set_card_pin.php is in 'frontend/api',
// then dirname(__DIR__, 2) would reach the root for 'Config.php' IF Config.php is directly in root.
// Example: If your structure is project-root/config/Config.php and project-root/api/set_card_pin.php
// Then it should be: require_once __DIR__ . '/../config/Config.php';
require_once dirname(__DIR__, 2) . '/Config.php'; // Assuming Config.php is two levels up from 'api'
require_once dirname(__DIR__, 2) . '/functions.php'; // Assuming functions.php is two levels up from 'api'

use MongoDB\Client; // This is not strictly needed here if $mongoDb is passed from Config
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Set content type for JSON response
header('Content-Type: application/json');

// Initialize variables for response
$response_data = [];
$statusCode = 200; // Default to success

try {
    // Ensure MongoDB connection is available.
    // Assuming Config.php already establishes $mongoDb as a global variable.
    global $mongoDb;
    if (!$mongoDb) {
        error_log("CRITICAL ERROR: MongoDB connection object (\$mongoDb) is not available in set_card_pin.php.");
        throw new Exception('Database connection error. Please try again later.', 500);
    }

    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated. Please log in.', 401);
    }

    // Convert user ID from session string to MongoDB ObjectId
    $user_id_string = $_SESSION['user_id'];
    try {
        $userObjectId = new ObjectId($user_id_string);
    } catch (Exception $e) {
        error_log("ERROR: Invalid user ID format from session ('$user_id_string') in set_card_pin.php: " . $e->getMessage());
        throw new Exception('Invalid user session. Please try logging in again.', 400);
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
        $cardObjectId = new ObjectId($cardId);
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
        // Check if activation code is set on the card and matches
        if (empty($card['activation_code']) || $card['activation_code'] !== $activationCode) {
            throw new Exception('Invalid activation code.', 400);
        }

        $updateFields['is_active'] = true;
        $updateFields['activation_date'] = new MongoDB\BSON\UTCDateTime();
        $updateFields['pin_hashed'] = password_hash($newPin, PASSWORD_DEFAULT); // Hash and store new PIN
        $message = 'Card activated successfully and PIN set.';

    } elseif (isset($card['pin_hashed']) && !empty($currentPin)) { // Check if pin_hashed exists AND currentPin is provided
        // --- PIN Change Flow ---
        if (!$isCardActive) {
            throw new Exception('Card must be active to change PIN. Please activate it first.', 400);
        }
        // Verify current PIN
        if (!password_verify($currentPin, $card['pin_hashed'])) {
            throw new Exception('Incorrect current PIN.', 401);
        }

        $updateFields['pin_hashed'] = password_hash($newPin, PASSWORD_DEFAULT); // Hash and store new PIN
        $message = 'Card PIN updated successfully.';

    } else {
        // Neither activation code nor current PIN provided
        // This covers cases where pin_hashed is null but no activation code is given
        // or where a current PIN is expected but not provided.
        if (empty($card['pin_hashed'])) {
             throw new Exception('This card needs to be activated first. Please provide the activation code.', 400);
        } else {
             throw new Exception('Please provide your current PIN to change it.', 400);
        }
    }

    // Add a last updated timestamp to the card for all modifications
    $updateFields['last_updated'] = new MongoDB\BSON\UTCDateTime();

    // Perform the update
    $updateResult = $bankCardsCollection->updateOne(
        ['_id' => $cardObjectId, 'user_id' => $userObjectId], // Double-check ownership on update
        ['$set' => $updateFields]
    );

    if ($updateResult->getModifiedCount() === 1) {
        $response_data = ['success' => true, 'message' => $message];
        $statusCode = 200;
    } else {
        // If no document was modified, it could mean:
        // 1. The document was already in the desired state (e.g., trying to activate an already active card without the right flow)
        // 2. A race condition occurred (another process modified it)
        // 3. The query conditions (like user_id or _id) didn't match after initial find.
        error_log("WARN: No card document modified for card ID " . $cardId . " by user " . $user_id_string . ". Update attempted: " . json_encode($updateFields));
        throw new Exception('Failed to update card. No changes applied or card state conflict. Please reload and try again.', 409); // 409 Conflict is more appropriate here
    }

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Catch specific MongoDB driver exceptions
    error_log("MongoDB Driver EXCEPTION in set_card_pin.php: " . $e->getMessage() . " Code: " . $e->getCode() . " File: " . $e->getFile() . " Line: " . $e->getLine());
    $response_data = ['success' => false, 'message' => 'A database error occurred during PIN operation.'];
    $statusCode = 500;
} catch (Exception $e) {
    // Catch any other general PHP exceptions
    error_log("GENERIC EXCEPTION in set_card_pin.php: " . $e->getMessage() . " Line: " . $e->getLine() . " File: " . $e->getFile());

    // Use the error code from the exception if available and valid HTTP status, otherwise default to 500
    $exceptionCode = $e->getCode();
    $statusCode = ($exceptionCode >= 100 && $exceptionCode < 600) ? $exceptionCode : 500;

    // Provide a more specific error message based on the exception message if it's a client-side error (4xx)
    $response_data = ['success' => false, 'message' => $e->getMessage()];
}

// Set the HTTP status code before sending the JSON response
http_response_code($statusCode);
echo json_encode($response_data);
exit; // Ensure nothing else is outputted