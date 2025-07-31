<?php

require_once __DIR__ . '/../Config.php'; // Correct path
require_once __DIR__.'/../vendor/autoload.php'; // Correct path
require_once __DIR__.'/../functions.php'; // Correct path

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$full_name = ''; // Will be fetched from DB

$user_id_mongo = null;
try {
    // Convert session user_id to MongoDB ObjectId
    $user_id_mongo = new ObjectId($user_id);
} catch (Exception $e) {
    error_log("Invalid user ID in session for transactions.php: " . $user_id . " - " . $e->getMessage());
    $_SESSION['message'] = "Invalid session. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: login.php');
    exit;
}

$mongoClient = null;
$database = null;

try {
    // Establish MongoDB connection
    $mongoClient = new Client(MONGODB_CONNECTION_URI);
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);

    // Fetch user's name for display in header
    $usersCollection = $database->users;
    $user_doc = $usersCollection->findOne(['_id' => $user_id_mongo], ['projection' => ['first_name' => 1, 'last_name' => 1]]);

    if ($user_doc) {
        $full_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));
    } else {
        // Fallback if user data not found
        $full_name = $_SESSION['username'] ?? 'User';
    }

    // --- Transaction Filtering and Pagination ---
    $records_per_page = 10;
    $current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($current_page - 1) * $records_per_page;

    $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

    $filter = [
        '$or' => [
            ['user_id' => $user_id_mongo],
            ['recipient_user_id' => $user_id_mongo]
        ]
    ];

    if (!empty($search_query)) {
        $search_regex = new MongoDB\BSON\Regex($search_query, 'i'); // Case-insensitive search
        $filter['$or'][] = [
            '$or' => [
                ['description' => $search_regex],
                ['transaction_reference' => $search_regex],
                ['recipient_name' => $search_regex],
                ['sender_name' => $search_regex]
            ]
        ];
    }

    if (!empty($filter_type)) {
        $filter['transaction_type'] = $filter_type;
    }

    if (!empty($filter_status)) {
        $filter['status'] = $filter_status;
    }

    if (!empty($start_date) || !empty($end_date)) {
        $date_filter = [];
        if (!empty($start_date)) {
            $date_filter['$gte'] = new MongoDB\BSON\UTCDateTime(strtotime($start_date . ' 00:00:00') * 1000);
        }
        if (!empty($end_date)) {
            $date_filter['$lte'] = new MongoDB\BSON\UTCDateTime(strtotime($end_date . ' 23:59:59') * 1000);
        }
        $filter['initiated_at'] = $date_filter;
    }

    $transactionsCollection = $database->transactions;
    $accountsCollection = $database->accounts; // Assuming 'accounts' collection exists for currency info

    // Count total transactions for pagination
    $total_transactions = $transactionsCollection->countDocuments($filter);
    $total_pages = ceil($total_transactions / $records_per_page);

    // Fetch transactions with pagination and sorting
    $options = [
        'sort' => ['initiated_at' => -1],
        'limit' => $records_per_page,
        'skip' => $offset,
        'projection' => [
            'initiated_at' => 1, 'description' => 1, 'amount' => 1, 'currency' => 1,
            'transaction_type' => 1, 'status' => 1, 'transaction_reference' => 1,
            'user_id' => 1, 'recipient_user_id' => 1, 'sender_account_number' => 1,
            'recipient_account_number' => 1, 'recipient_name' => 1, 'sender_name' => 1,
            'recipient_iban' => 1, 'recipient_sort_code' => 1,
            'recipient_external_account_number' => 1, 'recipient_bank_name' => 1,
            'account_id' => 1, // User's own account ID for source currency
        ]
    ];

    $transactions = [];
    $transactions_cursor = $transactionsCollection->find($filter, $options);

    foreach ($transactions_cursor as $transaction_doc) {
        // Convert MongoDB\BSON\UTCDateTime to PHP DateTime object
        if ($transaction_doc['initiated_at'] instanceof MongoDB\BSON\UTCDateTime) {
            $transaction_doc['initiated_at'] = $transaction_doc['initiated_at']->toDateTime();
        }

        // Fetch user's account currency for this transaction if available
        $user_account_currency = $transaction_doc['currency'] ?? 'EUR'; // Default

        if (isset($transaction_doc['account_id']) && $transaction_doc['account_id'] instanceof ObjectId) {
            $account_doc = $accountsCollection->findOne(['_id' => $transaction_doc['account_id']]);
            if ($account_doc && isset($account_doc['currency'])) {
                $user_account_currency = $account_doc['currency'];
            }
        } elseif (isset($transaction_doc['recipient_user_id']) && $transaction_doc['recipient_user_id'] == $user_id_mongo && isset($transaction_doc['recipient_account_number'])) {
            // If the current user is the recipient, try to find the currency of their *receiving* account
            // This assumes recipient_account_number can link to an account document for the logged-in user
            $recipient_acc_doc = $accountsCollection->findOne([
                'user_id' => $user_id_mongo,
                'account_number' => $transaction_doc['recipient_account_number']
            ]);
            if ($recipient_acc_doc && isset($recipient_acc_doc['currency'])) {
                $user_account_currency = $recipient_acc_doc['currency'];
            }
        }
        $transaction_doc['user_account_currency'] = $user_account_currency;

        $transactions[] = (array) $transaction_doc; // Cast to array for consistent access
    }

} catch (Exception $e) {
    error_log("MongoDB Error in transactions.php: " . $e->getMessage());
    $_SESSION['message'] = "Error fetching transaction history. Please try again later.";
    $_SESSION['message_type'] = "error";
    $transactions = []; // Ensure transactions array is empty on error
} finally {
    $mongoClient = null; // No explicit close needed for MongoDB PHP Client
}

