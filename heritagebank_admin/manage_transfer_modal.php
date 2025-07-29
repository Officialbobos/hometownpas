<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\manage_transfer_modal.php

// Removed session_start() as it should be handled by a central router file.

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
    $message = "Could not load the list of users.";
    $message_type = "error";
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
                    $message = "No custom message found for this user.";
                    $message_type = "info";
                }
            }
            // --- Existing Logic: Handle message saving ---
            else {
                $modalMessage = trim($_POST['modal_message'] ?? '');
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
                    $message = "No changes were made for this user.";
                    $message_type = "info";
                }
            }

            $selectedUserId = $userId; // Keep the selected user in the dropdown

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
}

// Fetch current settings for the selected user to populate the form
if (!empty($selectedUserId)) {
    try {
        $selectedUser = $usersCollection->findOne(['_id' => new ObjectId($selectedUserId)]);
        if ($selectedUser) {
            $modalMessage = $selectedUser['transfer_modal_message'] ?? '';
            $isActive = $selectedUser['show_transfer_modal'] ?? false;
        }
    } catch (MongoDBDriverException $e) {
        $message = "Could not load current settings for the selected user.";
        $message_type = "error";
        error_log("Admin Transfer Modal Load Error: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transfer Modal - Admin</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* ... (Your existing CSS styles remain the same) ... */
        .dashboard-container { display: flex; flex-direction: column; min-height: 100vh; background-color: #fff; margin: 20px; border-radius: 8px; box-shadow: 0 0 15px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .dashboard-header { background-color: #007bff; color: white; padding: 20px 30px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #0056b3; }
        .dashboard-header .logo { max-height: 50px; width: auto; margin-right: 20px; }
        .dashboard-header h2 { margin: 0; font-size: 1.8em; flex-grow: 1; }
        .logout-button { background-color: #dc3545; color: white; padding: 10px 20px; border: none; border-radius: 5px; text-decoration: none; font-weight: bold; transition: background-color 0.3s ease; }
        .logout-button:hover { background-color: #c82333; }
        .dashboard-content { padding: 30px; flex-grow: 1; }
        .dashboard-content h3 { color: #007bff; font-size: 1.6em; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; }
        .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1em;
            resize: vertical;
        }
        .form-group textarea { min-height: 100px; }
        .form-group input[type="checkbox"] { margin-right: 10px; }
        /* Add a style for the new button */
        .form-group .btn-secondary {
            background-color: #6c757d; /* Gray for secondary action */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-left: 10px;
        }
        .form-group .btn-secondary:hover { background-color: #5a6268; }
        
        .form-group .btn-primary {
            background-color: #28a745; /* Green for submit */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .form-group .btn-primary:hover { background-color: #218838; }

        .message-box { padding: 12px 20px; margin-bottom: 20px; border-radius: 6px; text-align: center; font-size: 0.95em; }
        .message-box.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-box.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message-box.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Hometown Bank Logo" class="logo">
            <h2>Manage Transfer Modal Message</h2>
            <a href="<?php echo rtrim(BASE_URL, '/') . '/admin/logout'; ?>" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if ($message): ?>
                <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="manage_transfer_modal" method="GET" style="margin-bottom: 20px;">
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
                <h3>Manage Message for Selected User</h3>
                <form action="" method="POST">
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
                        <button type="submit" name="remove_message" class="btn-secondary">Remove Message</button>
                    </div>
                </form>
            <?php else: ?>
                <p>Please select a user from the dropdown above to manage their transfer modal message.</p>
            <?php endif; ?>

            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/admin" style="display: inline-block; margin-top: 20px; color: #007bff; text-decoration: none;">&larr; Back to Dashboard</a></p>
        </div>
    </div>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/script.js"></script>
</body>
</html>