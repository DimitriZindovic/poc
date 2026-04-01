<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shopify Test - Subscriptions</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-5xl p-6">
        <h1 class="text-2xl font-bold">MVP Shopify Test: Abonnement 6 boxes</h1>
        <p class="mt-2 text-sm text-slate-600">
            Cette page permet de tester le flux: produits Shopify -> achat test -> generation manuelle de la liste d'expedition.
        </p>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">1) Produits Shopify</h2>
            <button id="load-products" class="mt-3 rounded bg-slate-900 px-4 py-2 text-white">Charger les produits</button>
            <ul id="products" class="mt-4 space-y-2 text-sm"></ul>
        </section>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">2) Simuler achat abonnement</h2>
            <form id="simulate-order-form" class="mt-3 grid gap-3 md:grid-cols-3">
                <input id="variant-id" class="rounded border border-slate-300 px-3 py-2" type="number" placeholder="Variant ID Shopify" required>
                <input id="buyer-email" class="rounded border border-slate-300 px-3 py-2" type="email" placeholder="email client" required>
                <button class="rounded bg-emerald-700 px-4 py-2 text-white" type="submit">Simuler commande</button>
            </form>
            <pre id="simulate-result" class="mt-3 overflow-auto rounded bg-slate-950 p-3 text-xs text-green-200"></pre>
        </section>

        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">3) Voir derniere liste d'expedition</h2>
            <p class="mt-1 text-sm text-slate-600">Apres un fake achat, lance la commande CRON dans le terminal puis recharge ici.</p>
            <button id="load-shipping" class="mt-3 rounded bg-indigo-700 px-4 py-2 text-white">Charger la derniere liste</button>
            <pre id="shipping-result" class="mt-3 overflow-auto rounded bg-slate-950 p-3 text-xs text-cyan-200"></pre>
        </section>
    </main>

    <script>
        const productsList = document.getElementById('products');
        const simulateResult = document.getElementById('simulate-result');
        const shippingResult = document.getElementById('shipping-result');
        let checkoutPollingId = null;

        document.getElementById('load-products').addEventListener('click', async () => {
            productsList.innerHTML = '<li>Chargement...</li>';

            const response = await fetch('/api/shopify/products');
            const data = await response.json();

            if (!data.products) {
                productsList.innerHTML = '<li>Erreur de chargement.</li>';
                return;
            }

            productsList.innerHTML = data.products.map((product) => {
                const variant = (product.variants && product.variants[0]) ? product.variants[0] : null;
                const variantId = variant ? variant.id : 'N/A';
                return `<li class="rounded border border-slate-200 p-2"><strong>${product.title}</strong><br>Variant ID: ${variantId}</li>`;
            }).join('');
        });

        document.getElementById('simulate-order-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            simulateResult.textContent = 'Simulation en cours...';

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

            if (data.checkout_url) {
                const statusUrl = `/api/shopify/checkout-status?email=${encodeURIComponent(data.tracking_email || document.getElementById('buyer-email').value)}`;
                simulateResult.textContent = JSON.stringify(data, null, 2)
                    + '\n\n👉 Ouvre cette URL pour payer: ' + data.checkout_url
                    + '\n⏳ Suivi automatique en cours via: ' + statusUrl;

                window.open(data.checkout_url, '_blank', 'noopener,noreferrer');

                if (checkoutPollingId) {
                    clearInterval(checkoutPollingId);
                }

                checkoutPollingId = setInterval(async () => {
                    const statusResponse = await fetch(statusUrl);
                    const statusData = await statusResponse.json();

                    if (statusData.found) {
                        simulateResult.textContent = JSON.stringify({
                            checkout: data,
                            ingestion_status: statusData,
                        }, null, 2);
                        clearInterval(checkoutPollingId);
                        checkoutPollingId = null;
                    }
                }, 5000);
            } else {
                simulateResult.textContent = JSON.stringify(data, null, 2);
            }
        });

        document.getElementById('load-shipping').addEventListener('click', async () => {
            shippingResult.textContent = 'Chargement...';

            const response = await fetch('/api/shipping-lists/latest');
            const data = await response.json();
            shippingResult.textContent = JSON.stringify(data, null, 2);
        });
    </script>
</body>
</html>
