<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * The uploaded export and its parsed products live in a private folder under
 * wp-content/uploads until the import is done or the user starts over. Only
 * the folder path and a few counts are kept in one non-autoloaded option.
 */
class Session
{
    const OPTION = 's2fc_session';

    public static function dir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 's2fc-migrator';
    }

    public static function ensure_dir(): string
    {
        return self::protect_dir(self::dir());
    }

    /** Create the folder and keep it out of reach of the web server. */
    private static function protect_dir(string $dir): string
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        }
        return $dir;
    }

    /**
     * Route wp_handle_upload() into the private folder. Works from the
     * array it is given: calling wp_upload_dir() here would re-enter this
     * filter forever.
     */
    public static function upload_dir_filter(array $dirs): array
    {
        $dir = self::protect_dir(trailingslashit($dirs['basedir']) . 's2fc-migrator');
        $dirs['path']   = $dir;
        $dirs['url']    = trailingslashit($dirs['baseurl']) . 's2fc-migrator';
        $dirs['subdir'] = '/s2fc-migrator';
        return $dirs;
    }

    public static function get(): array
    {
        $s = get_option(self::OPTION, []);
        return is_array($s) ? $s : [];
    }

    public static function active(): bool
    {
        $s = self::get();
        return !empty($s['data_file']) && file_exists($s['data_file']);
    }

    /**
     * Store the parsed export.
     *
     * @param array $summary  counts and the source file name
     * @param array $products parsed products, index => product
     */
    public static function start(array $summary, array $products): void
    {
        self::clear();
        $dir  = self::ensure_dir();
        $file = $dir . '/products-' . wp_generate_password(12, false) . '.json';
        file_put_contents($file, wp_json_encode($products));

        $summary['data_file'] = $file;
        $summary['created']   = time();
        $summary['results']   = [];
        update_option(self::OPTION, $summary, false);
    }

    public static function products(): array
    {
        $s = self::get();
        if (empty($s['data_file']) || !file_exists($s['data_file'])) {
            return [];
        }
        $data = json_decode((string) file_get_contents($s['data_file']), true);
        return is_array($data) ? $data : [];
    }

    public static function product(int $index)
    {
        $products = self::products();
        return $products[$index] ?? null;
    }

    /** Remember the outcome of one product so a reload still shows it. */
    public static function record_result(int $index, array $result): void
    {
        $s = self::get();
        if (!$s) {
            return;
        }
        $s['results'][$index] = $result;
        update_option(self::OPTION, $s, false);
    }

    public static function update(array $changes): void
    {
        $s = array_merge(self::get(), $changes);
        update_option(self::OPTION, $s, false);
    }

    public static function clear(): void
    {
        $s = self::get();
        if (!empty($s['data_file']) && file_exists($s['data_file'])) {
            @unlink($s['data_file']);
        }
        if (!empty($s['csv_file']) && file_exists($s['csv_file'])) {
            @unlink($s['csv_file']);
        }
        delete_option(self::OPTION);
    }
}
