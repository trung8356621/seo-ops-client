# Keyword MCP Type 2 — Deferred after v1

## Status

Keyword MCP Type 2 v1 is **CLOSED**.

Capability: `keyword.relationship`  
Schema: `keyword.relationship.v1`

Canonical contract: [`docs/contracts/KEYWORD_MCP.md`](../docs/contracts/KEYWORD_MCP.md)

The items below are intentionally deferred and are **NOT** blockers for v1.

## Debt / Deferred

### 1. MySQL integration proof

Current state:

- Real MySQL tests exist (`KeywordRelationshipReadModelIntegrationTest` A–D)
- 5 tests currently **SKIPPED**
- Disposable MySQL test DB was not configured

Required later:

- Configure `SEO_TEST_USE_MYSQL`
- Configure a safe database matching `*_test`
- Execute the MySQL suite
- Record the actual proof result

Do **not** run against any non-test database.

### 2. GSC expansion

Current v1 GSC section remains intentionally limited (deterministic mapping types only).

Future work may expand GSC data only when a concrete consumer/use-case requires it.

### 3. Tags

Tags are not part of the current v1 relationship contract.

Define semantics before adding them.

### 4. Planning ID redesign

Current planning representation is accepted for v1.

ID redesign is deferred to avoid changing the closed contract without a real requirement.

### 5. Full `source_updated_at`

Current implementation does not provide full normalized `source_updated_at` coverage.

Define source semantics before expanding this field.

### 6. AI integration

No AI expansion is part of Keyword Relationship v1.

Any future AI consumer must be designed separately and must not silently change the MCP contract.

### 7. Additional relationship charts

Current visualization is sufficient to close v1.

Additional charts are deferred until actual UX requirements are defined.

## Reopen rule

Do **NOT** reopen Keyword MCP Type 2 v1 merely because one of these deferred items exists.

Reopen/change the contract only when:

- there is a concrete consumer requirement,
- schema impact is understood,
- tests/contracts are updated deliberately.
