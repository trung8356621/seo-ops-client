# Article Editor — Domain Link List

> Status: Canonical (editor Links panel)  
> Last verified: 2026-08-26  
> Owner: `omnichannel-addons/content` (UI/matcher) + `omnichannel-addons/seo` (catalog SoT)  
> Related: [`ARTICLE_EDITOR.md`](../modules/ARTICLE_EDITOR.md), [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md), [`SITE_MCP_AND_DOMAINS.md`](../modules/SITE_MCP_AND_DOMAINS.md)

## Purpose

**Domain link list** in Article Editor → Links is:

> Domain link **inventory** + **soft lexical locator** + **manual insert helper**

Not AI / embedding / semantic search. Not Internal Links suggestions.

## Catalog source (Laravel)

Effective list from `EffectiveDomainLinkResolver` / `DomainLinkListEditorService`:

| Order | Source | Notes |
|-------|--------|--------|
| 1 | `custom` | Prompt/domain manual links |
| 2 | `product_cat` | Synced product category URLs |
| 3 | `main_domain` | Optional main domain row |

Dedupe: custom wins over `product_cat` for same normalized anchor/URL.  
Payload: `domain_link_list_catalog` (full site catalog) + optional `domain_link_list` (legacy exact-for-article filter on server — **UI must not treat server exact filter as live occurrence SoT**).

## Client runtime (React)

Isolated from Internal Links. Do **not** change `filterDomainLinksInArticleContent` / Internal matcher for Domain Link needs.

| Module | Role |
|--------|------|
| `domainLinkSourceResolver.js` | Order custom → product_cat; dedupe |
| `domainLinkTextNormalizer.js` | NFC, lowercase, punct/whitespace; accent-fold fallback only |
| `domainLinkMatcher.js` | Soft lexical match + scores + occurrences |
| `domainLinkOccurrenceIndex.js` | Scan blocks → list rows with `occurrence_count` |
| `domainLinkNavigator.js` | Locate via `scrollToLink` (expand collapsed section) |
| `domainLinkInsertAction.js` | Selection → `create_link`; else wrap matched text |

UI: `ArticleLinksSidebar.jsx` (primary), `ArticleDomainWidgetsSidebar.jsx` (compat).

### Matching levels

1. **Exact** normalized phrase  
2. **Contiguous** meaningful tokens  
3. **Proximity** within ~8–12 token window  
4. **Accent-insensitive** fallback (lower score)

Rules (conservative):

- 1-token: exact token boundary only  
- 2-token: both tokens, tight span  
- 3-token: all three tokens required  
- 4+: ≥ ~65% meaningful tokens in window  

### Count `(n)`

`(n)` = **number of candidate occurrences in the current article document** (soft/exact match regions).

**Not:**

- `article_count` (other articles using the link)  
- product/category catalog counts  
- raw unrelated word hits  

### Visibility

- Rows with **`occurrence_count === 0` are hidden**.  
- Already-linked anchors/URLs still filtered out (same as suggestions).  
- Empty copy: no matching domain keywords in this article (catalog may still exist).

### Locate / insert

```text
Click Domain Link row
  → cycle occurrence index
  → scrollToLink({ text, blockId, searchPlainText: true })
  → expand target section, highlight/select matched region (transient; not saved to HTML)

Insert icon
  → if editor has text selection → create_link(URL) on selection (keep user text)
  → else wrap last located matchedText (or caret insert fallback)
```

Re-index: on `seo-editor-links-updated` with `editor_blocks` from document (debounce ~400ms for `client-document`). Prefer full `blocksRef` snapshot over DOM-only scan (collapsed sections unmount TipTap slots).

## Regression boundary

| Must not change | Path |
|-----------------|------|
| Internal Links matcher / count / click / insert | `articleLinkSuggestionFilter` exact phrase + Links Internal section |
| CTA replacement / phone-address | CTA modules |
| Article save / serialization | persist pipeline |

`scrollToExtractedLink` may accept optional `blockId` / `localIndex` for Domain Link; Internal Links callers omit them → behavior unchanged.

## Tests

```text
node --test addons/content/resources/js/__tests__/domainLinkMatcher.test.mjs
```

Covers exact/soft/proximity/far/two-token/accent/count/cycle/inventory/edit + Internal exact-filter regression.

PHP contract (catalog wiring only): `DomainLinkListEditorServiceContractTest`.

## Manual smoke

Links → Domain link list:

1. Only rows with matches; custom before product_cat; no silly duplicates.  
2. `(n)` matches in-article candidates; click cycles and jumps.  
3. Edit anchor in editor, then insert URL on selection.  
4. Edit body → counts refresh after debounce.  
5. Internal Links + CTA unchanged; save does not persist locate `<mark>`.
