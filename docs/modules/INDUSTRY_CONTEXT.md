# Industry Context

Industry Context answers: “What world does this business operate in?” It does not answer: “What should this specific site publish right now?”

```text
GLOBAL Industry Context
    ↓ reusable industry/business world

SITE Context
    ↓ actual Topics/Keywords/Articles/GSC/site facts

RUNTIME Context
    ↓ current date/current trend/user instruction
```

The global catalog uses `industry_context_profiles.key` as its stable future sync identity. Its local numeric ID is never a sync identity. Sites bind to a profile through the nullable `SiteMeta` key `seo_industry_context_key`.

The canonical contract is [`resources/schemas/industry-context.v1.schema.json`](../../resources/schemas/industry-context.v1.schema.json). The same schema documents the profile, guides structured generation, and validates accepted data. It excludes current time/trends and site-specific SEO state so profiles remain reusable.

Future content candidates must contain at least one valid link and must not store reasoning:

```json
{"context_links":[{"type":"audience|customer_need|offering|demand_driver|seasonality|content_universe","ref":"stable-id"}]}
```
