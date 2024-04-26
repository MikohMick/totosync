<?php

/*
  Plugin Name: Totosync
  Description: Import products from JSON API into WooCommerce.
  Version: 1.1
  Author: rindradev@gmail.com
 */

// Function to import products from JSON API
function totosync_import_products() {
//    $json_data = file_get_contents('http://shop.ruelsoftware.co.ke/api/featuredproducts/197.248.191.179');
    $json_data = '[{"itemId":2628,"itemName":"SIT ME UP","description":"SIT ME UP","itemCode":"2628","productCategory":"Nursery gear and furniture","price1":3400,"price2":0,"price3":0,"price4":0,"measurement":" Piece","colour":"PEACH","quantity":1,"isFeatured":true,"imageUrls":["http://197.248.191.179/2628/08_49_14.png"]},
	{"itemId":2627,"itemName":"SIT ME UP","description":"SIT ME UP","itemCode":"2627","productCategory":"Nursery gear and furniture","price1":3400,"price2":0,"price3":0,"price4":0,"measurement":" Piece","colour":"PINK AND ORANGE","quantity":1,"isFeatured":true,"imageUrls":["http://197.248.191.179/2627/08_51_02.png"]},
	{"itemId":2626,"itemName":"SIT ME UP","description":"SIT ME UP","itemCode":"2626","productCategory":"Nursery gear and furniture","price1":3400,"price2":0,"price3":0,"price4":0,"measurement":" Piece","colour":"PINK AND GREEN","quantity":2,"isFeatured":true,"imageUrls":["http://197.248.191.179/2626/08_51_27.png"]},
	{"itemId":2629,"itemName":"L PREGNANCY PILLOW","description":"L PREGNANCY PILLOW","itemCode":"2629","productCategory":"Diapering and mother care","price1":3400,"price2":0,"price3":0,"price4":0,"measurement":" Piece","colour":"MAROON","quantity":1,"isFeatured":true,"imageUrls":["http://197.248.191.179/2629/12_21_33.png"]},
	{"itemId":2635,"itemName":"BOTTLE BANK MEDIUM 10204","description":"BOTTLE BANK MEDIUM 10204","itemCode":"2635","productCategory":"Feeding accessories","price1":2000,"price2":0,"price3":0,"price4":0,"measurement":" Piece","colour":"PINK","quantity":0,"isFeatured":true,"imageUrls":["http://197.248.191.179/2635/08_55_08.png"]}]';

    if ($json_data === false) {
        // Handle error
        return;
    }
    totosync_parse_json($json_data);
}

// Function to import products from JSON API from AJAX call
function totosync_batch_import_products($offset, $batch_size) {
    // Start the session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Generate a unique file name for the user session
    $session_id = session_id();
    if (empty($session_id)) {
        // Generate a session ID if it's empty
        $session_id = uniqid();
        session_id($session_id);
        session_start();
    }

    // Check if the file exists
    $file_name = __DIR__ . '/temp/totosync_data_' . $session_id . '.json';
    if (file_exists($file_name)) {
        // Read JSON data from the file
        $json_data = file_get_contents($file_name);
    } else {
        // Fetch JSON data from the API
        $json_data = file_get_contents('http://shop.ruelsoftware.co.ke/api/featuredproducts/197.248.191.179');

        // Save JSON data to the file
        file_put_contents($file_name, $json_data);
    }

    // Convert JSON data to array
    $products = json_decode($json_data, true);

    // Check if JSON decoding was successful
    if ($products === null) {
        // Handle JSON decoding error
        return;
    }

    // Get the total number of products
    $total_products = count($products);

    // Slice the array to get a chunk of products starting from the given offset
    $chunk = array_slice($products, $offset, $batch_size);

    // Check if there are products to process
    if (!empty($chunk)) {
        // Loop through each product chunk and process them
        foreach ($chunk as $product) {
            // Example: Process each product
            totosync_parse_single_json(json_encode($product));
        }

        // Check if there are more products to process
        $more_products = $offset + $batch_size < $total_products;

        // Return the total number of products and whether there are more products to process
        return array(
            'total_products' => $total_products,
            'more_products' => $more_products
        );
    } else {
        // No more products to process
        // Delete the file
        unlink($file_name);
        return false;
    }
}

// Function to import products from JSON API - ajax callback
add_action('wp_ajax_totosync_recursive_import', 'totosync_recursive_import_callback');

