<?php
defined('ABSPATH') || exit;

/**
 * Target = site HBTN (Carbon Fields trên CPT cars).
 * Đọc dữ liệu hiện tại + ghi dữ liệu mới. (Sau này có thể thay bằng target khác.)
 *
 * LƯU Ý cấu trúc HBTN (khác contract generic):
 *   - car_versions[]: name, price, status, + thông số riêng dạng field PHẲNG ver_* (không phải specs lồng).
 *   - car_specs[]:    thông số CHUNG (spec_label/spec_value). Theme override ver_* lên các dòng có
 *                     data-spec-key khi bấm tab bản → nên các nhãn override PHẢI có mặt trong car_specs.
 *   Xem docs/hbtn-theme-integration.md.
 */
class VCS_Repository {

    /** Nhãn spec (nguồn/hub) → sub-field ver_* trong car_versions của theme HBTN. */
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

    /** Đọc ver_* của 1 phiên bản → mảng specs [[label,value]] (đảo VER_MAP). */
    private static function read_ver_specs($v) {
        $out = [];
        foreach (self::VER_MAP as $label => $field) {
            if (isset($v[$field]) && $v[$field] !== '') $out[] = ['label' => $label, 'value' => (string) $v[$field]];
        }
        return $out;
    }

    /** Dữ liệu hiện tại: ['price'=>int, 'versions'=>[[name,price,status,specs]], 'specs'=>[[label,value]]]. */
    public static function current($post_id) {
        $versions = [];
        foreach ((array) self::cf($post_id, 'car_versions') as $v) {
            $versions[] = [
                'name'   => (string) ($v['name'] ?? ''),
                'price'  => (int) preg_replace('/\D/', '', (string) ($v['price'] ?? '')),
                'status' => (($v['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                'specs'  => self::read_ver_specs($v),
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
     * Dựng giá trị "mới" (đã áp mapping) từ dữ liệu nguồn, để so sánh & ghi.
     * - versions: name = shortname + ' ' + label; giữ status + specs riêng (map ver_* khi apply).
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
        if ($base) foreach ($base['specs'] as $s) $push($s['label'], $s['value']);   // động cơ, công suất, số chỗ… (bản base)
        foreach ((array) $normalized['specs'] as $s) $push($s['label'], $s['value']); // kích thước, gầm, lốp… (chung)
        foreach ($cur['specs'] as $s) if (!isset($seen[$s['label']])) $push($s['label'], $s['value']); // giữ spec cũ nguồn không có

        return [
            'price'    => (int) $normalized['price'],
            'versions' => $versions,
            'specs'    => $specs,
        ];
    }

    /** Ghi vào Carbon Fields (định dạng HBTN: car_versions phẳng ver_*). Trả về true/false. */
    public static function apply($post_id, $built) {
        if (!function_exists('carbon_set_post_meta')) return false;
        self::set($post_id, 'car_price', (string) $built['price']);
        self::set($post_id, 'car_versions', array_map(function ($v) {
            $row = ['name' => $v['name'], 'price' => (string) $v['price'], 'status' => $v['status'] ?? 'on_sale'];
            foreach (self::VER_MAP as $field) $row[$field] = ''; // khởi tạo đủ ô
            foreach ((array) ($v['specs'] ?? []) as $s) {
                if (isset(self::VER_MAP[$s['label']])) $row[self::VER_MAP[$s['label']]] = (string) $s['value'];
            }
            return $row;
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
