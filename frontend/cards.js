// Path: C:\xampp\htdocs\hometownbank\frontend\cards.js

document.addEventListener('DOMContentLoaded', () => {
    // Message Box Elements (for general page messages, not the card modal)
    const messageBoxOverlay = document.getElementById('messageBoxOverlay');
    const messageBoxContentWrapper = document.getElementById('messageBoxContentWrapper');
    const messageBoxContentParagraph = document.getElementById('messageBoxContent'); // The <p> tag itself
    const messageBoxButton = document.getElementById('messageBoxButton');

    // Card Activation/Info Modal Elements (for admin-set card messages)
    const cardActivationModal = document.getElementById('cardActivationModal');
    const cardActivationModalTitle = document.getElementById('cardActivationModalTitle');
    const cardActivationModalMessage = document.getElementById('cardActivationModalMessage');
    const closeCardActivationModalBtn = document.getElementById('closeCardActivationModalBtn');
    const cardActivationModalOkBtn = document.getElementById('cardActivationModalOkBtn');


    // Card Action Elements
    const actionCardSelect = document.getElementById('actionCardSelect');
    const freezeActionButton = document.getElementById('freezeActionButton');
    const reportActionButton = document.getElementById('reportActionButton');
    const setPinActionButton = document.getElementById('setPinActionButton');

    // Ensure these variables are globally available from bank_cards.php
    // These are already defined in your bank_cards.php <script> block
    // const PHP_BASE_URL;
    // const FRONTEND_BASE_URL;
    // const currentUserId;
    // const currentUserFullName;
    // const currentUserEmail;
    // const initialMessage; // General page message
    // const initialMessageType; // Type for general page message
    // const initialCardModalMessage; // Specific message for card modal
    // const initialShowCardModal; // Boolean to show card modal
    // const initialCardModalMessageType; // Type for card modal message


    // --- Function to show general custom message box (already present) ---
    window.showMessageBox = function(message, type = 'info') {
        if (!messageBoxContentParagraph || !messageBoxContentWrapper || !messageBoxOverlay) {
            console.error("MessageBox elements not found in DOM!");
            console.log(`MessageBox (fallback): Type: ${type}, Message: ${message}`);
            return;
        }

        messageBoxContentParagraph.textContent = message;
        messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info', 'message-box-warning');

        if (type === 'success') {
            messageBoxContentWrapper.classList.add('message-box-success');
        } else if (type === 'error') {
            messageBoxContentWrapper.classList.add('message-box-error');
        } else if (type === 'warning') { // Added warning type
            messageBoxContentWrapper.classList.add('message-box-warning');
        } else {
            messageBoxContentWrapper.classList.add('message-box-info');
        }

        messageBoxOverlay.classList.add('show');
    };

    // Function to hide the general message box
    function hideMessageBox() {
        if (messageBoxOverlay) {
            messageBoxOverlay.classList.remove('show');
            messageBoxContentWrapper.classList.remove('message-box-success', 'message-box-error', 'message-box-info', 'message-box-warning');
            messageBoxContentParagraph.textContent = '';
        }
    }

    // Event listener for the general message box OK button
    if (messageBoxButton) {
        messageBoxButton.addEventListener('click', hideMessageBox);
    }
    // Optional: Hide if clicking outside for general message box
    if (messageBoxOverlay) {
        messageBoxOverlay.addEventListener('click', function(event) {
            if (event.target === messageBoxOverlay) {
                hideMessageBox();
            }
        });
    }

    // --- NEW: Functions to manage the dedicated Card Activation/Info Modal ---
    function showCardInfoModal(message, type = 'info', title = 'Important Message') {
        if (!cardActivationModal || !cardActivationModalMessage || !cardActivationModalTitle) {
            console.error("Card info modal elements not found in DOM!");
            return;
        }

        cardActivationModalMessage.textContent = message;
        cardActivationModalTitle.textContent = title;

        // Clear previous classes and add the new one for styling
        cardActivationModal.classList.remove('modal-success', 'modal-error', 'modal-info', 'modal-warning');
        if (type === 'success') {
            cardActivationModal.classList.add('modal-success');
        } else if (type === 'error') {
            cardActivationModal.classList.add('modal-error');
        } else if (type === 'warning') {
            cardActivationModal.classList.add('modal-warning');
        } else {
            cardActivationModal.classList.add('modal-info');
        }

        cardActivationModal.style.display = 'flex'; // Use flex for centering
    }

    async function hideCardInfoModal() {
        if (!cardActivationModal) return;

        cardActivationModal.style.display = 'none';
        cardActivationModalMessage.textContent = '';
        cardActivationModalTitle.textContent = '';
        cardActivationModal.classList.remove('modal-success', 'modal-error', 'modal-info', 'modal-warning');

        // Make an AJAX call to clear the session variables after the modal is dismissed
        try {
            const response = await fetch(`${PHP_BASE_URL}api/clear_card_modal_session.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ action: 'clear_card_modal_message' }) // Action name is descriptive
            });
            const result = await response.json();
            if (result.status !== 'success') {
                console.error('Failed to clear card modal session:', result.message);
            }
        } catch (error) {
            console.error('Error clearing card modal session:', error);
        }

        // --- NEW: Redirect user to the dashboard after closing the modal ---
        window.location.href = `${FRONTEND_BASE_URL}dashboard.php`;
    }

    // Event listeners for the Card Activation/Info Modal
    if (closeCardActivationModalBtn) {
        closeCardActivationModalBtn.addEventListener('click', hideCardInfoModal);
    }
    if (cardActivationModalOkBtn) {
        cardActivationModalOkBtn.addEventListener('click', hideCardInfoModal);
    }
    if (cardActivationModal) {
        cardActivationModal.addEventListener('click', function(event) {
            if (event.target === cardActivationModal) {
                hideCardInfoModal();
            }
        });
    }

    // --- END NEW Card Activation/Info Modal Logic ---


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


    // --- Initial page message display (using the unified message box) ---
    // This logic handles general page messages (e.g., from order processing)
    if (typeof initialMessage !== 'undefined' && initialMessage.trim() !== '') {
        window.showMessageBox(initialMessage, initialMessageType);
    }

    // --- Handle initial display of the specific Card Info Modal from session ---
    // This uses the dedicated card modal elements and new functions
    if (typeof initialShowCardModal !== 'undefined' && initialShowCardModal === true &&
        typeof initialCardModalMessage !== 'undefined' && initialCardModalMessage.trim() !== '') {
        let modalTitle = 'Important Message';
        switch (initialCardModalMessageType) {
            case 'success':
                modalTitle = 'Success!';
                break;
            case 'error':
                modalTitle = 'Error!';
                break;
            case 'warning':
                modalTitle = 'Warning!';
                break;
            case 'info':
            default:
                modalTitle = 'Notification';
                break;
        }
        showCardInfoModal(initialCardModalMessage, initialCardModalMessageType, modalTitle);
        // The session clearing for this modal happens when hideCardInfoModal() is called
    }


    // Function to fetch and populate user's bank accounts for the order form
    async function fetchUserAccounts() {
        if (!accountIdSelect) {
            console.error("Account ID select element not found.");
            return;
        }
        accountIdSelect.innerHTML = '<option value="">-- Loading Accounts --</option>';
        try {
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
                    const displayNum = card.display_card_number || (card.card_number ? card.card_number.slice(-4) : '****');
                    option.textContent = `${card.card_type} (...${displayNum}) - Status: ${card.status_display_text}`;
                    option.dataset.status = card.status;
                    actionCardSelect.appendChild(option);
                });
                actionCardSelect.dispatchEvent(new Event('change'));
            } else {
                actionCardSelect.innerHTML = '<option value="" disabled>No Cards Available</option>';
                if (freezeActionButton) freezeActionButton.disabled = true;
                if (reportActionButton) reportActionButton.disabled = true;
                if (setPinActionButton) setPinActionButton.disabled = true;
            }
        } catch (error) {
            console.error('Error populating action card select:', error);
            actionCardSelect.innerHTML = '<option value="" disabled>Error Loading Cards</option>';
            if (freezeActionButton) freezeActionButton.disabled = true;
            if (reportActionButton) reportActionButton.disabled = true;
            if (setPinActionButton) setPinActionButton.disabled = true;
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
            if (freezeActionButton) freezeActionButton.disabled = true;
            if (reportActionButton) reportActionButton.disabled = true;
            if (setPinActionButton) setPinActionButton.disabled = true;

            // Reset button texts
            if (freezeActionButton) freezeActionButton.textContent = '❄️ Freeze/Unfreeze Card';

            if (cardId) {
                // Set cardId on all relevant buttons
                if (freezeActionButton) freezeActionButton.dataset.cardId = cardId;
                if (reportActionButton) reportActionButton.dataset.cardId = cardId;
                if (setPinActionButton) setPinActionButton.dataset.cardId = cardId;

                // Enable/Disable buttons based on card status
                if (cardStatus === 'active') {
                    if (freezeActionButton) {
                        freezeActionButton.disabled = false;
                        freezeActionButton.textContent = '❄️ Freeze Card';
                        freezeActionButton.dataset.status = 'active';
                    }
                    if (reportActionButton) reportActionButton.disabled = false;
                    if (setPinActionButton) setPinActionButton.disabled = false;
                } else if (cardStatus === 'frozen') {
                    if (freezeActionButton) {
                        freezeActionButton.disabled = false;
                        freezeActionButton.textContent = '🔓 Unfreeze Card';
                        freezeActionButton.dataset.status = 'frozen';
                    }
                    if (reportActionButton) reportActionButton.disabled = false;
                    if (setPinActionButton) setPinActionButton.disabled = false;
                } else if (cardStatus === 'pending_activation') {
                    // if (activateActionButton) activateActionButton.disabled = false;
                    if (reportActionButton) reportActionButton.disabled = false;
                } else if (cardStatus === 'lost' || cardStatus === 'stolen' || cardStatus === 'cancelled') {
                    window.showMessageBox(`This card is ${cardStatus} and cannot be managed further.`, 'info');
                }
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
                window.location.href = `${FRONTEND_BASE_URL}/set_card_pin.php?card_id=${cardId}`;
            } else {
                window.showMessageBox("Please select a card to set its PIN.", 'info');
            }
        });
    }

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
                    window.showMessageBox(data.message, 'success');
                    orderCardForm.reset();
                    if (typeof currentUserFullName !== 'undefined') {
                        document.getElementById('cardHolderName').value = currentUserFullName;
                    }
                    fetchUserCards();
                    populateActionCardSelect();
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
});