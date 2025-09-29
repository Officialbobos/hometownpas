// Path: C:\xampp\htdocs\hometownbank\frontend\transfer.js

document.addEventListener('DOMContentLoaded', function() {
    // --- Existing UI Elements ---
    const menuIcon = document.getElementById('menuIcon');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const transferMethodSelect = document.getElementById('transfer_method');
    const allExternalFields = document.querySelectorAll('.external-fields'); 
    const commonExternalFieldsDiv = document.querySelector('.common-external-fields'); 
    const recipientNameInput = document.getElementById('recipient_name'); 

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

    // --- NEW PIN MODAL ELEMENTS ---
    const initiateTransferBtn = document.getElementById('initiateTransferBtn'); // The button that triggers the PIN modal
    const transferForm = document.getElementById('transferForm'); // The main form
    const transferPinModal = document.getElementById('transferPinModal');
    const pinConfirmationForm = document.getElementById('pinConfirmationForm'); // The PIN form inside the modal
    const transferPinInput = document.getElementById('transfer_pin');
    const cancelPinBtn = document.getElementById('cancelPinBtn');
    const pinError = document.getElementById('pinError');
    const transferDataPayload = document.getElementById('transfer_data_payload'); // Hidden field for form data

    // --- NEW CANADIAN ELEMENTS ---
    const canadaPayeeSelect = document.getElementById('recipient_saved_account_id_canada');
    const canadaRecipientFields = [
        document.getElementById('recipient_bank_name_canada'),
        document.getElementById('recipient_institution_number_canada'),
        document.getElementById('recipient_transit_number_canada'),
        document.getElementById('recipient_external_account_number_canada'),
        document.getElementById('recipient_address_canada'),
        document.getElementById('recipient_city_canada')
    ];
    
    // --- Sidebar Toggle Functionality ---
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
    }

    if (menuIcon) menuIcon.addEventListener('click', toggleSidebar);
    if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', toggleSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar); 

    // --- Transfer Method & Field Visibility Logic (Updated for 'external_canada_eft') ---
    
    // Function to hide all optional field sections and their required attributes
    function hideAllExternalFields() {
        allExternalFields.forEach(fieldDiv => {
            fieldDiv.style.display = 'none';
            fieldDiv.querySelectorAll('input, select, textarea').forEach(input => {
                input.removeAttribute('required');
            });
        });

        // Ensure recipient name is hidden and not required if no external method is selected
        if (commonExternalFieldsDiv) {
            commonExternalFieldsDiv.style.display = 'none';
        }
        if (recipientNameInput) {
            recipientNameInput.removeAttribute('required');
            // Do NOT clear recipientNameInput.value here if it was set via form_data restoration.
            // Only clear if it's explicitly cleared by the method logic.
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
            case 'external_canada_eft': // NEW CASE
                fieldsToShow.push('fields_external_canada_eft');
                recipientNameRequired = true;
                break;
            default:
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
        if (commonExternalFieldsDiv && recipientNameInput) { 
            if (recipientNameRequired) {
                commonExternalFieldsDiv.style.display = 'block';
                recipientNameInput.setAttribute('required', 'required');
            } else {
                commonExternalFieldsDiv.style.display = 'none';
                recipientNameInput.removeAttribute('required');
                // recipientNameInput.value = ''; // Don't clear here, rely on form_data restoration
            }
        }
    }
    
    // --- Canadian Saved Payee Logic (NEW) ---
    if (canadaPayeeSelect) {
        canadaPayeeSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            // Function to clear all fields related to Canada recipient details
            const clearCanadaRecipientFields = () => {
                canadaRecipientFields.forEach(field => {
                    if (field) field.value = '';
                });
                if (recipientNameInput) recipientNameInput.value = '';
            };

            if (selectedOption.value) {
                // Auto-fill form fields from data attributes
                if (recipientNameInput) recipientNameInput.value = selectedOption.dataset.recipientName || '';
                
                // Set recipient fields to the saved payee data
                if (canadaRecipientFields[0]) canadaRecipientFields[0].value = selectedOption.dataset.bankName || '';
                if (canadaRecipientFields[1]) canadaRecipientFields[1].value = selectedOption.dataset.institution || '';
                if (canadaRecipientFields[2]) canadaRecipientFields[2].value = selectedOption.dataset.transit || '';
                if (canadaRecipientFields[3]) canadaRecipientFields[3].value = selectedOption.dataset.account || '';
                if (canadaRecipientFields[4]) canadaRecipientFields[4].value = selectedOption.dataset.address || '';
                if (canadaRecipientFields[5]) canadaRecipientFields[5].value = selectedOption.dataset.cityProvincePostal || '';
                
                // Optionally disable manual fields after auto-filling
                canadaRecipientFields.forEach(field => {
                    if (field) field.disabled = true;
                });

            } else {
                // 'Choose a Saved Account' selected: Clear and enable fields
                clearCanadaRecipientFields();
                canadaRecipientFields.forEach(field => {
                    if (field) field.disabled = false;
                });
            }
        });
    }


    // --- Balance and Currency Display Logic ---
    
    // Function to update balance display
    function updateBalanceDisplay() {
        const selectedOption = sourceAccountIdSelect.options[sourceAccountIdSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const balance = selectedOption.getAttribute('data-balance');
            const currency = selectedOption.getAttribute('data-currency');
            const currencySymbol = getCurrencySymbol(currency);

            // Use Math.abs() to ensure no minus sign is displayed.
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
    
    // Helper to get currency symbol
    function getCurrencySymbol(currencyCode) {
        switch (currencyCode ? currencyCode.toUpperCase() : '') {
            case 'USD': return '$';
            case 'EUR': return '€';
            case 'GBP': return '£';
            case 'JPY': return '¥';
            case 'CAD': return 'C$'; // Added CAD
            case 'NGN': return '₦'; 
            default: return currencyCode || '';
        }
    }

    // --- Transfer PIN Modal Logic (NEW) ---
    
    // Step 1: Intercept the "Initiate Transfer" button click
    if (initiateTransferBtn) {
        initiateTransferBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Temporarily ensure required attributes are set for browser validation
            validateFormBeforePinModal(); 

            // Check if the form is valid using the browser's native reportValidity()
            if (!transferForm.checkValidity()) {
                transferForm.reportValidity();
                return; // Stop if form is not valid
            }

            // Clear previous errors
            pinError.style.display = 'none';
            
            // Serialize all form data and store it in the hidden PIN modal field
            const formData = new FormData(transferForm);
            const data = {};
            formData.forEach((value, key) => {
                // Exclude the button's name/value if it exists
                if (key !== 'initiate_transfer_btn') { 
                    data[key] = value;
                }
            });
            
            // Set the JSON payload for the PIN confirmation form
            transferDataPayload.value = JSON.stringify(data);

            // Open the PIN modal
            transferPinModal.classList.add('active');
            transferPinInput.value = ''; // Clear PIN input every time
            transferPinInput.focus();
        });
    }

    // Step 2: Handle the PIN confirmation form submission
    if (pinConfirmationForm) {
        pinConfirmationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const pinValue = transferPinInput.value;
            
            // Client-side PIN format validation (4 digits)
            if (pinValue.length === 4 && /^\d{4}$/.test(pinValue)) {
                
                // IMPORTANT: The form already has the necessary hidden fields, 
                // including the serialized payload and the actual PIN input.
                // We just need to submit the PIN confirmation form now.
                // The `make_transfer.php` endpoint will receive:
                // 1. initiate_transfer=1
                // 2. transfer_data_payload (JSON of all transfer fields)
                // 3. transfer_pin (The 4-digit PIN)
                
                // Re-submit the form
                this.submit(); 
                
            } else {
                pinError.textContent = "Transfer PIN must be a 4-digit number.";
                pinError.style.display = 'block';
                transferPinInput.focus();
            }
        });
    }

    // Step 3: Handle PIN modal cancellation
    if (cancelPinBtn) {
        cancelPinBtn.addEventListener('click', function() {
            transferPinModal.classList.remove('active');
            pinError.style.display = 'none';
        });
    }


    // --- Transfer Success Modal Logic (Existing) ---

    // Helper to get user-friendly display name for transfer method
    function getTransferMethodDisplayName(methodKey) {
        const methodMap = {
            'internal_self': 'Between My Accounts',
            'internal_heritage': 'To Another HomeTown Bank Pa Account',
            'external_iban': 'International Bank Transfer (IBAN/SWIFT)',
            'external_sort_code': 'UK Bank Transfer (Sort Code/Account No)',
            'external_usa_account': 'USA Bank Transfer (Routing/Account No)',
            'external_canada_eft': 'Canadian Bank Transfer (EFT)' // Added Canadian
        };
        return methodMap[methodKey] || methodKey; 
    }

    if (modalCloseButton && transferSuccessModal) { 
        modalCloseButton.addEventListener('click', function() {
            transferSuccessModal.classList.remove('active');
            window.location.href = 'transfer.php'; // Refresh to clear form
        });
    }

    if (transferSuccessModal) {
        transferSuccessModal.addEventListener('click', (e) => {
            if (e.target === transferSuccessModal) {
                transferSuccessModal.classList.remove('active');
                window.location.href = 'transfer.php'; 
            }
        });
    }


    // --- Initial Setup and Event Listeners ---
    
    if (transferMethodSelect) { 
        transferMethodSelect.addEventListener('change', function() {
            showFieldsForMethod(this.value);
            // Clear Canadian fields whenever method changes
            if (canadaPayeeSelect) canadaPayeeSelect.value = '';
            canadaPayeeSelect.dispatchEvent(new Event('change')); 
        });
    }

    if (sourceAccountIdSelect) { 
        sourceAccountIdSelect.addEventListener('change', updateBalanceDisplay);
    }
    
    // A consolidated function to enforce required attributes right before validation/submission
    // This is run by the Initiate Transfer button click handler.
    function validateFormBeforePinModal() {
        const method = transferMethodSelect.value;
        const formElements = transferForm.querySelectorAll('input, select, textarea');

        // 1. Temporarily remove 'required' from ALL fields (except for the core fields which must be required)
        formElements.forEach(input => {
            if (input.id !== 'amount' && input.id !== 'source_account_id' && input.id !== 'transfer_method') {
                 input.removeAttribute('required');
            }
        });
        
        // 2. Re-apply 'required' based on the current selection
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
            case 'external_canada_eft':
                // Require all Canadian fields *unless* a saved payee is selected
                if (!canadaPayeeSelect || !canadaPayeeSelect.value) {
                    fieldsToRequire.push('recipient_bank_name_canada', 'recipient_institution_number_canada', 'recipient_transit_number_canada', 'recipient_external_account_number_canada', 'recipient_address_canada', 'recipient_city_canada');
                }
                shouldRequireRecipientName = true;
                break;
            default:
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
    }


    // Initial setup based on PHP provided data (APP_DATA is injected via PHP)
    if (window.APP_DATA) {
        // Set initial transfer method and show relevant fields
        if (window.APP_DATA.initialTransferMethod && transferMethodSelect) {
            transferMethodSelect.value = window.APP_DATA.initialTransferMethod;
            showFieldsForMethod(window.APP_DATA.initialTransferMethod);
        } else {
            // Default to 'internal_self' if no initial method is set 
            if (transferMethodSelect) {
                transferMethodSelect.value = 'internal_self';
                showFieldsForMethod('internal_self');
            }
        }

        // Set initial source account and update balance display
        if (window.APP_DATA.initialSelectedFromAccount && sourceAccountIdSelect) {
            sourceAccountIdSelect.value = window.APP_DATA.initialSelectedFromAccount;
        }
        
        // This must be called once on load to populate the initial balance/currency correctly.
        updateBalanceDisplay(); 


       // Show existing Transfer Success Modal if flag is set
        if (window.APP_DATA.showModal && transferSuccessModal && Object.keys(window.APP_DATA.modalDetails).length > 0) {
            const details = window.APP_DATA.modalDetails;
            
            // Use Math.abs() to ensure no minus sign is displayed
            const absoluteAmount = Math.abs(parseFloat(details.amount));
            
            modalAmount.textContent = absoluteAmount.toLocaleString(undefined, { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
            
            modalCurrency.textContent = getCurrencySymbol(details.currency); // Use helper for symbol
            modalRecipient.textContent = details.recipient_name;
            modalStatus.textContent = details.status;
            modalReference.textContent = details.reference || 'N/A';
            modalMethod.textContent = getTransferMethodDisplayName(details.method);
            
            transferSuccessModal.classList.add('active');
        }
    }
});