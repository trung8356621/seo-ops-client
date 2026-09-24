#!/usr/bin/env python3
"""Generate docs/i18n-audit.md from i18n-audit-raw.json."""
from __future__ import annotations

import json
from collections import Counter, defaultdict
from pathlib import Path

RAW = Path(r"d:\work\omnichannel-client\storage\app\i18n-audit-raw.json")
OUT = Path(r"d:\work\omnichannel-client\docs\i18n-audit.md")

data = json.loads(RAW.read_text(encoding="utf-8"))
s = data["summary"]
parity = data["parity"]
findings = data["findings"]

def esc(t: str) -> str:
    return t.replace("|", "\\|").replace("\n", " ")

# Critical + nav/model props table
nav_syms = {
    "$modelLabel",
    "$pluralModelLabel",
    "$navigationLabel",
    "$navigationGroup",
    "NavigationGroup::make",
    "const",
}
nav_rows = [
    f
    for f in findings
    if f["symbol"] in nav_syms
    and f["category"] in {"HARDCODED_VI", "HARDCODED_EN"}
    and not f["current"].startswith("rgba(")
]

# Module table
mc: dict[str, Counter] = defaultdict(Counter)
for f in findings:
    mc[f["module"]][f["category"]] += 1

# Representative findings per module (max 12 High/Critical each)
by_mod_samples: dict[str, list] = defaultdict(list)
prio = {"Critical": 0, "High": 1, "Medium": 2, "Low": 3}
for f in sorted(findings, key=lambda x: (prio.get(x["severity"], 9), x["file"], x["line"])):
    if f["severity"] in {"Critical", "High"} and len(by_mod_samples[f["module"]]) < 12:
        # prefer nav / fluent / translated-vi / missing
        if f["symbol"] in nav_syms or f["category"] in {
            "HARDCODED_VI",
            "MIXED_COMPOSITION",
            "MISSING_TRANSLATION_KEY",
            "TRANSLATED_LITERAL",
        }:
            by_mod_samples[f["module"]].append(f)

lines: list[str] = []
A = lines.append

