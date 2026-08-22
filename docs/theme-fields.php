<?php
/**
 * VIG Car Sync — Carbon Fields cho CPT `cars` — MẪU GENERIC (specs lồng, hướng A).
 *
 * Đây là cách ship chính thức: thông số riêng của bản = repeater `specs` (spec_label/spec_value).
 * Theme lấy dữ liệu qua vig_car_data($id) rồi lặp generic — xem docs/hbtn-theme-integration.md
 * để biết cách sửa single-cars.php khớp mẫu này.
 *
 * Cách dùng: copy toàn bộ khối add_action bên dưới vào file khai báo Carbon Fields
 * của theme (thường là functions.php của child theme, chỗ đang có
 * carbon_fields_register_fields). NẾU theme đã có sẵn car_price/car_versions/car_specs,
 * chỉ cần BỔ SUNG 2 sub-field mới vào car_versions: `status` (select) + `specs` (complex lồng).
 *
 * Plugin VIG Car Sync tra cứu theo đúng các tên field/sub-field này — giữ nguyên tên.
 */
defined('ABSPATH') || exit;

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action('carbon_fields_register_fields', function () {
    Container::make('post_meta', 'Thông tin xe')
        ->where('post_type', '=', 'cars')
        ->add_fields([

            Field::make('text', 'car_price', 'Giá niêm yết (từ)')
                ->set_attribute('type', 'number')
                ->set_help_text('Đơn vị: đồng. VIG Car Sync ghi tự động.'),

            Field::make('complex', 'car_versions', 'Phiên bản')
                ->set_layout('tabbed-horizontal')
                ->add_fields([
                    Field::make('text', 'name', 'Tên phiên bản'),
                    Field::make('text', 'price', 'Giá'),

                    // MỚI: trạng thái bán
                    Field::make('select', 'status', 'Trạng thái')
                        ->set_options(['on_sale' => 'Đang bán', 'discontinued' => 'Ngừng bán'])
                        ->set_default_value('on_sale'),

                    // MỚI: thông số RIÊNG của bản (khác nhau giữa các phiên bản)
                    Field::make('complex', 'specs', 'Thông số riêng của bản')
                        ->add_fields([
                            Field::make('text', 'spec_label', 'Tên thông số'),
                            Field::make('text', 'spec_value', 'Giá trị'),
                        ]),
                ]),

            // Thông số CHUNG cho cả dòng
            Field::make('complex', 'car_specs', 'Thông số chung (cả dòng)')
                ->add_fields([
                    Field::make('text', 'spec_label', 'Tên thông số'),
                    Field::make('text', 'spec_value', 'Giá trị'),
                ]),
        ]);
});

/**
 * Đọc ra front-end (tham khảo):
 *
 *   $versions = carbon_get_post_meta(get_the_ID(), 'car_versions');
 *   $common   = carbon_get_post_meta(get_the_ID(), 'car_specs');
 *   foreach ($versions as $v) {
 *       if (($v['status'] ?? 'on_sale') === 'discontinued') continue; // ẩn bản ngừng bán
 *       // thông số bản = $v['specs'] (riêng) + $common (chung)
 *   }
 */
