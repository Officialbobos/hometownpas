<?php
session_start();
require_once '../../Config.php';

// Use MongoDB PHP Library
require_once '../../functions.php';
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

// Include AWS SDK for PHP (for Backblaze B2 S3 Compatible API)
require '../../vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: ../index.php');
    exit;
}

// --- Initialize Variables ---
$message = '';
$message_type = '';

$user_id_from_url = isset($_GET['id']) ? $_GET['id'] : '';
$user_id = null;
$user_data = null;
$accounts_data = [];
$current_profile_image_url = '';

// Re-fetch message if it came from a redirect (e.g., from manage_users.php after delete)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = htmlspecialchars($_GET['message']);
    $message_type = htmlspecialchars($_GET['type']);
}

// Establish MongoDB connection
try {
    $client = new Client(MONGODB_CONNECTION_URI);
    $database = $client->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->selectCollection('users');
    $accountsCollection = $database->selectCollection('accounts');
} catch (Exception $e) {
    die("ERROR: Could not connect to MongoDB. " . $e->getMessage());
}

// Initialize B2 S3Client
$s3Client = null;
try {
    $s3Client = new S3Client([
        'version'     => 'latest',
        'region'      => B2_REGION,
        'endpoint'    => B2_ENDPOINT,
        'credentials' => [
            'key'    => B2_APPLICATION_KEY_ID,
            'secret' => B2_APPLICATION_KEY,
        ],
        'http'        => [
            'verify' => false // Set to true in production with proper CA certificates
        ]
    ]);
} catch (AwsException $e) {
    error_log("B2 S3Client initialization error: " . $e->getMessage());
    $message = "An error occurred while initializing file storage. Image functionality may not work.";
    $message_type = 'error';
}

// --- Functions ---
/**
 * Fetches user and account data from the database.
 * @param \MongoDB\Collection $usersCollection
 * @param \MongoDB\Collection $accountsCollection
 * @param string|null $userIdFromUrl The user ID from the URL as a string.
 * @return array An associative array with user_data, accounts_data, and profile_image_url.
 */
function fetchUserData(
    $usersCollection,
    $accountsCollection,
    $userIdFromUrl,
    $s3Client
) {
    $userData = null;
    $accountsData = [];
    $profileImageUrl = '';

    if (!empty($userIdFromUrl)) {
        try {
            $userId = new ObjectId($userIdFromUrl);
            $userData = $usersCollection->findOne(['_id' => $userId]);

            if ($userData) {
                $userData = (array) $userData;

                // Fetch associated accounts
                $cursorAccounts = $accountsCollection->find(['user_id' => $userId]);
                foreach ($cursorAccounts as $accountDoc) {
                    $accountsData[] = (array) $accountDoc;
                }

                // Generate pre-signed URL for profile image from B2
                if (!empty($userData['profile_image_key']) && $s3Client) {
                    try {
                        $command = $s3Client->getCommand('GetObject', [
                            'Bucket' => B2_BUCKET_NAME,
                            'Key'    => $userData['profile_image_key'],
                        ]);
                        $request = $s3Client->createPresignedRequest($command, '+60 minutes');
                        $profileImageUrl = (string) $request->getUri();
                    } catch (AwsException $e) {
                        error_log("Error generating pre-signed URL: " . $e->getMessage());
                        $profileImageUrl = '';
                    }
                }
            }
        } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
            // Invalid ObjectId format
            $userData = null;
        }
    }
    return ['user_data' => $userData, 'accounts_data' => $accountsData, 'profile_image_url' => $profileImageUrl];
}

// --- Initial Data Fetch ---
$fetched_data = fetchUserData($usersCollection, $accountsCollection, $user_id_from_url, $s3Client);
$user_data = $fetched_data['user_data'];
$accounts_data = $fetched_data['accounts_data'];
$current_profile_image_url = $fetched_data['profile_image_url'];

