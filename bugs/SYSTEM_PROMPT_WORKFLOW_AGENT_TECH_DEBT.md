# Technical Debt — System Prompt / Workflow / Agent / Storage

> Mục đích: lưu lại các khoản nợ kỹ thuật sau đợt tách Prompt + Workflow sang System layer để tránh quên và tránh sửa lan man trong các task khác.

## 1. Trạng thái hiện tại

### Đã hoàn tất
- Prompt / System AI đã có System boundary phía client.
- Task / System Workflow đã có System boundary phía client.
- Prompt UI đã chuyển sang Admin, reuse UI cũ.
- Workflow/Task UI đã chuyển sang Admin, reuse UI cũ.
- Production Workflow callers đã đi qua `SystemWorkflowClient`.
- Đã có production proof cho:
  - `FULL_RUN`
  - `FROM_NODE`
  - `SINGLE_STEP`
  - `outline_vocabulary` scope
- SEO compatibility routes vẫn giữ:
  - `/seo/prompts*`
  - `/seo/tasks*`

### Chưa hoàn tất
- Agent mới ở mức scaffold/System boundary, chưa có production cutover tương đương Prompt/Workflow.
- Physical database ownership của Prompt/Task vẫn còn ở SEO DB.
- Workflow remote/API parity vẫn còn các phần cần hoàn thiện/verify.
- Execution history vẫn nằm ở SEO DB.

## 2. Database ownership debt

### Có thể tách tương đối an toàn sang Client/System DB
Nhóm này là **definition/config data**, thay đổi ít:

- Prompt definitions
- Prompt versions
- Task definitions
- Workflow `flow_data`
- Prompt/Task bindings/config liên quan System

### Chưa nên chuyển cùng đợt
Giữ ở SEO DB:

- `prompt_results`
- `prompt_result_routing_attempts`
- Article execution history
- Content Project execution history

### Lý do
`prompt_results` và `prompt_result_routing_attempts` đang dính nhiều logic runtime:

- article id
- project/task execution
- prompt/version
- provider/model
- routing attempts
- retry/fallback
- split parent/child
- AI history/debug
- production evidence

Nếu chuyển 2 bảng này sang Client DB ngay, rủi ro lớn nhất không phải lỗi system mà là **lỗi logic âm thầm**:

- query vẫn chạy nhưng lấy sai relation
- history thiếu/mất liên kết
- retry/fallback đọc sai execution
- article/debug UI lệch dữ liệu
- cross-connection relation sai nhưng không crash ngay
- ownership/account filter bị lệch

=> Nếu migrate execution history sau này, phải coi là **một project riêng** và retest toàn bộ logic liên quan.

## 3. Definition storage migration plan

Khi chuyển Prompt + Task definitions sang Client DB:

1. Copy dữ liệu trước, không xóa nguồn cũ ngay.
2. Giữ nguyên ID nếu có thể:
   - prompt id
   - prompt version id
   - task id
3. Verify:
   - row count
   - current version pointer
   - prompt key
   - task flow hash
   - owner/account
4. Switch read path.
5. Switch write path.
6. Giữ bảng cũ read-only một thời gian rollback.
7. Sau khi production proof xanh mới cân nhắc cleanup.

### Điểm cần soi kỹ
- `prompt_results -> prompt/version` relation
- installer/repair/seed còn ghi vào SEO DB không
- Prompt #26 ownership kiểu cũ không được lặp lại
- default Task/Prompt creation path
- import/export
- AI History / rerun / debug links
- multi-tenant owner/account resolver

## 4. Logic regression risk — bắt buộc test lại

Ưu tiên test logic hơn test system.

### Prompt/System AI
- Prompt version resolution
- current prompt version
- PromptResult parent/child
- split generation
- FREE -> sectioned
- PAID -> single_pass
- `route_cost_auto`
- retry/fallback
- short output warning
- truncation failure
- FreeOnly zero paid

