# Topic grouping boundary

Current source is the authority. This file describes the grouping cut that exists after the provider boundary task. It does not describe a semantic service, because that service is not implemented.

## Ownership

Laravel Topic business code owns:

- Keyword and SiteKeyword business state
- Topic rows and Topic membership
- manual ownership (`seo_topics.source=manual`)
- Topic locks and membership locks
- manual move, split, and rename
- Focus Article relations
- permissions
- review and apply (not built in this cut)
- persistence
- DNA rebuild and workspace metric cache

The grouping provider owns analysis only. A proposal is not a Topic, not a membership row, and not an instruction to write the database.

`TopicReclusterService` still applies seed identity, discovered-topic identity, manual freeze, locks, persistence, DNA, and cache after it receives a proposal.

`TopicMembershipReconcileService` still applies lock, seed, move, and attach rules after it receives a scan proposal. Manual create persists the Topic first, then calls that reconcile orchestrator.

## Current flow

```
Keyword candidates (TopicSiteKeywordService::loadTopicCandidateKeywords)
+ seeds (TopicSeedResolver)
+ locked Topics / locked keywords / manual inventory (TopicReclusterService)
        ↓
TopicReclusterService::recluster
        ↓
TopicGroupingProvider::analyze          scope = site_recluster
        ↓
TopicGroupingProposal
        ↓
TopicGroupingProposalMapper::toReclusterClusters
        ↓
TopicSeedIdentityResolver
        ↓
TopicDiscoveredIdentityResolver
        ↓
persistClusters (membership, dissolve, DNA)
        ↓
KeywordWorkspaceMetricCache
```

`ReclusterSiteTopicsJob` still calls `TopicReclusterService::recluster`. No analysis job table, polling, or webhook was added.

## Automatic membership side path

This path is explicit. It is not a second hidden matcher call.

```
TopicManualCreateService::create
        ↓
persist / reuse manual Topic
        ↓
TopicMembershipReconcileService::reconcile
        ↓
TopicGroupingProvider::analyze          scope = topic_membership_scan
        ↓
Laravel applies lock / seed / move / attach, then DNA and cache
```

The same reconcile orchestrator is used by Topic detail rescan and by content-project `GeneratedTopicMaterializer`. Those callers do not talk to the lexical matcher themselves.

`topic_membership_scan` is direct lexical match against one Topic label. It does not run site discovery or core-fallback attach. That is current product behavior, kept on purpose.

## Current provider

Binding, in `SearchIntelligenceServiceProvider::register` (config-selected):

```php
TopicGroupingProvider::class
  → LegacyTopicGroupingProvider           when TOPIC_GROUPING_PROVIDER=legacy (default)
  → SemanticHttpTopicGroupingProvider     when TOPIC_GROUPING_PROVIDER=semantic_http
```

`LegacyTopicGroupingProvider`:

- `site_recluster` → `TopicClusterEngine::cluster`
- `topic_membership_scan` → `TopicMembershipMatcher::matches`

`SemanticHttpTopicGroupingProvider`:

- `site_recluster` → HTTP `POST /v1/topic/analyses` (proposal only)
- `topic_membership_scan` → delegates to legacy lexical matcher

See [`SEMANTIC_ANALYTICS_INTEGRATION.md`](./SEMANTIC_ANALYTICS_INTEGRATION.md).

`TopicClusterEngine` remains the working lexical grouper. It is no longer a constructor dependency of `TopicReclusterService`.

Protected Topic refs and locked keyword refs are input context so the legacy engine can keep excluding frozen members. They are correlation data. The provider does not write them. Persist still enforces manual freeze and locks even if a later provider suggests something else.

`language` on the input is null today. Site recluster is not language-split.

`TopicGroupingProposal::$analysisRef` is null for the legacy provider. Semantic HTTP fills it with the remote `analysis_id`. Proposal runs persist in `seo_topic_grouping_runs` (analyze-only; Apply is Prompt 5).

## Semantic analyze vs legacy apply

When `TOPIC_GROUPING_PROVIDER=semantic_http`, `ReclusterSiteTopicsJob` / sync UI call `TopicGroupingAnalysisService::analyzeSite` and **stop** at `proposal_ready`. They do **not** call `TopicReclusterService::persistClusters`.

Legacy mode still analyzes then applies via `TopicReclusterService::recluster`.

## Manual operations

These do not call `TopicGroupingProvider` or `TopicClusterEngine`:

- `TopicManualCreateService` persistence itself (only the follow-up reconcile analyzes)
- `TopicRenameService`
- `TopicMoveKeywordService`
- `TopicSplitService`
- `TopicDissolveService`
- `TopicManualOwnership`

Manual move still promotes auto Topics to manual and locks that membership. That stays independent of analyzer availability.

## Legacy paths still active

| Path | Where | Why it is still here |
| --- | --- | --- |
| Lexical site clustering | `LegacyTopicGroupingProvider` → `TopicClusterEngine` | Current recluster behavior. Removal would change grouping. |
| Lexical one-topic scan | `LegacyTopicGroupingProvider` → `TopicMembershipMatcher` | Manual create and rescan attach by direct phrase match. The matcher is no longer called from reconcile. |
| Business reconcile | `TopicMembershipReconcileService` | Lock, seed, move, attach, DNA, cache. This is Laravel, not the provider. |

## Invariant

Grouping proposal ≠ Topic.

Replacing `TopicGroupingProvider` is the insertion point for a later semantic analytics provider. It does not require another Topic-core refactor of identity, locks, manual ownership, or persistence.
