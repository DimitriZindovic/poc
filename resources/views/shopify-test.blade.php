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
        <header>
            <h1 class="text-2xl font-bold">Pilotage des Abonnements</h1>
            <p class="mt-2 text-sm text-slate-600">Suivez les produits, les commandes et les expéditions depuis un tableau de pilotage unique.</p>
        </header>

        <!-- === Catalogue Shopify === -->
        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">Catalogue Shopify</h2>
            <button id="load-products" class="mt-3 rounded bg-slate-900 px-4 py-2 text-white">Charger les produits</button>
            <ul id="products" class="mt-4 space-y-2 text-sm">
                <li class="rounded border border-dashed border-slate-300 bg-slate-50 p-3 text-slate-600">Cliquez sur "Charger les produits" pour afficher le catalogue.</li>
            </ul>
        </section>

        <!-- === Créer une Commande === -->
        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <h2 class="text-lg font-semibold">Créer une Commande avec Subscription</h2>
            <form id="simulate-order-form" class="mt-4 space-y-4">
                <div>
                    <label for="variant-id" class="block text-sm font-medium">Sélectionner un produit</label>
                    <select id="variant-id" class="mt-2 w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">Charger les produits d'abord</option>
                    </select>
                </div>
                <div>
                    <label for="buyer-email" class="block text-sm font-medium">Email de l'acheteur</label>
                    <input id="buyer-email" type="email" placeholder="client@example.fr" class="mt-2 w-full rounded border border-slate-300 px-3 py-2 text-sm" required />
                </div>
                <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Créer la commande</button>
            </form>
            <p class="mt-2 text-xs text-slate-500 italic">Les abonnements existants dans Shopify seront affichés ci-dessous</p>
            <div id="simulate-result" class="mt-4 text-sm text-slate-600"></div>
        </section>

        <!-- === Commandes === -->
        <section class="mt-6 rounded-xl border border-slate-300 bg-white p-4">
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="text-lg font-semibold">Commandes Shopify</h2>
                <button id="load-orders" class="rounded bg-slate-900 px-4 py-2 text-white text-sm">Rafraîchir</button>
            </div>
            <div id="orders-result" class="rounded-xl border border-slate-200 bg-white p-0">
                Cliquez sur "Rafraîchir" pour charger les données.
            </div>
        </section>

    </main>
</body>
</html>
