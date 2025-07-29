// Function to show a message box
function showMessageBox(message, isSuccess) {
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    // Targeting the wrapper div for styling and the paragraph for text
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper');
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // This is your <p> tag

    // Set the message text in the paragraph
    messageBoxContentParagraph.textContent = message;

    // Remove any existing status classes from the wrapper
    messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error');

    // Apply status-specific classes to the wrapper (where your background/border styles would be)
    if (isSuccess) {
        messageBoxContentWrapper.classList.add('message-box-success');
    } else {
        messageBoxContentWrapper.classList.add('message-box-error');
    }

    // Show the overlay
    messageBoxOverlay.classList.add('show');
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

    // Message Box Elements (re-referenced to match your HTML)
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxButton = document.getElementById('messageBoxButton');
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper'); // The div containing the <p> and button
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // The <p> tag itself

    const orderCardSubmitButton = orderCardForm.querySelector('button[type="submit"]'); // Get the submit button

    // Function to fetch and populate user's bank accounts for the order form
    async function fetchUserAccounts() {
        accountIdSelect.innerHTML = '<option value="">-- Loading Accounts --</option>';
        try {
            const response = await fetch(`${PHP_BASE_URL}api/get_user_accounts`);

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const data = await response.json();

            if (data.success && data.accounts.length > 0) {
                accountIdSelect.innerHTML = '<option value="">Select an Account</option>';
                data.accounts.forEach(account => {
                    const option = document.createElement('option');
                    option.value = account.id;
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
            if (error.message.includes("Unexpected token '<'")) {
                showMessageBox("Failed to load accounts: Server returned an unexpected response (HTML instead of JSON). This often means the server couldn't find the requested API endpoint or there's a PHP error on the API script. Please contact support.", false);
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
            const response = await fetch(`${PHP_BASE_URL}api/get_user_cards`);

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

                    // Dynamic bank logo. Assuming card.bank_logo_src is provided by API
                    // Also using card.bank_name if available, defaulting to 'HOMETOWN BANK'
                    const bankLogoHtml = card.bank_logo_src ? `<img src="${card.bank_logo_src}" alt="Bank Logo" class="bank-logo-on-card">` : '';
                    const bankName = card.bank_name || 'HOMETOWN BANK';

                    cardItem.innerHTML = `
                        ${bankLogoHtml}
                        <h4>${bankName}</h4>
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
                        <p class="card-cvv-mock">
                            CVV: ${card.display_cvv} 
                        </p>
                        ${card.is_active == 1 ? '<div class="card-status active">Active</div>' : '<div class="card-status inactive">Inactive</div>'}
                        ${card.card_logo_src ? `<img src="${card.card_logo_src}" alt="${card.card_network} Logo" class="card-network-logo-img">` : ''}
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
            if (error.message.includes("Unexpected token '<'")) {
                showMessageBox("Failed to load cards: Server returned an unexpected response (HTML instead of JSON). This often means the server couldn't find the requested API endpoint or there's a PHP error on the API script. Please contact support.", false);
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

        // Disable submit button and show loading state
        orderCardSubmitButton.disabled = true;
        orderCardSubmitButton.textContent = 'Ordering...'; // Or add a spinner icon

        const formData = new FormData(orderCardForm);

        try {
            const response = await fetch(`${PHP_BASE_URL}api/order_card`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}, response: ${errorText}`);
            }

            const data = await response.json();

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
            if (error.message.includes("Unexpected token '<'")) {
                showMessageBox("An unexpected error occurred: Server returned an invalid response (HTML instead of JSON). This often means the server couldn't process the request correctly or there's a PHP error on the API script. Please contact support.", false);
            } else {
                showMessageBox(`An unexpected error occurred while placing your order: ${error.message}. Please try again.`, false);
            }
        } finally {
            // Re-enable submit button and revert text
            orderCardSubmitButton.disabled = false;
            orderCardSubmitButton.textContent = 'Order Card';
        }
    });

    // Handle message box dismissal
    messageBoxButton.addEventListener('click', () => {
        messageBoxOverlay.classList.remove('show'); // Hide the overlay
        // Remove success/error classes from the wrapper when hidden
        messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error');
        // Clear the message text
        messageBoxContentParagraph.textContent = '';
    });
});