<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\dashboard.php
session_start(); // Start the session

// --- REMOVE TEMPORARY DEBUG CODE FOR INI_SET & ERROR_REPORTING ---
// These are now handled by Config.php based on APP_DEBUG.
// ini_set('display_errors', 1); // For development: Display all errors
// ini_set('display_startup_errors', 1); // For development: Display startup errors
// error_reporting(E_ALL); // For development: Report all errors

// Load Composer's autoloader for MongoDB classes and Dotenv
require_once __DIR__ . '/../vendor/autoload.php';

// Include necessary files for MongoDB connection and utility functions
// Config.php should be loaded before functions.php if functions rely on its constants.
require_once __DIR__ . '/../Config.php'; // This defines APP_DEBUG and error settings
require_once __DIR__ . '/../functions.php'; // This should contain getMongoDBClient() and getCollection()

use MongoDB\BSON\UTCDateTime; // Ensure UTCDateTime is used for date handling
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // For general MongoDB driver exceptions

// Check if the admin is NOT logged in, redirect to login page
// Using $_SESSION['admin_user_id'] as the primary indicator for admin login
// Assuming 'index.php' in the current directory is your admin login page.
// If your admin login is through the main index.php router (e.g., 'admin/login'),
// you might need to use BASE_URL here: header('Location: ' . BASE_URL . '/admin/login');
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

// Get logged-in admin's full name for display
$admin_full_name = $_SESSION['admin_full_name'] ?? 'Admin User';

// Initialize statistics variables
$totalUsers = 'N/A';
$pendingApprovals = 'N/A';
$dailyTransactions = 'N/A';
$systemHealth = 'Optimal'; // This is static; implement deeper checks if dynamic status is needed

try {
    // Get MongoDB collections using the streamlined getCollection() function
    $usersCollection = getCollection('users');
    $transactionsCollection = getCollection('transactions');

    // Fetch Total Users (excluding admin users if desired, but general count is fine for dashboard)
    $totalUsers = $usersCollection->countDocuments([]); // Counts all users
    // If you want to exclude admins: $totalUsers = $usersCollection->countDocuments(['is_admin' => ['$ne' => true]]);

    // Fetch Pending Approvals (e.g., transactions with 'pending' status)
    $pendingApprovals = $transactionsCollection->countDocuments(['status' => 'pending']);

    // Fetch Daily Transactions
    // Get the start and end of the current day in UTC for comparison with MongoDB's UTCDateTime
    // The server's timezone might affect strtotime, but MongoDB's UTCDateTime handles conversion.
    // For consistency, consider storing local time and UTC time or just UTC.
    $startOfDay = new UTCDateTime(strtotime('today midnight', time()) * 1000); // Current day, 00:00:00 UTC
    $endOfDay = new UTCDateTime(strtotime('tomorrow midnight', time()) * 1000);   // Next day, 00:00:00 UTC (exclusive)

    $dailyTransactions = $transactionsCollection->countDocuments([
        'initiated_at' => [ // Assuming 'initiated_at' field stores UTCDateTime for transactions
            '$gte' => $startOfDay,
            '$lt' => $endOfDay
        ]
    ]);

} catch (MongoDBDriverException $e) { // Catch specific MongoDB driver exceptions
    // Log the error for debugging purposes
    error_log("MongoDB Dashboard Statistics Error: " . $e->getMessage());
    // Fallback values and set a user-friendly error message
    $totalUsers = 'Error';
    $pendingApprovals = 'Error';
    $dailyTransactions = 'Error';
    $systemHealth = 'Degraded (DB Error)';
    $_SESSION['admin_message'] = "Could not fetch dashboard statistics due to a database error.";
    $_SESSION['admin_message_type'] = "error";
} catch (Exception $e) { // Catch any other general exceptions
    error_log("General Dashboard Statistics Error: " . $e->getMessage());
    $totalUsers = 'Error';
    $pendingApprovals = 'Error';
    $dailyTransactions = 'Error';
    $systemHealth = 'Degraded (Application Error)'; // More descriptive error for app issues
    $_SESSION['admin_message'] = "An unexpected error occurred while fetching dashboard statistics.";
    $_SESSION['admin_message_type'] = "error";
}

