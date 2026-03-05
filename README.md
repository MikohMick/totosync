# ToToSync — WooCommerce POS Product Sync

Syncs featured products from a POS API into WooCommerce on demand.
Handles simple products, variable products (Colour + Measurement attributes),
images, prices, stock levels, and automatic trash/restore when items appear
or disappear from the API.

---

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 5.8+ |
| WooCommerce | 5.0+ |
| PHP | 7.4+ |

---

## Installation

1. Upload the `totosync` folder to `/wp-content/plugins/totosync/`
   ```
   /wp-content/plugins/totosync/totosync.php
   /wp-content/plugins/totosync/sync-listener.php
   /wp-content/plugins/totosync/script.js
   ```
2. Go to **WordPress Admin → Plugins** and activate **ToToSync**.
3. That's it — no server cron or extra configuration required.

---

## Usage

1. Go to **WordPress Admin → ToToSync**.
2. The **Status** card shows when the last sync ran and the API endpoint in use.
3. Click **Sync Now** to pull the latest products from the POS.
4. A progress bar tracks how many products have been processed.
5. The **Last Sync Log** below shows what was created, updated, or trashed.

The button responds instantly — the sync runs in the background so you can
navigate away at any time and it will still complete.

---

## How It Works

| Scenario | What the plugin does |
|---|---|
| Product exists in API | Updates price, stock, category, and description |
| Product is new | Creates it in WooCommerce and downloads its image |
| Product removed from API | Moves it to Trash in WooCommerce |
| Trashed product reappears | Restored automatically on the next sync |
| Image URL is a private IP (192.168.x.x) | Skips the image; product is still synced |
| Image URL is a public FTP URL | Converts to HTTP and downloads it |
| Colour or Measurement field present | Creates/updates a variable product with attributes |
| No Colour and no Measurement | Creates/updates a simple product |
| Multiple API items share the same name | Grouped under one parent variable product; each colour/measurement combo becomes a separate variation |

### Variable products

Items that share an `itemName` in the API (e.g. *Cotton Roampers Set Of 3*)
are automatically grouped under a single WooCommerce variable product.
Each unique combination of `colour` + `measurement` becomes its own variation
with its own SKU, price, and stock level.

The plugin detects existing variations by matching the stored attribute slugs
directly from the database — not from the WooCommerce object cache — so all
variations are found and updated correctly even when many items with the same
name are processed in a single sync run.

---

## Troubleshooting

**Sync log shows "API returned HTTP 5xx" or connection errors**
The POS server is unreachable. Check the API URL on the admin page is
accessible from your server:
```bash
curl -I http://shop.ruelsoftware.co.ke/api/FeaturedProducts/197.248.191.179
```

**Products are created but have no images**
The API is returning private-IP image URLs (e.g. `ftp://192.168.x.x/...`).
Images will be attached automatically on the next sync once the POS server
starts serving them from a public IP.

**Sync Now button spins forever / sync never starts**
Deactivate and reactivate the plugin to regenerate the listener secret, then
try again. If it still hangs, check that `sync-listener.php` is present in
the plugin folder and readable by the web server.

---

## Changelog

### 2.2.1
- Fixed: multiple variations of the same parent product (same `itemName`,
  different colour/measurement) were not all being created. The children scan
  now queries the database directly instead of using the WC object cache, and
  compares attribute slugs rather than term labels, so every variation is
  matched correctly during a single sync run.
- `WC_Product_Variable::sync()` called after each variation save to keep
  the parent's price range and stock status up to date immediately.

### 2.2.0
- Removed WP-Cron entirely — sync is manual-only via Sync Now button.
- Sync Now button now responds instantly (non-blocking listener via
  `sync-listener.php`); the old flush/fastcgi_finish_request approach is gone.
- Items with only colour OR only measurement now correctly become variable
  products instead of simple products.
- Colour and measurement attributes handled independently — missing one no
  longer prevents the other from being registered.
- Existing simple products auto-converted to variable when a variation arrives.
- New variations explicitly set to `publish` status.
- Empty attribute postmeta no longer written (prevents WooCommerce treating
  all variations as matching "any").

### 2.1.3
- Clear transients on deactivation; full cleanup on uninstall.

### 2.1.0
- Handle private-IP image URLs gracefully (sync product, skip image).
- Convert `ftp://` image URLs to `http://` for public-IP POS servers.
- Trash products/variations that disappear from the API.
- Restore trashed products automatically when they reappear in the API.

### 2.0.0
- Variable product support with Colour and Measurement attributes.

### 1.0.0
- Initial release.

---

## Support

Open an issue on the [GitHub repository](https://github.com/MikohMick/totosync).
