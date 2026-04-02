/**
 * Shipping Dashboard - Gestion des expéditions et commandes
 * Fonctionnalités: produits, commandes, expéditions avec filtrage et état
 */

console.log("✓ shipping-dashboard.js module loaded");

// ============================================================
// STATE & DOM ELEMENTS
// ============================================================

let productsList, variantSelect, simulateResult, shippingResult, ordersResult;

let checkoutPollingId = null;
let shippingStatusFilter = "all";
let currentShippingItems = [];

// Initialiser les références DOM une fois le DOM prêt
function initializeDOMReferences() {
    productsList = document.getElementById("products");
    variantSelect = document.getElementById("variant-id");
    simulateResult = document.getElementById("simulate-result");
    shippingResult = document.getElementById("shipping-result");
    ordersResult = document.getElementById("orders-result");

    console.log("✓ DOM References initialized:", {
        productsList: !!productsList,
        variantSelect: !!variantSelect,
        simulateResult: !!simulateResult,
        shippingResult: !!shippingResult,
        ordersResult: !!ordersResult,
    });
}

// ============================================================
// FORMATTING FUNCTIONS
// ============================================================

/**
 * Formate un montant en devise FR
 */
function formatPrice(value, currency = "EUR") {
    if (value === null || value === undefined || value === "") {
        return "Prix indisponible";
    }

    const numeric = Number(value);
    if (Number.isNaN(numeric)) {
        return `${value} ${currency || ""}`.trim();
    }

    try {
        return new Intl.NumberFormat("fr-FR", {
            style: "currency",
            currency: currency || "EUR",
        }).format(numeric);
    } catch (error) {
        return `${numeric.toFixed(2)} ${currency || ""}`.trim();
    }
}

/**
 * Formate une date en format FR court
 */
