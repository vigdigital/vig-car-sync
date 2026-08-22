# HBTN theme — điều chỉnh để hỗ trợ thông số riêng từng phiên bản + trạng thái ngừng bán

**Gửi:** team code theme `hbtn-theme`
**Lý do:** đối chiếu brochure chính hãng **Honda CR-V 2026**, dữ liệu đúng cho thấy các phiên bản của 1 dòng có thể **khác động cơ / số chỗ / dẫn động** (CR-V: bản **G/L = 1.5 Turbo 7 chỗ**, bản **e:HEV = 2.0 Hybrid 5 chỗ**), và có bản **đã ngừng bán** (L AWD) cần ẩn khỏi web. Theme hiện đã hỗ trợ ghi đè 5 thông số/bản (`ver_engine, ver_power, ver_torque, ver_transmission, ver_fuel`) — cần **bổ sung thêm** cho đủ.

> **Tin tốt:** `js/car.js` **KHÔNG cần sửa**. Cơ chế override đã generic (loop mọi `[data-spec-key]`, lấy giá trị theo key trong `data-specs` của bản, không có thì dùng mặc định). Thêm key mới là tự chạy. Chỉ sửa **2 file PHP**.

---

## Việc 1 — `inc/carbon-fields-fields.php`

### 1a. Thêm field trạng thái + 4 thông số riêng vào `car_versions`

Trong `Field::make('complex', 'car_versions', …)->add_fields([ … ])`, **thêm** (giữ nguyên các field cũ `name, price, ver_engine…ver_fuel`):

```php
// ... sau Field 'price', thêm trạng thái bán:
Field::make('select', 'status', __('Trạng thái', 'hbtn-theme'))
    ->set_options(['on_sale' => 'Đang bán', 'discontinued' => 'Ngừng bán'])
    ->set_default_value('on_sale'),

// ... sau 'ver_fuel', thêm 4 thông số riêng còn thiếu:
Field::make('text', 'ver_seats',       __('Số chỗ ngồi', 'hbtn-theme')),
Field::make('text', 'ver_drivetrain',  __('Dẫn động', 'hbtn-theme')),
Field::make('text', 'ver_consumption', __('Mức tiêu thụ (hỗn hợp)', 'hbtn-theme')),
Field::make('text', 'ver_weight',      __('Trọng lượng (bản thân)', 'hbtn-theme')),
```

> Không đổi tên các field cũ. Plugin **VIG Car Sync** tra cứu theo đúng các tên này.

---

## Việc 2 — `single-cars.php`

### 2a. Mở rộng `$spec_key_map` (thêm 4 dòng)

```php
$spec_key_map = [
    'Động cơ'                  => 'engine',
    'Công suất'                => 'power',
    'Mô-men xoắn'              => 'torque',
    'Hộp số'                   => 'transmission',
    'Nhiên liệu'               => 'fuel',
    // MỚI:
    'Số chỗ ngồi'              => 'seats',
    'Dẫn động'                 => 'drivetrain',
    'Mức tiêu thụ (hỗn hợp)'   => 'consumption',
    'Trọng lượng (bản thân)'   => 'weight',
];
```

### 2b. Mở rộng `$ver_specs_json` (thêm 4 key)

```php
$ver_specs_json = function ($v) {
    return wp_json_encode([
        'engine'       => $v['ver_engine'] ?? '',
        'power'        => $v['ver_power'] ?? '',
        'torque'       => $v['ver_torque'] ?? '',
        'transmission' => $v['ver_transmission'] ?? '',
        'fuel'         => $v['ver_fuel'] ?? '',
        // MỚI:
        'seats'        => $v['ver_seats'] ?? '',
        'drivetrain'   => $v['ver_drivetrain'] ?? '',
        'consumption'  => $v['ver_consumption'] ?? '',
        'weight'       => $v['ver_weight'] ?? '',
    ]);
};
```

### 2c. Ẩn phiên bản `discontinued` khỏi web

Có **2 vòng lặp** render tab phiên bản (spec-sidebar `.vtab` ~dòng 62, và lăn bánh `.vtab-or` ~dòng 117). Ngay đầu mỗi vòng, bỏ qua bản ngừng bán:

```php
foreach ($versions as $i => $v) :
    if (($v['status'] ?? 'on_sale') === 'discontinued') continue;   // ẩn bản ngừng bán
    $vp = (int) preg_replace('/\D/', '', (string) $v['price']);
    // ... giữ nguyên phần còn lại
```

