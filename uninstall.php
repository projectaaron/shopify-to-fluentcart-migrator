<?php
/**
 * Removes the migrator's own data. Imported FluentCart products are left alone.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('s2fc_session');

$upload = wp_upload_dir();
$dir    = trailingslashit($upload['basedir']) . 's2fc-migrator';
if (is_dir($dir)) {
    foreach ((array) glob($dir . '/*') as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_s2fc_img_%' OR option_name LIKE '_transient_timeout_s2fc_img_%'");