// Check for and display one-time admin messages (e.g., from transfer_process.php or other admin actions)
$admin_message = '';
$admin_message_type = '';
if (isset($_SESSION['admin_message'])) {
    $admin_message = $_SESSION['admin_message'];
    $admin_message_type = $_SESSION['admin_message_type'];
    unset($_SESSION['admin_message']); // Clear the message after displaying it
    unset($_SESSION['admin_message_type']); // Clear the message type
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General Body Styles */
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6;
            color: #333;
        }

        /* Dashboard Container */
        .dashboard-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #fff;
            margin: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            overflow: hidden; /* Clear floats */
        }

        /* Dashboard Header */
        .dashboard-header {
            background-color: #007bff; /* Primary blue for header */
            color: white;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #0056b3;
        }

        .dashboard-header .logo {
            max-height: 50px; /* Adjust logo size */
            width: auto;
            margin-right: 20px;
        }

        .dashboard-header h2 {
            margin: 0;
            font-size: 1.8em;
            flex-grow: 1; /* Allows h2 to take available space */
        }

        .logout-button {
            background-color: #dc3545; /* Red for logout */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .logout-button:hover {
            background-color: #c82333;
        }

        /* Dashboard Content */
        .dashboard-content {
            padding: 30px;
            flex-grow: 1; /* Allows content to expand */
        }

        .dashboard-content h3 {
            color: #007bff;
            font-size: 1.6em;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }

        .dashboard-content p {
            color: #555;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        /* Message Box Styling */
        .message-box {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.95em;
            transition: opacity 0.5s ease-out; /* For JS fade out */
        }
        .message-box.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message-box.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }


        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .stat-card h4 {
            color: #0056b3;
            font-size: 1.2em;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .stat-card p {
            font-size: 2em;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        /* Dashboard Navigation */
        .dashboard-nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .dashboard-nav li {
            background-color: #e9ecef;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .dashboard-nav li:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            background-color: #dee2e6;
        }

        .dashboard-nav a {
            display: block;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #007bff;
            font-weight: 600;
            font-size: 1.1em;
        }

        .dashboard-nav a:hover {
            color: #0056b3;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                text-align: center;
            }
            .dashboard-header .logo {
                margin-bottom: 15px;
                margin-right: 0;
            }
            .dashboard-header h2 {
                margin-bottom: 15px;
            }
            .stats-grid, .dashboard-nav ul {
                grid-template-columns: 1fr; /* Stack columns on smaller screens */
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                margin: 10px;
                padding: 15px;
            }
            .dashboard-header {
                padding: 15px;
            }
            .dashboard-content {
                padding: 20px;
            }
            .dashboard-header h2 {
                font-size: 1.5em;
            }
            .stat-card h4 {
                font-size: 1em;
            }
            .stat-card p {
                font-size: 1.8em;
            }
            .dashboard-nav a {
                font-size: 1em;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Hometown Bank Logo" class="logo">
            <h2>Welcome, <?php echo htmlspecialchars($admin_full_name); ?>!</h2>
        <a href="<?php echo rtrim(BASE_URL, '/') . '/admin/logout'; ?>" class="logout-button">Logout</a>
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
                <button onclick="alert('System Health check not yet implemented.');" style="background-color: #007bff; color: white; border: none; padding: 8px 12px; border-radius: 5px; cursor: pointer; margin-top: 10px;">Details</button>
                </div>
            </div>

            <nav class="dashboard-nav">
                <ul>
                    <li><a href="<?php echo rtrim(BASE_URL, '/') . '/admin/users'; ?>">User Management</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/') . '/admin/transactions'; ?>">Transaction History</a></li>
                    <li><a href="#">Reports & Analytics</a></li>
                    <li><a href="#">System Settings</a></li>
                    <li><a href="#">Transfer Approvals</a></li>
                    <li> <a href="<?php echo rtrim(BASE_URL, '/'); ?>/admin/manage_modals">Manage Transfer Modal</a></li>

                </ul>
            </nav>
        </div>
    </div>
    <script src="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/script.js'; ?>"></script>
    <script>
        // Optional: JavaScript to fade out messages after a few seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messageBox = document.querySelector('.message-box');
            if (messageBox && messageBox.textContent.trim() !== '') {
                // Only fade out success and warning messages
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