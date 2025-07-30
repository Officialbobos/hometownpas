<?php
session_start();
require_once '../Config.php'; // Essential for MONGO_URI, MONGO_DB_NAME
require_once '../vendor/autoload.php'; // Make sure Composer's autoloader is included for MongoDB classes
require_once '../functions.php'; // Include functions.php if it contains utility functions like getCollection

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException; // Specific MongoDB exception

// Check if the user is logged in. If not, redirect to login page.
// Using BASE_URL for redirection for robustness.
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php'); // Redirect to login page
    exit;
}

// Convert session user_id to MongoDB ObjectId
try {
    $user_id_obj = new ObjectId($_SESSION['user_id']);
} catch (Exception $e) {
    // Log the error for debugging purposes
    error_log("Invalid user ID in session: " . ($_SESSION['user_id'] ?? 'N/A') . " - " . $e->getMessage());
    $_SESSION['message'] = "Your session is invalid. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: ' . rtrim(BASE_URL, '/') . '/login.php');
    exit;
}

$user_data = [];
$mongoClient = null; // Initialize to null for finally block to ensure cleanup
$errorMessage = ''; // To store user-friendly error messages

try {
    // Establish MongoDB connection using details from Config.php
    $mongoClient = new Client(MONGODB_CONNECTION_URI);
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $usersCollection = $database->users; // Assuming your user collection is named 'users'

    // Fetch user data from the 'users' collection by _id
    $user_data = (array) $usersCollection->findOne(['_id' => $user_id_obj]);

    if (!$user_data) {
        // This case indicates user_id from session didn't find a matching user
        $errorMessage = "User profile data could not be found. Please log in again.";
        error_log("User data not found for ID: " . $_SESSION['user_id']);
        // Consider redirecting to logout/login if user data is unexpectedly missing
        // header('Location: ' . rtrim(BASE_URL, '/') . '/logout.php');
        // exit;
    }

} catch (MongoDBDriverException $e) {
    // Catch specific MongoDB driver exceptions
    $errorMessage = "A database error occurred while fetching your profile. Please try again later.";
    error_log("MongoDB Error in profile.php: " . $e->getMessage());
    $user_data = []; // Ensure user_data is empty on error
} catch (Exception $e) {
    // Catch any other general exceptions
    $errorMessage = "An unexpected error occurred while loading your profile. Please try again later.";
    error_log("General Error in profile.php: " . $e->getMessage());
    $user_data = []; // Ensure user_data is empty on error
} finally {
    // It's good practice to close the MongoDB client connection if it was opened
    // In PHP, MongoDB connections are persistent by default, but explicitly nulling
    // the client can help with resource management if you're not using a global client.
    $mongoClient = null;
}

// Fallback for display if data not found or on error
$display_username = $user_data['email'] ?? 'N/A'; // Using 'email' as the display username
$display_first_name = $user_data['first_name'] ?? 'N/A';
$display_last_name = $user_data['last_name'] ?? 'N/A';
$display_email = $user_data['email'] ?? 'N/A';
$display_phone = $user_data['phone_number'] ?? 'N/A';
$display_address = $user_data['home_address'] ?? 'N/A';

// Variables for additional profile details
$display_nationality = $user_data['nationality'] ?? 'N/A';
$display_dob = $user_data['date_of_birth'] ?? 'N/A';
// If date_of_birth is stored as a MongoDB\BSON\UTCDateTime object, format it:
if ($display_dob instanceof MongoDB\BSON\UTCDateTime) {
    $display_dob = $display_dob->toDateTime()->format('Y-m-d');
}

$display_gender = $user_data['gender'] ?? 'N/A';
$display_occupation = $user_data['occupation'] ?? 'N/A';
$display_membership_number = $user_data['membership_number'] ?? 'N/A';

// --- PROFILE IMAGE PATH LOGIC ---
// Define the path to the default profile image relative to the project root.
// Assuming 'images' folder is also at the project root, similar to 'uploads'.
$default_profile_image_path = 'images/default_profile.png';

