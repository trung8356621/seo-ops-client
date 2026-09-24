#!/usr/bin/env python3
"""Summarize i18n-audit-raw.json for report authoring."""
from __future__ import annotations

import json
from collections import Counter, defaultdict
from pathlib import Path

data = json.loads(Path(r"d:\work\omnichannel-client\storage\app\i18n-audit-raw.json").read_text(encoding="utf-8"))
out = Path(r"d:\work\omnichannel-client\storage\app\i18n-audit-summary.txt")
lines: list[str] = []

lines.append("=== CRITICAL ===")
crit = [f for f in data["findings"] if f["severity"] == "Critical"]
for f in sorted(crit, key=lambda x: (x["file"], x["line"])):
    lines.append(
        f"{f['repo']}/{f['file']}:{f['line']} [{f['category']}] {f['symbol']} = {f['current']!r}"
    )
lines.append(f"CRITICAL COUNT {len(crit)}")

lines.append("\n=== NAV/MODEL LABEL PROPS ===")
for f in data["findings"]:
    if f["symbol"] in {
        "$modelLabel",
        "$pluralModelLabel",
        "$navigationLabel",
        "$navigationGroup",
        "NavigationGroup::make",
        "const",
    }:
        lines.append(
            f"{f['severity']:8} {f['category']:22} {f['repo']}/{f['file']}:{f['line']} "
            f"{f['symbol']}={f['current']!r}"
        )

lines.append("\n=== PARITY ===")
for label, p in data["parity"].items():
    issues = []
    if p["en_only"]:
        issues.append(f"en_only={len(p['en_only'])}")
    if p["vi_only"]:
        issues.append(f"vi_only={len(p['vi_only'])}")
    if not p["en_file_exists"]:
        issues.append("missing_en_file")
    if not p["vi_file_exists"]:
        issues.append("missing_vi_file")
    if p.get("suspicious_identical_count"):
        issues.append(f"identical={p['suspicious_identical_count']}")
    if issues:
        lines.append(
            f"{label}: en={p['en_key_count']} vi={p['vi_key_count']} | " + ", ".join(issues)
        )
        if p["en_only"][:20]:
            lines.append("  en_only: " + ", ".join(p["en_only"][:20]))
        if p["vi_only"][:20]:
            lines.append("  vi_only: " + ", ".join(p["vi_only"][:20]))
        if p.get("suspicious_identical")[:15]:
            lines.append("  identical: " + ", ".join(p["suspicious_identical"][:15]))

lines.append("\n=== MODULE x CATEGORY ===")
mc: dict[str, Counter] = defaultdict(Counter)
for f in data["findings"]:
    mc[f["module"]][f["category"]] += 1
for mod, c in sorted(mc.items(), key=lambda x: -sum(x[1].values())):
    lines.append(f"{mod}: {dict(c)} total={sum(c.values())}")

lines.append("\n=== TOP HARDCODED_VI FILES ===")
fc = Counter(f["file"] for f in data["findings"] if f["category"] == "HARDCODED_VI")
for file, n in fc.most_common(30):
    lines.append(f"{n:4} {file}")

lines.append("\n=== TOP HARDCODED_EN FILES ===")
fc = Counter(f["file"] for f in data["findings"] if f["category"] == "HARDCODED_EN")
for file, n in fc.most_common(20):
    lines.append(f"{n:4} {file}")

lines.append("\n=== MIXED_COMPOSITION ===")
for f in data["findings"]:
    if f["category"] == "MIXED_COMPOSITION":
        lines.append(f"{f['repo']}/{f['file']}:{f['line']} {f['current']}")

lines.append("\n=== TRANSLATED_LITERAL VI only ===")
for f in data["findings"]:
    if f["category"] == "TRANSLATED_LITERAL" and any(
        ch in f["current"]
        for ch in "àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ"
    ):
        lines.append(f"{f['repo']}/{f['file']}:{f['line']} {f['current']!r}")

lines.append("\n=== MISSING_TRANSLATION_KEY sample (40) ===")
miss = [f for f in data["findings"] if f["category"] == "MISSING_TRANSLATION_KEY"]
for f in miss[:40]:
    lines.append(f"{f['repo']}/{f['file']}:{f['line']} {f['current']!r}")
lines.append(f"MISSING total {len(miss)}")

# High leverage unique strings for nav
lines.append("\n=== UNIQUE HARDCODED_VI NAV STRINGS ===")
nav_vi = sorted(
    {
        f["current"]
        for f in data["findings"]
        if f["category"] == "HARDCODED_VI"
        and f["symbol"]
        in {
            "$navigationGroup",
            "$navigationLabel",
            "$modelLabel",
            "$pluralModelLabel",
            "NavigationGroup::make",
            "const",
            "Tab/Section::make",
        }
    }
)
for s in nav_vi:
    lines.append(repr(s))

out.write_text("\n".join(lines), encoding="utf-8")
print(f"Wrote {out} ({len(lines)} lines)")
