<?php
defined('ABSPATH') || exit;

class VCS_Admin {

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'metabox']);
        add_action('save_post_' . VCS_POST_TYPE, [__CLASS__, 'save_metabox']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_ajax_vcs_preview', [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_vcs_apply', [__CLASS__, 'ajax_apply']);
    }

    /* ---------- Metabox: URL nguồn trên mỗi xe ---------- */
    public static function metabox() {
        add_meta_box('vcs_source', 'Nguồn dữ liệu (đồng bộ)', [__CLASS__, 'metabox_html'], VCS_POST_TYPE, 'side', 'high');
    }

    public static function metabox_html($post) {
        wp_nonce_field('vcs_save_url', 'vcs_url_nonce');
        $url = get_post_meta($post->ID, VCS_URL_META, true);

        // Dropdown chọn từ kho VIG Car Hub (nhanh, không cần dán link).
        $brands = VCS_Source_Hub::index();
        if ($brands) {
            echo '<p><label for="vcs_hub_pick"><strong>Chọn từ kho VIG Car Hub</strong></label></p>';
            echo '<select id="vcs_hub_pick" style="width:100%"><option value="">— chọn model —</option>';
            foreach ($brands as $b) {
                echo '<optgroup label="' . esc_attr($b['brand_name'] ?? $b['brand']) . '">';
                foreach ((array) ($b['models'] ?? []) as $m) {
                    $ref = 'vighub:' . $b['brand'] . '/' . ($m['slug'] ?? '');
                    $lbl = ($m['name'] ?? $m['slug']) . ($m['price'] ? ' — ' . number_format((int) $m['price'], 0, ',', '.') . 'đ' : '');
                    echo '<option value="' . esc_attr($ref) . '"' . selected($url, $ref, false) . '>' . esc_html($lbl) . '</option>';
                }
                echo '</optgroup>';
            }
            echo '</select>';
            echo '<p class="description" style="margin:4px 0 10px">Hoặc dán link nguồn (Honda/VnExpress) bên dưới.</p>';
        }

        echo '<p><label for="vcs_url"><strong>Tham chiếu nguồn</strong></label></p>';
        echo '<input type="text" id="vcs_url" name="vcs_url" value="' . esc_attr($url) . '" placeholder="vighub:honda/honda-city  hoặc  https://vnexpress.net/..." style="width:100%">';
        echo '<p class="description"><code>vighub:hãng/slug</code> (kho VIG) hoặc link Honda/VnExpress. Sau đó vào <em>Xe → Đồng bộ dữ liệu</em> để đối chiếu.</p>';
        if ($url) {
            $page = admin_url('edit.php?post_type=' . VCS_POST_TYPE . '&page=vcs-sync');
            echo '<p><a class="button button-secondary" href="' . esc_url($page) . '">Mở trang đồng bộ</a></p>';
        }
        // đổ lựa chọn dropdown vào ô input
        echo '<script>(function(){var s=document.getElementById("vcs_hub_pick"),u=document.getElementById("vcs_url");if(s&&u)s.addEventListener("change",function(){if(s.value)u.value=s.value;});})();</script>';
    }

    public static function save_metabox($post_id) {
        if (!isset($_POST['vcs_url_nonce']) || !wp_verify_nonce($_POST['vcs_url_nonce'], 'vcs_save_url')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $raw = isset($_POST['vcs_url']) ? trim(wp_unslash($_POST['vcs_url'])) : '';
        // Chấp nhận cả tham chiếu hub (vighub:hãng/slug) lẫn URL http(s).
        if (strpos($raw, 'vighub:') === 0) {
            $val = preg_replace('~[^a-z0-9:/_-]~i', '', $raw); // slug an toàn
        } else {
            $val = esc_url_raw($raw);
        }
        if ($val) update_post_meta($post_id, VCS_URL_META, $val);
        else delete_post_meta($post_id, VCS_URL_META);
    }

    /* ---------- Trang admin: Đồng bộ dữ liệu ---------- */
    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . VCS_POST_TYPE,
            'Đồng bộ dữ liệu xe', 'Đồng bộ dữ liệu', 'edit_posts', 'vcs-sync', [__CLASS__, 'page']
        );
    }

    public static function page() {
        $cars = get_posts(['post_type' => VCS_POST_TYPE, 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
        echo '<div class="wrap vcs-wrap"><h1>Đồng bộ dữ liệu xe từ nguồn</h1>';
        echo '<p class="description">Trích xuất giá · phiên bản · thông số từ nguồn (VnExpress) → đối chiếu với dữ liệu hiện tại. <span class="vcs-legend"><i class="vcs-dot vcs-new"></i> giá trị mới/khác sẽ tô xanh</span>. Bấm <strong>Chấp nhận</strong> để ghi đè.</p>';
        echo '<table class="widefat striped vcs-list"><thead><tr><th style="width:220px">Dòng xe</th><th>URL nguồn</th><th style="width:230px">Thao tác</th></tr></thead><tbody>';
        foreach ($cars as $c) {
            $url = get_post_meta($c->ID, VCS_URL_META, true);
            echo '<tr data-id="' . (int) $c->ID . '"><td><strong>' . esc_html($c->post_title) . '</strong></td>';
            echo '<td class="vcs-url">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>' : '<em>Chưa có URL — thêm ở trang sửa xe</em>') . '</td>';
            echo '<td>';
            if ($url) echo '<button class="button button-primary vcs-sync" data-id="' . (int) $c->ID . '">Đồng bộ</button> <span class="vcs-status"></span>';
            else echo '<a class="button" href="' . esc_url(get_edit_post_link($c->ID)) . '">Thêm URL</a>';
            echo '</td></tr>';
            echo '<tr class="vcs-diff-row" data-id="' . (int) $c->ID . '" style="display:none"><td colspan="3" class="vcs-diff-cell"></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function assets($hook) {
        $on_sync = ($hook === VCS_POST_TYPE . '_page_vcs-sync');
        $on_edit = in_array($hook, ['post.php', 'post-new.php'], true) && get_post_type() === VCS_POST_TYPE;
        if (!$on_sync && !$on_edit) return;
        wp_enqueue_style('vcs-admin', VCS_URL . 'assets/admin.css', [], VCS_VER);
        if ($on_sync) {
            wp_enqueue_script('vcs-admin', VCS_URL . 'assets/admin.js', [], VCS_VER, true);
            wp_localize_script('vcs-admin', 'VCS', [
                'ajax'  => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vcs_sync'),
            ]);
        }
    }

    /* ---------- AJAX ---------- */
    public static function ajax_preview() {
        check_ajax_referer('vcs_sync', 'nonce');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['msg' => 'Không có quyền.']);

        $built = self::fetch_and_build($id);
        if (is_wp_error($built)) wp_send_json_error(['msg' => $built->get_error_message()]);

        $diff = VCS_Differ::diff(VCS_Repository::current($id), $built);
        wp_send_json_success([
            'html'    => self::render_diff($diff, $built['_model']),
            'changes' => VCS_Differ::count_changes($diff),
        ]);
    }

    public static function ajax_apply() {
        check_ajax_referer('vcs_sync', 'nonce');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id || !current_user_can('edit_post', $id)) wp_send_json_error(['msg' => 'Không có quyền.']);

        $built = self::fetch_and_build($id); // re-fetch để đảm bảo ghi đúng dữ liệu nguồn mới nhất
        if (is_wp_error($built)) wp_send_json_error(['msg' => $built->get_error_message()]);

        $ok = VCS_Repository::apply($id, $built);
        if (!$ok) wp_send_json_error(['msg' => 'Không ghi được (thiếu Carbon Fields?).']);
        wp_send_json_success(['msg' => 'Đã đồng bộ: ' . count($built['versions']) . ' phiên bản · ' . count($built['specs']) . ' thông số.']);
    }

    /** Fetch nguồn + build dữ liệu mới đã map. Trả WP_Error nếu lỗi. */
    private static function fetch_and_build($id) {
        $url = get_post_meta($id, VCS_URL_META, true);
        if (!$url) return new WP_Error('no_url', 'Xe chưa có URL nguồn.');
        $source = VCS_Sources::detect($url);
        if (!$source) return new WP_Error('no_source', 'Không nhận diện được nguồn cho URL này.');
        $data = $source->fetch($url);
        if (empty($data['ok'])) return new WP_Error('fetch', $data['error'] ?: 'Lỗi tải/parse nguồn.');
        $built = VCS_Repository::build_new($id, $data);
        $built['_model'] = $data['model'];
        return $built;
    }

    /* ---------- Render bảng so sánh ---------- */
    public static function render_diff($diff, $model) {
        $groups = [
            'price'    => 'Giá',
            'versions' => 'Phiên bản',
            'specs'    => 'Thông số kỹ thuật',
        ];
        ob_start();
        echo '<div class="vcs-diff">';
        if ($model) echo '<div class="vcs-model">Nguồn: <strong>' . esc_html($model) . '</strong></div>';
        echo '<table class="vcs-table"><thead><tr><th>Trường</th><th>Hiện tại</th><th>Dữ liệu mới</th></tr></thead><tbody>';
        foreach ($groups as $key => $title) {
            echo '<tr class="vcs-group"><td colspan="3">' . esc_html($title) . '</td></tr>';
            foreach ($diff[$key] as $r) {
                $cls = 'vcs-' . $r['status'];
                echo '<tr class="' . $cls . '">';
                echo '<td class="vcs-field">' . esc_html($r['field']) . '</td>';
                echo '<td class="vcs-cur">' . esc_html($r['current']) . '</td>';
                echo '<td class="vcs-newval">' . esc_html($r['new']);
                if ($r['status'] === 'new') echo ' <span class="vcs-tag">mới</span>';
                elseif ($r['status'] === 'changed') echo ' <span class="vcs-tag">đổi</span>';
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
        echo '<div class="vcs-actions"><button class="button button-primary vcs-accept">Chấp nhận đồng bộ</button> <button class="button vcs-cancel">Huỷ</button></div>';
        echo '</div>';
        return ob_get_clean();
    }
}
