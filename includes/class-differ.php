<?php
defined('ABSPATH') || exit;

/**
 * So sánh dữ liệu hiện tại vs dữ liệu mới (đã build/map).
 * Trả về các nhóm hàng, mỗi hàng: ['field','current','new','status'].
 *   status: 'same' | 'changed' | 'new'   (+ 'removed' cho phiên bản biến mất)
 */
class VCS_Differ {

    public static function diff($current, $built) {
        return [
            'price'    => self::diff_price($current, $built),
            'versions' => self::diff_versions($current, $built),
            'specs'    => self::diff_specs($current, $built),
        ];
    }

    private static function money($n) { return number_format((int) $n, 0, ',', '.') . ' đ'; }

    private static function diff_price($cur, $new) {
        $c = (int) $cur['price']; $n = (int) $new['price'];
        return [[
            'field'   => 'Giá niêm yết (từ)',
            'current' => $c ? self::money($c) : '—',
            'new'     => $n ? self::money($n) : '—',
            'status'  => ($c === $n) ? 'same' : 'changed',
        ]];
    }

    private static function diff_versions($cur, $new) {
        $rows = [];
        $cur_by = [];
        foreach ($cur['versions'] as $v) $cur_by[$v['name']] = (int) $v['price'];
        $new_names = [];
        foreach ($new['versions'] as $v) {
            $new_names[$v['name']] = true;
            $c = isset($cur_by[$v['name']]) ? $cur_by[$v['name']] : null;
            $n = (int) $v['price'];
            $rows[] = [
                'field'   => $v['name'],
                'current' => ($c === null) ? '—' : self::money($c),
                'new'     => self::money($n),
                'status'  => ($c === null) ? 'new' : (($c === $n) ? 'same' : 'changed'),
            ];
        }
        // Phiên bản cũ không còn trong nguồn
        foreach ($cur['versions'] as $v) {
            if (empty($new_names[$v['name']])) {
                $rows[] = ['field' => $v['name'], 'current' => self::money($v['price']), 'new' => '(không còn ở nguồn)', 'status' => 'removed'];
            }
        }
        return $rows;
    }

    private static function diff_specs($cur, $new) {
        $rows = [];
        $cur_by = [];
        foreach ($cur['specs'] as $s) $cur_by[$s['label']] = $s['value'];
        // $new['specs'] đã merge nên chứa cả spec giữ nguyên; chỉ hiển thị hàng có ý nghĩa.
        foreach ($new['specs'] as $s) {
            $c = isset($cur_by[$s['label']]) ? $cur_by[$s['label']] : null;
            $n = $s['value'];
            $status = ($c === null) ? 'new' : (($c === $n) ? 'same' : 'changed');
            $rows[] = [
                'field'   => $s['label'],
                'current' => ($c === null) ? '—' : $c,
                'new'     => $n,
                'status'  => $status,
            ];
        }
        return $rows;
    }

    /** Đếm số thay đổi (changed + new) để hiển thị badge. */
    public static function count_changes($diff) {
        $n = 0;
        foreach (['price', 'versions', 'specs'] as $g) {
            foreach ($diff[$g] as $r) if ($r['status'] === 'changed' || $r['status'] === 'new') $n++;
        }
        return $n;
    }
}
