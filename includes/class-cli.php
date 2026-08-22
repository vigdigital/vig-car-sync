<?php
defined('ABSPATH') || exit;

/**
 * VCS_CLI — build kho dữ liệu xe tập trung (VIG Car Hub) từ nguồn, ra JSON theo hãng.
 * Chạy trên 1 WordPress có plugin này (dùng lại đúng Source/chuẩn hoá mà dealer tin dùng).
 *
 *   wp vig-car build --sources=/path/vig-car-data/sources.json --out=/path/vig-car-data/data
 *
 * sources.json: { "honda": { "brand_name":"Honda", "urls":[ "...", ... ] }, ... }
 * output:       data/<brand>.json (schema: xem vig-car-data/schema.json)
 */
class VCS_CLI {

    /**
     * PULL (consumer): kéo dữ liệu từ nguồn (VIG Car Hub / Honda / VnExpress) → ghi vào xe.
     * Tương đương bấm "Đồng bộ" trong WP-Admin, nhưng chạy dòng lệnh.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : ID 1 xe (CPT cars) cần đồng bộ.
     *
     * [--all]
     * : Đồng bộ MỌI xe có sẵn URL nguồn (bỏ qua --post).
     *
     * [--source=<ref>]
     * : Ép nguồn cho lần chạy này (vd vighub:honda/honda-cr-v). Kèm --post sẽ lưu luôn vào xe.
     *
     * [--dry-run]
     * : Chỉ xem bảng thay đổi, KHÔNG ghi.
     *
     * [--yes]
     * : Ghi luôn, không hỏi xác nhận.
     *
     * ## EXAMPLES
     *
     *     wp vig-car pull --post=123 --source=vighub:honda/honda-cr-v --yes
     *     wp vig-car pull --all --dry-run
     *
     * @when after_wp_load
     */
    public function pull($args, $assoc) {
        $post_id = isset($assoc['post']) ? (int) $assoc['post'] : 0;
        $all     = isset($assoc['all']);
        $source  = isset($assoc['source']) ? trim((string) $assoc['source']) : '';
        $dry     = isset($assoc['dry-run']);
        $yes     = isset($assoc['yes']);
        $changed = isset($assoc['changed']);

        VCS_Source_Hub::flush(); // đồng bộ = luôn lấy data Hub mới nhất (bỏ cache)

        // Ép nguồn + lưu vào xe (khi có --post).
        if ($source !== '' && $post_id) update_post_meta($post_id, VCS_URL_META, $source);

        // --changed: chỉ đụng xe có rev ở hub KHÁC rev đã sync (tải 1 file index).
        $hubrev = $changed ? VCS_Source_Hub::rev_index() : [];

        // Danh sách xe cần xử lý.
        $ids = [];
        if ($all) {
            $ids = get_posts([
                'post_type'   => VCS_POST_TYPE, 'post_status' => 'any',
                'numberposts' => -1, 'fields' => 'ids',
                'meta_key'    => VCS_URL_META, 'meta_compare' => 'EXISTS',
            ]);
        } elseif ($post_id) {
            $ids = [$post_id];
        } else {
            \WP_CLI::error('Cần --post=<id> hoặc --all.');
        }
        if (!$ids) \WP_CLI::error('Không có xe nào để đồng bộ (thiếu URL nguồn?).');

        $done = 0; $skip = 0; $fresh = 0; $changed_total = 0;
        foreach ($ids as $id) {
            $title = get_the_title($id) ?: "#$id";
            $ref = ($source !== '' && (!$all)) ? $source : (string) get_post_meta($id, VCS_URL_META, true);
            if ($ref === '') { \WP_CLI::warning("[$title] chưa có URL nguồn — bỏ qua."); $skip++; continue; }

            // --changed: bỏ qua xe đã khớp rev hub (không cần sync).
            if ($changed && strpos($ref, 'vighub:') === 0) {
                $key = substr($ref, strlen('vighub:'));
                $cur = (string) get_post_meta($id, '_vcs_hub_rev', true);
                if ($cur !== '' && isset($hubrev[$key]) && $hubrev[$key] === $cur) { $fresh++; continue; }
            }

            $src = VCS_Sources::detect($ref);
            if (!$src) { \WP_CLI::warning("[$title] không nhận diện nguồn: $ref — bỏ qua."); $skip++; continue; }
            $data = $src->fetch($ref);
            if (empty($data['ok'])) { \WP_CLI::warning("[$title] lỗi nguồn: " . ($data['error'] ?: '?')); $skip++; continue; }

            $built = VCS_Repository::build_new($id, $data);
            $diff  = VCS_Differ::diff(VCS_Repository::current($id), $built);
            $n     = VCS_Differ::count_changes($diff);
            $changed_total += $n;

            \WP_CLI::log("── [$title] nguồn=" . $data['source'] . " · " . count($built['versions']) . " bản · " . count($built['specs']) . " spec chung · thay đổi: $n");
            self::print_diff_rows($diff);

            if ($dry) { \WP_CLI::log('   (dry-run: không ghi)'); $done++; continue; }
            if (!$yes) \WP_CLI::confirm("   Ghi thay đổi cho [$title]?");

            $ok = VCS_Repository::apply($id, $built);
            if (!$ok) { \WP_CLI::warning("[$title] KHÔNG ghi được (thiếu Carbon Fields?)."); $skip++; continue; }
            if (!empty($data['rev'])) update_post_meta($id, '_vcs_hub_rev', $data['rev']); // đánh dấu đã sync rev này
            \WP_CLI::log("   ✓ đã ghi [$title]");
            $done++;
        }

        $tail = $fresh ? " · $fresh đã mới nhất (bỏ qua)" : '';
        if ($dry) \WP_CLI::success("Dry-run xong: $done xe · tổng $changed_total thay đổi · $skip bỏ qua$tail.");
        else      \WP_CLI::success("Đồng bộ xong: $done xe ghi · $skip bỏ qua$tail.");
    }