> Tuỳ chọn: nếu muốn **vẫn hiện** bản ngừng bán nhưng có nhãn, thay `continue` bằng việc thêm class/badge `Ngừng bán` — nhưng khuyến nghị ẩn để không gây nhầm bảng giá.
>
> Lưu ý `$first = $versions[0]` (dòng ~18): nếu bản đầu tiên là discontinued thì nên chọn bản `on_sale` đầu tiên làm mặc định. Gợi ý:
> ```php
> $active = array_values(array_filter($versions, fn($v) => ($v['status'] ?? 'on_sale') !== 'discontinued'));
> $first  = $active ? $active[0] : ($versions[0] ?? ['name' => get_the_title(), 'price' => $price]);
> ```

---

## Điều kiện để override hiển thị (quan trọng)

JS chỉ ghi đè được dòng thông số **đã tồn tại** trong bảng "Thông số kỹ thuật" chung (`car_specs`) — vì nó tìm phần tử `[data-spec-key]`. Nên bảng chung của mỗi xe **phải có sẵn dòng** cho các nhãn: `Số chỗ ngồi`, `Dẫn động`, `Mức tiêu thụ (hỗn hợp)`, `Trọng lượng (bản thân)` (giá trị mặc định = bản base). **Plugin VIG Car Sync tự ghi** các dòng này khi đồng bộ, nên team theme không phải nhập tay.

Plugin ghi `car_specs` = **9 thông số của bản base** (động cơ, công suất, mô-men, hộp số, nhiên liệu, số chỗ, dẫn động, tiêu thụ, trọng lượng — các dòng sẽ được override) **+ ~13 thông số chung của dòng** (kích thước, gầm, lốp, treo, phanh…). Với CR-V là **~22 dòng** → cần **nâng `set_max`** (hiện `12`):

```php
// trong car_specs:
->set_max(24)   // cũ: 12 — nâng để đủ 9 spec bản base + ~13 spec chung (hoặc bỏ hẳn set_max)
```

> Bảng chung sẽ dài hơn trước (~22 dòng thay vì ~12). Nếu muốn gọn, team theme có thể style/gộp hiển thị — nhưng **giữ đủ 9 dòng có `data-spec-key`** để cơ chế override theo bản còn chạy.

---

## Hợp đồng dữ liệu plugin → theme (để 2 bên khớp)

Sau khi theme xong, plugin **VIG Car Sync 0.4.x** (Repository bản HBTN) sẽ ghi:

- `car_price` (text số) — giá bản đang bán thấp nhất
- `car_specs[]` — thông số **CHUNG** cả dòng: `{ spec_label, spec_value }` (gồm cả các dòng có `data-spec-key` để override)
- `car_versions[]` — mỗi phiên bản:
  ```
  name, price, status(on_sale|discontinued),
  ver_engine, ver_power, ver_torque, ver_transmission, ver_fuel,
  ver_seats, ver_drivetrain, ver_consumption, ver_weight
  ```

Nguồn dữ liệu chuẩn = **VIG Car Hub** (`vighub:honda/honda-cr-v`, JSON tại `raw.githubusercontent.com/vigdigital/vig-car-data`). Plugin map `versions[].specs[]` (label→value) sang đúng các ô `ver_*` theo bảng:

| Nhãn trong hub (`label`) | Ô theme (`car_versions`) |
|---|---|
| Động cơ | `ver_engine` |
| Công suất | `ver_power` |
| Mô-men xoắn | `ver_torque` |
| Hộp số | `ver_transmission` |
| Nhiên liệu | `ver_fuel` |
| Số chỗ ngồi | `ver_seats` |
| Dẫn động | `ver_drivetrain` |
| Mức tiêu thụ (hỗn hợp) | `ver_consumption` |
| Trọng lượng (bản thân) | `ver_weight` |

---

## Checklist test (trang 1 xe CR-V)

- [ ] Sửa xe CR-V → tab **Thông số**: `car_versions` có ô Trạng thái + Số chỗ/Dẫn động/Tiêu thụ/Trọng lượng
- [ ] Bấm lần lượt các tab bản ngoài web (`.vtab`): **Động cơ, Số chỗ, Hộp số, Tiêu thụ… đổi theo bản** (G = 1.5T/7 chỗ; e:HEV = 2.0 Hybrid/5 chỗ)
- [ ] Bản **L AWD (ngừng bán) không xuất hiện** trong danh sách bản + máy tính lăn bánh
- [ ] Bản mặc định khi mở trang là bản đang bán (không phải bản ngừng bán)
- [ ] `car_specs` hiển thị đủ thông số chung (không bị cắt do `set_max`)

Tham chiếu dữ liệu đúng: brochure `CRV 2026.pdf` (file hãng) và `docs/DATA-CONTRACT.md` trong plugin.
