<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php'; // For Dotenv
require_once __DIR__ . '/../Config.php'; // For BASE_URL etc.

// Load Dotenv if .env file exists
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->load();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // You can add a check for a specific action in the POST body if you want,
    // but for clearing this specific session data, it's often not necessary.
    // For example: $data = json_decode(file_get_contents('php://input'), true);
    // if (isset($data['action']) && $data['action'] === 'clear_card_modal_message') { ... }

    // Clear the specific session variables for the card modal
    unset($_SESSION['display_card_modal_on_bank_cards']);
    unset($_SESSION['card_modal_message_for_display']);
    unset($_SESSION['card_modal_message_type']); // Crucially, clear the type too

    echo json_encode(['status' => 'success', 'message' => 'Card modal session message cleared.']);

} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only POST is allowed.']);
}