document.addEventListener('DOMContentLoaded', function() {
    // Cache frequently used DOM elements
    const sourceAccountSelect = document.getElementById('source_account_id');
    const transferMethodSelect = document.getElementById('transfer_method');
    const recipientNameGroup = document.getElementById('recipient_name_group');
    const recipientNameInput = document.getElementById('recipient_name');

    const internalSelfFields = document.getElementById('internal_self_fields');
    const destinationAccountSelectSelf = document.getElementById('destination_account_id_self');

    const internalHeritageFields = document.getElementById('internal_heritage_fields');
    const recipientAccountNumberInternal = document.getElementById('recipient_account_number_internal');

    const externalIbanFields = document.getElementById('external_iban_fields');
    const recipientIban = document.getElementById('recipient_iban');
    const recipientSwiftBic = document.getElementById('recipient_swift_bic');
    const recipientBankNameIban = document.getElementById('recipient_bank_name_iban');

    const externalSortCodeFields = document.getElementById('external_sort_code_fields');
    const recipientSortCode = document.getElementById('recipient_sort_code');
    const recipientExternalAccountNumber = document.getElementById('recipient_external_account_number');
    const recipientBankNameSort = document.getElementById('recipient_bank_name_sort');

    const displayCurrentBalance = document.getElementById('display_current_balance');
    const currentCurrencyDisplay = document.getElementById('current_currency_display');
    const amountCurrencySymbol = document.getElementById('amount_currency_symbol');
    const amountInput = document.getElementById('amount');

    // Modal elements
    const transferModal = document.getElementById('transferConfirmationModal');
    const closeButton = document.querySelector('.close-button');

    // Global variables passed from PHP (ensure these are correctly outputted in your HTML)
    // Example: <script>const userAccountsData = <?php echo json_encode($user_accounts_data_for_js); ?>;</script>
    // Example: <script>const senderName = "<?php echo htmlspecialchars($sender_name_for_js); ?>";</script>
    // Example: <script>const showModalOnLoad = <?php echo json_encode($show_transfer_modal); ?>;</script>
    // Example: <script>const modalDetails = <?php echo json_encode($modal_transfer_details); ?>;</script>

    /**
     * Updates the displayed source account balance, currency, and amount input placeholder.
     * It also triggers the update for self-transfer destination options.
     */
    function updateAccountBalanceDisplay() {
        const selectedOption = sourceAccountSelect.options[sourceAccountSelect.selectedIndex];

        if (selectedOption && selectedOption.value !== "") {
            const balance = parseFloat(selectedOption.dataset.balance);
            const currencyCode = selectedOption.dataset.currency;
            const accountNumber = selectedOption.dataset.accountNumber;
            let currencySymbol = '€'; // Default for EUR

            // Determine currency symbol
            switch (currencyCode.toUpperCase()) {
                case 'GBP': currencySymbol = '£'; break;
                case 'USD': currencySymbol = '$'; break;
                case 'EUR': currencySymbol = '€'; break;
                case 'NGN': currencySymbol = '₦'; break; // Assuming Naira for Nigeria
                // Add more cases for other currencies if needed
            }

            // Update display elements
            displayCurrentBalance.textContent = `${currencySymbol}${balance.toFixed(2)} (${accountNumber})`;
            currentCurrencyDisplay.textContent = currencyCode;
            amountCurrencySymbol.textContent = currencySymbol;
            amountInput.placeholder = `e.g., 100.00 (${currencyCode})`;

        } else {
            // Default or clear if no account is selected
            displayCurrentBalance.textContent = `N/A`;
            currentCurrencyDisplay.textContent = 'N/A';
            amountCurrencySymbol.textContent = '€'; // Revert to default symbol
            amountInput.placeholder = 'e.g., 100.00';
        }

        // Always update destination options when source account changes
        updateSelfTransferDestinationOptions();
    }

    /**
     * Populates the 'To Account' dropdown for 'Between My Accounts' transfers.
     * It filters out the selected source account and ensures currency matching.
     * Also attempts to re-select a previously chosen destination.
     */
    function updateSelfTransferDestinationOptions() {
        const sourceAccountId = sourceAccountSelect.value;
        const selectedSourceCurrency = sourceAccountSelect.options[sourceAccountSelect.selectedIndex]?.dataset.currency;

        // Store the currently selected destination to try and re-select it later
        const currentDestination = destinationAccountSelectSelf.value;

        // Clear existing options except the default
        destinationAccountSelectSelf.innerHTML = '<option value="">-- Select Destination Account --</option>';

        // `userAccountsData` is expected to be defined globally by PHP.
        if (typeof userAccountsData !== 'undefined' && Array.isArray(userAccountsData)) {
            userAccountsData.forEach(account => {
                // Ensure the account is not the source account and has the same currency
                if (account.id != sourceAccountId && selectedSourceCurrency && account.currency === selectedSourceCurrency) {
                    const option = document.createElement('option');
                    option.value = account.id;
                    option.textContent = `${account.account_type.charAt(0).toUpperCase() + account.account_type.slice(1)} - ${account.account_number} (${account.currency} ${parseFloat(account.balance).toFixed(2)})`;
                    destinationAccountSelectSelf.appendChild(option);
                }
            });
        }

        // Attempt to re-select the previously chosen destination, if it's still valid
        if (currentDestination && destinationAccountSelectSelf.querySelector(`option[value="${currentDestination}"]`)) {
            destinationAccountSelectSelf.value = currentDestination;
        } else {
            // If the previously selected destination is now the source, or invalid, clear it
            destinationAccountSelectSelf.value = "";
        }
    }

    /**
     * Shows/hides specific form fields based on the selected transfer method.
     * It also manages the 'required' attribute and pre-fills the recipient name for self-transfers.
     */
    function showHideTransferFields() {
        const method = transferMethodSelect.value;

        // Hide all method-specific fields and clear their required attributes/values
        document.querySelectorAll('.transfer-method-fields').forEach(field => {
            field.classList.add('hidden');
            field.querySelectorAll('input, select, textarea').forEach(input => {
                input.removeAttribute('required');
                // Only clear if the input is not meant to persist values across method changes (e.g., amount, description)
                // For recipient details, clearing makes sense to prevent stale data.
                if (input.type !== 'submit' && input.id !== 'amount' && input.id !== 'description') {
                    input.value = '';
                }
            });
        });

        // Always show recipient name field by default, then modify as needed
        recipientNameGroup.classList.remove('hidden');
        recipientNameInput.removeAttribute('required'); // Start as not required

        // Clear recipient name input by default when method changes, unless specifically handled below
        // This ensures a clean slate, especially if a previous method filled it.
        if (method !== 'internal_self') { // Don't clear if about to be pre-filled by internal_self logic
             recipientNameInput.value = '';
        }

        // Show relevant fields and set required attributes based on the selected method
        switch (method) {
            case 'internal_heritage':
                internalHeritageFields.classList.remove('hidden');
                recipientNameInput.setAttribute('required', 'required');
                recipientAccountNumberInternal.setAttribute('required', 'required');
                break;

            case 'internal_self':
                internalSelfFields.classList.remove('hidden');
                recipientNameGroup.classList.add('hidden'); // Hide recipient name for self-transfer
                // Auto-fill recipient name for self-transfer. `senderName` is expected from PHP.
                recipientNameInput.value = (typeof senderName !== 'undefined' ? senderName : '') + " (Internal Transfer)";
                destinationAccountSelectSelf.setAttribute('required', 'required');
                updateSelfTransferDestinationOptions(); // Ensure options are correct when this method is selected
                break;

            case 'external_iban':
                externalIbanFields.classList.remove('hidden');
                recipientNameInput.setAttribute('required', 'required');
                recipientIban.setAttribute('required', 'required');
                recipientSwiftBic.setAttribute('required', 'required');
                recipientBankNameIban.setAttribute('required', 'required');
                break;

            case 'external_sort_code':
                externalSortCodeFields.classList.remove('hidden');
                recipientNameInput.setAttribute('required', 'required');
                recipientSortCode.setAttribute('required', 'required');
                recipientExternalAccountNumber.setAttribute('required', 'required');
                recipientBankNameSort.setAttribute('required', 'required');
                break;

            default:
                // No specific method selected or an unknown one, keep recipient name not required by default
                break;
        }
    }

    /**
     * Opens the transfer confirmation modal and populates it with transfer details.
     * @param {object} details - An object containing transfer information.
     */
    function openModal(details) {
        document.getElementById('modalAmount').textContent = (details.currency || '') + ' ' + (details.amount || 'N/A');
        document.getElementById('modalRecipient').textContent = details.recipient_name || 'N/A';
        document.getElementById('modalMethod').textContent = details.method || 'N/A';
        document.getElementById('modalStatus').textContent = details.status || 'N/A';
        document.getElementById('modalReference').textContent = details.reference || 'N/A';
        transferModal.style.display = 'block';
        
    }

    /**
     * Closes the transfer confirmation modal.
     */
    function closeModal() {
        transferModal.style.display = 'none';
        // You might want to redirect the user or reset the form after closing the modal,
        // depending on your UX flow. For now, we'll just close it.
        // window.location.href = 'transfer.php'; // Example: refresh the page
    }

    // --- Event Listeners ---
    sourceAccountSelect.addEventListener('change', updateAccountBalanceDisplay);
    transferMethodSelect.addEventListener('change', showHideTransferFields);
    closeButton.onclick = closeModal;

    // Close modal if user clicks outside of it
    window.onclick = function(event) {
        if (event.target == transferModal) {
            closeModal();
        }
    }

    // --- Initial Calls on Page Load ---
    // These ensure the form state is correct when the page first loads,
    // especially if values are pre-filled from a previous POST submission.
    updateAccountBalanceDisplay();
    showHideTransferFields();

    // Check if modal should be shown on page load (from successful transfer)
    // These variables (`showModalOnLoad`, `modalDetails`) must be defined in your
    // `transfer.php` file using PHP's `json_encode` for this to work.
    if (typeof showModalOnLoad !== 'undefined' && showModalOnLoad === true && typeof modalDetails !== 'undefined') {
        openModal(modalDetails);
    }

    console.log("Heritage Bank transfer script initialized and ready!");
});