### Workflow
- `FULL_RUN`
- `FROM_NODE`
- `SINGLE_STEP`
- `outline_vocabulary`
- ordered steps
- failed/blocked semantics
- `prior_steps`
- `seed_from_artifact`
- domain persistence ownership
- no caller-side legacy fallback

### Content Project
- step retry
- phase 1 Outline/Vocabulary
- phase 2 Article Writing delegation
- run-item success/fail
- counters
- evidence
- cancel/superseded guards

### Article
- body hash
- outline persistence
- meta/keywords
- PromptResult linkage
- no unintended WP publish
- no duplicate media/article writes

## 5. Workflow API debt

### Cần hoàn thiện / verify
- `RemoteHttpWorkflowTransport`
- Bearer auth parity với System AI
- `via_http_api` anti-recursion
- remote mode fail-closed
- cross-request run cache
- remote GET parity
- multi-tenant ownership guard
- cross-account execution block

### Chưa cần làm
- full Workflow shadow execution
- generic async queue engine
- real cancel/retry/resume
- new `workflow_runs` DB table

### Lý do defer
Workflow graph có side effects. Shadow/double execution có thể gây:

- duplicate PromptResults
- duplicate article writes
- duplicate media
- duplicate vocabulary
- duplicate Content Project mutations

## 6. Agent debt

Agent chưa production-cutover như Prompt/Workflow.

Khi làm Agent:
- reuse System AI
- reuse System Workflow
- Agent chỉ orchestration/decision layer
- simple chat -> AI
- complex task -> Workflow
- không duplicate Prompt/Workflow storage
- không tạo một execution engine thứ ba

## 7. Compatibility debt

Hiện vẫn giữ:

- `/seo/prompts*`
- `/seo/tasks*`

Mục đích:
- rollback
- old backlinks
- compatibility

Không cleanup chỉ để “code đẹp”.

Chỉ retire khi:
- Admin route ổn định đủ lâu
- không còn backlink cũ
- không còn external integration phụ thuộc route cũ

## 8. Deferred unrelated debt

### Content Project no-F5
Backend start đúng nhưng runtime UI đôi lúc không update đến khi F5.

Không sửa speculative.

Nếu mở lại:
- trace T0 click
- T1 run created
- T2 dispatch
- T3 HTTP response
- T4 Livewire morph

### System storage cleanup
Không chuyển storage chỉ vì muốn “mọi thứ System nằm client”.

Nguyên tắc:
> definition/config có thể tách trước; execution/history để sau.

## 9. Nguyên tắc cho các task sau

- Inspect trước khi sửa.
- Reuse trước khi rebuild.
- Không migrate nhiều layer cùng lúc.
- Không cleanup helper chỉ để grep = 0.
- Không dual-write nếu chưa có nhu cầu rõ ràng.
- Storage migration làm sau boundary/runtime migration.
- Mỗi migration phải có rollback path.
- Với logic execution: production proof quan trọng hơn unit test đơn thuần.
- Không vì system test xanh mà bỏ qua retest logic domain.

## 10. Suggested future work order

1. Hoàn tất/verify Workflow remote API parity.
2. Production cutover Agent.
3. Tách Prompt + Task **definitions** sang Client/System DB.
4. Chạy full logic regression.
5. Giữ execution/history ở SEO DB.
6. Chỉ khi thật sự cần mới thiết kế generic System Execution Store.
7. Cuối cùng mới cân nhắc migrate:
   - `prompt_results`
   - `prompt_result_routing_attempts`

## 11. Do-not-forget

Khoản nợ nguy hiểm nhất:

> **`prompt_results` + `prompt_result_routing_attempts` không phải config data.**

Chúng là execution/history và đang dính trực tiếp vào logic bài viết, routing, retry, split, debug và production evidence.

Không chuyển chúng chỉ để hoàn thành “physical separation”.

Nếu migrate sau này:
- làm riêng một phase
- có migration/backfill
- có cross-DB compatibility window
- có rollback
- retest toàn bộ Article / Content Project / AI History / Routing
