<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pilotage Abonnements</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="dashboard-shell">
        <header class="hero-panel">
            <div class="hero-content">
                <div>
                    <h1 class="hero-title">Pilotage commandes & abonnements</h1>
                    <p class="hero-subtitle">Catalogue, création de commande et suivi abonnement sur une interface opérationnelle.</p>
                </div>
                <div class="hero-badge">DZ</div>
            </div>
        </header>

        <section class="dashboard-grid">
            <div class="layout-2">
                <div class="panel panel-sticky">
                    <h2 class="panel-title">Créer une commande</h2>
                    <p class="panel-note">Produit + email client.</p>

                    <form id="simulate-order-form" class="mt-4 space-y-4">
                        <div>
                            <label for="variant-id" class="field-label">Produit</label>
                            <select id="variant-id" class="field-control">
                                <option value="">Charger les produits d'abord</option>
                            </select>
                        </div>
                        <div>
                            <label for="buyer-email" class="field-label">Email client</label>
                            <input id="buyer-email" type="email" placeholder="client@example.fr" class="field-control" required />
                        </div>
                        <button type="submit" class="btn-brand">Créer la commande</button>
                    </form>

                    <div id="simulate-result" class="mt-4 text-sm text-slate-600"></div>
                </div>

                <div class="panel">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="panel-title">Catalogue Shopify</h2>
                        <button id="load-products" class="btn-neutral">Charger les produits</button>
                    </div>
                    <ul id="products" class="products-grid mt-4 text-sm">
                        <li class="product-placeholder">Cliquez sur "Charger les produits".</li>
                    </ul>
                </div>
            </div>

            <section class="panel">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="panel-title">Commandes</h2>
                    <button id="load-orders" class="btn-neutral">Rafraîchir</button>
                </div>
                <div id="orders-result" class="p-0">
                    Cliquez sur "Rafraîchir" pour charger les données.
                </div>
            </section>
        </section>
    </main>
</body>
</html>
