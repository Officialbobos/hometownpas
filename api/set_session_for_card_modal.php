<?php
<<<<<<< HEAD
=======
// Path: C:\xampp\htdocs\hometownbank\api\set_session_for_card_modal.php

>>>>>>> 9279b39ec00731d3162e0fb489128bbccc0f0f75
session_start();
require_once __DIR__ . '/../vendor/autoload.php'; // For Dotenv
require_once __DIR__ . '/../Config.php'; // For BASE_URL etc.

// Load Dotenv if .env file exists
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->load();
}

<<<<<<< HEAD
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $message = $data['message'] ?? '';
    $show = $data['show'] ?? false;

    // Only set if message and show flag are valid
    if (!empty($message) && $show) {
        $_SESSION['display_card_modal_on_bank_cards'] = true;
        $_SESSION['card_modal_message_for_display'] = $message;
        echo json_encode(['status' => 'success', 'message' => 'Session variables set.']);
    } else {
        // Clear session variables if not meant to be shown (e.g., if admin turned it off)
        unset($_SESSION['display_card_modal_on_bank_cards']);
        unset($_SESSION['card_modal_message_for_display']);
        echo json_encode(['status' => 'success', 'message' => 'Session variables cleared/not set.']);
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
=======
// Ensure the request is JSON
header('Content-Type: application/json');

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Decode the JSON input
    $data = json_decode(file_get_contents('php://input'), true);

    // Get message, show flag, and crucially, the message type from the POST data
    $message = $data['message'] ?? '';
    $show = $data['show'] ?? false;
    $type = $data['type'] ?? 'info'; // Default to 'info' if not provided

    // Validate and set session variables
    if (!empty($message) && $show) {
        $_SESSION['display_card_modal_on_bank_cards'] = true;
        $_SESSION['card_modal_message_for_display'] = $message;
        $_SESSION['card_modal_message_type'] = $type; // Store the message type
        echo json_encode(['status' => 'success', 'message' => 'Session variables for card modal set.']);
    } else {
        // If 'show' is false or message is empty, clear the session variables
        unset($_SESSION['display_card_modal_on_bank_cards']);
        unset($_SESSION['card_modal_message_for_display']);
        unset($_SESSION['card_modal_message_type']); // Clear the type as well
        echo json_encode(['status' => 'success', 'message' => 'Session variables for card modal cleared/not set.']);
    }

} else {
    // Respond with an error for unsupported methods
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only POST is allowed.']);
}
>>>>>>> 9279b39ec00731d3162e0fb489128bbccc0f0f75
