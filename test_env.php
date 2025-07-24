<?php
// Adjust this path if Config.php is not directly in the parent directory
require_once __DIR__ . '/Config.php';

if (defined('MONGODB_CONNECTION_URI')) {
    echo "MONGODB_CONNECTION_URI is defined: " . MONGODB_CONNECTION_URI . "<br>";
} else {
    echo "MONGODB_CONNECTION_URI is NOT defined as a constant.<br>";
}

// This will show what Dotenv loaded into $_ENV
if (isset($_ENV['MONGODB_CONNECTION_URI'])) {
    echo "MONGODB_CONNECTION_URI (from _ENV): " . $_ENV['MONGODB_CONNECTION_URI'] . "<br>";
} else {
    echo "MONGODB_CONNECTION_URI (from _ENV) is NOT set.<br>";
}

echo "<hr>";
phpinfo(); // This will display all PHP configuration and environment variables
?>