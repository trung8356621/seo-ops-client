#!/usr/bin/env python3
"""Deterministic UI i18n scanner for SEO Ops (omnichannel-client + peer addons).

High-signal only: Filament/Livewire/Blade/JSX + panel providers.
Skips vendor/node_modules/addons-junction/docs/help-seed/tests.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import subprocess
from collections import defaultdict
from pathlib import Path
from typing import Any

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

VI_CHAR = re.compile(
    r"[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ"
    r"ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ]"
)

STATIC_PROP = re.compile(
    r"protected\s+static\s+\?string\s+\$(navigationGroup|navigationLabel|modelLabel|"
    r"pluralModelLabel|title|heading)\s*=\s*(['\"])(?P<val>.*?)\2",
    re.DOTALL,
)
NAV_GROUP_MAKE = re.compile(
    r"NavigationGroup::make\(\s*(['\"])(?P<val>.*?)\1\s*\)",
    re.DOTALL,
)
TAB_MAKE = re.compile(
    r"(?:Tabs\\Tab|Tab|Section|Fieldset|Wizard\\Step)::make\(\s*(['\"])(?P<val>.*?)\1",
    re.DOTALL,
)
CONST_STRING = re.compile(r"const\s+\w+\s*=\s*(['\"])(?P<val>.*?)\1\s*;")
FLUENT_UI = re.compile(
    r"->(?P<method>label|title|heading|description|helperText|placeholder|tooltip|"
    r"modalHeading|modalDescription|modalSubmitActionLabel|modalCancelActionLabel|"
    r"submitActionLabel|cancelActionLabel|emptyStateHeading|emptyStateDescription|"
    r"badge|hint|content|body|successNotificationTitle|failureNotificationTitle|"
    r"keyLabel|valueLabel|addActionLabel|navigationLabel|navigationGroup|"
    r"modelLabel|pluralModelLabel|copyMessage)\(\s*"
    r"(?:label:\s*)?(?P<arg>[^;\n]{0,400})",
    re.IGNORECASE,
)
TRANSLATED_CALL = re.compile(
    r"(?:__|trans|@lang)\(\s*(?P<quote>['\"])(?P<key>(?:\\.|(?!\1).)*?)\1"
)
MIXED_CONCAT = re.compile(
    r"(?:__|trans)\([^\n;]*?\)\s*\.\s*(?P<q1>['\"])(?P<after>(?:\\.|(?!\1).)+?)\1"
    r"|(?P<q2>['\"])(?P<before>(?:\\.|(?!\3).)+?)\3\s*\.\s*(?:__|trans)\("
)
STRING_LITERAL = re.compile(r"(['\"])(?P<val>(?:\\.|(?!\1).)*?)\1", re.DOTALL)

SKIP_DIR_NAMES = {
    "vendor", "node_modules", ".git", "storage", "bootstrap", "dist", "build",
    "coverage", ".idea", ".vscode", "public", "cache", "addons", "help-seed", "docs",
}

ALLOWLIST_DEFAULT = {
    "SEO", "WordPress", "OpenRouter", "Gemini", "DeepSeek", "MCP", "HTML",
    "Markdown", "FAQ", "API", "JSON", "CSV", "URL", "HTTP", "HTTPS",
    "PG Canary", "Automation", "Dashboard",
}

ADDON_ROOTS = {
    "content", "seo", "media", "ai-prompt", "search-intelligence", "search-foundation",
    "content-projects", "wordpress", "publishing", "site-sync", "agent", "social",
    "commerce", "seeding", "seo-content-ai-compat",
}

CODEISH = re.compile(r"^[a-z][a-z0-9_]*$")


def has_vi(s: str) -> bool:
    return bool(VI_CHAR.search(s))


def looks_english_ui(s: str) -> bool:
    s = s.strip()
    if not s or has_vi(s) or s in ALLOWLIST_DEFAULT:
        return False
    if CODEISH.match(s) or re.fullmatch(r"[\d\W_]+", s):
        return False
    if re.fullmatch(r"[a-z-]+\([^)]*\)", s, re.I):
        return False
    if " " in s and re.search(r"[A-Za-z]{2,}", s):
        return True
    if re.match(r"^[A-Z][a-z]+(?:\s+[A-Za-z]+)+$", s):
        return True
    if re.match(r"^[A-Z][a-zA-Z]{2,}$", s):
        return True
    return False


def classify_literal(val: str, via_trans: bool) -> str | None:
    val = val.strip()
    if not val or len(val) < 2:
        return None
    if via_trans:
        if has_vi(val):
            return "TRANSLATED_LITERAL"
        return None
    if has_vi(val):
        return "HARDCODED_VI"
    if looks_english_ui(val):
        return "HARDCODED_EN"
    return None


def extract_string_arg(arg: str) -> tuple[str | None, bool]:
    arg = arg.strip()
    m = re.match(r"(?:__|trans|@lang)\(\s*(['\"])(?P<key>.*?)\1", arg)
    if m:
        return m.group("key"), True
    m = re.match(r"(['\"])(?P<val>.*?)\1", arg, re.DOTALL)
    if m:
        return m.group("val"), False
    return None, False


def module_of(rel: str, root_name: str) -> str:
    parts = rel.replace("\\", "/").split("/")
    if parts and parts[0] in ADDON_ROOTS:
        return parts[0]
    if parts[0] == "app" and len(parts) > 2:
        return f"client/{parts[1]}"
    if parts[0] == "resources":
        return "client/resources"
    return root_name


def is_high_signal_path(path: Path) -> bool:
    parts = set(path.parts)
    if "Filament" in parts or "Livewire" in parts:
        return True
    if path.name.endswith(".blade.php"):
        return True
    if path.suffix in {".jsx", ".tsx"} and "resources" in parts:
        return True
    if path.name.endswith("PanelProvider.php") or path.name.endswith("ServiceProvider.php"):
        return True
    if path.name == "SeoUserNavigation.php":
        return True
    return False


def walk_ui_files(root: Path) -> list[Path]:
    out: list[Path] = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIR_NAMES and not d.startswith(".")]
        for fn in filenames:
            p = Path(dirpath) / fn
            if not (fn.endswith(".blade.php") or p.suffix in {".php", ".js", ".jsx", ".ts", ".tsx"}):
                continue
            if is_high_signal_path(p):
                out.append(p)
    return out


def finding(
    cat: str, rel: str, line: int, symbol: str, current: str, module: str, **extra: Any
) -> dict[str, Any]:
    row = {
        "category": cat,
        "file": rel,
        "line": line,
        "symbol": symbol,
        "current": current,
        "module": module,
    }
    row.update(extra)
    return row


def scan_file(path: Path, root: Path) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return findings

    rel = str(path.relative_to(root)).replace("\\", "/")
    if "/tests/" in f"/{rel}/" or rel.startswith("tests/"):
        return findings
    mod = module_of(rel, root.name)
    line_at = lambda pos: text[:pos].count("\n") + 1  # noqa: E731

    for m in STATIC_PROP.finditer(text):
        cat = classify_literal(m.group("val"), False)
        if cat:
            findings.append(finding(cat, rel, line_at(m.start()), f"${m.group(1)}", m.group("val"), mod))

    for m in NAV_GROUP_MAKE.finditer(text):
        cat = classify_literal(m.group("val"), False)
        if cat:
            findings.append(
                finding(cat, rel, line_at(m.start()), "NavigationGroup::make", m.group("val"), mod)
            )

    for m in TAB_MAKE.finditer(text):
        cat = classify_literal(m.group("val"), False)
        if cat:
            findings.append(
                finding(cat, rel, line_at(m.start()), "Tab/Section::make", m.group("val"), mod)
            )

    for m in CONST_STRING.finditer(text):
        val = m.group("val")
        cat = classify_literal(val, False)
        if cat:
            findings.append(finding(cat, rel, line_at(m.start()), "const", val, mod))

    for m in FLUENT_UI.finditer(text):
        lit, via = extract_string_arg(m.group("arg"))
        if lit is None:
            continue
        cat = classify_literal(lit, via)
        if cat:
            findings.append(
                finding(cat, rel, line_at(m.start()), f"->{m.group('method')}()", lit, mod)
            )

    for m in TRANSLATED_CALL.finditer(text):
        key = m.group("key")
        if has_vi(key):
            findings.append(
                finding("TRANSLATED_LITERAL", rel, line_at(m.start()), "__()", key, mod)
            )
        elif (
            looks_english_ui(key)
            and "::" not in key
            and not key.startswith("seo-")
            and (" " in key or (key[:1].isupper() and "." not in key))
        ):
            findings.append(
                finding(
                    "TRANSLATED_LITERAL",
                    rel,
                    line_at(m.start()),
                    "__()/json-key-style",
                    key,
                    mod,
                    note="English-as-key; verify lang JSON parity",
                )
            )

    for m in MIXED_CONCAT.finditer(text):
        fragment = m.group("after") or m.group("before") or ""
        if "<" in fragment or re.fullmatch(r"(?:\\[nrt]|[\s\W])+", fragment):
            continue
        if not re.search(r"[A-Za-z]", fragment) and not has_vi(fragment):
            continue
        findings.append(
            finding("MIXED_COMPOSITION", rel, line_at(m.start()), "concat", m.group(0)[:120], mod)
        )

    # Blade/JSX quoted Vietnamese only
    if path.name.endswith(".blade.php") or path.suffix in {".jsx", ".tsx", ".js"}:
        for i, line in enumerate(text.splitlines(), 1):
            stripped = line.strip()
            if not stripped or stripped.startswith(("//", "#", "*", "{{--", "<!--")):
                continue
            if any(x in line for x in ("__(", "trans(", "@lang", "Log::", "logger(", "dump(", "dd(")):
                continue
            for sm in STRING_LITERAL.finditer(line):
                val = sm.group("val")
                if not has_vi(val) or len(val.strip()) < 2:
                    continue
                if ("/" in val and " " not in val) or val.startswith(("heroicon", "fi-")):
                    continue
                findings.append(finding("HARDCODED_VI", rel, i, "quoted-vi", val[:140], mod))

    return findings


def flatten_php_array(text: str, prefix: str = "") -> dict[str, str]:
    text = re.sub(r"//.*?$", "", text, flags=re.M)
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    m = re.search(r"return\s*\[", text)
    if not m:
        return {}
    start = m.end() - 1
    depth = 0
    i = start
    while i < len(text):
        c = text[i]
        if c in "[(":
            depth += 1
        elif c in "])":
            depth -= 1
            if depth == 0 and c == "]":
                return _parse_assoc(text[start + 1 : i], prefix)
        i += 1
    return {}


def _parse_assoc(body: str, prefix: str) -> dict[str, str]:
    keys: dict[str, str] = {}
    parts: list[str] = []
    depth = 0
    cur: list[str] = []
    in_str = None
    escape = False
    for c in body:
        if in_str:
            cur.append(c)
            if escape:
                escape = False
            elif c == "\\":
                escape = True
            elif c == in_str:
                in_str = None
            continue
        if c in "'\"":
            in_str = c
            cur.append(c)
            continue
        if c in "[(":
            depth += 1
            cur.append(c)
            continue
        if c in "])":
            depth -= 1
            cur.append(c)
            continue
        if c == "," and depth == 0:
            parts.append("".join(cur).strip())
            cur = []
            continue
        cur.append(c)
    if cur:
        parts.append("".join(cur).strip())

    for part in parts:
        if not part or "=>" not in part:
            continue
        k, _, v = part.partition("=>")
        k = k.strip().strip("'\"")
        v = v.strip()
        full = f"{prefix}.{k}" if prefix else k
        if v.startswith("["):
            inner = v[1:-1] if v.endswith("]") else v[1:]
            keys.update(_parse_assoc(inner, full))
        else:
            vm = re.match(r"(['\"])(?P<val>.*?)\1", v, re.DOTALL)
            keys[full] = vm.group("val") if vm else v[:80]
    return keys


def load_json_keys(path: Path) -> dict[str, str]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    out: dict[str, str] = {}

    def walk(obj: Any, prefix: str = "") -> None:
        if isinstance(obj, dict):
            for k, v in obj.items():
                walk(v, f"{prefix}.{k}" if prefix else str(k))
        else:
            out[prefix] = "" if obj is None else str(obj)

    walk(data)
    return out


def load_php_keys(path: Path) -> dict[str, str]:
    """Load executable Laravel language arrays accurately, with parser fallback."""
    try:
        proc = subprocess.run(
            [
                "php",
                "-r",
                "echo json_encode(require $argv[1], JSON_UNESCAPED_UNICODE);",
                str(path),
            ],
            check=True,
            capture_output=True,
            timeout=30,
        )
        data = json.loads(proc.stdout.decode("utf-8"))
        out: dict[str, str] = {}

        def walk(obj: Any, prefix: str = "") -> None:
            if isinstance(obj, dict):
                for key, value in obj.items():
                    walk(value, f"{prefix}.{key}" if prefix else str(key))
            elif isinstance(obj, list):
                for index, value in enumerate(obj):
                    walk(value, f"{prefix}.{index}" if prefix else str(index))
            else:
                out[prefix] = "" if obj is None else str(obj)

        walk(data)
        return out
    except (OSError, subprocess.SubprocessError, UnicodeDecodeError, json.JSONDecodeError):
        return flatten_php_array(path.read_text(encoding="utf-8", errors="replace"))


def parity_report(client_root: Path, addons_root: Path) -> dict[str, Any]:
    pairs: list[tuple[str, Path, Path]] = []
    en_json, vi_json = client_root / "lang" / "en.json", client_root / "lang" / "vi.json"
    if en_json.exists() or vi_json.exists():
        pairs.append(("lang/*.json", en_json, vi_json))

    en_dir, vi_dir = client_root / "lang" / "en", client_root / "lang" / "vi"
    names: set[str] = set()
    if en_dir.is_dir():
        names |= {p.name for p in en_dir.glob("*.php")}
    if vi_dir.is_dir():
        names |= {p.name for p in vi_dir.glob("*.php")}
    for name in sorted(names):
        pairs.append((f"lang/{{locale}}/{name}", en_dir / name, vi_dir / name))

    if addons_root.is_dir():
        for addon in sorted(addons_root.iterdir()):
            if not addon.is_dir() or addon.name.startswith("."):
                continue
            for lang_rel in ("lang", "resources/lang"):
                base = addon / lang_rel
                if not base.is_dir():
                    continue
                en_d, vi_d = base / "en", base / "vi"
                fnames: set[str] = set()
                if en_d.is_dir():
                    fnames |= {str(p.relative_to(en_d)).replace("\\", "/") for p in en_d.rglob("*.php")}
                if vi_d.is_dir():
                    fnames |= {str(p.relative_to(vi_d)).replace("\\", "/") for p in vi_d.rglob("*.php")}
                for name in sorted(fnames):
                    pairs.append(
                        (f"{addon.name}/{lang_rel}/{{locale}}/{name}", en_d / name, vi_d / name)
                    )

    report: dict[str, Any] = {}
    for label, en_p, vi_p in pairs:
        if en_p.suffix == ".json":
            en_keys = load_json_keys(en_p) if en_p.exists() else {}
            vi_keys = load_json_keys(vi_p) if vi_p.exists() else {}
        else:
            en_keys = load_php_keys(en_p) if en_p.exists() else {}
            vi_keys = load_php_keys(vi_p) if vi_p.exists() else {}
        en_only = sorted(set(en_keys) - set(vi_keys))
        vi_only = sorted(set(vi_keys) - set(en_keys))
        identical = [
            k
            for k in set(en_keys) & set(vi_keys)
            if en_keys[k] == vi_keys[k]
            and en_keys[k].strip()
            and not has_vi(en_keys[k])
            and looks_english_ui(en_keys[k])
            and len(en_keys[k]) > 3
        ]
        report[label] = {
            "en_file_exists": en_p.exists(),
            "vi_file_exists": vi_p.exists(),
            "en_key_count": len(en_keys),
            "vi_key_count": len(vi_keys),
            "en_only": en_only,
            "vi_only": vi_only,
            "empty_en": sorted(k for k, v in en_keys.items() if not str(v).strip()),
            "empty_vi": sorted(k for k, v in vi_keys.items() if not str(v).strip()),
            "suspicious_identical": identical[:80],
            "suspicious_identical_count": len(identical),
        }
    return report


def severity_for(f: dict[str, Any]) -> str:
    sym = f.get("symbol", "")
    cat = f["category"]
    if cat == "HARDCODED_VI" and sym in {
        "$navigationGroup", "$navigationLabel", "$modelLabel", "$pluralModelLabel",
        "NavigationGroup::make", "const",
    }:
        return "Critical"
    if cat == "HARDCODED_VI" and ("modelLabel" in sym or "navigation" in sym.lower()):
        return "Critical"
    if cat == "MIXED_COMPOSITION":
        return "High"
    if cat == "TRANSLATED_LITERAL" and has_vi(f.get("current", "")):
        return "High"
    if cat == "HARDCODED_VI":
        return "High"
    if cat == "HARDCODED_EN" and sym in {
        "$navigationGroup", "$navigationLabel", "$modelLabel", "$pluralModelLabel", "const",
    }:
        return "High"
    if cat == "HARDCODED_EN":
        return "Medium"
    if cat == "TRANSLATED_LITERAL":
        return "Low"
    return "Medium"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--client", default=r"d:\work\omnichannel-client")
    ap.add_argument("--addons", default=r"d:\work\omnichannel-addons")
    ap.add_argument("--out", default="")
    ap.add_argument("--allowlist", default="")
    args = ap.parse_args()

    allow: set[str] = set(ALLOWLIST_DEFAULT)
    scoped_allow: set[tuple[str, str]] = set()
    allowlist_path = Path(args.allowlist) if args.allowlist else Path(args.client) / "tools/i18n-allowlist.json"
    if allowlist_path.exists():
        payload = json.loads(allowlist_path.read_text(encoding="utf-8"))
        if isinstance(payload, list):
            allow |= set(payload)
        elif isinstance(payload, dict):
            allow |= set(payload.get("strings", []))
            scoped_allow |= {
                (str(row.get("file", "")), str(row.get("current", "")))
                for row in payload.get("scoped", [])
            }

    all_findings: list[dict[str, Any]] = []
    for root_s, name in ((args.client, "omnichannel-client"), (args.addons, "omnichannel-addons")):
        root = Path(root_s)
        if not root.is_dir():
            continue
        for fpath in walk_ui_files(root):
            for row in scan_file(fpath, root):
                if row["current"] in allow or (row["file"], row["current"]) in scoped_allow:
                    continue
                row["repo"] = name
                row["severity"] = severity_for(row)
                all_findings.append(row)

    seen: set[tuple] = set()
    deduped: list[dict[str, Any]] = []
    for f in all_findings:
        key = (f["repo"], f["file"], f["line"], f["category"], f["current"][:80])
        if key in seen:
            continue
        seen.add(key)
        deduped.append(f)

    parity = parity_report(Path(args.client), Path(args.addons))

    by_cat: dict[str, int] = defaultdict(int)
    by_mod: dict[str, int] = defaultdict(int)
    by_sev: dict[str, int] = defaultdict(int)
    for f in deduped:
        by_cat[f["category"]] += 1
        by_mod[f["module"]] += 1
        by_sev[f["severity"]] += 1

    # JSON key usage vs files
    en_json = load_json_keys(Path(args.client) / "lang" / "en.json")
    vi_json = load_json_keys(Path(args.client) / "lang" / "vi.json")
    missing_vi_for_json_style = []
    filtered: list[dict[str, Any]] = []
    for f in deduped:
        if f.get("symbol") == "__()/json-key-style":
            k = f["current"]
            lookup_key = k.replace("\\'", "'").replace('\\"', '"')
            if lookup_key not in vi_json and lookup_key not in en_json:
                missing_vi_for_json_style.append(f)
                f["category"] = "MISSING_TRANSLATION_KEY"
                f["severity"] = "High"
                f["note"] = "English-as-key used but absent from lang/en.json and lang/vi.json"
                filtered.append(f)
                continue
            if lookup_key in en_json and lookup_key in vi_json:
                continue
        filtered.append(f)
    deduped = filtered

    # recount after reclass
    by_cat = defaultdict(int)
    by_sev = defaultdict(int)
    for f in deduped:
        by_cat[f["category"]] += 1
        by_sev[f["severity"]] += 1

    result = {
        "summary": {
            "total_findings": len(deduped),
            "by_category": dict(sorted(by_cat.items(), key=lambda x: -x[1])),
            "by_severity": dict(sorted(by_sev.items(), key=lambda x: -x[1])),
            "by_module": dict(sorted(by_mod.items(), key=lambda x: -x[1])),
            "json_style_keys_missing_from_lang": len(missing_vi_for_json_style),
        },
        "parity": parity,
        "findings": deduped,
    }

    out_text = json.dumps(result, ensure_ascii=False, indent=2)
    if args.out:
        Path(args.out).write_text(out_text, encoding="utf-8")
        print(f"Wrote {args.out} ({len(deduped)} findings)", file=sys.stderr)
    else:
        print(out_text)
    print("SUMMARY", json.dumps(result["summary"], ensure_ascii=False), file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
