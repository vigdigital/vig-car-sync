# VIG Car Sync

Trích xuất dữ liệu xe từ nguồn ngoài (VnExpress) → đối chiếu & đồng bộ vào website. Nguyên mẫu cho **VIG Data Engine** (Data Engine theo hãng, bơm data cho nhiều site sale).

## Dùng

**Cách 1 — WP-Admin:**
1. Mở trang sửa 1 xe (CPT `cars`) → box **"Nguồn dữ liệu (đồng bộ)"** bên phải → dán URL nguồn (vd `vighub:honda/honda-cr-v`, hoặc URL Honda/VnExpress) → Cập nhật.
2. Vào **Xe → Đồng bộ dữ liệu** → bấm **Đồng bộ** ở dòng xe → hiện bảng so sánh (giá trị mới/khác **tô xanh**) → **Chấp nhận đồng bộ** để ghi đè.

**Cách 2 — WP-CLI** (TUỲ CHỌN — chỉ site có **SSH + `wp`**; để tự động hoá):

> Site **không có SSH/WP-CLI** (vd HBTN) dùng **Cách 1 (WP-Admin)** — trang **Xe → Đồng bộ** tải Hub qua HTTP phía server rồi ghi, y hệt CLI, kèm nhãn *Cần cập nhật/Mới nhất* để biết xe nào cần. Không cần viết script HTTP riêng.

```bash
# XEM xe nào cần đồng bộ (chỉ tải index.json, so mã rev):
wp vig-car status               # tất cả xe: Mới nhất / Cần cập nhật / Chưa sync
wp vig-car status --changed     # chỉ xe cần cập nhật

# ĐỒNG BỘ:
wp vig-car pull --post=123 --source=vighub:honda/honda-cr-v --yes  # 1 xe, ép nguồn, ghi luôn
wp vig-car pull --all --changed --yes                             # CHỈ xe đổi ở hub (khuyên dùng)
wp vig-car pull --all --dry-run                                   # xem trước mọi xe, không ghi
```
> **Cơ chế biết xe nào cần sync:** mỗi model ở hub có mã **`rev`** (băm nội dung giá+phiên bản+thông số) trong `index.json`. Khi ghi, site lưu rev đã sync vào meta `_vcs_hub_rev`. `status`/`--changed` so rev site ↔ hub → chỉ đụng xe thật sự đổi. Trang **Xe → Đồng bộ** cũng hiện nhãn *Cần cập nhật / Mới nhất*.
>
> `wp vig-car build …` là lệnh **PRODUCER** (tạo file JSON kho, chạy ở máy build của VIG) — khác `pull`.

## Trường được đồng bộ → xem **[Hợp đồng dữ liệu (docs/DATA-CONTRACT.md)](docs/DATA-CONTRACT.md)**

Theme phải định nghĩa 3 Carbon Fields trên CPT `cars` (plugin tra theo tên):

| Field | Kiểu | Sub-field |
|---|---|---|
| `car_price` | text (số) | — |
| `car_versions` | complex (repeater) | `name`, `price`, `status` (đang bán/ngừng bán), `specs` (complex lồng: `spec_label`, `spec_value`) |
| `car_specs` | complex (repeater) | `spec_label`, `spec_value` |

`car_specs` = thông số **CHUNG** cả dòng; `car_versions.specs` = thông số **RIÊNG** từng bản (vì có dòng khác động cơ/số chỗ giữa các bản, vd CR-V G/L = 1.5T 7 chỗ, e:HEV = 2.0 Hybrid 5 chỗ). `status`/`specs` là tuỳ chọn — theme chưa thêm vẫn chạy.
Code đăng ký field + bộ nhãn thông số chuẩn + cách đọc ra front-end: xem [DATA-CONTRACT.md](docs/DATA-CONTRACT.md).

- **Merge thông minh:** thông số nguồn không có (kích thước, trục cơ sở…) được **giữ nguyên**, không bị xoá.
- Meta ẩn `_vcs_source_url` (tham chiếu nguồn) plugin tự quản — theme đừng đụng.

## Nguồn hỗ trợ (tự nhận diện theo URL)
| Nguồn | URL | Ghi chú |
|---|---|---|
| **Honda VN (chính hãng)** | `honda.com.vn/o-to/chi-tiet/{id}` | **Nguồn chính** — 13 thông số đầy đủ: kích thước, trục cơ sở, khoảng sáng gầm, cỡ lốp, trọng lượng, mức tiêu thụ + giá mới nhất |
| VnExpress V-Car | `vnexpress.net/.../v-car/dong-xe/...` | Dự phòng — thiếu kích thước |

Map ID Honda (honda.com.vn/o-to/chi-tiet/N): **City=2 · Civic=19 · Civic Type R=20 · CR-V=17 · HR-V=21 · BR-V=18** (Accord đã ngừng bán VN).

Ghép tên phiên bản ↔ giá theo `data-version` (index), không theo thứ tự DOM — chịu được bản trùng/ẩn (vd CR-V có 5 bản, index không tuần tự). Công suất/mô-men bản hybrid rút gọn "tổng hệ hybrid" / "mô-tơ điện".

## Kiến trúc (mở rộng)
```
Source (nguồn)  →  Normalized  →  Differ (so sánh)  →  Repository (ghi vào site)
```
- **Thêm nguồn mới** (hãng khác / trang khác): tạo class `implements VCS_Source_Interface`, đăng ký trong `VCS_Sources::all()` hoặc qua filter `vcs_sources`. Nguồn trả về mảng chuẩn hoá (`versions`, `specs` đã map sang label chuẩn của site).
- **Đổi target** (sau này đẩy sang nhiều site / VIG trung tâm): thay `VCS_Repository` bằng target khác — phần Source/Differ giữ nguyên.

## File
| File | Vai trò |
|---|---|
| `interface-source.php` | Hợp đồng nguồn |
| `class-source-honda.php` | Parser Honda chính hãng (bảng `.col-data`) — nguồn giàu nhất |
| `class-source-vnexpress.php` | Parser VnExpress (DOMXPath) + map spec → label HBTN |
| `class-sources.php` | Registry, detect nguồn theo URL |
| `class-repository.php` | Đọc/ghi Carbon Fields HBTN (build_new merge + apply có retry) |
| `class-differ.php` | So sánh hiện tại vs mới → hàng same/changed/new/removed |
| `class-admin.php` | Metabox URL + trang đồng bộ + AJAX + render bảng |

## Ghi chú
- Giá/công suất/mô-men lấy trực tiếp từ VnExpress; VnExpress **không** có kích thước → cần nhập tay 1 lần (plugin giữ nguyên khi sync).
- `carbon_set_post_meta` complex đôi khi bỏ lần ghi đầu trong 1 request → `Repository::set()` verify + retry.
