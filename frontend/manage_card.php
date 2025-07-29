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
     <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/bank_cards.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
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