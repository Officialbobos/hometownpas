<?php
// Config.php should be included first to ensure session and constants are available.

// Ensure session is started only once. It's often best to handle this in Config.php
// if Config.php is the first file included in every request.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CORRECTED: Use __DIR__ for robust path resolution
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // Ensure functions.php is included if it contains getMongoDBClient() or other helpers

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php'); // Use BASE_URL for redirects
    exit;
}

$user_id = $_SESSION['user_id']; // This should be a string representation of ObjectId from login
$user_full_name = $_SESSION['user_full_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

$message = '';
$message_type = '';

$client = null;
$database = null;
$usersCollection = null;
$accountsCollection = null;
$bankCardsCollection = null;

try {
    // Establish MongoDB connection using the CORRECTED CONSTANTS
    $client = getMongoDBClient(); // Use the helper function from functions.php
    $database = $client->selectDatabase(MONGODB_DB_NAME); // Use constant from Config.php
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
    $bankCardsCollection = $database->selectCollection('bank_cards');

    // Convert user_id from session string to MongoDB ObjectId
    $userObjectId = new ObjectId($user_id);

    // Fetch user's full name and email from the database if not already in session
    // This provides a fallback if session data is somehow lost or incomplete.
    if (empty($user_full_name) || empty($user_email)) {
        $user_doc = $usersCollection->findOne(['_id' => $userObjectId]);
        if ($user_doc) {
            $user_full_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
            $user_email = $user_doc['email'] ?? '';
            // Update session with fetched data for future requests
            $_SESSION['user_full_name'] = $user_full_name;
            $_SESSION['user_email'] = $user_email;
        } else {
            // Log error if user not found, but allow page to load with generic name
            error_log("User with ID " . $user_id . " not found in database for bank_cards.php.");
            $user_full_name = 'Bank Customer'; // Fallback if DB lookup fails
            $user_email = 'default@example.com';
        }
    }
} catch (MongoDBDriverException $e) {
    error_log("MongoDB connection or initial data fetch error in bank_cards.php: " . $e->getMessage());
    $message = "Database connection error. Please try again later. (Code: MDB_INIT)";
    $message_type = 'error';
    // For AJAX requests, return JSON; otherwise, die with an HTML message.
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => $message]));
    } else {
        die("<h1>" . htmlspecialchars($message) . "</h1><p>Please check application logs for details.</p>");
    }
} catch (Exception $e) { // Catch for ObjectId conversion or other general errors
    error_log("General error during initial setup in bank_cards.php: " . $e->getMessage());
    $message = "An unexpected error occurred during page setup. Please try again later.";
    $message_type = 'error';
    // If user_id is invalid, it's safer to redirect to login
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        die(json_encode(['success' => false, 'message' => $message]));
    } else {
        header('Location: ' . BASE_URL . '/index.php?error=invalid_user_session'); // Redirect with an error indicator
        exit;
    }
}

// Ensure full name and email are set for display, even if database lookup failed
$user_full_name = $user_full_name ?: 'Bank Customer';
$user_email = $user_email ?: 'default@example.com';