// Determine the image to display: user's custom image or the default.
// The path stored in the database is 'uploads/profile_images/filename.ext'.
// Since profile.php is in 'frontend/', and 'uploads/' is in root, we go up one level (..)
// before concatenating the stored path.
// Using BASE_URL for robustness as well.
$profile_image_src = !empty($user_data['profile_image']) ?
                     rtrim(BASE_URL, '/') . '/' . htmlspecialchars($user_data['profile_image']) :
                     rtrim(BASE_URL, '/') . '/' . $default_profile_image_path;
// --- END PROFILE IMAGE PATH LOGIC ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - User Profile</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Add styling for your profile page here */
        /* You can reuse styles from dashboard.php or create new ones */
        body.profile-page {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Reusing header/sidebar/footer styles from dashboard.php */
        .dashboard-header {
            background-color: #0056b3; /* Darker blue, typical for banks */
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .dashboard-header .logo-barclays {
            height: 40px;
            filter: brightness(0) invert(1);
        }

        .user-info {
            display: flex;
            align-items: center;
            font-size: 1.1em;
        }

        .user-info .profile-icon {
            margin-right: 10px;
            font-size: 1.5em;
            color: #ffcc00;
        }

        .user-info span {
            margin-right: 20px;
        }

        .user-info a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .user-info a:hover {
            background-color: rgba(255,255,255,0.2);
        }

        .dashboard-container { /* This wraps sidebar and main content */
            display: flex;
            flex-grow: 1;
        }

        .sidebar {
            width: 250px;
            background-color: #ffffff;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
            padding-top: 20px;
            flex-shrink: 0;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar ul li a {
            display: block;
            padding: 15px 30px;
            color: #333;
            text-decoration: none;
            font-size: 1.05em;
            border-left: 5px solid transparent;
            transition: background-color 0.3s ease, border-left-color 0.3s ease, color 0.3s ease;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background-color: #e6f0fa;
            border-left-color: #007bff;
            color: #0056b3;
        }

        .sidebar ul li a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .dashboard-footer {
            background-color: #333;
            color: #fff;
            text-align: center;
            padding: 20px;
            margin-top: auto;
            font-size: 0.9em;
        }

        /* Profile Page Specific Styles */
        .profile-container {
            flex-grow: 1;
            padding: 30px;
            background-color: #f4f7f6;
        }

        .profile-card {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            max-width: 600px; /* Max width for the card */
            margin: 30px auto; /* Center the card horizontally and add top/bottom margin */
            text-align: center; /* Center image and headings initially */
            margin-bottom: 30px; /* Add margin below the main profile card */
        }

        .profile-card h2 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 25px;
            font-size: 1.8em;
            text-align: center;
        }

        .profile-image-container {
            margin-bottom: 20px;
        }

        .profile-image-container img {
            max-width: 100%; /* Ensures image scales down on smaller screens */
            width: 150px; /* Desired fixed width for desktop */
            height: 150px; /* Desired fixed height to maintain circular shape for desktop */
            border-radius: 50%; /* Makes image circular */
            object-fit: cover; /* Ensures image covers the area without distortion */
            border: 3px solid #007bff; /* Blue border */
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            display: block; /* Ensures margin auto works for centering */
            margin: 0 auto; /* Centers the image horizontally */
        }

        .profile-details {
            text-align: left; /* Align text within details section */
            margin-top: 20px;
        }

        .profile-details p {
            margin-bottom: 15px;
            font-size: 1.1em;
            color: #333;
            line-height: 1.4; /* Improve readability */
        }

        .profile-details p strong {
            display: inline-block;
            width: 150px; /* Aligns labels consistently */
            color: #555;
            font-weight: bold; /* Explicitly bold the labels */
        }

        /* Responsive Adjustments for Profile Page */
        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column; /* Stack sidebar and main content */
            }
            .sidebar {
                width: 100%;
                padding-top: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .sidebar ul {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-around;
            }
            .sidebar ul li {
                flex: 1 1 auto;
                text-align: center;
            }
            .sidebar ul li a {
                border-left: none;
                border-bottom: 3px solid transparent;
                padding: 10px 15px;
            }
            .sidebar ul li a:hover,
            .sidebar ul li a.active {
                border-left-color: transparent;
                border-bottom-color: #007bff;
            }
            .profile-container {
                padding: 20px;
            }
            .profile-card { /* Apply to both cards */
                margin: 20px auto;
                padding: 20px;
            }
            .profile-details p {
                font-size: 1em;
            }
            .profile-details p strong {
                width: 100px; /* Adjust label width for smaller screens */
            }
        }

        @media (max-width: 480px) {
            .dashboard-header .logo-barclays {
                height: 30px;
            }
            .user-info .profile-icon {
                margin-right: 5px;
            }
            .sidebar ul li a i {
                margin-right: 0;
            }
            .sidebar ul li a {
                padding: 10px 5px;
            }
            .profile-card h2 {
                font-size: 1.5em;
            }

            /* --- Profile Image Size Reduction for Mobile --- */
            .profile-image-container img {
                width: 100px; /* Smaller width for mobile */
                height: 100px; /* Smaller height for mobile */
            }
            /* --- End Profile Image Size Reduction --- */

            .profile-details p strong {
                display: block; /* Stack label and value on very small screens */
                width: auto;
                margin-bottom: 5px;
            }
            .profile-details p {
                text-align: center; /* Center stacked details */
            }
        }
    </style>
</head>
<body class="profile-page">

    <header class="dashboard-header">
        <img src="https://i.imgur.com/YEFKZlG.png" alt="Heritage Bank Logo" class="logo-barclays">
        <div class="user-info">
            <i class="fas fa-user-circle profile-icon"></i>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'User'); ?></span>
            <a href="<?php echo rtrim(BASE_URL, '/') . '/logout.php'; ?>">Logout</a>
        </div>
    </header>

    <div class="dashboard-container">
        <nav class="sidebar">
            <ul>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/dashboard.php'; ?>"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/accounts.php'; ?>"><i class="fas fa-wallet"></i> <span>Accounts</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/transfer.php'; ?>"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/transactions.php'; ?>"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/statements.php'; ?>"><i class="fas fa-file-invoice"></i> <span>Statements</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/profile.php'; ?>" class="active"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/frontend/bank_cards.php'; ?>"><i class="fas fa-credit-card"></i> <span>Bank Cards</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/') . '/logout.php'; ?>"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </nav>

        <main class="profile-container">
            <section class="profile-card">
                <h2>User Profile</h2>
                <?php if (!empty($errorMessage)): ?>
                    <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">
                        <?php echo htmlspecialchars($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($user_data)): ?>
                    <div class="profile-image-container">
                        <img src="<?php echo $profile_image_src; ?>" alt="Profile Image">
                    </div>
                    <div class="profile-details">
                        <p><strong>Username:</strong> <?php echo $display_username; ?></p>
                        <p><strong>First Name:</strong> <?php echo $display_first_name; ?></p>
                        <p><strong>Last Name:</strong> <?php echo $display_last_name; ?></p>
                        <p><strong>Email:</strong> <?php echo $display_email; ?></p>
                        <p><strong>Phone:</strong> <?php echo $display_phone; ?></p>
                        <p><strong>Address:</strong> <?php echo $display_address; ?></p>
                        <p><strong>Nationality:</strong> <?php echo $display_nationality; ?></p>
                        <p><strong>Date of Birth:</strong> <?php echo $display_dob; ?></p>
                        <p><strong>Gender:</strong> <?php echo $display_gender; ?></p>
                        <p><strong>Occupation:</strong> <?php echo $display_occupation; ?></p>
                        <p><strong>Membership No.:</strong> <?php echo $display_membership_number; ?></p>
                    </div>
                <?php else: ?>
                    <p>User data not found or could not be loaded.</p>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <footer class="dashboard-footer">
        <p>&copy; <?php echo date('Y'); ?> HomeTown Bank. All rights reserved.</p>
    </footer>

</body>
</html>
