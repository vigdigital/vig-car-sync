# Decouple hiển thị xe khỏi cấu trúc theme — phân tích 3 hướng

**Vấn đề:** mỗi lần dữ liệu xe đổi, có phải sửa cấu trúc theme không? Có cách load trực tiếp từ plugin thay vì qua theme?

---

## 1. Trước tiên: phân biệt "đổi DATA" vs "đổi CẤU TRÚC"

Không phải thay đổi nào cũng đụng theme. Với thiết kế hiện tại (theme hbtn + field phẳng `ver_*`):

| Thao tác | Đụng theme? | Vì sao |
|---|---|---|
| Thêm **dòng xe** mới | ❌ Không | 1 dòng = 1 post `cars`, theme render generic |
| Thêm/bớt **phiên bản** | ❌ Không | `car_versions` là repeater — thêm/bớt dòng data |
| Đổi **giá / giá trị thông số** | ❌ Không | Chỉ là data |
| **Ngừng bán** dòng/bản | ❌ Không | Đã có field `status` |
| Thêm **LOẠI thông số mới** theo bản (vd "Dung lượng pin" cho xe điện) | ⚠️ **CÓ** | Theme hardcode 9 ô `ver_*` cố định → phải thêm ô |

**Kết luận:** thứ bạn lo ("thường xuyên bổ sung/ngừng bán dòng & bản") **đã không cần đụng theme**. Chỉ còn **1 chỗ coupling**: khi xuất hiện **loại thông số chưa có ô**.

---

## 2. Nguồn gốc coupling

Theme hbtn hiện đọc thông số riêng của bản qua **field cố định**:
`ver_engine, ver_power, ver_torque, ver_transmission, ver_fuel, ver_seats, ver_drivetrain, ver_consumption, ver_weight` + `$spec_key_map` khớp cứng nhãn → key.

→ Nhãn thông số nằm trong **code theme**, nên nhãn mới = sửa code.

## 3. Ràng buộc quan trọng của theme hbtn (ảnh hưởng lựa chọn)

Trang chi tiết xe không chỉ có bảng thông số. Tab phiên bản (`.vtab` / `.vtab-or`) **đồng thời điều khiển**:
- Giá hiển thị + tên bản đang chọn
- **Máy tính giá lăn bánh** (`renderOnroad()` — phí trước bạ, biển số… theo cấu hình vùng qua `hbtn_onroad_config()`)
- (Độc lập: bộ chọn màu, gallery)

→ **Logic tính lăn bánh là "của theme"** (nghiệp vụ địa phương), gắn chặt với việc chọn bản. Đây là lý do "plugin vẽ hết trang" **không đơn giản** — nếu plugin nuốt luôn phần tab/giá thì phải hoặc bê cả máy tính lăn bánh vào plugin, hoặc để plugin phát sự kiện cho theme. Cả hai đều thêm phức tạp.

---

## 4. Ba hướng đi

### 🟢 A — Theme đọc DATA generic qua helper của plugin *(khuyến nghị)*

- Chuyển thông số riêng của bản từ 9 ô `ver_*` → **1 repeater generic** (nhãn/giá-trị), giống `car_specs` chung.
- Plugin cung cấp **hàm đọc chuẩn hoá**:
  ```php
  $data = vig_car_data( $post_id );
  // [ 'versions' => [ ['name','price','status','specs'=>[['label','value'],…]], … ],
  //   'common'   => [ ['label','value'], … ] ]
  ```
- Theme `single-cars.php` **lặp generic** trên `$data` (vẽ tab + bảng thông số bằng vòng lặp, không gọi tên field cố định). Máy tính lăn bánh **giữ nguyên** (vẫn đọc giá bản đang chọn).

**Hết coupling:** thêm dòng/bản/**loại thông số mới**/ngừng bán = **chỉ data**, mãi mãi không sửa theme.
**Giữ được:** toàn bộ thiết kế hiện có (hero, màu, lăn bánh, tab) — chỉ đổi *cách đọc* thông số từ "field cố định" → "lặp mảng".
**Công sức:** vừa. Sửa ~1 vùng trong `single-cars.php` (khối render specs + `data-specs`) + plugin thêm hàm `vig_car_data()` + migrate data `ver_*` → repeater. Máy tính lăn bánh, màu, gallery **không đụng**.
**Rủi ro:** thấp — thiết kế/nghiệp vụ giữ nguyên, chỉ đổi cách lấy thông số.

