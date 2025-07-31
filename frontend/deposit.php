<?php
// The session, autoloader, and core files are already loaded by index.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // Ensure functions.php is included for getCollection()

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime; // Added for timestamping activation
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

// Ensure session_start() is called somewhere BEFORE this check,
// ideally in your central router file (like the main index.php)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/'));
    exit;
}

$user_id = $_SESSION['user_id'];

// The global $mongoDb and getCollection() function are available
$accountsCollection = null; // Initialize to null
try {
    $accountsCollection = getCollection('accounts');
} catch (MongoDBDriverException $e) { // Catch specific MongoDB exceptions
    error_log("MongoDB connection error in Deposit.php: " . $e->getMessage());
    die("A critical database error occurred. Please try again later.");
} catch (Exception $e) { // Catch general exceptions
    error_log("General database error in Deposit.php: " . $e->getMessage());
    die("A critical database error occurred. Please try again later.");
}


// Fetch user's accounts to populate the dropdown
$user_accounts = [];
$error = ''; // Initialize error variable
try {
    // Only proceed if $accountsCollection was successfully initialized
    if ($accountsCollection) {
        $accounts_cursor = $accountsCollection->find(['user_id' => new ObjectId($user_id)]);
        foreach ($accounts_cursor as $account) {
            $user_accounts[] = $account;
        }
    } else {
        $error = "Accounts collection not initialized due to a previous database error.";
    }
} catch (MongoDBDriverException $e) { // Catch specific MongoDB exceptions
    $error = "Error fetching your accounts. Please try again later.";
    error_log("MongoDB Error fetching accounts for check deposit: " . $e->getMessage());
} catch (Exception $e) { // Catch general exceptions
    $error = "Error fetching your accounts. An unexpected error occurred.";
    error_log("General Error fetching accounts for check deposit: " . $e->getMessage());
}

function get_currency_symbol($currency_code) {
    switch (strtoupper($currency_code)) {
        case 'GBP': return '£';
        case 'USD': return '$';
        case 'EUR': return '€';
        case 'NGN': return '₦';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Check - Hometown Bank Pa</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css"> <style>
        /* Your existing CSS styles remain here */
        .deposit-form-container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .deposit-form-container h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input[type="file"],
        .form-group input[type="number"],
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .form-group input[type="file"] {
            border: none;
        }
        .form-group small {
            color: #666;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn-submit:hover {
            background-color: #0056b3;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="greeting">
                <a href="dashboard.php" style="text-decoration: none; color: inherit;"> <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </header>

        <div class="deposit-form-container">
            <h2>Deposit a Check</h2>
            <div id="responseMessage">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
            </div>
            
            <form id="depositForm" action="/api/deposit_check.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="account_id">Choose Account</label>
                    <select class="form-select" id="account_id" name="account_id" required>
                        <option value="">Select an account...</option>
                        <?php foreach ($user_accounts as $account): ?>
                            <option value="<?= htmlspecialchars((string)$account['_id']) ?>">
                                <?= htmlspecialchars($account['account_type']) ?> (<?= htmlspecialchars($account['account_number']) ?>) - Balance: <?= get_currency_symbol($account['currency']) . number_format($account['balance'] ?? 0, 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount">Check Amount</label>
                    <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label for="front_image">Front of Check</label>
                    <input type="file" class="form-control" id="front_image" name="front_image" accept="image/*" required>
                    <small>Take a clear picture of the front of the check.</small>
                </div>
                <div class="form-group">
                    <label for="back_image">Back of Check</label>
                    <input type="file" class="form-control" id="back_image" name="back_image" accept="image/*" required>
                    <small>Take a clear picture of the back of the check.</small>
                </div>
                <button type="submit" class="btn-submit">Submit for Approval</button>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('depositForm').addEventListener('submit', function(event) {
            event.preventDefault();

            const form = event.target;
            const formData = new FormData(form);
            const responseDiv = document.getElementById('responseMessage');
            
            // Clear previous messages
            responseDiv.innerHTML = '';
            responseDiv.className = '';

            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if response is JSON, if not, parse as text and log
                const contentType = response.headers.get("content-type");
                if (contentType && contentType.indexOf("application/json") !== -1) {
                    return response.json();
                } else {
                    return response.text().then(text => {
                        console.error("Server did not return JSON. Response was:", text);
                        throw new Error("Server response was not valid JSON. Check backend script for errors.");
                    });
                }
            })
            .then(data => {
                if (data.success) {
                    responseDiv.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
                    form.reset(); // Clear form on success
                } else {
                    // Check if data.message exists, otherwise provide a generic error
                    const errorMessage = data.message || "An unknown error occurred on the server.";
                    responseDiv.innerHTML = `<div class="alert alert-danger">${errorMessage}</div>`;
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                responseDiv.innerHTML = `<div class="alert alert-danger">An unexpected error occurred: ${error.message}. Please try again.</div>`;
            });
        });
    </script>
</body>
</html>
