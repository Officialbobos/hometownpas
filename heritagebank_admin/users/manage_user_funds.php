<?php

require_once '../../Config.php'; // Adjust path based on your actual file structure
require_once '../../functions.php'; // This is good to have for future database operations

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = ''; // Will be 'success', 'error', 'credit', or 'debit' for styling

// MongoDB Connection
try {
    // MONGO_URI and MONGO_DB_NAME should be defined in Config.php
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $database->selectCollection('accounts');
    $usersCollection = $database->selectCollection('users'); // Need users collection to fetch user details
    $transactionsCollection = $database->selectCollection('transactions');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_number_input = trim($_POST['account_number'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $operation_type = $_POST['operation_type'] ?? ''; // 'credit' or 'debit'
    $admin_description = trim($_POST['admin_description'] ?? '');

    // Input validation
    if (empty($account_number_input) || $amount <= 0 || !in_array($operation_type, ['credit', 'debit'])) {
        $message = 'Please provide a valid account number, a positive amount, and select an operation type.';
        $message_type = 'error';
    } else {
        $current_balance = 0;
        $account_doc = null; // Will hold the account document
        $user_doc = null;    // Will hold the user document

        try {
            // Find the account by account_number
            $account_doc = $accountsCollection->findOne(['account_number' => $account_number_input]);

            if (!$account_doc) {
                $message = 'Account not found with the provided account number.';
                $message_type = 'error';
            } else {
                $current_balance = $account_doc['balance'];
                $account_id = $account_doc['_id'];
                $user_id = $account_doc['user_id'];

                // Fetch user details using the user_id from the account document
                $user_doc = $usersCollection->findOne(['_id' => $user_id]);

                if (!$user_doc) {
                    $message = 'Associated user not found for this account.';
                    $message_type = 'error';
                } else {
                    $user_email = $user_doc['email'];
                    $user_name = trim(($user_doc['first_name'] ?? '') . ' ' . ($user_doc['last_name'] ?? ''));

                    $new_balance = $current_balance;
                    $transaction_type_label = '';
                    $transaction_status = 'Completed';
                    $final_message_amount = number_format($amount, 2);

                    // --- REVISED LOGIC FOR DEFAULT DESCRIPTION ---
                    if (empty($admin_description)) {
                        // Use a neutral, user-friendly description that doesn't mention "admin"
                        if ($operation_type === 'credit') {
                            $admin_description = 'Bank Deposit';
                        } else {
                            $admin_description = 'Bank Withdrawal';
                        }
                    }

                    if ($operation_type === 'credit') {
                        $new_balance += $amount;
                        $transaction_type_label = 'credited';
                    } else { // debit
                        if ($current_balance < $amount) {
                            $message = 'Insufficient funds in account ' . htmlspecialchars($account_number_input) . ' for debit operation.';
                            $message_type = 'error';
                            $transaction_status = 'Failed - Insufficient Funds';
                        } else {
                            $new_balance -= $amount;
                            $transaction_type_label = 'debited';
                        }
                    }

                    if ($message_type !== 'error') {
                        // Update the balance in the accounts collection
                        $updateAccountResult = $accountsCollection->updateOne(
                            ['_id' => $account_id],
                            ['$set' => ['balance' => $new_balance]]
                        );

                        if ($updateAccountResult->getModifiedCount() === 1) {
                            // Also update the main user balance
                            $updateUserResult = $usersCollection->updateOne(
                                ['_id' => $user_id],
                                ['$set' => ['balance' => $new_balance]]
                            );

                            if ($updateUserResult->getModifiedCount() !== 1 && ($user_doc['balance'] ?? 0) !== $new_balance) {
                                error_log("Failed to update user's summary balance for user ID: " . $user_id . ". Account balance still updated.");
                            }

                            // Insert a transaction record
                            $insertTransactionResult = $transactionsCollection->insertOne([
                                'account_id' => $account_id,
                                'user_id' => $user_id,
                                'type' => $operation_type,
                                'amount' => $amount,
                                'description' => $admin_description, // Use the user-facing description
                                'current_balance' => $new_balance,
                                'transaction_date' => new MongoDB\BSON\UTCDateTime(),
                                'status' => $transaction_status,
                                'account_number' => $account_number_input
                            ]);

                            if ($insertTransactionResult->getInsertedId()) {
                                $message = "Account " . htmlspecialchars($account_number_input) . " (" . htmlspecialchars($user_name) . " - {$user_email}) successfully {$transaction_type_label} with " . number_format($amount, 2) . ". New balance: " . number_format($new_balance, 2);
                                $message_type = $operation_type;
                                $_POST = array(); // Clear form after success
                            } else {
                                $message = "Error recording transaction for account " . htmlspecialchars($account_number_input) . ". (Transaction insert failed)";
                                $message_type = 'error';
                            }
                        } else {
                            $message = "Error updating balance for account " . htmlspecialchars($account_number_input) . ". (Account update failed)";
                            $message_type = 'error';
                        }
                    }
                }
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            $message = "An unexpected database error occurred: " . $e->getMessage();
            $message_type = 'error';
            error_log("Manage User Funds MongoDB Error: " . $e->getMessage());
        } catch (Exception $e) { // For other general exceptions
            $message = "An unexpected error occurred: " . $e->getMessage();
            $message_type = 'error';
            error_log("Manage User Funds General Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage User Funds</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Specific styles for fund management messages */
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .message.credit {
            color: #1a7d30; /* Darker Green */
            background-color: #d4edda; /* Lighter Green */
            border-color: #a3daab;
        }
        .message.debit {
            color: #8c0000; /* Darker Red */
            background-color: #f8d7da; /* Lighter Red */
            border-color: #f5c6cb;
        }
        .message.error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .message.success { /* For other non-credit/debit successes, though 'credit' and 'debit' are more specific here */
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Manage User Funds</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>">
                    <?php
                        // Enhanced feedback with icons based on message type
                        if ($message_type === 'credit') {
                            echo '<span style="font-size: 1.2em;">&#x2713; + </span> ' . htmlspecialchars($message);
                        } elseif ($message_type === 'debit') {
                            echo '<span style="font-size: 1.2em;">&#x2717; - </span> ' . htmlspecialchars($message);
                        } else {
                            echo htmlspecialchars($message);
                        }
                    ?>
                </p>
            <?php endif; ?>

            <form action="manage_user_funds.php" method="POST" class="form-standard">
                <div class="form-group">
                    <label for="account_number">User Account Number</label>
                    <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>" placeholder="e.g., CHK00123456 or SAV00123456" required>
                    <small>Enter the exact account number to credit or debit.</small>
                </div>
                <div class="form-group">
                    <label for="amount">Amount</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" value="<?php echo htmlspecialchars($_POST['amount'] ?? '0.00'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="operation_type">Operation Type</label>
                    <select id="operation_type" name="operation_type" required>
                        <option value="">-- Select --</option>
                        <option value="credit" <?php echo (($_POST['operation_type'] ?? '') == 'credit') ? 'selected' : ''; ?>>Credit (Add Funds)</option>
                        <option value="debit" <?php echo (($_POST['operation_type'] ?? '') == 'debit') ? 'selected' : ''; ?>>Debit (Remove Funds)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="admin_description">Transaction Description (Optional)</label>
                    <input type="text" id="admin_description" name="admin_description" value="<?php echo htmlspecialchars($_POST['admin_description'] ?? ''); ?>" placeholder="e.g., Salary deposit, Withdrawal at ATM">
                    <small>This description will appear in the user's transaction history.</small>
                </div>
                <button type="submit" class="button-primary">Process Funds</button>
            </form>

            <p><a href="manage_users.php" class="back-link">&larr; Back to Manage Users</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>