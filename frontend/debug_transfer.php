<?php
// Path: hometownbank/frontend/debug_transfer.php

// ALWAYS enable error display for debugging!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debugging `make_transfer.php` Dependencies on Render</h1>";
echo "<h2>Current Directory: " . __DIR__ . "</h2>";

// --- Test Config.php ---
echo "<h3>1. Testing Config.php inclusion...</h3>";
$configPath = __DIR__ . '/../Config.php';
echo "Attempting to require: " . htmlspecialchars($configPath) . "<br>";
if (file_exists($configPath)) {
    echo "<b>SUCCESS: Config.php found!</b><br>";
    try {
        require_once $configPath;
        echo "Config.php loaded successfully.<br>";
        if (defined('BASE_URL')) {
            echo "BASE_URL defined: " . htmlspecialchars(BASE_URL) . "<br>";
        } else {
            echo "<b>WARNING: BASE_URL not defined in Config.php.</b><br>";
        }
        if (defined('MONGODB_CONNECTION_URI')) {
            echo "MONGODB_CONNECTION_URI defined (first few chars): " . htmlspecialchars(substr(MONGODB_CONNECTION_URI, 0, 20)) . "...<br>";
        } else {
            echo "<b>WARNING: MONGODB_CONNECTION_URI not defined in Config.php.</b><br>";
        }
    } catch (Throwable $e) { // Use Throwable for PHP 7+ for all errors/exceptions
        echo "<b>ERROR: Exception/Error during Config.php loading: " . htmlspecialchars($e->getMessage()) . " on line " . $e->getLine() . "</b><br>";
    }
} else {
    echo "<b>FATAL ERROR: Config.php NOT FOUND at the expected path!</b><br>";
}

echo "<hr>";

// --- Test functions.php ---
echo "<h3>2. Testing functions.php inclusion...</h3>";
$functionsPath = __DIR__ . '/../functions.php';
echo "Attempting to require: " . htmlspecialchars($functionsPath) . "<br>";
if (file_exists($functionsPath)) {
    echo "<b>SUCCESS: functions.php found!</b><br>";
    try {
        require_once $functionsPath;
        echo "functions.php loaded successfully.<br>";
        if (function_exists('sanitize_input')) {
            echo "Function 'sanitize_input' exists.<br>";
        } else {
            echo "<b>WARNING: Function 'sanitize_input' not found in functions.php.</b><br>";
        }
        if (function_exists('generateUniqueReference')) {
            echo "Function 'generateUniqueReference' exists.<br>";
        } else {
            echo "<b>WARNING: Function 'generateUniqueReference' not found in functions.php.</b><br>";
        }
    } catch (Throwable $e) {
        echo "<b>ERROR: Exception/Error during functions.php loading: " . htmlspecialchars($e->getMessage()) . " on line " . $e->getLine() . "</b><br>";
    }
} else {
    echo "<b>FATAL ERROR: functions.php NOT FOUND at the expected path!</b><br>";
}

echo "<hr>";

// --- Test Composer Autoload & MongoDB Class ---
echo "<h3>3. Testing MongoDB\Client class availability...</h3>";
if (class_exists('MongoDB\Client')) {
    echo "<b>SUCCESS: MongoDB\\Client class found! (Composer autoloader is working, and MongoDB extension likely installed)</b><br>";
    echo "Attempting a dummy MongoDB client instantiation (won't connect, just tests class existence):<br>";
    try {
        // This just tries to instantiate, not connect. Needs a dummy URI.
        // It's possible for the class to exist but the extension to be broken for actual connection.
        // But this confirms the autoloader.
        $dummyClient = new MongoDB\Client("mongodb://localhost:27017/?replicaSet=myReplicaSet"); // Dummy URI
        echo "Dummy MongoDB\\Client instantiated successfully.<br>";
    } catch (Throwable $e) {
        echo "<b>ERROR: Could not instantiate MongoDB\\Client (this might indicate a problem with the underlying PHP MongoDB extension or its configuration): " . htmlspecialchars($e->getMessage()) . " on line " . $e->getLine() . "</b><br>";
    }
} else {
    echo "<b>FATAL ERROR: MongoDB\\Client class NOT FOUND. This strongly indicates:</b><br>";
    echo "<ul>";
    echo "<li>Composer's `vendor/autoload.php` was not loaded (check Config.php's path/contents).</li>";
    echo "<li>`composer install` did not run successfully on Render.</li>";
    echo "<li>The PHP `mongodb` extension is not installed or enabled on Render.</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<h3>Debugging complete. Analyze the output above.</h3>";

?>
