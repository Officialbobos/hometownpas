<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\manage_transfer_modal.php

// Ensure session is started at the very beginning
// Assuming a central router or index.php handles session_start()
// If not, uncomment the line below:
// session_start(); 

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For getCollection()

use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Corrected authentication check to look for 'admin_logged_in'
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/heritagebank_admin/index.php');
    exit;
}

$usersCollection = getCollection('users');
$modalMessage = '';
$isActive = false;
$message = '';
$message_type = '';

// Fetch all users for the dropdown
$allUsers = [];
try {
    $allUsers = $usersCollection->find(['status' => 'active'], ['sort' => ['first_name' => 1]]);
    $allUsers = iterator_to_array($allUsers);
} catch (MongoDBDriverException $e) {
    $message = "Could not load the list of users: " . $e->getMessage();
    $message_type = "error";
    error_log("MongoDB User Fetch Error: " . $e->getMessage());
}

$selectedUserId = $_GET['user_id'] ?? null; // Get the user ID from the URL

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'] ?? null;

    if (!empty($userId)) {
        try {
            // --- NEW LOGIC: Handle message removal ---
            if (isset($_POST['remove_message'])) {
                $updateResult = $usersCollection->updateOne(
                    ['_id' => new ObjectId($userId)],
                    // Use $unset to completely remove the fields
                    ['$unset' => [
                        'transfer_modal_message' => '',
                        'show_transfer_modal' => ''
                    ]]
                );

                if ($updateResult->getModifiedCount() > 0) {
                    $message = "Transfer modal message removed successfully.";
                    $message_type = "success";
                    // Reset variables to clear the form
                    $modalMessage = '';
                    $isActive = false;
                } else {
                    $message = "No custom message found or no changes made for this user.";
                    $message_type = "info";
                }
            }
            // --- Existing Logic: Handle message saving ---
            else {
                $modalMessage = trim($_POST['modal_message'] ?? '');
                // Ensure checkbox value is correctly interpreted
                $isActive = isset($_POST['is_active']) && $_POST['is_active'] === 'on';

                $updateResult = $usersCollection->updateOne(
                    ['_id' => new ObjectId($userId)],
                    ['$set' => [
                        'transfer_modal_message' => $modalMessage,
                        'show_transfer_modal' => $isActive
                    ]]
                );

                if ($updateResult->getModifiedCount() > 0) {
                    $message = "Transfer modal message for user updated successfully.";
                    $message_type = "success";
                } else {
                    $message = "No changes were made or message was already set to the same value.";
                    $message_type = "info";
                }
            }

            $selectedUserId = $userId; // Keep the selected user in the dropdown after POST

        } catch (MongoDBDriverException $e) {
            $message = "Database error: " . $e->getMessage();
            $message_type = "error";
            error_log("Admin Transfer Modal Update Error: " . $e->getMessage());
        } catch (Exception $e) {
            $message = "An unexpected error occurred: " . $e->getMessage();
            $message_type = "error";
            error_log("Admin Transfer Modal Update General Error: " . $e->getMessage());
        }
    } else {
        $message = "Please select a user to update.";
        $message_type = "error";
    }
    // Redirect to self to prevent form resubmission on refresh
    header('Location: manage_transfer_modal.php?user_id=' . urlencode($selectedUserId ?? ''));
    exit;
}

