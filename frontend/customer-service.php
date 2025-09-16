<?php
//hometownbank\frontend\customer_service.php

// Ensure session is started.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error display for debugging. Remember to disable or set to 0 in production.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Include Composer's autoloader for any libraries (like PHPMailer for sendEmail)
require_once __DIR__ . '/../vendor/autoload.php';

// Include your custom configuration and functions files
require_once __DIR__ . '/../Config.php';

require_once __DIR__ . '/../functions.php'; // For sanitize_input, getMongoDBClient(), sendEmail(), etc.

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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/css/main.css"> <link rel="stylesheet" href="<?php echo BASE_URL; ?>/frontend/css/customer-service.css"> <style>
        /* Your inline CSS here */
        /* It's generally better to link external CSS files */
        /* For example, for the customer service page specifically */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #2c003e, #0a011d); /* Dark purple to very dark purple/blue */
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .header {
            background-color: rgba(30, 0, 45, 0.8);
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: center; /* Center the logo and title */
            border-bottom: 1px solid rgba(150, 0, 255, 0.3);
            color: #ffffff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header .logo img {
            height: 40px; /* Adjust logo size */
            margin-right: 15px;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            color: #ffffff;
            text-shadow: 0 0 8px rgba(255, 255, 255, 0.5);
        }

        .container {
            flex-grow: 1;
            max-width: 900px;
            margin: 30px auto;
            padding: 30px;
            background-color: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
            border-radius: 15px;
            box-shadow: 0 0 25px rgba(150, 0, 255, 0.3), 0 0 40px rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }

        .container h2 {
            color: #ffffff;
            font-size: 2.2em;
            margin-bottom: 20px;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.8);
        }

        .contact-info {
            color: #e0e0e0;
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .contact-buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
            margin-bottom: 40px;
        }

        .contact-button {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px 25px;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: bold;
            text-decoration: none;
            color: #ffffff;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            min-width: 220px;
        }

        .contact-button i {
            margin-right: 10px;
            font-size: 1.3em;
        }

        .contact-button.email {
            background: linear-gradient(45deg, #a052c9, #6a0dad);
            box-shadow: 0 5px 20px rgba(160, 82, 201, 0.4);
        }

        .contact-button.phone {
            background: linear-gradient(45deg, #6a0dad, #8a2be2);
            box-shadow: 0 5px 20px rgba(138, 43, 226, 0.4);
        }

        .contact-button.homepage {
            background: linear-gradient(45deg, #32cd32, #228b22); /* Lime Green / Forest Green */
            box-shadow: 0 5px 20px rgba(50, 205, 50, 0.4);
        }

        .contact-button:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(160, 82, 201, 0.6);
        }

        .contact-button.homepage:hover {
            box-shadow: 0 8px 25px rgba(50, 205, 50, 0.6);
        }


        /* FAQ Section */
        .faq-section {
            text-align: left;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .faq-section h3 {
            color: #e6b3ff;
            font-size: 1.8em;
            margin-bottom: 25px;
            text-align: center;
            text-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
        }

        .faq-item {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .faq-question {
            padding: 18px 25px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #ffffff;
            font-weight: bold;
            font-size: 1.1em;
            background-color: rgba(160, 82, 201, 0.1); /* Subtle purple background */
            transition: background-color 0.3s ease;
        }

        .faq-question:hover {
            background-color: rgba(160, 82, 201, 0.2);
        }

        .faq-question i {
            transition: transform 0.3s ease;
            color: #e6b3ff;
        }

        .faq-answer {
            max-height: 0;
            padding: 0 25px;
            color: #e0e0e0;
            background-color: rgba(255, 255, 255, 0.03);
            transition: max-height 0.5s ease-out, padding 0.5s ease-out;
            overflow: hidden;
            font-size: 1em;
            line-height: 1.5;
        }

        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }

        .faq-item.active .faq-answer {
            max-height: 200px; /* Adjust as needed for content, or use a JS calculation */
            padding: 15px 25px;
        }

        /* Footer */
        .footer {
            background-color: rgba(30, 0, 45, 0.8);
            color: #e0e0e0;
            text-align: center;
            padding: 20px;
            margin-top: auto; /* Pushes footer to the bottom */
            border-top: 1px solid rgba(150, 0, 255, 0.3);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.3);
        }

        .footer p {
            margin: 5px 0;
            font-size: 0.9em;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }

            .container {
                margin: 20px auto;
                padding: 20px;
            }

            .contact-button {
                min-width: unset; /* Allow buttons to shrink */
                width: 90%; /* Take more width on small screens */
            }

            .faq-question {
                font-size: 1em;
                padding: 15px 20px;
            }

            .faq-answer {
                padding: 10px 20px;
                font-size: 0.9em;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 20px;
            }

            .contact-buttons {
                flex-direction: column;
                align-items: center;
            }

            .contact-button {
                padding: 12px 20px;
                font-size: 1em;
            }
        }
    </style>
    <script>
        // If any JavaScript on this page needs BASE_URL_JS, define it here:
        const BASE_URL_JS = "<?php echo BASE_URL; ?>";
    </script>
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
    <a href="mailto:hometownbankpa@gmail.com" class="contact-button email">
        <i class="fas fa-envelope"></i> Email Us
    </a>
    <a href="tel:+12027359874" class="contact-button phone">
        <i class="fas fa-phone-alt"></i> Call Us: +1 202-735-9874
    </a>
    <a href="<?php echo rtrim(BASE_URL, '/'); ?>/dashboard" class="contact-button homepage">
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