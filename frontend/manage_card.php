<?php
session_start();
require_once '../Config.php'; // Ensure this Config.php contains MongoDB connection details
require_once '../vendor/autoload.php'; // Make sure Composer's autoloader is included for MongoDB classes

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// Check if the user is NOT logged in or user_id is not set
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); // Redirect to login page
    exit;
}

// Convert session user_id to MongoDB ObjectId
try {
    $user_id_obj = new ObjectId($_SESSION['user_id']);
} catch (Exception $e) {
    // Log the error for debugging purposes (e.g., to a file or a service)
    error_log("Invalid user ID in session: " . $_SESSION['user_id'] . " - " . $e->getMessage());
    $_SESSION['message'] = "Your session is invalid. Please log in again.";
    $_SESSION['message_type'] = "error";
    header('Location: ../login.php');
    exit;
}

$card = null;
$message = $_GET['message'] ?? ''; // Get message from GET if redirected
$message_type = $_GET['message_type'] ?? ''; // Get message type from GET

$mongoClient = null; // Initialize to null for finally block
try {
    // Establish MongoDB connection using details from Config.php
    $mongoClient = new Client(MONGODB_CONNECTION_URI);
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $cardsCollection = $database->bank_cards;

    // Handle form submission for card management
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $card_id_post_str = filter_input(INPUT_POST, 'card_id', FILTER_SANITIZE_STRING); // Get as string
        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

        if ($card_id_post_str && $action) {
            try {
                $card_id_obj = new ObjectId($card_id_post_str); // Convert to ObjectId
            } catch (Exception $e) {
                // If the card ID from the form is not a valid ObjectId, throw an exception
                throw new Exception("Invalid card ID format provided.");
            }

            // Validate that the card belongs to the current user
            // Use findOne to ensure the card exists and is linked to the user
            $existingCard = $cardsCollection->findOne([
                '_id' => $card_id_obj,
                'user_id' => $user_id_obj
            ]);

            if ($existingCard) {
                // Card belongs to the user, proceed with the requested action
                if ($action === 'toggle_status') {
                    // Convert checkbox value ('1' or not set) to a boolean for MongoDB
                    $new_status = (isset($_POST['is_active']) && $_POST['is_active'] === '1');
                    
                    $updateResult = $cardsCollection->updateOne(
                        ['_id' => $card_id_obj],
                        ['$set' => ['is_active' => $new_status]] // Use $set to update the 'is_active' field
                    );

                    if ($updateResult->getModifiedCount() > 0) {
                        $message = "Card status updated successfully!";
                        $message_type = 'success';
                    } else {
                        // This case means no document was modified. It could be because the status was already the same,
                        // or an unexpected issue.
                        $message = "Card status is already set to the desired state or no change was needed.";
                        $message_type = 'info'; // Indicate no change
                    }
                } elseif ($action === 'report_lost_stolen') {
                    $updateResult = $cardsCollection->updateOne(
                        ['_id' => $card_id_obj],
                        ['$set' => ['is_active' => false]] // Set 'is_active' to false for lost/stolen
                    );

                    if ($updateResult->getModifiedCount() > 0) {
                        $message = "Card reported as lost/stolen and blocked. Please contact support for further assistance.";
                        $message_type = 'success';
                    } else {
                        $message = "Card was already reported as lost/stolen or could not be updated.";
                        $message_type = 'info';
                    }
                } else {
                    $message = "Invalid action specified.";
                    $message_type = 'error';
                }
            } else {
                $message = "Unauthorized access or card not found.";
                $message_type = 'error';
            }
        } else {
            $message = "Invalid action or missing card ID.";
            $message_type = 'error';
        }

        // Redirect back to self after POST to prevent form re-submission on refresh
        // Pass messages via GET parameters
        header('Location: manage_card.php?card_id=' . urlencode($card_id_post_str) . '&message=' . urlencode($message) . '&message_type=' . urlencode($message_type));
        exit;
    }

    // Fetch card details for display (after any potential POST updates or on initial GET)
    $card_id_get_str = filter_input(INPUT_GET, 'card_id', FILTER_SANITIZE_STRING);

    if ($card_id_get_str) {
        try {
            $card_id_obj_get = new ObjectId($card_id_get_str); // Convert to ObjectId
        } catch (Exception $e) {
            throw new Exception("Invalid card ID format in URL.");
        }

        // Find the card by its _id and ensure it belongs to the current user
        $card = $cardsCollection->findOne(['_id' => $card_id_obj_get, 'user_id' => $user_id_obj]);

        if (!$card) {
            $message = "Card not found or you don't have permission to view it.";
            $message_type = 'error';
        } else {
            // Convert the MongoDB BSON document object to a PHP array for easier access
            $card = (array) $card;
            // The '_id' field is a BSON ObjectId; convert it to a string for use in HTML forms/display
            $card['id'] = (string) $card['_id']; 
            // The 'is_active' field from MongoDB will be a boolean, which works directly in PHP for truthiness.
        }
    } else {
        // If no card ID is provided in the GET request, and no message is already set (e.g., from a POST redirect)
        if (empty($message)) { 
            $message = "No card ID provided. Please select a card from your <a href='bank_cards.php'>Bank Cards</a> page.";
            $message_type = 'error';
        }
    }

} catch (Exception $e) {
    // Catch any exceptions that occur during MongoDB operations
    $message = "An error occurred: " . $e->getMessage();
    $message_type = 'error';
    // Log the full exception for debugging
    error_log("Card Management MongoDB Error: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
} finally {
    // In MongoDB PHP driver, there's no explicit close() method like MySQLi.
    // The client handles connections automatically, often using persistent connections or connection pooling.
    // Unsetting the client variable here just cleans up the reference.
    $mongoClient = null;
}

// Prepare full name for display in the navbar
$full_name = 'User';
if (isset($_SESSION['first_name']) && isset($_SESSION['last_name'])) {
    $full_name = htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
} elseif (isset($_SESSION['username'])) {
    $full_name = htmlspecialchars($_SESSION['username']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - Manage Card</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        /* CSS Variables for easier theming */
        :root {
            --primary-blue: #007bff;
            --dark-blue: #0056b3;
            --light-grey: #f4f7f6;
            --text-color-dark: #333;
            --text-color-light: #fff;
            --border-color: #eee;
            --box-shadow-light: 0 1px 3px rgba(0,0,0,0.08);
            --box-shadow-medium: 0 4px 8px rgba(0,0,0,0.1);
            --success-color: #28a745;
            --error-color: #dc3545;
            --info-color: #17a2b8; /* Added for 'info' message type */
            --font-family: 'Roboto', sans-serif;
            --sidebar-width: 260px;
            --navbar-height: 60px;
        }

        /* Base Styles */
        body {
            font-family: var(--font-family);
            margin: 0;
            padding: 0;
            background-color: var(--light-grey);
            color: var(--text-color-dark);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
            flex-direction: column; /* Default to column for mobile-first */
        }

        /* Top Navigation Bar */
        .top-navbar {
            background-color: var(--text-color-light);
            padding: 10px 20px;
            box-shadow: var(--box-shadow-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--navbar-height);
            box-sizing: border-box;
            position: fixed; /* Fixed on top */
            width: 100%; /* Full width */
            top: 0;
            left: 0;
            z-index: 1000;
        }

        .top-navbar .logo img {
            height: 40px;
        }

        .top-navbar h2 {
            margin: 0;
            font-size: 1.4em;
            color: var(--dark-blue);
        }

        .top-navbar .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9em;
        }
        .top-navbar .user-info .profile-icon {
            font-size: 1.2em;
            color: var(--primary-blue);
        }
        .top-navbar .user-info a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
        }
        .top-navbar .user-info a:hover {
            text-decoration: underline;
        }

        /* Hamburger Menu Toggle for Mobile */
        .menu-toggle {
            display: none; /* Hidden on desktop */
            font-size: 1.8em;
            color: var(--primary-blue);
            cursor: pointer;
            z-index: 1001; /* Ensure it's above other content */
        }

        /* Sidebar Navigation */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--primary-blue);
            color: var(--text-color-light);
            padding-top: var(--navbar-height); /* Space for fixed navbar */
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            z-index: 999; /* Below navbar */
            transform: translateX(0); /* Visible by default on desktop */
            transition: transform 0.3s ease-in-out;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar ul li a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: var(--text-color-light);
            text-decoration: none;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
        }
        .sidebar ul li a i {
            margin-right: 10px;
            font-size: 1.2em;
        }
        .sidebar ul li a:hover,
        .sidebar ul li.active a {
            background-color: var(--dark-blue);
        }

        /* Main Content Area */
        .main-content-wrapper {
            flex-grow: 1;
            padding: 20px;
            margin-top: var(--navbar-height); /* Space for fixed navbar */
            margin-left: var(--sidebar-width); /* Offset for sidebar on desktop */
            box-sizing: border-box;
            background-color: var(--light-grey);
            min-height: calc(100vh - var(--navbar-height));
        }

        .main-content-wrapper h3 {
            color: var(--text-color-dark);
            margin-bottom: 25px;
            font-size: 1.8em;
            text-align: center;
        }

        /* Messages */
        .message {
            padding: 12px 20px;
            margin: 15px auto 20px auto;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            max-width: 600px;
            box-shadow: var(--box-shadow-light);
        }
        .message.success {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: var(--error-color);
            border: 1px solid #f5c6cb;
        }
        .message.info {
            background-color: #d1ecf1;
            color: var(--info-color); /* Use info color variable */
            border: 1px solid #bee5eb;
        }

        /* The Bank Card Display */
        .bank-card-display {
            width: 320px; /* Standard card width */
            height: 200px; /* Standard card height */
            aspect-ratio: 1.6 / 1; /* For responsive scaling if desired */
            background: linear-gradient(135deg, #007bff, #0056b3); /* Heritage Bank blue gradient */
            color: var(--text-color-light);
            border-radius: 15px;
            box-shadow: var(--box-shadow-medium);
            margin: 30px auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }
        /* Example for adding a subtle texture or pattern */
        .bank-card-display::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="rgba(255,255,255,0.05)"/><rect x="0" y="0" width="5" height="5" fill="rgba(255,255,255,0.05)"/></svg>');
            background-size: 10px 10px;
            opacity: 0.8;
            pointer-events: none;
        }

        .bank-card-display .card-header-logo {
            text-align: left;
            font-size: 1.1em;
            font-weight: 700;
            opacity: 0.9;
        }

        .bank-card-display .card-number {
            font-size: 1.8em;
            text-align: center;
            letter-spacing: 2px;
            margin-bottom: 10px;
            font-weight: 400;
        }

        .bank-card-display .card-details-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end; /* Align to bottom of row */
            font-size: 0.9em;
            margin-top: auto; /* Push to bottom */
        }

        /* Added a new row specifically for CVV to give it its own spacing/alignment */
        .bank-card-display .card-cvv-row {
            display: flex;
            justify-content: flex-end; /* Align CVV to the right */
            font-size: 0.9em;
            margin-top: 5px; /* Small margin above it if needed */
        }

        .bank-card-display .card-details-group {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .bank-card-display .card-details-group.right {
            text-align: right;
        }

        .bank-card-display .card-details-label {
            font-weight: 300;
            opacity: 0.8;
            margin-bottom: 3px;
            font-size: 0.8em;
        }

        .bank-card-display .card-details-value {
            font-weight: 500;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            color: var(--text-color-light);
            font-size: 0.8em;
            display: inline-block;
        }
        .status-approved { background-color: var(--success-color); }
        .status-declined { background-color: var(--error-color); }

        /* Card Actions Section */
        .card-actions-section {
            background-color: var(--text-color-light);
            padding: 25px;
            border-radius: 8px;
            box-shadow: var(--box-shadow-light);
            max-width: 500px;
            margin: 20px auto;
            text-align: center;
        }

        .card-actions-section h4 {
            color: var(--dark-blue);
            margin-bottom: 20px;
            font-size: 1.5em;
        }

        /* Toggle Switch Styling */
        .form-group.switch-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        .form-group.switch-container label {
            margin-bottom: 0;
            font-weight: 500;
            color: var(--text-color-dark);
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
            vertical-align: middle;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc; transition: .4s; border-radius: 34px;
        }
        .slider:before {
            position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px;
            background-color: white; transition: .4s; border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--primary-blue); }
        input:focus + .slider { box-shadow: 0 0 1px var(--primary-blue); }
        input:checked + .slider:before {
            -webkit-transform: translateX(26px);
            -ms-transform: translateX(26px);
            transform: translateX(26px);
        }

        /* Buttons */
        .button-primary {
            display: block;
            width: calc(100% - 40px); /* Adjust for padding */
            max-width: 300px;
            margin: 15px auto;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-color-light);
            background-color: var(--primary-blue);
            border: none;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.3s ease, transform 0.2s ease;
            text-align: center;
            font-weight: 500;
            box-shadow: var(--box-shadow-light);
        }
        .button-primary:hover {
            background-color: var(--dark-blue);
            transform: translateY(-2px);
        }
        .button-primary i {
            margin-right: 8px;
        }
        .button-danger {
            background-color: var(--error-color);
        }
        .button-danger:hover {
            background-color: #c82333;
        }

        /* No Data Found */
        .no-data-found {
            text-align: center;
            padding: 40px;
            background-color: var(--text-color-light);
            border-radius: 8px;
            margin: 30px auto;
            color: #666;
            font-style: italic;
            max-width: 600px;
            box-shadow: var(--box-shadow-light);
        }
        .no-data-found .back-link {
            display: inline-block;
            margin-top: 15px;
            font-weight: normal;
        }

        .back-link-container {
            text-align: center;
            margin-top: 30px;
        }

        .back-link {
            display: inline-block; /* Changed to inline-block */
            padding: 10px 20px; /* Added padding */
            border-radius: 5px; /* Rounded corners */
            background-color: var(--primary-blue);
            color: var(--text-color-light) !important; /* Ensure text is white */
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        .back-link:hover {
            background-color: var(--dark-blue);
            text-decoration: none; /* Remove underline on hover for button-like link */
        }

        /* --- Mobile Responsiveness --- */
        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }

            .top-navbar {
                position: sticky; /* Make it sticky, not fixed, on mobile */
                top: 0;
                flex-wrap: wrap; /* Allow items to wrap */
                justify-content: center; /* Center items initially */
                height: auto; /* Auto height */
                padding: 10px;
            }
            .top-navbar .logo {
                order: 1; /* Control order of elements */
                margin-right: auto; /* Push toggle to right */
            }
            .top-navbar h2 {
                order: 3;
                width: 100%; /* Full width for title */
                text-align: center;
                font-size: 1.2em;
                margin-top: 10px;
                margin-bottom: 5px;
            }
            .top-navbar .user-info {
                order: 4;
                width: 100%;
                justify-content: center;
                margin-top: 5px;
                font-size: 0.85em;
            }
            .menu-toggle {
                display: block; /* Show hamburger on mobile */
                order: 2; /* Place it after logo */
                margin-left: auto; /* Push to the right */
            }

            .sidebar {
                transform: translateX(-100%); /* Hidden by default on mobile */
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 250px; /* Slightly smaller sidebar on mobile */
                padding-top: 0; /* No extra padding for navbar as navbar is sticky now */
            }
            .sidebar.active {
                transform: translateX(0); /* Slide in when active */
                box-shadow: 4px 0 10px rgba(0,0,0,0.3);
            }
            /* Overlay for when sidebar is open */
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 998; /* Below sidebar, above content */
            }
            .sidebar-overlay.active {
                display: block;
            }

            .main-content-wrapper {
                margin-left: 0; /* No offset for sidebar */
                padding-top: 15px; /* Adjust top padding */
                min-height: calc(100vh - var(--navbar-height)); /* Still ensure height, adjust for sticky header */
            }

            .bank-card-display {
                width: 280px; /* Smaller card on mobile */
                height: 175px; /* Maintain aspect ratio */
                padding: 15px;
            }

            .bank-card-display .card-number {
                font-size: 1.6em;
            }
            .bank-card-display .card-details-row {
                font-size: 0.8em;
            }
            .bank-card-display .card-cvv-row { /* Adjust CVV font size for mobile */
                font-size: 0.8em;
            }


            .card-actions-section {
                padding: 20px;
                margin: 20px 10px; /* Adjust side margins */
            }

            .button-primary {
                width: calc(100% - 30px); /* Adjust padding */
                padding: 10px 15px;
            }
        }

        @media (max-width: 480px) {
            .top-navbar .logo img {
                height: 30px;
            }
            .top-navbar h2 {
                font-size: 1.1em;
            }
            .bank-card-display {
                width: 260px;
                height: 160px;
                padding: 10px;
            }
            .bank-card-display .card-number {
                font-size: 1.5em;
            }
            .bank-card-display .card-details-row {
                font-size: 0.75em;
            }
            .bank-card-display .card-cvv-row { /* Even smaller CVV font size for tiny screens */
                font-size: 0.7em;
            }
            .message {
                padding: 10px;
                font-size: 0.9em;
            }
        }
    </style>
