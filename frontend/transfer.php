<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.php

// Ensure session is started FIRST.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Load Config.php first. It handles Composer's autoload.php and defines global constants like BASE_URL.
// __DIR__ . '/../' points from 'frontend/' up to the project root.
require_once __DIR__ . '/../Config.php';

// Then load functions.php, which might depend on constants or autoloader from Config.php.
require_once __DIR__ . '/../functions.php';

// Now, Composer classes are available because Config.php loaded autoload.php.
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Exception\Exception as MongoDBException;


// Check login, etc.
// *** CORRECTION: Use $_SESSION['logged_in'] for consistency with dashboard.php ***
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    // Use defined BASE_URL constant for redirection
    header('Location: ' . BASE_URL . '/login.php'); // Changed /login to /login.php for direct access
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
    // Check if constants are defined before using them
    // These checks are good, but if Config.php is working correctly, they should always be true.
    if (!defined('MONGODB_CONNECTION_URI') || empty(MONGODB_CONNECTION_URI)) {
        throw new Exception("MONGODB_CONNECTION_URI is not defined or empty.");
    }
    if (!defined('MONGODB_DB_NAME') || empty(MONGODB_DB_NAME)) {
        throw new Exception("MONGODB_DB_NAME is not defined or empty.");
    }

    $client = new Client(MONGODB_CONNECTION_URI);
    $db = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $db->accounts;
    $usersCollection = $db->users; // Get the users collection
    // *** NEW: Assuming a 'saved_payees' collection or similar exists for external accounts ***
    $savedPayeesCollection = $db->saved_payees ?? null; // Adjust collection name as needed

} catch (MongoDBException $e) {
    error_log("MongoDB connection error in transfer.php: " . $e->getMessage());
    die("ERROR: Could not connect to MongoDB. Please try again later. Detail: " . $e->getMessage());
} catch (Exception $e) {
    error_log("General database connection error in transfer.php: " . $e->getMessage());
    die("ERROR: An unexpected error occurred during database connection. Please try again later. Detail: " . $e->getMessage());
}

