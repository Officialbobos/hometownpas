<?php
if (extension_loaded('mongodb')) {
    echo "MongoDB extension is loaded!\n";
    try {
        $client = new MongoDB\Client("mongodb://localhost:27017");
        echo "MongoDB\\Client class found and instantiated!\n";
    } catch (Throwable $e) {
        echo "Error instantiating MongoDB\\Client: " . $e->getMessage() . "\n";
    }
} else {
    echo "MongoDB extension is NOT loaded.\n";
}
phpinfo(); // This is crucial for debugging without shell access
?>