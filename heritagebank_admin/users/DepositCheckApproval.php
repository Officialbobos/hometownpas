<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../vendor/autoload.php';

// Load .env and Config
$dotenvPath = dirname(__DIR__, 2); 
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    $dotenv->load();
}
require_once __DIR__ . '/../../Config.php';
require_once __DIR__ . '/../../functions.php';

// Define the bank name here for use in transaction descriptions
const BANK_NAME = 'Home Town Bank';

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$error = '';

// MongoDB Connection
try {
    $client = getMongoDBClient(); // Get the client instance
    $checkDepositsCollection = getCollection('check_deposits', $client);
    $accountsCollection = getCollection('accounts', $client);
    $transactionsCollection = getCollection('transactions', $client);
    $usersCollection = getCollection('users', $client);
} catch (Exception $e) {
    die("A critical database error occurred. Please try again later.");
}

// Handle approval/decline actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $deposit_id_str = $_POST['deposit_id'] ?? null;
    $action = $_POST['action'] ?? null;
    $admin_notes = $_POST['admin_notes'] ?? '';

    // Sanitize and validate inputs
    $deposit_id_str = filter_var($deposit_id_str, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $action = filter_var($action, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $admin_notes = filter_var($admin_notes, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($deposit_id_str && ($action == 'approve' || $action == 'decline')) {
        try {
            $deposit_id_obj = new MongoDB\BSON\ObjectId($deposit_id_str);
            $deposit = $checkDepositsCollection->findOne(['_id' => $deposit_id_obj]);

            if (!$deposit) {
                $error = "Deposit not found.";
            } elseif ($deposit['status'] !== 'pending') {
                $error = "This deposit has already been processed.";
            } else {
                $user_id_obj = $deposit['user_id'];
                $account_id_obj = $deposit['account_id'];
                $amount = $deposit['amount'];

                $session = $client->startSession();
                $session->startTransaction();

                try {
                    if ($action == 'approve') {
                        // 1. Update account balance atomically
                        $updateResult = $accountsCollection->updateOne(
                            ['_id' => $account_id_obj],
                            ['$inc' => ['balance' => $amount]],
                            ['session' => $session]
                        );

                        if ($updateResult->getModifiedCount() === 0) {
                             throw new Exception("Failed to update account balance. Account not found or no change made.");
                        }

                        // Fetch user and account info for transaction log and email
                        $user = $usersCollection->findOne(['_id' => $user_id_obj], ['session' => $session]);
                        $account_info = $accountsCollection->findOne(['_id' => $account_id_obj], ['session' => $session]);
                        
                        // 2. Add a new transaction record
                        $ref_no = 'CHCK' . uniqid();
                        $transaction_data = [
                            'user_id' => $user_id_obj,
                            'account_id' => $account_id_obj,
                            'account_type' => $account_info['account_type'],
                            'transaction_type' => 'Credit',
                            'description' => 'Check Deposit',
                            'amount' => $amount,
                            'currency' => $account_info['currency'],
                            'status' => 'completed',
                            'initiated_at' => new MongoDB\BSON\UTCDateTime(),
                            'completed_at' => new MongoDB\BSON\UTCDateTime(),
                            'recipient_name' => BANK_NAME,
                            'ref_no' => $ref_no,
                        ];
                        $transactionsCollection->insertOne($transaction_data, ['session' => $session]);

                        // 3. Update deposit status
                        $checkDepositsCollection->updateOne(
                            ['_id' => $deposit_id_obj],
                            ['$set' => ['status' => 'approved', 'admin_notes' => $admin_notes]],
                            ['session' => $session]
                        );

                        $session->commitTransaction();
                        $message = "Deposit #{$deposit_id_str} approved successfully.";

                        // Send email notification after successful transaction
                        $user_subject = "Your Check Deposit has been Approved";
                        $user_email_body = createTransactionEmailBody(
                            $user['first_name'],
                            $transaction_data,
                            "Your check deposit for " . $account_info['currency'] . ' ' . number_format($amount, 2) . " has been approved and added to your account."
                        );
                        sendEmail($user['email'], $user_subject, $user_email_body);

                    } elseif ($action == 'decline') {
                        // Update deposit status to declined
                        $checkDepositsCollection->updateOne(
                            ['_id' => $deposit_id_obj],
                            ['$set' => ['status' => 'declined', 'admin_notes' => $admin_notes]],
                            ['session' => $session]
                        );
                        
                        $session->commitTransaction();
                        $message = "Deposit #{$deposit_id_str} declined successfully.";

                        // Fetch user and account info for email
                        $user = $usersCollection->findOne(['_id' => $user_id_obj]);
                        $account_info = $accountsCollection->findOne(['_id' => $account_id_obj]);

                        // Send email notification for decline
                        $user_subject = "Your Check Deposit Has Been Declined";
                        $user_email_body = createTransactionEmailBody(
                            $user['first_name'],
                            [
                                'amount' => $amount, 
                                'currency' => $account_info['currency'],
                                'transaction_type' => 'N/A',
                                'description' => 'Check Deposit',
                                'ref_no' => 'CHCK' . uniqid(),
                                'status' => 'declined'
                            ],
                            "Your check deposit for " . $account_info['currency'] . ' ' . number_format($amount, 2) . " was declined. Reason: " . ($admin_notes ?: "Please contact support for details.")
                        );
                        sendEmail($user['email'], $user_subject, $user_email_body);
                    }
                } catch (MongoDB\Driver\Exception\Exception $e) {
                    $session->abortTransaction();
                    $error = "An error occurred during the database transaction: " . $e->getMessage();
                    error_log("MongoDB Transaction failed in DepositCheckApproval.php: " . $e->getMessage());
                } catch (Exception $e) {
                    $session->abortTransaction();
                    $error = "An unexpected error occurred: " . $e->getMessage();
                    error_log("General Exception in DepositCheckApproval.php: " . $e->getMessage());
                } finally {
                    $session->endSession();
                }
            }
        } catch (Exception $e) {
            $error = "An error occurred: " . $e->getMessage();
            error_log("Admin action on check deposit failed: " . $e->getMessage());
        }
    }
}

// Fetch all pending deposits
$pending_deposits = [];
try {
    $filter = ['status' => 'pending'];
    $options = ['sort' => ['created_at' => 1]];
    $deposits_cursor = $checkDepositsCollection->find($filter, $options);
    
    foreach ($deposits_cursor as $deposit) {
        $user_info = $usersCollection->findOne(['_id' => $deposit['user_id']]);
        $account_info = $accountsCollection->findOne(['_id' => $deposit['account_id']]);
        
        $deposit['user_info'] = $user_info;
        $deposit['account_info'] = $account_info;
        $pending_deposits[] = $deposit;
    }

} catch (Exception $e) {
    $error = "Error fetching pending deposits: " . $e->getMessage();
    error_log("Error fetching pending check deposits from MongoDB: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Check Approval - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/style.css">
    <style>
        .deposit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .deposit-table th, .deposit-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .deposit-table th {
            background-color: #f2f2f2;
        }
        .deposit-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .deposit-table td a {
            color: #007bff;
            text-decoration: none;
        }
        .deposit-table td a:hover {
            text-decoration: underline;
        }
        .action-form textarea {
            width: 100%;
            margin-bottom: 5px;
        }
        .action-form button {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h2>Heritage Bank Admin</h2>
        </header>
        
        <div class="admin-content">
            <h3>Pending Check Deposit Approvals</h3>
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (empty($pending_deposits)): ?>
                <div class="alert alert-info">No pending check deposits to approve.</div>
            <?php else: ?>
                <table class="deposit-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Check Images</th>
                            <th>Submitted At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_deposits as $deposit): ?>
                            <tr>
                                <td><?= htmlspecialchars($deposit['_id']) ?></td>
                                <td><?= htmlspecialchars($deposit['user_info']['first_name'] . ' ' . $deposit['user_info']['last_name'] ?? $deposit['user_info']['email']) ?></td>
                                <td><?= htmlspecialchars($deposit['account_info']['account_type']) ?> (<?= htmlspecialchars($deposit['account_info']['account_number']) ?>)</td>
                                <td><?= htmlspecialchars($deposit['account_info']['currency'] . ' ' . number_format($deposit['amount'], 2)) ?></td>
                                <td>
                                    <a href="<?= rtrim(BASE_URL, '/') . '/' . htmlspecialchars($deposit['front_image_path']) ?>" target="_blank">Front</a><br>
                                    <a href="<?= rtrim(BASE_URL, '/') . '/' . htmlspecialchars($deposit['back_image_path']) ?>" target="_blank">Back</a>
                                </td>
                                <td><?= $deposit['created_at']->toDateTime()->format('Y-m-d H:i:s') ?></td>
                                <td>
                                    <form class="action-form" action="DepositCheckApproval.php" method="post">
                                        <input type="hidden" name="deposit_id" value="<?= htmlspecialchars($deposit['_id']) ?>">
                                        <textarea name="admin_notes" placeholder="Admin notes (optional)"></textarea>
                                        <button type="submit" name="action" value="approve" class="btn btn-success">Approve</button>
                                        <button type="submit" name="action" value="decline" class="btn btn-danger">Decline</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>