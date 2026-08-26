=== AEC Market – Skills & Programs Marketplace ===
Contributors: wpaecmarket
Tags: marketplace, multivendor, woocommerce, licensing, services
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A multi-vendor marketplace for AEC/BIM, Excel and AI builders: digital products with license keys plus tiered services, built on WooCommerce.

== Description ==

AEC Market turns a WooCommerce store into a multi-vendor skills marketplace where independent builders — AEC/BIM specialists, Excel/automation experts and AI script/tool authors — list and sell **digital products** (scripts, Excel templates, Revit/IFC add-ins, GPTs) and **services** (custom work, consulting) side by side.

= Features =

* **Vendor onboarding** – front-end application form, manual or automatic approval, vendor role with limited capabilities.
* **Vendor dashboard** – front-end dashboard for products, orders, earnings and store settings; no wp-admin access needed.
* **Digital products with licensing** – downloadable products generate license keys on purchase, with per-license activation limits and a public REST API (`wpaec/v1`) for activate / deactivate / validate checks from your sold scripts and add-ins.
* **Tiered services** – Basic / Standard / Premium packages with per-tier pricing, descriptions and delivery times. Tier prices are always re-validated server-side.
* **Commissions** – configurable platform commission (global and per-vendor), recorded per line item when orders complete, automatically voided on refunds and cancellations.
* **Payout tracking** – vendors store PayPal / Stripe Connect payout details; admins review pending earnings and mark commissions paid.
* **Marketplace catalog** – "Programs & Scripts" and "Services" category trees created on activation; filter any shop archive with `?listing_type=program` or `?listing_type=service`; "Sold by" attribution and public vendor store pages.
* **Emails** – notifications for new applications, approvals and new sales.
* **Standards-friendly** – HPOS compatible, translation-ready, theme-overridable templates (`yourtheme/aec-market/…`), extensible via actions and filters.

= Shortcodes =

* `[wpaec_vendor_registration]` – vendor application form.
* `[wpaec_vendor_dashboard]` – vendor dashboard.
* `[wpaec_vendor_store]` – public vendor store (`?vendor={id}` or `vendor` attribute).

Pages containing these shortcodes are created automatically on activation.

= License REST API =

* `POST /wp-json/wpaec/v1/license/activate` – body: `license_key`, `instance`.
* `POST /wp-json/wpaec/v1/license/deactivate` – body: `license_key`, `instance`.
* `GET /wp-json/wpaec/v1/license/validate?license_key=…`

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `aec-market` folder to `/wp-content/plugins/` and activate the plugin.
3. Review the settings under **AEC Market → Settings** (commission rate, approval mode, allowed upload types).
4. Point vendors at the automatically created *Become a Vendor* page.
5. Approve applications under **AEC Market → Vendors**.

== Frequently Asked Questions ==

= Does it split payments at checkout? =

No. The plugin records commissions per vendor line item and gives admins a payout ledger (mark-as-paid workflow). Vendors save their PayPal email or Stripe Connect account ID so you can pay them out via your processor of choice. A Stripe Connect auto-split integration can be layered on via the `wpaecmarket_commission_recorded` action.

= Are uploaded files safe? =

Vendor uploads are restricted to an admin-controlled extension whitelist; executable and script types (php, exe, js, svg, …) can never be whitelisted.

= Is my data deleted on uninstall? =

No. Commissions, licenses and vendor data are preserved unless you set `remove_data_on_uninstall` in the `wpaecmarket_settings` option before deleting the plugin.

== Changelog ==

= 1.1.3 =
* New: `[aec_forge_pricing]` shortcode — a dedicated credit-pricing page with pack cards and a per-tool cost table.

= 1.1.2 =
* New: credit packs are filed under a dedicated "AI Credits" shop category, separate from vendor marketplace products.

= 1.1.1 =
* New: AEC Forge–branded graphics for the tools dashboard — a hero banner, per-tool icons, and richer hover cards.

= 1.1.0 =
* New: AEC Forge Tools — pay-per-use AI tools for the tedious GC paperwork (RFI draft generator, submittal-log review, G702/G703 pay-app assembly, cost-exposure report).
* New: credits wallet with an append-only ledger, free-trial credits for new accounts, and idempotent credit grants on completed WooCommerce orders.
* New: credit packs auto-synced to virtual WooCommerce products; per-tool credit cost and Claude model overrides.
* New: `[aec_forge_tools]` and `[aec_forge_tool key="..."]` shortcodes for the tools dashboard and single-tool pages, plus dependency-free .docx/.xlsx deliverables.
* Note: requires an Anthropic API key (set under AEC Market → Forge Tools) for the AI tools to run.

= 1.0.0 =
* Initial release: vendor onboarding, front-end dashboard, licensing with REST activation API, tiered services, commissions and payout tracking.