function totosync_recursive_import_callback() {
    // Set time limit to 0 to prevent script from timing out
    set_time_limit(0);

    // Get offset and batch size from AJAX request
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10; // Default batch size
    // Call import function to process products in batches
    $total_products = totosync_batch_import_products($offset, $batch_size);

    // Send response back to JavaScript
    if ($total_products !== false) {
        wp_send_json_success(array('nb_products' => $total_products));
    } else {
        wp_send_json_error('No more products to process');
    }
    wp_die(); // Always include wp_die() to end AJAX processing
}

function totosync_enqueue_scripts($hook) {
    // Enqueue JavaScript file
    wp_enqueue_script('totosync-script', plugin_dir_url(__FILE__) . 'script.js', array('jquery'), '1.0', true);

    // Define batch size
    $batch_size = 10;

    // Localize AJAX URL and batch size
    wp_localize_script('totosync-script', 'totosync_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'batch_size' => $batch_size
    ));
}

// Define function to enqueue JavaScript and localize AJAX URL
add_action('admin_enqueue_scripts', 'totosync_enqueue_scripts');

// Function to parse JSON data
function totosync_parse_json($json_data) {
    $product = json_decode($json_data, true);
    if ($products) {
        foreach ($products as $product) {
            print_r($product);
            die();
            // Ignore product if no image
            if (empty($product['imageUrls'])) {
                continue;
            }

            // Check if the product already exists in the database by name
            $existing_product_id = totosync_get_product_id_by_name($product['itemName']);
            if (!empty($product['measurement']) && !empty($product['colour'])) {
                // Product with "measurement" and "colour" fields is treated as a variation
                if ($existing_product_id) {
                    // Product with the same name exists, treat it as a variation
                    totosync_create_variation($product);
                } else {
                    // Product with the same name doesn't exist, create a variable product first
                    $variable_product_id = totosync_create_variable_product($product);
                    // Then create the variation
                    totosync_create_variation($product, $variable_product_id);
                }
            } else {
                // Product without both "measurement" and "colour" fields is treated as a simple product
                if ($existing_product_id) {
                    // Product with the same name exists, treat it as a variation
                    totosync_create_variation($product);
                } else {
                    // Product with the same name doesn't exist, treat it as a simple product
                    totosync_create_product($product);
                }
            }
        }
    }
}

// Function to parse single JSON data
function totosync_parse_single_json($json_data) {
    $product = json_decode($json_data, true);
    if ($product === null) {
        // Handle JSON decoding error
        return;
    }

    // Ignore product if no image
    if (empty($product['imageUrls'])) {
        return;
    }

    // Check if the product already exists in the database by name
    $existing_product_id = totosync_get_product_id_by_name($product['itemName']);
    if (!empty($product['measurement']) && !empty($product['colour'])) {
        // Product with "measurement" and "colour" fields is treated as a variation
        if ($existing_product_id) {
            // Product with the same name exists, treat it as a variation
            totosync_create_variation($product);
        } else {
            // Product with the same name doesn't exist, create a variable product first
            $variable_product_id = totosync_create_variable_product($product);
            // Then create the variation
            totosync_create_variation($product, $variable_product_id);
        }
    } else {
        // Product without both "measurement" and "colour" fields is treated as a simple product
        if ($existing_product_id) {
            // Product with the same name exists, treat it as a variation
            totosync_create_variation($product);
        } else {
            // Product with the same name doesn't exist, treat it as a simple product
            totosync_create_product($product);
        }
    }
}

