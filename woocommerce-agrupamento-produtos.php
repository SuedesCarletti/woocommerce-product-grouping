<?php
/**
 * Plugin Name: Agrupamento de Produtos por Cor/Voltagem
 * Plugin URI: https://github.com/SuedesCarletti/woocommerce-product-grouping
 * Description: Vincula produtos WooCommerce que representam a mesma peça em cores/voltagens diferentes, exibindo um seletor de variação na página do produto e evitando duplicidade nas listagens.
 * Version: 1.0.0
 * Author: Suedes Carletti
 * License: Todos os direitos reservados / All rights reserved
 * Text Domain: ec-agrupamento
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EC_AGRUPAMENTO_VERSION', '1.0.0');
define('EC_AGRUPAMENTO_DIR', plugin_dir_path(__FILE__));
define('EC_AGRUPAMENTO_URL', plugin_dir_url(__FILE__));

require_once EC_AGRUPAMENTO_DIR . 'includes/class-ec-agrupamento-taxonomy.php';
require_once EC_AGRUPAMENTO_DIR . 'includes/class-ec-agrupamento-admin.php';
require_once EC_AGRUPAMENTO_DIR . 'includes/class-ec-agrupamento-frontend.php';

add_action('plugins_loaded', 'ec_agrupamento_bootstrap');

function ec_agrupamento_bootstrap() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'ec_agrupamento_missing_woocommerce_notice');
        return;
    }

    EC_Agrupamento_Taxonomy::init();
    EC_Agrupamento_Admin::init();
    EC_Agrupamento_Frontend::init();
}

function ec_agrupamento_missing_woocommerce_notice() {
    echo '<div class="notice notice-error"><p>' .
        esc_html__('Agrupamento de Produtos por Cor/Voltagem requer o WooCommerce ativo.', 'ec-agrupamento') .
        '</p></div>';
}