A("# Localization Audit")
A("")
A("Generated from current local source (omnichannel-client + omnichannel-addons).")
A("Scanner: `tools/i18n-audit-scan.py`. Guardrail: `tests/Unit/I18n/TranslationParityTest.php`.")
A("Scope: user-visible Filament/Livewire/Blade/JSX UI strings. Excludes vendor, node_modules,")
A("client `addons/` junction duplex, docs, help-seed, tests, prompts-as-content, DB user data.")
A("")
A("**This pass is AUDIT + guardrail only — no mass translation rewrite.**")
A("")
A("## Architecture")
A("")
A("### Locale resolver / switcher")
A("- Package: `bezhansalleh/filament-language-switch` (^3.1)")
A("- Configured in `app/Providers/AppServiceProvider.php` via `LanguageSwitch::configureUsing`")
A("- Supported locales: **`vi`, `en`** (switcher labels: Tiếng Việt / English)")
A("- No custom `SetLocale` middleware in app code — Filament Language Switch persists locale")
A("  (session/cookie) and Laravel/`App::getLocale()` follows")
A("")
A("### Defaults / fallback")
A("- `config/app.php`: `locale` = `env('APP_LOCALE', 'en')`")
A("- `fallback_locale` = `env('APP_FALLBACK_LOCALE', 'en')`")
A("- **Implication:** missing VI keys fall back to EN (good). Missing EN keys that only exist as")
A("  Vietnamese hardcoded literals never translate when locale=EN (bad — primary EN leakage).")
A("")
A("### Translation locations (SSOT)")
A("")
A("| Layer | Location | Namespace / style | Role |")
A("|---|---|---|---|")
A("| Client JSON | `lang/en.json`, `lang/vi.json` | English phrase as key (`__('Display name')`) | Thin admin shell labels |")
A("| Client PHP | `lang/{en,vi}/*.php` | Dot keys (`client_control.*`, `seo.*`, …) | Control plane + SEO rules |")
A("| SEO panel | `seo-content-ai-compat/lang/{en,vi}/filament.php` (+ `common.php`, `prompt_hooks.php`) | `seo-content-ai::filament.*` | **Primary product UI SSOT** (~3.6k–3.8k keys) |")
A("| Seeding | `seeding/resources/lang/{en,vi}/filament.php` | `seeding::filament.*` | Seeding UI |")
A("| Filament vendor | `vendor/filament/*/resources/lang` | `filament-panels::*` etc. | Framework chrome (EN/VI via Filament) |")
A("")
A("Registered via:")
A("- `SeoPanelProvider` / `AiPromptServiceProvider`: `loadTranslationsFrom(..., 'seo-content-ai')`")
A("- `SeedingServiceProvider`: `loadTranslationsFrom(..., 'seeding')`")
A("")
A("### Filament localization")
A("- Admin panel (`AdminPanelProvider`): hardcoded navigation groups `Quản lý`, `Hệ thống`, `Automation`")
A("- SEO panel: `SeoUserNavigation::GROUP_SYSTEM = 'Hệ thống'` (**hardcoded VI constant**, shared)")
A("- Module nav labels often correctly use `__('seo-content-ai::filament.nav.*')` via")
A("  `getNavigationLabel()` / `SeoUserNavigation::module*()` — **but many Resources still keep dead**")
A("  static `$navigationLabel` / `$modelLabel` English strings that are overridden at runtime")
A("- Admin `UserResource` does **not** override `getModelLabel()` → static VI labels are live →")
A("  Filament EN action prefix + VI modelLabel ⇒ **「New Thành viên」** (`FRAMEWORK_CUSTOM_MIX`)")
A("")
A("### Blade / Livewire")
A("- Prefer `__()` / `@lang` / `seo-content-ai::…` in views")
A("- Large volume of **hardcoded Vietnamese** remains in `seo-content-ai-compat` Blade views")
A("")
A("### Frontend JS/TS")
A("- No project-wide `react-i18next` / ICU message catalog detected")
A("- React widgets largely receive labels from Blade/Livewire props or hardcode EN/VI strings")
A("")
A("### Custom helpers")
A("- No dedicated translation service beyond Laravel `__()` / Filament + BezhanSalleh switcher")
A("- `SeoEngineService::scoringMessagesForLocale()` — scoring copy locale helper (domain, not UI chrome)")
A("")
A("## Summary")
A("")
A(f"- **Total high-signal findings:** {s['total_findings']}")
A(f"- **By severity:** {json.dumps(s['by_severity'], ensure_ascii=False)}")
A(f"- **By category:** {json.dumps(s['by_category'], ensure_ascii=False)}")
A("")
A("| Category | Count | Notes |")
A("|---|---:|---|")
A(f"| HARDCODED_VI | {s['by_category'].get('HARDCODED_VI', 0)} | Vietnamese literals in UI source |")
A(f"| HARDCODED_EN | {s['by_category'].get('HARDCODED_EN', 0)} | English literals (includes dead static props overridden by get*Label) |")
A(f"| MISSING_TRANSLATION_KEY | {s['by_category'].get('MISSING_TRANSLATION_KEY', 0)} | `__('English…')` with no `lang/*.json` entry → shows EN raw / no VI |")
A(f"| TRANSLATED_LITERAL | {s['by_category'].get('TRANSLATED_LITERAL', 0)} | `__('…')` using phrase/VI as key (JSON style or bad VI keys) |")
A(f"| MIXED_COMPOSITION | {s['by_category'].get('MIXED_COMPOSITION', 0)} | Concat of translated + hardcoded fragments |")
A("")
A("### Counts by module")
A("")
A("| Module | Total | HARDCODED_VI | HARDCODED_EN | MISSING | TRANSLATED_LITERAL | MIXED |")
A("|---|---:|---:|---:|---:|---:|---:|")
for mod, c in sorted(mc.items(), key=lambda x: -sum(x[1].values())):
    A(
        f"| {mod} | {sum(c.values())} | {c.get('HARDCODED_VI', 0)} | {c.get('HARDCODED_EN', 0)} | "
        f"{c.get('MISSING_TRANSLATION_KEY', 0)} | {c.get('TRANSLATED_LITERAL', 0)} | {c.get('MIXED_COMPOSITION', 0)} |"
    )
