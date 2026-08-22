# VIG Car Sync — Hợp đồng dữ liệu (Data Contract)

Tài liệu cho **theme developer** tích hợp website với plugin **VIG Car Sync**.
Plugin đồng bộ dữ liệu xe vào các **Carbon Fields** trên CPT `cars`. Theme phải
**định nghĩa đúng các field dưới đây** thì plugin mới ghi được.

---

## 0. Phân cấp dữ liệu (3 tầng)

```
Hãng (Brand)      →  Honda                         → file data/honda.json (brand)
  └ Dòng (Model)  →  City · CR-V · HR-V …          → models[]  (mỗi phần tử 1 dòng)
      └ Phiên bản →  CR-V G · CR-V L · CR-V e:HEV RS → models[].versions[]  (mỗi dòng nhiều bản)
```

- **Hãng** = 1 file JSON/hãng (`brand`, `brand_name`).
- **Dòng** = 1 phần tử `models[]` (`slug`, `name`, `price` = giá bản thấp nhất, `specs[]`).
- **Phiên bản** = 1 phần tử `versions[]` trong dòng (`name` = nhãn rút gọn "G"/"L"/"e:HEV RS", `price`).
  → **Tên phiên bản đầy đủ = Dòng + nhãn** ("CR-V" + "G" = "CR-V G"). Kho lưu nhãn rút gọn;
  plugin dealer tự ghép tên dòng khi ghi (`Repository::shortname` + label). Trên site, field
  `car_versions[].name` = tên đầy đủ (vd "CR-V G").

## 1. Bối cảnh

