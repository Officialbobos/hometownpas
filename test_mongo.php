<?php

// Try to load Composer's autoloader first
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require $autoloadPath;
    echo "<p>&#x2705; Composer autoloader loaded!</p>";
} else {
    echo "<p>&#x274C; Composer autoloader NOT found at: " . htmlspecialchars($autoloadPath) . "</p>";
    echo "<p>Please ensure 'composer install' ran successfully and 'vendor/autoload.php' exists.</p>";
}

echo "<h1>MongoDB Extension Check</h1>";

if (extension_loaded('mongodb')) {
    echo "<p>&#x2705; MongoDB extension is loaded!</p>";
    // Only attempt to instantiate if the autoloader was potentially loaded
    if (class_exists('MongoDB\\Client')) {
        try {
            $client = new MongoDB\Client("mongodb://localhost:27017"); // Adjust connection string if needed
            echo "<p>&#x2705; MongoDB\\Client class found and instantiated!</p>";
        } catch (Throwable $e) {
            echo "<p>&#x274C; Error instantiating MongoDB\\Client: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p>&#x274C; MongoDB\\Client class not found despite extension being loaded. Autoloader issue suspected.</p>";
    }
} else {
    echo "<p>&#x274C; MongoDB extension is NOT loaded.</p>";
}

echo "<h2>PHP Info:</h2>";
phpinfo();
?>