<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env and Config
$dotenvPath = dirname(__DIR__); 
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    $dotenv->load();
}
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\BSON\ObjectId;

// Check if the user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/'));
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// MongoDB Connection
try {
    $accountsCollection = getCollection('accounts');
    $checkDepositsCollection = getCollection('check_deposits');
} catch (Exception $e) {
    die("A critical database error occurred. Please try again later.");
}

// Fetch user's accounts to populate the dropdown
$user_accounts = [];
try {
    $accounts_cursor = $accountsCollection->find(['user_id' => new MongoDB\BSON\ObjectId($user_id)]);
    foreach ($accounts_cursor as $account) {
        $user_accounts[] = $account;
    }
} catch (Exception $e) {
    $error = "Error fetching your accounts. Please try again later.";
    error_log("Error fetching accounts for check deposit: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate input
    $account_id = $_POST['account_id'] ?? null;
    $amount = $_POST['amount'] ?? null;
    $amount = filter_var($amount, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);

    if (!$account_id || !$amount) {
        $error = "Please fill in all fields with valid data.";
    } elseif (!isset($_FILES['front_image']) || !isset($_FILES['back_image'])) {
        $error = "Please upload both the front and back of the check.";
    } else {
        $accountExists = false;
        foreach($user_accounts as $acc) {
            if ($acc['_id']->__toString() === $account_id) {
                $accountExists = true;
                break;
            }
        }
        if (!$accountExists) {
            $error = "The selected account is invalid.";
        } else {
            // Handle file uploads
            $upload_dir = '../uploads/checks/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
    
            $front_image_name = uniqid('front_') . '_' . basename($_FILES['front_image']['name']);
            $back_image_name = uniqid('back_') . '_' . basename($_FILES['back_image']['name']);
            
            $front_image_path = $upload_dir . $front_image_name;
            $back_image_path = $upload_dir . $back_image_name;

            // Check file type and size
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5 MB
            
            if (!in_array($_FILES['front_image']['type'], $allowed_types) || !in_array($_FILES['back_image']['type'], $allowed_types)) {
                $error = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
            } elseif ($_FILES['front_image']['size'] > $max_size || $_FILES['back_image']['size'] > $max_size) {
                $error = "File size exceeds the 5MB limit.";
            } elseif (move_uploaded_file($_FILES['front_image']['tmp_name'], $front_image_path) &&
                      move_uploaded_file($_FILES['back_image']['tmp_name'], $back_image_path)) {
                
                try {
                    $insertResult = $checkDepositsCollection->insertOne([
                        'user_id' => new MongoDB\BSON\ObjectId($user_id),
                        'account_id' => new MongoDB\BSON\ObjectId($account_id),
                        'amount' => (float) $amount,
                        'front_image_path' => $front_image_path,
                        'back_image_path' => $back_image_path,
                        'status' => 'pending',
                        'admin_notes' => '',
                        'created_at' => new MongoDB\BSON\UTCDateTime()
                    ]);
    
                    if ($insertResult->getInsertedId()) {
                        $message = "Your check deposit for $" . number_format($amount, 2) . " has been submitted and is pending administrator approval.";
                    } else {
                        $error = "Failed to submit deposit request. Please try again.";
                    }
                } catch (Exception $e) {
                    $error = "A database error occurred: " . $e->getMessage();
                    error_log("Error inserting check deposit into MongoDB: " . $e->getMessage());
                }
    
            } else {
                $error = "Failed to upload one or more images. Please check file permissions.";
            }
        }
    }
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
                <a href="dashboard" style="text-decoration: none; color: inherit;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </header>

        <div class="deposit-form-container">
            <h2>Deposit a Check</h2>
            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            <form action="Deposit.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="account_id">Choose Account</label>
                    <select class="form-select" id="account_id" name="account_id" required>
                        <option value="">Select an account...</option>
                        <?php foreach ($user_accounts as $account): ?>
                            <option value="<?= htmlspecialchars($account['_id']) ?>">
                                <?= htmlspecialchars($account['account_type']) ?> (<?= htmlspecialchars($account['account_number']) ?>) - Balance: <?= get_currency_symbol($account['currency']) . number_format($account['balance'], 2) ?>
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
</body>
</html>