// Path: C:\xampp\htdocs\hometownbank\frontend\user.dashboard.js

document.addEventListener('DOMContentLoaded', () => {

    // --- Dynamic User Name Display ---
    const greetingElement = document.querySelector('.greeting h1');
    if (greetingElement) {
        // Get user's first name from the data attribute set by PHP
        const userFirstName = greetingElement.dataset.userFirstName || "User"; // Fallback to "User"
        greetingElement.textContent = `Hi, ${userFirstName}`;
    }

    // --- Logic for Account Cards Carousel ---
    const accountCardsContainer = document.querySelector('.account-cards-container');
    const accountCards = accountCardsContainer ? Array.from(accountCardsContainer.querySelectorAll('.account-card')) : [];
    const accountPagination = document.querySelector('.account-pagination');

    let currentAccountIndex = 0;

    // Helper to get computed styles for card dimensions including margin
    function getCardDimensions() {
        if (accountCards.length === 0) return { cardWidth: 0, cardMarginRight: 0, totalCardWidth: 0 };
        const cardStyle = getComputedStyle(accountCards[0]);
        // Get the actual width of the card including padding and border
        const cardWidth = accountCards[0].offsetWidth;
        const cardMarginRight = parseFloat(cardStyle.marginRight) || 0;
        const totalCardWidth = cardWidth + cardMarginRight;
        return { cardWidth, cardMarginRight, totalCardWidth };
    }

    function showAccountCard(index) {
        if (accountCards.length === 0 || !accountCardsContainer) {
            // console.log("No account cards or container found for carousel."); // Debug
            return;
        }

        // Ensure index is within bounds (looping)
        currentAccountIndex = (index % accountCards.length + accountCards.length) % accountCards.length;

        const { totalCardWidth } = getCardDimensions();
        const offset = currentAccountIndex * totalCardWidth;

        accountCardsContainer.style.transform = `translateX(-${offset}px)`;
        accountCardsContainer.style.transition = 'transform 0.5s ease-in-out'; // Re-enable transition after drag

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

    function createPaginationDots() {
        if (!accountPagination) return;

        // Clear existing dots
        accountPagination.innerHTML = '';

        if (accountCards.length <= 1) { // Only show dots if more than one card
            return;
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

    if (accountCards.length > 0) {
        // Initial display
        showAccountCard(currentAccountIndex);
        createPaginationDots();

        // Recalculate and update on window resize (important for responsiveness)
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                // Temporarily disable transition during resize to prevent weird jumps
                if (accountCardsContainer) {
                    accountCardsContainer.style.transition = 'none';
                }
                showAccountCard(currentAccountIndex); // Recalculate offset based on new dimensions
                // No need to recreate dots unless number of cards changes or structure changes
            }, 200); // Debounce resize for better performance
        });

        // Touch/Swipe Logic
        let touchStartX = 0;
        let touchEndX = 0;
        let isSwiping = false; // Flag to ensure we only swipe if started on container

        accountCardsContainer.addEventListener('touchstart', (e) => {
            touchStartX = e.touches[0].clientX;
            isSwiping = true;
            // Temporarily disable transition during drag for smoother feel
            accountCardsContainer.style.transition = 'none';
        }, { passive: true });

        accountCardsContainer.addEventListener('touchmove', (e) => {
            if (!isSwiping || e.touches.length > 1) return;

            touchEndX = e.touches[0].clientX;
            const diff = touchEndX - touchStartX;
            const { totalCardWidth } = getCardDimensions();
            const currentOffset = -currentAccountIndex * totalCardWidth;

            // Apply the drag visually
            accountCardsContainer.style.transform = `translateX(${currentOffset + diff}px)`;
        });

        accountCardsContainer.addEventListener('touchend', (e) => {
            if (!isSwiping) return;
            isSwiping = false; // Reset swipe flag

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
                // Not enough swipe, snap back to current card
                showAccountCard(currentAccountIndex);
            }
            // Transition is re-enabled inside showAccountCard
        });

        // Mouse Drag Logic for Desktop (Optional, but good for completeness)
        let isDragging = false;
        let dragStartX = 0;
        let currentTranslate = 0;

        accountCardsContainer.addEventListener('mousedown', (e) => {
            isDragging = true;
            dragStartX = e.clientX;
            // Get current transform value for smooth continuation
            const transformMatch = accountCardsContainer.style.transform.match(/translateX\(([-.\d]+)px\)/);
            currentTranslate = transformMatch ? parseFloat(transformMatch[1]) : 0;
            accountCardsContainer.style.transition = 'none'; // Disable transition during drag
            accountCardsContainer.style.cursor = 'grabbing'; // Change cursor
        });

        accountCardsContainer.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
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
            // If mouse leaves while dragging, treat it as mouseup
            if (isDragging) {
                isDragging = false;
                accountCardsContainer.style.cursor = 'grab';
                showAccountCard(currentAccountIndex);
            }
        });
    }


    // --- Transfer Modal Logic ---
    const transferButton = document.getElementById('transferButton');
    const transferModalOverlay = document.getElementById('transferModalOverlay');
    const closeTransferModal = document.getElementById('closeTransferModal');

    if (transferButton && transferModalOverlay && closeTransferModal) {
        transferButton.addEventListener('click', () => {
            transferModalOverlay.classList.add('active');
        });

        closeTransferModal.addEventListener('click', () => {
            transferModalOverlay.classList.remove('active');
        });

        transferModalOverlay.addEventListener('click', (e) => {
            if (e.target === transferModalOverlay) {
                transferModalOverlay.classList.remove('active');
            }
        });

        // --- Handle transfer type selection within the modal ---
        // IMPORTANT: The `onclick` attributes in dashboard.php HTML are already handling this redirection.
        // If you want to handle it purely in JS, you need to remove the `onclick` attributes from the HTML
        // and assign unique IDs to each transfer option button.
        // For example:
        // <button class="transfer-option" id="transferOwnAccountBtn" data-transfer-type="Own Account">
        // Then in JS:
        // const transferOwnAccountBtn = document.getElementById('transferOwnAccountBtn');
        // if (transferOwnAccountBtn) {
        //     transferOwnAccountBtn.addEventListener('click', () => handleTransferTypeClick('own_account'));
        // }
        // ... and so on for all transfer types.
        // As it stands, the HTML `onclick` will trigger the navigation.
    }

    // --- Sidebar Logic ---
    const menuIcon = document.getElementById('menuIcon');
    const sidebar = document.getElementById('sidebar');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (menuIcon && sidebar && closeSidebarBtn && sidebarOverlay) {
        menuIcon.addEventListener('click', () => {
            sidebar.classList.add('active');
            sidebarOverlay.classList.add('active');
        });

        closeSidebarBtn.addEventListener('click', () => {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }

    // --- NEW: View My Cards Logic ---
    // Make sure the <a> tag in dashboard.php has id="viewMyCardsButton"
    const viewMyCardsButton = document.getElementById('viewMyCardsButton');

    if (viewMyCardsButton) {
        viewMyCardsButton.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default link behavior if BASE_URL_JS isn't ready or for custom handling
            // Ensure BASE_URL_JS is correctly passed from PHP and includes the domain
            // Refer to the "Key Observations and Changes" section above for adding BASE_URL_JS in dashboard.php
            if (typeof BASE_URL_JS !== 'undefined') {
                window.location.href = `${BASE_URL_JS}/frontend/bank_cards.php`;
            } else {
                console.error("BASE_URL_JS is not defined. Cannot navigate to bank_cards.php.");
                // Fallback to direct navigation if BASE_URL_JS is not defined, assuming relative path works
                window.location.href = './bank_cards.php';
            }
        });
    }
});