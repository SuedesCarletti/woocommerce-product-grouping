<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Seletor de cor/voltagem na página do produto e utilitário de
 * deduplicação para listagens (loja/busca).
 *
 * NOTA DE INTEGRAÇÃO: `render_selector()` é enganchado por padrão em
 * `woocommerce_single_product_summary` (prioridade 11, logo após o preço
 * que usa 10), o que funciona em temas WooCommerce "padrão". O tema
 * ecomall-child usa um template customizado (single-product.php) que não
 * necessariamente dispara esses hooks no ponto exato entre preço e frete
 * pedido na especificação — quando o código do tema estiver disponível,
 * chame `EC_Agrupamento_Frontend::render_selector($product->get_id())`
 * diretamente no lugar certo do template e remova o hook automático abaixo
 * (ou deixe-o como fallback para outros temas).
 *
 * Da mesma forma, `dedupe_by_group()` precisa ser chamado explicitamente
 * de dentro do loop que monta `$filtered_posts`/`$all_filtered_posts` em
 * archive-product.php — não há como isso ser feito via hook genérico sem
 * ver o código real do loop.
 */
class EC_Agrupamento_Frontend {

    public static function init() {
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'render_selector'), 11);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function enqueue() {
        if (function_exists('is_product') && is_product()) {
            wp_enqueue_style(
                'ec-agrupamento-frontend',
                EC_AGRUPAMENTO_URL . 'assets/frontend.css',
                array(),
                EC_AGRUPAMENTO_VERSION
            );
        }
    }

    /**
     * Mapa cor (slug em minúsculas) => hex. Filtrável para que o tema
     * substitua pelo mesmo mapa já usado em archive-product.php
     * ($COLOR_HEX), evitando dois mapas de cor divergentes.
     */
    public static function get_color_hex_map() {
        $default = array(
            // Português — nomenclatura usada no filtro do site (arquivo
            // "cor" do tema).
            'branco'   => '#FFFFFF',
            'preto'    => '#111111',
            'prata'    => '#C0C0C0',
            'cinza'    => '#808080',
            'azul'     => '#1E63C7',
            'vermelho' => '#C0392B',
            'verde'    => '#2E8B57',
            'amarelo'  => '#F1C40F',
            'rosa'     => '#E91E8C',
            'bege'     => '#D8C3A5',
            'dourado'  => '#C9A227',
            'inox'     => '#B7BABC',
            // Inglês — nomenclatura padrão de atributos globais do
            // WooCommerce (ex: produtos cadastrados com o atributo
            // "Color"/valores "White", "Silver" em vez de "Cor"/"Branco").
            'white'    => '#FFFFFF',
            'black'    => '#111111',
            'silver'   => '#C0C0C0',
            'gray'     => '#808080',
            'grey'     => '#808080',
            'blue'     => '#1E63C7',
            'red'      => '#C0392B',
            'green'    => '#2E8B57',
            'yellow'   => '#F1C40F',
            'pink'     => '#E91E8C',
            'beige'    => '#D8C3A5',
            'gold'     => '#C9A227',
            'stainless steel' => '#B7BABC',
        );

        $map = apply_filters('ec_agrupamento_color_hex_map', $default);

        // Normaliza pra chave em minúsculas: o tema pode filtrar com as
        // mesmas grafias usadas no atributo "cor" (ex: "Preto"), enquanto
        // o resto desta classe compara tudo em minúsculas.
        $normalized = array();
        foreach ($map as $cor => $hex) {
            $normalized[strtolower(trim($cor))] = $hex;
        }

        return $normalized;
    }

    private static function build_matrix($group_ids) {
        $matrix = array();

        foreach ($group_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_visible()) {
                continue;
            }

            $cor  = strtolower(trim(EC_Agrupamento_Taxonomy::get_cor($product)));
            $volt = strtolower(trim(EC_Agrupamento_Taxonomy::get_voltagem($product)));

            if (!$cor && !$volt) {
                continue;
            }

            $matrix[] = array(
                'id'        => $pid,
                'cor'       => $cor,
                'voltagem'  => $volt,
                'permalink' => get_permalink($pid),
            );
        }

        return $matrix;
    }

    public static function render_selector($product_id = null) {
        global $product;

        $product_id = $product_id ?: ($product ? $product->get_id() : 0);
        if (!$product_id) {
            return;
        }

        $group_ids = EC_Agrupamento_Taxonomy::get_group_product_ids($product_id, true);
        if (count($group_ids) < 2) {
            return;
        }

        $matrix = self::build_matrix($group_ids);
        if (count($matrix) < 2) {
            return;
        }

        $current       = wc_get_product($product_id);
        $current_cor   = strtolower(trim(EC_Agrupamento_Taxonomy::get_cor($current)));
        $current_volt  = strtolower(trim(EC_Agrupamento_Taxonomy::get_voltagem($current)));

        $colors = array();
        foreach ($matrix as $row) {
            if ($row['cor'] !== '') {
                $colors[$row['cor']] = true;
            }
        }

        // Se dois produtos do grupo caírem na mesma combinação cor+voltagem
        // (cadastro com o atributo "cor" vazio/repetido — o admin já avisa
        // disso na caixa de vínculo), o produto atual sempre vence pra sua
        // própria combinação, pra nunca "empurrar" o cliente pra outro
        // produto ao carregar a própria página.
        $voltages_for_current_color = array();
        foreach ($matrix as $row) {
            if ($row['cor'] !== $current_cor || $row['voltagem'] === '') {
                continue;
            }
            if ($row['id'] === $product_id || !isset($voltages_for_current_color[$row['voltagem']])) {
                $voltages_for_current_color[$row['voltagem']] = $row['permalink'];
            }
        }

        $hex_map = self::get_color_hex_map();

        echo '<div class="ec-grupo-seletor">';

        if ($colors) {
            echo '<div class="ec-grupo-cores"><span class="ec-grupo-label">' . esc_html__('Cor:', 'ec-agrupamento') . '</span>';
            foreach (array_keys($colors) as $cor) {
                $target = self::find_target_for_color($matrix, $cor, $current_volt);
                if (!$target) {
                    continue;
                }
                $hex     = isset($hex_map[$cor]) ? $hex_map[$cor] : '#CCCCCC';
                $classes = 'ec-grupo-cor-dot' . ($cor === $current_cor ? ' is-active' : '');
                printf(
                    '<a href="%1$s" class="%2$s" style="background-color:%3$s" title="%4$s"></a>',
                    esc_url($target),
                    esc_attr($classes),
                    esc_attr($hex),
                    esc_attr(ucfirst($cor))
                );
            }
            echo '</div>';
        }

        if ($voltages_for_current_color) {
            echo '<div class="ec-grupo-voltagens"><span class="ec-grupo-label">' . esc_html__('Voltagem:', 'ec-agrupamento') . '</span>';
            foreach ($voltages_for_current_color as $volt => $permalink) {
                $classes = 'ec-grupo-volt-btn' . ($volt === $current_volt ? ' is-active' : '');
                printf(
                    '<a href="%1$s" class="%2$s">%3$s</a>',
                    esc_url($permalink),
                    esc_attr($classes),
                    esc_html(strtoupper($volt))
                );
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Produto de destino ao trocar para $cor: mesma voltagem atual se
     * existir nessa cor, senão a primeira voltagem disponível para ela.
     */
    private static function find_target_for_color($matrix, $cor, $preferred_volt) {
        $fallback = null;

        foreach ($matrix as $row) {
            if ($row['cor'] !== $cor) {
                continue;
            }
            if ($fallback === null) {
                $fallback = $row['permalink'];
            }
            if ($row['voltagem'] === $preferred_volt) {
                return $row['permalink'];
            }
        }

        return $fallback;
    }

    /**
     * Recebe uma lista de IDs de produto já filtrada/ordenada pelo tema e
     * devolve a mesma lista com só 1 produto por grupo (o de maior
     * estoque; empate desempatado por total_sales).
     */
    public static function dedupe_by_group($product_ids) {
        $product_ids = array_map('intval', $product_ids);
        $seen_terms  = array();
        $result      = array();

        foreach ($product_ids as $pid) {
            $term_id = EC_Agrupamento_Taxonomy::get_group_term_id($pid);

            if (!$term_id) {
                $result[] = $pid;
                continue;
            }

            if (isset($seen_terms[$term_id])) {
                continue;
            }
            $seen_terms[$term_id] = true;

            $group_members = get_objects_in_term($term_id, EC_Agrupamento_Taxonomy::TAXONOMY);
            if (is_wp_error($group_members)) {
                $result[] = $pid;
                continue;
            }

            $group_ids = array_values(array_intersect(array_map('intval', $group_members), $product_ids));
            if (empty($group_ids)) {
                $group_ids = array($pid);
            }

            $result[] = self::pick_representative($group_ids);
        }

        return $result;
    }

    private static function pick_representative($group_ids) {
        $best_id    = $group_ids[0];
        $best_stock = -1;
        $best_sales = -1;

        foreach ($group_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $stock = $product->get_stock_quantity();
            $stock = $stock === null ? 0 : (int) $stock;
            $sales = (int) get_post_meta($pid, 'total_sales', true);

            if ($stock > $best_stock || ($stock === $best_stock && $sales > $best_sales)) {
                $best_id    = $pid;
                $best_stock = $stock;
                $best_sales = $sales;
            }
        }

        return $best_id;
    }

    /**
     * Pontinhos de cor de um produto (para o card na grade de listagem
     * indicar que há outras cores no mesmo grupo).
     */
    public static function get_group_color_dots($product_id) {
        $group_ids = EC_Agrupamento_Taxonomy::get_group_product_ids($product_id, true);
        if (count($group_ids) < 2) {
            return array();
        }

        $hex_map = self::get_color_hex_map();
        $dots    = array();

        foreach ($group_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $cor = strtolower(trim(EC_Agrupamento_Taxonomy::get_cor($product)));
            if ($cor === '' || isset($dots[$cor])) {
                continue;
            }

            $dots[$cor] = array(
                'hex'       => isset($hex_map[$cor]) ? $hex_map[$cor] : '#CCCCCC',
                'permalink' => get_permalink($pid),
            );
        }

        return $dots;
    }
}
