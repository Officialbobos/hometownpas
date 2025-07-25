<?php
session_start();
// Ensure Config.php is correctly included. It should be one level up from admin/users/
require_once __DIR__ . '/../../Config.php';
require_once '../../functions.php'; // This is good to have for future database operations


// Use the MongoDB Client class
use MongoDB\Client;
use MongoDB\Exception\Exception as MongoDBException; // Catch general MongoDB exceptions
use MongoDB\Driver\Exception\BulkWriteException; // Specific exception for write errors (e.g., duplicate key)

// Check if admin is logged in
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: ../index.php'); // Correct path from users folder to HERITAGEBANK_ADMIN/index.php
    exit;
}

$message = '';
$message_type = ''; // 'success' or 'error'

// Define the upload directory using an absolute path relative to the Railway /app root
define('UPLOAD_DIR', '/app/uploads/profile_images/');

// Attempt to create the upload directory if it doesn't exist
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0777, true)) {
        error_log('PHP Error: Failed to create upload directory: ' . UPLOAD_DIR . '. Please check underlying permissions or path.');
        // If directory creation fails, set an error message and prevent further processing.
        $message = 'Server configuration error: Could not create upload directory. Contact administrator.';
        $message_type = 'error';
        // We'll handle this with `goto` later if it's set here.
    }
}

// --- Define HomeTown Bank's Fixed Identifiers (Fictional) ---
// These would be real bank details in a production system.
// For simulation, these are consistent for "HomeTown Bank" for a given currency/region.

// For UK (GBP) accounts - BIC must be 11 chars
define('HOMETOWN_BANK_UK_BIC', 'HOMTGB2LXXX'); // Fictional BIC for HomeTown Bank UK
// UK Sort Code is 6 digits. We will generate the last 4.
define('HOMETOWN_BANK_UK_SORT_CODE_PREFIX', '90'); // Example: '90xxxx'

// For EURO (EUR) accounts (e.g., simulating a German branch for Euro operations) - BIC must be 11 chars
define('HOMETOWN_BANK_EUR_BIC', 'HOMTDEFFXXX'); // Fictional BIC for HomeTown Bank Europe (Germany)
// Fictional German Bankleitzahl (BLZ) part of BBAN for EUR IBANs.
// German BLZ is 8 digits.
define('HOMETOWN_BANK_EUR_BANK_CODE_BBAN', '50070010');

// For US (USD) accounts - BIC must be 11 chars
define('HOMETOWN_BANK_USD_BIC', 'HOMTUS33XXX'); // Fictional BIC for HomeTown Bank USA
// ABA Routing Transit Number (RTN) is 9 digits for US banks
// We will generate the full 9 digits dynamically per account to make them unique.

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
            return false; // Indicate an F:\xampp\htdocs\phpfile-main\admin\users\create_user.php
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
    // This is a simplified placeholder checksum calculation.
    // Real IBAN calculation uses MOD 97-10 on a string representation
    // where letters are mapped to numbers (A=10, B=11, etc.).
    // For demonstration, this aims to return 2 digits.

    $iban_string_for_calc = $bban . strtoupper($countryCode) . '00'; // Append country code + '00' for calculation
    $numeric_string = '';
    for ($i = 0; $i < strlen($iban_string_for_calc); $i++) {
        $char = $iban_string_for_calc[$i];
        if (ctype_alpha($char)) {
            $numeric_string .= (string)(ord($char) - 55); // A=10, B=11, ..., Z=35
        } else {
            $numeric_string .= $char;
        }
    }

    // Use bcmod for large numbers
    $checksum_val = bcmod($numeric_string, '97');
    $check_digits = 98 - (int)$checksum_val;

    return str_pad($check_digits, 2, '0', STR_PAD_LEFT);
}

/**
 * Generates a unique UK IBAN.
 * Format: GBkk BBBB SSSSSS AAAAAAAA
 * GB (Country Code), kk (Check Digits), BBBB (Bank Code from BIC), SSSSSS (Sort Code), AAAAAAAA (Account Number, typically 8 digits)
 * The total length will be 22 characters (GB + 2 + 4 + 6 + 8).
 *
 * @param MongoDB\Collection $accountsCollection The MongoDB accounts collection.
 * @param string $sortCode The 6-digit sort code for this account.
 * @param string $internalAccountNumber8Digits The 8-digit internal account number part for IBAN.
 * @return string|false The unique UK IBAN, or false on error.
 */
