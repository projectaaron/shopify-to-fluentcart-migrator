<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * Shopify's "Variant Barcode" is the product's GTIN (UPC, EAN, ISBN).
 * FluentCart has no field for it. Custom Meta Fields for FluentCart (free on
 * WordPress.org) adds a GTIN per variation, so when it is active the codes
 * are handed to it; otherwise they are kept on the product in this plugin's
 * own meta so nothing is lost, and the user is told.
 */
class Gtin
{
    const PLUGIN_SLUG = 'custom-meta-fields-for-fluentcart';
    const PLUGIN_NAME = 'Custom Meta Fields for FluentCart';
    const PLUGIN_URL  = 'https://wordpress.org/plugins/custom-meta-fields-for-fluentcart/';

    /** Where the codes will go, for the notices. */
    public static function state(): string
    {
        if (Helpers::meta_fields_active()) {
            return 'active';
        }
        if (Helpers::meta_fields_installed()) {
            return 'installed';
        }
        return 'missing';
    }

    /** Link to install the free plugin from WordPress.org in one click, or to activate it. */
    public static function install_url(): string
    {
        if (self::state() === 'installed' && current_user_can('activate_plugins')) {
            return admin_url('plugins.php?s=' . rawurlencode(self::PLUGIN_NAME) . '&plugin_status=all');
        }
        if (current_user_can('install_plugins')) {
            return wp_nonce_url(
                self_admin_url('update.php?action=install-plugin&plugin=' . self::PLUGIN_SLUG),
                'install-plugin_' . self::PLUGIN_SLUG
            );
        }
        return self::PLUGIN_URL;
    }

    /**
     * Save the barcodes of an imported product.
     *
     * @param int   $product_id  the FluentCart product (post) ID
     * @param array $rows        [['variation_id' => int, 'gtin' => string], ...]
     * @param string $brand      Shopify vendor
     * @return array ['saved' => int, 'skipped' => int, 'errors' => string[]]
     */
    public static function save(int $product_id, array $rows, string $brand = ''): array
    {
        $out = ['saved' => 0, 'skipped' => 0, 'errors' => []];
        $rows = array_values(array_filter($rows, function ($r) {
            return !empty($r['gtin']);
        }));
        if (!$rows) {
            return $out;
        }

        if (!Helpers::meta_fields_active()) {
            $out['skipped'] = count($rows);
            return $out;
        }

        $class = '\FctCustomMeta\ProductIdentifiers';

        // Validate each code with the plugin's own rules so a bad barcode does
        // not block the good ones.
        $clean = [];
        foreach ($rows as $r) {
            $gtin = call_user_func([$class, 'normalizeGtin'], (string) $r['gtin']);
            if (is_wp_error($gtin)) {
                $out['errors'][] = sprintf('%s: %s', $r['gtin'], $gtin->get_error_message());
                $out['skipped']++;
                continue;
            }
            if ($gtin === '') {
                continue;
            }
            $clean[] = ['variation_id' => (int) $r['variation_id'], 'gtin' => $gtin, 'mpn' => ''];
        }

        if (!$clean) {
            return $out;
        }

        $result = call_user_func([$class, 'set'], $product_id, ['brand' => $brand, 'rows' => $clean]);
        if (is_wp_error($result)) {
            // One duplicate stops the plugin's bulk save; fall back to one at a time.
            foreach ($clean as $row) {
                $single = call_user_func([$class, 'setVariation'], $product_id, $row['variation_id'], $row['gtin'], '');
                if (is_wp_error($single)) {
                    $out['errors'][] = sprintf('%s: %s', $row['gtin'], $single->get_error_message());
                    $out['skipped']++;
                } else {
                    $out['saved']++;
                }
            }
            return $out;
        }

        $out['saved'] = count($clean);
        return $out;
    }
}
