<?php
defined('ABSPATH') || exit;

/**
 * Target = site dealer (Carbon Fields trên CPT cars). Đọc dữ liệu hiện tại + ghi dữ liệu mới.
 *
 * CẤU TRÚC (hướng A — generic, không phụ thuộc nhãn thông số):
 *   - car_versions[]: name, price, status, specs[] (complex lồng: spec_label/spec_value).
 *   - car_specs[]:    thông số CHUNG (spec_label/spec_value).
 *   → Thêm/bớt loại thông số = chỉ data; theme lặp generic (không hardcode nhãn).
 *
 * Đọc còn fallback field phẳng ver_* (dữ liệu cũ) — xem VER_MAP.
 * Theme lấy dữ liệu để render qua vig_car_data($id) → self::for_display().
 */
class VCS_Repository {

    /** (Fallback đọc dữ liệu CŨ) nhãn spec → sub-field ver_* phẳng của theme đời trước. */
    const VER_MAP = [
        'Động cơ'                => 'ver_engine',
        'Công suất'              => 'ver_power',
        'Mô-men xoắn'            => 'ver_torque',
        'Hộp số'                 => 'ver_transmission',
        'Nhiên liệu'             => 'ver_fuel',
        'Số chỗ ngồi'            => 'ver_seats',
        'Dẫn động'               => 'ver_drivetrain',
        'Mức tiêu thụ (hỗn hợp)' => 'ver_consumption',
        'Trọng lượng (bản thân)' => 'ver_weight',
    ];

    /** Tên dòng rút gọn để dựng tên phiên bản: "Honda City" → "City". */
    public static function shortname($post_id) {
        $t = get_the_title($post_id);
        return trim(preg_replace('/^\s*honda\s+/i', '', $t));
    }

    /** Fallback: đọc ver_* của 1 phiên bản (dữ liệu cũ) → [[label,value]]. */
    private static function read_ver_specs($v) {
        $out = [];
        foreach (self::VER_MAP as $label => $field) {
            if (isset($v[$field]) && $v[$field] !== '') $out[] = ['label' => $label, 'value' => (string) $v[$field]];
        }
        return $out;
    }

    /** Đọc specs lồng của 1 phiên bản; nếu trống → fallback ver_* cũ. */
    private static function read_version_specs($v) {
        $out = [];
        foreach ((array) ($v['specs'] ?? []) as $s) {
            $l = (string) ($s['spec_label'] ?? '');
            if ($l !== '') $out[] = ['label' => $l, 'value' => (string) ($s['spec_value'] ?? '')];
        }
        return $out ?: self::read_ver_specs($v);
    }

