<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caixa "Grupo de Variações (Cor/Voltagem)" na edição do produto: busca
 * com autocomplete para vincular, lista dos produtos já vinculados com
 * botão de remover, e avisos de combinação duplicada / atributo ausente.
 */
class EC_Agrupamento_Admin {

    public static function init() {
        add_action('add_meta_boxes', array(__CLASS__, 'add_metabox'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_ajax_ec_grupo_buscar', array(__CLASS__, 'ajax_buscar'));
        add_action('wp_ajax_ec_grupo_adicionar', array(__CLASS__, 'ajax_adicionar'));
        add_action('wp_ajax_ec_grupo_remover', array(__CLASS__, 'ajax_remover'));
    }

    /**
     * Registra a caixa própria "Grupo de Variações", a menos que o tema
     * já embuta `render_group_section()` dentro de outra caixa e sinalize
     * isso com `add_filter('ec_agrupamento_render_own_metabox', '__return_false')`
     * (é o caso do ecomall-child, que junta este campo na caixa
     * "Personalização desta Página").
     */
    public static function add_metabox() {
        if (!apply_filters('ec_agrupamento_render_own_metabox', true)) {
            return;
        }

        add_meta_box(
            'ec_grupo_variacao',
            __('Grupo de Variações (Cor/Voltagem)', 'ec-agrupamento'),
            array(__CLASS__, 'render_metabox'),
            'product',
            'side',
            'default'
        );
    }

    public static function enqueue($hook) {
        global $post;

        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        wp_enqueue_style(
            'ec-agrupamento-admin',
            EC_AGRUPAMENTO_URL . 'assets/admin.css',
            array(),
            EC_AGRUPAMENTO_VERSION
        );
        wp_enqueue_script(
            'ec-agrupamento-admin',
            EC_AGRUPAMENTO_URL . 'assets/admin.js',
            array('jquery'),
            EC_AGRUPAMENTO_VERSION,
            true
        );
        wp_localize_script('ec-agrupamento-admin', 'ecAgrupamento', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('ec_grupo_nonce'),
            'productId' => $post->ID,
            'i18n'      => array(
                'confirmRemove' => __('Remover este produto do grupo?', 'ec-agrupamento'),
                'searching'     => __('Buscando...', 'ec-agrupamento'),
                'noResults'     => __('Nenhum produto encontrado.', 'ec-agrupamento'),
            ),
        ));
    }

    public static function render_metabox($post) {
        self::render_group_section($post);
    }

    /**
     * Conteúdo da caixa (busca, lista, avisos), sem o wrapper de caixa do
     * WordPress — público para que outra tela/caixa (ex: a caixa de
     * personalização do tema) possa chamá-lo diretamente.
     */
    public static function render_group_section($post) {
        $product_id = $post->ID;
        $linked     = EC_Agrupamento_Taxonomy::get_group_product_ids($product_id);

        echo '<div id="ec-grupo-box" data-product-id="' . esc_attr($product_id) . '">';

        self::render_warnings($product_id, $linked);

        echo '<p><input type="text" id="ec-grupo-busca" class="widefat" placeholder="' .
            esc_attr__('Buscar produto para vincular...', 'ec-agrupamento') . '" autocomplete="off" /></p>';
        echo '<div id="ec-grupo-resultados" class="ec-grupo-resultados"></div>';

        echo '<ul id="ec-grupo-lista" class="ec-grupo-lista">';
        self::render_linked_list_items($linked);
        echo '</ul>';

        echo '</div>';
    }

    private static function render_linked_list_items($linked) {
        if (empty($linked)) {
            echo '<li class="ec-grupo-vazio">' . esc_html__('Nenhum produto vinculado ainda.', 'ec-agrupamento') . '</li>';
            return;
        }

        foreach ($linked as $pid) {
            self::render_linked_item($pid);
        }
    }

    private static function render_linked_item($pid) {
        $product = wc_get_product($pid);
        if (!$product) {
            return;
        }

        $thumb = $product->get_image('thumbnail');
        $cor   = EC_Agrupamento_Taxonomy::get_cor($product);
        $volt  = EC_Agrupamento_Taxonomy::get_voltagem($product);
        $resumo = trim(($cor ?: '—') . ' / ' . ($volt ?: '—'));

        printf(
            '<li class="ec-grupo-item" data-product-id="%1$d">%2$s<span class="ec-grupo-item-info"><a href="%3$s" target="_blank">%4$s</a><br><small>%5$s</small></span><button type="button" class="button-link ec-grupo-remover" data-product-id="%1$d">%6$s</button></li>',
            $pid,
            $thumb,
            esc_url(get_edit_post_link($pid)),
            esc_html($product->get_name()),
            esc_html($resumo),
            esc_html__('Remover do grupo', 'ec-agrupamento')
        );
    }

    private static function render_warnings($product_id, $linked) {
        $all        = array_merge(array($product_id), $linked);
        $combos     = array();
        $missing    = array();
        $duplicates = array();

        foreach ($all as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $cor  = EC_Agrupamento_Taxonomy::get_cor($product);
            $volt = EC_Agrupamento_Taxonomy::get_voltagem($product);

            if (!$cor && !$volt) {
                $missing[] = $product->get_name();
                continue;
            }

            $combo = strtolower($cor . '|' . $volt);
            if (isset($combos[$combo])) {
                $duplicates[] = $product->get_name() . ' / ' . $combos[$combo];
            } else {
                $combos[$combo] = $product->get_name();
            }
        }

        if ($duplicates) {
            printf(
                '<div class="notice notice-warning inline"><p>%s %s</p></div>',
                esc_html__('Combinação de cor+voltagem repetida:', 'ec-agrupamento'),
                esc_html(implode(', ', $duplicates))
            );
        }

        if ($missing) {
            printf(
                '<div class="notice notice-warning inline"><p>%s %s</p></div>',
                esc_html__('Sem cor nem voltagem definidas (não aparecerá no seletor):', 'ec-agrupamento'),
                esc_html(implode(', ', $missing))
            );
        }
    }

    private static function respond_with_group_state($current_id) {
        $linked = EC_Agrupamento_Taxonomy::get_group_product_ids($current_id);

        ob_start();
        self::render_linked_list_items($linked);
        $list_html = ob_get_clean();

        ob_start();
        self::render_warnings($current_id, $linked);
        $warnings_html = ob_get_clean();

        wp_send_json_success(array(
            'list'     => $list_html,
            'warnings' => $warnings_html,
        ));
    }

    public static function ajax_buscar() {
        check_ajax_referer('ec_grupo_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('forbidden', 403);
        }

        $term       = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        $current_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if ($term === '') {
            wp_send_json_success(array());
        }

        $exclude = array_merge(array($current_id), EC_Agrupamento_Taxonomy::get_group_product_ids($current_id));

        // Busca por título (WP_Query 's' padrão) e por SKU (não incluído em
        // 's' por padrão, já que SKU é postmeta, não título/conteúdo) — sem
        // isso, digitar o código do produto não encontrava nada.
        $title_query = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $term,
            'posts_per_page' => 10,
            'post__not_in'   => $exclude,
            'fields'         => 'ids',
        ));

