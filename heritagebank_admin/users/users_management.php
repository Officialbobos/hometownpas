<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\users_management.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// Load Composer's autoloader for MongoDB classes and Dotenv
require_once __DIR__ . '/../vendor/autoload.php';

// Include necessary files for MongoDB connection and utility functions
// Config.php should be loaded before functions.php if functions rely on its constants.
require_once __DIR__ . '/../Config.php'; // This defines APP_DEBUG and error settings
require_once __DIR__ . '/../functions.php'; // This should contain getMongoDBClient() and getCollection()

// Check if the admin is NOT logged in, redirect to login page
// *************************************************************************************
// *** CRITICAL FIX: Changed redirect to use BASE_URL for consistency with router ***
// *************************************************************************************
if (!isset($_SESSION['admin_user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: ' . BASE_URL . '/admin/login'); // Redirect to admin login page via router
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
    <link rel="stylesheet" href="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        /* Add any specific styles for user_management here if needed, or put them in style.css */
        .user-management-nav ul {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        .user-management-nav li {
            background-color: #f0f8ff;
            border: 1px solid #d0e0ff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .user-management-nav li:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .user-management-nav a {
            display: block;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #007bff;
            font-weight: 600;
            font-size: 1.1em;
        }
        .user-management-nav a:hover {
            color: #0056b3;
        }
        .back-link {
            display: inline-block;
            margin-top: 30px;
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <img src="<?php echo rtrim(BASE_URL, '/'); ?>/images/logo.png" alt="Heritage Bank Logo" class="logo">
            <h2>User Management</h2>
            <a href="<?php echo BASE_URL; ?>/admin/logout" class="logout-button">Logout</a>
        </div>

        <div class="dashboard-content">
            <h3>User Management Options</h3>
            <p>Select an action related to user administration:</p>

            <nav class="user-management-nav">
                <ul>
                    <li><a href="<?php echo BASE_URL; ?>/admin/create_user">Create New User</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/manage_users">Manage Users (Edit/Delete)</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/manage_user_funds">Manage User Funds (Credit/Debit)</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/account_status_management">Manage Account Status</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/transactions_management">Transactions Management</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/generate_bank_card">Generate Bank Card (Mock)</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/admin/generate_mock_transaction">Generate Mock Transaction</a></li>
                </ul>
            </nav>
            <p><a href="<?php echo BASE_URL; ?>/admin" class="back-link">&larr; Back to Dashboard</a></p>
        </div>
    </div>
    <script src="<?php echo rtrim(BASE_URL, '/'); ?>/heritagebank_admin/script.js"></script>
</body>
</html>