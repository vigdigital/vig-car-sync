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
        if (isset($_GET['vcs_flush'])) VCS_Source_Hub::flush(); // nút "Làm mới" → bỏ cache Hub
        $cars = get_posts(['post_type' => VCS_POST_TYPE, 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
        $hubrev = VCS_Source_Hub::rev_index();   // brand/slug → rev hiện tại ở hub (1 lần)
        $flush_url = wp_nonce_url(add_query_arg(['page' => 'vcs-sync', 'vcs_flush' => 1], admin_url('edit.php?post_type=' . VCS_POST_TYPE)), 'vcs_flush');
        echo '<div class="wrap vcs-wrap"><h1>Đồng bộ dữ liệu xe từ nguồn</h1>';
        echo '<p class="description">Trích xuất giá · phiên bản · thông số từ nguồn → đối chiếu với dữ liệu hiện tại. <span class="vcs-legend"><i class="vcs-dot vcs-new"></i> giá trị mới/khác sẽ tô xanh</span>. Bấm <strong>Chấp nhận</strong> để ghi đè. · <a href="' . esc_url($flush_url) . '">🔄 Làm mới dữ liệu Hub</a> (nếu vừa cập nhật kho mà chưa thấy đổi).</p>';
        echo '<table class="widefat striped vcs-list"><thead><tr><th style="width:240px">Dòng xe</th><th>URL nguồn</th><th style="width:230px">Thao tác</th></tr></thead><tbody>';
        foreach ($cars as $c) {
            $url = get_post_meta($c->ID, VCS_URL_META, true);
            // Nhãn "cần cập nhật" cho nguồn hub (so rev đã sync ↔ rev hub).
            $badge = '';
            if ($url && strpos($url, 'vighub:') === 0) {
                $key = substr($url, strlen('vighub:'));
                $site = (string) get_post_meta($c->ID, '_vcs_hub_rev', true);
                $hub  = $hubrev[$key] ?? '';
                if ($hub === '')        $badge = ' <span class="vcs-badge vcs-badge-warn" title="Không tìm thấy ở hub">? hub</span>';
                elseif ($site === '')   $badge = ' <span class="vcs-badge vcs-badge-need">Chưa sync</span>';
                elseif ($site !== $hub) $badge = ' <span class="vcs-badge vcs-badge-need">Cần cập nhật</span>';
                else                    $badge = ' <span class="vcs-badge vcs-badge-ok">Mới nhất</span>';
            }
            echo '<tr data-id="' . (int) $c->ID . '"><td><strong>' . esc_html($c->post_title) . '</strong>' . $badge . '</td>';
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

        VCS_Source_Hub::flush(); // luôn lấy data Hub MỚI khi bấm Đồng bộ (bỏ cache 1h)
        $built = self::fetch_and_build($id);
        if (is_wp_error($built)) wp_send_json_error(['msg' => $built->get_error_message()]);

        $cur  = VCS_Repository::current($id);
        $diff = VCS_Differ::diff($cur, $built);
        wp_send_json_success([
            'html'    => self::render_matrix($cur, $built),
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
        if (!empty($built['_rev'])) update_post_meta($id, '_vcs_hub_rev', $built['_rev']); // đánh dấu đã sync rev này
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
        $built['_rev']   = $data['rev'] ?? '';
        return $built;
    }

    /* ---------- Render MA TRẬN so sánh: hàng = thông số, cột = phiên bản ---------- */

    private static function money($n) { $n = (int) $n; return $n ? number_format($n, 0, ',', '.') . ' đ' : '—'; }

    /** Gộp thông số 1 phiên bản = chung + riêng (riêng ghi đè). */
    private static function version_map($common, $version_specs) {
        $m = $common;
        foreach ((array) $version_specs as $s) $m[$s['label']] = $s['value'];
        return $m;
    }

    public static function render_matrix($cur, $built) {
        $versions = (array) $built['versions'];

        // Bản đồ thông số MỚI theo từng phiên bản + thứ tự hàng.
        $common_new = [];
        foreach ((array) $built['specs'] as $s) $common_new[$s['label']] = $s['value'];
        $labels = array_keys($common_new); // hàng = mọi nhãn thông số (theo thứ tự build)
        $new_by = [];
        foreach ($versions as $v) $new_by[$v['name']] = self::version_map($common_new, $v['specs'] ?? []);

        // Bản đồ HIỆN TẠI theo phiên bản (để tô ô sẽ đổi).
        $common_cur = [];
        foreach ((array) $cur['specs'] as $s) $common_cur[$s['label']] = $s['value'];
        $cur_by = [];
        foreach ((array) $cur['versions'] as $v) {
            $cur_by[$v['name']] = ['price' => (int) $v['price'], 'map' => self::version_map($common_cur, $v['specs'] ?? [])];
        }

        $cell = function ($new, $old) {
            $chg = ($old === null) || ((string) $old !== (string) $new); // khác/mới → tô xanh
            return '<td class="' . ($chg ? 'vcs-hl' : '') . '">' . esc_html($new === '' ? '—' : $new) . '</td>';
        };

        ob_start();
        echo '<div class="vcs-diff">';
        if (!empty($built['_model'])) {
            echo '<div class="vcs-model">Nguồn: <strong>' . esc_html($built['_model']) . '</strong> — ô <span class="vcs-hl-txt">tô xanh</span> là giá trị sẽ thay đổi khi Chấp nhận.</div>';
        }
        echo '<div class="vcs-matrix-wrap"><table class="vcs-matrix"><thead><tr><th class="vcs-rowhead">Thông tin / Thông số</th>';
        foreach ($versions as $v) {
            $disc = (($v['status'] ?? 'on_sale') === 'discontinued');
            echo '<th' . ($disc ? ' class="vcs-col-disc"' : '') . '>' . esc_html($v['name']) . ($disc ? '<br><small>⛔ ngừng bán</small>' : '') . '</th>';
        }
        echo '</tr></thead><tbody>';

        // Hàng Giá
        echo '<tr><td class="vcs-rowhead">Giá</td>';
        foreach ($versions as $v) {
            $old = isset($cur_by[$v['name']]) ? $cur_by[$v['name']]['price'] : null;
            echo $cell(self::money($v['price']), $old === null ? null : self::money($old));
        }
        echo '</tr>';

        // Hàng thông số
        foreach ($labels as $label) {
            echo '<tr><td class="vcs-rowhead">' . esc_html($label) . '</td>';
            foreach ($versions as $v) {
                $new = $new_by[$v['name']][$label] ?? '';
                $old = isset($cur_by[$v['name']]['map'][$label]) ? $cur_by[$v['name']]['map'][$label] : null;
                echo $cell($new, $old);
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Phiên bản cũ không còn ở nguồn (nếu có)
        $new_names = array_column($versions, 'name');
        $removed = [];
        foreach ((array) $cur['versions'] as $v) if (!in_array($v['name'], $new_names, true)) $removed[] = $v['name'];
        if ($removed) echo '<p class="vcs-removed">⚠️ Phiên bản không còn ở nguồn (sẽ bị xoá khi ghi): <strong>' . esc_html(implode(', ', $removed)) . '</strong></p>';

        echo '<div class="vcs-actions"><button class="button button-primary vcs-accept">Chấp nhận đồng bộ</button> <button class="button vcs-cancel">Huỷ</button></div>';
        echo '</div>';
        return ob_get_clean();
    }
}
