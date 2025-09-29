<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\users\create_user.php

session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load Composer's autoloader for MongoDB classes and Dotenv
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../Config.php';
require_once __DIR__ . '/../../functions.php';

use MongoDB\Client;
use MongoDB\Exception\Exception as MongoDBException;
use MongoDB\Driver\Exception\BulkWriteException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/admin/login');
    exit;
}

$message = '';
$message_type = ''; // 'success' or 'error'

// Check for flash messages from previous redirects (e.g., from a successful creation)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Define HomeTown Bank's Fixed Identifiers (Fictional)
define('HOMETOWN_BANK_UK_BIC', 'HOMTGB2L343');
define('HOMETOWN_BANK_UK_SORT_CODE_PREFIX', '90');
define('HOMETOWN_BANK_EUR_BIC', 'HOMTDEFF5678');
define('HOMETOWN_BANK_EUR_BANK_CODE_BBAN', '50070010');
define('HOMETOWN_BANK_USD_BIC', 'HOMTUS333232');
// NEW: Fictional identifiers for CAD
define('HOMETOWN_BANK_CAD_BIC', 'HOMTCA2C300');
define('HOMETOWN_BANK_CAD_BANK_INSTITUTION_NUMBER', '010'); // Example Canadian Institution Number

/**
 * Helper function to generate a secure, random 4-digit PIN.
 * @return string The 4-digit numeric PIN.
 */
