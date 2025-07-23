<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.php

session_start();
require_once '../Config.php';
require_once '../functions.php'; // Ensure this has sanitize_input, bcmath functions, and get_currency_symbol
require_once __DIR__ . '/../vendor/autoload.php'; // Assuming you installed MongoDB driver via Composer

use MongoDB\Client; // Add this line based on your config.php
use MongoDB\Exception\Exception as MongoDBException; // Add this line based on your config.php


// Check login, etc.
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ../indx.php'); // Redirect to login page if not logged in
    exit;
}

$user_id = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$user_email = $_SESSION['temp_user_email'] ?? $_SESSION['email'] ?? ''; // Added fallback for user_email
$full_name = trim($first_name . ' ' . $last_name);
if (empty($full_name)) {
    // If first_name and last_name are empty, try username (if it exists in session)
    $full_name = $_SESSION['username'] ?? 'User';
}

// Establish MongoDB connection
try {
    // Use MONGODB_CONNECTION_URI and MONGODB_DB_NAME from Config.php
    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME); // Use MONGODB_DB_NAME
    $accountsCollection = $db->accounts;
} catch (MongoDBException $e) { // Catch specific MongoDB exceptions
    die("ERROR: Could not connect to MongoDB. " . $e->getMessage());
} catch (Exception $e) { // Catch any other general exceptions
    die("ERROR: An unexpected error occurred during database connection. " . $e->getMessage());
}


