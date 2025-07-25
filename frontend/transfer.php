<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.php

session_start();

ini_set('display_errors', 1); // Enable error display for debugging (turn off in production)
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- START DEBUGGING SNIPPET ---
echo "<pre>";
echo "<h2>Debugging File Paths in transfer.php</h2>";
echo "Current __DIR__: " . __DIR__ . "<br>";

$expectedConfigPath = __DIR__ . '/../Config.php';
echo "Expected Config.php path: " . $expectedConfigPath . "<br>";
echo "Config.php file_exists: " . (file_exists($expectedConfigPath) ? 'TRUE' : 'FALSE') . "<br>";
echo "Config.php is_readable: " . (is_readable($expectedConfigPath) ? 'TRUE' : 'FALSE') . "<br>";

$parentDir = dirname(__DIR__);
echo "Parent directory (for .env): " . $parentDir . "<br>";
echo ".env file_exists in parent: " . (file_exists($parentDir . '/.env') ? 'TRUE' : 'FALSE') . "<br>";

echo "<h3>Listing contents of /var/www/html/</h3>";
$htmlDir = '/var/www/html/';
if (is_dir($htmlDir)) {
    $files = scandir($htmlDir);
    echo implode("<br>", $files);
} else {
    echo "/var/www/html/ is not a directory or does not exist.<br>";
}

echo "<h3>Listing contents of /var/www/html/frontend/</h3>";
$frontendDir = '/var/www/html/frontend/';
if (is_dir($frontendDir)) {
    $files = scandir($frontendDir);
    echo implode("<br>", $files);
} else {
    echo "/var/www/html/frontend/ is not a directory or does not exist.<br>";
}

echo "</pre>";
// --- END DEBUGGING SNIPPET ---

// Ensure these two lines are now using __DIR__ . '/../'
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception as MongoDBException;


// Check login, etc.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

$user_id = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$user_email = $_SESSION['temp_user_email'] ?? $_SESSION['email'] ?? '';
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    $full_name = $_SESSION['username'] ?? 'User';
}

// Establish MongoDB connection
try {
    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $db->accounts;
} catch (MongoDBException $e) {
    error_log("MongoDB connection error in transfer.php: " . $e->getMessage());
    die("ERROR: Could not connect to MongoDB. Please try again later. Detail: " . $e->getMessage()); // Keep detail for now for debugging on Render
} catch (Exception $e) {
    error_log("General database connection error in transfer.php: " . $e->getMessage());
    die("ERROR: An unexpected error occurred during database connection. Please try again later. Detail: " . $e->getMessage());
}


$user_accounts = [];
try {
    // Ensure user_id from session is a string representation of ObjectId
    $userIdObjectId = new ObjectId($user_id);
    $cursor = $accountsCollection->find([
        'user_id' => $userIdObjectId,
        'status' => 'active'
    ]);

    foreach ($cursor as $account) {
        // Convert MongoDB\BSON\ObjectId to string for 'id'
        $account['id'] = (string)$account['_id'];
        $user_accounts[] = $account;
    }
} catch (MongoDBException $e) {
    error_log("MongoDB error fetching account data in transfer.php: " . $e->getMessage());
    // You might want to display a user-friendly message here too
} catch (Exception $e) {
    error_log("General error fetching account data in transfer.php: " . $e->getMessage());
}


// Retrieve messages and form data from session after redirect from make_transfer.php
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
$show_modal_on_load = $_SESSION['show_modal_on_load'] ?? false;
$transfer_success_details = $_SESSION['transfer_success_details'] ?? [];

// Clear session variables after retrieving them
unset($_SESSION['message']);
unset($_SESSION['message_type']);
unset($_SESSION['show_modal_on_load']);
unset($_SESSION['transfer_success_details']);

// Restore form data if there was an error
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// Determine the active transfer method for UI display
// Prioritize GET 'type', then form data, then default
$active_transfer_method = $_GET['type'] ?? ($form_data['transfer_method'] ?? 'internal_self');

