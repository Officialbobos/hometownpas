<?php
// api/deposit_check.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    $dotenv->load();
}

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\BSON\ObjectId;

$response = ['success' => false, 'message' => 'An unknown error occurred.'];
$statusCode = 500;

try {
    // Check if the user is logged in
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.', 401);
    }
    
    // Check if the request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Only POST is allowed.', 405);
    }

    $user_id = $_SESSION['user_id'];

    // Sanitize and validate input
    $account_id = $_POST['account_id'] ?? null;
    $amount = $_POST['amount'] ?? null;
    $amount = filter_var($amount, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);

    if (!$account_id || !$amount) {
        throw new Exception("Please fill in all fields with valid data.", 400);
    } 
    
    if (!isset($_FILES['front_image']) || $_FILES['front_image']['error'] !== UPLOAD_ERR_OK ||
        !isset($_FILES['back_image']) || $_FILES['back_image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Please upload both the front and back of the check.", 400);
    }

    $accountsCollection = getCollection('accounts');
    $checkDepositsCollection = getCollection('check_deposits');

    // Verify account ownership
    $account = $accountsCollection->findOne(['_id' => new ObjectId($account_id), 'user_id' => new ObjectId($user_id)]);
    if (!$account) {
        throw new Exception("The selected account is invalid or does not belong to you.", 403);
    }

    // Handle file uploads
    $upload_dir = __DIR__ . '/../uploads/checks/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Use a unique ID for filenames to prevent conflicts
    $unique_id = uniqid();
    $front_image_ext = pathinfo($_FILES['front_image']['name'], PATHINFO_EXTENSION);
    $back_image_ext = pathinfo($_FILES['back_image']['name'], PATHINFO_EXTENSION);
    
    $front_image_name = "front_" . $unique_id . "." . $front_image_ext;
    $back_image_name = "back_" . $unique_id . "." . $back_image_ext;

    $front_image_path = $upload_dir . $front_image_name;
    $back_image_path = $upload_dir . $back_image_name;

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5 MB

    if (!in_array($_FILES['front_image']['type'], $allowed_types) || !in_array($_FILES['back_image']['type'], $allowed_types)) {
        throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.", 400);
    } elseif ($_FILES['front_image']['size'] > $max_size || $_FILES['back_image']['size'] > $max_size) {
        throw new Exception("File size exceeds the 5MB limit.", 400);
    }

    if (move_uploaded_file($_FILES['front_image']['tmp_name'], $front_image_path) &&
        move_uploaded_file($_FILES['back_image']['tmp_name'], $back_image_path)) {
        
        $insertResult = $checkDepositsCollection->insertOne([
            'user_id' => new ObjectId($user_id),
            'account_id' => new ObjectId($account_id),
            'amount' => (float) $amount,
            'front_image_path' => '/uploads/checks/' . $front_image_name, // Store a relative URL for the browser
            'back_image_path' => '/uploads/checks/' . $back_image_name,
            'status' => 'pending',
            'admin_notes' => '',
            'created_at' => new MongoDB\BSON\UTCDateTime()
        ]);

        if (!$insertResult->getInsertedId()) {
            throw new Exception("Failed to submit deposit request. Please try again.", 500);
        }

        $response = ['success' => true, 'message' => "Your check deposit for $" . number_format($amount, 2) . " has been submitted and is pending administrator approval."];
        $statusCode = 200;

    } else {
        throw new Exception("Failed to upload one or more images. Please check file permissions.", 500);
    }
} catch (Exception $e) {
    $statusCode = $e->getCode() ?: 500;
    $response = ['success' => false, 'message' => $e->getMessage()];
    error_log("Check Deposit Error: " . $e->getMessage());
}

http_response_code($statusCode);
echo json_encode($response);
exit;
?>