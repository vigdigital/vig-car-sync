# HBTN theme — CHỈ các đoạn cần chỉnh

Yêu cầu: plugin VIG Car Sync ≥ 0.8.0.

> ⚠️ **`js/car.js` CÓ sửa** (1 đoạn nhỏ — xem File 3). Vì thiết kế 2 bảng (ngắn + đầy đủ) khiến 1 `data-spec-key`
> xuất hiện ở **2 phần tử**; car.js cũ chỉ giữ 1 phần tử/key nên bảng ngắn ngừng đổi khi bấm bản. Phải gom mảng.

---

## FILE 1 — `inc/carbon-fields-fields.php`

**Thay** khối `Field::make('complex','car_versions',…)->add_fields([...])` (đang có `ver_engine…ver_fuel`) **bằng:**

```php
Field::make('complex', 'car_versions', __('Phiên bản & giá', 'hbtn-theme'))
    ->set_layout('tabbed-horizontal')
    ->add_fields([
        Field::make('text',   'name',   __('Tên phiên bản', 'hbtn-theme')),
        Field::make('text',   'price',  __('Giá niêm yết (VNĐ)', 'hbtn-theme')),
        Field::make('select', 'status', __('Trạng thái', 'hbtn-theme'))
            ->set_options(['on_sale' => 'Đang bán', 'discontinued' => 'Ngừng bán'])
            ->set_default_value('on_sale'),
        Field::make('complex', 'specs', __('Thông số riêng bản này', 'hbtn-theme'))
            ->set_layout('tabbed-horizontal')
            ->add_fields([
                Field::make('text', 'spec_label', __('Tên thông số', 'hbtn-theme')),
                Field::make('text', 'spec_value', __('Giá trị', 'hbtn-theme')),
            ]),
    ]),
```

**Và** ở `car_specs`: `->set_max(12)` → **`->set_max(24)`**.

> ⚠️ Chỉ xoá `ver_*` khi plugin đã ≥ 0.7.1 (có fallback chống mất data). Sau khi sửa xong 2 file → vào **Xe → Đồng bộ** bấm Đồng bộ từng xe 1 lần để ghi sang cấu trúc mới.

---

## FILE 2 — `single-cars.php`

### (a) Đầu file — **thay** 3 dòng đọc data:
```php
$price    = (int) preg_replace('/\D/', '', (string) carbon_get_the_post_meta('car_price'));
$specs    = carbon_get_the_post_meta('car_specs') ?: [];
$versions = carbon_get_the_post_meta('car_versions') ?: [];
```
**bằng:**
```php
$car      = function_exists('vig_car_data') ? vig_car_data(get_the_ID()) : ['price'=>0,'versions'=>[],'common'=>[]];
$price    = (int) $car['price'];
$common   = $car['common'];
$versions = array_values(array_filter($car['versions'], fn($v) => ($v['status'] ?? 'on_sale') !== 'discontinued'));
```

### (b) **Xoá** mảng `$spec_key_map = [ … ];` và **thay** `$ver_specs_json` **bằng:**
```php
$ver_specs_json = function ($v) {
    $m = [];
    foreach ($v['specs'] as $s) $m[$s['key']] = $s['value'];
    return wp_json_encode($m);
};
```

### (c) **Thay** vòng lặp render `$specs` trong `.specs-list` **bằng** (bảng NGẮN — chỉ thông số cơ bản):
```php
<?php foreach ($common as $s) : if (empty($s['basic'])) continue; ?>
  <div class="spec-row">
    <span class="spec-row-label"><?php echo esc_html($s['label']); ?></span>
    <span class="spec-row-value" data-spec-key="<?php echo esc_attr($s['key']); ?>"><?php echo esc_html($s['value']); ?></span>
  </div>
<?php endforeach; ?>
```

### (d) **Thêm** ngay sau `.specs-list` (bảng ĐẦY ĐỦ, gập/mở, không cần JS):
```php
<details class="specs-full">
  <summary>Xem thông số đầy đủ</summary>
  <table>
    <?php foreach ($common as $s) : ?>
      <tr><td><?php echo esc_html($s['label']); ?></td>
          <td data-spec-key="<?php echo esc_attr($s['key']); ?>"><?php echo esc_html($s['value']); ?></td></tr>
    <?php endforeach; ?>
  </table>
</details>
```

### (e) Hai vòng lặp tab (`.vtab` và `.vtab-or`): giữ nguyên, chỉ đảm bảo **không** còn dùng `$v['ver_*']` — data bản lấy qua `$ver_specs_json($v)`, `$v['price']`, `$v['name']`.

---

## FILE 3 — `js/car.js` (BẮT BUỘC với thiết kế 2 bảng)

Trong đoạn khởi tạo `specEls` + hàm `applySpecs`, **thay** để mỗi key giữ **nhiều phần tử**:

**Thay:**
```js
var specEls = {};
[].slice.call(document.querySelectorAll('[data-spec-key]')).forEach(function (el) {
  specEls[el.dataset.specKey] = { el: el, def: el.textContent };
});
function applySpecs(specsJson) {
  var overrides = {};
  try { overrides = JSON.parse(specsJson || '{}'); } catch (e) {}
  Object.keys(specEls).forEach(function (key) {
    var entry = specEls[key];
    entry.el.textContent = overrides[key] ? overrides[key] : entry.def;
  });
}
```
**Bằng:**
```js
var specEls = {};
[].slice.call(document.querySelectorAll('[data-spec-key]')).forEach(function (el) {
  var k = el.dataset.specKey;
  (specEls[k] = specEls[k] || []).push({ el: el, def: el.textContent });   // mảng: gom MỌI phần tử cùng key
});
function applySpecs(specsJson) {
  var overrides = {};
  try { overrides = JSON.parse(specsJson || '{}'); } catch (e) {}
  Object.keys(specEls).forEach(function (key) {
    specEls[key].forEach(function (entry) {                                 // cập nhật CẢ bảng ngắn lẫn đầy đủ
      entry.el.textContent = overrides[key] ? overrides[key] : entry.def;
    });
  });
}
```

---

## FILE 4 — CSS (thêm vào style của theme)

```css
.specs-full > summary{cursor:pointer;color:#c00;font-weight:600;padding:8px 0;list-style:none}
.specs-full > summary::after{content:" ▾"}
.specs-full[open] > summary::after{content:" ▴"}
.specs-full table{width:100%;border-collapse:collapse}
.specs-full td{padding:8px 4px;border-bottom:1px solid #eee}
.specs-full td:first-child{color:#666;width:45%}
```

---

## (Tuỳ chọn) đổi nhóm thông số cơ bản — `functions.php`

Mặc định 6: Động cơ · Hộp số · Công suất · Số chỗ ngồi · Nhiên liệu · Mức tiêu thụ. Đổi:
```php
add_filter('vcs_basic_specs', fn() => ['Động cơ', 'Công suất', 'Số chỗ ngồi', 'Hộp số']);
```
