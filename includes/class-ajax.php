<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * The upload form posts to admin-post.php; the import runs one product per
 * admin-ajax request so a big catalog never hits a PHP time limit and the
 * screen can show progress.
 */
class Ajax
{
    const NONCE = 's2fc_nonce';

    public function register(): void
    {
        add_action('admin_post_s2fc_upload', [$this, 'upload']);
        add_action('admin_post_s2fc_reset', [$this, 'reset']);
        add_action('wp_ajax_s2fc_import_one', [$this, 'import_one']);
        add_action('wp_ajax_s2fc_finish', [$this, 'finish']);
    }

    private function guard(string $action): void
    {
        if (!Helpers::user_can_migrate()) {
            wp_die(esc_html__('You are not allowed to run the migrator.', 'shopify-to-fluentcart-migrator'), 403);
        }
        check_admin_referer($action, self::NONCE);
    }

    public function upload(): void
    {
        $this->guard('s2fc_upload');

        if (!Helpers::fluentcart_ready()) {
            $this->back(['s2fc_error' => 'no_fluentcart']);
        }
        if (empty($_FILES['s2fc_csv']) || !is_array($_FILES['s2fc_csv'])) {
            $this->back(['s2fc_error' => 'no_file']);
        }

        $file = $_FILES['s2fc_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if (!empty($file['error'])) {
            $this->back(['s2fc_error' => 'upload_' . (int) $file['error']]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        add_filter('upload_dir', [Session::class, 'upload_dir_filter']);
        $moved = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => [
                'csv' => 'text/csv',
                'txt' => 'text/plain',
            ],
        ]);
        remove_filter('upload_dir', [Session::class, 'upload_dir_filter']);

        if (!empty($moved['error'])) {
            // WordPress often reports CSVs as text/plain or application/vnd.ms-excel; retry without the type check.
            $moved = $this->move_manually($file);
            if (is_wp_error($moved)) {
                $this->back(['s2fc_error' => 'upload_failed', 's2fc_msg' => rawurlencode($moved->get_error_message())]);
            }
        }

        $parsed = Csv_Parser::parse_file($moved['file']);
        if (is_wp_error($parsed)) {
            @unlink($moved['file']);
            $this->back(['s2fc_error' => 'parse', 's2fc_msg' => rawurlencode($parsed->get_error_message())]);
        }

        $summary = $parsed['summary'];
        $summary['source_name'] = sanitize_file_name($file['name']);
        $summary['csv_file']    = $moved['file'];
        $summary['options']     = Importer::default_options();

        Session::start($summary, $parsed['products']);
        $this->back(['s2fc_step' => 'review']);
    }

    /** @return array|\WP_Error ['file' => path] */
    private function move_manually(array $file)
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return new \WP_Error('type', __('Please upload the .csv file Shopify emailed you.', 'shopify-to-fluentcart-migrator'));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('tmp', __('Upload failed.', 'shopify-to-fluentcart-migrator'));
        }
        $dir  = Session::ensure_dir();
        $dest = $dir . '/export-' . wp_generate_password(12, false) . '.csv';
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return new \WP_Error('move', __('The file could not be saved to the uploads folder.', 'shopify-to-fluentcart-migrator'));
        }
        return ['file' => $dest];
    }

    public function reset(): void
    {
        $this->guard('s2fc_reset');
        Session::clear();
        $this->back([]);
    }

    public function import_one(): void
    {
        if (!Helpers::user_can_migrate()) {
            wp_send_json_error(['message' => __('Not allowed.', 'shopify-to-fluentcart-migrator')], 403);
        }
        check_ajax_referer('s2fc_import', 'nonce');

        $index   = isset($_POST['index']) ? (int) $_POST['index'] : -1;
        $options = isset($_POST['options']) && is_array($_POST['options']) ? wp_unslash($_POST['options']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $options = Importer::sanitize_options($options);

        $product = Session::product($index);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found in the uploaded file. Please upload it again.', 'shopify-to-fluentcart-migrator')], 404);
        }

        $result = Importer::import($product, $options);
        if (is_wp_error($result)) {
            $record = ['status' => 'failed', 'message' => $result->get_error_message(), 'warnings' => []];
            Session::record_result($index, $record);
            wp_send_json_error(['index' => $index, 'title' => $product['title'], 'message' => $result->get_error_message()]);
        }

        $result['title']    = $product['title'];
        $result['edit_url'] = Helpers::edit_url((int) $result['post_id']);
        Session::record_result($index, $result);
        Session::update(['options' => $options]);

        wp_send_json_success($result);
    }

    /** Called once after the last product: leaves the results, drops the CSV. */
    public function finish(): void
    {
        if (!Helpers::user_can_migrate()) {
            wp_send_json_error([], 403);
        }
        check_ajax_referer('s2fc_import', 'nonce');
        $s = Session::get();
        if (!empty($s['csv_file']) && file_exists($s['csv_file'])) {
            @unlink($s['csv_file']);
        }
        Session::update(['csv_file' => '', 'finished' => time()]);
        wp_send_json_success();
    }

    private function back(array $args): void
    {
        wp_safe_redirect(Helpers::admin_url($args));
        exit;
    }
}
