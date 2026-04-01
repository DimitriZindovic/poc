<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pilotage Abonnements</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-5xl p-6">
        <h1 class="text-2xl font-bold">Pilotage des Abonnements</h1>
        <p class="mt-2 text-sm text-slate-600">
            Suivez les produits, les commandes et les expéditions depuis un tableau de pilotage unique.
        </p>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">Catalogue Shopify</h2>
            <button id="load-products" class="mt-3 rounded bg-slate-900 px-4 py-2 text-white">Charger les produits</button>
            <ul id="products" class="mt-4 space-y-2 text-sm">
                <li class="rounded border border-dashed border-slate-300 bg-slate-50 p-3 text-slate-600">Cliquez sur "Charger les produits" pour afficher le catalogue.</li>
            </ul>
        </section>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">Créer une commande</h2>
            <form id="simulate-order-form" class="mt-3 grid gap-3 md:grid-cols-3">
                <select id="variant-id" class="rounded border border-slate-300 px-3 py-2" required>
                    <option value="">Sélectionner un produit</option>
                </select>
                <input id="buyer-email" class="rounded border border-slate-300 px-3 py-2" type="email" placeholder="Email client" required>
                <button class="rounded bg-emerald-700 px-4 py-2 text-white" type="submit">Créer la commande</button>
            </form>
            <div id="simulate-result" class="mt-3"></div>
        </section>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">Suivi des commandes</h2>
            <p class="mt-1 text-sm text-slate-600">Affichez l'état le plus récent des colis préparés.</p>
            <button id="load-shipping" class="mt-3 rounded bg-indigo-700 px-4 py-2 text-white">Afficher les commandes</button>
            <div id="shipping-result" class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                Cliquez sur "Afficher les commandes" pour charger les données.
            </div>
        </section>
    </main>

    <script>
        const productsList = document.getElementById('products');
        const variantSelect = document.getElementById('variant-id');
        const simulateResult = document.getElementById('simulate-result');
        const shippingResult = document.getElementById('shipping-result');
        let checkoutPollingId = null;
        let shippingStatusFilter = 'all';
        let currentShippingItems = [];

        async function loadProducts() {
            productsList.innerHTML = '<li>Chargement...</li>';
            variantSelect.innerHTML = '<option value="">Chargement des produits...</option>';

            const response = await fetch('/api/shopify/products');
            const data = await response.json();

            if (!data.products) {
                productsList.innerHTML = '<li>Erreur de chargement.</li>';
                variantSelect.innerHTML = '<option value="">Aucun produit disponible</option>';
                return;
            }

            variantSelect.innerHTML = '<option value="">Sélectionner un produit</option>';

            productsList.innerHTML = data.products.map((product) => {
                const variant = (product.variants && product.variants[0]) ? product.variants[0] : null;
                const variantId = variant ? variant.id : '-';

                if (variant && variant.id) {
                    const option = document.createElement('option');
                    option.value = String(variant.id);
                    option.textContent = `${product.title} (${variant.price || '-' } EUR)`;
                    variantSelect.appendChild(option);
                }

                return `<li class="rounded border border-slate-200 p-2"><strong>${product.title}</strong><br>Variant ID: ${variantId}</li>`;
            }).join('');
        }

        document.getElementById('load-products').addEventListener('click', loadProducts);
        variantSelect.addEventListener('focus', async () => {
            if (variantSelect.options.length <= 1) {
                await loadProducts();
            }
        });
        variantSelect.addEventListener('mousedown', async () => {
            if (variantSelect.options.length <= 1) {
                await loadProducts();
            }
        });

        function renderOrderPending() {
            simulateResult.innerHTML = `
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-sm font-semibold text-emerald-900">Création de la commande en cours...</p>
                    <p class="mt-1 text-sm text-emerald-700">Validation des informations et préparation du paiement.</p>
                </div>
            `;
        }

        function renderOrderResult(payload, statusPayload = null) {
            const orderId = payload.shopify_order_id || statusPayload?.order?.shopify_order_id || '-';
            const subscriptions = payload.created_subscriptions || statusPayload?.subscriptions_count || 0;
            const paymentLink = payload.checkout_url || null;

            simulateResult.innerHTML = `
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-slate-900">Commande créée</h3>
                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Succès</span>
                    </div>
                    <div class="mt-3 grid gap-2 text-sm text-slate-700 md:grid-cols-2">
                        <p><span class="font-semibold">Commande Shopify:</span> ${orderId}</p>
                        <p><span class="font-semibold">Abonnements créés:</span> ${subscriptions}</p>
                    </div>
                    ${paymentLink ? `<a href="${paymentLink}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-block rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Ouvrir le paiement</a>` : ''}
                </div>
            `;
        }

        function setShippingFilter(filter) {
            shippingStatusFilter = filter;

            document.querySelectorAll('[data-shipping-filter]').forEach((button) => {
                const isActive = button.dataset.shippingFilter === filter;
                button.classList.toggle('ring-2', isActive);
                button.classList.toggle('ring-offset-1', isActive);
                button.classList.toggle('ring-indigo-400', isActive);
            });

            renderShippingItems();
        }

        function renderOrderError(message) {
            simulateResult.innerHTML = `
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                    <p class="text-sm font-semibold text-rose-900">Échec de création de commande</p>
                    <p class="mt-1 text-sm text-rose-700">${message}</p>
                </div>
            `;
        }

        document.getElementById('simulate-order-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            renderOrderPending();

            try {
                const response = await fetch('/api/shopify/simulate-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        variant_id: document.getElementById('variant-id').value,
                        email: document.getElementById('buyer-email').value,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    renderOrderError(data.error || 'Une erreur est survenue.');
                    return;
                }

                renderOrderResult(data);

                // Générer et recharger la liste pour que la commande apparaisse directement.
                await fetch('/api/shipping-lists/generate', { method: 'POST' });
                await loadShippingList();

                if (data.checkout_url) {
                    const statusUrl = `/api/shopify/checkout-status?email=${encodeURIComponent(data.tracking_email || document.getElementById('buyer-email').value)}`;
                    window.open(data.checkout_url, '_blank', 'noopener,noreferrer');

                    if (checkoutPollingId) {
                        clearInterval(checkoutPollingId);
                    }

                    checkoutPollingId = setInterval(async () => {
                        const statusResponse = await fetch(statusUrl);
                        const statusData = await statusResponse.json();

                        if (statusData.found) {
                            renderOrderResult(data, statusData);
                            await fetch('/api/shipping-lists/generate', { method: 'POST' });
                            await loadShippingList();
                            clearInterval(checkoutPollingId);
                            checkoutPollingId = null;
                        }
                    }, 5000);
                }
            } catch (error) {
                renderOrderError(error.message || 'Erreur inconnue');
            }
        });

        function statusBadgeClass(status) {
            if (status === 'shipped' || status === 'delivered') {
                return 'bg-emerald-200 text-emerald-900 border border-emerald-400';
            }
            if (status === 'packed' || status === 'in_transit') {
                return 'bg-sky-200 text-sky-900 border border-sky-400';
            }
            return 'bg-amber-200 text-amber-900 border border-amber-400';
        }

        function cardToneClass(status) {
            if (status === 'shipped' || status === 'delivered') {
                return 'border-emerald-300 bg-emerald-50';
            }
            if (status === 'packed' || status === 'in_transit') {
                return 'border-sky-300 bg-sky-50';
            }
            return 'border-amber-300 bg-amber-50';
        }

        function cardToneStyle(status) {
            if (status === 'shipped' || status === 'delivered') {
                return 'background-color:#ecfdf3;border-color:#34d399;border-left-color:#059669;';
            }
            if (status === 'packed' || status === 'in_transit') {
                return 'background-color:#eff6ff;border-color:#60a5fa;border-left-color:#2563eb;';
            }
            return 'background-color:#fffbeb;border-color:#f59e0b;border-left-color:#d97706;';
        }

        function statusBadgeStyle(status) {
            const base = 'display:inline-flex;align-items:center;justify-content:center;height:34px;padding:0 16px;white-space:nowrap;line-height:1;flex:0 0 auto;';

            if (status === 'shipped' || status === 'delivered') {
                return base + 'background-color:#bbf7d0;color:#14532d;border-color:#16a34a;';
            }
            if (status === 'packed' || status === 'in_transit') {
                return base + 'background-color:#bfdbfe;color:#1e3a8a;border-color:#2563eb;';
            }
            return base + 'background-color:#fde68a;color:#78350f;border-color:#d97706;';
        }

        function shippedStatusLabel(status) {
            const map = {
                pending: 'En attente',
                packed: 'Emballe',
                shipped: 'Expedie',
                in_transit: 'En transit',
                delivered: 'Livre',
            };
            return map[status] || status;
        }

        function shippingMethodClass(method) {
            if (method === 'express') {
                return 'bg-sky-100 text-sky-800 border border-sky-200';
            }
            if (method === 'priority') {
                return 'bg-rose-100 text-rose-800 border border-rose-200';
            }
            if (method === 'international') {
                return 'bg-violet-100 text-violet-800 border border-violet-200';
            }
            return 'bg-slate-100 text-slate-700 border border-slate-200';
        }

        function formatAddress(address) {
            if (!address || typeof address !== 'object') {
                return 'Adresse indisponible';
            }

            const address1 = address.address1 || '';
            const zip = address.zip || '';
            const city = address.city || '';
            const country = address.country || '';

            return [address1, [zip, city].filter(Boolean).join(' '), country]
                .filter(Boolean)
                .join(', ');
        }

        function renderShippingList(data) {
            const shippingList = data.shipping_list;

            if (!shippingList) {
                currentShippingItems = [];
                shippingResult.innerHTML = `
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center">
                        <p class="text-sm font-semibold text-slate-700">Aucune commande disponible</p>
                        <p class="mt-1 text-sm text-slate-500">Aucune expédition n'a encore été générée.</p>
                    </div>
                `;
                return;
            }

            currentShippingItems = Array.isArray(shippingList.items) ? shippingList.items : [];
            shippingStatusFilter = 'all';
            renderShippingItems();
        }

        function renderShippingItems() {
            const filteredItems = shippingStatusFilter === 'all'
                ? currentShippingItems
                : currentShippingItems.filter((item) => item.status === shippingStatusFilter);

            const cards = filteredItems.map((item) => {
                const shippedStatus = item.shipped_status || 'pending';
                const method = item.shipping_method || 'standard';
                const tracking = item.tracking_number;
                const subStatus = item.subscription_status || 'active';
                const shippedBoxes = Number.isFinite(item.shipped_boxes) ? item.shipped_boxes : 0;
                const totalBoxes = Number.isFinite(item.total_boxes) ? item.total_boxes : 6;
                const progressPercent = Math.min(100, Math.round((shippedBoxes / Math.max(1, totalBoxes)) * 100));

                return `
                    <article class="rounded-xl border border-l-8 p-4 shadow-sm ${cardToneClass(shippedStatus)}" style="${cardToneStyle(shippedStatus)}">
                        <div class="flex flex-nowrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">${item.email || 'Client inconnu'}</p>
                                <p class="text-xs text-slate-500">Abonnement #${item.subscription_id} • Box #${item.box_number}</p>
                            </div>
                            <span class="shrink-0 rounded-full border text-sm font-semibold ${statusBadgeClass(shippedStatus)}" style="${statusBadgeStyle(shippedStatus)}">${shippedStatusLabel(shippedStatus)}</span>
                        </div>

                        <p class="mt-2 text-sm text-slate-700">${item.product_title || 'Produit non renseigne'}</p>

                        <div class="mt-3">
                            <div class="mb-1 flex items-center justify-between text-xs text-slate-600">
                                <span>Abonnement: ${subStatus}</span>
                                <span>${shippedBoxes}/${totalBoxes} boxes</span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-slate-200">
                                <div class="h-2 rounded-full bg-indigo-600" style="width: ${progressPercent}%"></div>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-semibold ${shippingMethodClass(method)}">${method}</span>
                            <span class="whitespace-nowrap rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 border border-slate-200">Tracking: ${tracking}</span>
                        </div>

                        <p class="mt-3 text-xs text-slate-600">${formatAddress(item.address)}</p>
                    </article>
                `;
            }).join('');

            const shippedCount = currentShippingItems.filter((item) => item.status === 'shipped').length;
            const pendingCount = currentShippingItems.length - shippedCount;

            shippingResult.innerHTML = `
                <div class="rounded-xl border border-slate-200 bg-gradient-to-r from-slate-50 to-indigo-50 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Commandes</p>
                            <h3 class="text-lg font-bold text-slate-900">Vue d'ensemble des expéditions</h3>
                        </div>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button data-shipping-filter="all" class="whitespace-nowrap rounded-full bg-indigo-100 px-3 py-1 font-semibold text-indigo-800">${currentShippingItems.length} colis</button>
                            <button data-shipping-filter="shipped" class="whitespace-nowrap rounded-full bg-emerald-200 px-3 py-1 font-semibold text-emerald-900">${shippedCount} expédiées</button>
                            <button data-shipping-filter="pending" class="whitespace-nowrap rounded-full bg-amber-200 px-3 py-1 font-semibold text-amber-900">${pendingCount} en attente</button>
                        </div>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    ${cards || '<div class="md:col-span-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-slate-600">Aucun résultat pour ce filtre.</div>'}
                </div>
            `;

            document.querySelectorAll('[data-shipping-filter]').forEach((button) => {
                button.addEventListener('click', () => setShippingFilter(button.dataset.shippingFilter));
            });

            document.querySelectorAll('[data-shipping-filter]').forEach((button) => {
                const isActive = button.dataset.shippingFilter === shippingStatusFilter;
                button.classList.toggle('ring-2', isActive);
                button.classList.toggle('ring-offset-1', isActive);
                button.classList.toggle('ring-indigo-400', isActive);
                button.classList.toggle('shadow-sm', isActive);
                button.classList.toggle('scale-105', isActive);
            });
        }

        async function loadShippingList() {
            shippingResult.innerHTML = `
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-sm text-slate-600">Chargement des commandes...</p>
                </div>
            `;

            try {
                const response = await fetch('/api/shipping-lists/latest');
                const data = await response.json();
                renderShippingList(data);
            } catch (error) {
                shippingResult.innerHTML = `
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <p class="text-sm font-semibold text-rose-900">Impossible de charger les commandes.</p>
                        <p class="mt-1 text-sm text-rose-700">${error.message || 'Erreur inconnue'}</p>
                    </div>
                `;
            }
        }

        document.getElementById('load-shipping').addEventListener('click', loadShippingList);

    </script>
</body>
</html>
