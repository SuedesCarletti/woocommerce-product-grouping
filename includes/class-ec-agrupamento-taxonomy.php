<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grupo de variações = 1 termo desta taxonomia. Cada produto pertence a
 * no máximo 1 grupo. Usamos taxonomia (em vez de meta com array de IDs)
 * para listar "todos os produtos do grupo X" com tax_query nativa, sem
 * risco de desincronizar quando um produto do grupo é excluído.
 */
class EC_Agrupamento_Taxonomy {

    const TAXONOMY = 'ec_grupo_variacao';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_taxonomy'));
    }

    public static function register_taxonomy() {
        register_taxonomy(self::TAXONOMY, 'product', array(
            'label'             => __('Grupo de Variações', 'ec-agrupamento'),
            'public'            => false,
            'hierarchical'      => false,
            'show_ui'           => false,
            'show_in_menu'      => false,
            'show_in_nav_menus' => false,
            'show_admin_column' => false,
            'show_in_rest'      => false,
            'query_var'         => false,
            'rewrite'           => false,
        ));
    }

    public static function get_group_term_id($product_id) {
        $terms = wp_get_object_terms($product_id, self::TAXONOMY, array('fields' => 'ids'));
        if (is_wp_error($terms) || empty($terms)) {
            return 0;
        }
        return (int) $terms[0];
    }

    /**
     * IDs dos outros produtos do mesmo grupo. Passe $include_self = true
     * para incluir o próprio produto no resultado.
     */
    public static function get_group_product_ids($product_id, $include_self = false) {
        $product_id = (int) $product_id;
        $term_id    = self::get_group_term_id($product_id);

        if (!$term_id) {
            return $include_self ? array($product_id) : array();
        }

        $ids = get_objects_in_term($term_id, self::TAXONOMY);
        if (is_wp_error($ids)) {
            return $include_self ? array($product_id) : array();
        }

        $ids = array_map('intval', $ids);

        if (!$include_self) {
            $ids = array_values(array_diff($ids, array($product_id)));
        }

        return $ids;
    }

    private static function create_group_term($label) {
        $slug   = 'ec-grupo-' . uniqid();
        $result = wp_insert_term($label, self::TAXONOMY, array('slug' => $slug));

        if (is_wp_error($result)) {
            return 0;
        }

        return (int) $result['term_id'];
    }

    /**
     * Vincula dois produtos ao mesmo grupo, criando o grupo se nenhum dos
     * dois tiver um ainda, ou mesclando os grupos se os dois já tiverem
     * grupos diferentes.
     */
    public static function link_products($product_id_a, $product_id_b) {
        $product_id_a = (int) $product_id_a;
        $product_id_b = (int) $product_id_b;

        if ($product_id_a === $product_id_b) {
            return false;
        }

        $term_a = self::get_group_term_id($product_id_a);
        $term_b = self::get_group_term_id($product_id_b);

        if ($term_a && $term_b && $term_a !== $term_b) {
            $group_b_products = get_objects_in_term($term_b, self::TAXONOMY);
            if (!is_wp_error($group_b_products)) {
                foreach ($group_b_products as $pid) {
                    wp_set_object_terms((int) $pid, array($term_a), self::TAXONOMY, false);
                }
            }
            wp_delete_term($term_b, self::TAXONOMY);
            return true;
        }

        if ($term_a && !$term_b) {
            wp_set_object_terms($product_id_b, array($term_a), self::TAXONOMY, false);
            return true;
        }

        if ($term_b && !$term_a) {
            wp_set_object_terms($product_id_a, array($term_b), self::TAXONOMY, false);
            return true;
        }

        $term_id = self::create_group_term(sprintf('Grupo #%d', $product_id_a));
        if (!$term_id) {
            return false;
        }

        wp_set_object_terms($product_id_a, array($term_id), self::TAXONOMY, false);
        wp_set_object_terms($product_id_b, array($term_id), self::TAXONOMY, false);
        return true;
    }

    /**
     * Remove um produto do grupo. Se sobrar só 1 produto (ou nenhum) no
     * grupo, ele deixa de ser um "grupo" e o termo é apagado.
     */
    public static function unlink_product($product_id) {
        $product_id = (int) $product_id;
        $term_id    = self::get_group_term_id($product_id);

        if (!$term_id) {
            return true;
        }

        wp_remove_object_terms($product_id, array($term_id), self::TAXONOMY);

        $remaining = get_objects_in_term($term_id, self::TAXONOMY);
        if (!is_wp_error($remaining) && count($remaining) <= 1) {
            foreach ($remaining as $pid) {
                wp_remove_object_terms((int) $pid, array($term_id), self::TAXONOMY);
            }
            wp_delete_term($term_id, self::TAXONOMY);
        }

        return true;
    }

    /**
     * Valor do atributo "cor" do produto. Catálogos misturam nomenclatura
     * em português ("cor") com a nomenclatura padrão em inglês do
     * WooCommerce ("color") dependendo de como o produto foi cadastrado —
     * tenta os dois (filtrável, pra outros nomes/idiomas) e usa o primeiro
     * que tiver valor.
     */
    public static function get_cor($product) {
        return self::get_first_attribute_value(
            $product,
            apply_filters('ec_agrupamento_cor_attribute_names', array('cor', 'color'))
        );
    }

    /**
     * Valor do atributo "voltagem" do produto — mesma lógica de
     * fallback de get_cor() para "voltagem" (pt) / "voltage" (en).
     */
    public static function get_voltagem($product) {
        return self::get_first_attribute_value(
            $product,
            apply_filters('ec_agrupamento_voltagem_attribute_names', array('voltagem', 'voltage'))
        );
    }

    private static function get_first_attribute_value($product, $attribute_names) {
        if (!$product) {
            return '';
        }

        foreach ($attribute_names as $name) {
            $value = $product->get_attribute($name);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
