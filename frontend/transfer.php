<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.php

// 1. Session and Configuration
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // get_currency_symbol, sanitize_input etc.

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception as MongoDBException;

// Enable error display based on APP_DEBUG
if (defined('APP_DEBUG') && APP_DEBUG === true) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// 2. Authorization Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    $_SESSION['message'] = "You must be logged in to view the transfer page.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_accounts = []; // Array to hold user's accounts for dropdowns
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']); // Clear form data after retrieval

$show_modal = $_SESSION['show_modal_on_load'] ?? false;
$modal_details = $_SESSION['transfer_success_details'] ?? [];
$user_show_transfer_modal = true; // Default to true, will fetch from DB

// Clear transfer session data after retrieval
unset($_SESSION['show_modal_on_load']);
unset($_SESSION['transfer_success_details']);

// 3. Database Connection and Data Fetching
try {
    if (!defined('MONGODB_CONNECTION_URI') || empty(MONGODB_CONNECTION_URI)) {
        throw new Exception("MONGODB_CONNECTION_URI is not defined or empty.");
    }
    if (!defined('MONGODB_DB_NAME') || empty(MONGODB_DB_NAME)) {
        throw new Exception("MONGODB_DB_NAME is not defined or empty.");
    }

    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $db->accounts;
    $usersCollection = $db->users;
    
    // Fetch user's accounts
    $accountsCursor = $accountsCollection->find([
        'user_id' => new ObjectId($user_id),
        'status' => 'active'
    ], [
        'projection' => ['account_number' => 1, 'account_type' => 1, 'balance' => 1, 'currency' => 1]
    ]);
    
    foreach ($accountsCursor as $account) {
        $user_accounts[] = [
            '_id' => (string)$account['_id'],
            'number_display' => $account['account_type'] . ' (****' . substr($account['account_number'], -4) . ')',
            'balance_display' => get_currency_symbol($account['currency']) . ' ' . number_format($account['balance'], 2),
            'currency' => $account['currency']
        ];
    }
    
    // Fetch user's modal status (for informational purposes)
    $user = $usersCollection->findOne(['_id' => new ObjectId($user_id)], ['projection' => ['show_transfer_modal' => 1]]);
    if ($user !== null && isset($user['show_transfer_modal'])) {
        $user_show_transfer_modal = $user['show_transfer_modal'];
    }


} catch (MongoDBException $e) {
    error_log("transfer.php: MongoDB connection or fetch error: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: Could not load account data. Please try again later.";
    $_SESSION['message_type'] = "error";
    // Do not redirect, just display error on the page
} catch (Exception $e) {
    error_log("transfer.php: General error: " . $e->getMessage());
    $_SESSION['message'] = "ERROR: An unexpected error occurred.";
    $_SESSION['message_type'] = "error";
}

// 4. Message Handling
$message = $_SESSION['message'] ?? null;
$message_type = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message']);
unset($_SESSION['message_type']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make a Transfer - HomeTown Bank Pa</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .transfer-container { max-width: 900px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
        .transfer-method-fields { display: none; margin-top: 15px; padding: 15px; border: 1px dashed #ccc; border-radius: 5px; }
        .form-row > .col, .form-row > [class*="col-"] { margin-bottom: 10px; }
        .form-control[type="number"]::-webkit-outer-spin-button, 
        .form-control[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .form-control[type="number"] { -moz-appearance: textfield; }
    </style>
</head>
<body>

    <div class="container">
        <div class="transfer-container">
            <h2 class="mb-4">Initiate a New Transfer 💸</h2>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($user_show_transfer_modal === true): ?>
                <div class="alert alert-info" role="alert">
                    <button type="button" class="btn btn-sm btn-primary float-right" data-toggle="modal" data-target="#adminNoticeModal">
                        Read Important Notice
                    </button>
                    <strong>Important:</strong> Please read the transfer notice before initiating your first transfer.
                </div>
            <?php endif; ?>

            <form action="<?php echo BASE_URL; ?>/frontend/make_transfer.php" method="POST">
                <input type="hidden" name="initiate_transfer" value="1">
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="source_account_id">Source Account</label>
                        <select id="source_account_id" name="source_account_id" class="form-control" required>
                            <option value="">Select Account</option>
                            <?php foreach ($user_accounts as $account): ?>
                                <option 
                                    value="<?php echo htmlspecialchars($account['_id']); ?>" 
                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                    <?php echo (isset($form_data['source_account_id']) && $form_data['source_account_id'] === $account['_id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($account['number_display']); ?> (Bal: <?php echo htmlspecialchars($account['balance_display']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="amount">Amount</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text currency-symbol">$</span>
                            </div>
                            <input type="number" step="0.01" min="0.01" id="amount" name="amount" class="form-control" placeholder="0.00" required
                                value="<?php echo htmlspecialchars($form_data['amount'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group col-md-3">
                        <label for="transfer_pin">Transfer PIN (4 Digits)</label>
                        <input type="text" pattern="\d{4}" maxlength="4" id="transfer_pin" name="transfer_pin" class="form-control" placeholder="****" required>
                        <small class="form-text text-muted">A 4-digit PIN is required.</small>
                    </div>
                </div>

                <hr>

                <div class="form-group">
                    <label for="transfer_method">Transfer Method</label>
                    <select id="transfer_method" name="transfer_method" class="form-control" required>
                        <option value="">Select Transfer Type</option>
                        <option value="internal_self" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'internal_self') ? 'selected' : ''; ?>>Between My Accounts</option>
                        <option value="internal_heritage" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'internal_heritage') ? 'selected' : ''; ?>>To HomeTown Bank Pa Account</option>
                        <option value="external_usa_account" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'external_usa_account') ? 'selected' : ''; ?>>To USA Bank Account (ACH/Wire)</option>
                        <option value="external_sort_code" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'external_sort_code') ? 'selected' : ''; ?>>To UK Bank Account (Sort Code)</option>
                        <option value="external_canada_eft" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'external_canada_eft') ? 'selected' : ''; ?>>To Canada Bank Account (EFT)</option>
                        <option value="external_iban" <?php echo (isset($form_data['transfer_method']) && $form_data['transfer_method'] === 'external_iban') ? 'selected' : ''; ?>>International/IBAN Transfer</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="recipient_name">Recipient Name</label>
                        <input type="text" id="recipient_name" name="recipient_name" class="form-control" placeholder="Name on Recipient Account" 
                            value="<?php echo htmlspecialchars($form_data['recipient_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="description">Description/Memo (Optional)</label>
                        <input type="text" id="description" name="description" class="form-control" placeholder="e.g., Monthly Rent" 
                            value="<?php echo htmlspecialchars($form_data['description'] ?? ''); ?>">
                    </div>
                </div>

                <div id="internal_self_fields" class="transfer-method-fields">
                    <h5 class="mb-3">Transfer to Your Other Account</h5>
                    <div class="form-group">
                        <label for="destination_account_id_self">Destination Account</label>
                        <select id="destination_account_id_self" name="destination_account_id_self" class="form-control">
                            <option value="">Select Destination Account</option>
                            <?php foreach ($user_accounts as $account): ?>
                                <option 
                                    value="<?php echo htmlspecialchars($account['_id']); ?>"
                                    <?php echo (isset($form_data['destination_account_id_self']) && $form_data['destination_account_id_self'] === $account['_id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($account['number_display']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="internal_heritage_fields" class="transfer-method-fields">
                    <h5 class="mb-3">To Another HomeTown Bank Pa Customer</h5>
                    <div class="form-group">
                        <label for="recipient_account_number_internal">Recipient HomeTown Bank Pa Account Number</label>
                        <input type="text" id="recipient_account_number_internal" name="recipient_account_number_internal" class="form-control" placeholder="Account Number" 
                            value="<?php echo htmlspecialchars($form_data['recipient_account_number_internal'] ?? ''); ?>">
                    </div>
                </div>
                
                <div id="external_usa_account_fields" class="transfer-method-fields">
                    <h5 class="mb-3">USA Bank Details (ACH/Wire)</h5>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="recipient_bank_name_usa">Recipient Bank Name</label>
                            <input type="text" id="recipient_bank_name_usa" name="recipient_bank_name_usa" class="form-control" placeholder="Bank Name"
                                value="<?php echo htmlspecialchars($form_data['recipient_bank_name_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="recipient_account_type_usa">Recipient Account Type</label>
                            <select id="recipient_account_type_usa" name="recipient_account_type_usa" class="form-control">
                                <option value="">Select Type</option>
                                <option value="Checking" <?php echo (isset($form_data['recipient_account_type_usa']) && $form_data['recipient_account_type_usa'] === 'Checking') ? 'selected' : ''; ?>>Checking</option>
                                <option value="Savings" <?php echo (isset($form_data['recipient_account_type_usa']) && $form_data['recipient_account_type_usa'] === 'Savings') ? 'selected' : ''; ?>>Savings</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="recipient_usa_routing_number">USA Routing Number (9 Digits)</label>
                            <input type="text" pattern="\d{9}" maxlength="9" id="recipient_usa_routing_number" name="recipient_usa_routing_number" class="form-control" placeholder="e.g., 021000021"
                                value="<?php echo htmlspecialchars($form_data['recipient_usa_routing_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="recipient_usa_account_number">USA Account Number</label>
                            <input type="text" id="recipient_usa_account_number" name="recipient_usa_account_number" class="form-control" placeholder="Account Number"
                                value="<?php echo htmlspecialchars($form_data['recipient_usa_account_number'] ?? ''); ?>">
                        </div>
                    </div>
                    <p class="mt-2 mb-0 font-weight-bold">Recipient Address (Required for Wire Transfers):</p>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="recipient_address_usa">Street Address</label>
                            <input type="text" id="recipient_address_usa" name="recipient_address_usa" class="form-control" placeholder="123 Main St"
                                value="<?php echo htmlspecialchars($form_data['recipient_address_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="recipient_city_usa">City</label>
                            <input type="text" id="recipient_city_usa" name="recipient_city_usa" class="form-control" placeholder="Anytown"
                                value="<?php echo htmlspecialchars($form_data['recipient_city_usa'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="recipient_state_usa">State/ZIP</label>
                            <div class="input-group">
                                <input type="text" id="recipient_state_usa" name="recipient_state_usa" class="form-control" placeholder="NY" style="max-width: 60px;"
                                    value="<?php echo htmlspecialchars($form_data['recipient_state_usa'] ?? ''); ?>">
                                <input type="text" id="recipient_zip_usa" name="recipient_zip_usa" class="form-control" placeholder="10001"
                                    value="<?php echo htmlspecialchars($form_data['recipient_zip_usa'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="external_sort_code_fields" class="transfer-method-fields">
                    <h5 class="mb-3">UK Bank Details (Sort Code)</h5>
                    <div class="form-group">
                        <label for="recipient_bank_name_sort">Recipient Bank Name</label>
                        <input type="text" id="recipient_bank_name_sort" name="recipient_bank_name_sort" class="form-control" placeholder="Bank Name"
                            value="<?php echo htmlspecialchars($form_data['recipient_bank_name_sort'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="recipient_sort_code">UK Sort Code (6 Digits)</label>
                            <input type="text" pattern="\d{6}" maxlength="6" id="recipient_sort_code" name="recipient_sort_code" class="form-control" placeholder="e.g., 108000"
                                value="<?php echo htmlspecialchars($form_data['recipient_sort_code'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="recipient_external_account_number">UK Account Number (8 Digits)</label>
                            <input type="text" pattern="\d{8}" maxlength="8" id="recipient_external_account_number" name="recipient_external_account_number" class="form-control" placeholder="Account Number"
                                value="<?php echo htmlspecialchars($form_data['recipient_external_account_number'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div id="external_canada_eft_fields" class="transfer-method-fields">
                    <h5 class="mb-3">Canadian EFT Details</h5>
                    <div class="form-group">
                        <label for="recipient_bank_name_canada">Recipient Bank Name</label>
                        <input type="text" id="recipient_bank_name_canada" name="recipient_bank_name_canada" class="form-control" placeholder="Bank Name"
                            value="<?php echo htmlspecialchars($form_data['recipient_bank_name_canada'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="recipient_institution_number_canada">Institution No (3 Digits)</label>
                            <input type="text" pattern="\d{3}" maxlength="3" id="recipient_institution_number_canada" name="recipient_institution_number_canada" class="form-control" placeholder="e.g., 004"
                                value="<?php echo htmlspecialchars($form_data['recipient_institution_number_canada'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="recipient_transit_number_canada">Transit No (5 Digits)</label>
                            <input type="text" pattern="\d{5}" maxlength="5" id="recipient_transit_number_canada" name="recipient_transit_number_canada" class="form-control" placeholder="e.g., 12345"
                                value="<?php echo htmlspecialchars($form_data['recipient_transit_number_canada'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="recipient_external_account_number_canada">Account Number</label>
                            <input type="text" pattern="\d{7,12}" maxlength="12" id="recipient_external_account_number_canada" name="recipient_external_account_number_canada" class="form-control" placeholder="7-12 Digits"
                                value="<?php echo htmlspecialchars($form_data['recipient_external_account_number_canada'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div id="external_iban_fields" class="transfer-method-fields">
                    <h5 class="mb-3">International Bank Details (IBAN/SWIFT)</h5>
                    <div class="form-group">
                        <label for="recipient_bank_name_iban">Recipient Bank Name</label>
                        <input type="text" id="recipient_bank_name_iban" name="recipient_bank_name_iban" class="form-control" placeholder="Bank Name"
                            value="<?php echo htmlspecialchars($form_data['recipient_bank_name_iban'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="recipient_iban">IBAN (International Bank Account Number)</label>
                            <input type="text" id="recipient_iban" name="recipient_iban" class="form-control" placeholder="e.g., DE89370400440532492000"
                                value="<?php echo htmlspecialchars($form_data['recipient_iban'] ?? ''); ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="recipient_swift_bic">SWIFT/BIC Code</label>
                            <input type="text" id="recipient_swift_bic" name="recipient_swift_bic" class="form-control" placeholder="e.g., COBADEFFXXX"
                                value="<?php echo htmlspecialchars($form_data['recipient_swift_bic'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="recipient_country">Recipient Bank Country</label>
                        <input type="text" id="recipient_country" name="recipient_country" class="form-control" placeholder="e.g., Germany"
                            value="<?php echo htmlspecialchars($form_data['recipient_country'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group mt-4">
                    <button type="submit" class="btn btn-lg btn-success btn-block">Complete Transfer Request</button>
                </div>
            </form>

        </div>
    </div>

    <div class="modal fade" id="adminNoticeModal" tabindex="-1" aria-labelledby="adminNoticeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="adminNoticeModalLabel">Important Transfer Notice ⚠️</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="lead">Thank you for choosing HomeTown Bank Pa for your transfer needs.</p>
                    <p>Please be aware that <strong>all initiated transfers are subject to a mandatory administrative review and approval process</strong>.</p>
                    <ul>
                        <li>Your account balance will be debited immediately.</li>
                        <li>The transfer status will be set to **Pending Approval**.</li>
                        <li>Transfers will only be processed by the bank once approved by an administrator.</li>
                        <li>You will receive an email confirmation when the transfer is approved and processed.</li>
                    </ul>
                    <p class="font-weight-bold text-danger">Do not close your browser after submitting. You will be redirected to the transfer history page after approval.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_modal): ?>
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="successModalLabel">Transfer Request Submitted! 🎉</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="lead">Your transfer request has been successfully submitted and is **awaiting approval**.</p>
                    <hr>
                    <h6 class="font-weight-bold">Summary:</h6>
                    <ul class="list-unstyled">
                        <li><strong>Amount:</strong> <span class="text-success"><?php echo htmlspecialchars($modal_details['currency'] . ' ' . $modal_details['amount']); ?></span></li>
                        <li><strong>Recipient:</strong> <?php echo htmlspecialchars($modal_details['recipient']); ?></li>
                        <li><strong>Method:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $modal_details['transfer_method']))); ?></li>
                        <li><strong>Reference:</strong> <?php echo htmlspecialchars($modal_details['reference_number']); ?></li>
                        <li><strong>Status:</strong> <span class="badge badge-warning"><?php echo htmlspecialchars($modal_details['status']); ?></span></li>
                    </ul>
                    <p class="mt-3 text-info">You will receive an email notification when the status changes.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <a href="#" class="btn btn-primary">Go to Dashboard</a> 
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Function to show/hide relevant fields based on transfer method
            function updateTransferFields() {
                var method = $('#transfer_method').val();
                // Hide all fields first
                $('.transfer-method-fields').slideUp(200);

                // Show the relevant field block
                if (method) {
                    $('#' + method + '_fields').slideDown(200);
                }
            }
            
            // Function to update the currency symbol
            function updateCurrencySymbol() {
                var selectedOption = $('#source_account_id option:selected');
                var currencyCode = selectedOption.data('currency');
                var symbol = '$'; // Default
                
                // Simplified currency symbol lookup for the frontend
                if (currencyCode === 'USD') symbol = '$';
                else if (currencyCode === 'GBP') symbol = '£';
                else if (currencyCode === 'EUR') symbol = '€';
                else if (currencyCode === 'CAD') symbol = 'C$'; // Canadian Dollar
                
                $('.currency-symbol').text(symbol);
            }

            // Event handlers
            $('#transfer_method').on('change', updateTransferFields);
            $('#source_account_id').on('change', updateCurrencySymbol);

            // Initial calls on load
            updateCurrencySymbol(); // Set initial currency symbol
            updateTransferFields(); // Show fields if form was repopulated from error

            // Show success modal if necessary
            <?php if ($show_modal): ?>
                $('#successModal').modal('show');
            <?php endif; ?>
            
            // Show admin notice modal if necessary on first load
            <?php if ($user_show_transfer_modal === true): ?>
                // Delay slightly to ensure main content is loaded
                setTimeout(function() {
                    $('#adminNoticeModal').modal('show');
                }, 500);
            <?php endif; ?>

        });
    </script>
</body>
</html>