// --- NEW LOGIC FOR ADMIN MODAL MESSAGE (INTEGRATED INTO STANDARD MESSAGE) ---
$admin_transfer_message = ''; // Initialize
if (!empty($user_id)) {
    try {
        $currentUser = $usersCollection->findOne(['_id' => new ObjectId($user_id)]);
        if ($currentUser) {
            // If show_transfer_modal is true and there's a message, capture it
            if (($currentUser['show_transfer_modal'] ?? false) && !empty($currentUser['transfer_modal_message'] ?? '')) {
                $admin_transfer_message = $currentUser['transfer_modal_message'];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching user modal message in transfer.php: " . $e->getMessage());
    }
}
// --- END NEW LOGIC ---

$user_accounts = [];
$user_saved_canada_accounts = []; // Initialize for new Canadian feature
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

    // *** NEW LOGIC: Fetch Saved Canadian Payees (This assumes a structure in saved_payees collection) ***
    if ($savedPayeesCollection) {
        $canada_payees_cursor = $savedPayeesCollection->find([
            'user_id' => $userIdObjectId,
            'transfer_method' => 'external_canada_eft', // Filter for Canadian accounts
            'status' => 'active' // Assuming a status field
        ]);

        foreach ($canada_payees_cursor as $payee) {
            $payee['id'] = (string)$payee['_id'];
            $user_saved_canada_accounts[] = $payee;
        }
    }
    // *** END NEW LOGIC ***

} catch (MongoDBException $e) {
    error_log("MongoDB error fetching account/payee data in transfer.php: " . $e->getMessage());
    // You might want to display a user-friendly message here too
} catch (Exception $e) {
    error_log("General error fetching account/payee data in transfer.php: " . $e->getMessage());
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
    case 'canada_bank':
        // *** MODIFIED: Renamed to match the make_transfer.php backend logic 'external_canada_eft' ***
        $active_transfer_method = 'external_canada_eft';
        break;
    default:
        // If it's not one of the recognized types or form data values, default to internal_self
        if (!in_array($active_transfer_method, ['internal_self', 'internal_heritage', 'external_iban', 'external_sort_code', 'external_usa_account', 'external_canada_eft'])) {
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
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 font-sans">
    <div class="min-h-screen flex flex-col md:flex-row">
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden" onclick="toggleSidebar()"></div>
        <div id="sidebar" class="fixed top-0 left-0 w-64 h-full bg-white shadow-lg transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out z-50 p-6 md:p-0">
            <div class="md:hidden p-4 flex justify-end">
                <button class="text-gray-600" id="closeSidebarBtn" onclick="toggleSidebar()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sidebar-header p-4 border-b">
                <div class="sidebar-profile flex flex-col items-center">
                    <img src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/images/default-profile.png" alt="Profile Picture" class="w-16 h-16 rounded-full mb-2">
                    <h3 class="text-lg font-semibold text-gray-800" id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></h3>
                    <p class="text-sm text-gray-500" id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></p>
                </div>
            </div>
            <nav class="sidebar-nav p-4">
                <ul class="space-y-2">
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/dashboard.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-home w-5 mr-3"></i> Dashboard</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/accounts.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-wallet w-5 mr-3"></i> Accounts</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/transfer.php" class="flex items-center p-2 text-white bg-blue-600 rounded-lg"><i class="fas fa-exchange-alt w-5 mr-3"></i> Transfers</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/statements.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-file-invoice w-5 mr-3"></i> Statements</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/profile.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-user w-5 mr-3"></i> Profile</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/settings.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-cog w-5 mr-3"></i> Settings</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/my_cards.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg"><i class="fas fa-credit-card w-5 mr-3"></i> Bank Cards</a></li>
                </ul>
            </nav>
            <button class="logout-button w-full flex items-center justify-center p-3 mt-4 bg-red-600 text-white hover:bg-red-700 transition duration-150" id="logoutButton" onclick="window.location.href='<?php echo rtrim(BASE_URL, '/'); ?>/logout.php'">
                <i class="fas fa-sign-out-alt mr-2"></i> Logout
            </button>
        </div>
        
        <main class="flex-1 p-4 md:ml-64 transition-all duration-300 ease-in-out">
            <header class="bg-white shadow-md rounded-lg p-4 flex justify-between items-center mb-6">
                <div class="flex items-center">
                    <div class="menu-icon md:hidden text-gray-600 cursor-pointer mr-4" id="menuIcon" onclick="toggleSidebar()">
                        <i class="fas fa-bars text-xl"></i>
                    </div>
                    <div class="greeting">
                        <h1 class="text-2xl font-semibold text-gray-800">Make a Transfer</h1>
                    </div>
                </div>
                </header>

            <div class="transfer-form-container bg-white p-6 rounded-lg shadow-md max-w-2xl mx-auto">
                <h2 class="text-xl font-bold text-gray-700 mb-4 border-b pb-2">Initiate New Transfer</h2>

                <?php if (!empty($message)): ?>
                    <p class="p-3 mb-4 rounded-lg <?php echo ($message_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'); ?>"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>

                <?php if (!empty($admin_transfer_message)): // Display admin message if available ?>
                    <div class="admin-message-alert bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4" role="alert">
                        <strong class="font-bold text-yellow-600">Important:</strong> <?php echo htmlspecialchars($admin_transfer_message); ?>
                    </div>
                <?php endif; ?>

                <form action="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/make_transfer.php" method="POST" id="transferForm">
                    <input type="hidden" name="initiate_transfer" value="1">

                    <div class="mb-4">
                        <label for="transfer_method" class="block text-sm font-medium text-gray-700 mb-1">Select Transfer Method:</label>
                        <select id="transfer_method" name="transfer_method" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">-- Choose Transfer Type --</option>
                            <option value="internal_self" <?php echo ($active_transfer_method === 'internal_self' ? 'selected' : ''); ?>>Between My Accounts</option>
                            <option value="internal_heritage" <?php echo ($active_transfer_method === 'internal_heritage' ? 'selected' : ''); ?>>To Another HomeTown Bank Pa Account</option>
                            <option value="external_iban" <?php echo ($active_transfer_method === 'external_iban' ? 'selected' : ''); ?>>International Bank Transfer (IBAN/SWIFT)</option>
                            <option value="external_sort_code" <?php echo ($active_transfer_method === 'external_sort_code' ? 'selected' : ''); ?>>UK Bank Transfer (Sort Code/Account No)</option>
                            <option value="external_usa_account" <?php echo ($active_transfer_method === 'external_usa_account' ? 'selected' : ''); ?>>USA Bank Transfer (Routing/Account No)</option>
                            <option value="external_canada_eft" <?php echo ($active_transfer_method === 'external_canada_eft' ? 'selected' : ''); ?>>Canadian Bank Transfer (Transit/Institution No)</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="source_account_id" class="block text-sm font-medium text-gray-700 mb-1">From Account:</label>
                        <select id="source_account_id" name="source_account_id" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">-- Select Your Account --</option>
                            <?php foreach ($user_accounts as $account): ?>
                                <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                    data-balance="<?php echo htmlspecialchars($account['balance']); ?>"
                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                    <?php echo ((string)($form_data['source_account_id'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo get_currency_symbol($account['currency'] ?? 'USD'); ?> <strong><?php echo number_format(abs((float)$account['balance']), 2); ?></strong>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-sm text-gray-500">Available Balance: <span id="amount_currency_symbol_for_balance"></span><span id="display_current_balance" class="text-green-600 font-semibold">N/A</span> <span id="current_currency_display"></span></p>
                    </div>

                    <div class="mb-4 external-fields common-external-fields">
                        <label for="recipient_name" class="block text-sm font-medium text-gray-700 mb-1">Recipient Full Name:</label>
                        <input type="text" id="recipient_name" name="recipient_name" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_name'] ?? ''); ?>">
                    </div>

                    <div id="fields_internal_self" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="destination_account_id_self" class="block text-sm font-medium text-gray-700 mb-1">To My Account:</label>
                            <select id="destination_account_id_self" name="destination_account_id_self" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Select Your Other Account --</option>
                                <?php foreach ($user_accounts as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['id']); ?>"
                                        <?php echo ((string)($form_data['destination_account_id_self'] ?? '') === (string)$account['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_type']); ?> (****<?php echo substr($account['account_number'], -4); ?>) - <?php echo get_currency_symbol($account['currency'] ?? 'USD'); ?> <?php echo number_format(abs((float)$account['balance']), 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="fields_internal_heritage" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="recipient_account_number_internal" class="block text-sm font-medium text-gray-700 mb-1">Recipient HomeTown Bank Pa Account Number:</label>
                            <input type="text" id="recipient_account_number_internal" name="recipient_account_number_internal" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_account_number_internal'] ?? ''); ?>">
                        </div>
                    </div>

                    <div id="fields_external_iban" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="recipient_bank_name_iban" class="block text-sm font-medium text-gray-700 mb-1">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_iban" name="recipient_bank_name_iban" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_iban'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_iban" class="block text-sm font-medium text-gray-700 mb-1">Recipient IBAN:</label>
                            <input type="text" id="recipient_iban" name="recipient_iban" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_iban'] ?? ''); ?>" placeholder="e.g., GBXX XXXX XXXX XXXX XXXX XXXX">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_swift_bic" class="block text-sm font-medium text-gray-700 mb-1">Recipient SWIFT/BIC:</label>
                            <input type="text" id="recipient_swift_bic" name="recipient_swift_bic" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_swift_bic'] ?? ''); ?>" placeholder="e.g., BARCGB22">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_country" class="block text-sm font-medium text-gray-700 mb-1">Recipient Country:</label>
                            <input type="text" id="recipient_country" name="recipient_country" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_country'] ?? ''); ?>" placeholder="e.g., United Kingdom">
                        </div>
                    </div>

                    <div id="fields_external_sort_code" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="recipient_bank_name_sort" class="block text-sm font-medium text-gray-700 mb-1">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_sort" name="recipient_bank_name_sort" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_sort'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_sort_code" class="block text-sm font-medium text-gray-700 mb-1">Recipient Sort Code (6 digits):</label>
                            <input type="text" id="recipient_sort_code" name="recipient_sort_code" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_sort_code'] ?? ''); ?>" pattern="\d{6}" title="Sort Code must be 6 digits">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_external_account_number" class="block text-sm font-medium text-gray-700 mb-1">Recipient Account Number (8 digits):</label>
                            <input type="text" id="recipient_external_account_number" name="recipient_external_account_number" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_external_account_number'] ?? ''); ?>" pattern="\d{8}" title="Account Number must be 8 digits">
                        </div>
                    </div>

                    <div id="fields_external_usa_account" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="recipient_bank_name_usa" class="block text-sm font-medium text-gray-700 mb-1">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_usa" name="recipient_bank_name_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_usa'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_usa_routing_number" class="block text-sm font-medium text-gray-700 mb-1">Recipient Routing Number (9 digits):</label>
                            <input type="text" id="recipient_usa_routing_number" name="recipient_usa_routing_number" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_usa_routing_number'] ?? ''); ?>" pattern="\d{9}" title="Routing Number must be 9 digits">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_usa_account_number" class="block text-sm font-medium text-gray-700 mb-1">Recipient Account Number:</label>
                            <input type="text" id="recipient_usa_account_number" name="recipient_usa_account_number" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_usa_account_number'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_account_type_usa" class="block text-sm font-medium text-gray-700 mb-1">Recipient Account Type:</label>
                            <select id="recipient_account_type_usa" name="recipient_account_type_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Account Type</option>
                                <option value="Checking" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Checking' ? 'selected' : ''); ?>>Checking</option>
                                <option value="Savings" <?php echo (($form_data['recipient_account_type_usa'] ?? '') === 'Savings' ? 'selected' : ''); ?>>Savings</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label for="recipient_address_usa" class="block text-sm font-medium text-gray-700 mb-1">Recipient Address:</label>
                            <input type="text" id="recipient_address_usa" name="recipient_address_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_address_usa'] ?? ''); ?>" placeholder="Street Address">
                        </div>
                        <div class="mb-4 grid grid-cols-3 gap-4">
                            <div>
                                <label for="recipient_city_usa" class="block text-sm font-medium text-gray-700 mb-1">City:</label>
                                <input type="text" id="recipient_city_usa" name="recipient_city_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_city_usa'] ?? ''); ?>">
                            </div>
                            <div>
                                <label for="recipient_state_usa" class="block text-sm font-medium text-gray-700 mb-1">State:</label>
                                <input type="text" id="recipient_state_usa" name="recipient_state_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_state_usa'] ?? ''); ?>">
                            </div>
                            <div>
                                <label for="recipient_zip_usa" class="block text-sm font-medium text-gray-700 mb-1">Zip Code:</label>
                                <input type="text" id="recipient_zip_usa" name="recipient_zip_usa" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_zip_usa'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div id="fields_external_canada_eft" class="external-fields hidden">
                        <div class="mb-4">
                            <label for="recipient_saved_account_id_canada" class="block text-sm font-medium text-gray-700 mb-1">Select Saved Recipient (Optional):</label>
                            <select id="recipient_saved_account_id_canada" name="recipient_saved_account_id_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Choose a Saved Account or Enter Details Below --</option>
                                <?php foreach ($user_saved_canada_accounts as $payee): ?>
                                    <option 
                                        value="<?php echo htmlspecialchars($payee['id']); ?>"
                                        data-institution="<?php echo htmlspecialchars($payee['institution_number'] ?? ''); ?>"
                                        data-transit="<?php echo htmlspecialchars($payee['transit_number'] ?? ''); ?>"
                                        data-account="<?php echo htmlspecialchars($payee['account_number'] ?? ''); ?>"
                                        data-bank-name="<?php echo htmlspecialchars($payee['bank_name'] ?? ''); ?>"
                                        data-address="<?php echo htmlspecialchars($payee['address'] ?? ''); ?>"
                                        data-city-province-postal="<?php echo htmlspecialchars($payee['city_province_postal'] ?? ''); ?>"
                                        data-recipient-name="<?php echo htmlspecialchars($payee['recipient_name'] ?? ''); ?>"
                                        <?php echo ((string)($form_data['recipient_saved_account_id_canada'] ?? '') === (string)$payee['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($payee['recipient_name'] ?? 'Payee'); ?> - (****<?php echo substr($payee['account_number'] ?? '', -4); ?>) - <?php echo htmlspecialchars($payee['bank_name'] ?? 'Canadian Bank'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label for="recipient_bank_name_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient Bank Name:</label>
                            <input type="text" id="recipient_bank_name_canada" name="recipient_bank_name_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_bank_name_canada'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_institution_number_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient Institution Number (3 digits):</label>
                            <input type="text" id="recipient_institution_number_canada" name="recipient_institution_number_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_institution_number_canada'] ?? ''); ?>" pattern="\d{3}" title="Institution Number must be 3 digits">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_transit_number_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient Transit Number (5 digits):</label>
                            <input type="text" id="recipient_transit_number_canada" name="recipient_transit_number_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_transit_number_canada'] ?? ''); ?>" pattern="\d{5}" title="Transit Number must be 5 digits">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_external_account_number_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient Account Number (7-12 digits):</label>
                            <input type="text" id="recipient_external_account_number_canada" name="recipient_external_account_number_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_external_account_number_canada'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_address_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient Address:</label>
                            <input type="text" id="recipient_address_canada" name="recipient_address_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_address_canada'] ?? ''); ?>" placeholder="Street Address">
                        </div>
                        <div class="mb-4">
                            <label for="recipient_city_canada" class="block text-sm font-medium text-gray-700 mb-1">Recipient City, Province/Territory, Postal Code:</label>
                            <input type="text" id="recipient_city_canada" name="recipient_city_canada" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($form_data['recipient_city_canada'] ?? ''); ?>" placeholder="e.g., Toronto, ON, M5V 2H1">
                        </div>
                    </div>

                    <div class="mb-4 relative">
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount:</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 pl-8" value="<?php echo htmlspecialchars($form_data['amount'] ?? ''); ?>" required>
                        <span class="currency-symbol absolute left-2 top-8 text-gray-600 font-bold" id="amount_currency_symbol"></span>
                    </div>
                    <div class="mb-6">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional):</label>
                        <textarea id="description" name="description" rows="3" class="w-full p-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                    </div>
                    <button type="button" class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition duration-150" id="initiateTransferBtn">Initiate Transfer</button>
                </form>
            </div>
            <p class="text-center mt-6"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/dashboard.php" class="text-blue-600 hover:text-blue-800 back-link">&larr; Back to Dashboard</a></p>
        </main>
    </div>

    <div class="modal-overlay fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 transition-opacity duration-300 hidden" id="transferSuccessModal">
        <div class="modal-content bg-white p-8 rounded-lg shadow-xl w-11/12 max-w-sm text-center">
            <h3 class="text-2xl font-bold text-green-600 mb-4">Transfer Initiated! <i class="fas fa-check-circle ml-2"></i></h3>
            <p class="text-gray-700 mb-4">Your transfer request has been successfully submitted and is awaiting approval.</p>
            <div class="text-left space-y-2 mb-6 text-sm">
                <p>Amount: <strong><span id="modalAmount" class="font-mono"></span> <span id="modalCurrency"></span></strong></p>
                <p>To: <strong><span id="modalRecipient"></span></strong></p>
                <p>Status: <strong class="status-pending text-yellow-600" id="modalStatus"></strong></p>
                <p>Reference: <span class="modal-reference font-mono text-gray-600" id="modalReference"></span></p>
                <p>Method: <span id="modalMethod" class="text-gray-500"></span></p>
            </div>
            <button class="modal-button w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition duration-150" id="modalCloseButton">Got It!</button>
        </div>
    </div>

    <div class="pin-modal-overlay fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 transition-opacity duration-300 hidden" id="transferPinModal">
        <div class="pin-modal-content bg-white p-8 rounded-lg shadow-xl w-11/12 max-w-sm text-center">
            <h3 class="text-2xl font-bold text-blue-600 mb-4">Confirm Transfer PIN</h3>
            <p class="text-gray-700 mb-4">Please enter your **4-digit Transfer PIN** to authorize this transaction.</p>
            <form id="pinConfirmationForm" method="POST" action="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/make_transfer.php">
                <input type="hidden" name="initiate_transfer" value="1">
                <input type="hidden" id="pin_transfer_method" name="transfer_method" value="">
                <input type="hidden" id="pin_source_account_id" name="source_account_id" value="">
                <input type="hidden" id="pin_amount" name="amount" value="">
                <input type="hidden" id="transfer_data_payload" name="transfer_data_payload" value=""> 
                
                <input type="password" id="transfer_pin" name="transfer_pin" class="w-full p-3 mb-4 border-2 border-gray-300 rounded-lg text-center text-xl tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500" maxlength="4" placeholder="••••" required>
                <button type="submit" class="modal-button w-full bg-green-600 text-white p-3 rounded-lg font-semibold hover:bg-green-700 transition duration-150">Confirm PIN & Transfer</button>
                <button type="button" class="modal-button w-full bg-red-500 text-white p-3 rounded-lg font-semibold hover:bg-red-600 transition duration-150 mt-3" id="cancelPinBtn">Cancel</button>
            </form>
            <p id="pinError" class="text-red-500 mt-3 hidden">Invalid PIN. Please try again.</p>
        </div>
    </div>
    <script>
        // This object makes PHP variables available to your JavaScript file.
        // It's defined once and can be accessed globally by your script.
        const APP_DATA = {
            userAccountsData: <?php echo json_encode($user_accounts); ?>,
            initialSelectedFromAccount: '<?php echo htmlspecialchars($form_data['source_account_id'] ?? ''); ?>',
            // MODIFIED: Use 'external_canada_eft' for consistency
            initialTransferMethod: '<?php echo htmlspecialchars($active_transfer_method); ?>', 
            showModal: <?php echo $show_modal_on_load ? 'true' : 'false'; ?>,
            modalDetails: <?php echo json_encode($transfer_success_details); ?>,
            // NEW: Pass saved Canadian accounts for auto-filling fields
            savedCanadianAccounts: <?php echo json_encode($user_saved_canada_accounts); ?>
        };

        // Simple function to toggle the sidebar (for mobile menu icon)
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    </script>

    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/transfer.js"></script>
</body>
</html>