function formatDate(value) {
    if (!value) {
        return "N/A";
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat("fr-FR", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(date);
}

/**
 * Échappe les caractères HTML dangereux
 */
function escapeHtml(text) {
    return (text || "").toString().replace(
        /[&<>\"]/g,
        (char) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
            })[char],
    );
}

/**
 * Formate une adresse complète
 */
function formatAddress(address) {
    if (!address || typeof address !== "object") {
        return "Adresse indisponible";
    }

    const address1 = address.address1 || "";
    const zip = address.zip || "";
    const city = address.city || "";
    const country = address.country || "";

    return [address1, [zip, city].filter(Boolean).join(" "), country]
        .filter(Boolean)
        .join(", ");
}

// ============================================================
// STYLING FUNCTIONS - STATUS BADGES & CARDS
// ============================================================

/**
 * Classes Tailwind pour badge de statut
 */
function statusBadgeClass(status) {
    if (status === "shipped" || status === "delivered") {
        return "bg-emerald-200 text-emerald-900 border border-emerald-400";
    }
    if (status === "packed" || status === "in_transit") {
        return "bg-sky-200 text-sky-900 border border-sky-400";
    }
    return "bg-amber-200 text-amber-900 border border-amber-400";
}

/**
 * Classes Tailwind pour la carte (couleur de fond + bordure)
 */
function cardToneClass(status) {
    if (status === "shipped" || status === "delivered") {
        return "border-emerald-300 bg-emerald-50";
    }
    if (status === "packed" || status === "in_transit") {
        return "border-sky-300 bg-sky-50";
    }
    return "border-amber-300 bg-amber-50";
}

/**
 * Styles inline pour la carte (couleurs précises hex)
 */
function cardToneStyle(status) {
    if (status === "shipped" || status === "delivered") {
        return "background-color:#ecfdf3;border-color:#34d399;border-left-color:#059669;";
    }
    if (status === "packed" || status === "in_transit") {
        return "background-color:#eff6ff;border-color:#60a5fa;border-left-color:#2563eb;";
    }
    return "background-color:#fffbeb;border-color:#f59e0b;border-left-color:#d97706;";
}

/**
 * Styles inline pour badge de statut (flex + hauteur fixe)
 */
function statusBadgeStyle(status) {
    const base =
        "display:inline-flex;align-items:center;justify-content:center;height:34px;padding:0 16px;white-space:nowrap;line-height:1;flex:0 0 auto;";

    if (status === "shipped" || status === "delivered") {
        return (
            base +
            "background-color:#bbf7d0;color:#14532d;border-color:#16a34a;"
        );
    }
    if (status === "packed" || status === "in_transit") {
        return (
            base +
            "background-color:#bfdbfe;color:#1e3a8a;border-color:#2563eb;"
        );
    }
    return (
        base + "background-color:#fde68a;color:#78350f;border-color:#d97706;"
    );
}

/**
 * Étiquette française pour statut d'expédition
 */
function shippedStatusLabel(status) {
    const map = {
        pending: "En attente",
        packed: "Emballe",
        shipped: "Expedie",
        in_transit: "En transit",
        delivered: "Livre",
    };
    return map[status] || status;
}

/**
 * Classes Tailwind pour méthode de livraison
 */
function shippingMethodClass(method) {
    if (method === "express") {
        return "bg-sky-100 text-sky-800 border border-sky-200";
    }
    if (method === "priority") {
        return "bg-rose-100 text-rose-800 border border-rose-200";
    }
    if (method === "international") {
        return "bg-violet-100 text-violet-800 border border-violet-200";
    }
    return "bg-slate-100 text-slate-700 border border-slate-200";
}

// ============================================================
// API FUNCTIONS
// ============================================================

/**
 * Charge la liste des produits depuis l'API Shopify
 */
async function loadProducts() {
    console.log("loadProducts() called");

    if (!productsList || !variantSelect) {
        console.error("DOM references not initialized");
        return;
    }

    productsList.innerHTML = "<li>Chargement...</li>";
    variantSelect.innerHTML =
        '<option value="">Chargement des produits...</option>';

    try {
        const response = await fetch("/api/shopify/products");
        const data = await response.json();

        if (!data.products) {
            throw new Error("Données produits indisponibles");
        }

        variantSelect.innerHTML =
            '<option value="">Sélectionner un produit</option>';

        productsList.innerHTML = data.products
            .map((product) => {
                const variant =
                    product.variants && product.variants[0]
                        ? product.variants[0]
                        : null;
                const variantId = variant ? variant.id : "-";

                if (variant && variant.id) {
                    const option = document.createElement("option");
                    option.value = String(variant.id);
                    option.textContent = `${product.title} (${variant.price || "-"} EUR)`;
                    variantSelect.appendChild(option);
                }

                return `<li class="rounded border border-slate-200 p-2"><strong>${product.title}</strong><br>Variant ID: ${variantId}</li>`;
            })
            .join("");

        console.log("✓ Produits chargés");
    } catch (error) {
        console.error("Erreur loadProducts:", error);
        productsList.innerHTML = "<li>Erreur de chargement.</li>";
        variantSelect.innerHTML =
            '<option value="">Aucun produit disponible</option>';
    }
}

/**
 * Charge la liste des commandes
 */
async function loadOrders() {
    console.log("loadOrders() called");

    if (!ordersResult) {
        console.error("ordersResult DOM element not initialized");
        return;
    }

    ordersResult.innerHTML = `
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-600">Chargement des commandes...</p>
        </div>
    `;

    try {
        const response = await fetch("/api/shopify/orders");
        const data = await response.json();
        renderOrders(data);
        console.log("✓ Commandes chargées");
    } catch (error) {
        console.error("Erreur loadOrders:", error);
        ordersResult.innerHTML = `
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-sm font-semibold text-rose-900">Impossible de charger les commandes.</p>
                <p class="mt-1 text-sm text-rose-700">${error.message || "Erreur inconnue"}</p>
            </div>
        `;
    }
}

/**
 * Charge la liste des expéditions/colis
 */
async function loadShippingList() {
    if (!shippingResult) {
        return;
    }

    shippingResult.innerHTML = `
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-600">Chargement des commandes...</p>
        </div>
    `;

    try {
        const response = await fetch("/api/shipping-lists/latest");
        const data = await response.json();
        renderShippingList(data);
    } catch (error) {
        shippingResult.innerHTML = `
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-sm font-semibold text-rose-900">Impossible de charger les commandes.</p>
                <p class="mt-1 text-sm text-rose-700">${error.message || "Erreur inconnue"}</p>
            </div>
        `;
    }
}

/**
 * Soumet le formulaire de simulation de commande
 */
async function submitSimulateOrder(event) {
    event.preventDefault();
    renderOrderPending();

    try {
        const response = await fetch("/api/shopify/simulate-order", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
            },
            body: JSON.stringify({
                variant_id: document.getElementById("variant-id").value,
                email: document.getElementById("buyer-email").value,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            renderOrderError(data.error || "Une erreur est survenue.");
            return;
        }

        renderOrderResult(data);

        // Générer et recharger pour que la commande apparaisse
        await fetch("/api/shipping-lists/generate", { method: "POST" });
        await loadOrders();
        if (shippingResult) {
            await loadShippingList();
        }

        if (data.checkout_url) {
            const email =
                data.tracking_email ||
                document.getElementById("buyer-email").value;
            const statusUrl = `/api/shopify/checkout-status?email=${encodeURIComponent(email)}`;
            window.open(data.checkout_url, "_blank", "noopener,noreferrer");

            if (checkoutPollingId) {
                clearInterval(checkoutPollingId);
            }

            // Polling pour confirmation du paiement
            checkoutPollingId = setInterval(async () => {
                try {
                    const statusResponse = await fetch(statusUrl);
                    const statusData = await statusResponse.json();

                    if (statusData.found) {
                        renderOrderResult(data, statusData);
                        await fetch("/api/shipping-lists/generate", {
                            method: "POST",
                        });
                        await loadOrders();
                        if (shippingResult) {
                            await loadShippingList();
                        }
                        clearInterval(checkoutPollingId);
                        checkoutPollingId = null;
                    }
                } catch (error) {
                    // Continue polling silently
                }
            }, 5000);
        }
    } catch (error) {
        renderOrderError(error.message || "Erreur inconnue");
    }
}

// ============================================================
// RENDER FUNCTIONS - ORDERS
// ============================================================

/**
 * Affiche un message pending pour la création de commande
 */
function renderOrderPending() {
    simulateResult.innerHTML = `
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="text-sm font-semibold text-emerald-900">Création de la commande en cours...</p>
            <p class="mt-1 text-sm text-emerald-700">Validation des informations et préparation du paiement.</p>
        </div>
    `;
}

/**
 * Affiche le résultat succès d'une commande créée
 */
function renderOrderResult(payload, statusPayload = null) {
    const orderId =
        payload.shopify_order_id ||
        statusPayload?.order?.shopify_order_id ||
        "-";
    const subscriptions =
        payload.created_subscriptions ||
        statusPayload?.subscriptions_count ||
        0;
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
            ${paymentLink ? `<a href="${paymentLink}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-block rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Ouvrir le paiement</a>` : ""}
        </div>
    `;
}

/**
 * Affiche une erreur lors de la création de commande
 */
function renderOrderError(message) {
    simulateResult.innerHTML = `
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
            <p class="text-sm font-semibold text-rose-900">Échec de création de commande</p>
            <p class="mt-1 text-sm text-rose-700">${message}</p>
        </div>
    `;
}

/**
 * Rend la liste des commandes avec filtrage et statuts
 */
function renderOrders(data) {
    const orders = Array.isArray(data.orders) ? data.orders : [];

    if (!orders.length) {
        ordersResult.innerHTML = `
            <div class="p-6 text-center text-slate-600">
                <p class="text-sm font-semibold">Aucune commande trouvée</p>
                <p class="mt-1 text-xs text-slate-500">Vérifie Shopify ou la base locale.</p>
            </div>
        `;
        return;
    }

    const rows = orders
        .map((order, idx) => {
            const address = formatAddress(order.shipping_address);
            const price = formatPrice(order.total_price, order.currency);
            const boxesLabel =
                order.subscription_count > 0
                    ? `${order.shipped_boxes || 0}/${order.total_boxes || 0}`
                    : "-";
            const isPaid = order.financial_status === "paid";
            const hasSubscription = order.subscription_count > 0;
            const nextShipment = order.next_shipment_at
                ? formatDate(order.next_shipment_at)
                : "-";

            return `
            <tr class="hover:bg-slate-50 transition-colors">
                <!-- # -->
                <td class="px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-500">${idx + 1}</td>

                <!-- Client -->
                <td class="px-4 py-3 border-b border-slate-200">
                    <p class="font-semibold text-slate-900 text-sm">${escapeHtml(order.customer_name || order.email || "Client")}</p>
                    <p class="text-xs text-slate-500">#${escapeHtml(order.shopify_order_id || "N/A")}</p>
                </td>

                <!-- Date -->
                <td class="px-4 py-3 border-b border-slate-200 text-xs text-slate-600 whitespace-nowrap">${formatDate(order.created_at)}</td>

                <!-- Produit -->
                <td class="px-4 py-3 border-b border-slate-200">
                    <p class="text-sm font-medium text-slate-900">${escapeHtml(order.line_item_title || "N/A")}</p>
                    <p class="text-xs text-slate-500">Qté: ${order.line_item_quantity || 1}</p>
                </td>

                <!-- Adresse -->
                <td class="px-4 py-3 border-b border-slate-200 text-sm text-slate-700">${escapeHtml(address)}</td>

                <!-- Prix -->
                <td class="px-4 py-3 border-b border-slate-200 text-right font-bold text-slate-900 text-sm">${price}</td>

                <!-- Statut Paiement -->
                <td class="px-4 py-3 border-b border-slate-200">
                    <span class="inline-flex items-center gap-1 whitespace-nowrap px-2.5 py-1 rounded-full text-xs leading-none font-semibold ${isPaid ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"}">
                        ${isPaid ? "✓ Payée" : "⏳ En attente"}
                    </span>
                </td>

                <!-- Abonnement -->
                <td class="px-4 py-3 border-b border-slate-200 text-center">
                    ${
                        hasSubscription
                            ? `
                        <div class="inline-flex flex-col items-center text-sm">
                            <span class="font-bold text-indigo-700">${order.subscription_count}</span>
                            <span class="text-xs text-indigo-600">Boxes ${boxesLabel}</span>
                        </div>
                    `
                            : `<span class="text-slate-400 text-sm">-</span>`
                    }
                </td>

                <!-- Prochain envoi -->
                <td class="px-4 py-3 border-b border-slate-200 text-xs text-slate-500 whitespace-nowrap">${nextShipment}</td>
            </tr>
        `;
        })
        .join("");

    ordersResult.innerHTML = `
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-white border-b border-slate-300 sticky top-0">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">#</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Client</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Produit</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Adresse</th>
                        <th class="px-4 py-3 text-right text-xs font-bold text-slate-600 uppercase">Montant</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Paiement</th>
                        <th class="px-4 py-3 text-center text-xs font-bold text-slate-600 uppercase">Abonnement</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-600 uppercase">Prochain envoi</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    ${rows}
                </tbody>
            </table>
        </div>
    `;

    console.log("✓ Commandes affichées (tableau simple)");
}

// ============================================================
// RENDER FUNCTIONS - SHIPPING
// ============================================================

/**
 * Rend la liste des expéditions avec les items actuels
 */
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

    currentShippingItems = Array.isArray(shippingList.items)
        ? shippingList.items
        : [];
    shippingStatusFilter = "all";
    renderShippingItems();
}

/**
 * Rend les items d'expédition filtrés avec cartes colorées
 */
function renderShippingItems() {
    const filteredItems =
        shippingStatusFilter === "all"
            ? currentShippingItems
            : currentShippingItems.filter(
                  (item) => item.status === shippingStatusFilter,
              );

    const cards = filteredItems
        .map((item) => {
            const shippedStatus = item.shipped_status || "pending";
            const method = item.shipping_method || "standard";
            const tracking = item.tracking_number;
            const subStatus = item.subscription_status || "active";
            const shippedBoxes = Number.isFinite(item.shipped_boxes)
                ? item.shipped_boxes
                : 0;
            const totalBoxes = Number.isFinite(item.total_boxes)
                ? item.total_boxes
                : 6;
            const progressPercent = Math.min(
                100,
                Math.round((shippedBoxes / Math.max(1, totalBoxes)) * 100),
            );

            return `
            <article class="rounded-xl border border-l-8 p-4 shadow-sm ${cardToneClass(shippedStatus)}" style="${cardToneStyle(shippedStatus)}">
                <div class="flex flex-nowrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">${item.email || "Client inconnu"}</p>
                        <p class="text-xs text-slate-500">Abonnement #${item.subscription_id} • Box #${item.box_number}</p>
                    </div>
                    <span class="shrink-0 rounded-full border text-sm font-semibold ${statusBadgeClass(shippedStatus)}" style="${statusBadgeStyle(shippedStatus)}">${shippedStatusLabel(shippedStatus)}</span>
                </div>

                <p class="mt-2 text-sm text-slate-700">${item.product_title || "Produit non renseigne"}</p>

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
        })
        .join("");

    const shippedCount = currentShippingItems.filter(
        (item) => item.status === "shipped",
    ).length;
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

    // Attacher les event listeners aux filtres
    document.querySelectorAll("[data-shipping-filter]").forEach((button) => {
        button.addEventListener("click", () =>
            setShippingFilter(button.dataset.shippingFilter),
        );
    });

    // Mettre à jour le style du bouton actif
    updateShippingFilterButtons();
}