    /**
     * STATUS: xe nào CẦN đồng bộ (so rev đã sync ↔ rev ở hub). Chỉ tải index.json.
     *
     * ## OPTIONS
     *
     * [--changed]
     * : Chỉ liệt kê xe cần cập nhật (bỏ xe đã mới nhất).
     *
     * ## EXAMPLES
     *
     *     wp vig-car status
     *     wp vig-car status --changed
     *
     * @when after_wp_load
     */
    public function status($args, $assoc) {
        $only_changed = isset($assoc['changed']);
        VCS_Source_Hub::flush(); // lấy index rev mới nhất
        $hubrev = VCS_Source_Hub::rev_index();

        $ids = get_posts([
            'post_type'   => VCS_POST_TYPE, 'post_status' => 'any',
            'numberposts' => -1, 'fields' => 'ids',
            'meta_key'    => VCS_URL_META, 'meta_compare' => 'EXISTS',
        ]);

        $rows = []; $need = 0;
        foreach ($ids as $id) {
            $ref = (string) get_post_meta($id, VCS_URL_META, true);
            $site = (string) get_post_meta($id, '_vcs_hub_rev', true);
            if (strpos($ref, 'vighub:') === 0) {
                $key = substr($ref, strlen('vighub:'));
                $hub = $hubrev[$key] ?? '';
                if ($hub === '')            $state = 'không có ở hub';
                elseif ($site === '')       $state = 'CHƯA SYNC';
                elseif ($site !== $hub)     $state = 'CẦN CẬP NHẬT';
                else                        $state = 'đã mới nhất';
            } else {
                $key = $ref; $hub = '(nguồn khác)'; $state = $site ? 'đã sync' : 'chưa sync';
            }
            $is_need = in_array($state, ['CHƯA SYNC', 'CẦN CẬP NHẬT'], true);
            if ($is_need) $need++;
            if ($only_changed && !$is_need) continue;
            $rows[] = [
                'id'        => $id,
                'xe'        => get_the_title($id) ?: "#$id",
                'nguon'     => $key,
                'rev_site'  => $site ?: '—',
                'rev_hub'   => $hub ?: '—',
                'trang_thai'=> $state,
            ];
        }

        if (!$rows) { \WP_CLI::success($only_changed ? 'Tất cả đã mới nhất.' : 'Không có xe nào có nguồn.'); return; }
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'xe', 'nguon', 'rev_site', 'rev_hub', 'trang_thai']);
        \WP_CLI::log("→ $need xe cần đồng bộ. Chạy: wp vig-car pull --all --changed --yes");
    }

    /** In gọn các dòng khác/mới của diff (bỏ dòng 'same'). */
    private static function print_diff_rows($diff) {
        $mark = ['changed' => '~', 'new' => '+', 'removed' => '-'];
        foreach (['price', 'versions', 'specs'] as $g) {
            foreach ((array) $diff[$g] as $r) {
                if (($r['status'] ?? 'same') === 'same') continue;
                $m = $mark[$r['status']] ?? '?';
                \WP_CLI::log(sprintf('     %s %-22s %s → %s', $m, $r['field'], $r['current'], $r['new']));
            }
        }
    }

    /**
     * Scrape các URL trong sources.json → ghi data/<brand>.json (merge theo source_url).
     *
     * [--sources=<file>] : đường dẫn sources.json (mặc định ./sources.json)
     * [--out=<dir>]      : thư mục output (mặc định ./data)
     * [--brand=<slug>]   : chỉ build 1 hãng
     * [--delay=<ms>]     : nghỉ giữa 2 request (mặc định 800ms, lịch sự với nguồn)
     */
    public function build($args, $assoc) {
        $sources_file = $assoc['sources'] ?? getcwd() . '/sources.json';
        $out_dir      = rtrim($assoc['out'] ?? getcwd() . '/data', '/');
        $only_brand   = $assoc['brand'] ?? '';
        $delay        = isset($assoc['delay']) ? (int) $assoc['delay'] : 800;

        if (!is_readable($sources_file)) \WP_CLI::error("Không đọc được sources: $sources_file");
        $sources = json_decode((string) file_get_contents($sources_file), true);
        if (!is_array($sources)) \WP_CLI::error('sources.json không hợp lệ.');
        if (!is_dir($out_dir) && !wp_mkdir_p($out_dir)) \WP_CLI::error("Không tạo được thư mục out: $out_dir");

        foreach ($sources as $brand => $conf) {
            if ($only_brand && $brand !== $only_brand) continue;
            $urls = (array) ($conf['urls'] ?? []);
            \WP_CLI::log("== $brand: " . count($urls) . " URL ==");

            // giữ data cũ để merge (update theo source_url, thêm mới ở cuối)
            $out_file = "$out_dir/$brand.json";
            $existing = [];
            if (is_readable($out_file)) {
                $prev = json_decode((string) file_get_contents($out_file), true);
                foreach ((array) ($prev['models'] ?? []) as $m) {
                    if (!empty($m['source_url'])) $existing[$m['source_url']] = $m;
                }
            }

            $ok = 0; $fail = 0;
            foreach ($urls as $item) {
                // item: chuỗi URL, hoặc { url, name?, slug? } (VIG khai báo tên model ở sources.json)
                $url  = is_array($item) ? (string) ($item['url'] ?? '') : (string) $item;
                $name_override = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
                $slug_override = is_array($item) ? trim((string) ($item['slug'] ?? '')) : '';
                if ($url === '') { \WP_CLI::warning('  bỏ qua entry thiếu url'); $fail++; continue; }

                // Model đã CURATE tay + khoá ("locked": true) → giữ nguyên, không scrape đè.
                if (!empty($existing[$url]['locked'])) {
                    \WP_CLI::log('  🔒 giữ nguyên (locked): ' . ($existing[$url]['name'] ?? $url));
                    $ok++;
                    continue;
                }

                $source = VCS_Sources::detect($url);
                if (!$source) { \WP_CLI::warning("  bỏ qua (không nhận nguồn): $url"); $fail++; continue; }
                $data = $source->fetch($url);
                if (empty($data['ok'])) { \WP_CLI::warning("  lỗi: $url — " . ($data['error'] ?? '?')); $fail++; continue; }

                $model = $name_override ?: trim((string) $data['model']);
                $entry = [
                    'slug'       => $slug_override ?: ($model ? sanitize_title($model) : sanitize_title(basename(parse_url($url, PHP_URL_PATH)))),
                    'name'       => $model,
                    'year'       => (preg_match('/\b(20\d{2})\b/', $model, $y) ? (int) $y[1] : null),
                    'source'     => $data['source'],
                    'source_url' => $url,
                    'price'      => (int) $data['price'],
                    'versions'   => array_map(function ($v) {
                        $out = ['name' => $v['label'] ?? ($v['name'] ?? ''), 'price' => (int) $v['price']];
                        if (!empty($v['status'])) $out['status'] = $v['status'];      // on_sale|discontinued (nếu nguồn có)
                        if (!empty($v['specs']))  $out['specs']  = array_values((array) $v['specs']); // thông số riêng của bản
                        return $out;
                    }, (array) $data['versions']),
                    'specs'      => array_values((array) $data['specs']),
                    'updated_at' => gmdate('c'),
                ];
                $existing[$url] = $entry; // merge/replace theo URL
                \WP_CLI::log("  ✓ " . ($model ?: $url) . " — {$entry['price']}đ · " . count($entry['versions']) . " bản · " . count($entry['specs']) . " spec");
                $ok++;
                if ($delay > 0) usleep($delay * 1000);
            }

            $payload = [
                'brand'      => $brand,
                'brand_name' => (string) ($conf['brand_name'] ?? ucfirst($brand)),
                'updated_at' => gmdate('c'),
                'count'      => count($existing),
                'models'     => array_values($existing),
            ];
            file_put_contents($out_file, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            \WP_CLI::success("$brand → $out_file ({$payload['count']} model · $ok ok · $fail lỗi)");
        }

        // index.json: gom mọi brand file để dealer duyệt nhanh (không tải hết từng file).
        $this->write_index($out_dir);
    }

    /** Quét mọi <brand>.json trong out → index.json (brands + model tóm tắt). */
    private function write_index($out_dir) {
        $brands = [];
        foreach ((array) glob("$out_dir/*.json") as $f) {
            if (basename($f) === 'index.json') continue;
            $b = json_decode((string) file_get_contents($f), true);
            if (!is_array($b) || empty($b['brand'])) continue;
            $models = [];
            foreach ((array) ($b['models'] ?? []) as $m) {
                $models[] = [
                    'slug'   => $m['slug'] ?? '',
                    'name'   => $m['name'] ?? '',
                    'price'  => (int) ($m['price'] ?? 0),
                    'status' => (($m['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                    'rev'    => VCS_Source_Hub::rev($m),   // mã băm nội dung → consumer biết model đã đổi chưa
                ];
            }
            $brands[] = [
                'brand'      => $b['brand'],
                'brand_name' => $b['brand_name'] ?? ucfirst($b['brand']),
                'file'       => basename($f),
                'count'      => count($models),
                'models'     => $models,
            ];
        }
        $index = ['updated_at' => gmdate('c'), 'brands' => $brands];
        file_put_contents("$out_dir/index.json", wp_json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        \WP_CLI::log('index.json: ' . count($brands) . ' hãng.');
    }
}