A("")
A("## Critical Global Problems")
A("")
A("1. **Admin navigation groups hardcoded in Vietnamese**")
A("   - `AdminPanelProvider`: `Quản lý`, `Hệ thống`")
A("   - Multiple Resources/Pages: `$navigationGroup = 'Quản lý'|'Hệ thống'`")
A("   - Affects every Admin screen when locale=EN")
A("")
A("2. **`SeoUserNavigation::GROUP_SYSTEM = 'Hệ thống'`**")
A("   - Shared constant; SEO panel “System” group never localizes")
A("   - Comment in file even documents Vietnamese-only intent for this group")
A("")
A("3. **FRAMEWORK_CUSTOM_MIX on Admin Users**")
A("   - `UserResource` `$modelLabel = 'Thành viên'` without `getModelLabel()` override")
A("   - Filament generates `New {modelLabel}` → **「New Thành viên」** in EN")
A("   - Same class mixes `__('Account')` JSON keys with `__('Lưu')` / `__('Huỷ')` / `__('Đã copy email')`")
A("")
A("4. **Dual translation conventions without complete coverage**")
A("   - Shell: English-as-key JSON (`lang/*.json`) — only **16** keys; many `__('…')` calls missing")
A("   - Product: semantic keys under `seo-content-ai::filament.*` — large but **EN/VI parity drift**")
A("     (52 EN-only, 194 VI-only keys in `filament.php`)")
A("")
A("5. **Dead static EN labels vs live `get*Label()` overrides (addons)**")
A("   - e.g. `ArticleResource` static `$navigationLabel = 'Articles'` but")
A("     `getNavigationLabel()` → `__('seo-content-ai::filament.nav.articles')`")
A("   - Runtime often OK; static props still confuse audits and can leak if override removed")
A("")
A("6. **Mass HARDCODED_VI in Blade (seo-content-ai-compat)**")
A("   - Domain overview / internal links / MCP panels still embed Vietnamese copy in templates")
A("")
A("## Findings by module")
A("")
A("Representative High/Critical items (full machine list: `storage/app/i18n-audit-raw.json`).")
A("Each row: severity · category · file · symbol · current · remediation.")
A("")

for mod in sorted(by_mod_samples.keys(), key=lambda m: -len(by_mod_samples[m])):
    rows = by_mod_samples[mod]
    if not rows:
        continue
    A(f"### `{mod}` ({sum(mc[mod].values())} findings)")
    A("")
    A("| Severity | Category | File | Symbol | Current | Fix |")
    A("|---|---|---|---|---|---|")
    for f in rows:
        why = {
            "HARDCODED_VI": "Hardcoded VI; bypasses locale",
            "HARDCODED_EN": "Hardcoded EN; no VI path",
            "MISSING_TRANSLATION_KEY": "Key/phrase missing from lang files",
            "TRANSLATED_LITERAL": "Literal used as translation key",
            "MIXED_COMPOSITION": "Do not concat fragments; one semantic phrase",
        }.get(f["category"], "")
        fix = {
            "HARDCODED_VI": "Move to `__('…')` / `seo-content-ai::…` + both locales",
            "HARDCODED_EN": "Replace with namespaced key; add EN+VI",
            "MISSING_TRANSLATION_KEY": "Add en.json + vi.json (or PHP lang) entries",
            "TRANSLATED_LITERAL": "Use stable semantic key; keep VI only in lang files",
            "MIXED_COMPOSITION": "Single translatable sentence/phrase",
        }.get(f["category"], "Localize via SSOT namespace")
        if f["symbol"] in {"$modelLabel", "$pluralModelLabel"} and f["category"] == "HARDCODED_VI":
            fix = "Override getModelLabel()/getPluralModelLabel() with keys; remove static VI (fixes New/Edit mix)"
        if f["symbol"] in nav_syms and "Hệ thống" in f["current"] or f["current"] in {"Quản lý", "Hệ thống", "Cài đặt", "Thành viên", "Thống kê"}:
            fix = "Centralize nav group/label keys; wire AdminPanelProvider + SeoUserNavigation"
        path = f"{f['repo']}/{f['file']}:{f['line']}"
        A(
            f"| {f['severity']} | {f['category']} | `{path}` | `{esc(f['symbol'])}` | "
            f"{esc(f['current'][:80])} | {why}. {fix} |"
        )
    A("")

A("### Navigation / modelLabel inventory (all)")
A("")
A("| Severity | Category | Module | File | Symbol | Current |")
A("|---|---|---|---|---|---|")
for f in sorted(nav_rows, key=lambda x: (prio.get(x["severity"], 9), x["file"], x["line"])):
    A(
        f"| {f['severity']} | {f['category']} | {f['module']} | "
        f"`{f['repo']}/{f['file']}:{f['line']}` | `{esc(f['symbol'])}` | {esc(f['current'])} |"
    )
A("")

A("## Translation parity")
A("")
A("| File | EN keys | VI keys | EN-only | VI-only | Suspicious identical EN=VI |")
A("|---|---:|---:|---:|---:|---:|")
for label, p in parity.items():
    A(
        f"| `{label}` | {p['en_key_count']} | {p['vi_key_count']} | "
        f"{len(p['en_only'])} | {len(p['vi_only'])} | {p.get('suspicious_identical_count', 0)} |"
    )
A("")