</head>
<body class="dashboard-page">
    <div class="dashboard-container">

        <div class="sidebar-overlay" onclick="toggleMenu()"></div>

        <nav class="top-navbar">
            <div class="logo">
                <img src="https://i.imgur.com/YEFKZlG.png" alt="Heritage Bank Logo">
            </div>
            <h2>Manage Card</h2>
            <div class="user-info">
                <i class="fas fa-user profile-icon"></i>
                <span><?php echo htmlspecialchars($full_name); ?></span>
                <a href="../logout.php">Logout</a>
            </div>
            <div id="menu-toggle" class="menu-toggle">
                <i class="fas fa-bars"></i>
            </div>
        </nav>

        <aside class="sidebar" id="sidebar">
            <ul>
                <li><a href="dashboard.php"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i> <span>Profile</span></a></li>
                <li><a href="statements.php"><i class="fas fa-file-alt"></i> <span>Statements</span></a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> <span>Transfers</span></a></li>
                <li><a href="transactions.php"><i class="fas fa-history"></i> <span>Transaction History</span></a></li>
                <li class="active"><a href="bank_cards.php"><i class="fas fa-credit-card"></i> <span>Bank Cards</span></a></li>
                <li><a href="#"><i class="fas fa-cog"></i> <span>Settings</span></a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </aside>

        <main class="main-content-wrapper">
            <h3 style="margin-top: 0;">Card Management</h3>

            <?php if (!empty($message)): ?>
                <p class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if ($card): ?>
                <div class="bank-card-display">
                    <div class="card-header-logo">Heritage Bank</div>
                    <div class="card-number"><?php echo htmlspecialchars(wordwrap($card['card_number'], 4, ' ', true)); ?></div>

                    <div class="card-details-row">
                        <div class="card-details-group">
                            <div class="card-details-label">Card Holder</div>
                            <div class="card-details-value"><?php echo htmlspecialchars($card['card_holder_name']); ?></div>
                        </div>
                        <div class="card-details-group right">
                            <div class="card-details-label">Expires</div>
                            <div class="card-details-value"><?php echo htmlspecialchars(str_pad($card['expiry_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($card['expiry_year'], 2, 2)); ?></div>
                        </div>
                    </div>
                    <div class="card-cvv-row">
                        <div class="card-details-group right">
                            <div class="card-details-label">CVV</div>
                            <div class="card-details-value"><?php echo htmlspecialchars($card['cvv']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="card-actions-section">
                    <h4>Card Actions</h4>

                    <form action="manage_card.php" method="POST">
                        <input type="hidden" name="card_id" value="<?php echo htmlspecialchars($card['id']); ?>">
                        <input type="hidden" name="action" value="toggle_status">
                        <div class="form-group switch-container">
                            <label for="status_toggle">Card Status:</label>
                            <label class="switch">
                                <input type="checkbox" id="status_toggle" name="is_active" value="1" <?php echo $card['is_active'] ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <span class="slider round"></span>
                            </label>
                            <span style="color: var(--text-color-dark); font-weight: bold;"><?php echo $card['is_active'] ? 'Active' : 'Blocked'; ?></span>
                        </div>
                        <small style="display: block; text-align: center; margin-top: 5px; color: #777;">Toggle to activate or block your card.</small>
                    </form>

                    <form action="manage_card.php" method="POST" onsubmit="return confirm('Are you sure you want to report this card as lost/stolen? This action will permanently block the card and cannot be easily reversed online. Please contact support after reporting.');" style="margin-top: 25px;">
                        <input type="hidden" name="card_id" value="<?php echo htmlspecialchars($card['id']); ?>">
                        <input type="hidden" name="action" value="report_lost_stolen">
                        <button type="submit" class="button-primary button-danger">
                            <i class="fas fa-exclamation-triangle"></i> Report Lost / Stolen
                        </button>
                        <small style="display: block; margin-top: 10px; color: var(--error-color); font-weight: bold;">(This action will permanently block the card)</small>
                    </form>
                </div>
            <?php else: ?>
                <div class="no-data-found">
                    <p><?php echo htmlspecialchars($message); ?></p>
                    <?php if ($message_type === 'error' && strpos($message, 'No card ID provided') !== false): ?>
                        <p><a href="bank_cards.php" class="back-link">Go to My Cards</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="back-link-container"><a href="bank_cards.php" class="back-link">&larr; Back to My Cards</a></p>
        </main>
    </div>

    <script>
        // JavaScript for mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');

            function toggleMenu() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            }

            menuToggle.addEventListener('click', toggleMenu);
            sidebarOverlay.addEventListener('click', toggleMenu);

            // Close sidebar when a link is clicked (optional, for better UX on mobile)
            document.querySelectorAll('#sidebar ul li a').forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 768) { // Only close on smaller screens
                        toggleMenu();
                    }
                });
            });
        });
    </script>
</body>
</html>