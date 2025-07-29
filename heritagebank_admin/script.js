document.addEventListener('DOMContentLoaded', function() {
    // The form ID in your index.php is 'adminLoginForm', not 'loginForm'.
    // Let's update this to match for clarity.
    const loginForm = document.getElementById('adminLoginForm');

    if (loginForm) {
        loginForm.addEventListener('submit', function(event) {
            // We are using 'email', not 'username'.
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            // Basic client-side validation
            if (emailInput.value.trim() === '' || passwordInput.value.trim() === '') {
                // Update the alert message to be specific to email and password
                alert('Please enter both email and password.');
                event.preventDefault(); // Stop form submission
            }
        });
    }

    // You can add more client-side functionalities here for the dashboard,
    // like dynamic content loading, interactive charts, etc.
});