/**
 * Change le filtre d'affinage des expéditions
 */
function setShippingFilter(filter) {
    shippingStatusFilter = filter;
    renderShippingItems();
}

/**
 * Met à jour l'apparence des boutons de filtrage d'expéditions
 */
function updateShippingFilterButtons() {
    document.querySelectorAll("[data-shipping-filter]").forEach((button) => {
        const isActive = button.dataset.shippingFilter === shippingStatusFilter;
        button.classList.toggle("ring-2", isActive);
        button.classList.toggle("ring-offset-1", isActive);
        button.classList.toggle("ring-indigo-400", isActive);
        button.classList.toggle("shadow-sm", isActive);
        button.classList.toggle("scale-105", isActive);
    });
}

// ============================================================
// EVENT LISTENERS
// ============================================================

document.addEventListener("DOMContentLoaded", () => {
    console.log("DOMContentLoaded triggered");

    // Initialiser les références DOM d'abord
    initializeDOMReferences();

    if (!variantSelect || !simulateResult || !ordersResult) {
        console.error("❌ Certains éléments DOM obligatoires ne sont pas trouvés");
        return;
    }

    console.log("✓ Attaching event listeners");

    // Charger les produits
    const loadProductsBtn = document.getElementById("load-products");
    if (loadProductsBtn) {
        loadProductsBtn.addEventListener("click", () => {
            console.log("load-products button clicked");
            loadProducts();
        });
    }

    // Auto-charger les produits quand on accède au select
    variantSelect.addEventListener("focus", async () => {
        console.log("variant-select focused");
        if (variantSelect.options.length <= 1) {
            await loadProducts();
        }
    });

    variantSelect.addEventListener("mousedown", async () => {
        console.log("variant-select mousedown");
        if (variantSelect.options.length <= 1) {
            await loadProducts();
        }
    });

    // Soumettre le formulaire de commande
    const orderForm = document.getElementById("simulate-order-form");
    if (orderForm) {
        orderForm.addEventListener("submit", (e) => {
            console.log("simulate-order-form submitted");
            submitSimulateOrder(e);
        });
    }

    // Charger les expéditions et commandes
    const loadShippingBtn = document.getElementById("load-shipping");
    if (loadShippingBtn && shippingResult) {
        loadShippingBtn.addEventListener("click", () => {
            console.log("load-shipping button clicked");
            loadShippingList();
        });
    }

    const loadOrdersBtn = document.getElementById("load-orders");
    if (loadOrdersBtn) {
        loadOrdersBtn.addEventListener("click", () => {
            console.log("load-orders button clicked");
            loadOrders();
        });
    }

    // Charger les commandes au démarrage
    console.log("Loading orders on startup");
    loadOrders();

    console.log("✓ Event listeners attached successfully");
});
