// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.js

document.addEventListener('DOMContentLoaded', function() {
    const menuIcon = document.getElementById('menuIcon');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const transferMethodSelect = document.getElementById('transfer_method');
    const allExternalFields = document.querySelectorAll('.external-fields'); // All sections to hide/show
    const commonExternalFields = document.querySelectorAll('.common-external-fields'); // Recipient Name
    const sourceAccountIdSelect = document.getElementById('source_account_id');
    const displayCurrentBalance = document.getElementById('display_current_balance');
    const amountCurrencySymbolForBalance = document.getElementById('amount_currency_symbol_for_balance');
    const currentCurrencyDisplay = document.getElementById('current_currency_display');
    const amountCurrencySymbol = document.getElementById('amount_currency_symbol');

    // Modal elements
    const transferSuccessModal = document.getElementById('transferSuccessModal');
    const modalCloseButton = document.getElementById('modalCloseButton');
    const modalAmount = document.getElementById('modalAmount');
    const modalCurrency = document.getElementById('modalCurrency');
    const modalRecipient = document.getElementById('modalRecipient');
    const modalStatus = document.getElementById('modalStatus');
    const modalReference = document.getElementById('modalReference');
    const modalMethod = document.getElementById('modalMethod');


    // Sidebar Toggle Functionality
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    }

    menuIcon.addEventListener('click', toggleSidebar);
    closeSidebarBtn.addEventListener('click', toggleSidebar);
    sidebarOverlay.addEventListener('click', toggleSidebar); // Close sidebar when clicking outside


    // Function to hide all optional field sections and their required attributes
    function hideAllExternalFields() {
        allExternalFields.forEach(fieldDiv => {
            fieldDiv.style.display = 'none';
            fieldDiv.querySelectorAll('input, select, textarea').forEach(input => {
                input.removeAttribute('required');
            });
        });
        // Ensure recipient name is hidden if no external method is selected
        commonExternalFields.forEach(fieldDiv => {
            fieldDiv.style.display = 'none';
            fieldDiv.querySelectorAll('input, select, textarea').forEach(input => {
                input.removeAttribute('required');
            });
        });
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
                break;
        }

        fieldsToShow.forEach(id => {
            const div = document.getElementById(id);
            if (div) {
                div.style.display = 'block';
                div.querySelectorAll('input, select, textarea').forEach(input => {
                    input.setAttribute('required', 'required');
                });
            }
        });

        // Handle recipient_name field visibility and required attribute
        const recipientNameInput = document.getElementById('recipient_name');
        const recipientNameGroup = recipientNameInput ? recipientNameInput.closest('.form-group') : null;

        if (recipientNameGroup) {
            if (recipientNameRequired) {
                recipientNameGroup.style.display = 'block';
                recipientNameInput.setAttribute('required', 'required');
            } else {
                recipientNameGroup.style.display = 'none';
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

            displayCurrentBalance.textContent = parseFloat(balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
    transferMethodSelect.addEventListener('change', function() {
        showFieldsForMethod(this.value);
    });

    sourceAccountIdSelect.addEventListener('change', updateBalanceDisplay);


    // Initial setup based on PHP provided data (especially useful after a redirect with errors)
    // APP_DATA is injected via PHP script tag
    if (window.APP_DATA) {
        // Set initial transfer method and show relevant fields
        if (window.APP_DATA.initialTransferMethod) {
            transferMethodSelect.value = window.APP_DATA.initialTransferMethod;
            showFieldsForMethod(window.APP_DATA.initialTransferMethod);
        } else {
            // Default to 'internal_self' if no initial method is set (first load)
            transferMethodSelect.value = 'internal_self';
            showFieldsForMethod('internal_self');
        }

        // Set initial source account and update balance display
        if (window.APP_DATA.initialSelectedFromAccount) {
            sourceAccountIdSelect.value = window.APP_DATA.initialSelectedFromAccount;
        }
        updateBalanceDisplay(); // Call once on load

        // Show modal if flag is set
        if (window.APP_DATA.showModal && Object.keys(window.APP_DATA.modalDetails).length > 0) {
            const details = window.APP_DATA.modalDetails;
            modalAmount.textContent = details.amount;
            modalCurrency.textContent = details.currency;
            modalRecipient.textContent = details.recipient_name;
            modalStatus.textContent = details.status;
            modalReference.textContent = details.reference;
            modalMethod.textContent = details.method;
            transferSuccessModal.classList.add('active');
        }
    }

    modalCloseButton.addEventListener('click', function() {
        transferSuccessModal.classList.remove('active');
        // Optionally redirect or refresh to clear form after modal close
        window.location.href = 'transfer.php';
    });

    // Ensure fields are correctly required/not required on form submission
    // This is a safety net in case JS state is messed up, though HTML required attr is better
    document.getElementById('transferForm').addEventListener('submit', function(event) {
        const method = transferMethodSelect.value;
        const recipientNameInput = document.getElementById('recipient_name');
        
        // Temporarily disable 'required' for all external fields
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

        // Let the browser's native validation take over
        // If you had custom validation, you'd perform it here
    });
});