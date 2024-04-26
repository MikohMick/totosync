<?php

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

// Include totosync.php
require_once(__DIR__ . '/totosync.php');

// Set time limit to 0 to prevent script from timing out
set_time_limit(0);

// Call function to perform the bulk operation
totosync_import_products();
