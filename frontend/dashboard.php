<?php
// C:\xampp_lite_8_4\www\phpfile-main\frontend\dashboard.php

error_log("--- dashboard.php Start ---");
error_log("Session ID (dashboard.php entry): " . session_id());
error_log("Session Contents (dashboard.php entry): " . print_r($_SESSION, true));

// Ensure session is started and user is authenticated
// Assumes a central router or a dedicated auth file handles session_start()
// and setting $_SESSION['user_id'] and $_SESSION['logged_in']
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/../functions.php'; // For getCollection()

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\Exception as MongoDBDriverException;

if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login');
    exit;
}

$usersCollection = getCollection('users');
$loggedInUserId = $_SESSION['user_id'];
$userData = null;
$transferModalMessage = '';
$showTransferModal = false;
$cardModalMessage = ''; // NEW: For View My Cards Modal
$showCardModal = false; // NEW: For View My Cards Modal
$message = '';
$message_type = '';

try {
    $userData = $usersCollection->findOne(['_id' => new ObjectId($loggedInUserId)]);

    if ($userData) {
        $transferModalMessage = $userData['transfer_modal_message'] ?? '';
        $showTransferModal = $userData['show_transfer_modal'] ?? false;
        $cardModalMessage = $userData['card_modal_message'] ?? ''; // NEW
        $showCardModal = $userData['show_card_modal'] ?? false; // NEW
    } else {
        // User data not found, possibly log out or show error
        $message = "User data not found.";
        $message_type = "error";
        // Recommendation: If user data not found, forcibly log out for security.
        header('Location: ' . rtrim(BASE_URL, '/') . '/logout'); // Corrected for clean URL
        exit;
    }
} catch (MongoDBDriverException $e) {
    $message = "Database error: " . $e->getMessage();
    $message_type = "error";
    error_log("Frontend Dashboard User Data Load Error: " . $e->getMessage());
} catch (Exception $e) {
    $message = "An unexpected error occurred: " . $e->getMessage();
    $message_type = "error";
    error_log("Frontend Dashboard General Error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Heritage Bank</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* General Styles */
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7f6;
            color: #333;
        }

        .dashboard-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background-color: #fff;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 1200px;
        }

        .dashboard-header {
            background-color: #007bff;
            color: white;
            padding: 20px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #0056b3;
        }

        .dashboard-header .logo {
            max-height: 50px;
            width: auto;
            margin-right: 20px;
        }

        .dashboard-header h1 {
            margin: 0;
            font-size: 2em;
            flex-grow: 1;
        }

        .user-info {
            font-size: 1.1em;
            font-weight: 300;
        }

        .logout-button {
            background-color: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
            margin-left: 20px;
        }

        .logout-button:hover {
            background-color: #c82333;
        }

        .dashboard-content {
            padding: 30px;
            flex-grow: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .card {
            background-color: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            flex: 1 1 calc(33% - 20px); /* Adjust for responsiveness */
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            min-width: 280px; /* Minimum width for cards */
        }

        .card h3 {
            color: #007bff;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.4em;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            width: 100%;
        }

        .card p {
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .card .balance {
            font-size: 1.8em;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 15px;
        }

        .card .btn {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
            cursor: pointer;
            margin-top: auto; /* Pushes button to the bottom */
        }

        .card .btn:hover {
            background-color: #0056b3;
        }

        .message-box {
            width: 100%;
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            text-align: center;
            font-size: 0.95em;
        }
        .message-box.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-box.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message-box.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.6); /* Black w/ opacity */
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 10px;
            width: 80%; /* Could be more specific, e.g., 500px */
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
            text-align: center;
        }

        .modal-content h2 {
            color: #007bff;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        .modal-content p {
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-content {
                flex-direction: column;
                align-items: center;
            }
            .card {
                flex: 1 1 90%;
            }
            .dashboard-header {
                flex-direction: column;
                text-align: center;
            }
            .dashboard-header .logo {
                margin-bottom: 10px;
            }
            .logout-button {
                margin-top: 10px;
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Heritage Bank Logo" class="logo">
            <h1>Welcome, <?php echo htmlspecialchars($userData['first_name'] ?? 'User'); ?>!</h1>
            <div class="user-info">
                <span>Account: <?php echo htmlspecialchars($userData['account_number'] ?? 'N/A'); ?></span>
            </div>
            <a href="<?php echo rtrim(BASE_URL, '/') . '/logout'; ?>" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <?php if ($message): ?>
                <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h3>Account Balance</h3>
                <p>Your current available balance is:</p>
                <p class="balance">$<?php echo number_format($userData['account_balance'] ?? 0, 2); ?></p>
            </div>

            <div class="card">
                <h3>Recent Transactions</h3>
                <p>Review your latest account activities.</p>
                <a href="<?php echo rtrim(BASE_URL, '/') . '/transactions'; ?>" class="btn">View Transactions</a>
            </div>

            <div class="card">
                <h3>Fund Transfer</h3>
                <p>Transfer funds to other accounts quickly.</p>
                <button id="transferButton" class="btn">Transfer Funds</button>
            </div>

            <div class="card">
                <h3>View My Cards</h3>
                <p>Access details about your linked cards.</p>
                <button id="viewCardsButton" class="btn">View My Cards</button>
            </div>

            <div class="card">
                <h3>Profile Settings</h3>
                <p>Update your personal information and preferences.</p>
                <a href="<?php echo rtrim(BASE_URL, '/') . '/profile'; ?>" class="btn">Manage Profile</a>
            </div>
        </div>
    </div>

    <div id="transferModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 id="transferModalTitle">Important Transfer Notice</h2>
            <p id="transferModalMessage"></p>
            <button class="btn" onclick="continueTransfer()">Continue to Transfer</button>
            <button class="btn" style="background-color: #6c757d;" onclick="closeModal('transferModal')">Cancel</button>
        </div>
    </div>

    <div id="viewCardsModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 id="viewCardsModalTitle">Information About Your Cards</h2>
            <p id="viewCardsModalMessage"></p>
            <button class="btn" onclick="continueViewCards()">View Cards Now</button>
            <button class="btn" style="background-color: #6c757d;" onclick="closeModal('viewCardsModal')">Close</button>
        </div>
    </div>

    <script>
        // PHP variables for JavaScript
        const showTransferModal = <?php echo json_encode($showTransferModal); ?>;
        const transferModalMessage = <?php echo json_encode($transferModalMessage); ?>;
        const showCardModal = <?php echo json_encode($showCardModal); ?>; // NEW
        const cardModalMessage = <?php echo json_encode($cardModalMessage); ?>; // NEW

        // Get the modals
        const transferModal = document.getElementById('transferModal');
        const viewCardsModal = document.getElementById('viewCardsModal'); // NEW

        // Get the buttons that open the modals
        const transferBtn = document.getElementById('transferButton');
        const viewCardsBtn = document.getElementById('viewCardsButton'); // NEW

        // Get the <span> elements that close the modals
        const closeButtons = document.querySelectorAll('.close-button');

        // Function to open a specific modal
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex'; // Use 'flex' for centering
        }

        // Function to close a specific modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Event listener for transfer button
        if (transferBtn) {
            transferBtn.addEventListener('click', function() {
                if (showTransferModal && transferModalMessage) {
                    document.getElementById('transferModalMessage').innerText = transferModalMessage;
                    openModal('transferModal');
                } else {
                    // Default action if no modal is set or active
                    window.location.href = '<?php echo rtrim(BASE_URL, '/'); ?>/transfer-funds'; // Corrected for clean URL
                }
            });
        }

        // Event listener for view cards button (NEW)
        if (viewCardsBtn) {
            viewCardsBtn.addEventListener('click', function() {
                if (showCardModal && cardModalMessage) {
                    document.getElementById('viewCardsModalMessage').innerText = cardModalMessage;
                    openModal('viewCardsModal');
                } else {
                    // Default action if no modal is set or active for 'View My Cards'
                    window.location.href = '<?php echo rtrim(BASE_URL, '/'); ?>/my-cards'; // Corrected for clean URL
                }
            });
        }

        // Attach close event to all close buttons
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Find the parent modal and hide it
                this.closest('.modal').style.display = 'none';
            });
        });

        // Close modal if user clicks outside of it
        window.addEventListener('click', function(event) {
            if (event.target == transferModal) {
                transferModal.style.display = 'none';
            }
            if (event.target == viewCardsModal) { // NEW
                viewCardsModal.style.display = 'none';
            }
        });

        // Functions for 'Continue' actions within the modals
        function continueTransfer() {
            closeModal('transferModal');
            // Navigate to the transfer page or initiate transfer process
            window.location.href = '<?php echo rtrim(BASE_URL, '/'); ?>/transfer-funds'; // Corrected for clean URL
        }

        function continueViewCards() { // NEW
            closeModal('viewCardsModal');
            // Navigate to the view cards page or initiate card display
            window.location.href = '<?php echo rtrim(BASE_URL, '/'); ?>/my-cards'; // Corrected for clean URL
        }

    </script>
</body>
</html>