// --- Handle AJAX requests (fetching cards or ordering new card) ---
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_cards') {
        $bank_cards = [];
        try {
            $cursor = $bankCardsCollection->find(['user_id' => $userObjectId]);

            foreach ($cursor as $row) {
                $row_arr = (array) $row; // Convert BSON document to PHP array
                $row_arr['id'] = (string) $row_arr['_id']; // Convert MongoDB _id to string for consistency
                $row_arr['display_card_number'] = '**** **** **** ' . substr($row_arr['card_number'] ?? '', -4);
                $row_arr['card_holder_name'] = strtoupper($user_full_name);
                $row_arr['display_expiry'] = str_pad($row_arr['expiry_month'] ?? '', 2, '0', STR_PAD_LEFT) . '/' . substr($row_arr['expiry_year'] ?? '', 2, 2);
                $row_arr['display_cvv'] = '***'; // Always mock CVV for security

                // Determine the card logo path based on the network
                $card_network_lower = strtolower($row_arr['card_network'] ?? 'default');

                // Corrected image path for PHP file_exists check and for frontend URL
                // Assuming images are in the 'frontend/images/card_logos/' directory
                $file_system_logo_path = __DIR__ . '/../frontend/images/card_logos/' . $card_network_lower . '.png'; // Adjusted path
                $card_logo_url = FRONTEND_BASE_URL . 'images/card_logos/' . $card_network_lower . '.png';

                // Fallback to default.png if specific logo not found on server
                if (!file_exists($file_system_logo_path)) {
                    $card_logo_url = FRONTEND_BASE_URL . 'images/card_logos/default.png';
                }
                $row_arr['card_logo_src'] = $card_logo_url;

                // Unset sensitive data before sending to frontend
                unset($row_arr['card_number']);
                unset($row_arr['cvv']);
                unset($row_arr['pin']); // Assuming 'pin' field might exist
                unset($row_arr['_id']); // Remove internal MongoDB ID from frontend
                unset($row_arr['user_id']); // Remove internal MongoDB ID from frontend
                unset($row_arr['account_id']); // Remove internal MongoDB ID from frontend

                $bank_cards[] = $row_arr;
            }
            echo json_encode(['success' => true, 'cards' => $bank_cards]);
        } catch (MongoDBDriverException $e) {
            error_log("Error fetching bank cards (AJAX): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error fetching cards: " . $e->getMessage()]);
        }
        exit; // IMPORTANT: Exit after sending JSON
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'order_card') {
        $cardType = filter_input(INPUT_POST, 'cardType', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cardNetwork = filter_input(INPUT_POST, 'cardNetwork', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $shippingAddress = filter_input(INPUT_POST, 'shippingAddress', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $accountId = filter_input(INPUT_POST, 'accountId', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Account ID will also be ObjectId

        if (empty($cardType) || empty($cardNetwork) || empty($shippingAddress) || empty($accountId)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required for ordering a new card, including the linked bank account.']);
            exit;
        }

        try {
            $accountObjectId = new ObjectId($accountId);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Invalid linked account ID format.']);
            exit;
        }

        // Generate mock card details
        $prefix = '';
        if ($cardNetwork === 'Visa') {
            $prefix = '4';
        } elseif ($cardNetwork === 'Mastercard') {
            $mc_prefixes = ['51', '52', '53', '54', '55'];
            $prefix = $mc_prefixes[array_rand($mc_prefixes)];
        } elseif ($cardNetwork === 'Amex') {
            $prefix = (mt_rand(0, 1) === 0) ? '34' : '37';
        } elseif ($cardNetwork === 'Verve') {
            $prefix = '5061';
        } else {
            $prefix = '9'; // Default for unknown networks
        }

        $card_number_length = ($cardNetwork === 'Amex') ? 15 : 16;
        $remaining_digits_length = $card_number_length - strlen($prefix);
        $random_digits = '';
        for ($i = 0; $i < $remaining_digits_length; $i++) {
            $random_digits .= mt_rand(0, 9);
        }
        $mock_card_number = $prefix . $random_digits;

        $mock_expiry_month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
        $mock_expiry_year = date('Y') + mt_rand(3, 7);
        $mock_cvv = str_pad(mt_rand(0, ($cardNetwork === 'Amex' ? 9999 : 999)), ($cardNetwork === 'Amex' ? 4 : 3), '0', STR_PAD_LEFT);
        $mock_card_holder_name = strtoupper($user_full_name);
        $initial_pin_hash = null; // Stored as null in MongoDB, PIN set on activation

        try {
            // Verify the linked account belongs to the user
            $account_data = $accountsCollection->findOne([
                '_id' => $accountObjectId,
                'user_id' => $userObjectId
            ]);

            if (!$account_data) {
                echo json_encode(['success' => false, 'message' => "Selected account not found or does not belong to your profile."]);
                exit;
            }

            $linked_account_type = $account_data['account_type'] ?? 'N/A';
            $linked_account_number = $account_data['account_number'] ?? 'N/A';

            // Insert new card into MongoDB
            $insertOneResult = $bankCardsCollection->insertOne([
                'user_id' => $userObjectId, // Store as ObjectId
                'account_id' => $accountObjectId, // Store as ObjectId
                'card_number' => $mock_card_number,
                'card_type' => $cardType,
                'expiry_month' => $mock_expiry_month,
                'expiry_year' => $mock_expiry_year,
                'cvv' => $mock_cvv, // DANGER: Remove in production or encrypt. Only for mock purposes.
                'card_holder_name' => $mock_card_holder_name,
                'is_active' => false, // New cards are inactive until activated by user
                'card_network' => $cardNetwork,
                'shipping_address' => $shippingAddress,
                'pin' => $initial_pin_hash, // Will be null initially
                'created_at' => new MongoDB\BSON\UTCDateTime(), // Timestamp
                'updated_at' => new MongoDB\BSON\UTCDateTime()  // Timestamp
            ]);

            if ($insertOneResult->getInsertedCount() === 1) {
                $inserted_card_id = (string) $insertOneResult->getInsertedId(); // Get string representation of new ObjectId

                // Email sending logic - using sendEmail function
                $subject = "Your HomeTown Bank Card Order Confirmation";
                $body = "Dear " . htmlspecialchars($user_full_name) . ",\n\n"
                    . "Thank you for ordering a new " . htmlspecialchars($cardNetwork) . " " . htmlspecialchars($cardType) . " card linked to your " . htmlspecialchars($linked_account_type) . " account (" . htmlspecialchars($linked_account_number) . ") from HomeTown Bank PA.\n\n"
                    . "Your order for a new card (ID: " . $inserted_card_id . ") has been successfully placed.\n"
                    . "Card Type: " . htmlspecialchars($cardType) . "\n"
                    . "Card Network: " . htmlspecialchars($cardNetwork) . "\n"
                    . "Linked Account: " . htmlspecialchars($linked_account_type) . " (No: " . htmlspecialchars($linked_account_number) . ")\n"
                    . "Shipping Address: " . htmlspecialchars($shippingAddress) . "\n\n"
                    . "Your card is currently being processed and will be shipped to the address provided. You will receive it within 5-7 business days.\n\n"
                    . "Once you receive your card, please log in to your dashboard to activate it and set your PIN.\n\n"
                    . "If you have any questions, please contact our customer support.\n\n"
                    . "Sincerely,\n"
                    . "The HomeTown Bank PA Team";

                if (sendEmail($user_email, $subject, nl2br($body), true)) { // Use nl2br for simple HTML formatting, true for isHtml
                    $email_status = "Email sent successfully.";
                } else {
                    $email_status = "Failed to send email. Please check server logs.";
                    error_log("Failed to send card order confirmation email to " . $user_email . " for user_id: " . $user_id);
                }

                echo json_encode(['success' => true, 'message' => 'Your card order has been placed successfully! ' . $email_status]);

            } else {
                throw new Exception("Failed to save card order to database.");
            }
        } catch (MongoDBDriverException $e) {
            error_log("Error ordering new card (MongoDB): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Database error placing order: " . $e->getMessage()]);
        } catch (Exception $e) {
            error_log("Error ordering new card (General): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => "Error placing order: " . $e->getMessage()]);
        }
        exit; // IMPORTANT: Exit after sending JSON
    }
    // If an AJAX request comes in but no specific action is matched
    echo json_encode(['success' => false, 'message' => 'Invalid AJAX action provided to bank_cards.php.']);
    exit;
}

// --- Non-AJAX request, render the HTML page ---
// We need to fetch the accounts for the dropdown in the form
$user_accounts_for_dropdown = [];
try {
    // Re-use $userObjectId from the initial connection block
    $cursor = $accountsCollection->find(
        ['user_id' => $userObjectId],
        ['projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]] // Added balance and currency for display in JS
    );
    foreach ($cursor as $accountDoc) {
        $user_accounts_for_dropdown[] = [
            'id' => (string) $accountDoc['_id'], // Convert ObjectId to string for HTML value
            'account_type' => $accountDoc['account_type'] ?? 'Account',
            'display_account_number' => '****' . substr($accountDoc['account_number'] ?? '', -4),
            'balance' => $accountDoc['balance'] ?? 0.00, // Make sure to fetch balance
            'currency' => $accountDoc['currency'] ?? 'USD' // Make sure to fetch currency
        ];
    }
} catch (MongoDBDriverException $e) {
    error_log("Error fetching accounts for dropdown: " . $e->getMessage());
    $message = "Could not load accounts for linking cards.";
    $message_type = 'error';
} catch (Exception $e) {
    error_log("Error processing user ID for accounts dropdown: " . $e->getMessage());
    $message = "Error loading user data.";
    $message_type = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Manage Cards</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for cards.html - Now inline in bank_cards.php */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            color: #333;
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(45deg, #6a11cb, #2575fc); /* Glow purple gradient */
            padding: 20px 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #eee;
        }

        .header .logo img {
            max-height: 45px; /* Reduced logo size */
            width: auto;
        }

        .header h1 {
            color: #ffffff; /* White text for header title */
            font-size: 1.8rem; /* Reduced font size for "Manage My Cards" */
            margin: 0;
            flex-grow: 1;
            text-align: center;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* Back to Dashboard Button Styling */
        .back-to-dashboard {
            display: inline-flex;
            align-items: center;
            padding: 10px 15px;
            background-color: rgba(255, 255, 255, 0.2); /* Semi-transparent white */
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            transition: background-color 0.3s ease, transform 0.2s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
        }

        .back-to-dashboard i {
            margin-right: 8px;
        }

        .back-to-dashboard:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .main-content {
            flex-grow: 1;
            padding: 30px 20px;
            max-width: 900px;
            margin: 30px auto;
            background-color: #ffffff;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #e0e0e0;
            width: calc(100% - 40px);
            box-sizing: border-box;
        }

        h2 {
            color: #4a0d93;
            font-size: 2rem;
            margin-bottom: 30px;
            text-align: center;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .cards-section, .order-card-section {
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .cards-section:last-child, .order-card-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .card-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); /* Adjusted min card width for better visuals */
            gap: 25px; /* Adjusted gap */
            margin-top: 25px; /* Adjusted margin */
            justify-content: center; /* Center cards in grid */
        }

        .card-item {
            background: linear-gradient(135deg, #f8f8f8, #e0e0e0);
            border: 1px solid #dcdcdc;
            border-radius: 18px; /* Slightly larger border-radius */
            padding: 25px; /* Adjusted padding */
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); /* Adjusted shadow */
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 200px; /* Adjusted min-height */
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }

        /* Specific card network styles for background, if desired */
        .card-item.visa { background: linear-gradient(135deg, #2a4b8d, #3f60a9); color: white;}
        .card-item.mastercard { background: linear-gradient(135deg, #eb001b, #ff5f00); color: white;}
        .card-item.amex { background: linear-gradient(135deg, #0081c7, #26a5d4); color: white;}
        .card-item.verve { background: linear-gradient(135deg, #006633, #009933); color: white;}
        /* Ensure text is readable on dark backgrounds */
        .card-item.visa h4, .card-item.visa .card-number, .card-item.visa .card-footer .label, .card-item.visa .card-footer .value, .card-item.visa .card-status { color: white; }
        .card-item.mastercard h4, .card-item.mastercard .card-number, .card-item.mastercard .card-footer .label, .card-item.mastercard .card-footer .value, .card-item.mastercard .card-status { color: white; }
        .card-item.amex h4, .card-item.amex .card-number, .card-item.amex .card-footer .label, .card-item.amex .card-footer .value, .card-item.amex .card-status { color: white; }
        .card-item.verve h4, .card-item.verve .card-number, .card-item.verve .card-footer .label, .card-item.verve .card-footer .value, .card-item.verve .card-status { color: white; }


        .card-item h4 {
            margin-top: 0;
            font-size: 1.1em;
            color: #333; /* Default text color for card issuer name */
        }
        .card-item.visa h4, .card-item.mastercard h4, .card-item.amex h4, .card-item.verve h4 {
            color: rgba(255,255,255,0.9); /* Lighter color for issuer name on colored cards */
        }

        .card-logo-img { /* This is the actual logo image from the backend */
            position: absolute;
            bottom: 20px;
            right: 20px;
            height: 45px; /* Slightly larger for better visibility */
            width: auto;
            max-width: 80px; /* Ensure it doesn't get too big */
        }

        .card-item .chip {
            width: 50px; /* Standard chip size */
            height: 35px;
            background-color: #d4af37;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .card-number {
            font-family: 'Space Mono', monospace;
            font-size: 1.6em; /* Adjusted font size for impact */
            letter-spacing: 2px;
            margin-bottom: 20px;
            color: #444; /* Default card number color */
            text-align: center;
            word-break: break-all;
        }
        .card-item.visa .card-number, .card-item.mastercard .card-number, .card-item.amex .card-number, .card-item.verve .card-number {
            color: #fff; /* White for card numbers on colored cards */
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 0.9em;
            width: 100%;
        }

        .card-footer .label {
            font-size: 0.7em;
            opacity: 0.7;
            margin-bottom: 3px;
            color: #666; /* Default label color */
        }
        .card-item.visa .card-footer .label, .card-item.mastercard .card-footer .label, .card-item.amex .card-footer .label, .card-item.verve .card-footer .label {
            color: rgba(255,255,255,0.7); /* Lighter label color on colored cards */
        }

        .card-footer .value {
            font-weight: bold;
            color: #333; /* Default value color */
        }
        .card-item.visa .card-footer .value, .card-item.mastercard .card-footer .value, .card-item.amex .card-footer .value, .card-item.verve .card-footer .value {
            color: #fff; /* White value color on colored cards */
        }

        p.card-cvv-mock { /* Styling for the CVV mock text */
            font-size: 0.8em;
            text-align: center;
            margin-top: 10px;
            color: #888;
        }
        .card-item.visa p.card-cvv-mock, .card-item.mastercard p.card-cvv-mock, .card-item.amex p.card-cvv-mock, .card-item.verve p.card-cvv-mock {
            color: rgba(255,255,255,0.7); /* Lighter CVV text on colored cards */
        }

        .card-status {
            font-size: 0.9em;
            text-align: right;
            margin-top: 10px;
            opacity: 0.9;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block; /* Allows padding to apply correctly */
        }
        .card-status.active { background-color: #d4edda; color: #155724; }
        .card-status.inactive { background-color: #f8d7da; color: #721c24; }
        /* For colored cards, status text should also be white */
        .card-item.visa .card-status, .card-item.mastercard .card-status, .card-item.amex .card-status, .card-item.verve .card-status {
            color: white; /* Make status text white on dark cards */
        }
        .card-item.visa .card-status.active { background-color: rgba(100, 255, 100, 0.3); border: 1px solid rgba(100, 255, 100, 0.5); }
        .card-item.visa .card-status.inactive { background-color: rgba(255, 100, 100, 0.3); border: 1px solid rgba(255, 100, 100, 0.5); }
        /* Apply similar rgba backgrounds for other colored cards if needed, or keep original if current contrast is good */
        .card-item.mastercard .card-status.active, .card-item.amex .card-status.active, .card-item.verve .card-status.active {
            background-color: rgba(100, 255, 100, 0.3); border: 1px solid rgba(100, 255, 100, 0.5);
        }
        .card-item.mastercard .card-status.inactive, .card-item.amex .card-status.inactive, .card-item.verve .card-status.inactive {
            background-color: rgba(255, 100, 100, 0.3); border: 1px solid rgba(255, 100, 100, 0.5);
        }

        /* Order New Card Section - Form and button styling */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #444; font-size: 0.95rem; }
        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd0d5;
            border-radius: 8px;
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: #fcfcfc;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #6a11cb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(106, 17, 203, 0.2);
            background-color: #ffffff;
        }

        .submit-button {
            padding: 14px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(45deg, #2ecc71, #27ae60);
            color: #ffffff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            width: auto;
            min-width: 220px;
            margin-top: 20px; /* Added margin for separation */
        }

        .submit-button:hover {
            background: linear-gradient(45deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(46, 204, 113, 0.4);
        }

        /* Loading and No Data Messages */
        .loading-message, .no-data-message {
            text-align: center;
            font-style: italic;
            color: #666;
            margin: 20px 0;
            font-size: 1.0rem;
        }

        .loading-message i {
            margin-right: 8px;
        }

        /* Custom Message Box */
        .message-box-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .message-box-overlay.show { /* Changed to 'show' as per cards.js */
            opacity: 1;
            visibility: visible;
        }

        .message-box-content {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            border: 1px solid #eee;
        }

        .message-box-overlay.show .message-box-content { /* Changed to 'show' as per cards.js */
            transform: translateY(0);
        }

        .message-box-content p {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 25px;
        }

        .message-box-content button {
            background: linear-gradient(45deg, #2575fc, #6a11cb);
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.0rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .message-box-content button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px 20px;
                align-items: center;
            }
            .header .logo {
                margin-bottom: 10px;
            }
            .header h1 {
                font-size: 1.5rem; /* Further reduced for smaller screens */
                text-align: center;
                margin-top: 10px;
            }
            .header-nav {
                width: 100%;
                text-align: center;
                margin-top: 15px;
            }
            .back-to-dashboard {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            .main-content {
                margin: 20px 10px;
                padding: 20px;
                border-radius: 12px;
                width: calc(100% - 20px);
            }
            h2 {
                font-size: 1.8rem;
                margin-bottom: 25px;
            }
            .card-list {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .card-item {
                min-height: auto;
                padding: 20px;
            }
            .card-logo-img {
                height: 40px;
                bottom: 15px;
                right: 15px;
            }
            .card-item h4 {
                font-size: 1em;
            }
            .card-item .chip {
                width: 45px;
                height: 30px;
                margin-bottom: 15px;
            }
            .card-number {
                font-size: 1.4rem;
                letter-spacing: 1.5px;
                margin-bottom: 15px;
            }
            .card-footer {
                font-size: 0.85em;
            }
            p.card-cvv-mock {
                font-size: 0.75em;
            }
            .card-status {
                font-size: 0.8em;
                padding: 4px 8px;
            }

            .order-card-section input,
            .order-card-section select,
            .order-card-section textarea {
                padding: 10px;
                font-size: 0.9rem;
            }
            .order-card-section .submit-button {
                padding: 12px 15px;
                font-size: 0.95rem;
            }
            .message-box-content {
                max-width: 350px;
                padding: 25px;
            }
            .message-box-content p {
                font-size: 1rem;
            }
            .message-box-content button {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
        }

        /* Specific styles for very small mobile devices (e.g., iPhone SE) */
        @media (max-width: 400px) {
            .header h1 {
                font-size: 1.3rem;
            }
            .main-content {
                margin: 15px 5px;
                padding: 15px;
                width: calc(100% - 10px);
            }
            h2 {
                font-size: 1.6rem;
                margin-bottom: 20px;
            }
            .card-item {
                padding: 15px;
            }
            .card-logo-img {
                height: 35px;
            }
            .card-item .chip {
                width: 40px;
                height: 25px;
            }
            .card-number {
                font-size: 1.2rem;
            }
            .card-footer {
                font-size: 0.8em;
            }
            p.card-cvv-mock {
                font-size: 0.7em;
            }
            .card-status {
                font-size: 0.75em;
            }
            .order-card-section label {
                font-size: 0.85rem;
            }
            .order-card-section input,
            .order-card-section select,
            .order-card-section textarea {
                padding: 8px;
                font-size: 0.85rem;
            }
            .order-card-section .submit-button {
                padding: 10px 12px;
                font-size: 0.85rem;
            }
            .message-box-content {
                max-width: 280px;
                padding: 15px;
            }
            .message-box-content p {
                font-size: 0.9rem;
            }
            .message-box-content button {
                padding: 6px 15px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <a href="<?php echo BASE_URL; ?>/dashboard">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="Hometown Bank PA Logo">
            </a>
        </div>
        <h1>Manage My Cards</h1>
        <nav class="header-nav">
            <a href="<?php echo BASE_URL; ?>/dashboard" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </nav>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <section class="cards-section">
            <h2>Your Current Cards</h2>
            <p id="cardsLoadingMessage" class="loading-message">
                <i class="fas fa-spinner fa-spin"></i> Loading your cards...
            </p>
            <div id="userCardList" class="card-list">
                <p class="no-data-message" id="noCardsMessage" style="display:none;">No bank cards found. Order a new one below!</p>
            </div>
        </section>

        <section class="order-card-section">
            <h2>Order a New Card</h2>
            <form id="orderCardForm">
                <input type="hidden" name="action" value="order_card">
                <div class="form-group">
                    <label for="cardType">Card Type:</label>
                    <select id="cardType" name="cardType" required>
                        <option value="">Select Card Type</option>
                        <option value="Debit">Debit Card</option>
                        <option value="Credit">Credit Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cardNetwork">Card Network:</label>
                    <select id="cardNetwork" name="cardNetwork" required>
                        <option value="">Select Card Network</option>
                        <option value="Visa">Visa</option>
                        <option value="Mastercard">Mastercard</option>
                        <option value="Amex">American Express</option>
                        <option value="Verve">Verve</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="accountId">Link to Account:</label>
                    <select id="accountId" name="accountId" required>
                        <option value="">Select an Account</option>
                        <?php foreach ($user_accounts_for_dropdown as $account): ?>
                            <option value="<?php echo htmlspecialchars($account['id']); ?>">
                                <?php echo htmlspecialchars($account['account_type'] . ' (' . $account['display_account_number'] . ') - ' . $account['currency'] . sprintf('%.2f', $account['balance'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="shippingAddress">Shipping Address:</label>
                    <textarea id="shippingAddress" name="shippingAddress" placeholder="Your full shipping address" rows="3" required></textarea>
                </div>

                <button type="submit" class="submit-button">
                    <i class="fas fa-credit-card" style="margin-right: 8px;"></i> Place Card Order
                </button>
            </form>
        </section>

        <section class="manage-pin-section">
            <h2>Manage Card PIN & Activation</h2>
            <p>To activate a new card or set/change your existing card's PIN, please visit the <a href="<?php echo BASE_URL; ?>/my_cards">Card Activation & PIN Management page</a>.</p>
        </section>
    </main>

    <div class="message-box-overlay" id="messageBoxOverlay">
        <div class="message-box-content" id="messageBoxContentWrapper">
            <p id="messageBoxContent"></p>
            <button id="messageBoxButton">OK</button>
        </div>
    </div>
<script>
    // These variables must be defined before cards.js is loaded
    const PHP_BASE_URL = <?php echo json_encode(BASE_URL); ?>;
    // Assuming 'frontend' is directly under your BASE_URL for frontend assets
    const FRONTEND_BASE_URL = <?php echo json_encode(BASE_URL . '/frontend/'); ?>;
    const currentUserId = <?php echo json_encode($user_id); ?>;
    const currentUserFullName = <?php echo json_encode($user_full_name); ?>;
</script>
<script src="<?php echo BASE_URL; ?>/frontend/cards.js"></script>
</body>
</html>