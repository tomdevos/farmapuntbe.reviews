"""
Build CNK stub notes in knowledgebase/cnk/ for every CNK found in a medication
schema xlsx that does not yet have a note.

Behaviour:
  - For each unique CNK in the schema, check knowledgebase/cnk/<CNK>.md.
  - If the file does not exist, create a stub with status: nog-niet-verrijkt.
  - If the file exists, leave it untouched (it may already be hand-curated).
  - Captures every Medicatienaam-variant seen for a CNK as an alias, plus the
    Medicatiegroep(s) it appears in.

Run after build_resident_notes.py — or standalone.
"""

from __future__ import annotations

import re
import unicodedata
from collections import defaultdict
from pathlib import Path
from typing import Any

import openpyxl

SOURCE_XLSX = Path(
    "/sessions/happy-magical-goldberg/mnt/uploads/parkhof_2026-05-07_medication-schema.xlsx"
)
VAULT_ROOT = Path("/sessions/happy-magical-goldberg/mnt/farmapuntbe.reviews")
CNK_DIR = VAULT_ROOT / "knowledgebase" / "cnk"

SHEET_NAMES = [
    "Tijdelijke medicatie",
    "Chronische medicatie",
    "Indien nodig medicatie",
    "Verboden medicatie",
]


def cleanup_str(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


def yaml_escape(text: str) -> str:
    # Quote strings safely for YAML scalars
    return text.replace("\\", "\\\\").replace('"', '\\"')


def collect_cnks() -> dict[str, dict[str, Any]]:
    """
    Returns: { cnk_str: { 'names': set, 'groups': set, 'first_seen_in': str } }
    """
    wb = openpyxl.load_workbook(SOURCE_XLSX, data_only=True)
    out: dict[str, dict[str, Any]] = defaultdict(
        lambda: {"names": set(), "groups": set(), "sheets": set()}
    )

    for sheet_name in SHEET_NAMES:
        ws = wb[sheet_name]
        header = [c.value for c in ws[1]]
        idx = {h: i for i, h in enumerate(header) if h is not None}
        cnk_i = idx.get("CNK")
        name_i = idx.get("Medicatienaam")
        group_i = idx.get("Medicatiegroep")
        if cnk_i is None:
            continue
        for row in ws.iter_rows(min_row=2, values_only=True):
            cnk = cleanup_str(row[cnk_i] if cnk_i < len(row) else None)
            if not cnk:
                continue
            entry = out[cnk]
            entry["sheets"].add(sheet_name)
            if name_i is not None and name_i < len(row):
                name = cleanup_str(row[name_i])
                if name:
                    entry["names"].add(name)
            if group_i is not None and group_i < len(row):
                group = cleanup_str(row[group_i])
                if group:
                    entry["groups"].add(group)
    return dict(out)


def pick_primary_name(names: set[str]) -> str:
    if not names:
        return "(onbekende medicatie)"
    # Prefer the longest, most descriptive name as the canonical title
    return max(names, key=len)


def render_stub(cnk: str, info: dict[str, Any]) -> str:
    names_sorted = sorted(info["names"], key=lambda s: (-len(s), s.lower()))
    primary = pick_primary_name(info["names"])
    groups_sorted = sorted(info["groups"])
    sheets_sorted = sorted(info["sheets"])

    lines = ["---"]
    lines.append(f'cnk: "{cnk}"')
    lines.append(f'medicatienaam: "{yaml_escape(primary)}"')

    if len(names_sorted) > 1:
        lines.append("aliassen:")
        for n in names_sorted:
            if n != primary:
                lines.append(f'  - "{yaml_escape(n)}"')

    if groups_sorted:
        lines.append("medicatiegroep:")
        for g in groups_sorted:
            lines.append(f'  - "{yaml_escape(g)}"')

    lines.append('actief_bestanddeel: ""')
    lines.append('atc_code: ""')
    lines.append('producent: ""')
    lines.append('vorm: ""')
    lines.append("status: nog-niet-verrijkt")
    lines.append("tags:")
    lines.append("  - cnk")
    lines.append("  - status/nog-niet-verrijkt")
    lines.append("---")
    lines.append("")
    lines.append(f"# {primary}")
    lines.append("")
    lines.append(f"CNK: **{cnk}**")
    lines.append("")
    if sheets_sorted:
        lines.append(
            "Voor het eerst gezien in: " + ", ".join(f"_{s}_" for s in sheets_sorted)
        )
        lines.append("")
    lines.append("## Productinformatie")
    lines.append("")
    lines.append("*Nog niet verrijkt.*")
    lines.append("")
    lines.append("## Actief bestanddeel")
    lines.append("")
    lines.append("*Nog niet verrijkt.* Link naar `knowledgebase/active-ingredient/<inn>.md` zodra bekend.")
    lines.append("")
    lines.append("## Indicaties")
    lines.append("")
    lines.append("*Nog niet verrijkt.*")
    lines.append("")
    lines.append("## Belangrijkste interacties / aandachtspunten")
    lines.append("")
    lines.append("*Nog niet verrijkt.*")
    lines.append("")
    lines.append("## Bronnen")
    lines.append("")
    lines.append("*Nog niet verrijkt.*")
    lines.append("")
    return "\n".join(lines)


def main() -> None:
    CNK_DIR.mkdir(parents=True, exist_ok=True)
    cnks = collect_cnks()

    created = 0
    skipped_existing = 0
    skipped_bad = 0

    for cnk, info in sorted(cnks.items()):
        # Basic sanity: CNK should be all digits in practice. Keep oddballs (e.g. 9999xxx),
        # but skip empty/garbage.
        if not re.fullmatch(r"[A-Za-z0-9_-]+", cnk):
            skipped_bad += 1
            continue
        target = CNK_DIR / f"{cnk}.md"
        if target.exists():
            skipped_existing += 1
            continue
        target.write_text(render_stub(cnk, info), encoding="utf-8")
        created += 1

    print(f"Unieke CNK's in schema: {len(cnks)}")
    print(f"Stubs aangemaakt:       {created}")
    print(f"Bestaande bestanden:    {skipped_existing}")
    print(f"Genegeerd (ongeldig):   {skipped_bad}")


if __name__ == "__main__":
    main()
