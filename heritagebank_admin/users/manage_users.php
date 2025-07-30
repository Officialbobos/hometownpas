<?php
/**
 * manage_users.php
 *
 * Admin panel page to manage user accounts.
 * Allows administrators to view a list of all users,
 * and perform actions like editing or deleting a user and their associated data.
 */

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../Config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../functions.php';

// Check if admin is logged in, if not, redirect to login page
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = '';

// MongoDB Connection
try {
    error_log("--- manage_users.php: Starting MongoDB connection block.");
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);
    error_log("--- manage_users.php: MongoDB Client created.");

    $database = $client->selectDatabase(MONGODB_DB_NAME);
    error_log("--- manage_users.php: Database selected.");

    $usersCollection = $database->selectCollection('users');
    error_log("--- manage_users.php: Users collection selected.");
    $accountsCollection = $database->selectCollection('accounts');
    error_log("--- manage_users.php: Accounts collection selected.");
    $transactionsCollection = $database->selectCollection('transactions');
    error_log("--- manage_users.php: Transactions collection selected.");
    $bankCardsCollection = $database->selectCollection('bank_cards');
    error_log("--- manage_users.php: Bank Cards collection selected.");
    $accountStatusHistoryCollection = $database->selectCollection('account_status_history');
    error_log("--- manage_users.php: Account status history collection selected.");

} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later.");
}

// --- Handle Delete Action ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $user_id_to_delete_str = $_GET['id'];
    
    try {
        // Convert string ID to a MongoDB\BSON\ObjectId for the query
        $user_id_to_delete = new MongoDB\BSON\ObjectId($user_id_to_delete_str);

        // Optional: Get profile image path before deleting user to remove the file
        $profile_image_path_to_delete = null;
        $user_to_delete = $usersCollection->findOne(['_id' => $user_id_to_delete]);
        if ($user_to_delete && isset($user_to_delete['profile_image'])) {
            $profile_image_path_to_delete = $user_to_delete['profile_image'];
        }
        
        // --- Deletion Cascade: Delete all related documents first ---
        
        // Delete related transactions
        $transactionsCollection->deleteMany(['user_id' => $user_id_to_delete]);
        
        // Delete related bank cards
        $bankCardsCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Delete associated account status history
        $accountStatusHistoryCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Delete all accounts associated with the user
        $accountsCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Finally, delete the user record itself
        $deleteUserResult = $usersCollection->deleteOne(['_id' => $user_id_to_delete]);

        if ($deleteUserResult->getDeletedCount() === 1) {
            $_SESSION['flash_message'] = "User and all associated data deleted successfully. 🎉";
            $_SESSION['flash_message_type'] = 'success';

            // Delete profile image file from server if it exists
            if ($profile_image_path_to_delete && file_exists('../../' . $profile_image_path_to_delete)) {
                unlink('../../' . $profile_image_path_to_delete);
            }
        } else {
            $_SESSION['flash_message'] = "User not found or already deleted.";
            $_SESSION['flash_message_type'] = 'error';
        }

    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        $_SESSION['flash_message'] = "Invalid user ID provided for deletion.";
        $_SESSION['flash_message_type'] = 'error';
        error_log("Invalid user ID for deletion: " . $_GET['id']);
    } catch (Exception $e) {
        $_SESSION['flash_message'] = "An unexpected error occurred during deletion: " . $e->getMessage();
        $_SESSION['flash_message_type'] = 'error';
        error_log("User deletion error: " . $e->getMessage());
    }
    
    // Redirect to the same page to prevent form re-submission and display flash message
    header('Location: manage_users.php');
    exit;
}

// --- Fetch Flash Message from Session (if it exists) ---
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    // Clear the session variables so the message doesn't persist on refresh
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}


