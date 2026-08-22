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
                $models[] = ['slug' => $m['slug'] ?? '', 'name' => $m['name'] ?? '', 'price' => (int) ($m['price'] ?? 0)];
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
