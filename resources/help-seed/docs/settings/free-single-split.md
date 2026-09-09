---
key: settings.ai.free_single_split
title: Free, Single và Split hoạt động thế nào?
summary: Thứ tự model trong AI Center quyết định Single hay Split; Free only kiểm soát fallback sang Paid. Split Outline và Split Content là hai cơ chế khác nhau.
group: settings
sort_order: 180
keywords:
  - free
  - single
  - split
  - outline
  - free only
  - ai center
  - sortable
updated_at: '2026-09-09'
---
# Free, Single và Split hoạt động thế nào?

SEO Ops luôn ưu tiên **thứ tự model** bạn đã kéo trong AI Center (Sortable).

Thứ tự model quyết định model nào được xét trước.
**Free/Paid không tự thay đổi thứ tự này.**

## Quy tắc chính

- Model đầu tiên có thể chạy là **Paid** → dùng **Single** (chạy một lượt).
- Model đầu tiên có thể chạy là **Free** → dùng **Split**.
- **Free only** bật → chỉ model Free được phép chạy.
- **Free only** tắt → router vẫn có thể fallback sang model Paid nếu các route Free gặp lỗi hoặc hết khả dụng.

Trong Global SEO Bar → **Tạo bài bằng AI → Chế độ**:

- **Bình thường** = Free only tắt
- **Chỉ Free** = Free only bật

Việc Split **không** có nghĩa hệ thống bị khóa vào OpenRouter.
Split chỉ là cách chia nhỏ công việc; mỗi lần gọi AI vẫn đi qua router bình thường.

## Split Outline là gì?

Khi Outline chạy theo chế độ Split, hệ thống không yêu cầu một model tạo toàn bộ dữ liệu Outline trong một lần.

Outline được tách thành **hai tác vụ AI**:

1. Dàn ý / cấu trúc bài — `article.outline.structure.generate`
2. Từ vựng / Vocabulary — `article.vocabulary.generate`

Sau khi cả hai hoàn thành, SEO Ops ghép chúng thành context Outline hoàn chỉnh để chuyển sang bước viết bài.

**Mục đích:**

- giảm độ khó của mỗi prompt
- phù hợp hơn với model Free
- giảm nguy cơ một prompt lớn bị thiếu dữ liệu
- cho phép quan sát lỗi Structure và Vocabulary riêng biệt

**Split Outline = 2 AI tasks.**

Nó **không** có nghĩa:

- dùng cố định OpenRouter
- dùng cố định một model Free
- bỏ qua Sortable
- bỏ qua router

Mỗi task vẫn dùng hệ thống AI routing bình thường.

## Split từ Outline sang Content là gì?

Đây là một cơ chế **khác** với Split Outline.

Sau khi đã có Outline hoàn chỉnh, bước viết Article Content có thể tiếp tục chia bài theo các phần/heading trong Outline.

Ví dụ Outline:

- H2 A
- H2 B
- H2 C
- H2 D
- H2 E
- H2 F

Ở chế độ **Split Content**, SEO Ops có thể viết từng phần riêng rồi ghép chúng thành bài hoàn chỉnh.

Ví dụ:

Outline hoàn chỉnh → viết phần A → viết phần B → viết phần C → … → ghép thành Article Content

**Mục đích:**

- tránh bắt model Free viết một bài rất dài trong một request
- giảm nguy cơ output bị cắt ngắn
- dễ retry/resume từng phần
- tận dụng model Free cho long-form content

## Hai loại Split không giống nhau

**Split Outline** (tách Dàn ý thành Structure + Vocabulary):

Outline → Structure → Vocabulary → ghép Outline

**Split Content** (chia nội dung theo các phần của Outline rồi ghép lại):

Outline đã hoàn chỉnh → chia theo các phần/heading → viết từng phần → ghép Article Content

Một lần chạy có thể trông như sau:

```
Split Outline
    Structure
    Vocabulary
        ↓
Complete Outline
        ↓
Split Content
    Section 1
    Section 2
    Section 3
    ...
        ↓
Complete Article
```

Đây **không** phải một thao tác Split duy nhất.

## Vì sao cần chú ý khi để Free đứng đầu?

Split có thể tạo **nhiều lần gọi AI hơn** Single.

Ví dụ:

- **Single:** → 1 request lớn
- **Split Content:** → nhiều request nhỏ theo từng phần của bài

Nếu **Free only** đang **TẮT**, router vẫn có thể fallback sang model Paid khi model Free không thể tiếp tục.

Vì vậy một bài bắt đầu bằng model Free nhưng fallback sang Paid trong quá trình Split có thể phát sinh **nhiều lượt gọi Paid hơn** so với chạy Single bằng Paid ngay từ đầu.

Nếu mục tiêu của bạn là:

> Dùng Free, nếu Free không chạy được thì dừng

hãy bật **Free only** (chỉ cho phép model Free).

Nếu mục tiêu là:

> Ưu tiên Free nhưng vẫn cần bài chạy xong

có thể để **Free only = OFF** và chấp nhận khả năng fallback sang Paid.

## Khi nào nên để model Paid đứng đầu?

Nếu model Paid đứng đầu Sortable và đang khả dụng, hệ thống dùng **Single** (chạy một lượt).

Single phù hợp khi:

- model có khả năng xử lý context dài
- muốn ít request hơn
- muốn tránh nhiều lần gọi Paid
- ưu tiên tính nhất quán của toàn bài

Hành vi Free/Paid phụ thuộc **route cost class**, không phụ thuộc thương hiệu provider.

## Ví dụ nhanh

### Ví dụ 1

AI Center:

1. Free Pool
2. Paid Model A
3. Paid Model B

Free only: **OFF**

→ First usable route = Free → **Split**.
Nếu Free sau đó lỗi → vẫn có thể fallback sang Paid.

### Ví dụ 2

AI Center:

1. Paid Model A
2. Free Pool

→ First usable route = Paid → **Single**.

### Ví dụ 3

AI Center:

1. Paid Model A
2. Free Pool

Paid Model A unavailable/cooldown.

→ Free Pool trở thành first usable route → **Split**.

### Ví dụ 4

Free only = **ON**

Paid models bị loại theo policy.

→ First usable Free route → **Split**.
Nếu không còn Free route nào tiếp tục được → execution **fails** thay vì dùng Paid.

## Kiểm tra hệ thống đã chạy kiểu nào

Mở **AI History** để xem:

- Prompt type
- model/provider đã dùng
- Free/Paid
- routing attempts
- skipped routes
- validation
- execution result

Khi có metadata hình thức chạy: **Single / Split** cho biết execution shape.

Không nên suy luận hành vi chỉ từ thông báo lỗi cuối cùng.
