<?php
defined('ABSPATH') || exit;

/**
 * Nguồn CHÍNH HÃNG Honda VN (https://www.honda.com.vn/o-to/chi-tiet/{id}).
 * Giàu hơn VnExpress: đủ kích thước, trục cơ sở, khoảng sáng gầm, lốp, trọng lượng…
 * Cấu trúc HTML tĩnh:
 *   - versions: .version-name-desk ("… Phiên bản RS") + .proposal-price[data-version=N] (giá)
 *   - specs:    <td>LABEL</td><td class="col-data" data-version-col='all'>VALUE</td>
 */
class VCS_Source_Honda implements VCS_Source_Interface {

    public function id() { return 'honda'; }
    public function label() { return 'Honda VN (chính hãng)'; }
    public function matches($url) { return (bool) preg_match('~honda\.com\.vn/o-to/chi-tiet~i', $url); }

    public function fetch($url) {
        $res = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'headers'    => ['Accept-Language' => 'vi,en;q=0.8'],
        ]);
        if (is_wp_error($res)) return $this->err($res->get_error_message());
        if (wp_remote_retrieve_response_code($res) !== 200) return $this->err('HTTP ' . wp_remote_retrieve_response_code($res) . ' khi tải trang Honda.');
        $html = wp_remote_retrieve_body($res);
        if (!$html) return $this->err('Không đọc được nội dung trang Honda.');

        $versions = $this->parse_versions($html);
        if (!$versions) return $this->err('Không tìm thấy bảng giá phiên bản trên trang Honda.');
        $raw = $this->parse_spec_rows($html);
        $specs = $this->map_specs($raw);
        $price = min(array_column($versions, 'price'));

        return [
            'ok' => true, 'error' => null, 'source' => $this->id(),
            'model' => $this->parse_model($html), 'price' => $price,
            'versions' => $versions, 'specs' => $specs,
        ];
    }

    private function err($m) { return ['ok' => false, 'error' => $m, 'source' => $this->id(), 'model' => '', 'price' => 0, 'versions' => [], 'specs' => []]; }

    private function parse_model($html) {
        // tên dòng nằm ở meta og:title hoặc tiêu đề model; fallback rỗng (Repository dùng tên post).
        if (preg_match('~property="og:title"\s+content="([^"]+)"~i', $html, $m)) {
            $t = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            if ($t && stripos($t, 'chi tiết') === false) return $t;
        }
        return '';
    }

    /**
     * Ghép tên phiên bản với giá THEO data-version (index, không theo thứ tự DOM —
     * trang Honda có bản trùng/ẩn làm lệch thứ tự, vd CR-V có v21 xen giữa).
     * - Giá: .proposal-price[data-version=N] → priceByVer[N], giữ thứ tự N xuất hiện.
     * - Tên: mỗi "Phiên bản X" gắn với data-version=N gần nhất phía trước → labelByVer[N].
     */
    private function parse_versions($html) {
        // Giá theo N (first-wins) + thứ tự xuất hiện của N
        preg_match_all('~proposal-price[^>]*data-version\s*=\s*(\d+)[^>]*>\s*([\d.]+)~i', $html, $pm, PREG_SET_ORDER);
        $priceByVer = []; $order = [];
        foreach ($pm as $p) {
            $v = (int) $p[1];
            if (!isset($priceByVer[$v])) { $priceByVer[$v] = (int) preg_replace('/\D/', '', $p[2]); $order[] = $v; }
        }

        // Nhãn theo N: data-version gần nhất phía trước mỗi "Phiên bản X"
        $labelByVer = [];
        if (preg_match_all('~Phiên bản\s+([^<\n]{1,18})~u', $html, $lm, PREG_OFFSET_CAPTURE)) {
            foreach ($lm[1] as $match) {
                $label = $this->clean($match[0]);
                $off = $match[1];
                if ($label === '') continue;
                $window = substr($html, max(0, $off - 1500), min($off, 1500));
                if (preg_match_all('~data-version\s*=\s*["\']?(\d+)~i', $window, $dv)) {
                    $v = (int) end($dv[1]);
                    if (!isset($labelByVer[$v])) $labelByVer[$v] = $label;
                }
            }
        }

        // Join theo N, giữ thứ tự N của bảng giá
        $out = [];
        foreach ($order as $v) {
            if ($priceByVer[$v] > 0 && !empty($labelByVer[$v])) {
                $out[] = ['label' => $labelByVer[$v], 'price' => $priceByVer[$v]];
            }
        }
        return $out;
    }

    /** Tất cả hàng <td>label</td><td class="col-data">value</td> → [label=>value] (lấy cột đầu). */
    private function parse_spec_rows($html) {
        preg_match_all('~<td>\s*([^<]+?)\s*</td>\s*<td class="col-data"[^>]*>(.*?)</td>~is', $html, $m, PREG_SET_ORDER);
        $raw = [];
        foreach ($m as $r) {
            $label = $this->clean($r[1]);
            $val = $this->clean(strip_tags($r[2]));
            if ($label !== '' && !isset($raw[$label])) $raw[$label] = $val;
        }
        return $raw;
    }

    /** Map spec Honda → label chuẩn HBTN + format. */
    private function map_specs($raw) {
        $engine = ''; $cc = '';
        $out = [];
        $push = function ($label, $value) use (&$out) {
            $value = trim($value);
            if ($value !== '' && $value !== '-') $out[] = ['label' => $label, 'value' => $value];
        };
        foreach ($raw as $k => $v) {
            $lk = $this->lower($k);
            if (strpos($lk, 'kiểu động cơ') !== false)     { $engine = $v; continue; }
            if (strpos($lk, 'dung tích xi lanh') !== false) { $cc = preg_replace('/\D/', '', $v); continue; }
            if (strpos($lk, 'công suất') !== false)        { $push('Công suất', $this->fmt_power($v)); continue; }
            if (strpos($lk, 'mô-men') !== false)           { $push('Mô-men xoắn', $this->fmt_torque($v)); continue; }
            if (strpos($lk, 'hộp số') !== false)           { $push('Hộp số', $v); continue; }
            if (strpos($lk, 'dẫn động') !== false)         { $push('Dẫn động', $v); continue; }
            if (strpos($lk, 'thùng nhiên liệu') !== false) { $push('Dung tích bình xăng', $this->with_unit($v, 'lít')); continue; }
            if (strpos($lk, 'tổ hợp') !== false)           { $push('Mức tiêu thụ (hỗn hợp)', rtrim($v) . ' lít/100km'); continue; }
            if (strpos($lk, 'số chỗ') !== false)           { $push('Số chỗ ngồi', preg_replace('/\D/', '', $v)); continue; }
            if (strpos($lk, 'dài x rộng x cao') !== false || strpos($lk, 'kích thước') !== false) { $push('Kích thước (D×R×C)', $this->with_unit($this->norm_dims($v), 'mm')); continue; }
            if (strpos($lk, 'chiều dài cơ sở') !== false)  { $push('Trục cơ sở', $this->with_unit($v, 'mm')); continue; }
            if (strpos($lk, 'sáng gầm') !== false)         { $push('Khoảng sáng gầm', $this->with_unit($v, 'mm')); continue; }
            if (strpos($lk, 'cỡ lốp') !== false)           { $push('Cỡ lốp', $v); continue; }
            if (strpos($lk, 'khối lượng bản thân') !== false) { $push('Trọng lượng (bản thân)', $this->with_unit($v, 'kg')); continue; }
        }
        if ($engine !== '') {
            $eng = $engine;
            if ($cc !== '') $eng .= ' (' . number_format((float) $cc, 0, ',', '.') . 'cc)';
            array_unshift($out, ['label' => 'Động cơ', 'value' => $eng]);
        }
        // Nhiên liệu suy ra từ động cơ
        $lc = $this->lower($engine);
        $fuel = (strpos($lc, 'hybrid') !== false || strpos($lc, 'e:hev') !== false || strpos($lc, 'điện') !== false) ? 'Xăng / Hybrid' : 'Xăng';
        $out[] = ['label' => 'Nhiên liệu', 'value' => $fuel];
        return $out;
    }

    /** Chuẩn hoá "4347 x 1790 x 1590" → "4.347 × 1.790 × 1.590". */
    private function norm_dims($v) {
        $parts = preg_split('~\s*[x×X]\s*~u', $v);
        $out = [];
        foreach ($parts as $p) {
            $n = preg_replace('/\D/', '', $p);
            if ($n === '') continue;
            $out[] = ((int) $n >= 1000) ? number_format((int) $n, 0, ',', '.') : $n;
        }
        return implode(' × ', $out);
    }
    private function strip_paren($v) { return trim(preg_replace('~\s*\([^)]*\)~', '', $v)); }

    /** Công suất: bản thường "119/6.600"→"119 mã lực @ 6.600 v/p"; hybrid có "Kết hợp: 204"→"204 mã lực (tổng hệ hybrid)". */
    private function fmt_power($v) {
        $v = $this->strip_paren($v);
        if (preg_match('~kết hợp[:\s]*([\d.]+)~ui', $v, $m)) return $m[1] . ' mã lực (tổng hệ hybrid)';
        if (preg_match('~^\s*([\d.]+)\s*/\s*([\d.\-]+)\s*$~u', $v, $m)) return $m[1] . ' mã lực @ ' . $m[2] . ' v/p';
        return $v; // hybrid/đa phần: giữ nguyên (không cắt sai)
    }

    /** Mô-men: bản thường "145/4.300"→"145 Nm @ 4.300 v/p"; hybrid → "335 Nm (mô-tơ điện)". */
    private function fmt_torque($v) {
        $v = $this->strip_paren($v);
        if (preg_match('~^\s*([\d.]+)\s*/\s*([\d.\-]+)\s*$~u', $v, $m)) return $m[1] . ' Nm @ ' . $m[2] . ' v/p';
        if (preg_match('~mô-?tơ[:\s]*([\d.]+)~ui', $v, $m)) return $m[1] . ' Nm (mô-tơ điện)';
        return $v;
    }
    private function with_unit($v, $u) { $v = trim($v); return ($v === '' || stripos($v, $u) !== false) ? $v : $v . ' ' . $u; }
    private function clean($s) { return trim(preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES, 'UTF-8'))); }
    private function lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
}
