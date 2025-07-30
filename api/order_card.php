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

// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // Use a specific alias to avoid conflict with general Exception

// Initialize a variable to hold the response data
$response_data = [];
$statusCode = 200; // Default success status code

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

    // --- Input Validation ---
    // Check if all necessary POST data is present
    if (!isset($_POST['cardHolderName'], $_POST['cardType'], $_POST['cardNetwork'], $_POST['accountId'], $_POST['shippingAddress'])) {
        throw new Exception('All card order fields are required.', 400);
    }

    $cardHolderName = $_POST['cardHolderName'];
    $cardType = $_POST['cardType'];
    $cardNetwork = $_POST['cardNetwork'];
    $accountId = $_POST['accountId'];
    $shippingAddress = $_POST['shippingAddress'];

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

    // Fetch user details for email notification (recipient name and email)
    $usersCollection = getCollection('users');
    $user_doc = $usersCollection->findOne(['_id' => $userObjectId]);
    if (!$user_doc || empty($user_doc['email'])) {
        error_log("CRITICAL ERROR: Logged-in user with ID " . $user_id_string . " not found or email missing. Cannot send order confirmation.");
        throw new Exception('User email not available. Please contact support.', 500);
    }
    $recipient_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
    $recipient_email = $user_doc['email'];

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
        
        // --- START OF EMAIL SENDING ---
        // This section is now uncommented to send the email.
        $mail = new PHPMailer(true);
        try {
            // Server settings...
            // (These settings remain the same)
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_USERNAME') ?: 'hometownbankpa@gmail.com';
            $mail->Password   = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            // Recipients...
            // (These settings remain the same)
            $sender_email = getenv('ADMIN_EMAIL') ?: 'hometownbankpa@gmail.com';
            $sender_name = getenv('SMTP_FROM_NAME') ?: 'Hometown Bank PA';
            $mail->setFrom($sender_email, $sender_name);
            $mail->addAddress($recipient_email, $recipient_name);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your Hometown Bank PA Card Order Confirmation';

            // New and improved HTML body with inline CSS for better compatibility
            $mail->Body = '
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Card Order Confirmation</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                    .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); overflow: hidden; }
                    .header { background-color: #004d99; color: #ffffff; padding: 20px; text-align: center; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                    .content { padding: 30px; line-height: 1.6; color: #333333; }
                    .button { display: inline-block; padding: 12px 24px; margin-top: 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #999999; border-top: 1px solid #eeeeee; margin-top: 20px; }
                    .details-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    .details-table th, .details-table td { text-align: left; padding: 10px; border-bottom: 1px solid #f0f0f0; }
                    .details-table th { background-color: #f9f9f9; width: 40%; font-weight: 600; }
                </style>
            </head>
            <body>
                <div class="email-container">
                    <div class="header">
                        <h1>Hometown Bank PA</h1>
                    </div>
                    <div class="content">
                        <h2>Hello ' . htmlspecialchars($recipient_name) . ',</h2>
                        <p>Thank you for ordering a new bank card. We have successfully processed your request and your card is now being prepared for shipping.</p>
                        
                        <p>Here are the details of your order:</p>
                        
                        <table class="details-table">
                            <tr>
                                <th>Card Type</th>
                                <td>' . htmlspecialchars($cardType) . '</td>
                            </tr>
                            <tr>
                                <th>Card Network</th>
                                <td>' . htmlspecialchars($cardNetwork) . '</td>
                            </tr>
                            <tr>
                                <th>Card Holder Name</th>
                                <td>' . htmlspecialchars($cardHolderName) . '</td>
                            </tr>
                            <tr>
                                <th>Linked Account ID</th>
                                <td>' . htmlspecialchars($accountId) . '</td>
                            </tr>
                            <tr>
                                <th>Shipping Address</th>
                                <td>' . nl2br(htmlspecialchars($shippingAddress)) . '</td>
                            </tr>
                            <tr>
                                <th>Order Date</th>
                                <td>' . (new DateTime())->format('F j, Y') . '</td>
                            </tr>
                            <tr>
                                <th>Estimated Delivery</th>
                                <td>On or before ' . htmlspecialchars($delivery_date_formatted) . '</td>
                            </tr>
                        </table>
                        
                        <p>Your new card will be delivered to the address provided. Once you receive it, please visit your online banking portal to activate it and set a new PIN.</p>
                        
                        <p style="text-align: center;">
                           <a href="' . BASE_URL . '/my_cards" class="button">Manage My Cards</a>
                        </p>
                        
                        <p>If you have any questions, please feel free to contact our support team.</p>
                        <p>Sincerely,<br>The Hometown Bank PA Team</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date("Y") . ' Hometown Bank PA. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>';

            // Plain-text alternative for email clients that don't support HTML
            $mail->AltBody = "Hello {$recipient_name},\n\nThank you for ordering a new card from Hometown Bank PA!\n\nHere are the details of your order:\n\n- Card Type: {$cardType}\n- Card Network: {$cardNetwork}\n- Card Holder Name: {$cardHolderName}\n- Linked Account ID: {$accountId}\n- Shipping Address: {$shippingAddress}\n- Order Date: " . (new DateTime())->format('F j, Y') . "\n- Estimated Delivery: On or before {$delivery_date_formatted}\n\nYour card is currently pending delivery. Once you receive it, please visit the Card Management section in your online banking portal to activate it and set your Personal Identification Number (PIN).\n\nIf you have any questions, please contact our support team.\n\nSincerely,\n\nThe Hometown Bank PA Team";

            $mail->send();
            error_log("SUCCESS: Order confirmation email sent to {$recipient_email} for card ID {$newCardId}.");

        } catch (PHPMailerException $e) {
            error_log("EMAIL ERROR: Failed to send order confirmation email to {$recipient_email}. Mailer Error: {$mail->ErrorInfo}. Exception: {$e->getMessage()}");
            $response_data['message'] .= " However, there was an issue sending your confirmation email. Please check your spam folder.";
        }
        // --- END OF EMAIL SENDING ---

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
exit;