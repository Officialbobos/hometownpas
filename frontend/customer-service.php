<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hometown Bank PA - Customer Service</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Basic Reset & Body Styles */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f7fa; /* Light background */
            color: #333;
            line-height: 1.6;
        }

        /* Header Styles */
        .header {
            background-color: #ffffff;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header .logo img {
            max-height: 50px; /* Adjust logo size */
            width: auto;
        }

        .header h1 {
            color: #2575fc; /* Accent color */
            font-size: 1.8rem;
            margin: 0;
            flex-grow: 1;
            text-align: center;
        }

        /* Main Content Area */
        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 30px;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .container h2 {
            color: #6a11cb; /* Another accent color */
            font-size: 2.2rem;
            margin-bottom: 25px;
        }

        .contact-info p {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: #555;
        }

        .contact-buttons {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 30px;
            align-items: center;
        }

        .contact-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 300px; /* Limit button width */
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none; /* For anchor tags used as buttons */
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .contact-button i {
            margin-right: 12px;
            font-size: 1.3rem;
        }

        .contact-button.email {
            background: linear-gradient(45deg, #007bff, #0056b3);
        }

        .contact-button.email:hover {
            background: linear-gradient(45deg, #0056b3, #003d80);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .contact-button.phone {
            background: linear-gradient(45deg, #28a745, #1e7e34);
        }

        .contact-button.phone:hover {
            background: linear-gradient(45deg, #1e7e34, #155d2b);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        /* New style for the homepage button */
        .contact-button.homepage {
            background: linear-gradient(45deg, #6a11cb, #2575fc); /* Using existing accent colors */
        }

        .contact-button.homepage:hover {
            background: linear-gradient(45deg, #2575fc, #6a11cb);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .faq-section {
            margin-top: 50px;
            padding-top: 30px;
            border-top: 1px solid #eee;
            text-align: left;
        }

        .faq-section h3 {
            color: #2575fc;
            font-size: 1.8rem;
            margin-bottom: 20px;
            text-align: center;
        }

        .faq-item {
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .faq-question {
            padding: 18px 25px;
            font-size: 1.05rem;
            font-weight: 600;
            color: #444;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #eef3f7;
            transition: background-color 0.2s ease;
        }

        .faq-question:hover {
            background-color: #e0e7ed;
        }

        .faq-question i {
            transition: transform 0.3s ease;
        }

        .faq-answer {
            padding: 0 25px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out, padding 0.4s ease-out;
            color: #666;
            font-size: 0.95rem;
        }

        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }

        .faq-item.active .faq-answer {
            max-height: 150px; /* Adjust as needed, should be larger than content */
            padding: 15px 25px 20px;
        }

        /* Footer Styles */
        .footer {
            text-align: center;
            padding: 30px 20px;
            margin-top: 50px;
            background-color: #2575fc;
            color: #ffffff;
            font-size: 0.9rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px 20px;
            }
            .header .logo {
                margin-bottom: 10px;
            }
            .header h1 {
                font-size: 1.5rem;
            }
            .container {
                margin: 20px auto;
                padding: 20px;
            }
            .container h2 {
                font-size: 1.8rem;
            }
            .contact-button {
                font-size: 1rem;
                padding: 12px 20px;
            }
            .contact-button i {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">
            <img src="https://i.imgur.com/YmC3kg3.png" alt="Hometown Bank PA Logo">
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
            <a href="dashboard.php" class="contact-button homepage">
                <i class="fas fa-home"></i> Back to Homepage
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