// Function to create or update product
function totosync_create_variable_product($product_data) {
    // Check if product already exists by name
    $product_id = totosync_get_product_id_by_name($product_data['itemName']);

    if (!$product_id) {
        // Product does not exist, create new variable product
        $new_product = new WC_Product_Variable();
        $new_product->set_name($product_data['itemName']);
        $new_product->set_description($product_data['description']);
        $new_product->set_category_ids([totosync_get_or_create_category($product_data['productCategory'])]); // Set category
        $new_product_id = $new_product->save();

        // Attach images to product
        if (!empty($product_data['imageUrls'])) {
            totosync_attach_images($new_product_id, $product_data['imageUrls']);
        }

        $product_id = $new_product_id;
    } else {
        // Product exists, update existing product
        $product = wc_get_product($product_id);
        // Set category
        $product->set_category_ids([totosync_get_or_create_category($product_data['productCategory'])]);
        // Save the product
        $product->save();
    }

    // Set attributes for variable product
    $attributes = [
        'measurement' => $product_data['measurement'],
        'colour' => $product_data['colour']
    ];

    // Create or update product attributes and terms
    foreach ($attributes as $attribute_name => $attribute_value) {
        $attribute_slug = sanitize_title($attribute_name);
        $term_slug = sanitize_title($attribute_value);

        // Check if attribute exists
        $attribute = wc_get_attribute($attribute_slug);
        if (!$attribute) {
            // If attribute doesn't exist, create it
            $args = [
                'name' => $attribute_name,
                'slug' => $attribute_slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => true,
            ];
            wc_create_attribute($args);
            register_taxonomy('pa_' . $attribute_slug, array('product'), array());
        }

        // Check if term exists
        $term_exists = term_exists($term_slug, 'pa_' . $attribute_slug);

        if (!$term_exists) {
            // If term doesn't exist, create it
            $term_data = wp_insert_term($attribute_value, 'pa_' . $attribute_slug, ['slug' => $term_slug]);
            if (is_wp_error($term_data)) {
                // Term creation failed, log or display the error
                error_log("Term creation failed: " . $term_data->get_error_message()); // Log error
                return false; // Return false to indicate failure
            } else {
                // Get the term ID
                $term_id = $term_data['term_id'];
                // Attach the term to the product
                wp_set_object_terms($product_id, $term_id, 'pa_' . $attribute_slug, true);
            }
        } else {
            // Term already exists, get the term object
            $term = get_term_by('slug', $term_slug, 'pa_' . $attribute_slug);
            // Attach the term to the product
            wp_set_object_terms($product_id, $term->term_id, 'pa_' . $attribute_slug, true);
        }
    }

    return true; // Return true to indicate success
}

// Function to create variation for existing variable product
function totosync_create_variation($product_data) {
    // Get existing variable product
    $product_id = totosync_get_product_id_by_name($product_data['itemName']);

    // Check if product exists
    if (!$product_id) {
        // Product doesn't exist, cannot create variation
        return;
    }

    // Get existing product object
    $product = wc_get_product($product_id);

    // Ensure product object is valid
    if (!$product) {
        // Product object is not valid, cannot create variation
        return;
    }

    // Attach attributes to product - display product attributes
    totosync_add_product_attributes($product_id);

    // Check if a variation with the same attributes already exists
    $existing_variation_id = totosync_get_existing_variation_id($product_id, $product_data);

    // Set attributes
    $attributes = [
        'pa_measurement' => $product_data['measurement'], // Assuming $product_data['measurement'] contains attribute value
        'pa_colour' => $product_data['colour'] // Assuming $product_data['colour'] contains attribute value
    ];

    if ($existing_variation_id) {
        try {
            // Variation with the same attributes already exists, update its price
            $variation = wc_get_product($existing_variation_id);
            $variation->set_regular_price($product_data['price1']);
            $variation->set_sku($product_data['itemCode']);
            $variation->set_description($product_data['description']);
            // Set the stock quantity
            $variation->set_stock_quantity($product_data['quantity']);
            // Enable or disable stock management
            $variation->set_manage_stock(true);

            $variation->save();
        } catch (Exception $e) {
            // Handle the exception gracefully
            // For example, log the error or display a message to the user
            error_log('Error updating variation SKU ' . $product_data['itemCode'] . ' : ' . $e->getMessage());
            // Optionally, you can choose to stop further processing or continue with other operations
        }
        // If variation is successfully saved, set variation attributes
        totosync_set_variation_attributes($existing_variation_id, $attributes);

        $variation_has_pictures = has_post_thumbnail($existing_variation_id);

        if (!$variation_has_pictures) {
            // Attach images to product
            if (!empty($product_data['imageUrls'])) {
                totosync_attach_images_to_variation($existing_variation_id, $product_data['imageUrls']);
            }
        }
    } else {
        try {
            // Create a new variation object
            $variation = new WC_Product_Variation();

            // Set variation properties
            $variation->set_regular_price($product_data['price1']);
            $variation->set_parent_id($product_id);

            $variation->set_sku($product_data['itemCode']);
            $variation->set_description($product_data['description']);
            // Set the stock quantity
            $variation->set_stock_quantity($product_data['quantity']);
            // Enable or disable stock management
            $variation->set_manage_stock(true);

            // Save the variation
            $variation_id = $variation->save();

            if ($variation_id) {
                // If variation is successfully saved, set variation attributes
                totosync_set_variation_attributes($variation_id, $attributes);

                // Attach images to product
                if (!empty($product_data['imageUrls'])) {
                    totosync_attach_images_to_variation($variation_id, $product_data['imageUrls']);
                }
            }
        } catch (Exception $e) {
            // Handle the exception gracefully
            // For example, log the error or display a message to the user
            error_log('Error updating variation SKU ' . $product_data['itemCode'] . ' : ' . $e->getMessage());
            // Optionally, you can choose to stop further processing or continue with other operations
        }
    }
}

