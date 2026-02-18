<?php

if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

$plugin_meta = [
    'Plugin Name'       => 'Transactions Import From JSON',
    'Description'       => 'Transactions Import From JSON is a powerful PipraPay plugin that provides import transactions from JSON files — completely free.',
    'Version'           => '1.0.0',
    'Author'            => 'Your Name',
    'Author URI'        => 'https://yourwebsite.com/',
    'License'           => 'GPL-2.0+',
    'License URI'       => 'http://www.gnu.org/licenses/gpl-2.0.txt',
    'Requires at least' => '1.0.0',
    'Plugin URI'        => '',
    'Text Domain'       => '',
    'Domain Path'       => '',
    'Requires PHP'      => ''
];

$funcFile = __DIR__ . '/functions.php';
if (file_exists($funcFile)) {
    require_once $funcFile;
}

// Load the admin UI rendering function
function transactions_import_json_admin_page()
{
    $viewFile = __DIR__ . '/views/admin-ui.php';

    if (file_exists($viewFile)) {
        include $viewFile;
    } else {
        echo "<div class='alert alert-warning'>Admin UI not found.</div>";
    }
}