<?php
// IMPORTANT: Load Composer autoloader, Config.php, and functions.php FIRST.
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/functions.php';

// --- Use the provided MongoDB connection string ---
// IMPORTANT: Ensure this connection string is accurate and matches your Render environment variables.
// It's best practice to load this from your .env file or Render's environment settings.
// For this script, we'll assume MONGODB_CONNECTION_URI is correctly set in your Config.php,
// which should be loading from your environment.
// If not, you might need to define it here temporarily like:
// $connectionString = 'mongodb+srv://boboskieofficial:Oluwasegunbobo_000@cluster0.kft3ods.mongodb.net/HometownBankPA?retryWrites=true&w=majority&appName=Cluster0';
// define('MONGODB_CONNECTION_URI_FOR_SCRIPT', $connectionString);
// and then update getMongoDBClient() to use it, or pass it as an argument.

// --- Connect to MongoDB ---
try {
    $mongoClient = getMongoDBClient(); // Assumes this function uses MONGODB_CONNECTION_URI from Config.php
    $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME); // MONGODB_DB_NAME should be defined in Config.php
    $adminCollection = $mongoDb->selectCollection('admin'); // Assuming your admin collection is named 'admin'
} catch (Exception $e) {
    // In a real-world scenario, you'd want to log this error instead of echoing it directly.
    die("Database connection failed: " . $e->getMessage());
}

// --- Admin User Details ---
// The ID '6881ce7b549401e932055a14' corresponds to the admin document you provided.
$adminId = new MongoDB\BSON\ObjectId('6881ce7b549401e932055a14');
$newPlainPassword = 'Oladipupo0$'; // *** REPLACE THIS WITH YOUR NEW SECURE PASSWORD ***

// --- Generate New Password Hash ---
$newPasswordHash = password_hash($newPlainPassword, PASSWORD_BCRYPT);

if ($newPasswordHash === false) {
    die("Error generating password hash. Please check your PHP configuration.");
}

// --- Update the Admin Document ---
try {
    $updateResult = $adminCollection->updateOne(
        ['_id' => $adminId], // Filter to find the specific admin document
        [
            '$set' => [
                'password' => $newPasswordHash,
                'updated_at' => new MongoDB\BSON\UTCDateTime() // Update the timestamp
            ]
        ]
    );

    if ($updateResult->getModifiedCount() === 1) {
        echo "Admin password updated successfully! 🎉";
        error_log("Admin password for ID '{$adminId}' updated successfully.");
    } elseif ($updateResult->getMatchedCount() === 1) {
        echo "Admin password was already set to this value. No changes made.";
        error_log("Admin password for ID '{$adminId}' was already the target value.");
    } else {
        echo "No admin document found with the specified ID.";
        error_log("Attempted to update admin password for ID '{$adminId}', but no document was found.");
    }
} catch (Exception $e) {
    echo "An error occurred while updating the password: " . $e->getMessage();
    error_log("Error updating admin password for ID '{$adminId}': " . $e->getMessage());
}

?>