<?php
defined('ABSPATH') || exit;

/**
 * Nguồn = KHO XE TẬP TRUNG của VIG (VIG Car Hub).
 * Thay vì mỗi site tự scrape web, site pull data đã curate từ hub (JSON theo hãng).
 *
 * Tham chiếu lưu trong meta: `vighub:<brand>/<slug>`  (vd vighub:honda/honda-city).
 * Base URL kho: hằng số VCS_HUB_BASE (wp-config) hoặc mặc định GitHub raw (public).
 *   → khi VPS VIG sẵn sàng: define('VCS_HUB_BASE', 'https://data.vigdigital.com/cars/');
 */
class VCS_Source_Hub implements VCS_Source_Interface {

    const DEFAULT_BASE = 'https://raw.githubusercontent.com/vigdigital/vig-car-data/master/data/';

    public function id() { return 'hub'; }
    public function label() { return 'VIG Car Hub'; }
    public function matches($url) { return is_string($url) && strpos($url, 'vighub:') === 0; }

    public static function base() {
        $b = defined('VCS_HUB_BASE') && VCS_HUB_BASE ? VCS_HUB_BASE : self::DEFAULT_BASE;
        return trailingslashit($b);
    }

    /** GET + decode 1 file JSON của hub (cache 1h). */
    public static function get_json($file) {
        $key = 'vcs_hub_' . md5($file);
        $cached = get_transient($key);
        if ($cached !== false) return $cached;
        $res = wp_remote_get(self::base() . ltrim($file, '/'), ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (is_array($data)) set_transient($key, $data, HOUR_IN_SECONDS);
        return is_array($data) ? $data : null;
    }

    /** index.json → danh sách hãng + model (cho dropdown chọn). */
    public static function index() {
        $idx = self::get_json('index.json');
        return is_array($idx) && !empty($idx['brands']) ? $idx['brands'] : [];
    }

    public function fetch($ref) {
        // ref: vighub:honda/honda-city
        $path = substr($ref, strlen('vighub:'));
        list($brand, $slug) = array_pad(explode('/', $path, 2), 2, '');
        $brand = sanitize_key($brand);
        $slug  = sanitize_title($slug);
        if ($brand === '' || $slug === '') return $this->err('Tham chiếu hub không hợp lệ (cần vighub:hãng/slug).');

        $data = self::get_json($brand . '.json');
        if (!$data) return $this->err("Không tải được kho hãng '$brand' từ VIG Car Hub.");

        $model = null;
        foreach ((array) ($data['models'] ?? []) as $m) {
            if (($m['slug'] ?? '') === $slug) { $model = $m; break; }
        }
        if (!$model) return $this->err("Không tìm thấy model '$slug' trong kho '$brand'.");

        // Map JSON hub → định dạng chuẩn của interface (versions: name→label).
        // Mang theo status (on_sale|discontinued) + specs RIÊNG của từng phiên bản.
        $versions = [];
        foreach ((array) ($model['versions'] ?? []) as $v) {
            $vspecs = [];
            foreach ((array) ($v['specs'] ?? []) as $s) {
                $vspecs[] = ['label' => (string) ($s['label'] ?? ''), 'value' => (string) ($s['value'] ?? '')];
            }
            $status = ($v['status'] ?? 'on_sale');
            $versions[] = [
                'label'  => (string) ($v['name'] ?? ''),
                'price'  => (int) ($v['price'] ?? 0),
                'status' => in_array($status, ['on_sale', 'discontinued'], true) ? $status : 'on_sale',
                'specs'  => $vspecs,
            ];
        }
        $specs = [];
        foreach ((array) ($model['specs'] ?? []) as $s) {
            $specs[] = ['label' => (string) ($s['label'] ?? ''), 'value' => (string) ($s['value'] ?? '')];
        }
        return [
            'ok' => true, 'error' => null, 'source' => $this->id(),
            'model' => (string) ($model['name'] ?? ''), 'price' => (int) ($model['price'] ?? 0),
            'versions' => $versions, 'specs' => $specs,
        ];
    }

    private function err($m) { return ['ok' => false, 'error' => $m, 'source' => $this->id(), 'model' => '', 'price' => 0, 'versions' => [], 'specs' => []]; }
}
