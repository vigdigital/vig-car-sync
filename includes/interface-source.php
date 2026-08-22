<?php
defined('ABSPATH') || exit;

/**
 * Hợp đồng cho mọi nguồn dữ liệu xe (VnExpress, và các nguồn/hãng khác sau này).
 * fetch() trả về mảng chuẩn hoá:
 *   [
 *     'ok'      => bool,
 *     'error'   => string|null,
 *     'source'  => 'vnexpress',
 *     'model'   => 'Honda City 2023',          // tên hiển thị từ nguồn
 *     'price'   => 499000000,                    // giá thấp nhất (bản đang bán)
 *     'versions'=> [
 *         [
 *           'label'  => 'G',                      // rút gọn, chưa gắn tên dòng
 *           'price'  => 499000000,
 *           'status' => 'on_sale',                // 'on_sale' | 'discontinued' (tuỳ chọn, mặc định on_sale)
 *           'specs'  => [ ['label'=>'Động cơ','value'=>'1.5L Turbo…'], ... ],  // thông số RIÊNG của bản (tuỳ chọn)
 *         ], ...
 *     ],
 *     'specs'   => [ ['label'=>'Công suất','value'=>'119 mã lực @ 6.600 v/p'], ... ], // thông số CHUNG cho cả dòng
 *   ]
 * Ghi chú: 'status' + 'specs' trong version là tuỳ chọn — nguồn cũ không có vẫn chạy (mặc định on_sale, specs rỗng).
 */
interface VCS_Source_Interface {
    /** Slug nguồn, vd 'vnexpress'. */
    public function id();
    /** Tên hiển thị. */
    public function label();
    /** URL này có thuộc nguồn không. */
    public function matches($url);
    /** Tải + parse → mảng chuẩn hoá (xem docblock trên). */
    public function fetch($url);
}
