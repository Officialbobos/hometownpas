<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\manage_transfer_modal.php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For getCollection()

use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Check if the admin is NOT logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: ' . BASE_URL . '/admin/index');
    exit;
}

$settingsCollection = getCollection('app_settings');
$modalMessage = '';
$isActive = false;
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modalMessage = trim($_POST['modal_message'] ?? '');
    $isActive = isset($_POST['is_active']) && $_POST['is_active'] === 'on';

    try {
        $updateResult = $settingsCollection->updateOne(
            ['setting_name' => 'transfer_modal_message'],
            ['$set' => [
                'content' => $modalMessage,
                'is_active' => $isActive,
                'last_updated' => new UTCDateTime(new DateTime()) // Current UTC time
            ]],
            ['upsert' => true] // Create the document if it doesn't exist
        );

        if ($updateResult->getModifiedCount() > 0 || $updateResult->getUpsertedCount() > 0) {
            $message = "Transfer modal message updated successfully.";
            $message_type = "success";
        } else {
            $message = "No changes were made to the transfer modal message.";
            $message_type = "info";
        }
    } catch (MongoDBDriverException $e) {
        $message = "Database error: " . $e->getMessage();
        $message_type = "error";
        error_log("Admin Transfer Modal Update Error: " . $e->getMessage());
    } catch (Exception $e) {
        $message = "An unexpected error occurred: " . $e->getMessage();
        $message_type = "error";
        error_log("Admin Transfer Modal Update General Error: " . $e->getMessage());
    }
}

// Fetch current settings for display
try {
    $settings = $settingsCollection->findOne(['setting_name' => 'transfer_modal_message']);
    if ($settings) {
        $modalMessage = $settings['content'] ?? '';
        $isActive = $settings['is_active'] ?? false;
    }
} catch (MongoDBDriverException $e) {
    $message = "Could not load current settings.";
    $message_type = "error";
    error_log("Admin Transfer Modal Load Error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transfer Modal - Admin</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Existing dashboard.php styles should be either in style.css or copied here */
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; background-color: #f4f7f6; color: #333; }
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
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-sizing: border-box; /* Include padding in width */
            font-size: 1em;
            min-height: 100px;
            resize: vertical;
        }
        .form-group input[type="checkbox"] { margin-right: 10px; }
        .form-group button {
            background-color: #28a745; /* Green for submit */
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .form-group button:hover { background-color: #218838; }

        /* Message Box Styling */
        .message-box {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.95em;
        }
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
            <a href="<?php echo BASE_URL; ?>/admin/logout" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if ($message): ?>
                <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="form-group">
                    <label for="modal_message">Modal Message Content:</label>
                    <textarea id="modal_message" name="modal_message" rows="5" placeholder="Enter the message to display to users regarding transfers."><?php echo htmlspecialchars($modalMessage); ?></textarea>
                </div>
                <div class="form-group">
                    <input type="checkbox" id="is_active" name="is_active" <?php echo $isActive ? 'checked' : ''; ?>>
                    <label for="is_active" style="display: inline-block;">Display Message on User Dashboard</label>
                </div>
                <div class="form-group">
                    <button type="submit">Save Settings</button>
                </div>
            </form>

            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/admin" style="display: inline-block; margin-top: 20px; color: #007bff; text-decoration: none;">&larr; Back to Dashboard</a></p>
        </div>
    </div>
        <script src="<?php echo rtrim(BASE_URL, characters: '/'); ?>/heritagebank_admin/script.js"></script>

</body>
</html>