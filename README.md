# WooCommerce POS Product Importer

This plugin allows you to import products and their variations from a POS (Point of Sale) API into your WooCommerce store. It supports importing both simple and variable products, along with their variations, prices, and SKUs.

## Features

- Import products from a POS API in JSON format
- Import variable products and their variations
- Import single products
- Skip products and variations without images
- Update prices and add missing SKUs for products and variations
- Update product data based on the product name from the JSON
- Two import methods:
  1. From the WordPress admin area via the "ToToSync" menu
  2. Via a CRON job targeting `/totosync/sync.php`

## Installation

1. Upload the plugin files to the `/wp-content/plugins/totosync` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

## Usage

### Import from Admin Area

1. Go to `WooCommerce -> ToToSync` in your WordPress admin area.
2. Click the "Import" button to start the import process.
3. Do not close the page during the import process. Wait for the import to complete successfully.

### Import via CRON

1. Set up a CRON job to hit the `/totosync/sync.php` file on your website periodically.
2. The plugin will automatically import the products from the POS API based on the CRON schedule.

## Notes

- The plugin updates product data based on the product name from the JSON. If a product with the same name already exists in your store, it will be updated with the new data.
- Products and variations without images will be skipped during the import process.
- Missing SKUs for products and variations will be automatically generated and added during the import.

## Support

If you encounter any issues or have questions regarding the plugin, please open a new issue on our [GitHub repository](https://github.com/MikohMick/totosync).

## Changelog

### 1.0.0
- Initial release
