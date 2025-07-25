<?php
// This is frontend/login.php

// Initialize message and message type variables
$message = '';
$message_type = '';

// Check if the login form was submitted via POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Sanitize and retrieve input data
    $lastName = trim($_POST['last_name'] ?? '');
    $membershipNumber = trim($_POST['membership_number'] ?? '');

    // --- YOUR ACTUAL LOGIN AUTHENTICATION LOGIC GOES HERE ---
    // This is where you would typically query your database to check credentials.
    // Example: You might have a function like 'verifyUserCredentials' in your functions.php
    // require_once __DIR__ . '/../Database.php'; // If your database connection is not already global
    // require_once __DIR__ . '/../AuthService.php'; // Or a specific authentication service file

    // Example placeholder: Replace this with real authentication
    if ($lastName === 'your_test_lastname' && $membershipNumber === '123456789012') { // <--- CHANGE THESE TEST CREDENTIALS
        // Authentication successful
        $_SESSION['user_id'] = 1; // Set a user ID (e.g., from your database)
        $_SESSION['role'] = 'user'; // Set the user's role (e.g., 'user', 'admin')

        // Redirect to the dashboard
        header('Location: ' . BASE_URL . '/dashboard');
        exit; // Important: terminate script execution after redirection
    } else {
        // Authentication failed
        $message = "Invalid last name or membership number.";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - User Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="background-container">
    </div>

    <div class="login-card-container">
        <div class="login-card">
            <div class="bank-logo">
                <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
            </div>

            <?php if (!empty($message)): ?>
                <div id="login-message" class="message-box <?php echo htmlspecialchars($message_type); ?>" style="display: block;">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php else: ?>
                <div id="login-message" class="message-box" style="display: none;"></div>
            <?php endif; ?>

            <form class="login-form" id="loginForm" action="/" method="POST">
                <div class="form-group username-group">
                    <label for="last_name" class="sr-only">Last Name</label>
                    <p class="input-label">Last Name</p>
                    <div class="input-wrapper">
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-group password-group">
                    <label for="membership_number" class="sr-only">Membership Number</label>
                    <p class="input-label">Membership Number</p>
                    <div class="input-wrapper">
                        <input type="text" id="membership_number" name="membership_number" placeholder="" required pattern="\d{12}" title="Membership number must be 12 digits" value="<?php echo htmlspecialchars($_POST['membership_number'] ?? ''); ?>">
                    </div>
                    <a href="#" class="forgot-password-link">Forgot?</a>
                </div>

                <div class="buttons-group">
                    <button type="submit" name="login" class="btn btn-primary">Sign in</button>
                </div>
            </form>

        </div>
    </div>

    <footer>
        <p>&copy; 2025 HomeTown Bank Pa. All rights reserved.</p>
        <div class="footer-links">
            <a href="heritagebank_admin/index.php">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>