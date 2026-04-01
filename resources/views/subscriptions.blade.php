<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tableau des Abonnements</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 text-slate-900">
    <main class="mx-auto max-w-6xl p-6">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-4xl font-bold text-slate-900">📦 Tableau des Abonnements</h1>
            <p class="mt-2 text-slate-600">Suivi des commandes récurrentes, des échéances et des expéditions.</p>
        </div>

        <!-- Actions -->
        <div class="mb-6 flex flex-wrap gap-3">
            <button id="load-all" class="rounded-lg bg-blue-600 px-6 py-2 font-semibold text-white hover:bg-blue-700 transition">
                🔄 Actualiser
            </button>
            <button id="generate-test" class="rounded-lg bg-green-600 px-6 py-2 font-semibold text-white hover:bg-green-700 transition">
                ✨ Générer des commandes
            </button>
            <button id="refresh" class="rounded-lg bg-slate-600 px-6 py-2 font-semibold text-white hover:bg-slate-700 transition">
                ↻ Rafraîchissement auto
            </button>
        </div>

        <!-- Stats Summary -->
        <div id="stats" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="rounded-lg bg-white p-4 border border-slate-200 shadow-sm">
                <div class="text-2xl font-bold text-blue-600" id="stat-total">0</div>
                <div class="text-sm text-slate-600">Total abonnements</div>
            </div>
            <div class="rounded-lg bg-white p-4 border border-slate-200 shadow-sm">
                <div class="text-2xl font-bold text-green-600" id="stat-active">0</div>
                <div class="text-sm text-slate-600">Actifs</div>
            </div>
            <div class="rounded-lg bg-white p-4 border border-slate-200 shadow-sm">
                <div class="text-2xl font-bold text-orange-600" id="stat-paused">0</div>
                <div class="text-sm text-slate-600">Pausés</div>
            </div>
            <div class="rounded-lg bg-white p-4 border border-slate-200 shadow-sm">
                <div class="text-2xl font-bold text-purple-600" id="stat-completed">0</div>
                <div class="text-sm text-slate-600">Complétés</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="mb-6 flex flex-wrap gap-2">
            <button class="filter-btn rounded-full bg-slate-200 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-300 transition" data-filter="all">
                Tous
            </button>
            <button class="filter-btn rounded-full bg-slate-200 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-300 transition" data-filter="active">
                ✓ Actifs
            </button>
            <button class="filter-btn rounded-full bg-slate-200 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-300 transition" data-filter="paused">
                ⏸ Pausés
            </button>
            <button class="filter-btn rounded-full bg-slate-200 px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-300 transition" data-filter="completed">
                ✓ Complétés
            </button>
        </div>

        <!-- Subscriptions Grid -->
        <div id="subscriptions-container" class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-lg bg-white p-6 text-center text-slate-500">
                Chargement...
            </div>
        </div>

        <!-- Loading State -->
        <div id="loading" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 text-center">
                <div class="animate-spin w-8 h-8 border-4 border-slate-300 border-t-blue-600 rounded-full mx-auto"></div>
                <p class="mt-4 text-slate-600">Génération des données...</p>
            </div>
        </div>
    </main>

    <script>
        let currentFilter = 'all';
        let autoRefreshId = null;
        let allSubscriptions = [];

        // ===== UTILITIES =====
        function showLoading(show = true) {
            document.getElementById('loading').classList.toggle('hidden', !show);
        }

        function getStatusBadge(status) {
            const badges = {
                'active': { icon: '✓', color: 'bg-green-100 text-green-800', label: 'Actif' },
                'paused': { icon: '⏸', color: 'bg-orange-100 text-orange-800', label: 'Pausé' },
                'completed': { icon: '✓', color: 'bg-purple-100 text-purple-800', label: 'Complété' },
            };
            const badge = badges[status] || badges['active'];
            return `<span class="inline-block rounded-full ${badge.color} px-3 py-1 text-xs font-semibold">${badge.icon} ${badge.label}</span>`;
        }

        function getShippingMethodBadge(method) {
            const colors = {
                'standard': 'bg-slate-100 text-slate-800',
                'express': 'bg-blue-100 text-blue-800',
                'priority': 'bg-red-100 text-red-800',
                'international': 'bg-indigo-100 text-indigo-800',
            };
            const label = {
                'standard': '📦 Standard',
                'express': '⚡ Express',
                'priority': '🚀 Priority',
                'international': '🌍 International',
            };
            const color = colors[method] || colors['standard'];
            return `<span class="inline-block rounded px-2 py-1 text-xs font-medium ${color}">${label[method]}</span>`;
        }

        function isBoxesDue(nextShipment) {
            const next = new Date(nextShipment);
            const today = new Date();
            return next <= today;
        }

        // ===== LOAD SUBSCRIPTIONS =====
        async function loadSubscriptions() {
            try {
                const response = await fetch('/api/subscriptions');
                const data = await response.json();

                allSubscriptions = data.subscriptions || [];
                renderSubscriptions();
                updateStats();
            } catch (error) {
                console.error('Erreur chargement:', error);
                document.getElementById('subscriptions-container').innerHTML =
                    '<div class="col-span-full text-center text-red-600">Erreur de chargement</div>';
            }
        }

        // ===== RENDER SUBSCRIPTIONS =====
        function renderSubscriptions() {
            const filtered = currentFilter === 'all'
                ? allSubscriptions
                : allSubscriptions.filter(s => s.status === currentFilter);

            if (filtered.length === 0) {
                document.getElementById('subscriptions-container').innerHTML =
                    '<div class="col-span-full text-center py-12 text-slate-500">Aucun abonnement trouvé</div>';
                return;
            }

            const html = filtered.map(sub => `
                <div class="rounded-lg bg-white border border-slate-200 shadow-md hover:shadow-lg transition overflow-hidden">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-slate-50 to-slate-100 p-4 border-b border-slate-200">
                        <div class="flex justify-between items-start mb-2">
                            <div>
                                <h3 class="font-bold text-lg">${escapeHtml(sub.email)}</h3>
                                <p class="text-sm text-slate-600">${escapeHtml(sub.product_title)}</p>
                            </div>
                            ${getStatusBadge(sub.status)}
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="p-4 space-y-4">
                        <!-- Shipping Method -->
                        <div>
                            <span class="text-xs text-slate-600">Méthode d'expédition</span>
                            <div class="mt-1">
                                ${getShippingMethodBadge(sub.shipping_method)}
                            </div>
                        </div>

                        <!-- Progress -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-semibold text-slate-700">Progression</span>
                                <span class="text-sm font-bold text-slate-900">${sub.shipped_boxes}/${sub.total_boxes}</span>
                            </div>
                            <div class="w-full bg-slate-200 rounded-full h-3">
                                <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full"
                                     style="width: ${sub.progress_percent}%"></div>
                            </div>
                            <div class="text-xs text-slate-600 mt-1">${sub.progress_percent}% complété</div>
                        </div>

                        <!-- Next Shipment -->
                        <div>
                            <div class="flex justify-between">
                                <span class="text-sm text-slate-600">Prochaine expédition</span>
                                <span class="text-sm font-semibold ${isBoxesDue(sub.next_shipment_at) ? 'text-red-600' : 'text-green-600'}">
                                    ${sub.next_shipment_at}
                                    ${isBoxesDue(sub.next_shipment_at) ? '⚠️ DÜE' : ''}
                                </span>
                            </div>
                        </div>

                        <!-- Address Preview -->
                        <div class="text-xs text-slate-600 bg-slate-50 p-2 rounded border border-slate-200">
                            <p class="font-semibold">📍 Adresse</p>
                            <p>${escapeHtml(sub.shipping_address.address1)}</p>
                            <p>${sub.shipping_address.zip} ${escapeHtml(sub.shipping_address.city)}</p>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2 pt-2">
                            <button class="flex-1 rounded bg-slate-600 px-3 py-2 text-sm text-white font-medium hover:bg-slate-700 transition"
                                    onclick="viewDetails(${sub.id})">
                                Détails
                            </button>
                            <button class="flex-1 rounded bg-blue-600 px-3 py-2 text-sm text-white font-medium hover:bg-blue-700 transition"
                                    onclick="viewShippingItems(${sub.id})">
                                📦 Items
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');

            document.getElementById('subscriptions-container').innerHTML = html;
        }

        // ===== UPDATE STATS =====
        function updateStats() {
            const total = allSubscriptions.length;
            const active = allSubscriptions.filter(s => s.status === 'active').length;
            const paused = allSubscriptions.filter(s => s.status === 'paused').length;
            const completed = allSubscriptions.filter(s => s.status === 'completed').length;

            document.getElementById('stat-total').textContent = total;
            document.getElementById('stat-active').textContent = active;
            document.getElementById('stat-paused').textContent = paused;
            document.getElementById('stat-completed').textContent = completed;
        }

        // ===== GENERATE TEST DATA =====
        document.getElementById('generate-test').addEventListener('click', async () => {
            if (!confirm('Générer 5 commandes ?')) return;

            showLoading(true);
            try {
                const response = await fetch('/api/test/generate', { method: 'POST' });
                const data = await response.json();
                alert(data.message || 'Commandes générées');
                await loadSubscriptions();
            } catch (error) {
                alert('Erreur: ' + error.message);
            } finally {
                showLoading(false);
            }
        });

        // ===== FILTERS =====
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach(b => {
                    b.classList.toggle('bg-slate-300', b === btn);
                    b.classList.toggle('bg-slate-200', b !== btn);
                });
                currentFilter = btn.dataset.filter;
                renderSubscriptions();
            });
        });

        // ===== ACTION BUTTONS =====
        document.getElementById('load-all').addEventListener('click', loadSubscriptions);

        document.getElementById('refresh').addEventListener('click', () => {
            if (autoRefreshId) {
                clearInterval(autoRefreshId);
                autoRefreshId = null;
                document.getElementById('refresh').classList.remove('bg-green-600');
                document.getElementById('refresh').classList.add('bg-slate-600');
                return;
            }
            autoRefreshId = setInterval(loadSubscriptions, 5000);
            document.getElementById('refresh').classList.remove('bg-slate-600');
            document.getElementById('refresh').classList.add('bg-green-600');
        });

        // ===== DETAIL VIEWS =====
        function viewDetails(subscriptionId) {
            const sub = allSubscriptions.find(s => s.id === subscriptionId);
            if (!sub) return;

            const details = `
ID: ${sub.id}
Email: ${sub.email}
Produit: ${sub.product_title}
Statut: ${sub.status}
Boxes: ${sub.shipped_boxes}/${sub.total_boxes}
Méthode: ${sub.shipping_method}
Prochaine expédition: ${sub.next_shipment_at}
Créé: ${sub.created_at}

Adresse:
${sub.shipping_address.address1}
${sub.shipping_address.zip} ${sub.shipping_address.city}
${sub.shipping_address.country}
            `;
            alert(details);
        }

        async function viewShippingItems(subscriptionId) {
            try {
                const response = await fetch(`/api/shipping-lists/latest`);
                const data = await response.json();
                const items = data.shipping_list?.items?.filter(i => i.subscription_id === subscriptionId) || [];

                if (items.length === 0) {
                    alert('Aucun item d\'expédition pour cette subscription.\nLancer d\'abord: php artisan app:generate-shipping-list');
                    return;
                }

                const itemsText = items.map((item, i) =>
                    `[Box #${item.box_number}] ${item.shipped_status} | Tracking: ${item.tracking_number || 'N/A'}`
                ).join('\n');

                alert(`Items d'expédition:\n\n${itemsText}`);
            } catch (error) {
                alert('Erreur: ' + error.message);
            }
        }

        function escapeHtml(text) {
            return (text || '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char]));
        }

        // ===== INIT =====
        loadSubscriptions();
    </script>
</body>
</html>