### 🟡 B — Plugin render nguyên khối (shortcode `[vig_car_specs]`)

- Plugin tự xuất HTML tab + bảng thông số + JS đổi tab.
- Theme chỉ nhúng shortcode + CSS.

**Hết coupling:** có, kể cả loại thông số mới.
**Vấn đề:** khối tab do plugin vẽ, nhưng **máy tính lăn bánh của theme** cần biết "bản đang chọn / giá" → phải bắc cầu (plugin phát event JS, theme lắng nghe), hoặc bê luôn máy tính lăn bánh vào plugin (mất tính "của theme", khó tuỳ biến phí theo vùng). Markup do plugin quy định → theme khó can thiệp layout tinh.
**Công sức:** cao. Viết renderer + JS trong plugin, thiết kế cơ chế event, chỉnh theme bỏ khối cũ, migrate data.
**Rủi ro:** trung bình–cao — dễ vênh với thiết kế bespoke hiện có + nghiệp vụ lăn bánh.
**Khi nào hợp:** site mới, theme "mỏng", chưa có thiết kế/nghiệp vụ phức tạp.

### ⚪ C — Giữ nguyên (field phẳng `ver_*`)

**Không coupling** cho: thêm/bớt dòng, bản, giá, ngừng bán (việc thường ngày).
**Còn coupling** cho: loại thông số mới → thêm 1 field vào theme (hiếm; 9 ô đã phủ gần hết).
**Công sức:** 0 (đang chạy).
**Rủi ro:** 0.

---

## 5. So sánh nhanh

| | A: theme đọc generic | B: plugin render | C: giữ nguyên |
|---|---|---|---|
| Thêm dòng/bản/ngừng bán | data | data | data |
| **Loại thông số mới** | **data** ✅ | **data** ✅ | sửa theme ⚠️ |
| Giữ thiết kế hiện có | ✅ nguyên vẹn | ⚠️ phải dựng lại | ✅ |
| Máy tính lăn bánh | ✅ không đụng | ⚠️ phải bắc cầu | ✅ |
| Công sức 1 lần | Vừa | Cao | 0 |
| Rủi ro | Thấp | TB–Cao | 0 |

---

## 6. Khuyến nghị

**Chọn A.** Nó giải quyết đúng nỗi lo ("đổi data không phải sửa cấu trúc theme") — kể cả loại thông số mới — mà **giữ trọn thiết kế + máy tính lăn bánh** hiện có. B tuy "load thẳng từ plugin" đúng chữ, nhưng đụng độ với nghiệp vụ lăn bánh và thiết kế riêng của hbtn, không đáng cho site đã có sẵn giao diện đẹp.

**Nếu chưa cần ngay:** C vẫn ổn cho vận hành hằng ngày; chỉ khi sắp có dòng xe dùng **nhóm thông số mới** (vd xe điện) thì nâng lên A.

### Phác thảo công sức cho A
1. **Plugin:** thêm hàm `vig_car_data($id)` (đọc car_versions + car_specs → mảng chuẩn) + đổi Repository lưu specs bản dạng **repeater** thay vì `ver_*`. Cung cấp cả `car_versions.specs` (complex lồng) — đã có sẵn trong contract generic.
2. **Theme:** thay khối render specs cứng trong `single-cars.php` bằng vòng lặp trên `vig_car_data()`; `$ver_specs_json` sinh động từ mảng (không liệt kê field). Bỏ `$spec_key_map` cứng → sinh key tự động từ nhãn.
3. **Migrate:** 1 lần chuyển dữ liệu `ver_*` hiện có → repeater (script hoặc sync lại từ hub).
4. `car.js` sửa 1 đoạn nhỏ nếu render **2 bảng** (ngắn+đầy đủ): gom `specEls[key]` thành mảng để cập nhật mọi phần tử cùng key (bản gốc chỉ giữ 1 phần tử/key → bảng thứ 2 đè mất bảng đầu). Xem `hbtn-changeset.md` File 3.

> Ước lượng: nửa ngày công (plugin + theme + test 1 xe). Sau đó thêm/bớt bất cứ thứ gì = chỉ data.
