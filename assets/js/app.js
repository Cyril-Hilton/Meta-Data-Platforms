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

    function clampPrice(product, price) {
        const minPrice = Number(product.min_price || 1);
        const defaultPrice = Number(product.price || 1);
        const parsed = Number.parseFloat(price);
        const value = Number.isFinite(parsed) ? parsed : defaultPrice;

        return Math.max(minPrice, Math.min(100000, Math.round(value * 100) / 100));
    }

    function priceToEstimatedUsers(product, price) {
        const baseUsers = Math.max(1, Number(product.base_users || 1));
        const basePrice = Math.max(0.01, Number(product.base_price || product.price || 1));
        const safePrice = clampPrice(product, price);

        return Math.max(1, Math.round((safePrice / basePrice) * baseUsers));
    }

    function productUnitPrice(product, users, customPrice) {
        if (!isUsagePriced(product)) {
            return Number(product.price || 0);
        }

        if (customPrice !== undefined && customPrice !== null && Number.isFinite(Number(customPrice))) {
            return clampPrice(product, customPrice);
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

            const quantity = Math.max(1, Math.min(99, Number(item.quantity || 1)));

            if (isUsagePriced(product)) {
                const price = item.price !== undefined && item.price !== null ? clampPrice(product, item.price) : productUnitPrice(product, item.users);
                const users = item.users !== undefined && item.users !== null ? clampUsers(product, item.users) : priceToEstimatedUsers(product, price);

                normalized.push({
                    id: product.id,
                    quantity,
                    price,
                    users,
                });
                return;
            }

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
        const unitPrice = item.price !== undefined && item.price !== null ? clampPrice(product, item.price) : productUnitPrice(product, item.users);
        return Math.round(unitPrice * 100) * Number(item.quantity || 1);
    }

    function cartTotalCents() {
        return cart.reduce((total, item) => total + lineTotalCents(item), 0);
    }

    function cartCount() {
        return cart.reduce((total, item) => total + Number(item.quantity || 1), 0);
    }

    function usageEstimateText(product, price, users) {
        const safePrice = price !== undefined && price !== null ? clampPrice(product, price) : productUnitPrice(product, users);
        const estUsers = priceToEstimatedUsers(product, safePrice);

        return `${displayMoney.format(safePrice)} / month → ~${estUsers.toLocaleString()} users included. Updates as you edit.`;
    }

    function activeUserInputState() {
        const active = document.activeElement;

        if (!active || (!active.matches?.('[data-users-input]') && !active.matches?.('[data-price-input]'))) {
            return null;
        }

        const lineContainers = $$('[data-cart-lines]');

        return {
            id: active.dataset.priceInput || active.dataset.usersInput,
            lineIndex: active.dataset.lineIndex,
            listIndex: lineContainers.findIndex((node) => node.contains(active)),
        };
    }

    function restoreUserInputFocus(state) {
        if (!state) {
            return;
        }

        const lineContainers = $$('[data-cart-lines]');
        const scopedInputs = state.listIndex >= 0 && lineContainers[state.listIndex]
            ? $$('[data-price-input], [data-users-input]', lineContainers[state.listIndex])
            : [];
        const fallbackInputs = $$('[data-price-input], [data-users-input]');
        const allInputs = [...scopedInputs, ...fallbackInputs];
        const input = (state.lineIndex !== undefined
            ? allInputs.find((node) => node.dataset.lineIndex === String(state.lineIndex))
            : null) || allInputs.find((node) => (node.dataset.priceInput || node.dataset.usersInput) === state.id);

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
        const product = productMap.get(id);

        if (!product) return;

        if (isUsagePriced(product)) {
            const defaultPrice = clampPrice(product, product.price);
            const defaultUsers = clampUsers(product, product.default_users);
            const found = cart.find((item) => item.id === id && item.price === defaultPrice);

            if (found) {
                found.quantity += 1;
            } else {
                cart.push({ id, quantity: 1, price: defaultPrice, users: defaultUsers });
            }
        } else {
            const found = cart.find((item) => item.id === id);
            if (found) {
                found.quantity += 1;
            } else {
                cart.push({ id, quantity: 1 });
            }
        }

        saveCart();
        renderCart();
        toast(`${product.name} added to cart`);
    }

    function updateQuantity(target, delta) {
        let index = -1;

        if (typeof target === 'number') {
            index = target;
        } else if (typeof target === 'string') {
            const parsed = Number.parseInt(target, 10);
            if (Number.isFinite(parsed) && String(parsed) === target && cart[parsed]) {
                index = parsed;
            } else {
                index = cart.findIndex((line) => line.id === target);
            }
        }

        const item = cart[index];
        if (!item) return;

        item.quantity += delta;
        if (item.quantity <= 0) {
            cart.splice(index, 1);
        }

        saveCart();
        renderCart();
    }

    function updateLiveCartTotals(index) {
        const item = cart[index];
        if (!item) return;

        const product = productMap.get(item.id);
        if (!product) return;

        const unitPrice = item.price !== undefined && item.price !== null ? clampPrice(product, item.price) : productUnitPrice(product, item.users);
        const lineTotal = unitPrice * Number(item.quantity || 1);
        const priceNote = item.quantity > 1
            ? `${displayMoney.format(unitPrice)} / month × ${item.quantity} = <strong>${displayMoney.format(lineTotal)}</strong>`
            : `${displayMoney.format(unitPrice)} / month${isUsagePriced(product) ? ' custom price' : ''}`;
        const estimateText = usageEstimateText(product, unitPrice, item.users);

        $$(`[data-line-index="${index}"]`).forEach((lineNode) => {
            const priceP = lineNode.querySelector('.cart-line-body > p');
            if (priceP) priceP.innerHTML = priceNote;

            const estimateNote = lineNode.querySelector('.usage-live-note');
            if (estimateNote) estimateNote.textContent = estimateText;
        });

        const total = cartTotalCents() / 100;
        $$('[data-cart-total]').forEach((node) => {
            node.textContent = displayMoney.format(total);
        });
    }

    function updatePrice(target, price, isLive = false) {
        let index = -1;

        if (typeof target === 'number') {
            index = target;
        } else if (typeof target === 'string') {
            const parsed = Number.parseInt(target, 10);
            if (Number.isFinite(parsed) && String(parsed) === target && cart[parsed]) {
                index = parsed;
            } else {
                index = cart.findIndex((line) => line.id === target);
            }
        }

        const item = cart[index];
        const product = item ? productMap.get(item.id) : null;
        if (!item || !product || !isUsagePriced(product)) return;

        item.price = clampPrice(product, price);
        item.users = priceToEstimatedUsers(product, item.price);
        saveCart();

        if (isLive && index >= 0) {
            updateLiveCartTotals(index);
        } else {
            renderCart();
        }
    }

    function updateUsers(target, users, isLive = false) {
        let index = -1;

        if (typeof target === 'number') {
            index = target;
        } else if (typeof target === 'string') {
            const parsed = Number.parseInt(target, 10);
            if (Number.isFinite(parsed) && String(parsed) === target && cart[parsed]) {
                index = parsed;
            } else {
                index = cart.findIndex((line) => line.id === target);
            }
        }

        const item = cart[index];
        const product = item ? productMap.get(item.id) : null;
        if (!item || !product || !isUsagePriced(product)) return;

        item.users = clampUsers(product, users);
        item.price = productUnitPrice(product, item.users);
        saveCart();

        if (isLive && index >= 0) {
            updateLiveCartTotals(index);
        } else {
            renderCart();
        }
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

        const linesHtml = cart.map((item, index) => {
            const product = productMap.get(item.id);
            const usagePriced = isUsagePriced(product);
            const unitPrice = item.price !== undefined && item.price !== null ? clampPrice(product, item.price) : productUnitPrice(product, item.users);
            const lineTotal = unitPrice * Number(item.quantity || 1);
            const productImage = product.image || '/assets/images/market-og.svg';
            const productImageType = product.image_type || 'logo';
            const usageControls = usagePriced ? `
                <label class="user-calculator">
                    <span>Monthly price ($)</span>
                    <input type="number" min="${Number(product.min_price || 1)}" max="100000" step="1" value="${unitPrice}" data-price-input="${product.id}" data-users-input="${product.id}" data-line-index="${index}">
                    <small class="usage-live-note">${usageEstimateText(product, unitPrice, item.users)}</small>
                </label>
            ` : '';
            const controls = `
                <div class="quantity-controls" aria-label="Quantity controls for ${escapeHtml(product.name)}">
                    <button type="button" data-qty="${index}" data-delta="-1">-</button>
                    <strong>${item.quantity}</strong>
                    <button type="button" data-qty="${index}" data-delta="1">+</button>
                </div>
            `;
            const priceNote = item.quantity > 1
                ? `${displayMoney.format(unitPrice)} / month × ${item.quantity} = <strong>${displayMoney.format(lineTotal)}</strong>`
                : `${displayMoney.format(unitPrice)} / month${usagePriced ? ' custom price' : ''}`;

            return `
                <div class="cart-line" data-line-index="${index}">
                    <img class="line-logo cart-line-logo line-logo--${escapeHtml(productImageType)}" src="${escapeHtml(productImage)}" alt="" loading="lazy" decoding="async">
                    <div class="cart-line-body">
                        <h3>${escapeHtml(product.name)}</h3>
                        <p>${priceNote}</p>
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
        const priceInput = event.target.closest('[data-price-input]');
        const userInput = event.target.closest('[data-users-input]');
        const targetInput = priceInput || userInput;

        if (targetInput) {
            const lineIndex = targetInput.dataset.lineIndex;
            const isLive = event.type === 'input';
            const targetId = targetInput.dataset.priceInput || targetInput.dataset.usersInput;

            if (priceInput || targetInput.dataset.priceInput) {
                updatePrice(lineIndex !== undefined ? lineIndex : targetId, targetInput.value, isLive);
            } else {
                updateUsers(lineIndex !== undefined ? lineIndex : targetId, targetInput.value, isLive);
            }
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
