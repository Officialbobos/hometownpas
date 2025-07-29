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
// Prefer session messages, then fall back to GET (GET messages should be transient)
$message = $_SESSION['message'] ?? ($_GET['message'] ?? '');
$message_type = $_SESSION['message_type'] ?? ($_GET['message_type'] ?? '');
// Clear session messages after retrieving them
unset($_SESSION['message']);
unset($_SESSION['message_type']);


$mongoClient = null; // Initialize to null for finally block
try {
    // Establish MongoDB connection using details from Config.php
    $mongoClient = new Client(MONGODB_CONNECTION_URI);
    $database = $mongoClient->selectDatabase(MONGODB_DB_NAME);
    $cardsCollection = $database->bank_cards;

    // Handle form submission for card management
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // --- FIX START ---
        // Replace FILTER_SANITIZE_STRING with FILTER_UNSAFE_RAW and flags for better, non-deprecated sanitization
        $card_id_post_str = filter_input(INPUT_POST, 'card_id', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
        $action = filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
        // --- FIX END ---

        if ($card_id_post_str && $action) {
            try {
                $card_id_obj = new ObjectId($card_id_post_str); // Convert to ObjectId
            } catch (Exception $e) {
                // If the card ID from the form is not a valid ObjectId, throw an exception
                throw new Exception("Invalid card ID format provided.");
            }

            // Validate that the card belongs to the current user
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
                        $_SESSION['message'] = "Card status updated successfully!";
                        $_SESSION['message_type'] = 'success';
                    } else {
                        // This case means no document was modified. It could be because the status was already the same,
                        // or an unexpected issue.
                        $_SESSION['message'] = "Card status is already set to the desired state or no change was needed.";
                        $_SESSION['message_type'] = 'info'; // Indicate no change
                    }
                } elseif ($action === 'report_lost_stolen') {
                    $updateResult = $cardsCollection->updateOne(
                        ['_id' => $card_id_obj],
                        ['$set' => ['is_active' => false, 'status' => 'reported_lost_stolen']] // Also update status field
                    );

                    if ($updateResult->getModifiedCount() > 0) {
                        $_SESSION['message'] = "Card reported as lost/stolen and blocked. Please contact support for further assistance.";
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = "Card was already reported as lost/stolen or could not be updated.";
                        $_SESSION['message_type'] = 'info';
                    }
                } else {
                    $_SESSION['message'] = "Invalid action specified.";
                    $_SESSION['message_type'] = 'error';
                }
            } else {
                $_SESSION['message'] = "Unauthorized access or card not found.";
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = "Invalid action or missing card ID.";
            $_SESSION['message_type'] = 'error';
        }

        // Redirect back to self after POST to prevent form re-submission on refresh
        // Messages are now handled via session, so no need to pass in GET
        header('Location: manage_card.php?card_id=' . urlencode($card_id_post_str));
        exit;
    }

    // Fetch card details for display (after any potential POST updates or on initial GET)
    // --- FIX START ---
    // Same sanitization for GET parameter
    $card_id_get_str = filter_input(INPUT_GET, 'card_id', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
    // --- FIX END ---

    if ($card_id_get_str) {
        try {
            $card_id_obj_get = new ObjectId($card_id_get_str); // Convert to ObjectId
        } catch (Exception $e) {
            throw new Exception("Invalid card ID format in URL.");
        }

        // Find the card by its _id and ensure it belongs to the current user
        $card = $cardsCollection->findOne(['_id' => $card_id_obj_get, 'user_id' => $user_id_obj]);

        if (!$card) {
            $_SESSION['message'] = "Card not found or you don't have permission to view it.";
            $_SESSION['message_type'] = 'error';
            header('Location: bank_cards.php'); // Redirect if card not found or unauthorized
            exit;
        } else {
            // Convert the MongoDB BSON document object to a PHP array for easier access
            $card = (array) $card;
            // The '_id' field is a BSON ObjectId; convert it to a string for use in HTML forms/display
            $card['id'] = (string) $card['_id'];
            // 'is_active' will be a boolean, which works directly.
            // Ensure card_number_masked and expiry_date exist for display
            $card['card_number_display'] = $card['card_number_masked'] ?? '************0000';
            $card['expiry_display'] = $card['expiry_date'] ?? 'MM/YY'; // Assuming expiry_date is MM/YY string
        }
    } else {
        // If no card ID is provided in the GET request, redirect to bank_cards.php
        $_SESSION['message'] = "No card ID provided. Please select a card to manage.";
        $_SESSION['message_type'] = 'error';
        header('Location: bank_cards.php');
        exit;
    }

} catch (Exception $e) {
    // Catch any exceptions that occur during MongoDB operations
    $_SESSION['message'] = "An error occurred: " . $e->getMessage();
    $_SESSION['message_type'] = 'error';
    // Log the full exception for debugging
    error_log("Card Management MongoDB Error: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
    // Redirect to prevent re-displaying the error on refresh
    header('Location: bank_cards.php'); // Or some error page
    exit;
} finally {
    // MongoDB PHP driver handles connections automatically, no explicit close needed
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
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/bank_cards.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
    <style>
        /* New CSS for the Realistic Card Design */
        .bank-card-display {
            position: relative;
            width: 90vw; /* Occupy 90% of viewport width on mobile */
            max-width: 400px; /* Cap width for desktop to be like a real card */
            /* Maintain standard credit card aspect ratio (approx. 1.6:1) */
            padding-bottom: 56.25%; /* (height / width) * 100 = (400px * 0.625 / 400px) * 100 = 62.5%. Or use 100 / 1.6 = 62.5% */
            /* Actual aspect ratio is closer to 1.585:1, so padding-bottom: 63.03% */
            aspect-ratio: 1.585 / 1; /* Modern way for aspect ratio */
            background: linear-gradient(135deg, #004494, #0056b3); /* Heritage Bank Blue Gradient */
            border-radius: 15px;
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
            color: #fff;
            padding: 20px 25px; /* Adjust padding to fit content */
            margin: 30px auto; /* Center the card */
            display: flex;
            flex-direction: column;
            justify-content: space-between; /* Distribute content top to bottom */
            font-family: 'Roboto', sans-serif;
            overflow: hidden; /* Ensure nothing spills out */
            background-size: cover;
            background-position: center;
            /* Optional: Add a subtle texture or pattern */
            /* background-image: url('path/to/subtle-card-texture.png'); */
        }

        .bank-card-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.1); /* Subtle overlay for depth */
            border-radius: 15px;
        }

        .bank-card-display .card-header-logo {
            font-size: 1.2rem; /* Adjusted for card size */
            font-weight: 700;
            text-align: right; /* Usually logos are on the right or top left */
            margin-bottom: 10px;
            z-index: 1; /* Ensure text is above overlay */
        }

        .bank-card-display .card-network-logo {
            position: absolute;
            top: 20px;
            left: 25px;
            width: 60px; /* Small logo for network */
            height: auto;
            z-index: 1;
        }

        .bank-card-display .card-chip {
            width: 45px;
            height: 35px;
            background-color: #d4af37; /* Gold color */
            border-radius: 5px;
            position: absolute;
            top: 70px; /* Position below logo */
            left: 25px;
            box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.3);
            z-index: 1;
        }

        .bank-card-display .card-number {
            font-family: 'Roboto Mono', monospace; /* Monospace for card number */
            font-size: 1.8rem; /* Larger font for card number */
            letter-spacing: 0.15rem; /* Space between numbers */
            text-align: center;
            margin-top: auto; /* Push to bottom for alignment */
            margin-bottom: 10px;
            z-index: 1;
        }

        .bank-card-display .card-holder-name,
        .bank-card-display .card-expiry {
            font-size: 0.9rem; /* Smaller for details */
            text-transform: uppercase;
            font-weight: 400;
            z-index: 1;
        }
        
        .bank-card-display .card-details-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end; /* Align to the bottom of the card */
            width: 100%;
            z-index: 1;
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
            font-size: 0.7em;
            opacity: 0.8;
            margin-bottom: 2px;
        }

        /* Responsive adjustments for the card */
        @media (min-width: 768px) {
            .bank-card-display {
                width: 400px; /* Fixed width on desktop for physical card size */
                /* padding-bottom will automatically adjust height based on aspect-ratio */
            }
            /* You might slightly adjust font sizes for desktop if needed */
            .bank-card-display .card-number {
                font-size: 2rem;
            }
            .bank-card-display .card-holder-name,
            .bank-card-display .card-expiry {
                font-size: 1rem;
            }
        }

        /* General Dashboard Layout and Message styles remain from your previous code */
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
            min-height: 100vh;
            width: 100%;
        }
        .top-navbar {
            background-color: #004494;
            color: white;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            width: 100%;
            position: fixed; /* Keep navbar fixed */
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000; /* Ensure it's on top */
        }
        .top-navbar .logo img {
            height: 40px;
        }
        .top-navbar h2 {
            margin: 0;
            font-size: 1.8em;
            flex-grow: 1;
            text-align: center;
        }
        .top-navbar .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .top-navbar .user-info .profile-icon {
            font-size: 1.5em;
        }
        .top-navbar .user-info a {
            color: #ffcc29;
            text-decoration: none;
            font-weight: bold;
        }
        .top-navbar .menu-toggle {
            display: none; /* Hidden on desktop */
            font-size: 1.8em;
            cursor: pointer;
        }

        /* Sidebar styles */
        .sidebar {
            width: 250px;
            background-color: #003366; /* Darker blue for sidebar */
            color: white;
            padding-top: 80px; /* Space for fixed top navbar */
            position: fixed;
            height: 100vh;
            top: 0;
            left: 0;
            z-index: 900;
            transition: transform 0.3s ease;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar ul li a {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        .sidebar ul li a .fas {
            margin-right: 15px;
            font-size: 1.2em;
        }
        .sidebar ul li a:hover,
        .sidebar ul li.active a {
            background-color: #0056b3;
            border-left: 5px solid #ffcc29;
            padding-left: 20px;
        }
        
        .main-content-wrapper {
            flex-grow: 1;
            padding: 20px;
            padding-top: 80px; /* Space for fixed top navbar */
            margin-left: 250px; /* Offset for sidebar */
            transition: margin-left 0.3s ease;
        }

        /* Mobile adjustments */
        @media (max-width: 768px) {
            .top-navbar h2 {
                font-size: 1.2em; /* Smaller title on mobile */
                text-align: left;
                margin-left: 20px;
            }
            .top-navbar .user-info {
                display: none; /* Hide user info on small screens, can be moved to sidebar */
            }
            .top-navbar .menu-toggle {
                display: block; /* Show menu toggle on mobile */
            }
            .sidebar {
                transform: translateX(-250px); /* Hide sidebar by default */
            }
            .sidebar.active {
                transform: translateX(0); /* Show sidebar when active */
            }
            .sidebar-overlay {
                display: none; /* Hidden by default */
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 800;
            }
            .sidebar-overlay.active {
                display: block;
            }
            .main-content-wrapper {
                margin-left: 0; /* No offset on mobile */
            }
        }
        
        /* Message styles */
        .message {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: bold;
            border: 1px solid transparent;
            word-wrap: break-word; /* Ensure messages wrap */
        }
        .message.success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .message.error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        .message.info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }

        /* Card Actions Section */
        .card-actions-section {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            padding: 25px;
            margin-top: 30px;
        }
        .card-actions-section h4 {
            color: #333;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.5em;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .form-group.switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .form-group.switch-container label {
            font-weight: bold;
            color: #333;
            flex-shrink: 0;
            margin-right: 15px;
        }
        /* The Switch - from your bank_cards.css */
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }
        input:checked + .slider {
            background-color: #007bff; /* Active color */
        }
        input:focus + .slider {
            box-shadow: 0 0 1px #007bff;
        }
        input:checked + .slider:before {
            -webkit-transform: translateX(26px);
            -ms-transform: translateX(26px);
            transform: translateX(26px);
        }
        /* Rounded sliders */
        .slider.round {
            border-radius: 34px;
        }
        .slider.round:before {
            border-radius: 50%;
        }

        .button-primary {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: bold;
            transition: background-color 0.3s ease;
            width: 100%; /* Make button full width */
            margin-top: 15px; /* Space between forms/buttons */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .button-primary:hover {
            background-color: #0056b3;
        }
        .button-primary.button-danger {
            background-color: #dc3545; /* Red for danger action */
        }
        .button-primary.button-danger:hover {
            background-color: #c82333;
        }
        .back-link-container {
            text-align: center;
            margin-top: 30px;
        }
        .back-link {
            color: #004494;
            text-decoration: none;
            font-weight: bold;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #0056b3;
        }
        .no-data-found {
            text-align: center;
            padding: 40px 20px;
            background-color: #f8f8f8;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            margin-top: 30px;
        }
        .no-data-found p {
            font-size: 1.1em;
            color: #555;
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
                    <?php if (isset($card['card_network'])): ?>
                        <img src="<?php echo rtrim(BASE_URL, '/'); ?>/images/<?php echo strtolower(htmlspecialchars($card['card_network'])); ?>_logo.png" alt="<?php echo htmlspecialchars($card['card_network']); ?>" class="card-network-logo" onerror="this.style.display='none'">
                    <?php endif; ?>

                    <div class="card-chip"></div>

                    <div class="card-number"><?php echo htmlspecialchars(wordwrap($card['card_number_display'], 4, ' ', true)); ?></div>

                    <div class="card-details-bottom">
                        <div class="card-details-group">
                            <div class="card-details-label">Card Holder</div>
                            <div class="card-holder-name"><?php echo htmlspecialchars($card['card_holder_name']); ?></div>
                        </div>
                        <div class="card-details-group right">
                            <div class="card-details-label">Expires</div>
                            <div class="card-expiry"><?php echo htmlspecialchars($card['expiry_display']); ?></div>
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
                    <p><a href="bank_cards.php" class="back-link">Go to My Cards</a></p>
                </div>
            <?php endif; ?>

            <p class="back-link-container"><a href="bank_cards.php" class="back-link">&larr; Back to My Cards</a></p>
        </main>
    </div>

    <script>
        // JavaScript for mobile menu toggle
        function toggleMenu() {
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.querySelector('.sidebar-overlay');
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            menuToggle.addEventListener('click', toggleMenu);

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