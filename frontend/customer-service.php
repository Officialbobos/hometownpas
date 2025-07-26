<?php
// Path: C:\xampp\htdocs\hometownbank\frontend\customer_service.php

// Ensure session is started.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error display for debugging. Remember to disable or set to 0 in production.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "DEBUG: customer-service.php - Start of file.<br>\n"; // ADDED DEBUG

// Include Composer's autoloader for any libraries (like PHPMailer for sendEmail)
require_once __DIR__ . '/../vendor/autoload.php';
echo "DEBUG: customer-service.php - After autoload.php include.<br>\n"; // ADDED DEBUG

// Include your custom configuration and functions files
require_once __DIR__ . '/../Config.php';
echo "DEBUG: customer-service.php - After Config.php include.<br>\n"; // ADDED DEBUG

require_once __DIR__ . '/../functions.php'; // For sanitize_input, getMongoDBClient(), sendEmail(), etc.
echo "DEBUG: customer-service.php - After functions.php include.<br>\n"; // ADDED DEBUG

echo "DEBUG: customer-service.php - All PHP includes processed. Preparing HTML.<br>\n"; // ADDED DEBUG

// No specific backend logic is needed for a static customer service display page
// unless you plan to dynamically fetch FAQs from a database, etc.
// For now, it's a static page, so no database connection is required on initial load.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Customer Service</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Your CSS here */
        /* ... */
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="https://i.imgur.com/UeqGGSn.png" alt="HomeTown Bank Logo">
        </div>
        <h1>Customer Service</h1>
    </header>

    <main class="container">
        <h2>We're Here to Help!</h2>
        <p class="contact-info">
            Our dedicated customer service team is available to assist you with any questions, concerns, or support you may need.
            Please choose your preferred method of contact below.
        </p>
        <p class="contact-info">
            We aim to respond to all inquiries as quickly as possible.
        </p>

        <div class="contact-buttons">
            <a href="mailto:hometowncustomersercvice@gmail.com" class="contact-button email">
                <i class="fas fa-envelope"></i> Email Us
            </a>
            <a href="tel:+12544007639" class="contact-button phone">
                <i class="fas fa-phone-alt"></i> Call Us: +1 254-400-7639
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard" class="contact-button homepage">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
        </div>

        <section class="faq-section">
            <h3>Frequently Asked Questions</h3>
            <div class="faq-item">
                <div class="faq-question">
                    How do I reset my online banking password?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    You can reset your password directly from the login page by clicking on the "Forgot Password?" link. Follow the on-screen instructions to verify your identity and set a new password.
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question">
                    What are your branch hours?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Our branch hours vary by location. Please visit the "Locations" section on our main website or use our branch locator tool to find the hours for your nearest Hometown Bank PA branch.
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question">
                    How can I report a lost or stolen card?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Immediately report a lost or stolen card by calling our 24/7 fraud hotline at +1-800-987-6543. You can also temporarily freeze your card through your online banking portal or mobile app.
                </div>
            </div>
            <div class="faq-item">
                <div class="faq-question">
                    What documents do I need to open a new account?
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Typically, you will need a valid government-issued ID (like a driver's license or passport), your Social Security Number, and proof of address. Please contact us or visit a branch for specific requirements based on the account type you wish to open.
                </div>
            </div>
        </section>

    </main>

    <footer class="footer">
        <p>&copy; 2025 Hometown Bank PA. All rights reserved.</p>
        <p>Your trusted partner for financial success.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const faqQuestions = document.querySelectorAll('.faq-question');

            faqQuestions.forEach(question => {
                question.addEventListener('click', () => {
                    const faqItem = question.closest('.faq-item');
                    faqItem.classList.toggle('active'); // Toggles the 'active' class
                });
            });
        });
    </script>

</body>
</html>