    /** Dữ liệu hiện tại: ['price'=>int, 'versions'=>[[name,price,status,specs]], 'specs'=>[[label,value]]]. */
    public static function current($post_id) {
        $versions = [];
        foreach ((array) self::cf($post_id, 'car_versions') as $v) {
            $versions[] = [
                'name'   => (string) ($v['name'] ?? ''),
                'price'  => (int) preg_replace('/\D/', '', (string) ($v['price'] ?? '')),
                'status' => (($v['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                'specs'  => self::read_version_specs($v),
            ];
        }
        $specs = [];
        foreach ((array) self::cf($post_id, 'car_specs') as $s) {
            $specs[] = ['label' => (string) ($s['spec_label'] ?? ''), 'value' => (string) ($s['spec_value'] ?? '')];
        }
        return [
            'price'    => (int) preg_replace('/\D/', '', (string) self::cf($post_id, 'car_price')),
            'versions' => $versions,
            'specs'    => $specs,
        ];
    }

    /**
     * Dữ liệu để THEME render (generic). Mỗi spec kèm 'key' = sanitize_title(nhãn) để theme
     * gắn data-spec-key (JS đổi thông số theo bản khớp key này — không cần map cứng).
     *   ['price'=>int, 'versions'=>[['name','price','status','specs'=>[['label','value','key']]]], 'common'=>[[label,value,key]]]
     */
    public static function for_display($post_id) {
        $c = self::current($post_id);
        $withkey = function ($s) {
            return ['label' => $s['label'], 'value' => $s['value'], 'key' => sanitize_title($s['label'])];
        };
        $versions = array_map(function ($v) use ($withkey) {
            $v['specs'] = array_map($withkey, $v['specs']);
            return $v;
        }, $c['versions']);
        return [
            'price'    => $c['price'],
            'versions' => $versions,
            'common'   => array_map($withkey, $c['specs']),
        ];
    }

    /**
     * Dựng giá trị "mới" từ nguồn, để so sánh & ghi.
     * - versions: name = shortname + ' ' + label; giữ status + specs riêng.
     * - car_specs (CHUNG) = specs riêng của BẢN BASE (bản on_sale đầu tiên) + specs chung của dòng.
     *   → bản base cung cấp các dòng có data-spec-key để theme override khi bấm tab bản khác.
     */
    public static function build_new($post_id, $normalized) {
        $short = self::shortname($post_id);
        $cur = self::current($post_id);

        $versions = [];
        foreach ((array) $normalized['versions'] as $v) {
            $label = $v['label'] ?? ($v['name'] ?? '');
            $versions[] = [
                'name'   => trim($short . ' ' . $label),
                'price'  => (int) $v['price'],
                'status' => (($v['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                'specs'  => isset($v['specs']) && is_array($v['specs']) ? $v['specs'] : [],
            ];
        }

        // Bản base = bản on_sale đầu tiên (fallback bản đầu).
        $base = null;
        foreach ($versions as $v) { if ($v['status'] !== 'discontinued') { $base = $v; break; } }
        if ($base === null) $base = $versions[0] ?? null;

        // car_specs (chung) = specs riêng của bản base (để có dòng keyed) + specs chung của dòng.
        $specs = [];
        $seen = [];
        $push = function ($label, $value) use (&$specs, &$seen) {
            if ($label === '' || isset($seen[$label])) return;
            $specs[] = ['label' => $label, 'value' => $value]; $seen[$label] = true;
        };
        if ($base) foreach ($base['specs'] as $s) $push($s['label'], $s['value']);
        foreach ((array) $normalized['specs'] as $s) $push($s['label'], $s['value']);
        foreach ($cur['specs'] as $s) if (!isset($seen[$s['label']])) $push($s['label'], $s['value']);

        return [
            'price'    => (int) $normalized['price'],
            'versions' => $versions,
            'specs'    => $specs,
        ];
    }

    /** Ghi vào Carbon Fields (generic: car_versions.specs lồng). Trả về true/false. */
    public static function apply($post_id, $built) {
        if (!function_exists('carbon_set_post_meta')) return false;
        self::set($post_id, 'car_price', (string) $built['price']);
        self::set($post_id, 'car_versions', array_map(function ($v) {
            return [
                'name'   => $v['name'],
                'price'  => (string) $v['price'],
                'status' => $v['status'] ?? 'on_sale',
                'specs'  => array_map(function ($s) {
                    return ['spec_label' => $s['label'], 'spec_value' => $s['value']];
                }, isset($v['specs']) && is_array($v['specs']) ? $v['specs'] : []),
            ];
        }, $built['versions']));
        self::set($post_id, 'car_specs', array_map(function ($s) {
            return ['spec_label' => $s['label'], 'spec_value' => $s['value']];
        }, $built['specs']));
        return true;
    }

    /** Set + verify + retry 1 lần (Carbon Fields đôi khi bỏ lần ghi complex đầu tiên trong 1 request). */
    private static function set($post_id, $field, $value) {
        carbon_set_post_meta($post_id, $field, $value);
        if (is_array($value)) {
            $back = carbon_get_post_meta($post_id, $field);
            if (empty($back) && !empty($value)) carbon_set_post_meta($post_id, $field, $value);
        }
    }

    private static function cf($post_id, $field) {
        return function_exists('carbon_get_post_meta') ? carbon_get_post_meta($post_id, $field) : null;
    }
}