// Fetch current settings for the selected user to populate the form
if (!empty($selectedUserId)) {
    try {
        $selectedUser = $usersCollection->findOne(['_id' => new ObjectId($selectedUserId)]);
        if ($selectedUser) {
            $modalMessage = $selectedUser['transfer_modal_message'] ?? '';
            // Ensure boolean conversion for checkbox
            $isActive = (bool)($selectedUser['show_transfer_modal'] ?? false);
        } else {
            // User not found, clear selected ID and message
            $selectedUserId = null;
            $modalMessage = '';
            $isActive = false;
            $message = "Selected user not found or is inactive.";
            $message_type = "error";
        }
    } catch (MongoDBDriverException $e) {
        $message = "Could not load current settings for the selected user: " . $e->getMessage();
        $message_type = "error";
        error_log("Admin Transfer Modal Load Error: " . $e->getMessage());
    } catch (Exception $e) {
        $message = "An unexpected error occurred while loading user settings: " . $e->getMessage();
        $message_type = "error";
        error_log("Admin Transfer Modal Load General Error: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transfer Modal - Admin</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/admin_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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

        .admin-container {
            display: flex;
            flex: 1; /* Allows it to take up remaining space */
            width: 100%;
            max-width: 1400px; /* Max width for content */
            margin: 0 auto;
            box-sizing: border-box;
        }

        /* Admin Header */
        .admin-header {
            background-color: #004494; /* Heritage Blue */
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .admin-header .logo {
            height: 35px; /* Adjusted logo size */
            width: auto;
            margin-right: 15px;
        }
        .admin-header h1 {
            margin: 0;
            font-size: 1.5em; /* Adjusted font size */
            color: white;
            flex-grow: 1; /* Allows title to take available space */
        }
        .admin-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .admin-info span {
            font-size: 1em;
        }
        .admin-info a {
            background-color: #ffcc29; /* Heritage Gold */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .admin-info a:hover {
            background-color: #e0b821;
        }

        /* Admin Sidebar */
        .admin-sidebar {
            width: 250px;
            background-color: #ffffff;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
            flex-shrink: 0; /* Prevent shrinking */
        }
        .admin-sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .admin-sidebar ul li {
            margin-bottom: 10px;
        }
        .admin-sidebar ul li a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .admin-sidebar ul li a:hover {
            background-color: #e9ecef;
            color: #004494;
        }
        .admin-sidebar ul li a.active {
            background-color: #004494;
            color: white;
        }

        /* Main Content Area */
        .admin-main-content {
            flex-grow: 1; /* Takes up remaining space */
            padding: 30px;
            background-color: #f4f7f6;
        }

        .section-header {
            color: #004494;
            margin-bottom: 25px;
            font-size: 2em;
            font-weight: 700;
        }

        /* Messages */
        .message-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            word-wrap: break-word;
            border: 1px solid transparent;
        }
        .message-box.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        .message-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        .message-box.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
        }

        /* Form Styling */
        form {
            background-color: #ffffff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
            font-size: 1em;
            resize: vertical; /* Allow vertical resizing for textareas */
            min-height: 100px; /* Minimum height for textarea */
            max-width: 100%; /* Ensure it doesn't overflow */
        }

        .form-group input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.2); /* Make checkbox slightly larger */
            vertical-align: middle;
        }

        .form-group .btn-primary,
        .form-group .btn-secondary {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-right: 10px; /* Space between buttons */
            display: inline-block; /* Allow them to sit side-by-side */
        }

        .form-group .btn-primary {
            background-color: #004494; /* Heritage Blue */
            color: white;
        }

        .form-group .btn-primary:hover {
            background-color: #003366;
        }

        .form-group .btn-secondary {
            background-color: #6c757d; /* Gray for secondary action */
            color: white;
        }

        .form-group .btn-secondary:hover {
            background-color: #5a6268;
        }

        /* Responsive Design */
        @media (max-width: 992px) { /* Tablets and smaller desktops */
            .admin-container {
                flex-direction: column; /* Stack sidebar and main content */
            }
            .admin-sidebar {
                width: 100%; /* Sidebar takes full width */
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); /* Shadow on bottom */
                padding: 10px 0;
            }
            .admin-sidebar ul {
                display: flex; /* Arrange links horizontally */
                flex-wrap: wrap; /* Allow wrapping */
                justify-content: center; /* Center links */
                gap: 5px; /* Space between links */
            }
            .admin-sidebar ul li {
                margin-bottom: 0; /* Remove vertical margin */
            }
            .admin-sidebar ul li a {
                padding: 8px 12px; /* Smaller padding for links */
                font-size: 0.9em;
            }
            .admin-main-content {
                padding: 20px;
            }
        }

        @media (max-width: 768px) { /* Smaller tablets and mobile */
            .admin-header {
                flex-direction: column;
                padding: 10px 15px;
                gap: 10px;
            }
            .admin-header h1 {
                font-size: 1.3em;
            }
            .admin-info {
                width: 100%;
                justify-content: center;
            }
            .admin-info a {
                padding: 6px 10px;
                font-size: 0.9em;
            }
            /* Adjust form button stacking on small screens */
            .form-group .btn-primary,
            .form-group .btn-secondary {
                display: block;
                width: 100%;
                margin-right: 0;
                margin-bottom: 10px;
            }
            .form-group .btn-secondary {
                margin-left: 0; /* Remove margin-left when stacked */
            }
        }

        @media (max-width: 480px) { /* Extra small devices */
            .admin-main-content {
                padding: 15px;
            }
            .section-header {
                font-size: 1.6em;
            }
            form {
                padding: 15px;
            }
            .admin-sidebar ul {
                flex-direction: column; /* Stack links vertically on tiny screens */
            }
            .admin-sidebar ul li a {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <img src="https://i.imgur.com/UeqGGSn.png" alt="Heritage Bank Logo" class="logo">
        <h1>Admin Dashboard</h1>
        <div class="admin-info">
            <span>Welcome, Admin!</span> <a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/admin_logout.php'; ?>">Logout</a>
        </div>
    </header>

    <div class="admin-container">
        <nav class="admin-sidebar">
            <ul>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/create_user.php'; ?>">Create New User</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_users.php'; ?>">Manage Users (Edit/Delete)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/users/manage_user_funds.php'; ?>">Manage User Funds (Credit/Debit)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/account_status_management.php'; ?>">Manage Account Status</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/transactions_management.php'; ?>">Transactions Management</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/generate_bank_card.php'; ?>">Generate Bank Card (Mock)</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/generate_mock_transaction.php'; ?>">Generate Mock Transaction</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/heritagebank_admin/manage_transfer_modal.php'; ?>" class="active">Manage Transfer Modal</a></li>
            </ul>
        </nav>

        <main class="admin-main-content">
            <h1 class="section-header">Manage Transfer Modal Message</h1>

            <?php if ($message): ?>
                <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="manage_transfer_modal.php" method="GET">
                <div class="form-group">
                    <label for="user_id">Select User to Manage:</label>
                    <select id="user_id" name="user_id" onchange="this.form.submit()">
                        <option value="">-- Select a User --</option>
                        <?php foreach ($allUsers as $user): ?>
                            <option value="<?php echo htmlspecialchars((string)$user['_id']); ?>"
                                <?php echo ($selectedUserId === (string)$user['_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <hr style="border-top: 1px solid #ddd; margin: 30px 0;">

            <?php if (!empty($selectedUserId)): ?>
                <h3>Settings for User: <?php echo htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name']); ?></h3>
                <form action="manage_transfer_modal.php" method="POST">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($selectedUserId); ?>">
                    
                    <div class="form-group">
                        <label for="modal_message">Modal Message Content:</label>
                        <textarea id="modal_message" name="modal_message" rows="5" placeholder="Enter the message to display to this user."><?php echo htmlspecialchars($modalMessage); ?></textarea>
                    </div>
                    <div class="form-group">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo $isActive ? 'checked' : ''; ?>>
                        <label for="is_active" style="display: inline-block;">Display Message to this User</label>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="save_message" class="btn-primary">Save Settings</button>
                        <button type="submit" name="remove_message" class="btn-secondary" onclick="return confirm('Are you sure you want to remove the custom message for this user?');">Remove Message</button>
                    </div>
                </form>
            <?php else: ?>
                <p>Please select a user from the dropdown above to manage their transfer modal message.</p>
            <?php endif; ?>

            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/admin_dashboard.php" style="display: inline-block; margin-top: 20px; color: #004494; text-decoration: none;">&larr; Back to Dashboard</a></p>
        </main>
    </div>
</body>
</html>