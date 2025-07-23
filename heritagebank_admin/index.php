<?php
session_start(); // Start the session at the very beginning

// Include necessary files for MongoDB connection and potentially password hashing if needed
require_once '../Config.php';
require_once '../functions.php'; // This should contain getMongoDBClient() and getCollection()

// Check if the admin is already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            $client = getMongoDBClient(); // Get the MongoDB client instance
            $adminsCollection = getCollection('admins', $client); // Get the 'admins' collection

            // Find the admin user by username (email)
            $adminUser = $adminsCollection->findOne(['email' => $username]);

            if ($adminUser && password_verify($password, $adminUser['password_hash'])) {
                // Authentication successful
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $username;
                $_SESSION['admin_id'] = (string)$adminUser['_id']; // Store MongoDB ObjectId as string in session
                header('Location: dashboard.php'); // Redirect to the admin dashboard
                exit;
            } else {
                // Authentication failed
                $error_message = 'Invalid username or password. Please try again.';
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            // Log the error for debugging (e.g., connection issues, authentication issues with MongoDB)
            error_log("MongoDB Admin Login Error: " . $e->getMessage());
            $error_message = 'An error occurred during login. Please try again later.';
        } catch (Exception $e) {
            // Catch any other unexpected errors
            error_log("General Admin Login Error: " . $e->getMessage());
            $error_message = 'An unexpected error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank Admin Login</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Bank Logo" class="logo">
            <h2>Admin Login</h2>
        </div>
        <form action="index.php" method="POST" class="login-form" id="loginForm">
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username / Email</label>
                <input type="text" id="username" name="username" placeholder="Enter your admin email" required value="<?php echo htmlspecialchars($username ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="login-button">Login</button>
        </form>
    </div>
    <script src="script.js"></script>
</body>
</html>