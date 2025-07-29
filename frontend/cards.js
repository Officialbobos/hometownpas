// Path: C:\xampp\htdocs\hometownbank\frontend\cards.js

document.addEventListener('DOMContentLoaded', () => {
    // Message Box Elements - Define them ONCE within DOMContentLoaded
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper');
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // The <p> tag itself
    const messageBoxButton = document.getElementById('messageBoxButton');

    // Card Action Elements (NEW)
    const actionCardSelect = document.getElementById('actionCardSelect');
    const freezeActionButton = document.getElementById('freezeActionButton');
    const reportActionButton = document.getElementById('reportActionButton');
    const setPinActionButton = document.getElementById('setPinActionButton');


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
        messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info'); // Corrected to use 'message-box-info'

        // Apply type-specific classes for styling (matching your CSS classes for message-box-overlay)
        if (type === 'success') {
            messageBoxContentWrapper.classList.add('message-box-success');
        } else if (type === 'error') {
            messageBoxContentWrapper.classList.add('message-box-error');
        } else {
            messageBoxContentWrapper.classList.add('message-box-info'); // Use 'message-box-info' for clarity
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
            messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info'); // Clear classes
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
            const response = await fetch(`${PHP_BASE_URL}api/get_user_accounts.php`); // PHP_BASE_URL should already have the trailing slash

            if (!response.ok) {
                const errorText = await response.text();
                if (errorText.trim().startsWith('<')) {
                    throw new Error(`Server error or API endpoint not found. Raw response: ${errorText.substring(0, 200)}...`);
                }
                throw new Error(`HTTP error! Status: ${response.status}, Response: ${errorText}`);
            }

            const data = await response.json();

            if (data.status === true && data.accounts && data.accounts.length > 0) {
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
            if (error.message.includes("Failed to fetch")) {
                showMessageBox("Failed to load accounts: Could not connect to the server. Please check your network or server status.", 'error');
            } else if (error.message.includes("Server error or API endpoint not found")) {
                showMessageBox(`Failed to load accounts: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
                showMessageBox("Failed to load accounts: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                showMessageBox(`Failed to load accounts: ${error.message}. Please try refreshing the page.`, 'error');
            }
        }
    }

   // Function to render a single card HTML
function renderCard(card) {
    let bankBrandingHtml = '';
    if (card.bank_logo_src && card.bank_logo_src !== '') {
        bankBrandingHtml = `<img src="${card.bank_logo_src}" alt="${card.bank_name || 'Bank'} Logo" class="bank-logo-on-card" onerror="this.src='${PHP_BASE_URL}images/default_logo.png'; this.alt='Default Bank Logo';">`;
    } else if (card.bank_name) {
        bankBrandingHtml = `<h4>${card.bank_name}</h4>`;
    }

    let cardNetworkLogoHtml = '';
    if (card.card_network) {
        // Corrected default logo path in onerror
        cardNetworkLogoHtml = `<img src="${PHP_BASE_URL}images/${card.card_network.toLowerCase()}_logo.png" alt="${card.card_network} Logo" class="card-network-logo-img" onerror="this.src='${PHP_BASE_URL}images/default_logo.png'; this.alt='Default Network Logo';">`;
    }

    const networkClass = card.card_network ? card.card_network.toLowerCase() : '';

    return `
        <div class="card-item ${networkClass}" data-card-id="${card.id}">
            ${bankBrandingHtml}
            <div class="chip"></div>
            <div class="card-number">${card.card_number ? card.card_number.replace(/(.{4})/g, '$1 ').trim() : 'N/A'}</div>
            <p class="card-cvv-mock">CVV: ***</p>

            <div class="card-footer">
                <div>
                    <div class="label">Card Holder</div>
                    <div class="value">${card.card_holder_name || currentUserFullName}</div>
                </div>
                <div>
                    <div class="label">Expires</div>
                    <div class="value">${card.expiry_date_display}</div>
                </div>
            </div>

            ${cardNetworkLogoHtml}
            <span class="card-status ${card.status_display_class}">${card.status_display_text}</span>
            </div>
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
            const response = await fetch(`${PHP_BASE_URL}api/get_user_cards.php`, {
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
                    const cardHtml = renderCard(card);
                    const tempDiv = document.createElement('div'); // Create a temporary div
                    tempDiv.innerHTML = cardHtml;
                    const cardElement = tempDiv.firstElementChild; // Get the actual card item

                    if (cardElement) {
                        // Add click listener to the cardElement itself (the div with class="card-item")
                        // This allows clicking anywhere on the card to go to details, unless a button is clicked
                        cardElement.addEventListener('click', (event) => {
                            // Prevent navigation if the click originated from within a button or a specific interactive element
                            if (event.target.closest('.card-actions button')) {
                                // If a button was clicked, handle its specific action (e.g., freezeCard, reportCard)
                                const button = event.target.closest('.card-actions button');
                                const cardId = button.dataset.cardId;
                                if (button.classList.contains('freeze-btn')) {
                                    const currentStatus = button.dataset.status;
                                    toggleCardStatus(cardId, currentStatus); // Call a function to toggle status
                                } else if (button.classList.contains('report-btn')) {
                                    reportLostStolen(cardId); // Call a function to report
                                } else if (button.classList.contains('set-pin-btn')) {
                                    window.location.href = `${FRONTEND_BASE_URL}/set_card_pin.php?card_id=${cardId}`;
                                }
                                event.stopPropagation(); // Stop the event from bubbling up to the cardElement
                                return;
                            }
                            // If it's not a button, navigate to the manage_card page
                            window.location.href = `${FRONTEND_BASE_URL}/manage_card.php?card_id=${card.id}`;
                        });

                        userCardList.appendChild(cardElement); // Append the actual element
                    }
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
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
                showMessageBox("Failed to load cards: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                showMessageBox(`Failed to load cards: ${error.message}`, 'error');
            }
        } finally {
            cardsLoadingMessage.style.display = 'none'; // Hide loading message
        }
    }

    // --- NEW: Function to fetch and populate cards for the action dropdown ---
    async function populateActionCardSelect() {
        if (!actionCardSelect) {
            console.error("Action card select element not found.");
            return;
        }
        actionCardSelect.innerHTML = '<option value="">-- Loading Cards --</option>'; // Initial loading state
        try {
            const response = await fetch(`${PHP_BASE_URL}api/get_user_cards.php`, {
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
                actionCardSelect.innerHTML = '<option value="">-- Select a Card --</option>';
                result.cards.forEach(card => {
                    const option = document.createElement('option');
                    option.value = card.id;
                    option.textContent = `${card.card_type} (${card.display_card_number}) - Status: ${card.status_display_text}`;
                    option.dataset.status = card.status; // Store current status in dataset
                    actionCardSelect.appendChild(option);
                });
                // Enable buttons if cards are available
                actionCardSelect.dispatchEvent(new Event('change')); // Trigger change to set initial button states
            } else {
                actionCardSelect.innerHTML = '<option value="" disabled>No Cards Available</option>';
                // Disable all action buttons if no cards are available
                freezeActionButton.disabled = true;
                reportActionButton.disabled = true;
                setPinActionButton.disabled = true;
            }
        } catch (error) {
            console.error('Error populating action card select:', error);
            actionCardSelect.innerHTML = '<option value="" disabled>Error Loading Cards</option>';
            freezeActionButton.disabled = true;
            reportActionButton.disabled = true;
            setPinActionButton.disabled = true;
            showMessageBox(`Failed to load cards for actions: ${error.message}`, 'error');
        }
    }

    // --- Event listener for card selection in the action dropdown (NEW) ---
    if (actionCardSelect) {
        actionCardSelect.addEventListener('change', () => {
            const selectedOption = actionCardSelect.options[actionCardSelect.selectedIndex];
            const cardId = selectedOption.value;
            const cardStatus = selectedOption.dataset.status; // Get the status from the dataset

            if (cardId) {
                freezeActionButton.disabled = false;
                reportActionButton.disabled = false;
                setPinActionButton.disabled = false;

                // Update freeze button text based on card status
                if (cardStatus === 'active') { // Assuming 'active' means not frozen
                    freezeActionButton.textContent = '❄️ Freeze Card';
                    freezeActionButton.dataset.status = 'active';
                } else if (cardStatus === 'frozen') {
                    freezeActionButton.textContent = '🔓 Unfreeze Card';
                    freezeActionButton.dataset.status = 'frozen';
                } else { // Handle other statuses if necessary
                    freezeActionButton.textContent = 'Freeze/Unfreeze Card';
                    freezeActionButton.disabled = true; // Disable if status is not clearly active/frozen
                }
                freezeActionButton.dataset.cardId = cardId; // Set cardId on the button for easy access
                reportActionButton.dataset.cardId = cardId;
                setPinActionButton.dataset.cardId = cardId;
            } else {
                freezeActionButton.disabled = true;
                reportActionButton.disabled = true;
                setPinActionButton.disabled = true;
                freezeActionButton.textContent = '❄️ Freeze/Unfreeze Card'; // Reset text
            }
        });
    }

    // --- Card Action Functions (Stubs for now) ---
    // You will need to implement the actual AJAX calls for these
    async function toggleCardStatus(cardId, currentStatus) {
        if (!cardId) {
            showMessageBox("Please select a card first.", 'info');
            return;
        }
        const action = currentStatus === 'frozen' ? 'unfreeze' : 'freeze';
        showMessageBox(`Attempting to ${action} card...`, 'info');
        try {
            const response = await fetch(`${PHP_BASE_URL}api/update_card_status.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_id: cardId, action: action })
            });
            const result = await response.json();
            if (result.status === 'success') {
                showMessageBox(result.message, 'success');
                fetchUserCards(); // Refresh cards display
                populateActionCardSelect(); // Refresh action dropdown
            } else {
                showMessageBox(result.message, 'error');
            }
        } catch (error) {
            console.error("Error toggling card status:", error);
            showMessageBox(`Failed to toggle card status: ${error.message}`, 'error');
        }
    }

    async function reportLostStolen(cardId) {
        if (!cardId) {
            showMessageBox("Please select a card first.", 'info');
            return;
        }
        const confirmReport = confirm("Are you sure you want to report this card as lost/stolen? This action cannot be undone and a new card will be issued.");
        if (!confirmReport) {
            return;
        }
        showMessageBox(`Reporting card as lost/stolen...`, 'info');
        try {
            const response = await fetch(`${PHP_BASE_URL}api/update_card_status.php`, { // Assuming you use the same API for this
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_id: cardId, action: 'report_lost_stolen' })
            });
            const result = await response.json();
            if (result.status === 'success') {
                showMessageBox(result.message, 'success');
                fetchUserCards(); // Refresh cards display
                populateActionCardSelect(); // Refresh action dropdown
            } else {
                showMessageBox(result.message, 'error');
            }
        } catch (error) {
            console.error("Error reporting card:", error);
            showMessageBox(`Failed to report card: ${error.message}`, 'error');
        }
    }

    // --- NEW: Event listeners for action buttons ---
    if (freezeActionButton) {
        freezeActionButton.addEventListener('click', () => {
            const cardId = freezeActionButton.dataset.cardId;
            const currentStatus = freezeActionButton.dataset.status;
            if (cardId) {
                toggleCardStatus(cardId, currentStatus);
            } else {
                showMessageBox("Please select a card to freeze/unfreeze.", 'info');
            }
        });
    }

    if (reportActionButton) {
        reportActionButton.addEventListener('click', () => {
            const cardId = reportActionButton.dataset.cardId;
            if (cardId) {
                reportLostStolen(cardId);
            } else {
                showMessageBox("Please select a card to report.", 'info');
            }
        });
    }

    if (setPinActionButton) {
        setPinActionButton.addEventListener('click', () => {
            const cardId = setPinActionButton.dataset.cardId;
            if (cardId) {
                window.location.href = `${FRONTEND_BASE_URL}/my_cards?action=set_pin&card_id=${cardId}`; // Updated URL for consistency with initial redirect
            } else {
                showMessageBox("Please select a card to set its PIN.", 'info');
            }
        });
    }


    // Call functions on page load
    fetchUserAccounts(); // Populate the account dropdown
    fetchUserCards();    // Display existing cards
    populateActionCardSelect(); // NEW: Populate the action card dropdown

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
                const response = await fetch(`${PHP_BASE_URL}api/order_card.php`, {
                    method: 'POST',
                    body: formData,
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
                    populateActionCardSelect(); // NEW: Refresh the action card dropdown
                } else {
                    showMessageBox(data.message, 'error'); // Show error message from server
                }
            } catch (error) {
                console.error('Error ordering card:', error);
                if (error.message.includes("Failed to fetch")) {
                    showMessageBox("An unexpected error occurred: Could not connect to the server. Please check your network or server status.", 'error');
                } else if (error.message.includes("Server error or API endpoint not found")) {
                    showMessageBox(`An unexpected error occurred: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
                } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
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