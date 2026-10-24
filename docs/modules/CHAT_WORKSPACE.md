# Chat Workspace

> Status: Canonical  
> Owner: Content (`ChatWorkspacePage`) + Seo (group chat API)  
> Last verified: 2026-10-24

## COMMUNICATION WORKSPACE RULE

1. Chat Workspace entry is `/seo/{connection_hash}/chat` for **Group Chat**. **Agent tab is isolated** (legacy Agent Workspace is reference-only — see `AGENT_WORKSPACE.md`).
2. Floating global Chat/Agent launcher (`.seo-global-chat__launcher` / `chat-mode-launcher`) **đã hidden/retired khỏi normal/global pages.**
3. `/seo/{connection_hash}/chat` vẫn có thể mount launcher nội bộ để switch Group | Agent (Agent alerts unavailable).
4. Live page: `Omnichannel\Addons\Content\Filament\Pages\ChatWorkspacePage`. Legacy Agent Filament page remains on disk under `addons/agent` but is **not discovered**.
5. Group Chat runtime là **JS-owned**. Laravel chỉ JSON API + auth + persistence.
6. Ngoài Chat page: unread badge config có thể còn (sidebar Chat nav). Không mount floating launcher.
7. Group messages không poll nhanh hơn **15 giây** mặc định.
8. Poller phải **single-inflight**.
9. **Support Ticket local Laravel đã retired** (2026-10-24). Không còn `support_tickets` table, header composer, Admin resource, hay `/api/support-tickets`. Future support = OPS-SERVER hosted (see ops-server `docs/modules/SUPPORT_TICKETS_FUTURE.md`).
10. Không tạo Telegram/Zalo/social messaging adapter trong module này.
11. Không generic hóa Agent/Group thành universal messaging framework.
12. Không tạo popup/page Chat song song kiểu `ChatModeV2`.

## Routes (Chat)

| Entry | Path | Notes |
|-------|------|-------|
| Chat Workspace | `/seo/{connection_hash}/chat` | Group Chat; `?tab=agent` shows reference-only notice |
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
