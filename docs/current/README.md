# Current documentation (canonical for agents)

This directory is the **only** current canonical docs namespace for AI/code agents.

- **Current source code is final authority.**
- Docs outside `docs/current/` may be legacy or outdated. Do not treat them as architecture source of truth unless verified against current source.
- `docs/archive/` is reference/history only.
- If a document here conflicts with source, **source wins** — then update `docs/current/` to match.

Do not rewrite the rest of `docs/` from this tree. A dedicated documentation audit will archive/move old docs later.

## Index

- [architecture/DB_DIALECT_EXCEPTIONS.md](architecture/DB_DIALECT_EXCEPTIONS.md) — remaining active-runtime database-specific SQL after the pre-host portability pass.
- [architecture/TOPIC_GROUPING_BOUNDARY.md](architecture/TOPIC_GROUPING_BOUNDARY.md) — Topic grouping analysis contract versus Laravel Topic business ownership.
