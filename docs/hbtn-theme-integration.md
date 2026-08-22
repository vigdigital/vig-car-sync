# HBTN theme — tích hợp hướng A (generic, KHÔNG hardcode nhãn thông số)

**Gửi:** team code theme `hbtn-theme`
**Mục tiêu:** thêm/bớt **dòng xe · phiên bản · loại thông số · ngừng bán** = **chỉ đổi data**, không bao giờ sửa cấu trúc theme nữa.

**Nguyên tắc:** theme không đọc field cố định (`ver_engine`…) nữa. Thay bằng:
- Thông số riêng của bản = **1 repeater `specs`** (nhãn/giá-trị) trong `car_versions`.
- Theme lấy dữ liệu qua hàm plugin **`vig_car_data()`** rồi **lặp generic**.
- `js/car.js` **KHÔNG đổi** (đã override theo `data-spec-key`).

> Cần plugin **VIG Car Sync ≥ 0.7.0** (cung cấp `vig_car_data()`).

---

## Việc 1 — `inc/carbon-fields-fields.php`

Thay **toàn bộ** phần `->add_fields([...])` của `car_versions` bằng:

```php
Field::make('complex', 'car_versions', __('Phiên bản & giá', 'hbtn-theme'))
    ->set_layout('tabbed-horizontal')
    ->add_fields([
        Field::make('text',   'name',   __('Tên phiên bản', 'hbtn-theme')),
        Field::make('text',   'price',  __('Giá niêm yết (VNĐ)', 'hbtn-theme')),
        Field::make('select', 'status', __('Trạng thái', 'hbtn-theme'))
            ->set_options(['on_sale' => 'Đang bán', 'discontinued' => 'Ngừng bán'])
            ->set_default_value('on_sale'),
        // Thông số RIÊNG của bản — repeater generic (thêm nhãn nào cũng được, KHÔNG cần field mới)
        Field::make('complex', 'specs', __('Thông số riêng bản này', 'hbtn-theme'))
            ->set_layout('tabbed-horizontal')
            ->add_fields([
                Field::make('text', 'spec_label', __('Tên thông số', 'hbtn-theme')),
                Field::make('text', 'spec_value', __('Giá trị', 'hbtn-theme')),
            ]),
    ]),
```

- **Bỏ** các field `ver_engine, ver_power, ver_torque, ver_transmission, ver_fuel` + `separator` cũ.
- `car_specs` (chung): nâng giới hạn — `->set_max(24)` hoặc bỏ `set_max` (dòng có thể ~22).

> ⚠️ **AN TOÀN DATA (đọc kỹ):** Khi xoá field `ver_*` khỏi schema, Carbon Fields **ngừng trả về** chúng qua `carbon_get_post_meta()` **dù dữ liệu vẫn nằm nguyên trong DB**. Nếu bạn đã nhập tay thông số riêng theo bản (HR-V, Civic e:HEV…), chúng sẽ **im lặng biến mất khỏi hiển thị**.
> → Plugin **≥ 0.7.1** đã có **fallback đọc thẳng postmeta thô** nên KHÔNG mất data khi hiển thị. Nhưng vẫn **BẮT BUỘC**: sau khi sửa xong, **chạy đồng bộ lại từng xe 1 lần** (xem cuối tài liệu) để plugin **ghi dữ liệu sang cấu trúc mới `specs`** — nếu không, dữ liệu chỉ "sống" nhờ fallback, dễ mất khi thao tác về sau. **Đừng xoá `ver_*` trên site đang chạy nếu plugin < 0.7.1.**

---

## Việc 2 — `single-cars.php`

### 2a. Đầu file: lấy data qua plugin (thay 3 dòng đọc cũ)

Thay:
```php
$price    = (int) preg_replace('/\D/', '', (string) carbon_get_the_post_meta('car_price'));
$specs    = carbon_get_the_post_meta('car_specs') ?: [];
$versions = carbon_get_the_post_meta('car_versions') ?: [];
```
bằng:
```php
$car      = function_exists('vig_car_data') ? vig_car_data(get_the_ID())
                                            : ['price'=>0,'versions'=>[],'common'=>[]];
$price    = (int) $car['price'];
$common   = $car['common'];   // [ ['label','value','key'], … ]  ← thông số CHUNG
// chỉ hiển thị bản đang bán:
$versions = array_values(array_filter($car['versions'], function ($v) {
                return ($v['status'] ?? 'on_sale') !== 'discontinued';
            }));
```
> (các biến khác: `$img,$colors,$promos,$gallery,$seo_*` giữ nguyên đọc `carbon_get_the_post_meta`.)

### 2b. Bỏ `$spec_key_map` + đổi `$ver_specs_json` thành generic

