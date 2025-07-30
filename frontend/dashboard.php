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
    <title>HomeTown Bank Pa - Dashboard</title>
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/style.css">
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/frontend/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="menu-icon" id="menuIcon">
                <i class="fas fa-bars"></i>
            </div>
            <div class="greeting">
                <h1 data-user-first-name="<?php echo htmlspecialchars($first_name); ?>">Hi, </h1>
        </header>

        <section class="accounts-section">
            <div class="accounts-header-row">
                <h2>Accounts</h2>
                <div class="view-all-link">
                    <a href="accounts.php">View all</a>
                </div>
            </div>
            <div class="account-cards-container">
                <?php if (empty($user_accounts)): ?>
                    <p class="loading-message" id="accountsLoadingMessage">No accounts found. Please contact support.</p>
                <?php else: ?>
                    <?php foreach ($user_accounts as $account): ?>
                        <div class="account-card">
                            <div class="account-details">
                                <p class="account-type"><?php echo htmlspecialchars(strtoupper($account['account_type'] ?? 'N/A')); ?></p>
                                <p class="account-number">**** **** **** <?php echo htmlspecialchars(substr($account['account_number'] ?? 'N/A', -4)); ?></p>
                            </div>
                            <div class="account-balance">
                                <p class="balance-amount">
                                    <?php echo htmlspecialchars($account['currency'] ?? 'USD'); ?> <?php echo number_format($account['balance'] ?? 0, 2); ?>
                                </p>
                                <p class="balance-status">Available</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="account-pagination">
            </div>
        </section>

        <section class="actions-section">
            <div class="action-button" id="transferButton">
                <i class="fas fa-exchange-alt"></i>
                <p>Transfer</p>
            </div>
            <div class="action-button" id="depositButton">
                <i class="fas fa-download"></i>
                <p>Deposit</p>
            </div>
            <div class="action-button">
                <i class="fas fa-dollar-sign"></i>
                <p>Pay</p>
            </div>
            <div class="action-button" id="messageButton" onclick="window.location.href='customer-service.php'">
                <i class="fas fa-headset"></i> <p>Customer Service</p>
            </div>
        </section>

        <section class="bank-cards-section">
            <h2>My Cards</h2>
            <a class="view-cards-button" id="viewMyCardsButton" href="bank_cards.php">
                <i class="fas fa-credit-card"></i> View My Cards
            </a>
            <div class="card-list-container" id="userCardList" style="display: none;">
                <p class="loading-message" id="cardsLoadingMessage">No cards found. Go to "Manage All Cards" to add one.</p>
            </div>
        </section>

        <section class="activity-section">
            <div class="transactions-header">
                <h2>Transactions</h2> <span class="more-options" onclick="window.location.href='statements.php'">...</span>
            </div>
            <div class="transaction-list">
                <?php if (empty($recent_transactions)): ?>
                    <p class="loading-message" id="transactionsLoadingMessage">No recent transactions to display.</p>
                <?php else: ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <div class="transaction-item">
                            <div class="transaction-details">
                                <span class="transaction-description"><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></span>
                                <span class="transaction-account">
                                    <?php
                                    // In MongoDB, you might not directly join like in SQL.
                                    // You might store account_number and account_type directly in the transaction document,
                                    // or fetch the account details separately for each transaction using its account_id.
                                    // For simplicity here, assuming they are available in the transaction document.
                                    echo htmlspecialchars($transaction['account_type'] ?? 'N/A');
                                    if (isset($transaction['account_number'])) {
                                        echo ' x' . htmlspecialchars(substr($transaction['account_number'], -4));
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="transaction-amount-date">
                                <span class="transaction-amount <?php echo (($transaction['transaction_type'] ?? '') == 'Credit') ? 'credit' : 'debit'; ?>">
                                    <?php echo (($transaction['transaction_type'] ?? '') == 'Credit' ? '+' : '-'); ?>
                                    <?php echo htmlspecialchars($transaction['currency'] ?? 'USD'); ?> <?php echo number_format($transaction['amount'] ?? 0, 2); ?>
                                </span>
                                <span class="transaction-date">
                                    <?php
                                    // MongoDB stores dates as BSON Date objects. Convert to a readable format.
                                    if (isset($transaction['initiated_at']) && $transaction['initiated_at'] instanceof MongoDB\BSON\UTCDateTime) {
                                        echo htmlspecialchars(date('M d', $transaction['initiated_at']->toDateTime()->getTimestamp()));
                                    } else if (isset($transaction['initiated_at'])) {
                                        // Fallback if it's a string, or other format
                                        echo htmlspecialchars(date('M d', strtotime($transaction['initiated_at'])));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button class="see-more-button" onclick="window.location.href='statements.php'">See more</button>
        </section>

    </div>

    <div class="transfer-modal-overlay" id="transferModalOverlay">
        <div class="transfer-modal-content">
            <h3>Choose Transfer Type</h3>
            <div class="transfer-options-list">
                <button class="transfer-option" data-transfer-type="Own Account" onclick="window.location.href='transfer.php?type=own_account'">
                    <i class="fas fa-wallet"></i> <p>Transfer to My Other Account</p>
                </button>

                <button class="transfer-option" data-transfer-type="Bank to Bank" onclick="window.location.href='transfer.php?type=bank_to_bank'">
                    <i class="fas fa-university"></i>
                    <p>Bank to Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="ACH" onclick="window.location.href='transfer.php?type=ach'">
                    <i class="fas fa-exchange-alt"></i>
                    <p>ACH Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Wire" onclick="window.location.href='transfer.php?type=wire'">
                    <i class="fas fa-ethernet"></i>
                    <p>Wire Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="International Bank" onclick="window.location.href='transfer.php?type=international_bank'">
                    <i class="fas fa-globe"></i>
                    <p>International Bank Transfer</p>
                </button>
                <button class="transfer-option" data-transfer-type="Domestic Wire" onclick="window.location.href='transfer.php?type=domestic_wire'">
                    <i class="fas fa-home"></i>
                    <p>Domestic Wire Transfer</p>
                </button>
            </div>
            <button class="close-modal-button" id="closeTransferModal">Close</button>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="close-sidebar-button" id="closeSidebarBtn">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-profile">
                <img src="/heritagebank/images/default-profile.png" alt="Profile Picture" class="sidebar-profile-pic">
                <h3><span id="sidebarUserName"><?php echo htmlspecialchars($full_name); ?></span></h3>
                <p><span id="sidebarUserEmail"><?php echo htmlspecialchars($user_email); ?></span></p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="accounts.php"><i class="fas fa-wallet"></i> Accounts</a></li>
                <li><a href="transfer.php"><i class="fas fa-exchange-alt"></i> Transfers</a></li>
                <li><a href="statements.php"><i class="fas fa-file-invoice"></i> Statements</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="#"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="bank_cards.php"><i class="fas fa-credit-card"></i> Bank Cards</a></li>
            </ul>
        </nav>
        <button class="logout-button" id="logoutButton" onclick="window.location.href='../logout.php'">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </div>

    <script src="<?php echo rtrim(BASE_URL, '/') . '/frontend/user.dashboard.js'; ?>"></script>
</body>
</html>
