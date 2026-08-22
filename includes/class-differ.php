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

    /** Chữ ký thông số 1 bản (để phát hiện thông số RIÊNG đổi dù giá/tên giữ nguyên). */
    private static function spec_sig($specs) {
        $a = [];
        foreach ((array) $specs as $s) $a[] = ($s['label'] ?? '') . '=' . ($s['value'] ?? '');
        sort($a);
        return implode('|', $a);
    }

    private static function diff_versions($cur, $new) {
        $rows = [];
        $cur_by = [];
        foreach ($cur['versions'] as $v) $cur_by[$v['name']] = $v; // giữ CẢ bản (giá + status + specs)
        $new_names = [];
        foreach ($new['versions'] as $v) {
            $new_names[$v['name']] = true;
            $c   = $cur_by[$v['name']] ?? null;
            $cp  = $c ? (int) $c['price'] : null;
            $np  = (int) $v['price'];
            $disc = (($v['status'] ?? 'on_sale') === 'discontinued');
            // đổi = giá khác, HOẶC trạng thái khác, HOẶC thông số riêng khác
            $st_changed  = $c && (($c['status'] ?? 'on_sale') !== ($v['status'] ?? 'on_sale'));
            $sp_changed  = $c && (self::spec_sig($c['specs'] ?? []) !== self::spec_sig($v['specs'] ?? []));
            if ($c === null)                             $status = 'new';
            elseif ($cp !== $np || $st_changed || $sp_changed) $status = 'changed';
            else                                         $status = 'same';
            $note = '';
            if ($status === 'changed' && $cp === $np && !$st_changed && $sp_changed) $note = ' (thông số bản đổi)';
            $rows[] = [
                'field'   => $v['name'] . ($disc ? ' — ⛔ ngừng bán' : ''),
                'current' => ($c === null) ? '—' : self::money($cp),
                'new'     => self::money($np) . $note . ($disc ? ' (ẩn khỏi bảng giá)' : ''),
                'status'  => $status,
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
