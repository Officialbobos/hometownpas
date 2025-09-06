// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.js

document.addEventListener('DOMContentLoaded', function() {
    const menuIcon = document.getElementById('menuIcon');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const transferMethodSelect = document.getElementById('transfer_method');
    const allExternalFields = document.querySelectorAll('.external-fields'); // All sections to hide/show
    // Refinement 1: Assuming commonExternalFields refers to the *parent* div of recipient_name
    const commonExternalFieldsDiv = document.querySelector('.common-external-fields'); // Recipient Name's parent div
    const recipientNameInput = document.getElementById('recipient_name'); // Get the input directly

    const sourceAccountIdSelect = document.getElementById('source_account_id');
    const displayCurrentBalance = document.getElementById('display_current_balance');
    const amountCurrencySymbolForBalance = document.getElementById('amount_currency_symbol_for_balance');
    const currentCurrencyDisplay = document.getElementById('current_currency_display');
    const amountCurrencySymbol = document.getElementById('amount_currency_symbol');

    // Existing Transfer Success Modal elements
    const transferSuccessModal = document.getElementById('transferSuccessModal');
    const modalCloseButton = document.getElementById('modalCloseButton');
    const modalAmount = document.getElementById('modalAmount');
    const modalCurrency = document.getElementById('modalCurrency');
    const modalRecipient = document.getElementById('modalRecipient');
    const modalStatus = document.getElementById('modalStatus');
    const modalReference = document.getElementById('modalReference');
    const modalMethod = document.getElementById('modalMethod');

    // NEW: Custom Transfer Modal (from admin message) elements
    const transferCustomModal = document.getElementById('transferCustomModal'); // Corrected ID

    // Sidebar Toggle Functionality
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    }

    if (menuIcon) menuIcon.addEventListener('click', toggleSidebar);
    if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', toggleSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar); // Close sidebar when clicking outside


    // Function to hide all optional field sections and their required attributes
    function hideAllExternalFields() {
        allExternalFields.forEach(fieldDiv => {
            fieldDiv.style.display = 'none';
            fieldDiv.querySelectorAll('input, select, textarea').forEach(input => {
                input.removeAttribute('required');
                // Consider clearing values when hidden, but be careful with form_data restoration
                // input.value = ''; // Uncomment if you want to clear values on hide
            });
        });

        // Ensure recipient name is hidden and not required if no external method is selected
        if (commonExternalFieldsDiv) {
            commonExternalFieldsDiv.style.display = 'none';
        }
        if (recipientNameInput) {
            recipientNameInput.removeAttribute('required');
            recipientNameInput.value = ''; // Clear value when hidden
        }
    }

    // Function to show specific fields and set required attributes
    function showFieldsForMethod(method) {
        hideAllExternalFields(); // Start by hiding everything

        let fieldsToShow = [];
        let recipientNameRequired = false;

        switch (method) {
            case 'internal_self':
                fieldsToShow.push('fields_internal_self');
                // recipient_name is not needed for internal self
                break;
            case 'internal_heritage':
                fieldsToShow.push('fields_internal_heritage');
                recipientNameRequired = true;
                break;
            case 'external_iban':
                fieldsToShow.push('fields_external_iban');
                recipientNameRequired = true;
                break;
            case 'external_sort_code':
                fieldsToShow.push('fields_external_sort_code');
                recipientNameRequired = true;
                break;
            case 'external_usa_account':
                fieldsToShow.push('fields_external_usa_account');
                recipientNameRequired = true;
                break;
            default:
                // No specific fields for default (e.g., if "Choose Transfer Type" is selected)
                // This will effectively hide all specific fields and recipient name
                break;
        }

        fieldsToShow.forEach(id => {
            const div = document.getElementById(id);
            if (div) {
                div.style.display = 'block';
                div.querySelectorAll('input, select, textarea').forEach(input => {
                    // Only make required if not description or recipient name (handled separately)
                    if (input.name && input.name !== 'description' && input.id !== 'recipient_name') {
                        input.setAttribute('required', 'required');
                    }
                });
            }
        });

        // Handle recipient_name field visibility and required attribute
        if (commonExternalFieldsDiv && recipientNameInput) { // Ensure elements exist
            if (recipientNameRequired) {
                commonExternalFieldsDiv.style.display = 'block';
                recipientNameInput.setAttribute('required', 'required');
            } else {
                commonExternalFieldsDiv.style.display = 'none';
                recipientNameInput.removeAttribute('required');
                recipientNameInput.value = ''; // Clear value if not relevant
            }
        }
    }


    // Function to update balance display
    function updateBalanceDisplay() {
        const selectedOption = sourceAccountIdSelect.options[sourceAccountIdSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const balance = selectedOption.getAttribute('data-balance');
            const currency = selectedOption.getAttribute('data-currency');
            const currencySymbol = getCurrencySymbol(currency);

            // This line correctly uses Math.abs() to ensure no minus sign is displayed.
            displayCurrentBalance.textContent = parseFloat(Math.abs(balance)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            amountCurrencySymbolForBalance.textContent = currencySymbol;
            currentCurrencyDisplay.textContent = currency;
            amountCurrencySymbol.textContent = currencySymbol; // Also update currency symbol next to amount input
        } else {
            displayCurrentBalance.textContent = 'N/A';
            amountCurrencySymbolForBalance.textContent = '';
            currentCurrencyDisplay.textContent = '';
            amountCurrencySymbol.textContent = '';
        }
    }
    // Helper to get currency symbol (can be expanded)
    function getCurrencySymbol(currencyCode) {
        switch (currencyCode.toUpperCase()) {
            case 'USD': return '$';
            case 'EUR': return '€';
            case 'GBP': return '£';
            case 'JPY': return '¥';
            case 'NGN': return '₦'; // Example for Naira
            default: return currencyCode; // Fallback to code if symbol not found
        }
    }

    // Event Listeners
    if (transferMethodSelect) { // Check if element exists before adding listener
        transferMethodSelect.addEventListener('change', function() {
            showFieldsForMethod(this.value);
        });
    }

    if (sourceAccountIdSelect) { // Check if element exists before adding listener
        sourceAccountIdSelect.addEventListener('change', updateBalanceDisplay);
    }


    // Initial setup based on PHP provided data (especially useful after a redirect with errors)
    // APP_DATA is injected via PHP script tag
    if (window.APP_DATA) {
        // Set initial transfer method and show relevant fields
        if (window.APP_DATA.initialTransferMethod && transferMethodSelect) {
            transferMethodSelect.value = window.APP_DATA.initialTransferMethod;
            showFieldsForMethod(window.APP_DATA.initialTransferMethod);
        } else {
            // Default to 'internal_self' if no initial method is set (first load or invalid type)
            // Ensure the select element exists before setting value
            if (transferMethodSelect) {
                transferMethodSelect.value = 'internal_self';
                showFieldsForMethod('internal_self');
            }
        }

        // Set initial source account and update balance display
        if (window.APP_DATA.initialSelectedFromAccount && sourceAccountIdSelect) {
            sourceAccountIdSelect.value = window.APP_DATA.initialSelectedFromAccount;
        }
        
        // **CORRECTED LOGIC:**
        // The previous line to call `updateBalanceDisplay()` here was fine, but a slightly
        // better approach is to ensure the select element has the correct value
        // from PHP first, and *then* update the display.
        // This is the ideal place to ensure the initial balance is correct.
        updateBalanceDisplay(); // Call once on load to populate the balance correctly.


       // Show existing Transfer Success Modal if flag is set
if (window.APP_DATA.showModal && transferSuccessModal && Object.keys(window.APP_DATA.modalDetails).length > 0) {
    const details = window.APP_DATA.modalDetails;
    
    // Ensure no sign is displayed by using Math.abs() on the amount
    const absoluteAmount = Math.abs(parseFloat(details.amount));
    
    // Format the amount
    modalAmount.textContent = absoluteAmount.toLocaleString(undefined, { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    });
    
    modalCurrency.textContent = details.currency;
    modalRecipient.textContent = details.recipient_name;
    modalStatus.textContent = details.status;
    
    // Refinement 2: Check for details.reference before setting textContent
    modalReference.textContent = details.reference || 'N/A'; // Provide a fallback
    
    // Refinement 2: Map method to a more user-friendly name for the modal
    modalMethod.textContent = getTransferMethodDisplayName(details.method);
    
    transferSuccessModal.classList.add('active');
}

        // CUSTOM TRANSFER MODAL LOGIC (from admin)
        // This logic is already correctly placed in transfer.php's inline script.
        // The `transferCustomModal`'s display is handled directly by the PHP's
        // embedded JS which runs *before* this script. This ensures the modal
        // takes precedence and the form is hidden if the admin message is active.
        // No further JS needed here to explicitly 'show' this modal.
    }

    // Helper to get user-friendly display name for transfer method
    function getTransferMethodDisplayName(methodKey) {
        const methodMap = {
            'internal_self': 'Between My Accounts',
            'internal_heritage': 'To Another HomeTown Bank Pa Account',
            'external_iban': 'International Bank Transfer (IBAN/SWIFT)',
            'external_sort_code': 'UK Bank Transfer (Sort Code/Account No)',
            'external_usa_account': 'USA Bank Transfer (Routing/Account No)'
        };
        return methodMap[methodKey] || methodKey; // Return mapped name or original key if not found
    }


    if (modalCloseButton && transferSuccessModal) { // Check both exist
        modalCloseButton.addEventListener('click', function() {
            transferSuccessModal.classList.remove('active');
            // Optionally redirect or refresh to clear form after modal close
            // This ensures a clean state for the next transfer.
            window.location.href = 'transfer.php';
        });
    }

    // Close modal if overlay is clicked
    if (transferSuccessModal) {
        transferSuccessModal.addEventListener('click', (e) => {
            if (e.target === transferSuccessModal) {
                transferSuccessModal.classList.remove('active');
                window.location.href = 'transfer.php'; // Also refresh page on overlay click
            }
        });
    }


    // Ensure fields are correctly required/not required on form submission
    // This is a safety net in case JS state is messed up, though HTML required attr is better
    const transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function(event) {
            const method = transferMethodSelect.value;

            // Temporarily disable 'required' for all potentially required inputs outside of current method
            allExternalFields.forEach(fieldDiv => {
                fieldDiv.querySelectorAll('input, select, textarea').forEach(input => {
                    input.removeAttribute('required');
                });
            });
            if (recipientNameInput) recipientNameInput.removeAttribute('required');

            // Re-apply 'required' based on the current selection right before submission
            let fieldsToRequire = [];
            let shouldRequireRecipientName = false;

            switch (method) {
                case 'internal_self':
                    fieldsToRequire.push('destination_account_id_self');
                    break;
                case 'internal_heritage':
                    fieldsToRequire.push('recipient_account_number_internal');
                    shouldRequireRecipientName = true;
                    break;
                case 'external_iban':
                    fieldsToRequire.push('recipient_bank_name_iban', 'recipient_iban', 'recipient_swift_bic', 'recipient_country');
                    shouldRequireRecipientName = true;
                    break;
                case 'external_sort_code':
                    fieldsToRequire.push('recipient_bank_name_sort', 'recipient_sort_code', 'recipient_external_account_number');
                    shouldRequireRecipientName = true;
                    break;
                case 'external_usa_account':
                    fieldsToRequire.push('recipient_bank_name_usa', 'recipient_usa_routing_number', 'recipient_usa_account_number', 'recipient_account_type_usa', 'recipient_address_usa', 'recipient_city_usa', 'recipient_state_usa', 'recipient_zip_usa');
                    shouldRequireRecipientName = true;
                    break;
                default:
                    // If no method selected, ensure the 'amount' and 'source_account_id' are still required
                    // (They should already have 'required' in HTML, but this ensures it)
                    // The form itself should handle this if transferMethodSelect is required
                    break;
            }

            fieldsToRequire.forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.setAttribute('required', 'required');
                }
            });

            if (shouldRequireRecipientName && recipientNameInput) {
                recipientNameInput.setAttribute('required', 'required');
            }

            // The browser's native validation will now kick in based on the applied 'required' attributes.
            // If you have custom validation logic, it would typically run here before event.preventDefault()
            // and then preventDefault if validation fails.
        });
    }
});