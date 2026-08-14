(() => {
    const products = Array.isArray(window.MDP_PRODUCTS) ? window.MDP_PRODUCTS : [];
    const config = window.MDP_CONFIG || {};
    const productMap = new Map(products.map((product) => [product.id, product]));
    const cartKey = 'mdp_cart_v2';
    try {
        localStorage.removeItem('mdp_cart_v1');
    } catch {
        // Storage can be unavailable in strict privacy modes.
    }
    let cart = loadCart();

    const displayMoney = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: config.displayCurrency || 'USD',
    });

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function isUsagePriced(product) {
        return product && product.pricing_model === 'users';
    }

    function clampUsers(product, users) {
        const defaultUsers = Number(product.default_users || product.base_users || 1);
        const minUsers = Number(product.min_users || 1);
        const value = Number.parseInt(users || defaultUsers, 10);

        return Math.max(minUsers, Math.min(10000000, Number.isFinite(value) ? value : defaultUsers));
    }

    function productUnitPrice(product, users) {
        if (!isUsagePriced(product)) {
            return Number(product.price || 0);
        }

        const baseUsers = Math.max(1, Number(product.base_users || 1));
        const basePrice = Number(product.base_price || product.price || 0);
        const minPrice = Number(product.min_price || 0);
        const safeUsers = clampUsers(product, users);
        const calculated = basePrice * (safeUsers / baseUsers);

        return Math.round(Math.max(minPrice, calculated) * 100) / 100;
    }

    function normalizeCart(items) {
        const normalized = [];

        items.forEach((item) => {
            const product = productMap.get(item.id);
            if (!product) return;

            if (isUsagePriced(product)) {
                normalized.push({
                    id: product.id,
                    quantity: 1,
                    users: clampUsers(product, item.users),
                });
                return;
            }

            const quantity = Math.max(1, Math.min(99, Number(item.quantity || 1)));
            normalized.push({ id: product.id, quantity });
        });

        return normalized;
    }

    function loadCart() {
        try {
            const parsed = JSON.parse(localStorage.getItem(cartKey) || '[]');
            return normalizeCart(Array.isArray(parsed) ? parsed : []);
        } catch {
            return [];
        }
    }

    function saveCart() {
        localStorage.setItem(cartKey, JSON.stringify(normalizeCart(cart)));
    }

    function lineTotalCents(item) {
        const product = productMap.get(item.id);
        return Math.round(productUnitPrice(product, item.users) * 100) * Number(item.quantity || 1);
    }

    function cartTotalCents() {
        return cart.reduce((total, item) => total + lineTotalCents(item), 0);
    }

    function cartCount() {
        return cart.reduce((total, item) => total + Number(item.quantity || 1), 0);
    }

    function usageEstimateText(product, users) {
        const safeUsers = clampUsers(product, users);
        const price = productUnitPrice(product, safeUsers);

        return `${safeUsers.toLocaleString()} users → ${displayMoney.format(price)} / month. Updates as you edit.`;
    }

    function activeUserInputState() {
        const active = document.activeElement;

        if (!active || !active.matches?.('[data-users-input]')) {
            return null;
        }

        const lineContainers = $$('[data-cart-lines]');

        return {
            id: active.dataset.usersInput,
            listIndex: lineContainers.findIndex((node) => node.contains(active)),
        };
    }

    function restoreUserInputFocus(state) {
        if (!state?.id) {
            return;
        }

        const lineContainers = $$('[data-cart-lines]');
        const scopedInputs = state.listIndex >= 0 && lineContainers[state.listIndex]
            ? $$('[data-users-input]', lineContainers[state.listIndex])
            : [];
        const fallbackInputs = $$('[data-users-input]');
        const input = [...scopedInputs, ...fallbackInputs].find((node) => node.dataset.usersInput === state.id);

        if (!input) {
            return;
        }

        input.focus({ preventScroll: true });

        try {
            const end = input.value.length;
            input.setSelectionRange(end, end);
        } catch {
            // Number inputs do not always support selection ranges.
        }
    }

    function toast(message) {
        const node = $('[data-toast]');
        if (!node) return;
        node.textContent = message;
        node.classList.add('is-visible');
        clearTimeout(node._hideTimer);
        node._hideTimer = setTimeout(() => node.classList.remove('is-visible'), 2600);
    }

    function addToCart(id) {
        const found = cart.find((item) => item.id === id);
        const product = productMap.get(id);

        if (!product) return;

        if (found) {
            if (isUsagePriced(product)) {
                found.users = clampUsers(product, found.users);
                saveCart();
                renderCart();
                openCart();
                toast(`${product.name} is already in cart. Adjust users in checkout.`);
                return;
            }

            found.quantity += 1;
        } else {
            cart.push(isUsagePriced(product)
                ? { id, quantity: 1, users: clampUsers(product, product.default_users) }
                : { id, quantity: 1 });
        }

        saveCart();
        renderCart();
        toast(`${product.name} added to cart`);
    }

    function updateQuantity(id, delta) {
        const item = cart.find((line) => line.id === id);
        const product = productMap.get(id);
        if (!item) return;

        if (isUsagePriced(product)) {
            cart = cart.filter((line) => line.id !== id);
        } else {
            item.quantity += delta;
            if (item.quantity <= 0) {
                cart = cart.filter((line) => line.id !== id);
            }
        }

        saveCart();
        renderCart();
    }

    function updateUsers(id, users) {
        const item = cart.find((line) => line.id === id);
        const product = productMap.get(id);
        if (!item || !isUsagePriced(product)) return;

        item.users = clampUsers(product, users);
        saveCart();
        renderCart();
    }

    function renderCart() {
        const focusState = activeUserInputState();

        $$('[data-cart-count]').forEach((node) => {
            node.textContent = String(cartCount());
        });

        $$('[data-pay-button]').forEach((button) => {
            const defaultText = button.dataset.defaultText || button.textContent || 'Proceed to checkout';
            button.dataset.defaultText = defaultText;

            if (button.dataset.loading === 'true') {
                return;
            }

            button.disabled = cart.length === 0;
            button.textContent = cart.length === 0 ? 'Add products to checkout' : defaultText;
        });

        const total = cartTotalCents() / 100;
        $$('[data-cart-total]').forEach((node) => {
            node.textContent = displayMoney.format(total);
        });

        $$('[data-charge-note]').forEach((chargeNote) => {
            chargeNote.textContent = 'Secure checkout is handled by Paystack.';
        });

        const lineNodes = $$('[data-cart-lines]');
        if (lineNodes.length === 0) return;

        if (cart.length === 0) {
            const emptyHtml = `
                <div class="empty-cart">
                    <strong>Your cart is empty.</strong>
                    <span>Add a few tools to build a monthly stack.</span>
                    <a href="#products">Browse products</a>
                </div>
            `;
            lineNodes.forEach((linesNode) => {
                linesNode.innerHTML = emptyHtml;
            });
            return;
        }

        const linesHtml = cart.map((item) => {
            const product = productMap.get(item.id);
            const usagePriced = isUsagePriced(product);
            const lineTotal = lineTotalCents(item) / 100;
            const productImage = product.image || '/assets/images/market-og.svg';
            const productImageType = product.image_type || 'logo';
            const currentUsers = usagePriced ? clampUsers(product, item.users) : null;
            const usageControls = usagePriced ? `
                <label class="user-calculator">
                    <span>Number of users</span>
                    <input type="number" min="${Number(product.min_users || 1)}" max="10000000" step="1" value="${currentUsers}" data-users-input="${product.id}">
                    <small class="usage-live-note">${usageEstimateText(product, currentUsers)}</small>
                </label>
            ` : '';
            const controls = usagePriced ? `
                <button class="remove-line" type="button" data-qty="${product.id}" data-delta="-1">Remove</button>
            ` : `
                <div class="quantity-controls" aria-label="Quantity controls for ${escapeHtml(product.name)}">
                    <button type="button" data-qty="${product.id}" data-delta="-1">-</button>
                    <strong>${item.quantity}</strong>
                    <button type="button" data-qty="${product.id}" data-delta="1">+</button>
                </div>
            `;

            return `
                <div class="cart-line">
                    <img class="line-logo cart-line-logo line-logo--${escapeHtml(productImageType)}" src="${escapeHtml(productImage)}" alt="" loading="lazy" decoding="async">
                    <div class="cart-line-body">
                        <h3>${escapeHtml(product.name)}</h3>
                        <p>${displayMoney.format(lineTotal)} / month${usagePriced ? ' based on users' : ''}</p>
                        ${usageControls}
                    </div>
                    <div class="cart-line-actions">${controls}</div>
                </div>
            `;
        }).join('');

        lineNodes.forEach((linesNode) => {
            linesNode.innerHTML = linesHtml;
        });

        restoreUserInputFocus(focusState);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function openCart() {
        const drawer = $('[data-cart-drawer]');
        if (!drawer) return;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeCart() {
        const drawer = $('[data-cart-drawer]');
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function scrollToCurrentHash() {
        const id = window.location.hash ? window.location.hash.slice(1) : '';
        if (!id) return;

        const target = document.getElementById(id);
        if (!target) return;

        target.scrollIntoView({ block: 'start', behavior: 'auto' });
    }

    function setupFilters() {
        const select = $('[data-category]');
        const search = $('[data-search]');
        const categories = [...new Set(products.map((product) => product.category))].sort();

        if (select) {
            const existingOptions = new Set([...select.options].map((option) => option.value));
            categories.forEach((category) => {
                if (existingOptions.has(category)) return;
                const option = document.createElement('option');
                option.value = category;
                option.textContent = category;
                select.appendChild(option);
            });
        }

        const apply = () => {
            const term = (search?.value || '').trim().toLowerCase();
            const category = select?.value || 'all';

            $$('[data-product-card]').forEach((card) => {
                const matchesSearch = !term || card.dataset.name.includes(term);
                const matchesCategory = category === 'all' || card.dataset.category === category;
                card.hidden = !(matchesSearch && matchesCategory);
            });
        };

        search?.addEventListener('input', apply, { passive: true });
        select?.addEventListener('change', apply);
    }

    async function initializePayment(formData) {
        const response = await fetch(config.initializeEndpoint || '/api/initialize.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: formData.get('name'),
                email: formData.get('email'),
                monthly_terms: formData.get('monthly_terms') === 'on',
                cart: normalizeCart(cart),
            }),
        });

        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message = result.message || 'Unable to initialize payment.';

            if (message.includes('Paystack secret key is not configured')) {
                throw new Error('Paystack test keys are not configured on this local server. Add test keys to .env before testing payment.');
            }

            throw new Error(message);
        }

        return result;
    }

    async function handleCheckout(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const button = $('[data-pay-button]', form);
        const formData = new FormData(form);
        const totalCents = cartTotalCents();

        if (cart.length === 0 || totalCents < 1) {
            toast('Add at least one product before checkout.');
            return;
        }

        const defaultButtonText = button?.dataset.defaultText || button?.textContent || 'Proceed to checkout';
        if (button) {
            button.dataset.defaultText = defaultButtonText;
            button.dataset.loading = 'true';
            button.disabled = true;
            button.textContent = 'Preparing secure checkout...';
        }

        try {
            const initialized = await initializePayment(formData);
            if (button) button.textContent = 'Redirecting to Paystack...';
            window.location.href = initialized.authorization_url;
        } catch (error) {
            if (button) {
                delete button.dataset.loading;
                button.disabled = false;
                button.textContent = defaultButtonText;
            }
            renderCart();
            toast(error.message || 'Unable to start checkout.');
        }
    }

    document.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-to-cart]');
        if (addButton) {
            addToCart(addButton.dataset.addToCart);
            return;
        }

        const quantityButton = event.target.closest('[data-qty]');
        if (quantityButton) {
            updateQuantity(quantityButton.dataset.qty, Number(quantityButton.dataset.delta || 0));
            return;
        }

        if (event.target.closest('[data-open-cart]')) {
            openCart();
            return;
        }

        if (event.target.closest('[data-go-checkout]')) {
            closeCart();
            return;
        }

        if (event.target.closest('[data-close-cart]')) {
            closeCart();
            return;
        }

        const drawer = $('[data-cart-drawer]');
        if (drawer && event.target === drawer) {
            closeCart();
        }
    });

    function handleUserInputChange(event) {
        const userInput = event.target.closest('[data-users-input]');
        if (userInput) {
            updateUsers(userInput.dataset.usersInput, userInput.value);
        }
    }

    document.addEventListener('input', handleUserInputChange);
    document.addEventListener('change', handleUserInputChange);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeCart();
    });

    $$('[data-checkout-form]').forEach((form) => {
        form.addEventListener('submit', handleCheckout);
    });

    setupFilters();
    renderCart();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js').catch(() => {});
        });
    }

    window.addEventListener('load', () => {
        setTimeout(scrollToCurrentHash, 120);
        setTimeout(scrollToCurrentHash, 450);
    });
})();