// Map old GET 'type' values to new internal method names for consistency in the select dropdown
switch ($active_transfer_method) {
    case 'own_account':
        $active_transfer_method = 'internal_self';
        break;
    case 'bank_to_bank': // This was ambiguous, making it 'internal_heritage' (Hometown to Hometown)
        $active_transfer_method = 'internal_heritage';
        break;
    case 'international_bank':
        $active_transfer_method = 'external_iban';
        break;
    case 'uk_bank':
        $active_transfer_method = 'external_sort_code';
        break;
    case 'ach': // ACH and Wire/Domestic Wire map to USA account transfer
    case 'wire':
    case 'domestic_wire':
        $active_transfer_method = 'external_usa_account';
        break;
    default:
        // If it's not one of the recognized types or form data values, default to internal_self
        if (!in_array($active_transfer_method, ['internal_self', 'internal_heritage', 'external_iban', 'external_sort_code', 'external_usa_account'])) {
            $active_transfer_method = 'internal_self';
        }
        break;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank Pa - Transfer</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/transfer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Inline CSS for initial hidden states for JS to control */
        .external-fields {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="menu-icon" id="menuIcon">
                <i class="fas fa-bars"></i>
            </div>
            <div class="greeting">
                <h1>Make a Transfer</h1>
            </div>
            <div class="profile-pic">
                <img src="<?php echo BASE_URL; ?>/images/default-profile.png" alt="Profile Picture" id="headerProfilePic">
            </div>
        </header>

        <main class="main-content">
            <div class="transfer-form-container">
                <h2>Initiate New Transfer</h2>

                <?php if (!empty($message)): ?>
                    <p class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>

                <form action="<?php echo BASE_URL; ?>/make_transfer" method="POST" id="transferForm">
                    <input type="hidden" name="initiate_transfer" value="1">

                    <div class="form-group">
                        <label for="transfer_method">Select Transfer Method:</label>
                        <select id="transfer_method" name="transfer_method" class="form-control" required>
                            <option value="">-- Choose Transfer Type --</option>
                            <option value="internal_self" <?php echo ($active_transfer_method === 'internal_self' ? 'selected' : ''); ?>>Between My Accounts</option>
                            <option value="internal_heritage" <?php echo ($active_transfer_method === 'internal_heritage' ? 'selected' : ''); ?>>To Another HomeTown Bank Pa Account</option>
                            <option value="external_iban" <?php echo ($active_transfer_method === 'external_iban' ? 'selected' : ''); ?>>International Bank Transfer (IBAN/SWIFT)</option>
                            <option value="external_sort_code" <?php echo ($active_transfer_method === 'external_sort_code' ? 'selected' : ''); ?>>UK Bank Transfer (Sort Code/Account No)</option>
                            <option value="external_usa_account" <?php echo ($active_transfer_method === 'external_usa_account' ? 'selected' : ''); ?>>USA Bank Transfer (Routing/Account No)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="source_account_id">From Account:</label>
                        <select id="source_account_id" name="source_account_id" class="form-control" required>
                            <option value="">-- Select Your Account --</option>
                            <?php foreach ($user_accounts as $account): ?>
                                <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                    data-balance="<?php echo htmlspecialchars($account['balance']); ?>"
                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                    <?php echo ((string)($form_data['source_account_id'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo get_currency_symbol($account['currency'] ?? 'USD'); ?> <?php echo number_format((float)$account['balance'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p>Available Balance: <span id="amount_currency_symbol_for_balance"></span><span id="display_current_balance">N/A</span> <span id="current_currency_display"></span></p>
                    </div>

                    <div class="form-group external-fields common-external-fields">
                        <label for="recipient_name">Recipient Full Name:</label>
                        <input type="text" id="recipient_name" name="recipient_name" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_name'] ?? ''); ?>">
                    </div>

                    <div id="fields_internal_self" class="external-fields">
                        <div class="form-group">
                            <label for="destination_account_id_self">To My Account:</label>
                            <select id="destination_account_id_self" name="destination_account_id_self" class="form-control">
                                <option value="">-- Select Your Other Account --</option>
                                <?php foreach ($user_accounts as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                        <?php echo ((string)($form_data['destination_account_id_self'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo get_currency_symbol($account['currency'] ?? 'USD'); ?> <?php echo number_format((float)$account['balance'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="fields_internal_heritage" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_account_number_internal">Recipient HomeTown Bank Pa Account Number:</label>
                            <input type="text" id="recipient_account_number_internal" name="recipient_account_number_internal" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_account_number_internal'] ?? ''); ?>">
                        </div>
                    </div>

                    <div id="fields_external_iban" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_iban">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_iban" name="recipient_bank_name_iban" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_iban'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_iban">Recipient IBAN:</label>
                            <input type="text" id="recipient_iban" name="recipient_iban" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_iban'] ?? ''); ?>" placeholder="e.g., GBXX XXXX XXXX XXXX XXXX XXXX">
                        </div>
                        <div class="form-group">
                            <label for="recipient_swift_bic">Recipient SWIFT/BIC:</label>
                            <input type="text" id="recipient_swift_bic" name="recipient_swift_bic" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_swift_bic'] ?? ''); ?>" placeholder="e.g., BARCGB22">
                        </div>
                        <div class="form-group">
                            <label for="recipient_country">Recipient Country:</label>
                            <input type="text" id="recipient_country" name="recipient_country" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_country'] ?? ''); ?>" placeholder="e.g., United Kingdom">
                        </div>
                    </div>

                    <div id="fields_external_sort_code" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_sort">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_sort" name="recipient_bank_name_sort" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_sort'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_sort_code">Recipient Sort Code (6 digits):</label>
                            <input type="text" id="recipient_sort_code" name="recipient_sort_code" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_sort_code'] ?? ''); ?>" pattern="\d{6}" title="Sort Code must be 6 digits">
                        </div>
                        <div class="form-group">
                            <label for="recipient_external_account_number">Recipient Account Number (8 digits):</label>
                            <input type="text" id="recipient_external_account_number" name="recipient_external_account_number" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_external_account_number'] ?? ''); ?>" pattern="\d{8}" title="Account Number must be 8 digits">
                        </div>
                    </div>

                    <div id="fields_external_usa_account" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_usa">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_usa" name="recipient_bank_name_usa" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_usa_routing_number">Recipient Routing Number (9 digits):</label>
                            <input type="text" id="recipient_usa_routing_number" name="recipient_usa_routing_number" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_usa_routing_number'] ?? ''); ?>" pattern="\d{9}" title="Routing Number must be 9 digits">
                        </div>
                        <div class="form-group">
                            <label for="recipient_usa_account_number">Recipient Account Number:</label>
                            <input type="text" id="recipient_usa_account_number" name="recipient_usa_account_number" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_usa_account_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_account_type_usa">Recipient Account Type:</label>
                            <select id="recipient_account_type_usa" name="recipient_account_type_usa" class="form-control">
                                <option value="">Select Account Type</option>
                                <option value="Checking" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Checking' ? 'selected' : ''); ?>>Checking</option>
                                <option value="Savings" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Savings' ? 'selected' : ''); ?>>Savings</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="recipient_address_usa">Recipient Address:</label>
                            <input type="text" id="recipient_address_usa" name="recipient_address_usa" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_address_usa'] ?? ''); ?>" placeholder="Street Address">
                        </div>
                        <div class="form-group">
                            <label for="recipient_city_usa">Recipient City:</label>
                            <input type="text" id="recipient_city_usa" name="recipient_city_usa" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_city_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_state_usa">Recipient State:</label>
                            <input type="text" id="recipient_state_usa" name="recipient_state_usa" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_state_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_zip_usa">Recipient Zip Code:</label>
                            <input type="text" id="recipient_zip_usa" name="recipient_zip_usa" class="form-control" value="<?php echo htmlspecialchars($form_data['recipient_zip_usa'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount:</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" class="form-control" value="<?php echo htmlspecialchars($form_data['amount'] ?? ''); ?>" required>
                        <span class="currency-symbol" id="amount_currency_symbol"></span>
                    </div>
                    <div class="form-group">
                        <label for="description">Description (Optional):</label>
                        <textarea id="description" name="description" rows="3" class="form-control"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="button-primary">Initiate Transfer</button>
                </form>
            </div>
            <p style="text-align: center; margin-top: 20px;"><a href="<?php echo BASE_URL; ?>/dashboard" class="back-link">&larr; Back to Dashboard</a></p>
        </main>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar-button" id="closeSidebarBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-profile">
                <img src="<?php echo BASE_URL; ?>/images/default-profile.png" alt="Profile Picture" class="sidebar-profile-pic">
                <h3><span id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></span></h3>
                <p><span id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></span></p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="<?php echo BASE_URL; ?>/dashboard"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>/accounts"><i class="fas fa-wallet"></i> Accounts</a></li>
                <li><a href="<?php echo BASE_URL; ?>/transfer" class="active"><i class="fas fa-exchange-alt"></i> Transfers</a></li>
                <li><a href="<?php echo BASE_URL; ?>/statements"><i class="fas fa-file-invoice"></i> Statements</a></li>
                <li><a href="<?php echo BASE_URL; ?>/profile"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="<?php echo BASE_URL; ?>/settings"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="<?php echo BASE_URL; ?>/bank_cards"><i class="fas fa-credit-card"></i> Bank Cards</a></li>
            </ul>
        </nav>
        <button class="logout-button" id="logoutButton" onclick="window.location.href='<?php echo BASE_URL; ?>/logout'">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>

    <div class="modal-overlay" id="transferSuccessModal">
        <div class="modal-content">
            <h3>Transfer Initiated!</h3>
            <p>Your transfer request has been successfully submitted and is awaiting approval.</p>
            <p>Amount: <strong><span id="modalAmount"></span> <span id="modalCurrency"></span></strong></p>
            <p>To: <strong><span id="modalRecipient"></span></strong></p>
            <p>Status: <strong class="status-pending" id="modalStatus"></strong></p>
            <p>Reference: <span class="modal-reference" id="modalReference"></span></p>
            <p>Method: <span id="modalMethod"></span></p>
            <button class="modal-button" id="modalCloseButton">Got It!</button>
        </div>
    </div>


    <script>
        window.APP_DATA = {
            userAccountsData: <?php echo json_encode($user_accounts); ?>,
            initialSelectedFromAccount: '<?php echo htmlspecialchars($form_data['source_account_id'] ?? ''); ?>',
            initialTransferMethod: '<?php
                $js_transfer_method = $active_transfer_method;
                echo htmlspecialchars($js_transfer_method);
            ?>',
            showModal: <?php echo $show_modal_on_load ? 'true' : 'false'; ?>,
            modalDetails: <?php echo json_encode($transfer_success_details); ?>
        };
    </script>
    <script src="<?php echo BASE_URL; ?>/frontend/transfer.js"></script>
</body>
</html>