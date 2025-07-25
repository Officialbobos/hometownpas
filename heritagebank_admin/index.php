<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\index.php

// 1. Load your core configuration first.
require_once __DIR__ . '/../Config.php';

// 2. Load your functions file.
require_once __DIR__ . '/../functions.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

$message = '';
$message_type = ''; // 'success', 'error', 'warning'

// If admin is already logged in (session is active)
if (isset($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php'); // Redirect to admin dashboard
    exit();
}

// Handle admin login form submission
if (isset($_POST['admin_login'])) {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Passwords are not sanitized with htmlspecialchars

    if (empty($email) || empty($password)) {
        $message = "Please enter both email and password.";
        $message_type = 'error';
    } else {
        try {
            // Get the 'admin' collection
            $adminCollection = getCollection('admin'); // CORRECTED LINE

            // Find admin user by email and ensure they are 'active'
            $admin_user = $adminCollection->findOne([
                'email' => $email,
                'status' => 'active'
            ]);


            if ($admin_user) {
                // Verify password
                if (isset($admin_user['password']) && password_verify($password, $admin_user['password'])) {
                    // Password is correct, admin is authenticated
                    $_SESSION['admin_user_id'] = (string)$admin_user['_id']; // Store as string for session
                    $_SESSION['admin_email'] = $admin_user['email'];
                    // Use first_name and last_name from the admin document
                    $_SESSION['admin_full_name'] = trim(($admin_user['first_name'] ?? '') . ' ' . ($admin_user['last_name'] ?? ''));
                    $_SESSION['is_admin'] = true; // Explicitly mark as admin in session

                    // Redirect to admin dashboard
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $message = "Invalid email or password.";
                    $message_type = 'error';
                }
            } else {
                $message = "Invalid email or password."; // Keep message generic for security
                $message_type = 'error';
            }
        } catch (Exception $e) {
            error_log("Admin login error: " . $e->getMessage());
            $message = "An unexpected error occurred during login. Please try again later.";
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Specific styles for admin login page */
        body {
            background-color: #f0f2f5; /* Lighter background for admin */
            font-family: 'Inter', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            flex-direction: column;
        }
        .admin-login-container {
            background-color: #fff;
            padding: 35px 45px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        .admin-login-container h2 {
            font-size: 1.8em;
            color: #333;
            margin-bottom: 25px;
        }
        .admin-logo {
            margin-bottom: 30px;
        }
        .admin-logo img {
            max-width: 150px;
            height: auto;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .input-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 0.9em;
        }
        .input-wrapper input {
            width: calc(100% - 24px);
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        .input-wrapper input:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .message-box {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.95em;
        }
        .message-box.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        footer {
            margin-top: 30px;
            color: #777;
            font-size: 0.85em;
            text-align: center;
        }
        .footer-links a {
            color: #007bff;
            text-decoration: none;
            margin: 0 10px;
        }
        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-login-container">
        <div class="admin-logo">
            <img src="https://i.imgur.com/UeqGGSn.png" alt="Hometown Bank Logo"> </div>
        <h2>Admin Login</h2>

        <?php if (!empty($message)): ?>
            <div id="login-message" class="message-box <?php echo htmlspecialchars($message_type); ?>" style="display: block;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php else: ?>
            <div id="login-message" class="message-box" style="display: none;"></div>
        <?php endif; ?>

        <form class="admin-login-form" id="adminLoginForm" action="index.php" method="POST">
            <div class="form-group">
                <label for="email" class="sr-only">Email</label>
                <p class="input-label">Email</p>
                <div class="input-wrapper">
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="sr-only">Password</label>
                <p class="input-label">Password</p>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" required>
                </div>
            </div>

            <div class="buttons-group">
                <button type="submit" name="admin_login" class="btn btn-primary">Log In</button>
            </div>
        </form>
    </div>

    <footer>
        <p>&copy; 2025 Hometown Bank. All rights reserved.</p>
        <div class="footer-links">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

</body>
</html>