// If a valid user ID was provided but no user was found, set an error message
if (!$user_data && !empty($user_id_from_url) && empty($message)) {
    $message = "No user found with the provided ID.";
    $message_type = 'error';
    $user_id = null;
} elseif ($user_data) {
    $user_id = $user_data['_id']; // Set the ObjectId for use in POST logic
}


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $home_address = trim($_POST['home_address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $membership_number = trim($_POST['membership_number'] ?? '');
    $admin_created_at_str = trim($_POST['admin_created_at'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $remove_profile_image = isset($_POST['remove_profile_image']);

    $old_profile_image_key = $user_data['profile_image_key'] ?? null;
    $profile_image_key_to_save = $old_profile_image_key;
    $uploaded_new_image_key = null;
    $is_valid = true;

    // --- Validation ---
    if (empty($first_name) || empty($last_name) || empty($email) || empty($home_address) || empty($phone_number) || empty($nationality) || empty($date_of_birth) || empty($gender) || empty($occupation) || empty($membership_number) || empty($admin_created_at_str)) {
        $message = 'All user fields (except new password and profile image) are required.';
        $message_type = 'error';
        $is_valid = false;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
        $is_valid = false;
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $message = 'New password must be at least 6 characters long.';
        $message_type = 'error';
        $is_valid = false;
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $message = 'Invalid gender selected.';
        $message_type = 'error';
        $is_valid = false;
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_of_birth) || !strtotime($date_of_birth)) {
        $message = 'Invalid Date of Birth format. Please use YYYY-MM-DD.';
        $message_type = 'error';
        $is_valid = false;
    }

    // Convert and validate admin_created_at
    if ($is_valid) {
        try {
            $admin_created_at = new UTCDateTime(strtotime($admin_created_at_str) * 1000);
        } catch (Exception $e) {
            $message = 'Invalid "Created At" date/time format. Please use YYYY-MM-DDTHH:MM.';
            $message_type = 'error';
            $is_valid = false;
        }
    }

    // Check for duplicate email
    if ($is_valid) {
        $existing_user_with_email = $usersCollection->findOne([
            'email' => $email,
            '_id' => ['$ne' => $user_id]
        ]);
        if ($existing_user_with_email) {
            $message = "Error updating user: Email address already exists for another user.";
            $message_type = 'error';
            $is_valid = false;
        }
    }

    // Validation for account data
    if ($is_valid) {
        $submitted_accounts = $_POST['accounts'] ?? [];
        foreach ($submitted_accounts as $index => $account) {
            $acc_type = trim($account['account_type'] ?? '');
            $acc_number = trim($account['account_number'] ?? '');
            $balance = floatval($account['balance'] ?? 0);
            $currency = trim($account['currency'] ?? '');

            if (empty($acc_type) || empty($acc_number) || empty($currency)) {
                $message = "Account " . ($index + 1) . ": Account type, number, and currency are required.";
                $message_type = 'error';
                $is_valid = false;
                break;
            }
            if (!is_numeric($balance) || $balance < 0) {
                $message = "Account " . ($index + 1) . ": Balance must be a non-negative number.";
                $message_type = 'error';
                $is_valid = false;
                break;
            }
            if (!in_array($currency, ['USD', 'EUR', 'GBP', 'NGN'])) {
                $message = "Account " . ($index + 1) . ": Invalid currency selected.";
                $message_type = 'error';
                $is_valid = false;
                break;
            }
        }
    }

    if ($is_valid) {
        // --- Handle Profile Image Upload / Removal ---
        $image_operation_success = true;

        if ($remove_profile_image) {
            if ($old_profile_image_key && $s3Client) {
                try {
                    $s3Client->deleteObject([
                        'Bucket' => B2_BUCKET_NAME,
                        'Key'    => $old_profile_image_key,
                    ]);
                    $profile_image_key_to_save = null;
                } catch (AwsException $e) {
                    $message = 'Error deleting old profile image.';
                    $message_type = 'error';
                    $image_operation_success = false;
                }
            } else {
                $profile_image_key_to_save = null;
            }
        } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['profile_image']['tmp_name'];
            $file_name = $_FILES['profile_image']['name'];
            $file_size = $_FILES['profile_image']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_ext)) {
                $message = 'Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.';
                $message_type = 'error';
                $image_operation_success = false;
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $message = 'Image file size exceeds 5MB limit.';
                $message_type = 'error';
                $image_operation_success = false;
            } else {
                if ($s3Client) {
                    $new_image_key = 'profile_images/' . uniqid('user_', true) . '.' . $file_ext;
                    try {
                        $s3Client->putObject([
                            'Bucket'      => B2_BUCKET_NAME,
                            'Key'         => $new_image_key,
                            'SourceFile'  => $file_tmp_path,
                            'ACL'         => 'public-read',
                            'ContentType' => mime_content_type($file_tmp_path)
                        ]);
                        $profile_image_key_to_save = $new_image_key;
                        $uploaded_new_image_key = $new_image_key;
                    } catch (AwsException $e) {
                        $message = 'Error uploading new profile image to storage.';
                        $message_type = 'error';
                        $image_operation_success = false;
                        error_log("B2 upload error: " . $e->getMessage());
                    }
                } else {
                    $message = 'Image storage is not configured. Cannot upload image.';
                    $message_type = 'error';
                    $image_operation_success = false;
                }
            }
        }

        // Proceed only if image operation was successful
        if ($image_operation_success) {
            $transaction_success = true;
            $session = null;

            try {
                // Start a session for multi-document transaction
                $session = $client->startSession();
                $session->startTransaction();

                // 1. Update User Details
                $user_update_fields = [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'home_address' => $home_address,
                    'phone_number' => $phone_number,
                    'nationality' => $nationality,
                    'date_of_birth' => $date_of_birth,
                    'gender' => $gender,
                    'occupation' => $occupation,
                    'membership_number' => $membership_number,
                    'profile_image_key' => $profile_image_key_to_save,
                    'created_at' => $admin_created_at
                ];

                if (!empty($new_password)) {
                    $user_update_fields['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                }

                $updateResultUser = $usersCollection->updateOne(
                    ['_id' => $user_id],
                    ['$set' => $user_update_fields],
                    ['session' => $session]
                );

                if ($updateResultUser->getMatchedCount() === 0) {
                    $message = "User update failed: User ID not found.";
                    $message_type = 'error';
                    $transaction_success = false;
                }

                // 2. Update Account Details
                if ($transaction_success) {
                    foreach ($submitted_accounts as $account) {
                        try {
                            $account_mongo_id = new ObjectId($account['id']);
                        } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
                            $message = "Invalid account ID for an account.";
                            $message_type = 'error';
                            $transaction_success = false;
                            break;
                        }

                        $account_update_fields = [
                            'account_type' => trim($account['account_type'] ?? ''),
                            'account_number' => trim($account['account_number'] ?? ''),
                            'balance' => floatval($account['balance'] ?? 0),
                            'currency' => trim($account['currency'] ?? ''),
                            'sort_code' => empty(trim($account['sort_code'] ?? '')) ? null : trim($account['sort_code']),
                            'iban' => empty(trim($account['iban'] ?? '')) ? null : trim($account['iban']),
                            'swift_bic' => empty(trim($account['swift_bic'] ?? '')) ? null : trim($account['swift_bic'])
                        ];

                        $updateResultAccount = $accountsCollection->updateOne(
                            ['_id' => $account_mongo_id, 'user_id' => $user_id],
                            ['$set' => $account_update_fields],
                            ['session' => $session]
                        );

                        if ($updateResultAccount->getMatchedCount() === 0) {
                            $message = "Failed to update an account: Account not found or does not belong to user.";
                            $message_type = 'error';
                            $transaction_success = false;
                            break;
                        }
                    }
                }

                // --- Finalize Transaction ---
                if ($transaction_success) {
                    $session->commitTransaction();
                    $message = "User and account details updated successfully!";
                    $message_type = 'success';

                    // After successful commit, delete the old image from B2
                    if ($uploaded_new_image_key && $old_profile_image_key && $s3Client) {
                        try {
                            $s3Client->deleteObject([
                                'Bucket' => B2_BUCKET_NAME,
                                'Key'    => $old_profile_image_key,
                            ]);
                        } catch (AwsException $e) {
                            error_log("Failed to delete old profile image '{$old_profile_image_key}' from B2: " . $e->getMessage());
                        }
                    }
                } else {
                    $session->abortTransaction();
                    if (empty($message)) {
                        $message = "Failed to update user or account details. Rolling back changes.";
                    }
                    // If transaction aborted, delete the newly uploaded image from B2
                    if ($uploaded_new_image_key && $s3Client) {
                        try {
                            $s3Client->deleteObject([
                                'Bucket' => B2_BUCKET_NAME,
                                'Key'    => $uploaded_new_image_key,
                            ]);
                        } catch (AwsException $e) {
                            error_log("Failed to clean up newly uploaded B2 image after abort: " . $e->getMessage());
                        }
                    }
                }
            } catch (MongoDB\Driver\Exception\RuntimeException $e) {
                if (isset($session) && $session->inTransaction()) {
                    $session->abortTransaction();
                }
                $message = "Database error during update: " . $e->getMessage();
                $message_type = 'error';
                $transaction_success = false;

                if ($uploaded_new_image_key && $s3Client) {
                    try {
                        $s3Client->deleteObject([
                            'Bucket' => B2_BUCKET_NAME,
                            'Key'    => $uploaded_new_image_key,
                        ]);
                    } catch (AwsException $e) {
                        error_log("Failed to clean up newly uploaded B2 image after database error: " . $e->getMessage());
                    }
                }
            } finally {
                if (isset($session)) {
                    $session->endSession();
                }
            }

            // Re-fetch updated data to refresh the form
            $fetched_data = fetchUserData($usersCollection, $accountsCollection, $user_id, $s3Client);
            $user_data = $fetched_data['user_data'];
            $accounts_data = $fetched_data['accounts_data'];
            $current_profile_image_url = $fetched_data['profile_image_url'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Edit User</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General body and container styling */
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .dashboard-container {
            display: flex;
            flex-direction: column;
            width: 100%;
            align-items: center;
        }

        /* Dashboard Header */
        .dashboard-header {
            background-color: #004494; /* Darker blue for header */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dashboard-header .logo {
            height: 40px; /* Adjust logo size */
        }

        .dashboard-header h2 {
            margin: 0;
            color: white;
            font-size: 1.8em;
        }

        .dashboard-header .logout-button {
            background-color: #ffcc29; /* Heritage accent color */
            color: #004494;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .dashboard-header .logout-button:hover {
            background-color: #e0b821;
        }

        /* Main Content Area */
        .dashboard-content {
            padding: 30px;
            width: 100%;
            max-width: 900px; /* Adjusted max-width for forms */
            margin: 20px auto; /* Center the content */
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            box-sizing: border-box; /* Include padding in width */
        }

        /* Messages */
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            border: 1px solid transparent;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        /* Form styling */
        .form-standard .form-group {
            margin-bottom: 15px;
        }

        .form-standard label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-standard input[type="text"],
        .form-standard input[type="email"],
        .form-standard input[type="tel"],
        .form-standard input[type="date"],
        .form-standard input[type="datetime-local"],
        .form-standard input[type="number"],
        .form-standard input[type="password"],
        .form-standard select,
        .form-standard textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box; /* Include padding in width */
            font-size: 1em;
        }

        .form-standard button.button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .form-standard button.button-primary:hover {
            background-color: #0056b3;
        }

        /* Image Preview */
        .profile-image-preview {
            max-width: 150px;
            height: auto;
            border: 1px solid #ddd;
            margin-top: 10px;
            display: block;
        }
        .form-standard .form-group small {
            color: #666;
            font-size: 0.85em;
            margin-top: 5px;
            display: block;
        }
        /* Account Section Styling */
        .account-section {
            border: 1px solid #e0e0e0; /* Lighter border */
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            background-color: #fcfcfc; /* Slightly lighter background */
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.05); /* subtle inner shadow */
        }
        .account-section h4 {
            margin-top: 0;
            margin-bottom: 18px; /* More space below heading */
            color: #004494; /* Match header blue */
            font-size: 1.3em;
            border-bottom: 1px solid #eee; /* Separator line */
            padding-bottom: 10px;
        }
        .account-section .form-group {
            margin-bottom: 15px; /* Consistent spacing */
        }
        .account-section .form-group label {
            color: #555;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #004494;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>Edit User</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if ($user_data): ?>
            <form action="edit_user.php?id=<?php echo htmlspecialchars($user_id); ?>" method="POST" class="form-standard" enctype="multipart/form-data">
                <h3>User Information</h3>
                <div class="form-group">
                    <label for="id">User ID:</label>
                    <input type="text" id="id" name="id" value="<?php echo htmlspecialchars($user_id); ?>" readonly>
                </div>
                <div class="form-group">
                    <label for="membership_number">Membership Number:</label>
                    <input type="text" id="membership_number" name="membership_number" value="<?php echo htmlspecialchars($user_data['membership_number'] ?? ''); ?>" required>
                    <small>Membership number can be updated.</small>
                </div>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="home_address">Home Address</label>
                    <textarea id="home_address" name="home_address" rows="3" required><?php echo htmlspecialchars($user_data['home_address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($user_data['phone_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="nationality">Nationality</label>
                    <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($user_data['nationality'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($user_data['date_of_birth'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?php echo (($user_data['gender'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (($user_data['gender'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (($user_data['gender'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="occupation">Occupation</label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($user_data['occupation'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="profile_image">Profile Image (Max 5MB, JPG, PNG, GIF)</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <?php if (!empty($current_profile_image_url)): ?>
                        <p>Current Image:</p>
                        <img src="<?php echo htmlspecialchars($current_profile_image_url); ?>" alt="Profile Image" class="profile-image-preview">
                        <div style="margin-top: 10px;">
                            <input type="checkbox" id="remove_profile_image" name="remove_profile_image">
                            <label for="remove_profile_image">Remove current profile image</label>
                        </div>
                    <?php else: ?>
                        <p>No profile image uploaded.</p>
                    <?php endif; ?>
                    <small>Upload a new image to replace the current one, or tick "Remove current profile image" to delete it.</small>
                </div>

                <div class="form-group">
                    <label for="admin_created_at">Account Creation Date & Time</label>
                    <input type="datetime-local" id="admin_created_at" name="admin_created_at" value="<?php
                        if (isset($user_data['created_at']) && $user_data['created_at'] instanceof UTCDateTime) {
                            echo htmlspecialchars(date('Y-m-d\TH:i', $user_data['created_at']->toDateTime()->getTimestamp()));
                        } else {
                            echo '';
                        }
                    ?>" required>
                    <small>Set the exact date and time the user account was created.</small>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password (leave blank to keep current)</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password if changing">
                    <small>If you leave this blank, the user's password will not change.</small>
                </div>

                <hr style="margin: 30px 0;">

                <h3>Bank Accounts</h3>
                <?php if (empty($accounts_data)): ?>
                    <p>No bank accounts found for this user.</p>
                <?php else: ?>
                    <?php foreach ($accounts_data as $index => $account): ?>
                        <div class="account-section">
                            <h4>Account #<?php echo $index + 1; ?> (ID: <?php echo htmlspecialchars($account['_id']); ?>) - <?php echo htmlspecialchars($account['account_type'] ?? ''); ?></h4>
                            <input type="hidden" name="accounts[<?php echo $index; ?>][id]" value="<?php echo htmlspecialchars($account['_id']); ?>">

                            <div class="form-group">
                                <label for="account_type_<?php echo $index; ?>">Account Type:</label>
                                <select id="account_type_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][account_type]" required>
                                    <option value="">-- Select Account Type --</option>
                                    <option value="Checking" <?php echo (($account['account_type'] ?? '') == 'Checking') ? 'selected' : ''; ?>>Checking</option>
                                    <option value="Savings" <?php echo (($account['account_type'] ?? '') == 'Savings') ? 'selected' : ''; ?>>Savings</option>
                                    <option value="Current" <?php echo (($account['account_type'] ?? '') == 'Current') ? 'selected' : ''; ?>>Current</option>
                                    <option value="Fixed Deposit" <?php echo (($account['account_type'] ?? '') == 'Fixed Deposit') ? 'selected' : ''; ?>>Fixed Deposit</option>
                                    <option value="Loan" <?php echo (($account['account_type'] ?? '') == 'Loan') ? 'selected' : ''; ?>>Loan</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="account_number_<?php echo $index; ?>">Account Number:</label>
                                <input type="text" id="account_number_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][account_number]" value="<?php echo htmlspecialchars($account['account_number'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="balance_<?php echo $index; ?>">Balance:</label>
                                <input type="number" step="0.01" id="balance_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][balance]" value="<?php echo htmlspecialchars($account['balance'] ?? 0); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="currency_<?php echo $index; ?>">Currency:</label>
                                <select id="currency_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][currency]" required>
                                    <option value="">-- Select Currency --</option>
                                    <option value="USD" <?php echo (($account['currency'] ?? '') == 'USD') ? 'selected' : ''; ?>>USD</option>
                                    <option value="EUR" <?php echo (($account['currency'] ?? '') == 'EUR') ? 'selected' : ''; ?>>EUR</option>
                                    <option value="GBP" <?php echo (($account['currency'] ?? '') == 'GBP') ? 'selected' : ''; ?>>GBP</option>
                                    <option value="NGN" <?php echo (($account['currency'] ?? '') == 'NGN') ? 'selected' : ''; ?>>NGN</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="sort_code_<?php echo $index; ?>">Sort Code (e.g., 12-34-56 for GBP):</label>
                                <input type="text" id="sort_code_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][sort_code]" value="<?php echo htmlspecialchars($account['sort_code'] ?? ''); ?>">
                                <small>Required for GBP accounts.</small>
                            </div>
                            <div class="form-group">
                                <label for="iban_<?php echo $index; ?>">IBAN (e.g., for EUR accounts):</label>
                                <input type="text" id="iban_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][iban]" value="<?php echo htmlspecialchars($account['iban'] ?? ''); ?>">
                                <small>Required for EUR accounts.</small>
                            </div>
                            <div class="form-group">
                                <label for="swift_bic_<?php echo $index; ?>">SWIFT/BIC:</label>
                                <input type="text" id="swift_bic_<?php echo $index; ?>" name="accounts[<?php echo $index; ?>][swift_bic]" value="<?php echo htmlspecialchars($account['swift_bic'] ?? ''); ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <button type="submit" class="button-primary">Update User & Accounts</button>
            </form>
            <?php endif; ?>

            <p><a href="manage_users.php" class="back-link">&larr; Back to Manage Users</a></p>
        </div>
    </div>
    <script src="../script.js"></script>
</body>
</html>