// Path: C:\xampp\htdocs\hometownbank\frontend\cards.js

document.addEventListener('DOMContentLoaded', () => {
    // Message Box Elements - Define them ONCE within DOMContentLoaded
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper');
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // The <p> tag itself
    const messageBoxButton = document.getElementById('messageBoxButton');

    // --- Function to show custom message box ---
    function showMessageBox(message, type = 'info') {
        // Ensure elements exist before trying to manipulate them
        if (!messageBoxContentParagraph || !messageBoxContentWrapper || !messageBoxOverlay) {
            console.error("MessageBox elements not found in DOM!");
            // Fallback to console log if elements are missing
            console.log(`MessageBox: Type: ${type}, Message: ${message}`);
            return;
        }

        messageBoxContentParagraph.textContent = message;
        // Clear previous classes ensuring only one type class is active
        messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'info');

        // Apply type-specific classes for styling
        if (type === 'success') {
            messageBoxContentWrapper.classList.add('message-box-success');
        } else if (type === 'error') {
            messageBoxContentWrapper.classList.add('message-box-error');
        } else {
            messageBoxContentWrapper.classList.add('info'); // Default info style
        }

        messageBoxOverlay.classList.add('show'); // Show the overlay
    }

    // Check if PHP_BASE_URL and FRONTEND_BASE_URL are defined by PHP
    if (typeof PHP_BASE_URL === 'undefined' || typeof FRONTEND_BASE_URL === 'undefined') {
        console.error("Critical JavaScript variables (PHP_BASE_URL, FRONTEND_BASE_URL) are not defined. Check PHP include.");
        showMessageBox("Application error: Path configuration missing. Please contact support.", 'error'); // Use 'error' type
        return; // Stop execution if critical variables are missing
    }

    const userCardList = document.getElementById('userCardList');
    const cardsLoadingMessage = document.getElementById('cardsLoadingMessage');
    const noCardsMessage = document.getElementById('noCardsMessage');
    const orderCardForm = document.getElementById('orderCardForm');
    const accountIdSelect = document.getElementById('accountId');
    const orderCardSubmitButton = orderCardForm ? orderCardForm.querySelector('button[type="submit"]') : null; // Get the submit button, safely

    // Handle message box dismissal
    if (messageBoxButton) {
        messageBoxButton.addEventListener('click', () => {
            messageBoxOverlay.classList.remove('show'); // Hide the overlay
            messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'info'); // Clear classes
            messageBoxContentParagraph.textContent = ''; // Clear the message text
        });
    }

    // Function to fetch and populate user's bank accounts for the order form
    async function fetchUserAccounts() {
        if (!accountIdSelect) {
            console.error("Account ID select element not found.");
            return;
        }
        accountIdSelect.innerHTML = '<option value="">-- Loading Accounts --</option>';
        try {
            // CORRECTED: Added the missing '/' after PHP_BASE_URL and the '.php' extension
            const response = await fetch(`${PHP_BASE_URL}/api/get_user_accounts.php`);

            if (!response.ok) {
                const errorText = await response.text();
                // Check for HTML response, indicating a server-side error
                if (errorText.trim().startsWith('<')) {
                    throw new Error(`Server error or API endpoint not found. Raw response: ${errorText.substring(0, 200)}...`);
                }
                throw new Error(`HTTP error! Status: ${response.status}, Response: ${errorText}`);
            }

            const data = await response.json();

            // Corrected: Check data.status for consistency, as per your PHP API responses
            if (data.status === 'success' && data.accounts && data.accounts.length > 0) {
                accountIdSelect.innerHTML = '<option value="">Select an Account</option>';
                data.accounts.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.id;
                    const currency = account.currency ? `${account.currency} ` : '';
                    option.textContent = `${account.account_type} (${account.display_account_number}) - ${currency}${parseFloat(account.balance).toFixed(2)}`;
                    accountIdSelect.appendChild(option);
                });
            } else {
                accountIdSelect.innerHTML = '<option value="" disabled>No Accounts Found</option>'; // Use disabled
                console.warn("No accounts found for the user or error fetching accounts:", data.message || "Unknown error");
                showMessageBox(data.message || "No bank accounts found for your profile. Please add an account before ordering a card.", 'info'); // Use 'info' or 'warning' type
            }
        } catch (error) {
            accountIdSelect.innerHTML = '<option value="" disabled>Error Loading Accounts</option>'; // Use disabled
            console.error("Error fetching user accounts:", error);
            // More specific error messages
            if (error.message.includes("Failed to fetch")) {
                showMessageBox("Failed to load accounts: Could not connect to the server. Please check your network or server status.", 'error');
            } else if (error.message.includes("Server error or API endpoint not found")) {
                showMessageBox(`Failed to load accounts: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) { // Broaden JSON parsing error check
                showMessageBox("Failed to load accounts: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                showMessageBox(`Failed to load accounts: ${error.message}. Please try refreshing the page.`, 'error');
            }
        }
    }

    // Function to render a single card HTML
    function renderCard(card) {
        const cardNetworkLogo = card.card_network ?
            `<img src="${PHP_BASE_URL}/images/${card.card_network.toLowerCase()}_logo.png" alt="${card.card_network} Logo" class="card-network-logo" onerror="this.style.display='none'">` :
            '';

        return `
            <a href="${FRONTEND_BASE_URL}/manage_card.php?card_id=${card.id}" class="bank-card-display">
                ${cardNetworkLogo}
                <div class="card-chip"></div>
                <div class="card-number">${card.card_number_display.replace(/(.{4})/g, '$1 ').trim()}</div>
                <div class="card-details-bottom">
                    <div class="card-details-group">
                        <div class="card-details-label">Card Holder</div>
                        <div class="card-holder-name">${card.card_holder_name || currentUserFullName}</div>
                    </div>
                    <div class="card-details-group right">
                        <div class="card-details-label">Expires</div>
                        <div class="card-expiry">${card.expiry_date_display}</div>
                    </div>
                </div>
                <span class="card-status ${card.status_display_class}">${card.status_display_text}</span>
            </a>
        `;
    }

    // Function to fetch and display user's bank cards
    async function fetchUserCards() {
        if (!userCardList) {
            console.error("User card list container not found.");
            return;
        }
        cardsLoadingMessage.style.display = 'block'; // Show loading message
        userCardList.innerHTML = ''; // Clear previous cards
        noCardsMessage.style.display = 'none'; // Hide "no cards" message initially

        try {
            // CORRECTED: Added the missing '/' after PHP_BASE_URL and the '.php' extension
            const response = await fetch(`${PHP_BASE_URL}/api/get_user_cards.php`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                const errorText = await response.text();
                if (errorText.trim().startsWith('<')) {
                    throw new Error(`Server error or API endpoint not found. Raw response: ${errorText.substring(0, 200)}...`);
                }
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const result = await response.json();

            if (result.status === 'success' && result.cards && result.cards.length > 0) {
                result.cards.forEach(card => {
                    userCardList.innerHTML += renderCard(card);
                });
            } else {
                noCardsMessage.style.display = 'block'; // Show "no cards" message
                noCardsMessage.textContent = result.message || "No bank cards found. Order a new one below!"; // Display message from API or default
            }
        } catch (error) {
            console.error('Error fetching cards:', error);
            noCardsMessage.textContent = `Error loading cards: ${error.message}. Please try again.`;
            noCardsMessage.style.display = 'block';
            if (error.message.includes("Failed to fetch")) {
                showMessageBox("Failed to load cards: Could not connect to the server. Please check your network or server status.", 'error');
            } else if (error.message.includes("Server error or API endpoint not found")) {
                showMessageBox(`Failed to load cards: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) { // Broaden JSON parsing error check
                showMessageBox("Failed to load cards: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                showMessageBox(`Failed to load cards: ${error.message}`, 'error');
            }
        } finally {
            cardsLoadingMessage.style.display = 'none'; // Hide loading message
        }
    }

    // Call functions on page load
    fetchUserAccounts(); // Populate the account dropdown
    fetchUserCards();    // Display existing cards

    // Handle order card form submission
    if (orderCardForm) { // Ensure form exists before adding listener
        orderCardForm.addEventListener('submit', async (e) => {
            e.preventDefault(); // Prevent default form submission

            if (orderCardSubmitButton) {
                orderCardSubmitButton.disabled = true;
                orderCardSubmitButton.textContent = 'Ordering...'; // Or add a spinner icon
            }

            const formData = new FormData(orderCardForm);

            try {
                // CORRECTED: Added the missing '/' after PHP_BASE_URL and the '.php' extension
                const response = await fetch(`${PHP_BASE_URL}/api/order_card.php`, {
                    method: 'POST',
                    body: formData,
                    // No 'Content-Type': 'application/json' header for FormData,
                    // browser sets it automatically with boundary.
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    if (errorText.trim().startsWith('<')) {
                        throw new Error(`Server error or API endpoint not found. Raw response: ${errorText.substring(0, 200)}...`);
                    }
                    throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
                }

                const data = await response.json();

                if (data.status === 'success') {
                    showMessageBox(data.message, 'success'); // Show success message
                    orderCardForm.reset(); // Clear the form fields
                    // Re-set readonly card holder name after reset
                    document.getElementById('cardHolderName').value = currentUserFullName;
                    fetchUserCards(); // Refresh the card list to show the new card
                    // fetchUserAccounts(); // Re-fetch accounts only if card ordering affects account balances, usually not needed.
                } else {
                    showMessageBox(data.message, 'error'); // Show error message from server
                }
            } catch (error) {
                console.error('Error ordering card:', error);
                if (error.message.includes("Failed to fetch")) {
                    showMessageBox("An unexpected error occurred: Could not connect to the server. Please check your network or server status.", 'error');
                } else if (error.message.includes("Server error or API endpoint not found")) {
                    showMessageBox(`An unexpected error occurred: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
                } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) { // Broaden JSON parsing error check
                    showMessageBox("An unexpected error occurred: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
                } else {
                    showMessageBox(`An unexpected error occurred while placing your order: ${error.message}. Please try again.`, 'error');
                }
            } finally {
                if (orderCardSubmitButton) {
                    orderCardSubmitButton.disabled = false;
                    orderCardSubmitButton.textContent = 'Place Card Order'; // Revert text
                }
            }
        });
    }
});