function generateUniqueUkIban($accountsCollection, string $sortCode, string $internalAccountNumber8Digits): string|false {
    $countryCode = 'GB';
    $bankCode = substr(HOMETOWN_BANK_UK_BIC, 0, 4); // First 4 chars of BIC as Bank Code

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: Bank Code (4) + Sort Code (6) + Account Number (8)
        $bban_for_checksum = $bankCode . $sortCode . $internalAccountNumber8Digits;

        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $bankCode . $sortCode . $internalAccountNumber8Digits;

        // Check for uniqueness in the database
        try {
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return $iban_candidate; // Found a unique IBAN
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during IBAN uniqueness check: " . $e->getMessage());
            return false;
        }

        // If IBAN exists, regenerate the 8-digit account number part and try again
        $internalAccountNumber8Digits = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique UK IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique German-style EURO IBAN.
 * Format: DEkk BBBBBBBB AAAAAAAAAA
 * DE (Country Code), kk (Check Digits), BBBBBBBB (German Bankleitzahl - 8 digits), AAAAAAAAAA (German Account Number - 10 digits)
 * The total length will be 22 characters (DE + 2 + 8 + 10).
 *
 * @param MongoDB\Collection $accountsCollection The MongoDB accounts collection.
 * @param string $internalAccountNumber10Digits The 10-digit internal account number part for IBAN.
 * @return string|false The unique EURO IBAN, or false on error.
 */
function generateUniqueEurIban($accountsCollection, string $internalAccountNumber10Digits): string|false {
    $countryCode = 'DE'; // Example: German IBAN structure
    $bankleitzahl = HOMETOWN_BANK_EUR_BANK_CODE_BBAN; // Fictional German Bankleitzahl (8 digits)

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: BLZ (8) + Account Number (10)
        $bban_for_checksum = $bankleitzahl . $internalAccountNumber10Digits;

        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $bankleitzahl . $internalAccountNumber10Digits;

        // Check for uniqueness in the database
        try {
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                return $iban_candidate; // Found a unique IBAN
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during EUR IBAN uniqueness check: " . $e->getMessage());
            return false;
        }
        // If IBAN exists, regenerate the 10-digit account number part and try again
        $internalAccountNumber10Digits = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique EUR IBAN after $max_attempts attempts.");
    return false;
}

/**
 * Generates a unique US-style "IBAN" (simulating a BBAN with routing number).
 * This function will create a string in the 'iban' column that reflects this.
 * Format: USkk RRRRRRRRR AAAAAAAAAAAA (US + Check Digits + Routing Number + Account Number)
 *
 * @param MongoDB\Collection $accountsCollection The MongoDB accounts collection.
 * @param string $internalAccountNumberForUSD The internal account number, will be padded/truncated as needed.
 * @return array|false The unique US "IBAN" (BBAN) and its routing number, or false on error.
 */
function generateUniqueUsdIban($accountsCollection, string $internalAccountNumberForUSD): array|false {
    $countryCode = 'US';

    // The routing number needs to be generated and unique for each account (or at least for the first of a user).
    // This is now checked against the 'accounts' collection's 'routing_number' field.
    $generatedRoutingNumber = generateUniqueNumericId($accountsCollection, 'routing_number', 9);
    if ($generatedRoutingNumber === false) {
        error_log("Failed to generate a unique US Routing Number for IBAN.");
        return false;
    }

    // US account numbers can be variable length. Let's aim for a 10-digit number for the IBAN part
    // and ensure the internalAccountNumberForUSD is at least 10 digits for this
    $accountNumberPart = str_pad(substr($internalAccountNumberForUSD, -10), 10, '0', STR_PAD_LEFT);

    $max_attempts = 100;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // BBAN for checksum calculation: Routing Number (9) + Account Number (10)
        $bban_for_checksum = $generatedRoutingNumber . $accountNumberPart;

        // Use the simplified IBAN check digits, although real US 'IBANs' are not standard
        $checkDigits = calculateIbanCheckDigits($countryCode, $bban_for_checksum);

        $iban_candidate = $countryCode . $checkDigits . $generatedRoutingNumber . $accountNumberPart;

        // Check for uniqueness in the database
        try {
            $existing_account = $accountsCollection->findOne(['iban' => $iban_candidate], ['projection' => ['_id' => 1]]);
            if (!$existing_account) {
                // Return both the generated IBAN and the routing number so it can be stored separately
                return ['iban' => $iban_candidate, 'routing_number' => $generatedRoutingNumber];
            }
        } catch (MongoDBException $e) {
            error_log("MongoDB Error during USD 'IBAN' uniqueness check: " . $e->getMessage());
            return false;
        }

        // If "IBAN" exists, regenerate the 10-digit account number part and try again
        $accountNumberPart = str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    error_log("Failed to generate a unique US 'IBAN' after $max_attempts attempts.");
    return false;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize user data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? ''; // Don't trim password
    $home_address = trim($_POST['home_address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');

    $initial_balance = floatval($_POST['initial_balance'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'GBP');
    $fund_account_type = trim($_POST['fund_account_type'] ?? '');

    // Admin determined creation timestamp
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
    // Store as MongoDB Date object (or ISO 8601 string)
    // For MongoDB, it's best to store dates as BSON Date type or ISO 8601 strings.
    // The PHP driver handles DateTime objects directly for BSON Date.
    $admin_created_at = $admin_created_at_dt; // Keep as DateTime object for MongoDB

    $profile_image_path = null;
    $uploaded_file_full_path = null;

    // Initial validation
    if (!empty($message_type) && $message_type == 'error') { // Check if UPLOAD_DIR creation failed earlier
        goto end_of_post_processing;
    }
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($home_address) || empty($phone_number) || empty($nationality) || empty($date_of_birth) || empty($gender) || empty($occupation) || empty($admin_created_at)) {
        $message = 'All required fields (marked with *) must be filled.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $message_type = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
        $message_type = 'error';
    } elseif ($initial_balance < 0) {
        $message = 'Initial balance cannot be negative.';
        $message_type = 'error';
    } elseif (!in_array($currency, ['GBP', 'EUR', 'USD'])) {
        $message = 'Invalid currency selected. Only GBP, EURO, and USD are allowed.';
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
    } else {
        // --- Handle Profile Image Upload (Optional) ---
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['profile_image']['tmp_name'];
            $file_name = $_FILES['profile_image']['name'];
            $file_size = $_FILES['profile_image']['size'];
            $file_type = $_FILES['profile_image']['type'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_ext)) {
                $message = 'Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.';
                $message_type = 'error';
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $message = 'Image file size exceeds 5MB limit.';
                $message_type = 'error';
            } else {
                $new_file_name = uniqid('profile_', true) . '.' . $file_ext;
                $target_file_path = UPLOAD_DIR . $new_file_name;

                if (move_uploaded_file($file_tmp_path, $target_file_path)) {
                    $profile_image_path = 'uploads/profile_images/' . $new_file_name;
                    $uploaded_file_full_path = $target_file_path;
                } else {
                    $message = 'Failed to upload profile image.';
                    $message_type = 'error';
                }
            }
        }
        if ($message_type == 'error') {
            goto end_of_post_processing;
        }

        // --- MongoDB Operations ---
        $mongoClient = null; // Declare outside try for finally block scope
        $session = null;
        $transaction_success = true; // Assume success until an error occurs

        try {
            // Establish MongoDB connection
            $mongoClient = new Client(MONGODB_CONNECTION_URI);
            $mongoDb = $mongoClient->selectDatabase(MONGODB_DB_NAME);

            // Get collections
            $usersCollection = $mongoDb->users;
            $accountsCollection = $mongoDb->accounts;

            // Start a session and a transaction for atomicity
            $session = $mongoClient->startSession();
            $session->startTransaction();

            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Generate unique Membership Number (12 digits, numeric only)
            $membership_number = generateUniqueNumericId($usersCollection, 'membership_number', 12);
            if ($membership_number === false) {
                throw new Exception("Failed to generate unique Membership Number.");
            }

            // Determine and ensure unique username
            $username_for_db = strtolower($first_name . '.' . $last_name);
            if (empty($username_for_db) || strlen($username_for_db) > 50) {
                $username_for_db = strtolower(explode('@', $email)[0]);
            }
            $original_username = $username_for_db;
            $counter = 1;
            while (true) {
                $existing_username = $usersCollection->findOne(['username' => $username_for_db], ['projection' => ['_id' => 1]], ['session' => $session]);
                if (!$existing_username) {
                    break;
                }
                $username_for_db = $original_username . $counter++;
                if (strlen($username_for_db) > 50) {
                    $username_for_db = uniqid('user_');
                }
            }

            // Prepare user document for insertion
            $user_document = [
                'username' => $username_for_db,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password_hash' => $hashed_password,
                'home_address' => $home_address,
                'phone_number' => $phone_number,
                'nationality' => $nationality,
                'date_of_birth' => new MongoDB\BSON\UTCDateTime(strtotime($date_of_birth) * 1000), // Store DOB as BSON Date
                'gender' => $gender,
                'occupation' => $occupation,
                'membership_number' => $membership_number,
                'profile_image' => $profile_image_path,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000), // Store as BSON Date
                'status' => 'active', // Default status for new users
                'last_login' => null,
                'email_verified' => false,
                'kyc_status' => 'pending',
                'two_factor_enabled' => true // <-- ADDED THIS LINE: 2FA is enabled by default for new users
            ];

            // Insert user document
            $insert_user_result = $usersCollection->insertOne($user_document, ['session' => $session]);
            $new_user_id = $insert_user_result->getInsertedId(); // Get the MongoDB ObjectId

            if (!$new_user_id) {
                throw new Exception("Failed to insert user document.");
            }

            $accounts_created_messages = [];

            // Determine common sort_code, swift_bic, and routing_number based on currency once
            $common_sort_code = NULL;
            $common_swift_bic = NULL;
            $common_routing_number = NULL; // For USD

            if ($currency === 'GBP') {
                $common_sort_code = generateUniqueUkSortCode($accountsCollection);
                if ($common_sort_code === false) {
                    throw new Exception("Failed to generate a unique Sort Code for UK accounts.");
                }
                $common_swift_bic = HOMETOWN_BANK_UK_BIC;
            } elseif ($currency === 'EUR') {
                $common_sort_code = NULL; // Not applicable for EUR IBAN (uses BLZ which is part of IBAN BBAN)
                $common_swift_bic = HOMETOWN_BANK_EUR_BIC;
            } elseif ($currency === 'USD') {
                $common_sort_code = NULL; // Not applicable for US sort code
                $common_swift_bic = HOMETOWN_BANK_USD_BIC;
                // For USD, the routing number is generated *inside* generateUniqueUsdIban and returned.
                // We will store this generated routing number in the 'routing_number' field.
                // We'll capture it here to potentially reuse for multiple USD accounts if applicable.
            }

            // 1. Create Checking Account
            $checking_account_number = generateUniqueNumericId($accountsCollection, 'account_number', 12);
            if ($checking_account_number === false) {
                throw new Exception("Failed to generate unique Checking account number.");
            }

            $checking_iban = NULL;
            $checking_sort_code = NULL;
            $checking_routing_number = NULL;

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
                    $common_routing_number = $checking_routing_number; // Store for reuse
                } else {
                    throw new Exception("Failed to generate unique USD 'IBAN' and Routing Number for Checking account.");
                }
            }

            if ($checking_iban === false) {
                throw new Exception("Failed to generate unique IBAN/BBAN for Checking account.");
            }

            $checking_account_type = 'Checking';
            $checking_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Checking') ? $initial_balance : 0;

            $account_document_checking = [
                'user_id' => $new_user_id, // Reference to the user's ObjectId
                'account_number' => $checking_account_number,
                'account_type' => $checking_account_type,
                'balance' => $checking_initial_balance,
                'currency' => $currency,
                'sort_code' => $checking_sort_code,
                'routing_number' => $checking_routing_number,
                'iban' => $checking_iban,
                'swift_bic' => $common_swift_bic,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000), // Store as BSON Date
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

            if ($currency === 'GBP') {
                $uk_iban_part_account_number = str_pad(substr($savings_account_number, -8), 8, '0', STR_PAD_LEFT);
                $savings_iban = generateUniqueUkIban($accountsCollection, $common_sort_code, $uk_iban_part_account_number);
                $savings_sort_code = $common_sort_code;
            } elseif ($currency === 'EUR') {
                $eur_iban_part_account_number = str_pad(substr($savings_account_number, -10), 10, '0', STR_PAD_LEFT);
                $savings_iban = generateUniqueEurIban($accountsCollection, $eur_iban_part_account_number);
            } elseif ($currency === 'USD') {
                // Reuse the common_routing_number generated for Checking for Savings.
                $usd_iban_details_savings = generateUniqueUsdIban($accountsCollection, $savings_account_number);
                if ($usd_iban_details_savings !== false) {
                    $savings_iban = $usd_iban_details_savings['iban'];
                    // Important: For USD, we should reuse the same routing number for all accounts under a user/bank if they are truly linked.
                    // If you want a *different* routing number per account, then you would use $usd_iban_details_savings['routing_number'].
                    // Your original logic implies reuse by assigning $common_routing_number for checking.
                    // If common_routing_number is set, use it, otherwise use the one just generated for savings (shouldn't happen if checking succeeded)
                    $savings_routing_number = $common_routing_number ?? $usd_iban_details_savings['routing_number'];
                } else {
                    throw new Exception("Failed to generate unique USD 'IBAN' for Savings account.");
                }
            }

            if ($savings_iban === false) {
                throw new Exception("Failed to generate unique IBAN/BBAN for Savings account.");
            }

            $savings_account_type = 'Savings';
            $savings_initial_balance = ($initial_balance > 0 && $fund_account_type === 'Savings') ? $initial_balance : 0;

            $account_document_savings = [
                'user_id' => $new_user_id, // Reference to the user's ObjectId
                'account_number' => $savings_account_number,
                'account_type' => $savings_account_type,
                'balance' => $savings_initial_balance,
                'currency' => $currency,
                'sort_code' => $savings_sort_code,
                'routing_number' => $savings_routing_number,
                'iban' => $savings_iban,
                'swift_bic' => $common_swift_bic,
                'created_at' => new MongoDB\BSON\UTCDateTime($admin_created_at->getTimestamp() * 1000),
                'status' => 'active'
            ];
            $accountsCollection->insertOne($account_document_savings, ['session' => $session]);
            $accounts_created_messages[] = "Savings Account: **" . $savings_account_number . "** (Balance: " . number_format($savings_initial_balance, 2) . " " . $currency . ")<br>IBAN/BBAN: **" . $savings_iban . "**";

            // Add common bank details to success message
            if ($common_sort_code) $accounts_created_messages[] = "User's Common Sort Code: **" . $common_sort_code . "**";
            if ($common_routing_number) $accounts_created_messages[] = "User's Common Routing Number: **" . $common_routing_number . "**";
            if ($common_swift_bic) $accounts_created_messages[] = "User's Common SWIFT/BIC: **" . $common_swift_bic . "**";

            // If all operations were successful, commit the transaction
            $session->commitTransaction();
            $message = "User '{$first_name} {$last_name}' created successfully! <br>Membership Number: **{$membership_number}**<br>Initial Account Details:<br>" . implode("<br>", $accounts_created_messages);
            $message_type = 'success';
            $_POST = array(); // Clear form fields on success

        } catch (BulkWriteException $e) {
            // This exception is thrown for write concerns or duplicate key errors during bulk operations.
            // For single inserts, it typically means a unique index constraint violation.
            $error_code = $e->getCode();
            if ($error_code === 11000) { // MongoDB duplicate key error code
                $message = "Error creating user/account: A unique value (like email, membership number, or account number) already exists. Please check and try again.";
            } else {
                $message = "Database write error: " . $e->getMessage();
            }
            $message_type = 'error';
            $transaction_success = false; // Mark transaction as failed
            error_log("MongoDB BulkWriteException: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
        } catch (MongoDBException $e) {
            $message = "MongoDB Error: " . $e->getMessage();
            $message_type = 'error';
            $transaction_success = false;
            error_log("MongoDB General Error: " . $e->getMessage());
           } catch (Exception $e) {
            // Catch any other general PHP exceptions (e.g., from our helper functions)
            $message = "An unexpected error occurred: " . $e->getMessage();
            $message_type = 'error';
            $transaction_success = false;
            error_log("General Exception: " . $e->getMessage());

            // Abort transaction here on general error if it was started
            if (isset($session)) { // Ensure $session object was created
                try {
                    $session->abortTransaction();
                    error_log("MongoDB Transaction Aborted due to general error.");
                } catch (MongoDBException $abortE) {
                    error_log("Error during transaction abort (general error catch): " . $abortE->getMessage());
                }
            }
        } finally {
            // Always end the session, whether committed, aborted, or an error occurred before transaction start
            if (isset($session)) { // Ensure $session object was created
                $session->endSession();
            }
        }
            // If profile image was uploaded and transaction failed, delete it
            if (!$transaction_success && $uploaded_file_full_path && file_exists($uploaded_file_full_path)) {
                unlink($uploaded_file_full_path);
                error_log("Uploaded file deleted due to transaction rollback: " . $uploaded_file_full_path);
            }
            // No need to explicitly close MongoDB client, PHP cleans up
        }
    }
    end_of_post_processing:; // Label for goto statement
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeTown Bank - Create User</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="create_user.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <img src="../../images/logo.png" alt="HomeTown Bank Pa Logo" class="logo">
            <h2>Create New User</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </header>

        <main class="dashboard-content">
            <?php if (!empty($message)): ?>
                <p class="message <?php echo $message_type; ?>"><?php echo $message; ?></p>
            <?php endif; ?>

            <form action="create_user.php" method="POST" class="form-standard" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="first_name">First Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name <span class="required-asterisk">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span class="required-asterisk">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password <span class="required-asterisk">*</span></label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group full-width">
                    <label for="home_address">Home Address <span class="required-asterisk">*</span></label>
                    <textarea id="home_address" name="home_address" rows="3" required><?php echo htmlspecialchars($_POST['home_address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="phone_number">Phone Number <span class="required-asterisk">*</span></label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="nationality">Nationality <span class="required-asterisk">*</span></label>
                    <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($_POST['nationality'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span class="required-asterisk">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="gender">Gender <span class="required-asterisk">*</span></label>
                    <select id="gender" name="gender" required>
                        <option value="">-- Select Gender --</option>
                        <option value="Male" <?php echo (($_POST['gender'] ?? '') == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (($_POST['gender'] ?? '') == 'Female') ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo (($_POST['gender'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="occupation">Occupation <span class="required-asterisk">*</span></label>
                    <input type="text" id="occupation" name="occupation" value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="profile_image">Profile Image (Max 5MB, JPG, PNG, GIF)</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <small>Optional: You can upload a profile picture for the user.</small>
                </div>

                <div class="form-group">
                    <label for="routing_number_display">Routing Number</label>
                    <input type="text" id="routing_number_display" name="routing_number_display" value="Auto-generated upon creation" readonly class="readonly-field">
                    <small>This will be automatically generated and assigned to the user's account(s) if USD is selected.</small>
                </div>

                <div class="form-group">
                    <label for="initial_balance">Initial Balance Amount (Optional)</label>
                    <input type="number" id="initial_balance" name="initial_balance" step="0.01" value="<?php echo htmlspecialchars($_POST['initial_balance'] ?? '0.00'); ?>">
                    <small>Enter an amount to initially fund the user's chosen account type. Leave 0.00 if no initial funding.</small>
                </div>
                <div class="form-group">
                    <label for="currency">Account Currency <span class="required-asterisk">*</span></label>
                    <select id="currency" name="currency" required>
                        <option value="GBP" <?php echo (($_POST['currency'] ?? 'GBP') == 'GBP') ? 'selected' : ''; ?>>GBP</option>
                        <option value="EUR" <?php echo (($_POST['currency'] ?? '') == 'EUR') ? 'selected' : ''; ?>>EURO</option>
                        <option value="USD" <?php echo (($_POST['currency'] ?? '') == 'USD') ? 'selected' : ''; ?>>USD</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="fund_account_type">Fund Which Account? (Optional)</label>
                    <select id="fund_account_type" name="fund_account_type">
                        <option value="">-- Select Account Type (Optional) --</option>
                        <option value="Checking" <?php echo (($_POST['fund_account_type'] ?? '') == 'Checking') ? 'selected' : ''; ?>>Checking Account</option>
                        <option value="Savings" <?php echo (($_POST['fund_account_type'] ?? '') == 'Savings') ? 'selected' : ''; ?>>Savings Account</option>
                    </select>
                    <small>If an initial balance is provided, you must select an account type here.</small>
                </div>

                <div class="form-group">
                    <label for="admin_created_at">Account Creation Date & Time <span class="required-asterisk">*</span></label>
                    <input type="datetime-local" id="admin_created_at" name="admin_created_at" value="<?php echo htmlspecialchars($_POST['admin_created_at'] ?? date('Y-m-d\TH:i')); ?>" required>
                    <small>Set the exact date and time the account was (or should be) created.</small>
                </div>

                <button type="submit" class="button-primary">Create User</button>
            </form>

            <p><a href="users_management.php" class="back-link">&larr; Back to User Management</a></p>
        </main>
    </div>
    <style>
        .readonly-field {
            background-color: #e9e9e9;
            cursor: not-allowed;
        }
        .required-asterisk {
            color: red;
            font-weight: bold;
            margin-left: 5px;
        }
    </style>
</body>
</html>