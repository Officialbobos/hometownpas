document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const twoFactorForm = document.getElementById('twoFactorForm'); // This element isn't in index.php, but will be in verify_code.php
    const loginMessage = document.getElementById('login-message');
    const backToLoginBtn = document.getElementById('backToLoginBtn'); // This element is likely in verify_code.php
    const resendCodeBtn = document.getElementById('resendCodeBtn');   // This element is likely in verify_code.php

    // These variables need to be passed from PHP to JavaScript
    // We'll embed them directly into the HTML using data attributes or a global JS object
    // For now, we'll assume they are implicitly set by the PHP rendered HTML.
    // However, for clean separation, it's better to pass them as below.

    // Let's get the initial state from data attributes on the body or a div
    // For simplicity here, we'll retrieve them from the HTML elements themselves if present.
    // A more robust way would be to echo them into a global JS variable in index.php head or before this script.
    // Example: <script>window.appState = { show2FaInitially: <?php echo json_encode($show_2fa_form); ?>, messageType: "<?php echo htmlspecialchars($message_type); ?>" };</script>
    // Then access: const show2FaInitially = window.appState.show2FaInitially;

    // For the current setup, the PHP handles the initial display directly.
    // The JS only needs to react to user interactions or dynamic messages.

    // Get initial state from attributes if not directly set by PHP display style
    // You might want to add data-attributes to your forms for this
    // e.g., <form id="loginForm" data-show-initially="<?php echo $show_2fa_form ? 'false' : 'true'; ?>">

    // The current display logic is primarily driven by PHP, which is fine.
    // This JS will handle dynamic changes after the initial page load (e.g., messages, button clicks).

    // Display message box if there's a message from PHP
    // The PHP already sets the display and classes on page load for initial messages.
    // This part is more for dynamic messages that JavaScript might generate.
    if (loginMessage && loginMessage.textContent.trim() !== '') {
        // Assume initial state is set by PHP
        // No need to re-set display or classes here if PHP already handled it.
        // This is primarily for messages generated client-side by JS.
        if (loginMessage.classList.contains('success')) {
            setTimeout(() => {
                loginMessage.style.display = 'none';
                loginMessage.textContent = ''; // Clear message too
                loginMessage.className = 'message-box'; // Reset classes
            }, 5000); // Hide after 5 seconds
        }
    }


    // Handle "Back to Login" button for 2FA form
    if (backToLoginBtn) {
        backToLoginBtn.addEventListener('click', function() {
            // Redirect to reset the 2FA session on the server
            window.location.href = 'index.php?action=reset_2fa';
        });
    }

    // Handle "Resend Code" button for 2FA form
    if (resendCodeBtn) {
        resendCodeBtn.addEventListener('click', function() {
            // Disable the button to prevent multiple clicks
            resendCodeBtn.disabled = true;
            // Set a timeout to re-enable the button after a delay (e.g., 30 seconds)
            setTimeout(() => {
                resendCodeBtn.disabled = false;
            }, 30000); // Re-enable after 30 seconds

            loginMessage.textContent = 'Resending code... Please wait.';
            loginMessage.classList.remove('error', 'info'); // Remove other message types
            loginMessage.classList.add('success'); // Assume success for "sending" status
            loginMessage.style.display = 'block';

            // Make an AJAX request to resend_code.php
            fetch('resend_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'resend=true'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loginMessage.textContent = data.message;
                    loginMessage.classList.remove('error');
                    loginMessage.classList.add('success');
                } else {
                    loginMessage.textContent = data.message;
                    loginMessage.classList.remove('success');
                    loginMessage.classList.add('error');
                }
                // Set a timeout to hide the message, regardless of success or error
                setTimeout(() => {
                    loginMessage.style.display = 'none';
                    loginMessage.textContent = ''; // Clear message too
                    loginMessage.className = 'message-box'; // Reset classes
                }, 5000);
            })
            .catch(error => {
                console.error('Error resending 2FA code:', error);
                loginMessage.textContent = 'Failed to resend code due to a network error. Please try again.';
                loginMessage.classList.remove('success');
                loginMessage.classList.add('error');
                setTimeout(() => {
                    loginMessage.style.display = 'none';
                    loginMessage.textContent = ''; // Clear message too
                    loginMessage.className = 'message-box'; // Reset classes
                }, 5000);
            });
        });
    }
});