        $sku_query = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'post__not_in'   => $exclude,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_sku',
                    'value'   => $term,
                    'compare' => 'LIKE',
                ),
            ),
        ));

        $ids = array_slice(array_unique(array_merge($title_query->posts, $sku_query->posts)), 0, 10);

        $results = array();
        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $results[] = array(
                'id'    => $pid,
                'title' => $product->get_name(),
                'sku'   => $product->get_sku(),
            );
        }

        wp_send_json_success($results);
    }

    public static function ajax_adicionar() {
        check_ajax_referer('ec_grupo_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('forbidden', 403);
        }

        $current_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $target_id  = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;

        if (!$current_id || !$target_id || !get_post($current_id) || !get_post($target_id)) {
            wp_send_json_error(__('Produto inválido.', 'ec-agrupamento'));
        }

        EC_Agrupamento_Taxonomy::link_products($current_id, $target_id);
        self::respond_with_group_state($current_id);
    }

    public static function ajax_remover() {
        check_ajax_referer('ec_grupo_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('forbidden', 403);
        }

        $current_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $target_id  = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;

        if (!$target_id) {
            wp_send_json_error(__('Produto inválido.', 'ec-agrupamento'));
        }

        EC_Agrupamento_Taxonomy::unlink_product($target_id);
        self::respond_with_group_state($current_id);
    }
}
