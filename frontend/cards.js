// Function to show a message box
function showMessageBox(message, isSuccess) {
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxContent = document.getElementById('messageBoxContent');
    messageBoxContent.textContent = message;
    messageBoxOverlay.style.display = 'flex'; // Make the overlay visible

    // Apply inline styles based on success/error
    if (isSuccess) {
        messageBoxContent.style.color = '#155724'; // Dark green text
        messageBoxContent.style.backgroundColor = '#d4edda'; // Light green background
        messageBoxContent.style.borderColor = '#c3e6cb'; // Green border
    } else {
        messageBoxContent.style.color = '#721c24'; // Dark red text
        messageBoxContent.style.backgroundColor = '#f8d7da'; // Light red background
        messageBoxContent.style.borderColor = '#f5c6cb'; // Red border
    }

    // Add padding, border, and border-radius for styling
    messageBoxContent.style.padding = '20px';
    messageBoxContent.style.border = '1px solid';
    messageBoxContent.style.borderRadius = '5px';
    messageBoxContent.style.textAlign = 'center';
}

document.addEventListener('DOMContentLoaded', () => {
    // Check if PHP_BASE_URL and FRONTEND_BASE_URL are defined by PHP
    if (typeof PHP_BASE_URL === 'undefined' || typeof FRONTEND_BASE_URL === 'undefined') {
        console.error("Critical JavaScript variables (PHP_BASE_URL, FRONTEND_BASE_URL) are not defined. Check PHP include.");
        showMessageBox("Application error: Path configuration missing. Please contact support.", false);
        return; // Stop execution if critical variables are missing
    }

    const userCardList = document.getElementById('userCardList');
    const cardsLoadingMessage = document.getElementById('cardsLoadingMessage');
    const noCardsMessage = document.getElementById('noCardsMessage');
    const orderCardForm = document.getElementById('orderCardForm');
    const accountIdSelect = document.getElementById('accountId');
    const messageBoxButton = document.getElementById('messageBoxButton'); // Get the OK button

    // Function to fetch and populate user's bank accounts for the order form
    async function fetchUserAccounts() {
        accountIdSelect.innerHTML = '<option value="">-- Loading Accounts --</option>';
        try {
            // CORRECTED AJAX call to fetch_user_accounts.php
            const response = await fetch(`${PHP_BASE_URL}fetch_user_accounts.php`);

            if (!response.ok) {
                // If response is not OK (e.g., 404, 500), try to read it as text
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            // Attempt to parse JSON; catch if it's not valid JSON
            const data = await response.json();

            if (data.success && data.accounts.length > 0) {
                accountIdSelect.innerHTML = '<option value="">Select an Account</option>';
                data.accounts.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.id;
                    // Ensure 'currency' is available in your account data if you use it
                    const currency = account.currency ? `${account.currency} ` : '';
                    option.textContent = `${account.account_type} (${account.display_account_number}) - ${currency}${parseFloat(account.balance).toFixed(2)}`;
                    accountIdSelect.appendChild(option);
                });
            } else {
                accountIdSelect.innerHTML = '<option value="">No Accounts Found</option>';
                console.error("No accounts found for the user or error fetching accounts:", data.message || "Unknown error");
                showMessageBox(data.message || "No bank accounts found for your profile. Please add an account before ordering a card.", false);
            }
        } catch (error) {
            accountIdSelect.innerHTML = '<option value="">Error Loading Accounts</option>';
            console.error("Error fetching user accounts:", error);
            // Check if the error is due to a non-JSON response from the server (e.g., 404 HTML)
            if (error.message.includes("Unexpected token '<'")) {
                 showMessageBox("Failed to load accounts: Server returned an unexpected response. This often means the server couldn't find the requested file or there's a PHP error. Please contact support.", false);
            } else {
                showMessageBox(`Failed to load accounts: ${error.message}. Please try refreshing the page.`, false);
            }
        }
    }

    // Function to fetch and display user's bank cards
    async function fetchUserCards() {
        cardsLoadingMessage.style.display = 'block';
        userCardList.innerHTML = ''; // Clear existing cards
        noCardsMessage.style.display = 'none'; // Hide "no cards" message initially

        try {
            // This AJAX call correctly targets bank_cards.php for fetching cards
            const response = await fetch(`${PHP_BASE_URL}bank_cards.php?action=fetch_cards`);

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const data = await response.json();

            if (data.success && data.cards.length > 0) {
                data.cards.forEach(card => {
                    const cardItem = document.createElement('div');
                    const networkClass = card.card_network ? card.card_network.toLowerCase() : 'default';
                    cardItem.className = `card-item ${networkClass}`;

                    // Helper function to get card logo path
                    const cardLogoPath = (network) => {
                        // Use FRONTEND_BASE_URL for images, assuming your logos are like /frontend/images/visa_logo.png
                        if (network === 'Visa') return `${FRONTEND_BASE_URL}images/visa_logo.png`;
                        if (network === 'Mastercard') return `${FRONTEND_BASE_URL}images/mastercard_logo.png`;
                        if (network === 'Amex') return `${FRONTEND_BASE_URL}images/amex_logo.png`;
                        if (network === 'Verve') return `${FRONTEND_BASE_URL}images/verve_logo.png`;
                        return ''; // Return empty string if no specific logo
                    };

                    // Construct card HTML
                    cardItem.innerHTML = `
                        <h4>HOMETOWN BANK</h4>
                        <div class="chip"></div>
                        <div class="card-number">${card.display_card_number}</div>
                        <div class="card-footer">
                            <div>
                                <div class="label">CARD HOLDER</div>
                                <div class="value">${card.card_holder_name}</div>
                            </div>
                            <div>
                                <div class="label">EXPIRES</div>
                                <div class="value">${card.display_expiry}</div>
                            </div>
                        </div>
                        <p style="font-size: 0.8em; text-align: center; margin-top: 10px; color: rgba(255,255,255,0.7);">
                            CVV: ${card.display_cvv} (Mock)
                        </p>
                        ${card.is_active == 1 ? '<div class="card-status active">Active</div>' : '<div class="card-status inactive">Inactive</div>'}
                        ${cardLogoPath(card.card_network) ? `<img src="${cardLogoPath(card.card_network)}" alt="${card.card_network} Logo" class="card-logo">` : ''}
                    `;
                    userCardList.appendChild(cardItem);
                });
            } else {
                noCardsMessage.style.display = 'block'; // Show "no cards" message
            }
        } catch (error) {
            console.error('Error fetching cards:', error);
            noCardsMessage.textContent = `Error loading cards: ${error.message}. Please try again.`;
            noCardsMessage.style.display = 'block';
            // Check if the error is due to a non-JSON response from the server (e.g., 404 HTML)
            if (error.message.includes("Unexpected token '<'")) {
                 showMessageBox("Failed to load cards: Server returned an unexpected response. This often means the server couldn't find the requested file or there's a PHP error. Please contact support.", false);
            } else {
                showMessageBox(`Failed to load cards: ${error.message}`, false);
            }
        } finally {
            cardsLoadingMessage.style.display = 'none'; // Always hide loading message
        }
    }

    // Call functions on page load
    fetchUserAccounts(); // Populate the account dropdown
    fetchUserCards();    // Display existing cards

    // Handle order card form submission
    orderCardForm.addEventListener('submit', async (e) => {
        e.preventDefault(); // Prevent default form submission

        const formData = new FormData(orderCardForm);
        formData.append('action', 'order_card'); // Ensure action is always present for bank_cards.php

        try {
            // This AJAX call correctly targets bank_cards.php for ordering cards
            const response = await fetch(`${PHP_BASE_URL}bank_cards.php`, {
                method: 'POST',
                body: formData,
                headers: {
                    // This header is often automatically added by Fetch API with FormData,
                    // but explicitly adding it can help with server-side checks like PHP's $_SERVER['HTTP_X_REQUESTED_WITH']
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const data = await response.json(); // Parse the JSON response

            if (data.success) {
                showMessageBox(data.message, true); // Show success message
                orderCardForm.reset(); // Clear the form fields
                fetchUserCards(); // Refresh the card list to show the new card
                fetchUserAccounts(); // Re-fetch accounts to update balances if card ordering affects them
            } else {
                showMessageBox(data.message, false); // Show error message from server
            }
        } catch (error) {
            console.error('Error ordering card:', error);
            // Check if the error is due to a non-JSON response from the server (e.g., 404 HTML)
            if (error.message.includes("Unexpected token '<'")) {
                 showMessageBox("An unexpected error occurred: Server returned an invalid response. This often means the server couldn't process the request correctly. Please contact support.", false);
            } else {
                showMessageBox(`An unexpected error occurred while placing your order: ${error.message}. Please try again.`, false);
            }
        }
    });

    // Handle message box dismissal
    messageBoxButton.addEventListener('click', () => {
        document.getElementById('messageBoxOverlay').style.display = 'none';
    });
});