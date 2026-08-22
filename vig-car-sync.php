<?php
/**
 * Plugin Name: VIG Car Sync
 * Plugin URI:  https://vigdigital.com
 * Description: Trích xuất dữ liệu xe (giá · phiên bản · thông số) từ nguồn ngoài → so sánh & đồng bộ vào website (Carbon Fields). Nền tảng cho kho dữ liệu xe tập trung của VIG.
 * Version:     0.2.1
 * Author:      VIG Digital
 * Author URI:  https://vigdigital.com
 * License:     GPL-2.0-or-later
 * Text Domain: vig-car-sync
 * Update URI:  https://github.com/vigdigital/vig-car-sync
 *
 * Kiến trúc (tách lớp để sau mở rộng — xem knowledge/wp-skills/plugins/vig-car-sync.md):
 *   Source (nguồn)  →  Normalized (chuẩn hoá)  →  Differ (so sánh)  →  Repository (ghi vào site)
 *   - Thêm nguồn mới: tạo class implements VCS_Source_Interface + đăng ký ở VCS_Sources.
 *   - Hiện tại target site qua Carbon Fields (CPT cars). Sau có thể thay Repository = target khác.
 *   - Roadmap: thêm 1 Source = "VIG Car Hub" (REST+key) để pull data curated tập trung.
 */

defined('ABSPATH') || exit;

define('VCS_VER', '0.2.1');
define('VCS_DIR', plugin_dir_path(__FILE__));
define('VCS_URL', plugin_dir_url(__FILE__));
define('VCS_POST_TYPE', 'cars');          // CPT được đồng bộ
define('VCS_URL_META', '_vcs_source_url'); // meta lưu URL nguồn mỗi xe

// Menu chung + tự-update (dùng chung mọi plugin VIG).
require_once VCS_DIR . 'includes/vig-admin-menu.php';
require_once VCS_DIR . 'includes/vig-update-checker.php';
vig_setup_updates( __FILE__, 'vig-car-sync', 'vigdigital', true );

require_once VCS_DIR . 'includes/interface-source.php';
require_once VCS_DIR . 'includes/class-source-vnexpress.php';
require_once VCS_DIR . 'includes/class-source-honda.php';
require_once VCS_DIR . 'includes/class-sources.php';
require_once VCS_DIR . 'includes/class-repository.php';
require_once VCS_DIR . 'includes/class-differ.php';
require_once VCS_DIR . 'includes/class-admin.php';

add_action('plugins_loaded', function () {
    VCS_Admin::init();
});

// WP-CLI: build kho dữ liệu xe tập trung (VIG nội bộ) — dealer không dùng.
if (defined('WP_CLI') && WP_CLI) {
    require_once VCS_DIR . 'includes/class-cli.php';
    WP_CLI::add_command('vig-car', 'VCS_CLI');
}

// Migrate 1 lần: meta URL nguồn từ bản MVP (ezsite-car-sync) sang key mới — giữ URL đã nhập ở hbtn.
add_action('init', function () {
    if (get_option('vcs_migrated_meta')) return;
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_ezcs_source_url'
    ));
    foreach ((array) $rows as $r) {
        if ($r->meta_value && !get_post_meta($r->post_id, VCS_URL_META, true)) {
            update_post_meta($r->post_id, VCS_URL_META, $r->meta_value);
        }
    }
    update_option('vcs_migrated_meta', 1);
});
