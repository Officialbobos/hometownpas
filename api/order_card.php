<?php
// api/order_card.php
session_start();
header('Content-Type: application/json');

// Include your database connection, Composer autoloader, and general config.
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env and Config
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    $dotenv->load();
}

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

// Add the necessary 'use' statements for MongoDB classes
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBException;

// Add this line to confirm the script starts
error_log("DEBUG: Starting order_card.php script execution.");

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // Use a specific alias to avoid conflict with general Exception

// Initialize a variable to hold the response data
$response_data = [];
$statusCode = 200; // Default success status code

// The MongoDB connection object will be created by the getCollection() function from functions.php
// which is now included.

try {
    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated. Please log in.', 401);
    }

    $user_id_string = $_SESSION['user_id'];
    try {
        $userObjectId = new ObjectId($user_id_string);
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        error_log("ERROR: Invalid user ID format from session ('$user_id_string') in order_card.php: " . $e->getMessage());
        throw new Exception('Invalid user session data. Please try logging in again.', 400);
    }

    // Add this line to confirm the includes are successful and DB is connected
    error_log("DEBUG: config.php and functions.php included successfully. MongoDB connection ready.");

    // Fetch user details for email notification (recipient name and email)
    $usersCollection = getCollection('users');
    $user_doc = $usersCollection->findOne(['_id' => $userObjectId]);
    if (!$user_doc) {
        // User not found in DB, which is a critical issue if they are logged in.
        error_log("CRITICAL ERROR: Logged-in user with ID " . $user_id_string . " not found in database during card order.");
        throw new Exception('User profile not found. Please contact support.', 500);
    }
    $recipient_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
    $recipient_email = $user_doc['email'] ?? '';

    // Basic check for recipient email
    if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        error_log("WARNING: Invalid or missing email for user " . $user_id_string . ". Cannot send order confirmation email.");
        // For now, let's make it critical to ensure email setup is verified.
        throw new Exception('User email not available or invalid. Cannot send order confirmation.', 500);
    }

    // IMPORTANT: Use $_POST as your frontend is sending FormData
    $cardHolderName = $_POST['cardHolderName'] ?? null;
    $cardType = $_POST['cardType'] ?? null;
    $cardNetwork = $_POST['cardNetwork'] ?? null;
    $accountId = $_POST['accountId'] ?? null;
    $shippingAddress = $_POST['shippingAddress'] ?? null;

    // Add this line to confirm POST data is received
    error_log("DEBUG: Received POST data. Card Holder: $cardHolderName, Card Type: $cardType, Account ID: $accountId");

    // Basic validation
    if (empty($cardHolderName) || empty($cardType) || empty($cardNetwork) || empty($accountId) || empty($shippingAddress)) {
        throw new Exception('All card order fields are required.', 400);
    }

    // Validate account ID format and existence
    try {
        $accountObjectId = new ObjectId($accountId);
        $accountsCollection = getCollection('accounts');
        $account = $accountsCollection->findOne(['_id' => $accountObjectId, 'user_id' => $userObjectId]);

        if (!$account) {
            throw new Exception('Selected account not found or does not belong to you.', 404);
        }
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        error_log("ERROR: Invalid account ID format received: '$accountId' in order_card.php: " . $e->getMessage());
        throw new Exception('Invalid account ID format provided.', 400);
    } catch (Exception $e) {
        error_log("ERROR: Account ID lookup failed for ID '$accountId' in order_card.php: " . $e->getMessage());
        throw new Exception('Account verification failed. Please try again.', 500);
    }

    // Add this line to confirm validation passed
    error_log("DEBUG: All input validations passed.");

    // --- Card Generation Functions (Assumed to be defined elsewhere or included) ---
    // Make sure these functions are available.
    function generateCardNumber(string $network): string { /* ... */ return '1234567890123456'; } // Placeholder
    function generateExpiryDate(): string { /* ... */ return '12/28'; } // Placeholder
    function generateCVV(): string { /* ... */ return '123'; } // Placeholder
    function generatePIN(): string { /* ... */ return '4567'; } // Placeholder

    $cardNumber = generateCardNumber($cardNetwork);
    $expiryDate = generateExpiryDate();
    $cvv = generateCVV();
    $pin = generatePIN();

    $bankCardsCollection = getCollection('bank_cards');

    // Calculate delivery date estimate: current time + 7 calendar days
    $deliveryDate = new DateTime();
    $deliveryDate->modify('+7 days'); // Add 7 calendar days for the estimate
    $delivery_date_formatted = $deliveryDate->format('F j, Y'); // For email readability

    $newCard = [
        'user_id' => $userObjectId,
        'account_id' => $accountObjectId,
        'card_holder_name' => $cardHolderName,
        'card_type' => $cardType,
        'card_network' => $cardNetwork,
        'card_number_full' => $cardNumber, // For demo, store full. Encrypt in production.
        'card_number_masked' => '************' . substr($cardNumber, -4), // For display
        'expiry_date' => $expiryDate,
        'cvv_hashed' => hash('sha256', $cvv), // Hash CVV (do not store raw in production)
        'pin_hashed' => password_hash($pin, PASSWORD_DEFAULT), // Hash PIN
        'shipping_address' => $shippingAddress,
        'order_date' => new MongoDB\BSON\UTCDateTime(),
        'status' => 'pending_delivery',
        'is_active' => false,
        'activation_code' => bin2hex(random_bytes(8)),
        'activation_date' => null,
        'delivery_date_estimate' => $deliveryDate->format('Y-m-d H:i:s'),
    ];

    $insertResult = $bankCardsCollection->insertOne($newCard);

    if ($insertResult->getInsertedCount() === 1) {
        $newCardId = (string)$insertResult->getInsertedId();
        $response_data = [
            'success' => true,
            'message' => 'Your bank card has been successfully ordered and will be delivered to your address within 7 business days. Please activate it upon delivery.',
            'cardId' => $newCardId
        ];
        $statusCode = 200;
        
        error_log("DEBUG: Card successfully inserted. PHPMailer section is commented out for testing. Final response is ready.");

        // --- Temporarily Commented Out Email Sending to Isolate Error ---
        /*
        error_log("DEBUG: Card successfully inserted. Preparing to send email.");
        $mail = new PHPMailer(true); // true enables exceptions
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host      = getenv('SMTP_HOST') ?: 'smtp.gmail.com'; // Your SMTP server
            $mail->SMTPAuth  = true;
            $mail->Username  = getenv('SMTP_USERNAME') ?: 'hometownbankpa@gmail.com'; // SMTP username
            $mail->Password  = getenv('SMTP_PASSWORD'); // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SMTPS (465) or STARTTLS (587)
            $mail->Port      = 465; // TCP port to connect to; use 587 if you set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            // Recipients
            $sender_email = getenv('ADMIN_EMAIL') ?: 'hometownbankpa@gmail.com';
            $sender_name = getenv('SMTP_FROM_NAME') ?: 'Hometown Bank PA';

            $mail->setFrom($sender_email, $sender_name);
            $mail->addAddress($recipient_email, $recipient_name); // Add a recipient

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your Hometown Bank PA Card Order Confirmation';
            $mail->Body    = "
                <p>Dear {$recipient_name},</p>
                <p>Thank you for ordering a new card from Hometown Bank PA!</p>
                <p>Here are the details of your order:</p>
                <ul>
                    <li><strong>Card Type:</strong> {$cardType}</li>
                    <li><strong>Card Network:</strong> {$cardNetwork}</li>
                    <li><strong>Card Holder Name:</strong> {$cardHolderName}</li>
                    <li><strong>Linked Account ID:</strong> {$accountId}</li>
                    <li><strong>Shipping Address:</strong> {$shippingAddress}</li>
                    <li><strong>Order Date:</strong> " . (new DateTime())->format('F j, Y') . "</li>
                    <li><strong>Estimated Delivery:</strong> On or before {$delivery_date_formatted}</li>
                </ul>
                <p>Your card (ending in " . substr($cardNumber, -4) . ") is currently <b>pending delivery</b>. Once you receive it, please visit the Card Management section in your online banking portal to activate it and set your Personal Identification Number (PIN).</p>
                <p>If you have any questions, please contact our support team.</p>
                <p>Sincerely,</p>
                <p>The Hometown Bank PA Team</p>
            ";
            $mail->AltBody = "Dear {$recipient_name},\n\nThank you for ordering a new card from Hometown Bank PA!\n\nHere are the details of your order:\n- Card Type: {$cardType}\n- Card Network: {$cardNetwork}\n- Card Holder Name: {$cardHolderName}\n- Linked Account ID: {$accountId}\n- Shipping Address: {$shippingAddress}\n- Order Date: " . (new DateTime())->format('F j, Y') . "\n- Estimated Delivery: On or before {$delivery_date_formatted}\n\nYour card (ending in " . substr($cardNumber, -4) . ") is currently pending delivery. Once you receive it, please visit the Card Management section in your online banking portal to activate it and set your Personal Identification Number (PIN).\n\nIf you have any questions, please contact our support team.\n\nSincerely,\n\nThe Hometown Bank PA Team";

            $mail->send();
            error_log("SUCCESS: Order confirmation email sent to {$recipient_email} for card ID {$newCardId}.");

        } catch (PHPMailerException $e) {
            error_log("EMAIL ERROR: Failed to send order confirmation email to {$recipient_email}. Mailer Error: {$mail->ErrorInfo}. Exception: {$e->getMessage()}");
            $response_data['message'] .= " However, there was an issue sending your confirmation email. Please check your spam folder.";
        }
        */
        // --- End of Commented Section ---

    } else {
        error_log("ERROR: Failed to insert new card for user " . $userObjectId . ". Insert count: " . $insertResult->getInsertedCount());
        throw new Exception('Failed to place card order. Database insertion issue.', 500);
    }

} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB Driver EXCEPTION in order_card.php: " . $e->getMessage() . " Code: " . $e->getCode() . " File: " . $e->getFile() . " Line: " . $e->getLine());
    $response_data = ['success' => false, 'message' => 'A database error occurred while processing your card order.'];
    $statusCode = 500;
} catch (Exception $e) {
    error_log("GENERIC EXCEPTION in order_card.php: " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());

    $exceptionCode = $e->getCode();
    $statusCode = ($exceptionCode >= 100 && $exceptionCode < 600) ? $exceptionCode : 500;

    if ($statusCode >= 400 && $statusCode < 500) {
        $response_data = ['success' => false, 'message' => $e->getMessage()];
    } else {
        $response_data = ['success' => false, 'message' => 'An unexpected server error occurred during card order. Please try again.'];
    }
}

// Set the HTTP status code before sending the JSON response
http_response_code($statusCode);
echo json_encode($response_data);
exit; // Ensure nothing else is outputted