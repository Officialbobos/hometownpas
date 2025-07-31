// Path: C:\xampp\htdocs\hometownbank\frontend\cards.js

document.addEventListener('DOMContentLoaded', () => {
    // Message Box Elements - Define them ONCE within DOMContentLoaded
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper');
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // The <p> tag itself
    const messageBoxButton = document.getElementById('messageBoxButton');

    // Card Action Elements
    const actionCardSelect = document.getElementById('actionCardSelect');
    const freezeActionButton = document.getElementById('freezeActionButton');
    const reportActionButton = document.getElementById('reportActionButton');
    const setPinActionButton = document.getElementById('setPinActionButton');
    // const activateActionButton = document.getElementById('activateActionButton'); // This element is not in your HTML, consider removing if not used or adding to HTML

    // --- Function to show custom message box (your existing function, made global to be accessible from `initialMessage` logic) ---
    window.showMessageBox = function(message, type = 'info') {
        // Ensure elements exist before trying to manipulate them
        if (!messageBoxContentParagraph || !messageBoxContentWrapper || !messageBoxOverlay) {
            console.error("MessageBox elements not found in DOM!");
            // Fallback to console log if elements are missing
            console.log(`MessageBox (fallback): Type: ${type}, Message: ${message}`);
            return;
        }

        messageBoxContentParagraph.textContent = message;
        // Clear previous classes ensuring only one type class is active
        messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info');

        // Apply type-specific classes for styling
        if (type === 'success') {
            messageBoxContentWrapper.classList.add('message-box-success');
        } else if (type === 'error') {
            messageBoxContentWrapper.classList.add('message-box-error');
        } else {
            messageBoxContentWrapper.classList.add('message-box-info');
        }

        messageBoxOverlay.classList.add('show'); // Show the overlay
    };

    // Check if PHP_BASE_URL and FRONTEND_BASE_URL are defined by PHP
    if (typeof PHP_BASE_URL === 'undefined' || typeof FRONTEND_BASE_URL === 'undefined') {
        console.error("Critical JavaScript variables (PHP_BASE_URL, FRONTEND_BASE_URL) are not defined. Check PHP include.");
        window.showMessageBox("Application error: Path configuration missing. Please contact support.", 'error');
        return; // Stop execution if critical variables are missing
    }

    const userCardList = document.getElementById('userCardList');
    const cardsLoadingMessage = document.getElementById('cardsLoadingMessage');
    const noCardsMessage = document.getElementById('noCardsMessage');
    const orderCardForm = document.getElementById('orderCardForm');
    const accountIdSelect = document.getElementById('accountId');
    const orderCardSubmitButton = orderCardForm ? orderCardForm.querySelector('button[type="submit"]') : null;

    // Handle message box dismissal
    if (messageBoxButton) {
        messageBoxButton.addEventListener('click', () => {
            messageBoxOverlay.classList.remove('show');
            messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info');
            messageBoxContentParagraph.textContent = '';
        });
    }

    // --- Initial page message display (using the unified message box) ---
    // Prioritize the admin message if it's enabled and has content,
    // otherwise display the session message if it exists.
    if (typeof showAdminCardModal !== 'undefined' && showAdminCardModal === true &&
        typeof adminCardModalText !== 'undefined' && adminCardModalText.trim() !== '') {
        window.showMessageBox(adminCardModalText, 'info'); // Admin message is typically informational
    } else if (typeof initialMessage !== 'undefined' && initialMessage.trim() !== '') {
        window.showMessageBox(initialMessage, initialMessageType);
    }

    // Function to fetch and populate user's bank accounts for the order form
    async function fetchUserAccounts() {
        if (!accountIdSelect) {
            console.error("Account ID select element not found.");
            return;
        }
        accountIdSelect.innerHTML = '<option value="">-- Loading Accounts --</option>';
        try {
            // Using PHP_BASE_URL for API calls
            const response = await fetch(`${PHP_BASE_URL}api/get_user_accounts.php`);

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
                accountIdSelect.innerHTML = '<option value="" disabled>No Accounts Found</option>';
                console.warn("No accounts found for the user or error fetching accounts:", data.message || "Unknown error");
                // Consider whether you want a message box here or just a message in the dropdown.
                // showMessageBox(data.message || "No bank accounts found for your profile. Please add an account before ordering a card.", 'info');
            }
        } catch (error) {
            accountIdSelect.innerHTML = '<option value="" disabled>Error Loading Accounts</option>';
            console.error("Error fetching user accounts:", error);
            if (error.message.includes("Failed to fetch")) {
                window.showMessageBox("Failed to load accounts: Could not connect to the server. Please check your network or server status.", 'error');
            } else if (error.message.includes("Server error or API endpoint not found")) {
                window.showMessageBox(`Failed to load accounts: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
                window.showMessageBox("Failed to load accounts: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                window.showMessageBox(`Failed to load accounts: ${error.message}. Please try refreshing the page.`, 'error');
            }
        }
    }

    // Function to render a single card HTML
    function renderCard(card) {
        let bankBrandingHtml = '';
        if (card.bank_logo_src && card.bank_logo_src !== '') {
            bankBrandingHtml = `<img src="${card.bank_logo_src}" alt="${card.bank_name || 'Bank'} Logo" class="bank-logo-on-card">`;
        } else if (card.bank_name) {
            bankBrandingHtml = `<h4>${card.bank_name}</h4>`;
        } else {
            bankBrandingHtml = `<h4>Hometown Bank</h4>`;
        }

        let cardNetworkLogoHtml = '';
        if (card.card_network) {
            let networkLogoSrc = '';
            const cardNetworkLower = card.card_network.toLowerCase();

            if (cardNetworkLower === 'visa') {
                networkLogoSrc = 'https://i.imgur.com/6JWzGpy.png';
            } else if (cardNetworkLower === 'verve') {
                networkLogoSrc = 'https://i.imgur.com/un1E3AG.png';
            } else if (cardNetworkLower === 'mastercard' || cardNetworkLower === 'master') {
                networkLogoSrc = 'https://i.imgur.com/hYwvs0x.png';
            } else {
                networkLogoSrc = `${FRONTEND_BASE_URL}/images/default_network_logo.png`; // Use FRONTEND_BASE_URL for static assets
            }

            cardNetworkLogoHtml = `<img src="${networkLogoSrc}" alt="${card.card_network} Logo" class="card-network-logo-img" onerror="this.src='${FRONTEND_BASE_URL}/images/default_network_logo.png'; this.alt='Default Network Logo';">`;
        }

        const networkClass = card.card_network ? card.card_network.toLowerCase() : '';

        // Function to mask the card number, showing all digits but adding spaces
        const formatCardNumber = (cardNumber) => {
            if (!cardNumber || typeof cardNumber !== 'string') {
                return '**** **** **** ****'; // Default masked if invalid
            }
            // Ensure card number is exactly 16 digits or handle various lengths
            // If card.card_number is already masked from backend, just format it with spaces
            return cardNumber.replace(/(.{4})/g, '$1 ').trim();
        };

        // Determine the card number to display
        const displayCardNumber = formatCardNumber(card.card_number);

        return `
            <div class="card-item ${networkClass}" data-card-id="${card.id}">
                ${bankBrandingHtml}
                <div class="chip"></div>
                <div class="card-number">${displayCardNumber}</div>
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
        cardsLoadingMessage.style.display = 'block';
        userCardList.innerHTML = '';
        noCardsMessage.style.display = 'none';

        try {
            // Using PHP_BASE_URL for API calls
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
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = cardHtml;
                    const cardElement = tempDiv.firstElementChild;

                    if (cardElement) {
                        cardElement.addEventListener('click', (event) => {
                            // This click listener is for the card *itself*, leading to a details page.
                            // The action buttons below handle their own events.
                            window.location.href = `${FRONTEND_BASE_URL}/manage_card.php?card_id=${card.id}`;
                        });
                        userCardList.appendChild(cardElement);
                    }
                });
            } else {
                noCardsMessage.style.display = 'block';
                noCardsMessage.textContent = result.message || "No bank cards found. Order a new one below!";
            }
        } catch (error) {
            console.error('Error fetching cards:', error);
            noCardsMessage.textContent = `Error loading cards: ${error.message}. Please try again.`;
            noCardsMessage.style.display = 'block';
            if (error.message.includes("Failed to fetch")) {
                window.showMessageBox("Failed to load cards: Could not connect to the server. Please check your network or server status.", 'error');
            } else if (error.message.includes("Server error or API endpoint not found")) {
                window.showMessageBox(`Failed to load cards: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
            } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
                window.showMessageBox("Failed to load cards: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
            } else {
                window.showMessageBox(`Failed to load cards: ${error.message}`, 'error');
            }
        } finally {
            cardsLoadingMessage.style.display = 'none';
        }
    }

    // --- Function to fetch and populate cards for the action dropdown ---
    async function populateActionCardSelect() {
        if (!actionCardSelect) {
            console.error("Action card select element not found.");
            return;
        }
        actionCardSelect.innerHTML = '<option value="">-- Loading Cards --</option>';
        try {
            // Using PHP_BASE_URL for API calls
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
                    // Ensure 'display_card_number' exists or provide a fallback
                    const displayNum = card.display_card_number || (card.card_number ? card.card_number.slice(-4) : '****');
                    option.textContent = `${card.card_type} (...${displayNum}) - Status: ${card.status_display_text}`;
                    option.dataset.status = card.status;
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
                // if (activateActionButton) activateActionButton.disabled = true; // Uncomment if activateActionButton is used
            }
        } catch (error) {
            console.error('Error populating action card select:', error);
            actionCardSelect.innerHTML = '<option value="" disabled>Error Loading Cards</option>';
            freezeActionButton.disabled = true;
            reportActionButton.disabled = true;
            setPinActionButton.disabled = true;
            // if (activateActionButton) activateActionButton.disabled = true; // Uncomment if activateActionButton is used
            window.showMessageBox(`Failed to load cards for actions: ${error.message}`, 'error');
        }
    }

    // --- Event listener for card selection in the action dropdown ---
    if (actionCardSelect) {
        actionCardSelect.addEventListener('change', () => {
            const selectedOption = actionCardSelect.options[actionCardSelect.selectedIndex];
            const cardId = selectedOption.value;
            const cardStatus = selectedOption.dataset.status;

            // Reset all buttons to disabled first
            freezeActionButton.disabled = true;
            reportActionButton.disabled = true;
            setPinActionButton.disabled = true;
            // if (activateActionButton) activateActionButton.disabled = true; // Uncomment if activateActionButton is used

            // Reset button texts
            freezeActionButton.textContent = '❄️ Freeze/Unfreeze Card';


            if (cardId) {
                // Set cardId on all relevant buttons
                freezeActionButton.dataset.cardId = cardId;
                reportActionButton.dataset.cardId = cardId;
                setPinActionButton.dataset.cardId = cardId;
                // if (activateActionButton) activateActionButton.dataset.cardId = cardId; // Uncomment if activateActionButton is used


                // Enable/Disable buttons based on card status
                if (cardStatus === 'active') {
                    freezeActionButton.disabled = false;
                    freezeActionButton.textContent = '❄️ Freeze Card';
                    freezeActionButton.dataset.status = 'active'; // Update dataset status
                    reportActionButton.disabled = false;
                    setPinActionButton.disabled = false;
                } else if (cardStatus === 'frozen') {
                    freezeActionButton.disabled = false;
                    freezeActionButton.textContent = '🔓 Unfreeze Card';
                    freezeActionButton.dataset.status = 'frozen'; // Update dataset status
                    reportActionButton.disabled = false; // Still allow reporting
                    setPinActionButton.disabled = false; // Still allow PIN setting
                } else if (cardStatus === 'pending_activation') {
                    // if (activateActionButton) activateActionButton.disabled = false; // Uncomment if activateActionButton is used
                    reportActionButton.disabled = false; // Allow reporting even if pending
                    // Other actions (freeze, set pin) might be disabled until activated
                } else if (cardStatus === 'lost' || cardStatus === 'stolen' || cardStatus === 'cancelled') {
                    // All actions should be disabled for lost/stolen/cancelled cards
                    window.showMessageBox(`This card is ${cardStatus} and cannot be managed further.`, 'info');
                }
                // For any other statuses, keep buttons disabled by default (as per initial reset)
            }
        });
    }

    // --- Card Action Functions ---
    async function toggleCardStatus(cardId, currentStatus) {
        if (!cardId) {
            window.showMessageBox("Please select a card first.", 'info');
            return;
        }
        const action = currentStatus === 'frozen' ? 'unfreeze' : 'freeze';
        window.showMessageBox(`Attempting to ${action} card...`, 'info');
        try {
            // Using PHP_BASE_URL for API calls
            const response = await fetch(`${PHP_BASE_URL}api/update_card_status.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_id: cardId, action: action })
            });
            const result = await response.json();
            if (result.status === 'success') {
                window.showMessageBox(result.message, 'success');
                fetchUserCards();
                populateActionCardSelect();
            } else {
                window.showMessageBox(result.message, 'error');
            }
        } catch (error) {
            console.error("Error toggling card status:", error);
            window.showMessageBox(`Failed to toggle card status: ${error.message}`, 'error');
        }
    }

    async function reportLostStolen(cardId) {
        if (!cardId) {
            window.showMessageBox("Please select a card first.", 'info');
            return;
        }
        const confirmReport = confirm("Are you sure you want to report this card as lost/stolen? This action cannot be undone and a new card will be issued.");
        if (!confirmReport) {
            return;
        }
        window.showMessageBox(`Reporting card as lost/stolen...`, 'info');
        try {
            // Using PHP_BASE_URL for API calls
            const response = await fetch(`${PHP_BASE_URL}api/update_card_status.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_id: cardId, action: 'report_lost_stolen' })
            });
            const result = await response.json();
            if (result.status === 'success') {
                window.showMessageBox(result.message, 'success');
                fetchUserCards();
                populateActionCardSelect();
            } else {
                window.showMessageBox(result.message, 'error');
            }
        } catch (error) {
            console.error("Error reporting card:", error);
            window.showMessageBox(`Failed to report card: ${error.message}`, 'error');
        }
    }

    // Function to activate a card (if you decide to keep it as a separate action, or remove if integrated with set_card_pin)
    /*
    async function activateCard(cardId) {
        if (!cardId) {
            window.showMessageBox("Please select a card to activate.", 'info');
            return;
        }
        const confirmActivate = confirm("Are you sure you want to activate this card?");
        if (!confirmActivate) {
            return;
        }
        window.showMessageBox(`Activating card...`, 'info');
        try {
            const response = await fetch(`${PHP_BASE_URL}api/update_card_status.php`, { // Assuming this API can handle activation
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ card_id: cardId, action: 'activate' })
            });
            const result = await response.json();
            if (result.status === 'success') {
                window.showMessageBox(result.message, 'success');
                fetchUserCards(); // Refresh cards display
                populateActionCardSelect(); // Refresh action dropdown
            } else {
                window.showMessageBox(result.message, 'error');
            }
        } catch (error) {
            console.error("Error activating card:", error);
            window.showMessageBox(`Failed to activate card: ${error.message}`, 'error');
        }
    }
    */


    // --- Event listeners for action buttons ---
    if (freezeActionButton) {
        freezeActionButton.addEventListener('click', () => {
            const cardId = freezeActionButton.dataset.cardId;
            const currentStatus = freezeActionButton.dataset.status;
            if (cardId) {
                toggleCardStatus(cardId, currentStatus);
            } else {
                window.showMessageBox("Please select a card to freeze/unfreeze.", 'info');
            }
        });
    }

    if (reportActionButton) {
        reportActionButton.addEventListener('click', () => {
            const cardId = reportActionButton.dataset.cardId;
            if (cardId) {
                reportLostStolen(cardId);
            } else {
                window.showMessageBox("Please select a card to report.", 'info');
            }
        });
    }

    if (setPinActionButton) {
        setPinActionButton.addEventListener('click', () => {
            const cardId = setPinActionButton.dataset.cardId;
            if (cardId) {
                window.location.href = `${FRONTEND_BASE_URL}/set_card_pin.php?card_id=${cardId}`; // Using the more direct page
            } else {
                window.showMessageBox("Please select a card to set its PIN.", 'info');
            }
        });
    }

    /*
    // If you plan to have a separate activate button in HTML, uncomment this.
    // Otherwise, activation usually happens as part of setting the initial PIN.
    if (activateActionButton) {
        activateActionButton.addEventListener('click', () => {
            const cardId = activateActionButton.dataset.cardId;
            if (cardId) {
                activateCard(cardId);
            } else {
                window.showMessageBox("Please select a card to activate.", 'info');
            }
        });
    }
    */

    // Call functions on page load
    fetchUserAccounts();
    fetchUserCards();
    populateActionCardSelect();

    // Handle order card form submission
    if (orderCardForm) {
        orderCardForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (orderCardSubmitButton) {
                orderCardSubmitButton.disabled = true;
                orderCardSubmitButton.textContent = 'Ordering...';
            }

            const formData = new FormData(orderCardForm);

            try {
                // Using PHP_BASE_URL for API calls
                const response = await fetch(`${PHP_BASE_URL}api/order_card.php`, {
                    method: 'POST',
                    body: formData, // FormData typically doesn't need Content-Type header when sent directly
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
                    window.showMessageBox(data.message, 'success');
                    orderCardForm.reset();
                    document.getElementById('cardHolderName').value = currentUserFullName; // Reset card holder name
                    fetchUserCards(); // Refresh the card list display
                    populateActionCardSelect(); // Refresh the action dropdown
                } else {
                    window.showMessageBox(data.message, 'error');
                }
            } catch (error) {
                console.error('Error ordering card:', error);
                if (error.message.includes("Failed to fetch")) {
                    window.showMessageBox("An unexpected error occurred: Could not connect to the server. Please check your network or server status.", 'error');
                } else if (error.message.includes("Server error or API endpoint not found")) {
                    window.showMessageBox(`An unexpected error occurred: Server returned an unexpected response. This usually means the API endpoint is wrong or there's a PHP error on the server. Details: ${error.message.substring(0, 100)}...`, 'error');
                } else if (error.message.includes("Unexpected token") || error.message.includes("JSON")) {
                    window.showMessageBox("An unexpected error occurred: The server response was not valid JSON. This often means a PHP error occurred on the API script. Check server logs.", 'error');
                } else {
                    window.showMessageBox(`An unexpected error occurred while placing your order: ${error.message}. Please try again.`, 'error');
                }
            } finally {
                if (orderCardSubmitButton) {
                    orderCardSubmitButton.disabled = false;
                    orderCardSubmitButton.textContent = 'Place Card Order';
                }
            }
        });
    }

    // --- REMOVED: Separate logic for notificationModal (now handled by showMessageBox) ---
    // The previous `APP_DATA` and `notificationModal` logic is removed
    // to unify message display under `showMessageBox` using `showAdminCardModal` and `adminCardModalText`.
    // If you explicitly need a *different* type of modal for admin messages,
    // you would re-introduce that HTML and JavaScript.
});
