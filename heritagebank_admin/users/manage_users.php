<?php
session_start();
require_once '../../Config.php'; // Adjust path
require_once '../../functions.php'; // This is good to have for future database operations

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$message = '';
$message_type = '';

// --- MongoDB Connection ---
try {
    // MONGO_URI and MONGO_DB_NAME should be defined in Config.php
    $client = new MongoDB\Client(MONGODB_CONNECTION_URI);   
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
    $transactionsCollection = $database->selectCollection('transactions');
    $bankCardsCollection = $database->selectCollection('bank_cards');
    $accountStatusHistoryCollection = $database->selectCollection('account_status_history');
} catch (MongoDB\Driver\Exception\Exception $e) {
    error_log("MongoDB connection error: " . $e->getMessage());
    die("ERROR: Could not connect to database. Please try again later.");
}

// --- Handle Delete Action ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $user_id_to_delete_str = $_GET['id']; // MongoDB _id is a string/ObjectId
    
    try {
        // Convert string ID to MongoDB\BSON\ObjectId
        $user_id_to_delete = new MongoDB\BSON\ObjectId($user_id_to_delete_str);

        // Optional: Get profile image path before deleting user to remove the file
        $profile_image_path_to_delete = null;
        $user_to_delete = $usersCollection->findOne(['_id' => $user_id_to_delete]);
        if ($user_to_delete && isset($user_to_delete['profile_image'])) {
            $profile_image_path_to_delete = $user_to_delete['profile_image'];
        }

        // Delete related records from 'transactions' collection
        $transactionsCollection->deleteMany(['user_id' => $user_id_to_delete]);
        
        // Delete related records from 'bank_cards' collection
        $bankCardsCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Delete associated records from 'account_status_history' collection
        $accountStatusHistoryCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Delete accounts associated with the user
        $accountsCollection->deleteMany(['user_id' => $user_id_to_delete]);

        // Finally, delete the user record
        $deleteUserResult = $usersCollection->deleteOne(['_id' => $user_id_to_delete]);

        if ($deleteUserResult->getDeletedCount() === 1) {
            $message = "User and all associated data deleted successfully. 🎉";
            $message_type = 'success';

            // Delete profile image file from server if it exists
            // Adjust the path relative to your project root if 'profile_images' is not directly under it
            if ($profile_image_path_to_delete && file_exists('../../' . $profile_image_path_to_delete)) {
                unlink('../../' . $profile_image_path_to_delete);
            }
        } else {
            $message = "User not found or already deleted.";
            $message_type = 'error';
        }

    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        $message = "Invalid user ID provided for deletion.";
        $message_type = 'error';
        error_log("Invalid user ID for deletion: " . $_GET['id']);
    } catch (Exception $e) {
        $message = "Error deleting user: " . $e->getMessage();
        $message_type = 'error';
        error_log("User deletion error (MongoDB): " . $e->getMessage()); // Log the error for debugging
    }
    
    // Redirect to prevent re-submission on refresh and display message
    header('Location: manage_users.php?message=' . urlencode($message) . '&type=' . urlencode($message_type));
    exit;
}

// Re-fetch message if it came from a redirect after an action (like delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}


// --- Fetch Users (for display) ---
$users = [];
try {
    // Find all users
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
        $primaryAccount = $checkingAccount ?: $savingsAccount; // Use checking if exists, else savings
        $user['common_currency'] = $primaryAccount['currency'] ?? 'N/A';
        $user['common_sort_code'] = $primaryAccount['sort_code'] ?? 'N/A';
        $user['common_iban'] = $primaryAccount['iban'] ?? 'N/A';
        $user['common_swift_bic'] = $primaryAccount['swift_bic'] ?? 'N/A';
        
        // Ensure _id is converted to string for HTML links if it's not already
        $user['id'] = (string) $user['_id']; 

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