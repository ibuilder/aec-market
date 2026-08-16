# AEC Market – Skills & Programs Marketplace

[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

An Envato-style **multi-vendor marketplace** for AEC/BIM specialists, Excel/automation experts and AI tool authors, built on WooCommerce. Independent builders list and sell **digital products** (scripts, Excel templates, Revit/IFC add-ins, GPTs) with license keys and **tiered services** (Basic / Standard / Premium) side by side.

**Docs & demo page:** https://ibuilder.github.io/aec-market/

## Features

- **Vendor onboarding** — front-end application form, manual or automatic approval, dedicated vendor role with minimal capabilities.
- **Vendor dashboard** — front-end tabs for products, orders, earnings and store settings. Vendors never need wp-admin.
- **Digital products with licensing** — downloadable products generate unique license keys on purchase, with per-license activation limits and a public REST API for activation checks from your sold scripts and add-ins.
- **Tiered services** — Fiverr-style Basic / Standard / Premium packages with per-tier pricing, descriptions and delivery times. Tier prices are always re-validated server-side.
- **Commissions** — configurable platform commission (global default plus per-vendor overrides), recorded per line item on order completion and automatically voided on refunds and cancellations.
- **Payout tracking** — vendors store PayPal / Stripe Connect payout details; admins review pending earnings and mark commissions paid.
- **Marketplace catalog** — "Programs & Scripts" and "Services" category trees seeded on activation, `?listing_type=` archive filtering, "Sold by" attribution and public vendor store pages.
- **Standards-friendly** — passes the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) and full WordPress Coding Standards (WPCS), HPOS-compatible, translation-ready, theme-overridable templates.

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.2+ |
| WooCommerce | 8.0+ |
| PHP | 7.4+ |

## Installation

1. Install and activate [WooCommerce](https://wordpress.org/plugins/woocommerce/).
2. Download the latest `aec-market.zip` from [`dist/`](dist/) (or clone this repo into `wp-content/plugins/aec-market`).
3. Activate **AEC Market – Skills & Programs Marketplace** in *Plugins*.
4. Review **AEC Market → Settings** (commission rate, approval mode, allowed upload types).
5. Point vendors at the automatically created *Become a Vendor* page and approve applications under **AEC Market → Vendors**.

Activation creates the *Vendor Dashboard*, *Become a Vendor* and *Vendor Store* pages, plus the default marketplace category tree.

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[wpaec_vendor_registration]` | Vendor application form |
| `[wpaec_vendor_dashboard]` | Vendor dashboard (products, orders, earnings, settings) |
| `[wpaec_vendor_store]` | Public vendor store — `?vendor={id}` or `vendor` attribute |

## License REST API

Sold scripts and add-ins can phone home to validate their license:

```
POST /wp-json/wpaec/v1/license/activate     { "license_key": "...", "instance": "..." }
POST /wp-json/wpaec/v1/license/deactivate   { "license_key": "...", "instance": "..." }
GET  /wp-json/wpaec/v1/license/validate?license_key=...
```

The license key itself is the credential; no personal data is returned.

## Extending

Key hooks (all prefixed `wpaecmarket_`):

- `wpaecmarket_commission_rate` — filter the commission percentage per vendor.
- `wpaecmarket_commission_recorded` — fires per recorded commission; use it to layer on Stripe Connect auto-splits.
- `wpaecmarket_vendor_applied` / `wpaecmarket_vendor_approved` / `wpaecmarket_vendor_deactivated` — vendor lifecycle.
- `wpaecmarket_vendor_product_saved` — after a vendor saves a listing from the dashboard.
- `wpaecmarket_license_created` — after a license key is generated.

Templates in [`templates/`](templates/) can be overridden by copying them to `yourtheme/aec-market/`.

## Development

```bash
composer global require wp-coding-standards/wpcs
phpcs --standard=phpcs.xml.dist .
```

The [`phpcs.xml.dist`](phpcs.xml.dist) ruleset runs the full WordPress standard. `dist/aec-market.zip` is the WordPress.org submission build — dev files listed in [`.distignore`](.distignore) are excluded from it.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Copyright (C) 2026 AEC Market contributors.
