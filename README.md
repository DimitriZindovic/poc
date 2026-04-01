# Shopify Test MVP - Abonnement 6 Boxes + Supabase

Ce projet est un MVP Laravel pour valider le flux complet:

1. commande Shopify de test (produit abonnement),
2. ingestion de la commande,
3. creation d'un abonnement en base,
4. generation manuelle d'une liste d'expedition (cron lance au terminal).

## Architecture

- Shopify (store partenaire de test): source des commandes.
- Laravel: logique metier, webhook, simulation de commande, cron manuel.
- Supabase Postgres: base de donnees metier.
- Front de test: page unique pour piloter le MVP.

Le front appelle les APIs Laravel. Laravel appelle Shopify, puis persiste dans Supabase.

## Endpoints exposes

- `GET /` : front de test.
- `GET /api/shopify/products` : liste des produits Shopify.
- `POST /api/shopify/simulate-order` : cree une commande Shopify test (paid) et cree les abonnements.
- `POST /api/shopify/webhooks/orders-paid` : webhook Shopify orders/paid.
- `GET /api/shipping-lists/latest` : derniere liste d'expedition generee.

## Commande CRON (manuelle)

```bash
php artisan app:generate-shipping-list
```

Mode simulation sans ecriture:

```bash
php artisan app:generate-shipping-list --dry-run
```

## 1) Setup Supabase

### SQL a executer

Executer le script:

- [database/supabase/schema.sql](database/supabase/schema.sql)

dans SQL Editor Supabase.

### Variables d'environnement Laravel

Dans [.env](.env), definir:

- `DB_CONNECTION=pgsql`
- `DB_HOST` (host Supabase, idealement pooler)
- `DB_PORT` (`6543` avec pooler ou `5432` direct)
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Puis vider le cache config:

```bash
php artisan config:clear
```

## 2) Setup Shopify Partner (store de test)

### A. Creer le produit abonnement

Dans Shopify Admin (store test):

1. Creer un produit `Abonnement 6 boxes`.
2. S'assurer qu'il a au moins une variante publiee.
3. Recuperer le `Variant ID` (utile pour la simulation).

### B. Creer une app custom dans le store

1. `Settings > Apps and sales channels > Develop apps`.
2. Creer une app custom.
3. Activer scopes Admin API minimum:
    - `read_products`
    - `write_orders`
    - `read_orders`
4. Installer l'app.
5. Copier le token Admin API et le mettre dans `.env`:
    - `SHOPIFY_API_PASSWORD`
    - `SHOPIFY_API_KEY` (optionnel ici)
    - `SHOPIFY_API_URL` (`https://<store>.myshopify.com`)
    - `SHOPIFY_API_VERSION`

### C. Webhook orders/paid

1. Dans l'app custom: ajouter webhook topic `orders/paid`.
2. URL webhook:
    - `https://<ton-domaine>/api/shopify/webhooks/orders-paid`
3. Copier le secret de signature webhook vers `.env`:
    - `SHOPIFY_WEBHOOK`

Pour un test local, utiliser un tunnel (`ngrok` ou cloudflared) vers Laravel.

## 3) Demarrage local

```bash
composer install
npm install
php artisan serve
npm run dev
```

Ouvrir:

- `http://localhost:8000/`

## 4) Scenario de test complet

1. Ouvrir `GET /`.
2. Cliquer `Charger les produits`.
3. Prendre un `Variant ID` du produit abonnement.
4. Remplir email + variant puis `Simuler commande`.
5. Lancer le cron manuel:

```bash
php artisan app:generate-shipping-list
```

1. Cliquer `Charger la derniere liste` pour verifier les items d'expedition.

## Notes metier MVP

- Un line item est considere abonnement si le titre contient `abonnement` ou `box`.
- Une souscription est creee avec `total_boxes = 6`.
- Le cron genere 1 item d'expedition par souscription eligible.

- Verifications faites avant generation:

1. adresse complete (`address1`, `city`, `zip`, `country`)
2. statut actif
3. pas deja complete (`shipped_boxes < total_boxes`)
