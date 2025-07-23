<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once '../../Config.php'; // Adjust path based on your actual file structure

// Check if the admin is NOT logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php'); // Corrected redirect to admin login page
    exit;
}

// Dummy DB connection (replace with actual usage when querying data)
// This file does not directly query the DB, so the connection is commented out, which is fine.
// $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
// if ($conn === false) { die("ERROR: Could not connect to database. " . mysqli_connect_error()); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heritage Bank - User Management</title>
    <link rel="stylesheet" href="../style.css"> <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="../../images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>User Management</h2>
            <a href="../logout.php" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h3>User Management Options</h3>
            <p>Select an action related to user administration:</p>

            <nav class="user-management-nav">
                <ul>
                    <li><a href="create_user.php">Create New User</a></li>
                    <li><a href="manage_users.php">Manage Users (Edit/Delete)</a></li>
                    <li><a href="manage_user_funds.php">Manage User Funds (Credit/Debit)</a></li>
                    <li><a href="account_status_management.php">Manage Account Status</a></li>
                    <li><a href="transactions_management.php">Transactions Management</a></li>
                    <li><a href="generate_bank_card.php">Generate Bank Card (Mock)</a></li>
                    <li><a href="generate_mock_transaction.php">Generate Mock Transaction</a></li>
                </ul>
            </nav>
            <p><a href="../dashboard.php" class="back-link">&larr; Back to Dashboard</a></p>
        </div>
    </div>
    <script src="../script.js"></script> </body>
</html>