// set variation attributes
function totosync_set_variation_attributes($variation_id, $attributes) {
    if ($variation_id) {
        foreach ($attributes as $attribute_key => $attribute_value) {
            // Get the term object for the attribute value
            $term = get_term_by('slug', sanitize_title($attribute_value), $attribute_key);

            if ($term) {
                // Add meta data entry to wp_postmeta table
                add_post_meta($variation_id, 'attribute_' . $attribute_key, $term->slug);

                // Attach the term to the product
                $variation = wc_get_product($variation_id);
                $product_id = $variation->get_parent_id();
                wp_set_object_terms($product_id, $term->term_id, $attribute_key, true);
            } else {
                // If the term doesn't exist, create it and set it for the variation attribute
                $new_term = wp_insert_term($attribute_value, $attribute_key);
                if (!is_wp_error($new_term)) {
                    $term = get_term_by('id', $new_term['term_id'], $attribute_key);
                    add_post_meta($variation_id, 'attribute_' . $attribute_key, $term->slug);

                    // Attach the term to the product
                    $variation = wc_get_product($variation_id);
                    $product_id = $variation->get_parent_id();
                    wp_set_object_terms($product_id, $term->term_id, $attribute_key, true);
                }
            }
        }
    }
}

// Function add product attributes
function totosync_add_product_attributes($product_id) {
    // update the _product_attributes meta field
    $product_attributes = [
        'pa_colour' => [
            'name' => 'pa_colour',
            'value' => '', // Leave empty for now, will be updated later
            'position' => 1,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 1
        ],
        'pa_measurement' => [
            'name' => 'pa_measurement',
            'value' => '', // Leave empty for now, will be updated later
            'position' => 0,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 1
        ],
    ];

    // Check if _product_attributes meta key already exists for the product
    $product_attributes_existing = get_post_meta($product_id, '_product_attributes', true);

    if (empty($product_attributes_existing)) {
        // If _product_attributes meta key doesn't exist, serialize and save the product attributes
        update_post_meta($product_id, '_product_attributes', $product_attributes);
    }
}

// Function to get product ID by name
function totosync_get_product_id_by_name($product_name) {
    $args = array(
        'post_type' => 'product',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'fields' => 'ids',
        's' => $product_name
    );
    $product_query = new WP_Query($args);
    return $product_query->have_posts() ? $product_query->posts[0] : 0;
}

// Function to get existing variation ID by attributes
function totosync_get_existing_variation_id($product_id, $product_data) {
    // Check if the product ID is valid
    if (empty($product_id) || !is_numeric($product_id)) {
        return 0;
    }

    // Attempt to get the product object
    $product = wc_get_product($product_id);

    // Check if the product object is valid
    if (!$product || is_wp_error($product)) {
        return 0;
    }

    // Check if the product is a variable product
    if ($product->is_type('variable')) {
        // Loop through its variations
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            // Check if the variation matches the provided attributes
            if ($variation) {
                if (trim($variation->get_attribute('pa_measurement')) == trim($product_data['measurement']) &&
                        trim($variation->get_attribute('pa_colour')) === trim($product_data['colour'])) {
                    return $variation_id;
                }
            }
        }
    } elseif ($product->is_type('variation')) {
        // If the product is a variation itself, directly check its attributes
        if ($product->get_attribute('pa_measurement') == $product_data['measurement'] &&
                $product->get_attribute('pa_colour') == $product_data['colour']) {
            return $product_id;
        }
    }

    return 0;
}

