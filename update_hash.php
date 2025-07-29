<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

try {
    $mongoClient = getMongoDBClient();
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $adminCollection = $mongoDb->selectCollection('admin');

    $email = 'hometownbankpa@gmail.com';
    $new_password = 'Oluwasegunbobo0';

    // Generate a new hash for the correct password
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // Update the user's document
    $updateResult = $adminCollection->updateOne(
        ['email' => $email],
        ['$set' => ['password' => $new_hash]]
    );

    if ($updateResult->getModifiedCount() > 0) {
        echo "Password for " . htmlspecialchars($email) . " updated successfully to:<br>";
        echo htmlspecialchars($new_hash);
    } else {
        echo "No documents were modified. User not found or password was already correct.";
    }

} catch (Exception $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>