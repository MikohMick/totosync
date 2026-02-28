# ToToSync — WooCommerce POS Product Sync

Syncs featured products from a POS API into WooCommerce every 30 minutes.
Supports simple products, variable products (Colour + Measurement attributes),
images, prices, and live stock levels.

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
   /wp-content/plugins/totosync/sync.php
   /wp-content/plugins/totosync/script.js
   ```
2. Go to **WordPress Admin → Plugins** and activate **ToToSync**.
3. After activation the plugin schedules itself to run every 30 minutes via
   WP-Cron. Continue to step 3 below to switch to a reliable server cron
   (strongly recommended for production).

---

## Setup: Server Cron (Recommended)

WP-Cron only fires when someone visits your site. On low-traffic sites syncs
can be missed or delayed. A real server cron guarantees the sync runs every
30 minutes regardless of traffic.

### Step 1 — Disable WP-Cron

Add the following line to `wp-config.php` **above** the `/* That's all, stop editing! */` comment:

```php
define( 'DISABLE_WP_CRON', true );

/* That's all, stop editing! Happy publishing. */
```

### Step 2 — Add a server cron job

Open your crontab:
```bash
crontab -e
```

Add **one** of the following lines:

**Option A — PHP CLI** *(fastest, recommended)*
```bash
*/30 * * * * php /var/www/html/wp-content/plugins/totosync/sync.php >> /var/log/totosync.log 2>&1
```

**Option B — curl WP-Cron endpoint** *(use if PHP CLI is unavailable)*
```bash
*/30 * * * * curl -s https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Replace `/var/www/html` with your actual WordPress root path and
`your-site.com` with your actual domain.

> **Note:** After adding `DISABLE_WP_CRON = true`, the admin panel will show
> **"Not scheduled"** in red for the next auto-sync time. This is normal —
> the server cron is now in charge.

### Step 3 — Verify the cron is set

```bash
crontab -l
```

You should see the line you just added.

---

## Usage

### Automatic sync

Once the server cron is configured the plugin runs silently every 30 minutes
with no further action required. The result of each run is visible in the
**Last Sync Log** on the admin page.

### Manual sync (admin panel)

1. Go to **WordPress Admin → ToToSync**.
2. Check the **Status** card to see the last sync time and the API endpoint
   being used.
3. Click **Sync Now** to trigger an immediate sync.
4. A progress bar shows how many products have been processed. Do not close
   the page until it completes.

---

## How It Works

| Scenario | What the plugin does |
|---|---|
| Product exists in API | Updates price, stock, category, and description |
| Product is new | Creates it in WooCommerce and downloads its image |
| Product removed from API | Moves it to Trash in WooCommerce |
| Image URL is a private IP (192.168.x.x) | Skips the image; product is still synced |
| Image URL is a public FTP URL | Converts to HTTP and downloads it |
| Colour + Measurement fields present | Creates/updates a variable product with attributes |
| No Colour or Measurement | Creates/updates a simple product |

---

## Troubleshooting

**Admin page shows "Not scheduled" in red**
Expected when `DISABLE_WP_CRON` is set to `true`. Verify your server cron is
active with `crontab -l`.

**Sync log shows "API returned HTTP 5xx" or connection errors**
The POS server is unreachable. Check that the API URL shown on the admin page
is accessible from your server:
```bash
curl -I http://shop.ruelsoftware.co.ke/api/FeaturedProducts/197.248.191.179
```

**Products are created but have no images**
The API is returning private IP image URLs (e.g. `ftp://192.168.x.x/...`).
Images will be attached automatically on the next sync once the POS server
starts serving them from a public IP.

**Sync log is empty or shows stale data**
If you disabled WP-Cron but have not set up a server cron yet, the sync will
not run automatically. Add the cron job from the Setup section above.

---

## Changelog

### 2.1.0
- Handle private-IP image URLs gracefully (sync product, skip image)
- Convert `ftp://` image URLs to `http://` for public-IP POS servers
- Trash products/variations that disappear from the API
- Restore trashed products automatically when they reappear in the API
- Add batched AJAX progress bar for manual sync

### 2.0.0
- Variable product support with Colour and Measurement attributes
- Automatic 30-minute WP-Cron schedule on activation

### 1.0.0
- Initial release

---

## Support

Open an issue on the [GitHub repository](https://github.com/MikohMick/totosync).