- **CPT bắt buộc:** `cars` (theme tự đăng ký `register_post_type('cars', …)`).
- **Field engine hiện tại:** [Carbon Fields](https://carbonfields.net/). Không có Carbon Fields → plugin **không ghi được** (`apply()` trả `false`).
- Plugin **chỉ ghi 3 field** dưới đây + tự quản 1 meta ẩn. Mọi field khác của xe (ảnh, mô tả, màu…) do theme tự lo — plugin không đụng.

## 2. Ba field plugin ĐỌC & GHI

| Field | Kiểu Carbon | Sub-field | Ý nghĩa |
|---|---|---|---|
| `car_price` | `text` (số) | — | Giá niêm yết thấp nhất (bản base), đơn vị **đồng**, chỉ chữ số (vd `499000000`) |
| `car_versions` | `complex` (repeater) | `name` (text), `price` (text/số) | Danh sách phiên bản: tên đầy đủ (vd `City RS`) + giá |
| `car_specs` | `complex` (repeater) | `spec_label` (text), `spec_value` (text) | Danh sách thông số: **nhãn** + **giá trị** |

> **QUAN TRỌNG — `car_specs` là REPEATER, KHÔNG phải mỗi thông số 1 field.**
> Theme chỉ cần 1 repeater `car_specs`; các thông số (Động cơ, Công suất…) là **các dòng dữ liệu**, không phải schema. Nhờ vậy thêm/bớt thông số không cần đổi theme.

### Meta ẩn plugin tự quản (theme ĐỪNG đụng)
- `_vcs_source_url` — tham chiếu nguồn của xe (`vighub:hãng/slug` hoặc URL Honda/VnExpress). Plugin đọc/ghi qua metabox "Nguồn dữ liệu".

## 3. Đăng ký field (copy-paste vào theme)

```php
use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Container::make( 'post_meta', 'Thông tin xe' )
        ->where( 'post_type', '=', 'cars' )
        ->add_fields( [
            Field::make( 'text', 'car_price', 'Giá niêm yết (từ)' )
                ->set_attribute( 'type', 'number' )
                ->set_help_text( 'Đơn vị: đồng. VIG Car Sync ghi tự động.' ),

            Field::make( 'complex', 'car_versions', 'Phiên bản' )
                ->set_layout( 'tabbed-horizontal' )
                ->add_fields( [
                    Field::make( 'text', 'name',  'Tên phiên bản' ),
                    Field::make( 'text', 'price', 'Giá' ),
                ] ),

            Field::make( 'complex', 'car_specs', 'Thông số kỹ thuật' )
                ->add_fields( [
                    Field::make( 'text', 'spec_label', 'Tên thông số' ),
                    Field::make( 'text', 'spec_value', 'Giá trị' ),
                ] ),
        ] );
} );
```

> Tên field (`car_price`, `car_versions`, `car_specs`) và sub-field (`name`, `price`,
> `spec_label`, `spec_value`) phải **giữ nguyên** — plugin tra cứu theo tên này.

## 4. Đọc dữ liệu ra front-end (theme)

```php
$price    = carbon_get_post_meta( get_the_ID(), 'car_price' );      // "499000000"
$versions = carbon_get_post_meta( get_the_ID(), 'car_versions' );   // [ ['name'=>'City RS','price'=>'569000000'], ... ]
$specs    = carbon_get_post_meta( get_the_ID(), 'car_specs' );      // [ ['spec_label'=>'Động cơ','spec_value'=>'1.5L…'], ... ]

echo number_format( (int) $price, 0, ',', '.' ) . ' đ';
foreach ( $versions as $v ) printf( '%s — %s đ<br>', esc_html($v['name']), number_format((int)$v['price'],0,',','.') );
foreach ( $specs as $s )    printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html($s['spec_label']), esc_html($s['spec_value']) );
```

## 5. Bộ nhãn thông số chuẩn (`spec_label`)

Plugin **map mọi nguồn về bộ nhãn chuẩn** này (thứ tự gợi ý để hiển thị). Giá trị (`spec_value`) đã format sẵn (kèm đơn vị). Không phải xe nào cũng đủ 14 nhãn.

| # | `spec_label` | Ví dụ `spec_value` |
|---|---|---|
| 1 | Động cơ | `1.5L DOHC i-VTEC 4 xi lanh (1.498cc)` |
| 2 | Công suất | `119 mã lực @ 6.600 v/p` · `204 mã lực (tổng hệ hybrid)` |
| 3 | Mô-men xoắn | `145 Nm @ 4.300 v/p` |
| 4 | Hộp số | `Vô cấp CVT` · `E-CVT` |
| 5 | Dẫn động | `Cầu trước` |
| 6 | Nhiên liệu | `Xăng` · `Xăng / Hybrid` |
| 7 | Số chỗ ngồi | `5` |
| 8 | Dung tích bình xăng | `40 lít` |
| 9 | Mức tiêu thụ (hỗn hợp) | `5.2 lít/100km` |
| 10 | Kích thước (D×R×C) | `4.347 × 1.790 × 1.590 mm` |
| 11 | Trục cơ sở | `2.600 mm` |
| 12 | Khoảng sáng gầm | `147 mm` |
| 13 | Cỡ lốp | `215/55 R17` |
| 14 | Trọng lượng (bản thân) | `1.156 kg` |

> Theme muốn gắn **icon** cho từng thông số: khớp theo `spec_label` (chuỗi cố định ở trên).

## 6. Định dạng dữ liệu nguồn (kho tập trung)

Khi kéo từ **VIG Car Hub** (`vighub:hãng/slug`), plugin đọc JSON theo schema:
[`vig-car-data/schema.json`](https://github.com/vigdigital/vig-car-data/blob/master/schema.json). Một model:

```jsonc
{
  "slug": "honda-city", "name": "Honda City", "year": null,
  "source": "honda", "source_url": "https://www.honda.com.vn/o-to/chi-tiet/2",
  "price": 499000000,
  "versions": [ { "name": "RS", "price": 569000000 }, ... ],   // name = nhãn rút gọn; plugin ghép "City RS" khi ghi
  "specs":    [ { "label": "Động cơ", "value": "1.5L…" }, ... ]
}
```

## 7. Dùng field engine khác (ACF / meta thường)?

Plugin ghi qua `VCS_Repository` (Carbon Fields). Nếu theme dùng **ACF** hoặc **meta
thường**, cần thay/mở rộng Repository (đọc/ghi field của bạn) — phần Source/Differ giữ
nguyên. Liên hệ VIG để có bản Repository tương ứng.
