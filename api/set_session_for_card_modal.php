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