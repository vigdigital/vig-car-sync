# VIG Car Sync

Trích xuất dữ liệu xe từ nguồn ngoài (VnExpress) → đối chiếu & đồng bộ vào website. Nguyên mẫu cho **VIG Data Engine** (Data Engine theo hãng, bơm data cho nhiều site sale).

## Dùng
1. Mở trang sửa 1 xe (CPT `cars`) → box **"Nguồn dữ liệu (đồng bộ)"** bên phải → dán URL VnExpress V-Car → Cập nhật.
2. Vào **Xe → Đồng bộ dữ liệu** → bấm **Đồng bộ** ở dòng xe → hiện bảng so sánh (giá trị mới/khác **tô xanh**) → **Chấp nhận đồng bộ** để ghi đè.

## Trường được đồng bộ → xem **[Hợp đồng dữ liệu (docs/DATA-CONTRACT.md)](docs/DATA-CONTRACT.md)**

Theme phải định nghĩa 3 Carbon Fields trên CPT `cars` (plugin tra theo tên):

| Field | Kiểu | Sub-field |
|---|---|---|
| `car_price` | text (số) | — |
| `car_versions` | complex (repeater) | `name`, `price` |
| `car_specs` | complex (repeater) | `spec_label`, `spec_value` |

`car_specs` là **repeater** (mỗi thông số 1 dòng), không phải mỗi thông số 1 field.
Code đăng ký field + bộ nhãn thông số chuẩn + cách đọc ra front-end: xem [DATA-CONTRACT.md](docs/DATA-CONTRACT.md).

- **Merge thông minh:** thông số nguồn không có (kích thước, trục cơ sở…) được **giữ nguyên**, không bị xoá.
- Meta ẩn `_vcs_source_url` (tham chiếu nguồn) plugin tự quản — theme đừng đụng.

## Nguồn hỗ trợ (tự nhận diện theo URL)
| Nguồn | URL | Ghi chú |
|---|---|---|
| **Honda VN (chính hãng)** | `honda.com.vn/o-to/chi-tiet/{id}` | **Nguồn chính** — 13 thông số đầy đủ: kích thước, trục cơ sở, khoảng sáng gầm, cỡ lốp, trọng lượng, mức tiêu thụ + giá mới nhất |
| VnExpress V-Car | `vnexpress.net/.../v-car/dong-xe/...` | Dự phòng — thiếu kích thước |

Map ID Honda (honda.com.vn/o-to/chi-tiet/N): **City=2 · Civic=19 · CR-V=17 · HR-V=21 · BR-V=18 · Accord=20**.

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
