<?php
defined('ABSPATH') || exit;

/**
 * Target = site HBTN (Carbon Fields trên CPT cars).
 * Đọc dữ liệu hiện tại + ghi dữ liệu mới. (Sau này có thể thay bằng target khác.)
 */
class VCS_Repository {

    /** Tên dòng rút gọn để dựng tên phiên bản: "Honda City" → "City". */
    public static function shortname($post_id) {
        $t = get_the_title($post_id);
        return trim(preg_replace('/^\s*honda\s+/i', '', $t));
    }

    /** Dữ liệu hiện tại: ['price'=>int, 'versions'=>[[name,price]], 'specs'=>[[label,value]]]. */
    public static function current($post_id) {
        $versions = [];
        foreach ((array) self::cf($post_id, 'car_versions') as $v) {
            $vspecs = [];
            foreach ((array) ($v['specs'] ?? []) as $s) {
                $vspecs[] = ['label' => (string) ($s['spec_label'] ?? ''), 'value' => (string) ($s['spec_value'] ?? '')];
            }
            $versions[] = [
                'name'   => (string) ($v['name'] ?? ''),
                'price'  => (int) preg_replace('/\D/', '', (string) ($v['price'] ?? '')),
                'status' => (($v['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                'specs'  => $vspecs,
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
     * - versions: name = shortname + ' ' + label.
     * - specs: MERGE — giữ spec cũ VnExpress không có (kích thước…), update/thêm spec nguồn có.
     */
    public static function build_new($post_id, $normalized) {
        $short = self::shortname($post_id);
        $cur = self::current($post_id);

        $versions = [];
        foreach ($normalized['versions'] as $v) {
            $name = trim($short . ' ' . $v['label']);
            $versions[] = [
                'name'   => $name,
                'price'  => (int) $v['price'],
                'status' => (($v['status'] ?? 'on_sale') === 'discontinued') ? 'discontinued' : 'on_sale',
                'specs'  => isset($v['specs']) && is_array($v['specs']) ? $v['specs'] : [],
            ];
        }

        // Merge specs theo label, giữ thứ tự cũ trước, thêm mới ở cuối.
        $new_by_label = [];
        foreach ($normalized['specs'] as $s) $new_by_label[$s['label']] = $s['value'];
        $specs = [];
        $used = [];
        foreach ($cur['specs'] as $s) {
            if (isset($new_by_label[$s['label']])) {
                $specs[] = ['label' => $s['label'], 'value' => $new_by_label[$s['label']]];
                $used[$s['label']] = true;
            } else {
                $specs[] = $s; // giữ nguyên (nguồn không có)
            }
        }
        foreach ($normalized['specs'] as $s) {
            if (empty($used[$s['label']])) $specs[] = $s; // spec mới hoàn toàn
        }

        return [
            'price'    => (int) $normalized['price'],
            'versions' => $versions,
            'specs'    => $specs,
        ];
    }

    /** Ghi vào Carbon Fields. Trả về true/false. */
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
