<?php // This MUST be the very first thing on line 1, no spaces/newlines before it
session_start(); // Start the session to access session variables

// Unset all of the session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

// Redirect to the frontend login page
// Use a defined BASE_URL if possible, for better portability
// For this example, assuming ../index.php is correct relative path
header('Location: ../index.php'); 
exit; // Crucial: terminate script execution after redirect
// It's good practice to omit the closing PHP tag ?> in files that only contain PHP code
// especially when dealing with headers, to prevent accidental whitespace.