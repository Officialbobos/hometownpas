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

    // Basic validation (can be expanded)
    if (empty($lastName) || empty($membershipNumber)) {
        $message = "Please enter both last name and membership number.";
        $message_type = "error";
    } else {
        try {
            // Establish MongoDB connection and get the 'users' collection
            // Assuming getCollection() is defined in functions.php and handles DB connection
            $usersCollection = getCollection('users');

            // Find the user by last_name and membership_number
            // IMPORTANT: For production, membership_number should be hashed in the DB
            // and you would compare the hash here. For this example, direct comparison.
            $user = $usersCollection->findOne([
                'last_name' => $lastName,
                'membership_number' => $membershipNumber // Direct comparison - HASH THIS IN PRODUCTION!
            ]);

            if ($user) {
                // User found and credentials match
                $twoFactorEnabled = $user['two_factor_enabled'] ?? false;
                $twoFactorMethod = $user['two_factor_method'] ?? 'email'; // Default to 'email' if not set

                if ($twoFactorEnabled && $twoFactorMethod !== 'none') {
                    // 2FA is enabled for this user. Redirect to verification page.
                    $_SESSION['auth_step'] = 'awaiting_2fa';
                    $_SESSION['temp_user_id'] = (string) $user['_id']; // Store ObjectId as string

                    // If 2FA method is email, generate and send code
                    if ($twoFactorMethod === 'email') {
                        $verificationCode = str_pad(random_int(0, (10**TWO_FACTOR_CODE_LENGTH) - 1), TWO_FACTOR_CODE_LENGTH, '0', STR_PAD_LEFT);
                        $expiryTime = new MongoDB\BSON\UTCDateTime(strtotime('+' . TWO_FACTOR_CODE_EXPIRY_MINUTES . ' minutes') * 1000);

                        // Update user document with the temporary code and expiry
                        $usersCollection->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => [
                                'two_factor_temp_code' => $verificationCode,
                                'two_factor_code_expiry' => $expiryTime
                            ]]
                        );

                        // Send the email (assuming sendEmail function exists in functions.php)
                        $emailSubject = "HomeTown Bank Login Verification Code";
                        $emailBodyHtml = "
                            <!DOCTYPE html>
                            <html lang='en'>
                            <head>
                                <meta charset='UTF-8'>
                                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                                <title>HomeTown Bank - Verification Code</title>
                                <style>
                                    body { font-family: sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                                    .email-container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.05); overflow: hidden; }
                                    .header { background-color: #0056b3; padding: 20px; text-align: center; color: #ffffff; font-size: 24px; font-weight: bold; }
                                    .content { padding: 30px; color: #333333; line-height: 1.6; text-align: center; }
                                    .verification-code { display: inline-block; background-color: #e6f0fa; color: #0056b3; font-size: 32px; font-weight: bold; padding: 15px 30px; border-radius: 5px; margin: 20px 0; letter-spacing: 3px; text-align: center; }
                                    .expiry-info { font-size: 0.9em; color: #777777; margin-top: 20px; }
                                    .footer { background-color: #f0f0f0; padding: 20px; text-align: center; font-size: 0.85em; color: #666666; border-top: 1px solid #eeeeee; }
                                </style>
                            </head>
                            <body>
                                <div class='email-container'>
                                    <div class='header'>HomeTown Bank</div>
                                    <div class='content'>
                                        <p>Dear " . htmlspecialchars($user['first_name'] ?? '') . ",</p>
                                        <p>Your verification code for HomeTown Bank Online Banking is:</p>
                                        <div class='verification-code'>" . htmlspecialchars($verificationCode) . "</div>
                                        <p class='expiry-info'>This code is valid for <strong>" . TWO_FACTOR_CODE_EXPIRY_MINUTES . " minutes</strong>.</p>
                                        <p>If you did not request this, please ignore this email.</p>
                                    </div>
                                    <div class='footer'>&copy; " . date('Y') . " HomeTown Bank.</div>
                                </div>
                            </body>
                            </html>";
                        sendEmail($user['email'], $emailSubject, $emailBodyHtml, "Your verification code for HomeTown Bank is: " . $verificationCode);
                    }
                    header('Location: ' . BASE_URL . '/verify_code');
                    exit;

                } else {
                    // No 2FA enabled for this user or method is 'none', log them in directly
                    $_SESSION['user_id'] = (string) $user['_id'];
                    $_SESSION['role'] = $user['role'] ?? 'user';
                    $_SESSION['first_name'] = $user['first_name'] ?? '';
                    $_SESSION['last_name'] = $user['last_name'] ?? '';
                    $_SESSION['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $_SESSION['email'] = $user['email'] ?? '';
                    $_SESSION['is_admin'] = $user['is_admin'] ?? false; // Assuming 'is_admin' field exists

                    header('Location: ' . BASE_URL . '/dashboard');
                    exit;
                }
            } else {
                // User not found or credentials do not match
                $message = "Invalid last name or membership number.";
                $message_type = "error";
            }
        } catch (MongoDB\Driver\Exception\Exception $e) {
            // Log the MongoDB error for debugging
            error_log("MongoDB Error during login: " . $e->getMessage());
            $message = "A database error occurred. Please try again later.";
            $message_type = "error";
        } catch (Exception $e) {
            // Catch any other unexpected errors
            error_log("General Error during login: " . $e->getMessage());
            $message = "An unexpected error occurred. Please try again.";
            $message_type = "error";
        }
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