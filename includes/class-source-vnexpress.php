<?php
defined('ABSPATH') || exit;

/**
 * Nguồn VnExpress V-Car (https://vnexpress.net/oto-xe-may/v-car/dong-xe/...).
 * Parse HTML server-side:
 *   - versions: <select class="car-version-selected"> <option data-car-price=".." >LABEL - X triệu</option>
 *   - specs:    <div class="thong-so-kt"> <div class="item"><div class="name">..</div><div class="des">..</div></div>
 */
class VCS_Source_VnExpress implements VCS_Source_Interface {

    public function id() { return 'vnexpress'; }
    public function label() { return 'VnExpress V-Car'; }
    public function matches($url) { return (bool) preg_match('~vnexpress\.net/.*v-car~i', $url); }

    public function fetch($url) {
        $res = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            'headers'    => ['Accept-Language' => 'vi,en;q=0.8'],
        ]);
        if (is_wp_error($res)) return $this->err($res->get_error_message());
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return $this->err("HTTP $code khi tải trang nguồn.");
        $html = wp_remote_retrieve_body($res);
        if (!$html) return $this->err('Không đọc được nội dung trang nguồn.');

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($dom);

        $versions = $this->parse_versions($xp);
        if (!$versions) return $this->err('Không tìm thấy bảng giá phiên bản trên trang nguồn (cấu trúc VnExpress có thể đã đổi).');

        $model = $this->parse_model($xp);
        $seat  = $this->first_seat($xp);
        $specs = $this->parse_specs($xp, $seat);
        $price = min(array_column($versions, 'price'));

        return [
            'ok'       => true,
            'error'    => null,
            'source'   => $this->id(),
            'model'    => $model,
            'price'    => $price,
            'versions' => $versions,
            'specs'    => $specs,
        ];
    }

    private function err($msg) {
        return ['ok' => false, 'error' => $msg, 'source' => $this->id(), 'model' => '', 'price' => 0, 'versions' => [], 'specs' => []];
    }

    private function parse_model($xp) {
        $n = $xp->query('//h1')->item(0);
        if (!$n) return '';
        $t = $this->clean($n->textContent);
        // Cắt phần phụ "... Đời xe: ...", "| ...", "Bản nâng cấp"
        $t = preg_split('/\s*(?:Đời xe|\||\bBản\b)/u', $t)[0];
        return trim($t);
    }

    /** Options trong select phiên bản chính. */
    private function parse_versions($xp) {
        $out = [];
        $opts = $xp->query('//select[contains(@class,"car-version-selected")]//option[@data-car-price]');
        foreach ($opts as $o) {
            $price = (int) preg_replace('/\D/', '', $o->getAttribute('data-car-price'));
            $text  = $this->clean($o->textContent);
            // "G - 499 triệu" → label = phần trước " - "
            $label = trim(preg_split('/\s+-\s+/u', $text)[0]);
            if ($price > 0 && $label !== '') $out[] = ['label' => $label, 'price' => $price];
        }
        return $out;
    }

    private function first_seat($xp) {
        $o = $xp->query('//select[contains(@class,"car-version-selected")]//option[@data-car-seat]')->item(0);
        return $o ? (int) $o->getAttribute('data-car-seat') : 0;
    }

    /** .thong-so-kt .item(name/des) → map sang label chuẩn HBTN + format giá trị. */
    private function parse_specs($xp, $seat) {
        $raw = [];
        $block = $xp->query('//*[contains(@class,"thong-so-kt")]')->item(0);
        if ($block) {
            foreach ($xp->query('.//div[contains(@class,"item")]', $block) as $item) {
                $n = $xp->query('.//div[contains(@class,"name")]', $item)->item(0);
                $d = $xp->query('.//div[contains(@class,"des")]', $item)->item(0);
                if ($n && $d) {
                    $label = $this->clean($n->textContent);
                    $val   = $this->clean($d->textContent);
                    if ($label !== '') $raw[$label] = $val;
                }
            }
        }
        return $this->map_specs($raw, $seat);
    }

    /**
     * Chuẩn hoá spec VnExpress → label + format của HBTN.
     * Khớp theo từ khoá (label VnExpress dài & hay đổi).
     */
    private function map_specs($raw, $seat) {
        $engine = ''; $cc = '';
        $out = [];
        $push = function ($label, $value) use (&$out) {
            $value = trim($value);
            if ($value !== '' && $value !== '-') $out[] = ['label' => $label, 'value' => $value];
        };

        foreach ($raw as $k => $v) {
            $lk = $this->lower($k);
            if (strpos($lk, 'kiểu động cơ') !== false || $lk === 'động cơ') { $engine = $v; continue; }
            if (strpos($lk, 'dung tích') !== false && strpos($lk, 'cc') !== false) { $cc = preg_replace('/\D/', '', $v); continue; }
            if (strpos($lk, 'công suất') !== false)    { $push('Công suất', $this->fmt_pair($v, 'mã lực', 'v/p')); continue; }
            if (strpos($lk, 'mô-men') !== false || strpos($lk, 'mô men') !== false) { $push('Mô-men xoắn', $this->fmt_pair($v, 'Nm', 'v/p')); continue; }
            if (strpos($lk, 'hộp số') !== false)       { $push('Hộp số', $v); continue; }
            if (strpos($lk, 'dẫn động') !== false)     { $push('Dẫn động', $v); continue; }
            if (strpos($lk, 'loại nhiên liệu') !== false) { $push('Nhiên liệu', $v); continue; }
            if (strpos($lk, 'tiêu thụ') !== false)     { $push('Mức tiêu thụ (hỗn hợp)', rtrim($v, ' /') . ' lít/100km'); continue; }
            if (strpos($lk, 'kích thước') !== false)   { $push('Kích thước (D×R×C)', $v); continue; }
            if (strpos($lk, 'cơ sở') !== false)        { $push('Trục cơ sở', $this->with_unit($v, 'mm')); continue; }
            if (strpos($lk, 'sáng gầm') !== false)     { $push('Khoảng sáng gầm', $this->with_unit($v, 'mm')); continue; }
            if (strpos($lk, 'bình nhiên liệu') !== false || (strpos($lk, 'bình') !== false && strpos($lk, 'xăng') !== false)) { $push('Dung tích bình xăng', $this->with_unit($v, 'lít')); continue; }
            if (strpos($lk, 'số chỗ') !== false)       { $push('Số chỗ ngồi', preg_replace('/\D/', '', $v)); continue; }
        }

        // Động cơ = kiểu + dung tích
        if ($engine !== '') {
            $eng = $engine;
            if ($cc !== '') $eng .= ' (' . number_format((float) $cc, 0, ',', '.') . 'cc)';
            array_unshift($out, ['label' => 'Động cơ', 'value' => $eng]);
        }
        // Số chỗ từ option nếu spec không có
        $has_seat = false;
        foreach ($out as $r) if ($r['label'] === 'Số chỗ ngồi') $has_seat = true;
        if (!$has_seat && $seat > 0) $out[] = ['label' => 'Số chỗ ngồi', 'value' => (string) $seat];

        return $out;
    }

    /** "119/6.600" → "119 mã lực @ 6.600 v/p". */
    private function fmt_pair($v, $u1, $u2) {
        $parts = array_map('trim', explode('/', $v, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return $parts[0] . ' ' . $u1 . ' @ ' . $parts[1] . ' ' . $u2;
        }
        return $v;
    }

    private function with_unit($v, $u) {
        $v = trim($v);
        return ($v === '' || stripos($v, $u) !== false) ? $v : $v . ' ' . $u;
    }

    private function clean($s) { return trim(preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES, 'UTF-8'))); }
    private function lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
}