// --- Fetch Users (for display) ---
$users = [];
try {
    // Find all users and sort by creation date descending
    $allUsers = $usersCollection->find([], ['sort' => ['created_at' => -1]]);

    foreach ($allUsers as $userDoc) {
        $user = (array) $userDoc; // Convert MongoDB Document to array

        // Fetch Checking Account
        $checkingAccount = $accountsCollection->findOne([
            'user_id' => $user['_id'],
            'account_type' => 'Checking'
        ]);
        $user['checking_balance'] = $checkingAccount ? $checkingAccount['balance'] : 0.00;
        $user['checking_account_number'] = $checkingAccount ? $checkingAccount['account_number'] : null;

        // Fetch Savings Account
        $savingsAccount = $accountsCollection->findOne([
            'user_id' => $user['_id'],
            'account_type' => 'Savings'
        ]);
        $user['savings_balance'] = $savingsAccount ? $savingsAccount['balance'] : 0.00;
        $user['savings_account_number'] = $savingsAccount ? $savingsAccount['account_number'] : null;

        // Determine common bank details from either account, preferring checking if both exist
        $primaryAccount = $checkingAccount ?: $savingsAccount;
        $user['common_currency'] = $primaryAccount['currency'] ?? 'N/A';
        $user['common_sort_code'] = $primaryAccount['sort_code'] ?? 'N/A';
        $user['common_iban'] = $primaryAccount['iban'] ?? 'N/A';
        $user['common_swift_bic'] = $primaryAccount['swift_bic'] ?? 'N/A';
        
        // Ensure _id is converted to string for HTML links
        $user['id'] = (string) $user['_id'];

        // Format creation date for display
        $user['created_at_formatted'] = isset($user['created_at']) ? $user['created_at']->toDateTime()->format('M j, Y') : 'N/A';

        $users[] = $user;
    }
} catch (Exception $e) {
    $message = "Error fetching users: " . $e->getMessage();
    $message_type = 'error';
    error_log("Fetch Users MongoDB Error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage Users</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General body and container styling */
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
            background-color: #004494; /* Darker blue for header */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header .logo {
            height: 40px; /* Adjust logo size */
        }

        .dashboard-header h2 {
            margin: 0;
            color: white;
            font-size: 1.8em;
        }

        .dashboard-header .logout-button {
            background-color: #ffcc29; /* Heritage accent color */
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
            max-width: 1200px; /* Wider for table */
            margin: 20px auto; /* Center the content */
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box; /* Include padding in width */
        }

        /* Messages */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            min-width: 800px; /* Ensure table doesn't get too small on narrow screens */
        }
        table th, table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        table th {
            background-color: #f2f2f2;
            font-weight: bold;
            background-color: #004494;
            color: white;
            font-size: 0.9em;
            text-transform: uppercase;
        }
        table td {
            font-size: 0.95em;
        }
        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        table tr:hover {
            background-color: #f1f1f1;
        }

        .button-small {
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            font-size: 0.85em;
            display: inline-block;
            margin-right: 5px;
            margin-bottom: 5px; /* For stacking on smaller screens */
        }
        .button-edit {
            background-color: #007bff; /* Blue */
        }
        .button-delete {
            background-color: #dc3545; /* Red */
        }
        .button-status { /* New style for status button */
            background-color: #17a2b8; /* Teal */
        }
        .button-edit:hover, .button-delete:hover, .button-status:hover {
            opacity: 0.9;
        }
        .add-user-button {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 20px;
            transition: background-color 0.3s ease;
        }

        .add-user-button:hover {
            background-color: #218838;
        }
        .account-details-cell span {
            display: block; /* Make each account detail a new line */
            margin-bottom: 5px;
        }
        .account-details-cell span:last-child {
            margin-bottom: 0;
        }
        /* Styles for account status display */
        .status-active { color: #28a745; font-weight: bold; }
        .status-suspended { color: #ffc107; font-weight: bold; }
        .status-blocked { color: #dc3545; font-weight: bold; }
        .status-restricted { color: #17a2b8; font-weight: bold; }
        .status-closed { color: #6c757d; font-weight: bold; }

    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Manage Users</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <h3>All Bank Users</h3>
            <p class="section-description">Here you can view, edit, or delete user accounts. You can also <a href="create_user.php" class="add-user-button">Create New User</a>.</p>

            <?php if (empty($users)): ?>
                <p>No users found. <a href="create_user.php">Create a new user</a>.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Membership No.</th>
                                <th>Account Status</th> 
                                <th>Common Bank Details</th>
                                <th>Accounts & Balances</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$user['_id']); ?></td>
                                    <td><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['membership_number'] ?? 'N/A'); ?></td>
                                    <td><span class="status-<?php echo strtolower(htmlspecialchars($user['account_status'] ?? 'N/A')); ?>"><?php echo htmlspecialchars(ucfirst($user['account_status'] ?? 'N/A')); ?></span></td>
                                    <td>
                                        <strong>Currency:</strong> <?php echo htmlspecialchars($user['common_currency'] ?? 'N/A'); ?><br>
                                        <?php if (($user['common_sort_code'] ?? 'N/A') !== 'N/A'): ?>
                                            <strong>Sort Code:</strong> <?php echo htmlspecialchars($user['common_sort_code']); ?><br>
                                        <?php endif; ?>
                                        <strong>IBAN:</strong> <?php echo htmlspecialchars($user['common_iban'] ?? 'N/A'); ?><br>
                                        <strong>SWIFT/BIC:</strong> <?php echo htmlspecialchars($user['common_swift_bic'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="account-details-cell">
                                        <?php if ($user['checking_account_number']): ?>
                                            <span><strong>Checking:</strong> <?php echo htmlspecialchars($user['checking_account_number']); ?> (<?php echo htmlspecialchars($user['common_currency']); ?> <?php echo number_format($user['checking_balance'], 2); ?>)</span>
                                        <?php else: ?>
                                            <span>No Checking Account</span>
                                        <?php endif; ?>
                                        <br>
                                        <?php if ($user['savings_account_number']): ?>
                                            <span><strong>Savings:</strong> <?php echo htmlspecialchars($user['savings_account_number']); ?> (<?php echo htmlspecialchars($user['common_currency']); ?> <?php echo number_format($user['savings_balance'], 2); ?>)</span>
                                        <?php else: ?>
                                            <span>No Savings Account</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="button-small button-edit">Edit</a>
                                        <a href="account_status_management.php?user_id=<?php echo $user['id']; ?>" class="button-small button-status">Manage Status</a>
                                        <a href="manage_users.php?action=delete&id=<?php echo $user['id']; ?>" class="button-small button-delete" onclick="return confirm('Are you sure you want to delete this user AND ALL their associated data (accounts, transactions, cards, etc.)? This action cannot be undone.');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p><a href="../dashboard.php" class="back-link">&larr; Back to Admin Dashboard</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>