// Function to create simple product
function totosync_create_product($product_data) {
    // Check if product exists by name
    $product_id = totosync_get_product_id_by_name($product_data['itemName']);

    // Get product category ID or create new category
    $category_id = totosync_get_or_create_category($product_data['productCategory']);

    if ($product_id) {
        // Update existing product
        $product = wc_get_product($product_id);
        $product->set_name($product_data['itemName']);
        $product->set_regular_price($product_data['price1']);
        $product->set_category_ids([$category_id]);
        $product->save();
    } else {
        // Create new product
        $new_product = new WC_Product_Simple();
        $new_product->set_name($product_data['itemName']);
        $new_product->set_regular_price($product_data['price1']);
        $new_product->set_category_ids([$category_id]);
        $new_product->save();

        // Attach images to product
        if (!empty($product_data['imageUrls'])) {
            totosync_attach_images($new_product->get_id(), $product_data['imageUrls']);
        }
    }
}

// Function to get or create category and return its ID
function totosync_get_or_create_category($category_name) {
    // Check if category exists
    $term = get_term_by('name', $category_name, 'product_cat');
    if ($term) {
        return $term->term_id;
    } else {
        // Create new category
        $term = wp_insert_term($category_name, 'product_cat');
        if (!is_wp_error($term)) {
            return $term['term_id'];
        } else {
            // Error handling: Failed to create category
            return 0;
        }
    }
}

// Function to attach images to products
function totosync_attach_images($product_id, $image_urls) {

    // Check if product already has a featured image
    if (has_post_thumbnail($product_id)) {
        return false; // product already has a featured image, exit the function
    }

    foreach ($image_urls as $image_url) {
        $image_id = totosync_upload_image_from_url($image_url);
        if ($image_id) {
            set_post_thumbnail($product_id, $image_id);
        }
    }
}

// Function to attach images to variation
function totosync_attach_images_to_variation($variation_id, $image_urls) {

    // Check if variation already has a featured image
    if (has_post_thumbnail($variation_id)) {
        return false; // Variation already has a featured image, exit the function
    }

    foreach ($image_urls as $image_url) {
        // Upload the image from URL and get its ID
        $image_id = media_sideload_image($image_url, $variation_id, '', 'id');

        if (is_wp_error($image_id)) {
            return false; // Exit if image upload fails
        }

        // Set the image as the post thumbnail of the variation
        set_post_thumbnail($variation_id, $image_id);

        return true;
    }
}

// Function to upload image from URL
function totosync_upload_image_from_url($image_url) {
    $upload_dir = wp_upload_dir();
    $image_data = file_get_contents($image_url);
    $filename = basename($image_url);
    if (wp_mkdir_p($upload_dir['path'])) {
        $file = $upload_dir['path'] . '/' . $filename;
    } else {
        $file = $upload_dir['basedir'] . '/' . $filename;
    }
    file_put_contents($file, $image_data);
    $wp_filetype = wp_check_filetype($filename, null);
    $attachment = [
        'post_mime_type' => $wp_filetype['type'],
        'post_title' => sanitize_file_name($filename),
        'post_content' => '',
        'post_status' => 'inherit'
    ];
    $attachment_id = wp_insert_attachment($attachment, $file);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file);
    wp_update_attachment_metadata($attachment_id, $attachment_data);
    return $attachment_id;
}

// Add admin menu
add_action('admin_menu', 'totosync_admin_menu');

function totosync_admin_menu() {
    add_menu_page(
            'Totosync Settings',
            'Totosync',
            'manage_options',
            'totosync-settings',
            'totosync_settings_page'
    );
}

// Settings page callback
function totosync_settings_page() {
    // Display your settings page content here
    echo '<div class="wrap">';
    echo '<h1>Totosync Settings</h1>';
    echo '<p>Click the button below to start the import process.</p>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="totosync_import" value="true">';
    echo '<button type="submit" class="button button-primary">Import Products</button>';
    echo '</form>';

    // Add button to trigger AJAX process
    echo '<p>Click the button below to start the AJAX import process recursively.</p>';
    echo '<button id="totosync-ajax-import" class="button button-secondary">Recursive AJAX Import</button>';

    if (isset($_POST['totosync_import']) && $_POST['totosync_import'] == 'true') {
        totosync_import_products();
        echo '<div class="notice notice-success"><p>Import process completed successfully!</p></div>';
    }
    echo '</div>';

    // Add progress bar
    echo '<div id="progress-bar-container">';
    echo '<progress id="progress-bar" max="100" value="0"></progress>';
    echo '</div>';
}
