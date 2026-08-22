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
 *     'price'   => 499000000,                    // giá thấp nhất (bản base)
 *     'versions'=> [ ['label'=>'G','price'=>499000000], ... ],  // label rút gọn, chưa gắn tên dòng
 *     'specs'   => [ ['label'=>'Công suất','value'=>'119 mã lực @ 6.600 v/p'], ... ], // đã map sang label chuẩn HBTN
 *   ]
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
