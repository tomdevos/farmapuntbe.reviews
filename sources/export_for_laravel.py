"""Export reference data (CLASS_INGREDIENTS map + GheOPS criteria) to JSON
for the Laravel seeder. Run from the repo root:

    python3 sources/export_for_laravel.py

Writes:
    app/database/seeders/data/drug-class-ingredients.json
    app/database/seeders/data/gheops-criteria.json
"""

from __future__ import annotations

import json
import re
from pathlib import Path

from screen_resident import CLASS_INGREDIENTS, slugify

REPO_ROOT = Path(__file__).resolve().parent.parent
GHEOPS_DIR = REPO_ROOT / "knowledgebase" / "gheops"
OUT_DIR = REPO_ROOT / "app" / "database" / "seeders" / "data"


def parse_frontmatter(text: str) -> tuple[dict, str]:
    if not text.startswith("---\n"):
        return {}, text
    end = text.find("\n---\n", 4)
    if end == -1:
        return {}, text
    raw = text[4:end]
    body = text[end + 5 :]
    meta: dict = {}
    key = None
    for line in raw.splitlines():
        if not line.strip():
            continue
        if line.startswith("  - "):
            if key:
                meta.setdefault(key, [])
                if isinstance(meta[key], list):
                    val = line[4:].strip().strip('"').strip("'")
                    meta[key].append(val)
            continue
        if ":" in line:
            k, _, v = line.partition(":")
            key = k.strip()
            v = v.strip()
            if v == "":
                meta[key] = []
            else:
                meta[key] = v.strip('"').strip("'")
    return meta, body


_AI_LINK_RE = re.compile(
    r"\[\[knowledgebase/active-ingredient/([^\\|\]]+)\\*\|([^\]]+)\]\]"
)


def extract_section(body: str, heading: str) -> str:
    pattern = re.compile(
        rf"##\s+{re.escape(heading)}\b(.*?)(?=\n##|\Z)", re.DOTALL
    )
    m = pattern.search(body)
    if not m:
        return ""
    return m.group(1).strip()


def export_drug_classes() -> None:
    out = []
    for klass, inns in sorted(CLASS_INGREDIENTS.items()):
        for inn in sorted(inns):
            out.append({"drug_class": klass, "ingredient_slug": slugify(inn), "ingredient_name": inn})
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    path = OUT_DIR / "drug-class-ingredients.json"
    path.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"wrote {path} — {len(out)} rows")


def export_gheops() -> None:
    out = []
    for lijst_dir in sorted(GHEOPS_DIR.glob("lijst-*")):
        list_num = int(lijst_dir.name.split("-")[-1])
        for md in sorted(lijst_dir.glob("*.md")):
            meta, body = parse_frontmatter(md.read_text(encoding="utf-8"))
            nr = str(meta.get("nr") or md.stem.split("-")[0])
            title = meta.get("criterium") or ""
            stoffen = meta.get("stoffen") or []
            if isinstance(stoffen, str):
                stoffen = []
            groepen = meta.get("groepen") or []
            if isinstance(groepen, str):
                groepen = []
            comorbiditeit = meta.get("comorbiditeit") or None
            ingredient_slugs = []
            for ai in stoffen:
                m = _AI_LINK_RE.search(ai) if isinstance(ai, str) else None
                if m:
                    ingredient_slugs.append(m.group(1))
                elif isinstance(ai, str):
                    ingredient_slugs.append(slugify(ai))
            rationale = extract_section(body, "Rationale")
            alternatief = extract_section(body, "Alternatief")
            out.append({
                "list_num": list_num,
                "nr": nr,
                "title": title,
                "comorbiditeit": comorbiditeit,
                "rationale": rationale or None,
                "alternatief": alternatief or None,
                "ingredient_slugs": ingredient_slugs,
                "drug_classes": [g for g in groepen if isinstance(g, str)],
            })
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    path = OUT_DIR / "gheops-criteria.json"
    path.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"wrote {path} — {len(out)} criteria")


if __name__ == "__main__":
    export_drug_classes()
    export_gheops()
