# Chat Workspace

> Status: Canonical  
> Owner: Agent (shell) + Content/Seo (group chat API)  
> Last verified: 2026-09-24

## COMMUNICATION WORKSPACE RULE

1. Chat Workspace entry vẫn là `/seo/{connection_hash}/chat` cho **Agent** và **Group Chat** (runtime giữ nguyên; refactor sau).
2. **Support Ticket là GLOBAL client feature** — entry = shared Filament topbar (`ClientCoreServiceProvider`), không thuộc SEO panel ownership.
3. Floating global Chat/Agent launcher (`.seo-global-chat__launcher` / `chat-mode-launcher`) **đã hidden/retired khỏi normal/global pages.**
4. `/seo/{connection_hash}/chat` vẫn có thể mount launcher nội bộ để switch Agent | Group (compatibility). Ticket tab trên chat chỉ là legacy composer; canonical = header.
5. Agent không có sidebar/page implementation thứ hai. Legacy `/seo/{hash}/agent` và `/admin/agent` chỉ là redirect compatibility → `chat?tab=agent`.
6. Group Chat runtime là **JS-owned**. Laravel chỉ JSON API + auth + persistence.
7. Ngoài Chat page: unread badge config có thể còn (sidebar Chat nav). Không mount floating launcher.
8. Group messages không poll nhanh hơn **15 giây** mặc định.
9. Poller phải **single-inflight**.
10. Support Ticket SSOT = bảng core/client `support_tickets`. Canonical submit = `POST /api/support-tickets`. Success = DB row created. Không ops-server delivery trong phase này. Attach via client `SupportTicketAttachmentService`.
11. Không tạo Telegram/Zalo/social messaging adapter trong module này.
12. Không generic hóa Agent/Group/Ticket thành universal messaging framework.
13. Không tạo popup/page Chat song song kiểu `ChatModeV2`.

## Support Ticket (global)

| Piece | Location |
|-------|----------|
| Header hook | `ClientCoreServiceProvider::registerSupportTicketHeaderHook` (`USER_MENU_BEFORE`) |
| Blade | `resources/views/filament/hooks/support-ticket-header.blade.php` |
| JS/CSS | `resources/js/support-ticket/headerTicketComposer.js`, `resources/css/support-ticket-header.css` |
| API | `POST/GET /api/support-tickets` (`App\Http\Controllers\SupportTicketController`) |
| Admin read-only | `/admin/support-tickets` (`SupportTicketResource`) |
| SEO compat | `/api/seo/support-tickets` → same controller |

Panels: Admin, SEO, SEO-main, Seeding (same `ServiceTopbarRouter::PANEL_IDS`).

## Routes (Chat)

| Entry | Path | Notes |
|-------|------|-------|
| Chat Workspace | `/seo/{connection_hash}/chat` | Agent + Group; legacy `?tab=ticket` may still render old panel |
| Legacy Agent | `/seo/{connection_hash}/agent` | redirect → chat?tab=agent |

## APIs (Chat group)

| Method | Path | Role |
|--------|------|------|
| GET | `/api/seo/team/messages?poll=1&after_id=` | History / delta |
| POST | `/api/seo/team/messages` | Send group message |
| GET | `/api/seo/team/unread-count` | `{ unread }` |
| POST | `/api/seo/team/mark-read` | Upsert cursor |

## DB (core mysql — seo-ops-client)

- `team_messages`
- `team_chat_read_cursors`
- `support_tickets` (`queued` for this phase; attachments in `metadata.attachments`)

## Deferred

Client → ops-server SaaS support management / two-way reply — **out of scope**.
