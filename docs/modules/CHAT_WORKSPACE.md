# Chat Workspace

> Status: Canonical  
> Owner: Agent (shell) + Content/Seo (group chat API) + Seo (support tickets)  
> Last verified: 2026-08-11

## COMMUNICATION WORKSPACE RULE

1. Có đúng **ONE** communication UI entry point: `/seo/{connection_hash}/chat`
2. Workspace có đúng **3** modes: **Agent** | **Group Chat** | **Support Ticket**
3. Mode switch qua **round chat launcher** (reuse `.seo-global-chat__launcher`) — **không** horizontal tabs trên page.
4. Agent không có sidebar/page implementation thứ hai. Legacy `/seo/{hash}/agent` và `/admin/agent` chỉ là redirect compatibility → `chat?tab=agent` (preserve `project_ref` và deep-link query).
5. Group Chat runtime là **JS-owned**. Laravel chỉ JSON API + auth + persistence. Không dùng Livewire polling/rendering cho group chat.
6. Ngoài Chat page: round launcher nhẹ + unread badge (`GET /api/seo/team/unread-count`). Không mount floating `global-ai-chat` panel.
7. Group messages không poll nhanh hơn **15 giây** mặc định nếu không có requirement mới được document.
8. Poller phải **single-inflight** (request xong mới schedule tiếp). Không `setInterval` overlap.
9. Support Ticket phải **offline-safe**. Local MySQL là source cho pending ticket cho đến khi remote delivery thành công. Attach/paste reuse `TeamChatAttachmentService`.
10. Không tạo Telegram/Zalo/social messaging adapter trong module này.
11. Không generic hóa Agent/Group/Ticket thành universal messaging framework nếu chưa có requirement thực tế.
12. Bất kỳ UI/chat implementation mới nào phải reuse Chat Workspace — không tạo popup/page/component song song (`ChatModeV2`, v.v.).

## Routes

| Entry | Path | Notes |
|-------|------|-------|
| Canonical | `/seo/{connection_hash}/chat` | `AgentWorkspacePage` slug `chat`; modes via `?tab=agent\|group\|ticket` |
| Default tab | `agent` | Round launcher always available to switch |
| Legacy Agent | `/seo/{connection_hash}/agent` | `AgentWorkspaceLegacyRedirect` → chat?tab=agent |
| Admin alias | `/admin/agent` | `AgentWorkspaceRedirect` → Chat Agent tab |
| Deep link | `AgentWorkspaceDeepLink::tryUrl([...])` | Always appends `tab=agent` |

## Shell UI

| Piece | Location |
|-------|----------|
| Mode content | `seo-content-ai::filament.pages.chat-workspace` — render **one** mode only |
| Round launcher | `seo-content-ai::components.chat-mode-launcher` (CSS class `.seo-global-chat__launcher`) |
| Outside Chat | `chat-unread-badge` mounts launcher when not on `filament.seo.pages.chat` |

All domains: Agent vẫn gated bởi `site_ref`; Group/Ticket không bị khóa chỉ vì All domains.

## APIs

| Method | Path | Role |
|--------|------|------|
| GET | `/api/seo/team/messages?poll=1&after_id=` | History (after_id=0 latest 50) or delta |
| POST | `/api/seo/team/messages` | Send group message |
| GET | `/api/seo/team/unread-count` | `{ unread }` via read cursor |
| POST | `/api/seo/team/mark-read` | Upsert `team_chat_read_cursors` |
| GET/POST | `/api/seo/support-tickets` | Local-first tickets (+ multipart `files[]`) |
| POST | `/api/seo/support-tickets/{id}/retry` | Manual remote retry |

## Poll intervals

| Context | Interval |
|---------|----------|
| Chat → Group tab active | 15s (single-inflight) |
| Other pages unread badge | 30s |
| Browser hidden | stop / 60s max; refresh once on visible |

## DB (core mysql)

- `team_messages` (reuse)
- `team_chat_read_cursors`
- `support_tickets` (`queued` \| `sent` \| `failed`; attachments in `metadata.attachments`)

## Frontend modules

- `addons/content/resources/js/chat/groupChatApp.js`
- `addons/content/resources/js/chat/ticketPanel.js`
- `addons/content/resources/js/chat/unreadBadge.js`

Agent UI/runtime: vẫn `AgentWorkspacePage` + `agent-workspace` partial — không duplicate Agent component.

## Related

- Agent runtime/capabilities: [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- Floating `global-ai-chat` panel **retired** (launcher CSS/class reused by `chat-mode-launcher`).