function generateRandomPin(): string {
    return str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Helper function to generate a unique numeric ID of a specific length.
 * Ensures the generated ID does not already exist in the specified MongoDB collection and field.
 *
 * @param MongoDB\Collection $collection The MongoDB collection object.
 * @param string $field The field name to check for uniqueness.
 * @param int $length The desired length of the numeric ID.
 * @return string|false The unique numeric ID as a string, or false on error.
 */
function generateUniqueNumericId($collection, $field, $length) {
    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $id_candidate = '';
        for ($i = 0; $i < $length; $i++) {
            if ($i === 0 && $length > 1) {
                $id_candidate .= mt_rand(1, 9);
            } else {
                $id_candidate .= mt_rand(0, 9);
            }
        }
        // Check uniqueness in MongoDB
        try {
            $existing_document = $collection->findOne([$field => $id_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_document) {
                return $id_candidate; // Found a unique ID
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during unique ID check ($collection->getCollectionName().$field): " . $e->getMessage());
            return false; // Indicate an error
        }
    }
    error_log("Failed to generate a unique Numeric ID for $collection->getCollectionName().$field after $max_attempts attempts.");
    return false; // Could not generate a unique ID
}

/**
 * Generates a 6-digit UK Sort Code.
 * Ensures uniqueness in the accounts collection.
 * @param MongoDB\Collection $accountsCollection The MongoDB accounts collection.
 * @return string The unique 6-digit UK Sort Code.
 */
function generateUniqueUkSortCode($accountsCollection): string|false {
    $max_attempts = 100;
    for ($i = 0; $i < $max_attempts; $i++) {
        $last_four = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $sort_code_candidate = HOMETOWN_BANK_UK_SORT_CODE_PREFIX . $last_four;
        try {
            $existing_account = $accountsCollection->findOne(['sort_code' => $sort_code_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return $sort_code_candidate;
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during sort code uniqueness check: " . $e->getMessage());
            return false;
        }
    }
    error_log("Failed to generate a unique UK Sort Code after $max_attempts attempts.");
    return false;
}

/**
 * Calculates a simplified IBAN check digits (NOT the real MOD97-10).
 * For production, use a robust IBAN generation library.
 * This function is a placeholder and should NOT be used for real banking systems.
 */
function calculateIbanCheckDigits(string $countryCode, string $bban): string {
    $iban_string_for_calc = $bban . strtoupper($countryCode) . '00';
    $numeric_string = '';
    for ($i = 0; $i < strlen($iban_string_for_calc); $i++) {
        $char = $iban_string_for_calc[$i];
        if (ctype_alpha($char)) {
            $numeric_string .= (string)(ord($char) - 55);
        } else {
            $numeric_string .= $char;
        }
    }
    // Using bcmod for large number support
    $checksum_val = bcmod($numeric_string, '97');
    $check_digits = 98 - (int)$checksum_val;
    return str_pad($check_digits, 2, '0', STR_PAD_LEFT);
}

/**
 * Generates a unique UK IBAN.
 */
function generateUniqueUkIban($accountsCollection, string $sortCode, string $internalAccountNumber8Digits): string|false {
    $countryCode = 'GB';
    $bankCode = substr(HOMETOWN_BANK_UK_BIC, 0, 4);
    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $bban_for_checksum = $bankCode . $sortCode . $internalAccountNumber8Digits;
        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);
        $iban_candidate = $countryCode . $checkDigits . $bankCode . $sortCode . $internalAccountNumber8Digits;
        try {
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return $iban_candidate;
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during IBAN uniqueness check: " . $e->getMessage());
            return false;
        }
        // In case of a collision, regenerate the internal account number and retry (unlikely for UK, but safer)
        $internalAccountNumber8Digits = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique UK IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique German-style EURO IBAN.
 */
function generateUniqueEurIban($accountsCollection, string $internalAccountNumber10Digits): string|false {
    $countryCode = 'DE';
    $bankleitzahl = HOMETOWN_BANK_EUR_BANK_CODE_BBAN;
    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $bban_for_checksum = $bankleitzahl . $internalAccountNumber10Digits;
        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);
        $iban_candidate = $countryCode . $checkDigits . $bankleitzahl . $internalAccountNumber10Digits;
        try {
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return $iban_candidate;
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during EUR IBAN uniqueness check: " . $e->getMessage());
            return false;
        }
        // In case of a collision, regenerate the internal account number and retry
        $internalAccountNumber10Digits = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique EUR IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique US-style "IBAN" (simulating a BBAN with routing number).
 * Returns an array: ['iban' => $iban_candidate, 'routing_number' => $generatedRoutingNumber]
 */
function generateUniqueUsdIban($accountsCollection, string $internalAccountNumberForUSD): array|false {
    $countryCode = 'US';
    // Use the function to get a unique 9-digit routing number
    $generatedRoutingNumber = generateUniqueNumericId($accountsCollection, 'routing_number', 9);
    if ($generatedRoutingNumber === false) {
        error_log("Failed to generate a unique US Routing Number for IBAN.");
        return false;
    }
    // Use the last 10 digits of the internal account number as the account number part
    $accountNumberPart = str_pad(substr($internalAccountNumberForUSD, -10), 10, '0', STR_PAD_LEFT);
    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN equivalent is Routing Number + Account Number Part
        $bban_for_checksum = $generatedRoutingNumber . $accountNumberPart;
        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);
        $iban_candidate = $countryCode . $checkDigits . $generatedRoutingNumber . $accountNumberPart;
        try {
            // Check for duplicate IBAN/BBAN
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return ['iban' => $iban_candidate, 'routing_number' => $generatedRoutingNumber];
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during USD 'IBAN' uniqueness check: " . $e->getMessage());
            return false;
        }
        // In case of a collision, regenerate the account number part and retry
        $accountNumberPart = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique US 'IBAN' after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique Canadian-style BBAN/Account structure.
 * Returns an array: ['iban' => $bban_candidate, 'transit_number' => $generatedTransitNumber]
 * Note: CAD does not use IBAN; the 'iban' field is used for the combined BBAN equivalent (Institution+Transit+Account).
 */
function generateUniqueCadBban($accountsCollection, string $internalAccountNumberForCAD): array|false {
    $institution_number = HOMETOWN_BANK_CAD_BANK_INSTITUTION_NUMBER; // 3-digit
    // Use the function to get a unique 5-digit Transit/Branch number
    $generatedTransitNumber = generateUniqueNumericId($accountsCollection, 'transit_number', 5);
    if ($generatedTransitNumber === false) {
        error_log("Failed to generate a unique CAD Transit Number.");
        return false;
    }
    // Use the last 7 digits of the internal account number as the account number part (Standard is 7-12 digits)
    $accountNumberPart = str_pad(substr($internalAccountNumberForCAD, -7), 7, '0', STR_PAD_LEFT);
    $max_attempts = 100;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // CAD BBAN equivalent (Institution + Transit + Account)
        $bban_candidate = $institution_number . $generatedTransitNumber . $accountNumberPart;
        try {
            // Check for duplicate BBAN
            $existing_account = $accountsCollection->findOne(['iban' => $bban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return ['iban' => $bban_candidate, 'transit_number' => $generatedTransitNumber];
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during CAD BBAN uniqueness check: " . $e->getMessage());
            return false;
        }
        // In case of a collision, regenerate the account number part and retry
        $accountNumberPart = str_pad(mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique CAD BBAN after $max_attempts attempts.");
    return false;
}

// NEW HELPER FUNCTION FOR GENERATING PRESIGNED B2 URLS
function getPresignedB2Url(string $objectKey, S3Client $b2Client, string $b2BucketName): string {
    if (empty($objectKey)) {
        return '';
    }
    try {
        $command = $b2Client->getCommand('GetObject', [
            'Bucket' => $b2BucketName,
            'Key' => $objectKey
        ]);
        $request = $b2Client->createPresignedRequest($command, '+1 hour');
        return (string) $request->getUri();
    } catch (S3Exception $e) {
        error_log("Error generating pre-signed URL for {$objectKey}: " . $e->getMessage());
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize user data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $transfer_pin = $_POST['transfer_pin'] ?? ''; // NEW: Transfer PIN
    $home_address = trim($_POST['home_address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $initial_balance = floatval($_POST['initial_balance'] ?? 0);
    // CAD added here
    $currency = trim($_POST['currency'] ?? 'GBP'); 
    $fund_account_type = trim($_POST['fund_account_type'] ?? '');

    $admin_created_at_raw = trim($_POST['admin_created_at'] ?? '');
    $admin_created_at_dt = null;
    if (!empty($admin_created_at_raw)) {
        $admin_created_at_dt = DateTime::createFromFormat('Y-m-d\TH:i', $admin_created_at_raw);
        if (!$admin_created_at_dt) {
            $admin_created_at_dt = DateTime::createFromFormat('Y-m-d H:i:s', $admin_created_at_raw);
        }
        if (!$admin_created_at_dt) {
            $admin_created_at_dt = new DateTime();
        }
    } else {
        $admin_created_at_dt = new DateTime();
    }
    $admin_created_at = $admin_created_at_dt;

    $profile_image_b2_key = null;
    $b2Client = null;

    // Initial form validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($home_address) || empty($phone_number) || empty($nationality) || empty($date_of_birth) || empty($gender) || empty($occupation) || empty($admin_created_at)) {
        $message = 'All required fields (marked with *) must be filled.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $message_type = 'error';
    // NEW: Transfer PIN Validation
    } elseif (!preg_match('/^\d{4}$/', $transfer_pin)) {
        $message = 'Transfer PIN must be exactly 4 numeric digits.';
        $message_type = 'error';
    } elseif ($initial_balance < 0) {
        $message = 'Initial balance cannot be negative.';
        $message_type = 'error';
    // CAD added to the allowed currencies
    } elseif (!in_array($currency, ['GBP', 'EUR', 'USD', 'CAD'])) {
        $message = 'Invalid currency selected. Only GBP, EURO, USD, and CAD are allowed.';
        $message_type = 'error';
    } elseif (!in_array($gender, ['Male', 'Female', 'Other'])) {
        $message = 'Invalid gender selected.';
        $message_type = 'error';
    } elseif (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date_of_birth) || !strtotime($date_of_birth)) {
        $message = 'Invalid Date of Birth format. Please use YYYY-MM-DD.';
        $message_type = 'error';
    } elseif ($initial_balance > 0 && empty($fund_account_type)) {
        $message = 'If an initial balance is provided, you must select an account type to fund.';
        $message_type = 'error';
    }

    // Only proceed with database operations if no validation errors
    if ($message_type !== 'error') {
        // START B2 PROFILE IMAGE UPLOAD LOGIC
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['profile_image']['tmp_name'];
            $file_name = $_FILES['profile_image']['name'];
            $file_size = $_FILES['profile_image']['size'];
            $file_type = $_FILES['profile_image']['type'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $max_file_size = 5 * 1024 * 1024; // 5MB limit

            if (!in_array($file_ext, $allowed_ext)) {
                $message = 'Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.';
                $message_type = 'error';
            } elseif ($file_size > $max_file_size) {
                $message = 'Image file size exceeds 5MB limit.';
                $message_type = 'error';
            } else {
                try {
                    $b2Client = new S3Client([
                        'version' => 'latest',
                        'region' => getenv('B2_REGION'),
                        'endpoint' => getenv('B2_S3_ENDPOINT'),
                        'credentials' => [
                            'key' => getenv('B2_ACCOUNT_ID'),
                            'secret' => getenv('B2_APPLICATION_KEY'),
                        ],
                        'use_path_style_endpoint' => true,
                    ]);

                    $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                    $profile_image_b2_key = 'profile_images/' . $new_file_name;

                    $result = $b2Client->putObject([
                        'Bucket' => getenv('B2_BUCKET_NAME'),
                        'Key' => $profile_image_b2_key,
                        'SourceFile' => $file_tmp_path,
                        'ContentType' => $file_type,
                    ]);
                } catch (S3Exception $e) {
                    $message = "Failed to upload profile image to cloud storage: " . $e->getMessage();
                    $message_type = 'error';
                    error_log("B2 Upload Error: " . $e->getMessage());
                } catch (Exception $e) {
                    $message = "An unexpected error occurred during image upload: " . $e->getMessage();
                    $message_type = 'error';
                    error_log("Image Upload General Error: " . $e->getMessage());
                }
            }
        }
        // END B2 PROFILE IMAGE UPLOAD LOGIC
    }

    // Only proceed with MongoDB if no errors so far
    if ($message_type !== 'error') {
        $mongoClient = null;
        $session = null;
        $transaction_success = false;
        $new_user_id = null;

        try {
            $mongoClient = new Client(MONGODB_CONNECTION_URI);
            $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

            $usersCollection = $mongoDb->users;
            $accountsCollection = $mongoDb->accounts;

            $session = $mongoClient->startSession();
            $session->startTransaction();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $hashed_transfer_pin = password_hash($transfer_pin, PASSWORD_DEFAULT); // NEW: Hash the Transfer PIN

            $membership_number = generateUniqueNumericId($usersCollection, 'membership_number', 12);
            if ($membership_number === false) {
                throw new Exception("Failed to generate unique Membership Number.");
            }

            $username_for_db = strtolower($first_name . '.' . $last_name);
            if (empty($username_for_db) || strlen($username_for_db) > 50) {
                $username_for_db = strtolower(explode('@', $email)[0]);
            }
            $original_username = $username_for_db;
            $counter = 1;
            while (true) {
                // Check if the username exists within the transaction
                $existing_username = $usersCollection->findOne(['username' => $username_for_db], ['projection' => ['_id' => 1]], ['session' => $session]);
                if (!$existing_username) {
                    break;
                }
                $username_for_db = $original_username . $counter++;
                if (strlen($username_for_db) > 50) {
                    $username_for_db = uniqid('user_');
                }
            }

            $user_document = [
                'username' => $username_for_db,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password_hash' => $hashed_password,
                'transfer_pin_hash' => $hashed_transfer_pin, // NEW: Store the HASHED PIN
                'home_address' => $home_address,
                'phone_number' => $phone_number,
                'nationality' => $nationality,
                'date_of_birth' => new MongoDB\BSON\UTCDateTime(strtotime($date_of_birth) * 1000),
                'gender' => $gender,
                'occupation' => $occupation,
                'membership_number' => $membership_number,
                'profile_image_key' => $profile_image_b2_key,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000),
                'status' => 'active',
                'last_login' => null,
                'email_verified' => false,
                'kyc_status' => 'pending',
                'two_factor_enabled' => true
            ];

            // Insert User Document
            $insert_user_result = $usersCollection->insertOne($user_document, ['session' => $session]);
            $new_user_id = $insert_user_result->getInsertedId();

            if (!$new_user_id) {
                throw new Exception("Failed to insert user document.");
            }

            $accounts_created_messages = [];
            $common_sort_code = NULL;
            $common_swift_bic = NULL;
            $common_routing_number = NULL;
            $common_transit_number = NULL; // New: For CAD

            if ($currency === 'GBP') {
                $common_sort_code = generateUniqueUkSortCode($accountsCollection);
                if ($common_sort_code === false) {
                    throw new Exception("Failed to generate a unique Sort Code for UK accounts.");
                }
                $common_swift_bic = HOMETOWN_BANK_UK_BIC;
            } elseif ($currency === 'EUR') {
                $common_sort_code = NULL;
                $common_swift_bic = HOMETOWN_BANK_EUR_BIC;
            } elseif ($currency === 'USD') {
                $common_sort_code = NULL;
                $common_swift_bic = HOMETOWN_BANK_USD_BIC;
            // NEW: Handle CAD currency specifics
            } elseif ($currency === 'CAD') { 
                $common_sort_code = NULL;
                $common_swift_bic = HOMETOWN_BANK_CAD_BIC;
            }

            // 1. Create Checking Account
            $checking_account_number = generateUniqueNumericId($accountsCollection, 'account_number', 12);
            if ($checking_account_number === false) {
                throw new Exception("Failed to generate unique Checking account number.");
            }

            $checking_iban = NULL;
            $checking_sort_code = NULL;
            $checking_routing_number = NULL;
            $checking_transit_number = NULL; // New: For CAD

            if ($currency === 'GBP') {
                $uk_iban_part_account_number = str_pad(substr($checking_account_number, -8), 8, '0', STR_PAD_LEFT);
                $checking_iban = generateUniqueUkIban($accountsCollection, $common_sort_code, $uk_iban_part_account_number);
                $checking_sort_code = $common_sort_code;
            } elseif ($currency === 'EUR') {
                $eur_iban_part_account_number = str_pad(substr($checking_account_number, -10), 10, '0', STR_PAD_LEFT);
                $checking_iban = generateUniqueEurIban($accountsCollection, $eur_iban_part_account_number);
            } elseif ($currency === 'USD') {
                $usd_iban_details = generateUniqueUsdIban($accountsCollection, $checking_account_number);
                if ($usd_iban_details !== false) {
                    $checking_iban = $usd_iban_details['iban'];
                    $checking_routing_number = $usd_iban_details['routing_number'];
                    $common_routing_number = $checking_routing_number;
                } else {
                    throw new Exception("Failed to generate unique USD 'IBAN' and Routing Number for Checking account.");
                }
            // NEW: CAD account generation
            } elseif ($currency === 'CAD') {
                $cad_bban_details = generateUniqueCadBban($accountsCollection, $checking_account_number);
                if ($cad_bban_details !== false) {
                    $checking_iban = $cad_bban_details['iban'];
                    $checking_transit_number = $cad_bban_details['transit_number'];
                    $common_transit_number = $checking_transit_number;
                } else {
                    throw new Exception("Failed to generate unique CAD BBAN and Transit Number for Checking account.");
                }
            }

            if ($checking_iban === false) {
                throw new Exception("Failed to generate unique IBAN/BBAN for Checking account.");
            }

            $checking_account_type = 'Checking';
            $checking_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Checking') ? $initial_balance : 0;

            $account_document_checking = [
                'user_id' => $new_user_id,
                'account_number' => $checking_account_number,
                'account_type' => $checking_account_type,
                'balance' => $checking_initial_balance,
                'currency' => $currency,
                'sort_code' => $checking_sort_code,
                'routing_number' => $checking_routing_number,
                'transit_number' => $checking_transit_number, // New field
                'iban' => $checking_iban,
                'swift_bic' => $common_swift_bic,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000),
                'status' => 'active'
            ];
            $accountsCollection->insertOne($account_document_checking, ['session' => $session]);
            $accounts_created_messages[] = "Checking Account: **" . $checking_account_number . "** (Balance: " . number_format($checking_initial_balance, 2) . " " . $currency . ")<br>IBAN/BBAN: **" . $checking_iban . "**";
            
            // 2. Create Savings Account
            $savings_account_number = generateUniqueNumericId($accountsCollection, 'account_number', 12);
            if ($savings_account_number === false) {
                throw new Exception("Failed to generate unique Savings account number.");
            }

            $savings_iban = NULL;
            $savings_sort_code = NULL;
            $savings_routing_number = NULL;
            $savings_transit_number = NULL; // New: For CAD

            if ($currency === 'GBP') {
                $uk_iban_part_account_number = str_pad(substr($savings_account_number, -8), 8, '0', STR_PAD_LEFT);
                $savings_iban = generateUniqueUkIban($accountsCollection, $common_sort_code, $uk_iban_part_account_number);
                $savings_sort_code = $common_sort_code;
            } elseif ($currency === 'EUR') {
                $eur_iban_part_account_number = str_pad(substr($savings_account_number, -10), 10, '0', STR_PAD_LEFT);
                $savings_iban = generateUniqueEurIban($accountsCollection, $eur_iban_part_account_number);
            } elseif ($currency === 'USD') {
                $usd_iban_details_savings = generateUniqueUsdIban($accountsCollection, $savings_account_number);
                // Note: For USD, we re-use the common routing number, but regenerate the IBAN/BBAN for uniqueness check
                if ($usd_iban_details_savings !== false) {
                    $savings_iban = $usd_iban_details_savings['iban'];
                    // We explicitly set the routing number to the common one, which was set by the checking account, 
                    // although the USD generation function re-generated one internally for the IBAN checksum, 
                    // the common one is what would be used for wire transfers.
                    $savings_routing_number = $common_routing_number; 
                } else {
                    throw new Exception("Failed to generate unique USD 'IBAN' for Savings account.");
                }
            // NEW: CAD account generation (re-using common transit number)
            } elseif ($currency === 'CAD') {
                $cad_bban_details_savings = generateUniqueCadBban($accountsCollection, $savings_account_number);
                if ($cad_bban_details_savings !== false) {
                    $savings_iban = $cad_bban_details_savings['iban'];
                    $savings_transit_number = $common_transit_number;
                } else {
                    throw new Exception("Failed to generate unique CAD BBAN for Savings account.");
                }
            }

            if ($savings_iban === false) {
                throw new Exception("Failed to generate unique IBAN/BBAN for Savings account.");
            }

            $savings_account_type = 'Savings';
            $savings_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Savings') ? $initial_balance : 0;

            $account_document_savings = [
                'user_id' => $new_user_id,
                'account_number' => $savings_account_number,
                'account_type' => $savings_account_type,
                'balance' => $savings_initial_balance,
                'currency' => $currency,
                'sort_code' => $savings_sort_code,
                'routing_number' => $savings_routing_number,
                'transit_number' => $savings_transit_number, // New field
                'iban' => $savings_iban,
                'swift_bic' => $common_swift_bic,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000),
                'status' => 'active'
            ];
            $accountsCollection->insertOne($account_document_savings, ['session' => $session]);
            $accounts_created_messages[] = "Savings Account: **" . $savings_account_number . "** (Balance: " . number_format($savings_initial_balance, 2) . " " . $currency . ")<br>IBAN/BBAN: **" . $savings_iban . "**";

            if ($common_sort_code) $accounts_created_messages[] = "User's Common Sort Code: **" . $common_sort_code . "**";
            if ($common_routing_number) $accounts_created_messages[] = "User's Common Routing Number: **" . $common_routing_number . "**";
            if ($common_transit_number) $accounts_created_messages[] = "User's Common Transit Number: **" . $common_transit_number . "** (Institution: " . HOMETOWN_BANK_CAD_BANK_INSTITUTION_NUMBER . ")";
            if ($common_swift_bic) $accounts_created_messages[] = "User's Common SWIFT/BIC: **" . $common_swift_bic . "**";
            $accounts_created_messages[] = "User's Transfer PIN (Unhashed): **{$transfer_pin}**"; // IMPORTANT: Show unhashed PIN to Admin ONLY upon creation

            $session->commitTransaction();
            $transaction_success = true;
            
            $_SESSION['flash_message'] = "User '{$first_name} {$last_name}' created successfully! <br>Membership Number: **{$membership_number}**<br>Initial Account Details:<br>" . implode("<br>", $accounts_created_messages);
            $_SESSION['flash_message_type'] = 'success';
            header('Location: ' . rtrim(BASE_URL, '/') . '/admin/manage_users');
            exit;

        } catch (BulkWriteException $e) {
            $error_code = $e->getCode();
            $message = ($error_code === 11000) ? "Error creating user/account: A unique value already exists." : "Database write error: " . $e->getMessage();
            $message_type = 'error';
            error_log("MongoDB BulkWriteException: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        } catch (MongoDBException $e) {
            $message = "MongoDB Error: " . $e->getMessage();
            $message_type = 'error';
            error_log("MongoDB General Error: " . $e->getMessage());
        } catch (Exception $e) {
            $message = "An unexpected error occurred: " . $e->getMessage();
            $message_type = 'error';
            error_log("General Exception: " . $e->getMessage());
        } finally {
            if (isset($session)) {
                // Check if a transaction is still active (e.g., if an exception occurred before commit)
                try {
                    $session->abortTransaction();
                } catch (\MongoDB\Driver\Exception\CommandException $e) {
                    // Ignore if transaction was already committed/aborted
                }
                $session->endSession();
            }
            // Image cleanup logic on rollback
            if (!$transaction_success && $profile_image_b2_key && isset($b2Client)) {
                try {
                    $b2Client->deleteObject([
                        'Bucket' => getenv('B2_BUCKET_NAME'),
                        'Key' => $profile_image_b2_key,
                    ]);
                    error_log("Uploaded B2 object deleted due to transaction rollback: " . $profile_image_b2_key);
                } catch (S3Exception $deleteE) {
                    error_log("Error deleting B2 object on rollback: " . $deleteE->getMessage());
                } catch (Exception $deleteE) {
                    error_log("General error deleting B2 object on rollback: " . $deleteE->getMessage());
                }
            }
        }
    }
}

// Generate a default PIN for the form input
$default_pin = generateRandomPin(); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - Create User</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Custom styles to mimic common app layout and colors */
        .bg-primary { background-color: #007bff; }
        .text-primary { color: #007bff; }
        .bg-secondary { background-color: #28a745; }
        .bg-danger { background-color: #dc3545; }
        .bg-card { background-color: #ffffff; }
        .border-gray-light { border-color: #ddd; }
        .bg-gray-lighter { background-color: #f8f9fa; }
        .required-asterisk { color: #dc3545; font-weight: bold; margin-left: 5px; }
        .readonly-field { background-color: #e9e9e9; cursor: not-allowed; }
    </style>
</head>
<body class="font-sans bg-gray-lighter p-4 md:p-8 min-h-screen">
    <div class="max-w-7xl mx-auto rounded-xl shadow-2xl bg-card flex flex-col min-h-full">
        <header class="bg-primary text-white p-4 md:p-6 flex items-center justify-between rounded-t-xl">
            <div class="flex items-center">
                <img src="<?php echo rtrim(BASE_URL, '/'); ?>/images/logo.png" alt="HomeTown Bank Logo" class="h-8 w-auto mr-4">
                <h2 class="text-2xl font-bold">Create New User</h2>
            </div>
            <a href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/logout.php" class="bg-danger hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-300 text-sm">Logout</a>
        </header>

        <main class="p-6 md:p-10 flex-grow">
            <?php if (!empty($message)): ?>
                <p class="p-4 mb-6 rounded-lg font-medium 
                    <?php echo ($message_type === 'success') ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                    <?php echo nl2br(htmlspecialchars($message)); ?>
                </p>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars(rtrim(BASE_URL, '/')); ?>/heritagebank_admin/users/create_user.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6" enctype="multipart/form-data">
                
                <h3 class="md:col-span-2 text-xl font-bold text-primary border-b pb-2 mb-4">Personal Information</h3>
                
                <div class="flex flex-col">
                    <label for="first_name" class="font-semibold text-gray-700 mb-1">First Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>
                <div class="flex flex-col">
                    <label for="last_name" class="font-semibold text-gray-700 mb-1">Last Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>
                
                <div class="flex flex-col">
                    <label for="email" class="font-semibold text-gray-700 mb-1">Email Address <span class="required-asterisk">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>
                <div class="flex flex-col">
                    <label for="password" class="font-semibold text-gray-700 mb-1">Password <span class="required-asterisk">*</span></label>
                    <input type="password" id="password" name="password" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>

                <div class="flex flex-col md:col-span-2">
                    <label for="transfer_pin" class="font-semibold text-gray-700 mb-1">Transfer PIN (4 Digits) <span class="required-asterisk">*</span></label>
                    <input type="text" 
                        id="transfer_pin" 
                        name="transfer_pin" 
                        maxlength="4"
                        placeholder="e.g., 1234"
                        value="<?php echo htmlspecialchars($_POST['transfer_pin'] ?? $default_pin); ?>" 
                        required 
                        pattern="\d{4}" 
                        title="Transfer PIN must be exactly 4 numeric digits."
                        class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary w-full md:w-1/2">
                    <small class="mt-1 text-sm text-gray-500">This 4-digit PIN is required for all customer transfers. **Must be numeric.**</small>
                </div>
                
                <div class="flex flex-col md:col-span-2">
                    <label for="home_address" class="font-semibold text-gray-700 mb-1">Home Address <span class="required-asterisk">*</span></label>
                    <textarea id="home_address" name="home_address" rows="3" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary"><?php echo htmlspecialchars($_POST['home_address'] ?? ''); ?></textarea>
                </div>
                <div class="flex flex-col">
                    <label for="phone_number" class="font-semibold text-gray-700 mb-1">Phone Number <span class="required-asterisk">*</span></label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>
                <div class="flex flex-col">
                    <label for="nationality" class="font-semibold text-gray-700 mb-1">Nationality <span class="required-asterisk">*</span></label>
                    <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($_POST['nationality'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>

                <div class="flex flex-col">
                    <label for="date_of_birth" class="font-semibold text-gray-700 mb-1">Date of Birth <span class="required-asterisk">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>
                <div class="flex flex-col">
                    <label for="gender" class="font-semibold text-gray-700 mb-1">Gender <span class="required-asterisk">*</span></label>
                    <select id="gender" name="gender" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?php echo (($_POST['gender'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (($_POST['gender'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (($_POST['gender'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="flex flex-col">
                    <label for="occupation" class="font-semibold text-gray-700 mb-1">Occupation <span class="required-asterisk">*</span></label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                </div>

                <div class="flex flex-col">
                    <label for="profile_image" class="font-semibold text-gray-700 mb-1">Profile Image (Max 5MB, JPG, PNG, GIF)</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*" class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                    <small class="mt-1 text-sm text-gray-500">Optional: You can upload a profile picture for the user.</small>
                </div>

                <h3 class="md:col-span-2 text-xl font-bold text-primary border-b pb-2 mb-4 mt-4">Account Details</h3>

                <div class="flex flex-col">
                    <label for="currency" class="font-semibold text-gray-700 mb-1">Account Currency <span class="required-asterisk">*</span></label>
                    <select id="currency" name="currency" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                        <option value="GBP" <?php echo (($_POST['currency'] ?? 'GBP') == 'GBP') ? 'selected' : ''; ?>>GBP</option>
                        <option value="EUR" <?php echo (($_POST['currency'] ?? '') == 'EUR') ? 'selected' : ''; ?>>EURO</option>
                        <option value="USD" <?php echo (($_POST['currency'] ?? '') == 'USD') ? 'selected' : ''; ?>>USD</option>
                        <option value="CAD" <?php echo (($_POST['currency'] ?? '') == 'CAD') ? 'selected' : ''; ?>>CAD</option>
                    </select>
                </div>
                <div class="flex flex-col">
                    <label for="routing_number_display" class="font-semibold text-gray-700 mb-1">Routing/Transit Number</label>
                    <input type="text" id="routing_number_display" name="routing_number_display" value="Auto-generated upon creation" readonly class="readonly-field p-3 border border-gray-300 rounded-lg">
                    <small class="mt-1 text-sm text-gray-500">This is automatically generated based on the currency (Routing for USD, Transit for CAD).</small>
                </div>

                <div class="flex flex-col">
                    <label for="initial_balance" class="font-semibold text-gray-700 mb-1">Initial Balance Amount (Optional)</label>
                    <input type="number" id="initial_balance" name="initial_balance" step="0.01" value="<?php echo htmlspecialchars($_POST['initial_balance'] ?? '0.00'); ?>" class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                    <small class="mt-1 text-sm text-gray-500">Enter an amount to initially fund the user's chosen account type. Leave 0.00 if no initial funding.</small>
                </div>
                <div class="flex flex-col">
                    <label for="fund_account_type" class="font-semibold text-gray-700 mb-1">Fund Which Account? (Optional)</label>
                    <select id="fund_account_type" name="fund_account_type" class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary">
                        <option value="">-- Select Account Type (Optional) --</option>
                        <option value="Checking" <?php echo (($_POST['fund_account_type'] ?? '') == 'Checking') ? 'selected' : ''; ?>>Checking Account</option>
                        <option value="Savings" <?php echo (($_POST['fund_account_type'] ?? '') == 'Savings') ? 'selected' : ''; ?>>Savings Account</option>
                    </select>
                    <small class="mt-1 text-sm text-gray-500">If an initial balance is provided, you must select an account type here.</small>
                </div>

                <div class="flex flex-col md:col-span-2">
                    <label for="admin_created_at" class="font-semibold text-gray-700 mb-1">Account Creation Date & Time <span class="required-asterisk">*</span></label>
                    <input type="datetime-local" id="admin_created_at" name="admin_created_at" value="<?php echo htmlspecialchars($_POST['admin_created_at'] ?? date('Y-m-d\TH:i')); ?>" required class="p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary w-full md:w-1/2">
                    <small class="mt-1 text-sm text-gray-500">Set the exact date and time the account was (or should be) created.</small>
                </div>
                
                <div class="md:col-span-2 pt-4">
                    <button type="submit" class="w-full md:w-auto bg-secondary hover:bg-green-600 text-white font-bold py-3 px-6 rounded-lg transition duration-300 text-lg">
                        Create User
                    </button>
                </div>
            </form>

            <p class="mt-6"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/admin/users/users_management.php" class="text-primary hover:underline font-semibold">&larr; Back to User Management</a></p>
        </main>
    </div>
</body>
</html>