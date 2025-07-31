// Path: C:\xampp\htdocs\hometownbank\frontend\user.dashboard.js

document.addEventListener('DOMContentLoaded', () => {

    // Ensure BASE_URL_JS is defined (it should be set in dashboard.php before this script)
    if (typeof BASE_URL_JS === 'undefined') {
        console.error("BASE_URL_JS is not defined. Please ensure it's set in dashboard.php.");
        // Fallback or exit if crucial constant is missing
        return;
    }

    // --- Dynamic User Name Display ---
    const greetingElement = document.querySelector('.greeting h1');
    if (greetingElement) {
        // Get user's first name from the data attribute set by PHP
        const userFirstName = greetingElement.dataset.userFirstName || "User"; // Fallback to "User"
        greetingElement.textContent = `Hi, ${userFirstName}`;
    }

    // --- Logic for Account Cards Carousel ---
    const accountCardsWrapper = document.querySelector('.account-cards-wrapper'); // This might be null if not in HTML
    const accountCardsContainer = document.querySelector('.account-cards-container');
    const accountCards = accountCardsContainer ? Array.from(accountCardsContainer.querySelectorAll('.account-card')) : [];
    const accountPagination = document.querySelector('.account-pagination');
    // Select all toggle indicators if they exist
    const accountToggleIndicators = accountCardsContainer ? Array.from(accountCardsContainer.querySelectorAll('.account-toggle-indicator')) : [];

    let currentAccountIndex = 0; // Index of the currently displayed card

    // Helper to get computed styles for card dimensions including margin/gap
    function getCardDimensions() {
        if (accountCards.length === 0) return { cardWidth: 0, gap: 0, totalCardWidth: 0 };

        const cardElement = accountCards[0];
        const cardStyle = getComputedStyle(cardElement);
        const cardWidth = cardElement.offsetWidth; // Includes padding and border

        let gap = 0;
        // Try to get gap from the container (CSS gap/column-gap property)
        if (accountCardsContainer) {
            const containerStyle = getComputedStyle(accountCardsContainer);
            gap = parseFloat(containerStyle.columnGap) || parseFloat(containerStyle.gap) || 0;
        }

        // Fallback to margin-right if container gap is not set (legacy/different CSS approach)
        if (gap === 0) {
            gap = parseFloat(cardStyle.marginRight) || 0;
        }

        const totalCardWidth = cardWidth + gap;
        return { cardWidth, gap, totalCardWidth };
    }

    /**
     * Shows a specific account card based on its index.
     * @param {number} index The index of the card to show.
     */
    function showAccountCard(index) {
        if (accountCards.length === 0 || !accountCardsContainer) {
            // console.warn("No account cards or container found for carousel. Skipping carousel logic.");
            return;
        }

        // Ensure index is within bounds (looping behavior for carousel)
        currentAccountIndex = (index % accountCards.length + accountCards.length) % accountCards.length;

        const { totalCardWidth } = getCardDimensions();
        const offset = currentAccountIndex * totalCardWidth;

        // Apply the transform to scroll the container
        accountCardsContainer.style.transform = `translateX(-${offset}px)`;
        // Re-enable transition after potential `transition: none` from drag/resize
        accountCardsContainer.style.transition = 'transform 0.5s ease-in-out';

        // Update pagination dots
        if (accountPagination) {
            const dots = accountPagination.querySelectorAll('.dot');
            dots.forEach((dot, dotIndex) => {
                if (dotIndex === currentAccountIndex) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            });
        }
    }

    /**
     * Shows a specific account card based on its account type.
     * @param {string} type The data-account-type of the card to show (e.g., 'checking', 'savings').
     */
    function showAccountCardByType(type) {
        const targetCard = accountCards.find(card => card.dataset.accountType === type);
        if (targetCard) {
            const index = accountCards.indexOf(targetCard);
            showAccountCard(index);
        } else {
            console.warn(`Account card of type '${type}' not found.`);
        }
    }

    function createPaginationDots() {
        if (!accountPagination) return;

        // Clear existing dots
        accountPagination.innerHTML = '';

        // Only show dots if more than one card
        if (accountCards.length <= 1) {
            accountPagination.style.display = 'none'; // Hide dots if only one card
            return;
        } else {
            accountPagination.style.display = 'flex'; // Show dots if multiple cards
        }

        accountCards.forEach((_, index) => {
            const dot = document.createElement('span');
            dot.classList.add('dot');
            if (index === currentAccountIndex) {
                dot.classList.add('active');
            }
            dot.addEventListener('click', () => {
                currentAccountIndex = index;
                showAccountCard(currentAccountIndex);
            });
            accountPagination.appendChild(dot);
        });
    }

    // Initialize carousel if cards are present
    if (accountCards.length > 0) {
        showAccountCard(currentAccountIndex);
        createPaginationDots();

        // Recalculate and update on window resize (important for responsiveness)
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                // Temporarily disable transition during resize to prevent jumps
                if (accountCardsContainer) {
                    accountCardsContainer.style.transition = 'none';
                }
                showAccountCard(currentAccountIndex); // Recalculate offset based on new dimensions
            }, 200); // Debounce resize for better performance
        });

        // --- Touch/Swipe Logic for Account Cards ---
        let touchStartX = 0;
        let touchEndX = 0;
        let isSwiping = false;

        accountCardsContainer.addEventListener('touchstart', (e) => {
            // Only start swipe if not on the toggle indicator
            if (e.target.closest('.account-toggle-indicator')) {
                isSwiping = false; // Do not start swipe if clicking toggle
                return;
            }
            touchStartX = e.touches[0].clientX;
            isSwiping = true;
            accountCardsContainer.style.transition = 'none'; // Disable transition during drag
        }, { passive: true }); // Use passive listener for better scroll performance

        accountCardsContainer.addEventListener('touchmove', (e) => {
            if (!isSwiping || e.touches.length > 1) return; // Only track single touch swipe

            touchEndX = e.touches[0].clientX;
            const diff = touchEndX - touchStartX;
            const { totalCardWidth } = getCardDimensions();
            const currentOffset = -currentAccountIndex * totalCardWidth;

            // Apply the drag visually by directly manipulating transform
            accountCardsContainer.style.transform = `translateX(${currentOffset + diff}px)`;
        }, { passive: true });

        accountCardsContainer.addEventListener('touchend', (e) => {
            if (!isSwiping) return;
            isSwiping = false;

            touchEndX = e.changedTouches[0].clientX;
            const swipeThreshold = 50; // Minimum pixels to swipe to trigger a change

            if (touchEndX < touchStartX - swipeThreshold) {
                // Swiped left, move to next card
                currentAccountIndex++;
                showAccountCard(currentAccountIndex);
            } else if (touchEndX > touchStartX + swipeThreshold) {
                // Swiped right, move to previous card
                currentAccountIndex--;
                showAccountCard(currentAccountIndex);
            } else {
                // Not enough swipe, snap back to current card position
                showAccountCard(currentAccountIndex);
            }
        });

        // --- Mouse Drag Logic for Desktop (Optional, but good for completeness) ---
        let isDragging = false;
        let dragStartX = 0;
        let currentTranslate = 0; // Stores the base translateX value before drag starts

        accountCardsContainer.addEventListener('mousedown', (e) => {
            // Only left click (main button) and not on the toggle indicator
            if (e.button !== 0 || e.target.closest('.account-toggle-indicator')) {
                isDragging = false; // Do not start drag if clicking toggle
                return;
            }

            isDragging = true;
            dragStartX = e.clientX;
            // Get current transform value for smooth continuation
            const transformMatch = accountCardsContainer.style.transform.match(/translateX\(([-.\d]+)px\)/);
            currentTranslate = transformMatch ? parseFloat(transformMatch[1]) : 0;
            accountCardsContainer.style.transition = 'none'; // Disable transition during drag
            accountCardsContainer.style.cursor = 'grabbing'; // Change cursor
            e.preventDefault(); // Prevent default drag behavior (e.g., image drag)
        });

        accountCardsContainer.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            e.preventDefault(); // Prevent text selection during drag
            const dragDistance = e.clientX - dragStartX;
            accountCardsContainer.style.transform = `translateX(${currentTranslate + dragDistance}px)`;
        });

        accountCardsContainer.addEventListener('mouseup', (e) => {
            if (!isDragging) return;
            isDragging = false;
            accountCardsContainer.style.cursor = 'grab'; // Reset cursor

            const dragDistance = e.clientX - dragStartX;
            const swipeThreshold = 100; // Adjust for mouse sensitivity

            if (dragDistance < -swipeThreshold) { // Dragged left
                currentAccountIndex++;
            } else if (dragDistance > swipeThreshold) { // Dragged right
                currentAccountIndex--;
            }
            showAccountCard(currentAccountIndex); // Snap to the correct card with transition
        });

        accountCardsContainer.addEventListener('mouseleave', () => {
            // If mouse leaves while dragging, treat it as mouseup to avoid stuck state
            if (isDragging) {
                isDragging = false;
                accountCardsContainer.style.cursor = 'grab';
                showAccountCard(currentAccountIndex);
            }
        });
    } // End of accountCards.length > 0 check

    // --- Account Toggle Button Logic (Integrated with showAccountCardByType) ---
    accountToggleIndicators.forEach(indicator => {
        indicator.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent carousel swipe/drag event from firing
            const targetAccountType = indicator.dataset.toggleTarget; // e.g., 'savings' or 'checking'
            showAccountCardByType(targetAccountType);
        });
    });


    // --- UNIFIED DYNAMIC MESSAGE MODAL HANDLING ---
    // Ensure these elements exist in your HTML (they should based on previous instructions)
    const dynamicMessageModal = document.getElementById('dynamicMessageModal');
    const modalTitleElement = dynamicMessageModal ? dynamicMessageModal.querySelector('#modalTitle') : null;
    const modalMessageContentElement = dynamicMessageModal ? dynamicMessageModal.querySelector('#modalMessageContent') : null;
    const closeDynamicModalButtons = dynamicMessageModal ? dynamicMessageModal.querySelectorAll('.close-button, .close-modal-button-main') : [];

    function openDynamicMessageModal(title, message, callback = null) {
        if (!dynamicMessageModal || !modalTitleElement || !modalMessageContentElement) {
            console.error("Dynamic message modal elements not found.");
            return;
        }
        modalTitleElement.textContent = title;
        // Use innerHTML and replace newlines for correct rendering
        modalMessageContentElement.innerHTML = message.replace(/\n/g, '<br>');
        dynamicMessageModal.classList.add('active'); // This class controls display: flex

        // Clear previous listeners on the main close button
        const mainCloseButton = dynamicMessageModal.querySelector('.close-modal-button-main');
        if (mainCloseButton) {
            // Clone the node to remove all existing event listeners
            const newOkayButton = mainCloseButton.cloneNode(true);
            mainCloseButton.parentNode.replaceChild(newOkayButton, mainCloseButton);
            newOkayButton.addEventListener('click', () => {
                closeDynamicMessageModal();
                if (callback && typeof callback === 'function') {
                    callback();
                }
            });
        }
    }

    function closeDynamicMessageModal() {
        if (dynamicMessageModal) {
            dynamicMessageModal.classList.remove('active');
            if (modalMessageContentElement) {
                modalMessageContentElement.innerHTML = ''; // Clear content on close
            }
        }
    }

    // Add event listeners for closing the dynamic message modal (for X button and overlay click)
    closeDynamicModalButtons.forEach(button => {
        // Ensure we don't double-add listeners if they are already handled by openDynamicMessageModal for the main button
        if (!button.classList.contains('close-modal-button-main')) {
            button.addEventListener('click', closeDynamicMessageModal);
        }
    });

    // Close modal if user clicks outside of it
    if (dynamicMessageModal) {
        dynamicMessageModal.addEventListener('click', (event) => {
            if (event.target === dynamicMessageModal) {
                closeDynamicMessageModal();
            }
        });
    }

    // --- TRANSFER MODAL (Choose Transfer Type) Handling ---
    const transferButton = document.getElementById('transferButton'); // The main 'Transfer' action button
    const transferModalOverlay = document.getElementById('transferModalOverlay'); // The choose transfer type modal
    const closeTransferModalButton = document.getElementById('closeTransferModal');
    const transferOptionButtons = document.querySelectorAll('.transfer-options-list .transfer-option');

    if (transferButton && transferModalOverlay && closeTransferModalButton) {
        transferButton.addEventListener('click', () => {
            transferModalOverlay.classList.add('active');
        });

        closeTransferModalButton.addEventListener('click', () => {
            transferModalOverlay.classList.remove('active');
        });

        // Close transfer type modal if user clicks outside of it
        transferModalOverlay.addEventListener('click', (event) => {
            if (event.target === transferModalOverlay) {
                transferModalOverlay.classList.remove('active');
            }
        });
    }

    // --- Logic for displaying admin-set transfer message ---
    transferOptionButtons.forEach(button => {
        button.addEventListener('click', (event) => {
            // Check if the admin-set message should be shown for transfers
            // `showTransferModal` and `transferModalMessage` are global constants set by PHP
            if (window.showTransferModal && window.transferModalMessage) {
                event.preventDefault(); // Stop the navigation to transfer page
                transferModalOverlay.classList.remove('active'); // Close the choose transfer type modal

                const originalHref = button.dataset.href; // Get the original link from data-href

                openDynamicMessageModal(
                    'Transfer Information',
                    window.transferModalMessage,
                    () => { // Callback to execute after user clicks 'Okay'
                        window.location.href = originalHref;
                    }
                );
            } else {
                // If no message or message is turned off, proceed with normal transfer logic
                const href = button.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            }
        });
    });


    // --- MODIFIED LOGIC FOR "VIEW MY CARDS" BUTTON ---
    const viewCardButton = document.getElementById('viewCardButton');

    if (viewCardButton) {
        viewCardButton.addEventListener('click', (event) => {
            event.preventDefault(); // Prevent default navigation initially

            // These are constants that should be passed from dashboard.php PHP
            // They hold the *current* state of the card modal settings from the database
            const messageFromDashboardPHP = window.cardModalMessage; // e.g., "Your new card requires activation."
            const showFromDashboardPHP = window.showCardModal;      // e.g., true or false

            // Make an AJAX call to set session variables for bank_cards.php, regardless of whether a message exists
            fetch(`${BASE_URL_JS}api/set_session_for_card_modal.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message_for_display: messageFromDashboardPHP, // Matches PHP session variable name
                    display_modal: showFromDashboardPHP            // Matches PHP session variable name
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Session set response for card modal:', data);
                // Redirect after session variables are set
                window.location.href = `${BASE_URL_JS}bank_cards`;
            })
            .catch(error => {
                console.error('Error setting session for card modal:', error);
                // In case of error, still redirect to avoid blocking the user
                window.location.href = `${BASE_URL_JS}bank_cards`;
            });
        });
    }

    // --- SIDEBAR TOGGLE LOGIC ---
    const menuIcon = document.querySelector('.menu-icon');
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.querySelector('.sidebar-overlay');
    const closeSidebarButton = document.querySelector('.close-sidebar-button');

    // Function to open the sidebar
    function openSidebar() {
        if (sidebar && sidebarOverlay) {
            sidebar.classList.add('active');
            sidebarOverlay.classList.add('active');
            // Disable body scrolling when sidebar is open
            document.body.style.overflow = 'hidden';
        }
    }

    // Function to close the sidebar
    function closeSidebar() {
        if (sidebar && sidebarOverlay) {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            // Re-enable body scrolling
            document.body.style.overflow = '';
        }
    }

    // Event listener for opening the sidebar (menu icon click)
    if (menuIcon) {
        menuIcon.addEventListener('click', openSidebar);
    }

    // Event listener for closing the sidebar (close button click)
    if (closeSidebarButton) {
        closeSidebarButton.addEventListener('click', closeSidebar);
    }

    // Event listener for closing the sidebar (clicking on the overlay)
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', (event) => {
            // Ensure the click was directly on the overlay, not its children
            if (event.target === sidebarOverlay) {
                closeSidebar();
            }
        });
    }

    // --- Initialize dynamic modals if active on page load ---
    // These are global variables set by PHP in dashboard.php
    // The previous logic correctly prevents auto-opening these specific modals on dashboard load,
    // as they are meant to be triggered by button clicks.
    // The outstanding payment modal is handled separately by PHP directly in dashboard.php.
});