# Detail for mismatched files
for label, p in parity.items():
    if not (p["en_only"] or p["vi_only"] or not p["en_file_exists"] or not p["vi_file_exists"]):
        continue
    A(f"### `{label}`")
    A("")
    if not p["vi_file_exists"]:
        A("- **Missing VI file** (Laravel will fall back to EN / vendor).")
    if not p["en_file_exists"]:
        A("- **Missing EN file**.")
    if p["en_only"]:
        A(f"- EN-only ({len(p['en_only'])}): " + ", ".join(f"`{k}`" for k in p["en_only"][:40]))
        if len(p["en_only"]) > 40:
            A(f"  - … +{len(p['en_only']) - 40} more")
    if p["vi_only"]:
        A(f"- VI-only ({len(p['vi_only'])}): " + ", ".join(f"`{k}`" for k in p["vi_only"][:40]))
        if len(p["vi_only"]) > 40:
            A(f"  - … +{len(p['vi_only']) - 40} more")
    A("")

A("### Notable parity notes")
A("- `lang/vi/auth.php`, `pagination.php`, `passwords.php`: **missing** (EN-only Laravel defaults)")
A("- `lang/vi/validation.php`: nearly empty vs full EN validation catalog")
A("- `lang/*.json`: key set parity OK (16/16) but coverage of shell UI is tiny")
A("- `seo-content-ai-compat/.../filament.php`: largest drift — prioritize EN-only keys that are")
A("  referenced at runtime; VI-only may be obsolete or ahead of EN")
A("- `client_control.php`: many intentional identical product terms (Control Server, status enums)")
A("")
A("### Dead keys")
A("- Not auto-deleted. VI-only keys in `filament.php` (194) are **candidates** for dead-key review")
A("  in a later batch (cross-check `__('seo-content-ai::…')` usage).")
A("")
A("## Recommended repair batches")
A("")
A("Do **not** mass-rewrite in one PR. Suggested order by leverage:")
A("")
A("1. **Localization foundation / global navigation**")
A("   - Translate Admin `NavigationGroup::make` + shared `SeoUserNavigation::GROUP_SYSTEM`")
A("   - Introduce stable keys e.g. `nav.group.management`, `nav.group.system` in client + SEO SSOT")
A("   - Align Admin vs SEO panel group naming")
A("")
A("2. **Admin / users / settings / sites / services**")
A("   - `UserResource` model/nav labels + Tab `Tài khoản` + `__('Lưu')`/`__('Huỷ')`")
A("   - Expand `lang/en.json` + `lang/vi.json` **or** migrate shell to PHP namespaces")
A("   - Fix Site/SiteService missing JSON keys")
A("")
A("3. **Content project / articles**")
A("   - Remove dead static EN labels or make them call the same keys as `get*Label()`")
A("   - Blade leftovers in article-related compat views")
A("")
A("4. **Keywords / topic / SEO audit / domains**")
A("   - Domain Blade HARDCODED_VI clusters")
A("   - `Statistics` nav `Thống kê`")
A("")
A("5. **AI / history / prompts**")
A("   - `ai-prompt` Filament resources + AI Center strings")
A("   - filament.php EN/VI parity for prompt/AI sections")
A("")
A("6. **Seeding**")
A("   - Prefer `seeding::filament.*`; purge remaining hardcoded UI")
A("")
A("7. **Automation / agent**")
A("   - Agent Filament pages/resources EN hardcodes")
A("")
A("8. **Remaining modules** (media, wordpress, publishing, commerce) + React widget strings")
A("")
A("9. **Framework validation/auth lang files** — copy/publish Laravel `vi` lang packs")
A("")
A("## Guardrail")
A("")
A("- Scanner: `php`/`python` `tools/i18n-audit-scan.py`")
A("- Allowlist: `tools/i18n-allowlist.json`")
A("- PHPUnit: `tests/Unit/I18n/TranslationParityTest.php`")
A("  - EN/VI key parity for known lang trees")
A("  - Obvious hardcoded Vietnamese in Filament static nav/model props")
A("")
A("## Highest-leverage fixes (start here)")
A("")
A("1. `UserResource` `$navigationLabel` / `$modelLabel` / `$pluralModelLabel` / `$navigationGroup`")
A("2. `AdminPanelProvider` navigation groups")
A("3. `SeoUserNavigation::GROUP_SYSTEM`")
A("4. Add missing `lang/vi/{auth,pagination,passwords,validation}.php` (or publish Laravel vi)")
A("5. Close `lang/*.json` gaps for Site/SiteService/User actions (`Lưu`, `Huỷ`, …)")
A("6. Reconcile `seo-content-ai-compat` `filament.php` EN-only (52) keys")
A("")
A("---")
A(f"_Raw finding count {s['total_findings']}. Scanner does not claim zero false positives on Blade;")
A("nav/modelLabel Critical list was manually cross-checked against source._")
A("")

OUT.write_text("\n".join(lines), encoding="utf-8")
print(f"Wrote {OUT} ({len(lines)} lines)")