$user_accounts = [];
try {
    // MongoDB stores _id as ObjectId, so convert user_id to ObjectId for query
    $userIdObjectId = new MongoDB\BSON\ObjectId($user_id);
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
    error_log("MongoDB error fetching account data: " . $e->getMessage());
    // You might want to display a user-friendly message here too
} catch (Exception $e) {
    error_log("General error fetching account data: " . $e->getMessage());
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
$active_transfer_method = $form_data['transfer_method'] ?? 'internal_self'; // Default to 'internal_self' for initial load
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank Pa - Transfer</title>
    <link rel="stylesheet" href="transfer.css">
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
                <img src="/heritagebank/images/default-profile.png" alt="Profile Picture" id="headerProfilePic">
            </div>
        </header>

        <main class="main-content">
            <div class="transfer-form-container">
                <h2>Initiate New Transfer</h2>

                <?php if (!empty($message)): ?>
                    <p class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>

                <form action="make_transfer.php" method="POST" id="transferForm">
                    <input type="hidden" name="initiate_transfer" value="1">

                    <div class="form-group">
                        <label for="transfer_method">Select Transfer Method:</label>
                        <select id="transfer_method" name="transfer_method" required>
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
                        <select id="source_account_id" name="source_account_id" required>
                            <option value="">-- Select Your Account --</option>
                            <?php foreach ($user_accounts as $account): ?>
                                <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                    data-balance="<?php echo htmlspecialchars($account['balance']); ?>"
                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                    <?php echo ((string)($form_data['source_account_id'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo htmlspecialchars($account['currency']); ?> <?php echo number_format($account['balance'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p>Available Balance: <span id="amount_currency_symbol_for_balance"></span><span id="display_current_balance">N/A</span> <span id="current_currency_display"></span></p>
                    </div>

                    <div class="form-group external-fields common-external-fields">
                        <label for="recipient_name">Recipient Full Name:</label>
                        <input type="text" id="recipient_name" name="recipient_name" value="<?php echo htmlspecialchars($form_data['recipient_name'] ?? ''); ?>">
                    </div>

                    <div id="fields_internal_self" class="external-fields">
                        <div class="form-group">
                            <label for="destination_account_id_self">To My Account:</label>
                            <select id="destination_account_id_self" name="destination_account_id_self">
                                <option value="">-- Select Your Other Account --</option>
                                <?php foreach ($user_accounts as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                        <?php echo ((string)($form_data['destination_account_id_self'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo htmlspecialchars($account['currency']); ?> <?php echo number_format($account['balance'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="fields_internal_heritage" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_account_number_internal">Recipient HomeTown Bank Pa Account Number:</label>
                            <input type="text" id="recipient_account_number_internal" name="recipient_account_number_internal" value="<?php echo htmlspecialchars($form_data['recipient_account_number_internal'] ?? ''); ?>">
                        </div>
                    </div>

                    <div id="fields_external_iban" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_iban">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_iban" name="recipient_bank_name_iban" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_iban'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_iban">Recipient IBAN:</label>
                            <input type="text" id="recipient_iban" name="recipient_iban" value="<?php echo htmlspecialchars($form_data['recipient_iban'] ?? ''); ?>" placeholder="e.g., GBXX XXXX XXXX XXXX XXXX XXXX">
                        </div>
                        <div class="form-group">
                            <label for="recipient_swift_bic">Recipient SWIFT/BIC:</label>
                            <input type="text" id="recipient_swift_bic" name="recipient_swift_bic" value="<?php echo htmlspecialchars($form_data['recipient_swift_bic'] ?? ''); ?>" placeholder="e.g., BARCGB22">
                        </div>
                        <div class="form-group">
                            <label for="recipient_country">Recipient Country:</label>
                            <input type="text" id="recipient_country" name="recipient_country" value="<?php echo htmlspecialchars($form_data['recipient_country'] ?? ''); ?>" placeholder="e.g., United Kingdom">
                        </div>
                    </div>

                    <div id="fields_external_sort_code" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_sort">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_sort" name="recipient_bank_name_sort" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_sort'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_sort_code">Recipient Sort Code (6 digits):</label>
                            <input type="text" id="recipient_sort_code" name="recipient_sort_code" value="<?php echo htmlspecialchars($form_data['recipient_sort_code'] ?? ''); ?>" pattern="\d{6}" title="Sort Code must be 6 digits">
                        </div>
                        <div class="form-group">
                            <label for="recipient_external_account_number">Recipient Account Number (8 digits):</label>
                            <input type="text" id="recipient_external_account_number" name="recipient_external_account_number" value="<?php echo htmlspecialchars($form_data['recipient_external_account_number'] ?? ''); ?>" pattern="\d{8}" title="Account Number must be 8 digits">
                        </div>
                    </div>

                    <div id="fields_external_usa_account" class="external-fields">
                        <div class="form-group">
                            <label for="recipient_bank_name_usa">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_usa" name="recipient_bank_name_usa" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_usa_routing_number">Recipient Routing Number (9 digits):</label>
                            <input type="text" id="recipient_usa_routing_number" name="recipient_usa_routing_number" value="<?php echo htmlspecialchars($form_data['recipient_usa_routing_number'] ?? ''); ?>" pattern="\d{9}" title="Routing Number must be 9 digits">
                        </div>
                        <div class="form-group">
                            <label for="recipient_usa_account_number">Recipient Account Number:</label>
                            <input type="text" id="recipient_usa_account_number" name="recipient_usa_account_number" value="<?php echo htmlspecialchars($form_data['recipient_usa_account_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_account_type_usa">Recipient Account Type:</label>
                            <select id="recipient_account_type_usa" name="recipient_account_type_usa">
                                <option value="">Select Account Type</option>
                                <option value="Checking" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Checking' ? 'selected' : ''); ?>>Checking</option>
                                <option value="Savings" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Savings' ? 'selected' : ''); ?>>Savings</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="recipient_address_usa">Recipient Address:</label>
                            <input type="text" id="recipient_address_usa" name="recipient_address_usa" value="<?php echo htmlspecialchars($form_data['recipient_address_usa'] ?? ''); ?>" placeholder="Street Address">
                        </div>
                        <div class="form-group">
                            <label for="recipient_city_usa">Recipient City:</label>
                            <input type="text" id="recipient_city_usa" name="recipient_city_usa" value="<?php echo htmlspecialchars($form_data['recipient_city_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_state_usa">Recipient State:</label>
                            <input type="text" id="recipient_state_usa" name="recipient_state_usa" value="<?php echo htmlspecialchars($form_data['recipient_state_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="recipient_zip_usa">Recipient Zip Code:</label>
                            <input type="text" id="recipient_zip_usa" name="recipient_zip_usa" value="<?php echo htmlspecialchars($form_data['recipient_zip_usa'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount:</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" value="<?php echo htmlspecialchars($form_data['amount'] ?? ''); ?>" required>
                        <span class="currency-symbol" id="amount_currency_symbol"></span>
                    </div>
                    <div class="form-group">
                        <label for="description">Description (Optional):</label>
                        <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="button-primary">Initiate Transfer</button>
                </form>
            </div>
            <p style="text-align: center; margin-top: 20px;"><a href="dashboard.php" class="back-link">&larr; Back to Dashboard</a></p>
        </main>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar-button" id="closeSidebarBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-profile">
                <img src="/heritagebank/images/default-profile.png" alt="Profile Picture" class="sidebar-profile-pic">
                <h3><span id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></span></h3>
                <p><span id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></span></p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="accounts.php"><i class="fas fa-wallet"></i> Accounts</a></li>
                <li><a href="transfer.php" class="active"><i class="fas fa-exchange-alt"></i> Transfers</a></li>
                <li><a href="statements.php"><i class="fas fa-file-invoice"></i> Statements</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="bank_cards.php"><i class="fas fa-credit-card"></i> Bank Cards</a></li>
            </ul>
        </nav>
        <button class="logout-button" id="logoutButton" onclick="window.location.href='../logout.php'">
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
            initialTransferMethod: '<?php echo htmlspecialchars($active_transfer_method); ?>',
            showModal: <?php echo $show_modal_on_load ? 'true' : 'false'; ?>,
            modalDetails: <?php echo json_encode($transfer_success_details); ?>
        };
    </script>
    <script src="transfer.js"></script>
</body>
</html>