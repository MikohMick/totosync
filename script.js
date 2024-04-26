jQuery(document).ready(function ($) {
    $('#totosync-ajax-import').click(function () {
        var offset = 0; // Initial offset
        var batch_size = parseInt(totosync_ajax.batch_size); // Use the batch size from tososync_ajax object
        var processed_products = 0; // Counter for processed products

        // Function to process each batch
        function processBatch() {
            $.ajax({
                url: totosync_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'totosync_recursive_import',
                    offset: offset
                },
                success: function (response) {
                    // Parse the JSON response
                    var nb_products = response.data.nb_products;
                    if (response.success && response.data.nb_products) {
                        var total_products = nb_products.total_products;
                        var more_products = nb_products.more_products;
                        // Check if there are more products to process

                        if (total_products) {
                            // If yes, recursively call processBatch after a delay (e.g., 1 second)
                            offset += batch_size;
                            processed_products += batch_size;
                            // Check if total_products is a valid number
                            if (!isNaN(total_products) && isFinite(total_products)) {
                                // Update the progress bar value
                                updateProgressBar(total_products, processed_products);
                            } else {
                                // Log an error or handle the invalid total_products value
                                console.error('Invalid total products value:', total_products);
                                return;
                            }
                            setTimeout(processBatch, 1000);
                        }
                        if (more_products) {
                            // console.log(offset);
                            // console.log(nb_products.total_products);
                            // If no more products, show completion message or perform any other action
                            console.log('All products processed');
                        }
                    } else {
                        // Handle error
                        // console.log('Error processing batch');
                        console.log('End of batch');
                    }
                },
                error: function (xhr, status, error) {
                    console.log('Error: ' + error); // Log error message
                }
            });
        }

        // Start processing batches
        processBatch();
    });
    function updateProgressBar(total, processed) {
        var progress = Math.round((processed / total) * 100);
        $('#progress-bar').val(progress);
    }
});