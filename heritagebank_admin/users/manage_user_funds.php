<?php
session_start();

// The following lines are for debugging and should not be used on a live production site.
// I've commented them out for security and performance on a live site.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once __DIR__ . '/../../Config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../functions.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, characters: '/') . '/heritagebank_admin/index.php');
    exit;
}


$message = '';
$message_type = ''; // Will be 'success', 'error', 'credit', or 'debit' for styling

// MongoDB Connection
try {
    $client = new Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $accountsCollection = $database->selectCollection('accounts');
    $usersCollection = $database->selectCollection('users');
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
        $account_doc = null;
        $user_doc = null;

        try {
            $account_doc = $accountsCollection->findOne(['account_number' => $account_number_input]);

            if (!$account_doc) {
                $message = 'Account not found with the provided account number.';
                $message_type = 'error';
            } else {
                $current_balance = $account_doc['balance'];
                $account_id = $account_doc['_id'];
                $user_id = $account_doc['user_id'];

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

                    if (empty($admin_description)) {
                        $admin_description = ($operation_type === 'credit') ? 'Admin credit' : 'Admin debit';
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
                        $updateAccountResult = $accountsCollection->updateOne(
                            ['_id' => $account_id],
                            ['$set' => ['balance' => $new_balance]]
                        );

                        if ($updateAccountResult->getModifiedCount() === 1) {
                            $updateUserResult = $usersCollection->updateOne(
                                ['_id' => $user_id],
                                ['$set' => ['balance' => $new_balance]]
                            );

                            if ($updateUserResult->getModifiedCount() !== 1 && ($user_doc['balance'] ?? 0) !== $new_balance) {
                                error_log("Failed to update user's summary balance for user ID: " . $user_id . ". Account balance still updated.");
                            }

                            $insertTransactionResult = $transactionsCollection->insertOne([
                                'account_id' => $account_id,
                                'user_id' => $user_id,
                                'type' => $operation_type,
                                'amount' => $amount,
                                'description' => $admin_description,
                                'current_balance' => $new_balance,
                                'transaction_date' => new UTCDateTime(),
                                'status' => $transaction_status,
                                'account_number' => $account_number_input
                            ]);

                            if ($insertTransactionResult->getInsertedId()) {
                                $message = "Account " . htmlspecialchars($account_number_input) . " (" . htmlspecialchars($user_name) . " - {$user_email}) successfully {$transaction_type_label} with " . number_format($amount, 2) . ". New balance: " . number_format($new_balance, 2);
                                $message_type = $operation_type;
                                $_POST = array(); // Clear POST data to reset form fields
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
        } catch (Exception $e) {
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
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/style.css'; ?>">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General Body and Container Styling */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .dashboard-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            align-items: center;
        }

        /* Dashboard Header */
        .dashboard-header {
            background-color: #004494; /* Heritage Blue */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .dashboard-header .logo {
            height: 40px;
        }
        .dashboard-header h2 {
            margin: 0;
            color: white;
            font-size: 1.8em;
        }
        .dashboard-header .logout-button {
            background-color: #ffcc29; /* Heritage Gold */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .dashboard-header .logout-button:hover {
            background-color: #e0b821;
        }

        /* Main Content Area */
        .dashboard-content {
            padding: 30px;
            width: 100%;
            max-width: 600px; /* Form width is narrower for better focus */
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box;
        }

        /* Message Styling */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
            word-wrap: break-word; /* Prevents overflow on small screens */
        }
        .message.credit {
            background-color: #d4edda;
            color: #1a7d30;
            border-color: #a3daab;
        }
        .message.debit {
            background-color: #f8d7da;
            color: #8c0000;
            border-color: #f5c6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Form Styling */
        .form-standard {
            display: flex;
            flex-direction: column;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-standard label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 1.1em;
        }
        .form-standard input[type="text"],
        .form-standard input[type="number"],
        .form-standard select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s ease;
        }
        .form-standard input:focus,
        .form-standard select:focus {
            outline: none;
            border-color: #004494;
            box-shadow: 0 0 5px rgba(0, 68, 148, 0.2);
        }
        .form-standard button.button-primary {
            background-color: #004494;
            color: white;
            padding: 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.2em;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .form-standard button.button-primary:hover {
            background-color: #003366;
        }
        .form-group small {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
            display: block;
        }

        /* Back Link */
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #004494;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #003366;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }
            .dashboard-header .logo {
                margin-bottom: 10px;
            }
            .dashboard-header h2 {
                font-size: 1.5em;
            }
            .dashboard-content {
                margin: 15px;
                padding: 20px;
            }
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
                        if ($message_type === 'credit') {
                            echo '<span style="font-size: 1.2em; font-family: monospace;">&#x2713; + </span> ' . htmlspecialchars($message);
                        } elseif ($message_type === 'debit') {
                            echo '<span style="font-size: 1.2em; font-family: monospace;">&#x2717; - </span> ' . htmlspecialchars($message);
                        } else {
                            echo htmlspecialchars($message);
                        }
                    ?>
                </p>
            <?php endif; ?>

            <form action="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_user_funds.php'; ?>" method="POST">
                <div class="form-group">
                    <label for="account_number">User Account Number</label>
                    <input type="text" id="account_number" name="account_number" value="<?php echo htmlspecialchars($_POST['account_number'] ?? ''); ?>" placeholder="e.g., CHK00123456" required>
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
                    <input type="text" id="admin_description" name="admin_description" value="<?php echo htmlspecialchars($_POST['admin_description'] ?? ''); ?>" placeholder="e.g., Salary deposit, ATM withdrawal">
                    <small>This description will appear in the user's transaction history.</small>
                </div>
                <button type="submit" class="button-primary">Process Funds</button>
            </form>

<<<<<<< HEAD
<p><a href="<?php echo rtrim(BASE_URL, '/') . '/admin/manage_users'; ?>" class="back-link">&larr; Back to Manage Users</a></p>        </div>
=======
            <p><a href="<?php echo rtrim(BASE_URL, '/') . '/admin/manage_users'; ?>" class="back-link">&larr; Back to Manage Users</a></p>
        </div>
>>>>>>> 9279b39ec00731d3162e0fb489128bbccc0f0f75
    </div>
<script src="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/script.js'; ?>"></script>
</body>
</html>