Xoá mảng `$spec_key_map`. Thay `$ver_specs_json` bằng:
```php
$ver_specs_json = function ($v) {
    $m = [];
    foreach ($v['specs'] as $s) $m[$s['key']] = $s['value'];   // key = sanitize_title(nhãn), do plugin cấp
    return wp_json_encode($m);
};
```
`$first` (bản mặc định) và `$vprefix` (rút gọn nhãn tab) **giữ nguyên** — vẫn chạy trên `$versions` đã lọc.

### 2c. Bảng thông số chung: gắn `data-spec-key` từ key của plugin

Thay vòng lặp render `$specs` (dùng `$spec_key_map`) bằng:
```php
<?php foreach ($common as $s) : ?>
  <div class="spec-row">
    <span class="spec-row-label"><?php echo esc_html($s['label']); ?></span>
    <span class="spec-row-value" data-spec-key="<?php echo esc_attr($s['key']); ?>"><?php echo esc_html($s['value']); ?></span>
  </div>
<?php endforeach; ?>
```

### 2d. Các vòng lặp tab phiên bản (2 chỗ: `.vtab` và `.vtab-or`)

Giữ nguyên cấu trúc — chỉ đảm bảo lặp trên `$versions` (đã lọc) và dùng `$v['price']`, `$v['name']`, `$ver_specs_json($v)`. Không còn `$v['ver_*']`. Ví dụ:
```php
<?php foreach ($versions as $i => $v) :
    $vp  = (int) preg_replace('/\D/', '', (string) $v['price']);
    $lbl = trim(substr($v['name'], strlen($vprefix))); if ($lbl === '') $lbl = $v['name']; ?>
  <button type="button" class="vtab<?php echo $i === 0 ? ' active' : ''; ?>"
          data-price="<?php echo esc_attr($vp); ?>"
          data-name="<?php echo esc_attr($v['name']); ?>"
          data-specs="<?php echo esc_attr($ver_specs_json($v)); ?>"><?php echo esc_html($lbl); ?></button>
<?php endforeach; ?>
```

---

## `js/car.js` — KHÔNG đổi ✅

Cơ chế override đã generic: nó lấy mọi `[data-spec-key]`, và với mỗi bản đọc `data-specs` (map key→giá trị) để thay. Vì `data-spec-key` (ở bảng chung) và key trong `data-specs` (ở bản) đều = `sanitize_title(nhãn)` do **plugin** cấp → luôn khớp. Máy tính **giá lăn bánh** cũng không đổi (vẫn đọc `data-price` của bản đang chọn).

---

## Sau khi sửa: chạy lại đồng bộ 1 lần để GHI sang cấu trúc mới

Bước này **ghi dữ liệu sang `specs` lồng** (không còn chỉ sống nhờ fallback) + đảm bảo bảng chung đủ dòng để override. Chọn **1 trong 2 cách** tuỳ site:

**Cách A — WP-Admin (không cần SSH/WP-CLI) — dùng cho HBTN:**
Vào **Xe → Đồng bộ dữ liệu** → mỗi dòng xe bấm **Đồng bộ** → **Chấp nhận**. Trang này tự tải Hub qua HTTP phía server rồi ghi — **không cần WP-CLI**. (Cạnh mỗi xe có nhãn *Cần cập nhật / Mới nhất* để biết xe nào cần.)

**Cách B — WP-CLI (chỉ site có SSH + `wp`) — tuỳ chọn, để tự động hoá:**
```bash
wp vig-car pull --all --changed --yes
```

> ⚠️ **Lưu ý về "đồng bộ lại":** nếu xe lấy nguồn từ Hub (`vighub:…`), đồng bộ sẽ ghi **theo dữ liệu Hub**. Với model mà Hub **chưa có** thông số riêng của bản (vd HR-V, Civic hiện chưa có), đồng bộ sẽ **không tự điền** phần đó — thông số riêng bạn **nhập tay** vẫn được **fallback giữ lại khi hiển thị**, và sẽ được ghi sang `specs` mới khi bạn **Cập nhật (lưu) lại bài xe** đó trong admin. Muốn Hub có sẵn thông số riêng cho HR-V/Civic thì bổ sung vào kho `vig-car-data` (như đã làm cho CR-V).

## Từ nay về sau

| Thao tác | Sửa theme? |
|---|---|
| Thêm/bớt dòng xe, phiên bản | ❌ chỉ data |
| Thêm **loại thông số mới** (vd "Dung lượng pin") | ❌ chỉ data (repeater) |
| Ngừng bán | ❌ chỉ data (`status`) |

## Checklist test (1 xe CR-V)

- [ ] Tab **Thông số** trong admin: `car_versions` có ô Trạng thái + repeater "Thông số riêng bản này"
- [ ] Ngoài web: bấm các tab bản → Động cơ/Số chỗ/Hộp số/Tiêu thụ **đổi theo bản** (G=1.5T/7 chỗ; e:HEV=2.0 Hybrid/5 chỗ)
- [ ] Bản **ngừng bán không hiện**; bản mặc định là bản đang bán
- [ ] Máy tính **giá lăn bánh** vẫn đúng theo bản đang chọn
