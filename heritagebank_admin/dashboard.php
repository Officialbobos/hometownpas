<?php
session_start(); // Start the session

// Include necessary files for MongoDB connection
require_once '../Config.php';
require_once '../functions.php'; // This should contain getMongoDBClient() and getCollection()

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$admin_username = $_SESSION['admin_username'] ?? 'Admin User'; // Get logged-in username

// Initialize statistics variables
$totalUsers = 'N/A';
$pendingApprovals = 'N/A';
$dailyTransactions = 'N/A';
$systemHealth = 'Optimal'; // This might be harder to get directly from DB, keep as static for now or implement deeper checks

try {
    $client = getMongoDBClient();
    $usersCollection = getCollection('users', $client);
    $transactionsCollection = getCollection('transactions', $client);

    // Fetch Total Users
    $totalUsers = $usersCollection->countDocuments([]);

    // Fetch Pending Approvals
    $pendingApprovals = $transactionsCollection->countDocuments(['status' => 'pending']);

    // Fetch Daily Transactions
    // For daily transactions, we need to consider the 'initiated_at' or 'transaction_date' field.
    // Assuming 'initiated_at' is a UTCDateTime object in MongoDB.
    // We need to query for documents where the date part matches today's date.
    $startOfDay = new MongoDB\BSON\UTCDateTime(strtotime('today midnight') * 1000);
    $endOfDay = new MongoDB\BSON\UTCDateTime(strtotime('tomorrow midnight') * 1000);

    $dailyTransactions = $transactionsCollection->countDocuments([
        'initiated_at' => [
            '$gte' => $startOfDay,
            '$lt' => $endOfDay
        ]
    ]);

} catch (MongoDB\Driver\Exception\Exception $e) {
    // Log the error for debugging
    error_log("MongoDB Dashboard Statistics Error: " . $e->getMessage());
    // Fallback or display an error message on the dashboard
    $totalUsers = 'Error';
    $pendingApprovals = 'Error';
    $dailyTransactions = 'Error';
    $systemHealth = 'Degraded (DB Error)';
    $_SESSION['admin_message'] = "Could not fetch dashboard statistics due to a database error.";
    $_SESSION['admin_message_type'] = "error";
} catch (Exception $e) {
    error_log("General Dashboard Statistics Error: " . $e->getMessage());
    $totalUsers = 'Error';
    $pendingApprovals = 'Error';
    $dailyTransactions = 'Error';
    $systemHealth = 'Degraded (App Error)';
    $_SESSION['admin_message'] = "An unexpected error occurred while fetching dashboard statistics.";
    $_SESSION['admin_message_type'] = "error";
}

// Check for and display admin messages (e.g., from transfer_process.php)
$admin_message = '';
$admin_message_type = '';
if (isset($_SESSION['admin_message'])) {
    $admin_message = $_SESSION['admin_message'];
    $admin_message_type = $_SESSION['admin_message_type'];
    unset($_SESSION['admin_message']); // Clear the message after displaying
    unset($_SESSION['admin_message_type']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="HomeTown Bank Logo" class="logo">
            <h2>Welcome, <?php echo htmlspecialchars($admin_username); ?>!</h2>
            <a href="logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($admin_message)): ?>
                <div class="message-box <?php echo htmlspecialchars($admin_message_type); ?>">
                    <?php echo htmlspecialchars($admin_message); ?>
                </div>
            <?php endif; ?>

            <h3>Admin Overview</h3>
            <p>This is your secure admin dashboard. Here you can manage users, transactions, reports, and more.</p>
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>Total Users</h4>
                    <p><?php echo htmlspecialchars($totalUsers); ?></p>
                </div>
                <div class="stat-card">
                    <h4>Pending Approvals</h4>
                    <p><?php echo htmlspecialchars($pendingApprovals); ?></p>
                </div>
                <div class="stat-card">
                    <h4>Daily Transactions</h4>
                    <p><?php echo htmlspecialchars($dailyTransactions); ?></p>
                </div>
                <div class="stat-card">
                    <h4>System Health</h4>
                    <p><?php echo htmlspecialchars($systemHealth); ?></p>
                </div>
            </div>

            <nav class="dashboard-nav">
                <ul>
                    <li><a href="users/users_management.php">User Management</a></li>
                    <li><a href="transactions_history.php">Transaction History</a></li>
                    <li><a href="#">Reports & Analytics</a></li>
                    <li><a href="#">System Settings</a></li>
                    <li><a href="transfer_approvals.php">Transfer Approvals</a></li> </ul>
            </nav>
        </div>
    </div>
    <script src="script.js"></script>
    <script>
        // Optional: JavaScript to fade out messages after a few seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messageBox = document.querySelector('.message-box');
            if (messageBox && messageBox.textContent.trim() !== '') {
                if (messageBox.classList.contains('success') || messageBox.classList.contains('warning')) {
                    setTimeout(() => {
                        messageBox.style.opacity = '0';
                        setTimeout(() => {
                            messageBox.style.display = 'none';
                        }, 500); // Wait for fade-out transition
                    }, 5000); // Hide after 5 seconds
                }
            }
        });
    </script>
</body>
</html>