// Helper to format currency
function formatCurrency($amount, $currency_code) {
    $symbol = '';
    switch (strtoupper($currency_code)) {
        case 'GBP': $symbol = '£'; break;
        case 'USD': $symbol = '$'; break;
        case 'EUR': $symbol = '€'; break;
        case 'NGN': $symbol = '₦'; break; // Added Nigerian Naira
        // Add more currencies as needed
        default: $symbol = ''; // Fallback, consider adding the code itself if symbol is unknown
    }
    return $symbol . number_format((float)abs($amount), 2); // Always format absolute value, sign is prepended later
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - Heritage Bank</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="transactions.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="dashboard-page">
    <header class="dashboard-header">
        <div class="logo">
            <img src="../images/logo.png" alt="Heritage Bank Logo" class="logo-barclays">
        </div>
        <div class="user-info">
            <i class="fa-solid fa-user profile-icon"></i>
            <span><?php echo htmlspecialchars($full_name); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <div class="dashboard-container">
        <aside class="sidebar">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li class="active"><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="transaction-history-content">
            <div class="card">
                <h2>Your Transaction History</h2>

                <form method="GET" action="transactions.php" class="filter-form">
                    <div class="form-group">
                        <label for="search">Search Description/Reference:</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="e.g., electricity bill">
                    </div>
                    <div class="form-group">
                        <label for="type">Transaction Type:</label>
                        <select id="type" name="type">
                            <option value="">All Types</option>
                            <option value="debit" <?php echo ($filter_type == 'debit') ? 'selected' : ''; ?>>Debit</option>
                            <option value="credit" <?php echo ($filter_type == 'credit') ? 'selected' : ''; ?>>Credit</option>
                            <option value="transfer" <?php echo ($filter_type == 'transfer') ? 'selected' : ''; ?>>General Transfer</option>
                            <option value="internal_self_transfer" <?php echo ($filter_type == 'internal_self_transfer') ? 'selected' : ''; ?>>Internal Self Transfer</option>
                            <option value="internal_heritage" <?php echo ($filter_type == 'internal_heritage') ? 'selected' : ''; ?>>Heritage Internal Transfer</option>
                            <option value="external_iban" <?php echo ($filter_type == 'external_iban') ? 'selected' : ''; ?>>International (IBAN)</option>
                            <option value="external_sort_code" <?php echo ($filter_type == 'external_sort_code') ? 'selected' : ''; ?>>UK Sort Code</option>
                            <option value="deposit" <?php echo ($filter_type == 'deposit') ? 'selected' : ''; ?>>Deposit</option>
                            <option value="withdrawal" <?php echo ($filter_type == 'withdrawal') ? 'selected' : ''; ?>>Withdrawal</option>
                            </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="completed" <?php echo ($filter_status == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo ($filter_status == 'failed') ? 'selected' : ''; ?>>Failed</option>
                            <option value="cancelled" <?php echo ($filter_status == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="start_date">From Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">To Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="form-group">
                        <button type="submit">Apply Filters</button>
                    </div>
                </form>

                <?php if (!empty($transactions)): ?>
                    <div class="table-responsive">
                        <table class="transactions-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction):
                                    // Initialize variables for the amount display and class
                                    $amount_for_display = (float)$transaction['amount'];
                                    $amount_class = '';
                                    $display_amount_string = '';

                                    // Determine if the logged-in user is the sender (debit) or recipient (credit)
                                    $is_user_sender = (isset($transaction['user_id']) && $transaction['user_id'] == $user_id_mongo);
                                    $is_user_recipient = (isset($transaction['recipient_user_id']) && $transaction['recipient_user_id'] == $user_id_mongo);

                                    // Determine if it's an incoming or outgoing transaction for the logged-in user
                                    $is_incoming_for_user = false;
                                    $is_outgoing_for_user = false;

                                    if ($is_user_sender && $is_user_recipient && $transaction['transaction_type'] === 'internal_self_transfer') {
                                        // This is a self-transfer affecting two of the user's own accounts
                                        // How you display this is crucial. If 'amount' is always positive in DB for these,
                                        // you might show it as neither red nor green, or require more logic based on account numbers.
                                        // For simplicity here, if it's a self-transfer and the 'amount' field in DB is positive,
                                        // we'll treat it as a neutral display for the amount itself, or based on its type's implied nature.
                                        // If your DB stores amount as negative for the debiting side of a self-transfer, the 'else' block will handle it.
                                        $amount_class = ''; // Neutral color if amount is always positive for self-transfers
                                        $display_amount_string = formatCurrency($amount_for_display, $transaction['user_account_currency'] ?? 'EUR');
                                        // You might still explicitly add '+' or '-' if it makes sense within the context of the account it affected.
                                        // For now, relying on the 'amount' field's sign if it exists, otherwise neutral.
                                        if ($amount_for_display > 0) {
                                            $display_amount_string = '+' . $display_amount_string;
                                            $amount_class = 'text-success'; // Assume positive amount for self-transfer is a 'credit' effect from a global view
                                        } else if ($amount_for_display < 0) {
                                            $display_amount_string = '-' . $display_amount_string;
                                            $amount_class = 'text-danger'; // Assume negative amount for self-transfer is a 'debit' effect
                                        }

                                    } else if ($is_user_sender) {
                                        // Logged-in user is the sender (initiator) of the transaction
                                        $is_outgoing_for_user = true;
                                    } else if ($is_user_recipient) {
                                        // Logged-in user is the recipient of the transaction
                                        $is_incoming_for_user = true;
                                    }

                                    // Assign amount class and display string based on transaction direction
                                    if ($is_incoming_for_user || strtolower($transaction['transaction_type']) == 'credit' || strtolower($transaction['transaction_type']) == 'deposit') {
                                        $amount_class = 'text-success';
                                        $display_amount_string = '+' . formatCurrency($amount_for_display, $transaction['user_account_currency'] ?? 'EUR');
                                    } else if ($is_outgoing_for_user || strtolower($transaction['transaction_type']) == 'debit' || strtolower($transaction['transaction_type']) == 'withdrawal') {
                                        $amount_class = 'text-danger';
                                        $display_amount_string = '-' . formatCurrency($amount_for_display, $transaction['user_account_currency'] ?? 'EUR');
                                    } else {
                                        // Fallback for cases not explicitly covered by sender/recipient logic
                                        // (e.g., if 'amount' in DB already carries the sign and type isn't perfectly clear)
                                        if ($amount_for_display >= 0) {
                                            $amount_class = 'text-success';
                                            $display_amount_string = '+' . formatCurrency($amount_for_display, $transaction['user_account_currency'] ?? 'EUR');
                                        } else {
                                            $amount_class = 'text-danger';
                                            $display_amount_string = '-' . formatCurrency(abs($amount_for_display), $transaction['user_account_currency'] ?? 'EUR');
                                        }
                                    }


                                    // Determine the display currency code
                                    $display_currency_code = $transaction['user_account_currency'] ?? ($transaction['currency'] ?? 'EUR');

                                    $display_description = htmlspecialchars($transaction['description']);
                                    $transaction_details = [];

                                    // More detailed description based on transaction type and sender/recipient
                                    if ($transaction['transaction_type'] === 'internal_self_transfer') {
                                        $transaction_details[] = "From Acc: " . htmlspecialchars($transaction['sender_account_number'] ?? 'N/A') . " to " . htmlspecialchars($transaction['recipient_account_number'] ?? 'N/A');
                                    } elseif ($is_user_sender) { // This user is the sender (outgoing)
                                        $transaction_details[] = "From Acc: " . htmlspecialchars($transaction['sender_account_number'] ?? 'N/A');
                                        if (!empty($transaction['recipient_name'])) {
                                            $transaction_details[] = "To: " . htmlspecialchars($transaction['recipient_name']);
                                        }
                                        if (!empty($transaction['recipient_account_number'])) {
                                            $transaction_details[] = "Acc No: " . htmlspecialchars($transaction['recipient_account_number']);
                                        } elseif (!empty($transaction['recipient_iban'])) {
                                            $transaction_details[] = "IBAN: " . htmlspecialchars($transaction['recipient_iban'] ?? '');
                                        } elseif (!empty($transaction['recipient_sort_code'])) {
                                            $transaction_details[] = "Sort Code: " . htmlspecialchars($transaction['recipient_sort_code'] ?? '');
                                            $transaction_details[] = "Ext Acc No: " . htmlspecialchars($transaction['recipient_external_account_number'] ?? '');
                                        }
                                        if (!empty($transaction['recipient_bank_name'])) {
                                            $transaction_details[] = "Bank: " . htmlspecialchars($transaction['recipient_bank_name'] ?? '');
                                        }
                                    } elseif ($is_user_recipient) { // This user is the recipient (incoming)
                                        $transaction_details[] = "To Acc: " . htmlspecialchars($transaction['recipient_account_number'] ?? 'N/A');
                                        if (!empty($transaction['sender_name'])) {
                                            $transaction_details[] = "From: " . htmlspecialchars($transaction['sender_name']);
                                        }
                                        if (!empty($transaction['sender_account_number'])) {
                                            $transaction_details[] = "Sender Acc: " . htmlspecialchars($transaction['sender_account_number']);
                                        }
                                    }

                                    // Status Class Logic
                                    $status_class_base = 'status-' . strtolower($transaction['status']);
                                    $status_class = '';
                                    if ($status_class_base === 'status-failed' || $status_class_base === 'status-cancelled') {
                                        $status_class = 'status-failed'; // Group failed and cancelled for red color
                                    } else {
                                        $status_class = $status_class_base;
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo $transaction['initiated_at']->format('Y-m-d H:i'); ?></td>
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $transaction['transaction_type']))); ?></td>
                                        <td><?php echo $display_description; ?></td>
                                        <td class="<?php echo $amount_class; ?>">
                                            <?php echo $display_amount_string; ?>
                                            <br><small>(<?php echo htmlspecialchars($display_currency_code); ?>)</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['transaction_reference']); ?></td>
                                        <td><span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst($transaction['status'])); ?></span></td>
                                        <td><?php echo implode('<br>', $transaction_details); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                                $query_params = $_GET; // Get current filter params
                                $query_params['page'] = $i; // Set current page
                                $pagination_link = '?' . http_build_query($query_params);
                            ?>
                            <a href="<?php echo $pagination_link; ?>" class="<?php echo ($i == $current_page) ? 'current-page' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <p class="no-transactions">No transactions found for the selected criteria.</p>
                <?php endif; ?>

            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>
