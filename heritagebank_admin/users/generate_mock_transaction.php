<?php
require_once '../../Config.php'; // Assuming Config.php contains MongoDB connection details
require_once '../../functions.php'; // This is good to have for future database operations

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = '';
$user_currency_symbol = '€'; // Default to Euro symbol for display in form if no user selected initially

// MongoDB Connection
try {
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);   
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
    $transactionsCollection = $database->selectCollection('transactions');
} catch (MongoDB\Driver\Exception\Exception $e) {
    die("ERROR: Could not connect to MongoDB: " . $e->getMessage());
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_identifier = trim($_POST['user_identifier'] ?? '');
    $start_date_str = trim($_POST['start_date'] ?? '');
    $end_date_str = trim($_POST['end_date'] ?? '');
    $max_amount_per_transaction = floatval($_POST['max_amount_per_transaction'] ?? 0);
    $min_transactions_per_day = intval($_POST['min_transactions_per_day'] ?? 0);
    $max_transactions_per_day = intval($_POST['max_transactions_per_day'] ?? 0);

    // Basic validation
    if (empty($user_identifier) || empty($start_date_str) || empty($end_date_str) ||
        $max_amount_per_transaction <= 0 || $min_transactions_per_day <= 0 ||
        $max_transactions_per_day < $min_transactions_per_day) {
        $message = 'All fields are required. Dates must be valid, amounts positive, and transaction counts correctly set.';
        $message_type = 'error';
    } else {
        try {
            $start_date = new DateTime($start_date_str);
            $end_date = new DateTime($end_date_str);

            if ($start_date > $end_date) {
                throw new Exception("Start date cannot be after end date.");
            }

            // Find the user and their first name, last name, and currency from 'users' collection
            $user = $usersCollection->findOne([
                '$or' => [
                    ['email' => $user_identifier],
                    ['membership_number' => $user_identifier]
                ]
            ]);

            if (!$user) {
                throw new Exception('User not found with the provided identifier.');
            }

            $user_id = $user['_id']; // MongoDB's default _id
            $user_name = $user['first_name'] . ' ' . $user['last_name'];
            $user_currency_code = strtoupper($user['currency'] ?? 'EUR'); // Default to EUR if not set in DB

            // --- START CODE TO FETCH ACCOUNT_ID AND ITS BALANCE ---
            $user_account = $accountsCollection->findOne(['user_id' => $user_id]);

            if (!$user_account) {
                throw new Exception('No account found for the provided user. Please ensure the user has at least one account in the "accounts" collection.');
            }
            $user_account_id = $user_account['_id'];
            $current_account_balance = $user_account['balance']; // Initialize with the account's current balance
            // --- END CODE TO FETCH ACCOUNT_ID AND ITS BALANCE ---

            // Set the currency symbol for display based on fetched code (now only handles EUR/GBP)
            switch ($user_currency_code) {
                case 'GBP': $user_currency_symbol = '£'; break;
                case 'EUR':
                default: $user_currency_symbol = '€'; break; // Default to Euro if it's not GBP or unrecognized
            }

            $successful_transactions = 0;
            $failed_transactions = 0;

            $credit_descriptions = [
                'Online Deposit', 'Transfer In', 'Salary', 'Investment Gain', 'Cash Deposit', 'Refund', 'Loan Disbursement'
            ];
            $debit_descriptions = [
                'Online Purchase', 'Groceries', 'Utility Bill', 'ATM Withdrawal', 'Transfer Out', 'Subscription Fee', 'Restaurant Bill', 'POS Payment'
            ];

            $interval = new DateInterval('P1D'); // 1 Day interval
            $period = new DatePeriod($start_date, $interval, $end_date->modify('+1 day')); // Include end date

            foreach ($period as $date) {
                $num_transactions_today = mt_rand($min_transactions_per_day, $max_transactions_per_day);

                for ($i = 0; $i < $num_transactions_today; $i++) {
                    $amount = round(mt_rand(100, $max_amount_per_transaction * 100) / 100, 2); // Random amount between 1 and max
                    $type = (mt_rand(0, 1) === 0) ? 'credit' : 'debit'; // Randomly choose credit or debit

                    $description_list = ($type === 'credit') ? $credit_descriptions : $debit_descriptions;
                    $description = $description_list[array_rand($description_list)];

                    $transaction_status = 'Completed';

                    // Generate a random time for the transaction within the day
                    $random_hour = str_pad(mt_rand(0, 23), 2, '0', STR_PAD_LEFT);
                    $random_minute = str_pad(mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                    $random_second = str_pad(mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                    $transaction_datetime = $date->format('Y-m-d') . " {$random_hour}:{$random_minute}:{$random_second}";

                    // Generate a unique transaction reference
                    $transaction_reference = 'TRX-' . $user_id . '-' . $date->format('Ymd') . $random_hour . $random_minute . $random_second . '-' . substr(uniqid(), -5);

                    if ($type === 'debit') {
                        if ($current_account_balance < $amount) {
                            $transaction_status = 'Failed - Insufficient Funds';
                        } else {
                            $current_account_balance -= $amount;
                        }
                    } else { // credit
                        $current_account_balance += $amount;
                    }

                    // Insert transaction into 'transactions' collection
                    try {
                        $insertResult = $transactionsCollection->insertOne([
                            'user_id' => $user_id,
                            'account_id' => $user_account_id,
                            'amount' => $amount,
                            'transaction_type' => $type,
                            'description' => $description,
                            'status' => $transaction_status,
                            'transaction_date' => new MongoDB\BSON\UTCDateTime(new DateTime($transaction_datetime)),
                            'currency' => $user_currency_code,
                            'transaction_reference' => $transaction_reference
                        ]);

                        if (!$insertResult->getInsertedId()) {
                            error_log("Failed transaction insertion for user " . $user_id);
                            throw new Exception("Transaction insertion failed for user " . $user_id);
                        }

                        // Update the balance in the 'accounts' collection
                        if ($transaction_status === 'Completed') {
                            $updateResult = $accountsCollection->updateOne(
                                ['_id' => $user_account_id],
                                ['$set' => ['balance' => $current_account_balance]]
                            );

                            if ($updateResult->getModifiedCount() !== 1) {
                                error_log("Failed to update account balance for account " . $user_account_id);
                                throw new Exception("Account balance update failed.");
                            }
                            $successful_transactions++;
                        } else {
                            $failed_transactions++;
                        }
                    } catch (MongoDB\Driver\Exception\Exception $mongoException) {
                        error_log("MongoDB operation failed: " . $mongoException->getMessage());
                        throw new Exception("Database operation failed during transaction generation: " . $mongoException->getMessage());
                    }
                }
            }

            // After all transactions for the period, update the user's final balance in the 'users' collection
            // This balance in 'users' collection will now reflect the final balance of the single primary account selected.
            $updateUserBalanceResult = $usersCollection->updateOne(
                ['_id' => $user_id],
                ['$set' => ['balance' => $current_account_balance]]
            );
            if ($updateUserBalanceResult->getModifiedCount() !== 1) {
                error_log("Failed to update user's final balance for user " . $user_id);
                throw new Exception("Final user balance update failed.");
            }

            // Determine balance display color and sign for the success message
            $balance_display = $user_currency_symbol . number_format($current_account_balance, 2);
            $balance_color_style = '';
            if ($current_account_balance > 0) {
                $balance_display = '+' . $balance_display; // Add plus sign for positive balance
                $balance_color_style = 'color: green;';
            } elseif ($current_account_balance < 0) {
                $balance_display = '-' . $balance_display; // Add minus sign for negative balance
                $balance_color_style = 'color: red;';
            }

            $message = "Generated " . $successful_transactions . " successful transactions and " . $failed_transactions . " failed transactions for " . htmlspecialchars($user_name) . ". New balance: <span style='" . $balance_color_style . " font-weight: bold;'>" . $balance_display . "</span>";
            $message_type = 'success';

            // Clear form fields after successful generation
            $_POST = array(); // Clear post data to reset form values

        } catch (Exception $e) {
            // For MongoDB, there's no direct rollback like relational databases unless using transactions
            // for multi-document operations (which requires replica set). For simple insert/update,
            // individual operations are atomic. If an error occurs, the operations before it would have committed.
            $message = "Transaction generation failed: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// If form was not submitted or on error, try to pre-fetch user currency if identifier is present
$display_user_currency_symbol = '€'; // Default symbol for form display
if (isset($_POST['user_identifier']) && !empty($_POST['user_identifier'])) {
    $temp_identifier = trim($_POST['user_identifier']);
    try {
        // Re-establish client if it somehow became null (though it's persistent here)
        if (!isset($client)) {
            $client = new MongoDB\Client(MONGO_URI);
            $database = $client->selectDatabase(MONGO_DB_NAME);
            $usersCollection = $database->selectCollection('users');
        }

        $user_for_currency_lookup = $usersCollection->findOne([
            '$or' => [
                ['email' => $temp_identifier],
                ['membership_number' => $temp_identifier]
            ]
        ]);

        if ($user_for_currency_lookup) {
            $fetched_currency_code = strtoupper($user_for_currency_lookup['currency'] ?? 'EUR');
            switch ($fetched_currency_code) {
                case 'GBP': $display_user_currency_symbol = '£'; break;
                case 'EUR':
                default: $display_user_currency_symbol = '€'; break;
            }
        }
    } catch (MongoDB\Driver\Exception\Exception $e) {
        error_log("ERROR: Could not fetch currency for display: " . $e->getMessage());
        // Fallback to default symbol
        $display_user_currency_symbol = '€';
    }
} else {
    // If no identifier is submitted, default to EUR for the form placeholder
    $display_user_currency_symbol = '€';
}

// MongoDB connection does not need explicit close like mysqli_close.
// The client object will be garbage collected when the script ends.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Generate Mock Transaction</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Additional styling for messages */
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .message.success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        /* Style for the "Back to User Management" link */
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Basic form styling for better appearance */
        .form-standard {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
        }

        .form-group small {
            color: #666;
            font-size: 0.85em;
        }

        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: auto; /* Adjust width to content */
            display: inline-block; /* Allows text-align center in parent if needed */
        }

        .button-primary:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Generate Mock Transactions</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo $message; /* $message already contains HTML */ ?></p>
            <?php endif; ?>

            <form action="generate_mock_transaction.php" method="POST" class="form-standard">
                <div class="form-group">
                    <label for="user_identifier">User Email or Membership Number</label>
                    <input type="text" id="user_identifier" name="user_identifier" value="<?php echo htmlspecialchars($_POST['user_identifier'] ?? ''); ?>" placeholder="e.g., user@example.com or MEM12345678" required>
                    <small>Enter the user's email or membership number.</small>
                </div>

                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? date('Y-m-01')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($_POST['end_date'] ?? date('Y-m-t')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_amount_per_transaction">Max Amount Per Transaction (<?php echo $display_user_currency_symbol; ?>)</label>
                    <input type="number" id="max_amount_per_transaction" name="max_amount_per_transaction" step="0.01" min="1.00" value="<?php echo htmlspecialchars($_POST['max_amount_per_transaction'] ?? '1000.00'); ?>" required>
                    <small>The maximum amount for any single generated transaction in the user's currency.</small>
                </div>

                <div class="form-group">
                    <label for="min_transactions_per_day">Min Transactions Per Day</label>
                    <input type="number" id="min_transactions_per_day" name="min_transactions_per_day" min="1" value="<?php echo htmlspecialchars($_POST['min_transactions_per_day'] ?? '1'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="max_transactions_per_day">Max Transactions Per Day</label>
                    <input type="number" id="max_transactions_per_day" name="max_transactions_per_day" min="1" value="<?php echo htmlspecialchars($_POST['max_transactions_per_day'] ?? '5'); ?>" required>
                    <small>Sets the range for how many transactions are generated each day.</small>
                </div>

                <button type="submit" class="button-primary">Generate Transactions</button>
            </form>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>