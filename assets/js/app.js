(() => {
    const products = Array.isArray(window.MDP_PRODUCTS) ? window.MDP_PRODUCTS : [];
    const config = window.MDP_CONFIG || {};
    const productMap = new Map(products.map((product) => [product.id, product]));
    const cartKey = 'mdp_cart_v1';
    let cart = loadCart();

    const displayMoney = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: config.displayCurrency || 'USD',
    });

    const chargeMoney = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: config.chargeCurrency || config.displayCurrency || 'USD',
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

    function chargeTotalCents() {
        const usdTotal = cartTotalCents() / 100;
        const chargeCurrency = config.chargeCurrency || config.displayCurrency || 'USD';

        if (chargeCurrency === 'GHS') {
            return Math.round(usdTotal * Number(config.usdToGhsRate || 1) * 100);
        }

        return Math.round(usdTotal * 100);
    }

    function cartCount() {
        return cart.reduce((total, item) => total + Number(item.quantity || 1), 0);
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
        $$('[data-cart-count]').forEach((node) => {
            node.textContent = String(cartCount());
        });

        const total = cartTotalCents() / 100;
        const totalNode = $('[data-cart-total]');
        if (totalNode) totalNode.textContent = displayMoney.format(total);

        const chargeNote = $('[data-charge-note]');
        if (chargeNote) {
            const chargeCurrency = config.chargeCurrency || config.displayCurrency || 'USD';
            if (chargeCurrency === 'GHS') {
                chargeNote.textContent = `Paystack fallback charge: ${chargeMoney.format(chargeTotalCents() / 100)} at $1 = GHS ${Number(config.usdToGhsRate || 1).toFixed(2)}`;
            } else {
                chargeNote.textContent = 'Paystack will charge the same dollar total.';
            }
        }

        const linesNode = $('[data-cart-lines]');
        if (!linesNode) return;

        if (cart.length === 0) {
            linesNode.innerHTML = '<div class="empty-cart">Your cart is empty. Add a few tools to build a monthly stack.</div>';
            return;
        }

        linesNode.innerHTML = cart.map((item) => {
            const product = productMap.get(item.id);
            const usagePriced = isUsagePriced(product);
            const lineTotal = lineTotalCents(item) / 100;
            const usageControls = usagePriced ? `
                <label class="user-calculator">
                    <span>Number of users</span>
                    <input type="number" min="${Number(product.min_users || 1)}" max="10000000" step="1" value="${clampUsers(product, item.users)}" data-users-input="${product.id}">
                    <small>${displayMoney.format(productUnitPrice(product, product.base_users))} at ${Number(product.base_users || 1).toLocaleString()} users. Minimum monthly pack: ${displayMoney.format(Number(product.min_price || product.price || 0))}.</small>
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
                    <div>
                        <h3>${escapeHtml(product.name)}</h3>
                        <p>${displayMoney.format(lineTotal)} / month${usagePriced ? ' based on users' : ''}</p>
                        ${usageControls}
                    </div>
                    ${controls}
                </div>
            `;
        }).join('');
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

    function setupFilters() {
        const select = $('[data-category]');
        const search = $('[data-search]');
        const categories = [...new Set(products.map((product) => product.category))].sort();

        if (select) {
            categories.forEach((category) => {
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
            throw new Error(result.message || 'Unable to initialize payment.');
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

        if (formData.get('monthly_terms') !== 'on') {
            toast('Please agree to monthly billing before checkout.');
            return;
        }

        button.disabled = true;
        button.textContent = 'Preparing secure checkout...';

        try {
            const initialized = await initializePayment(formData);
            button.textContent = 'Redirecting to Paystack...';
            window.location.href = initialized.authorization_url;
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Proceed to checkout';
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

        if (event.target.closest('[data-close-cart]')) {
            closeCart();
            return;
        }

        const drawer = $('[data-cart-drawer]');
        if (drawer && event.target === drawer) {
            closeCart();
        }
    });

    document.addEventListener('input', (event) => {
        const userInput = event.target.closest('[data-users-input]');
        if (userInput) {
            updateUsers(userInput.dataset.usersInput, userInput.value);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeCart();
    });

    $('[data-checkout-form]')?.addEventListener('submit', handleCheckout);

    setupFilters();
    renderCart();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/service-worker.js').catch(() => {});
        });
    }
})();
