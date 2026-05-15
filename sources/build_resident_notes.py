"""
Build per-resident Obsidian notes from a medication schema xlsx.

Folder layout:
  carecenter/<carecenter-slug>/<afdeling-slug>/<resident-slug>.md

Each note contains:
  - YAML frontmatter (name, room, doctor, department, carecenter, last_updated)
  - Medicatielijst: unique medications across all sheets, with CNK wikilinks
  - Therapieschema: chronisch / tijdelijk / indien nodig / verboden tables
"""

from __future__ import annotations

import os
import re
import sys
import unicodedata
from collections import defaultdict
from datetime import datetime, date
from pathlib import Path
from typing import Any

import openpyxl

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

SOURCE_XLSX = Path(
    "/sessions/happy-magical-goldberg/mnt/uploads/parkhof_2026-05-07_medication-schema.xlsx"
)
VAULT_ROOT = Path("/sessions/happy-magical-goldberg/mnt/farmapuntbe.reviews")
CARECENTER_NAME = "parkhof"  # derived from filename prefix
CNK_DIR = VAULT_ROOT / "knowledgebase" / "cnk"

SHEETS = {
    "chronisch": "Chronische medicatie",
    "tijdelijk": "Tijdelijke medicatie",
    "indien_nodig": "Indien nodig medicatie",
    "verboden": "Verboden medicatie",
}

TIME_SLOTS = ["06:00", "08:00", "12:00", "14:00", "17:00", "18:00", "20:00", "22:00"]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def slugify(text: str) -> str:
    """Lowercase, dashes, ascii-only — stable folder/file names."""
    if text is None:
        return "unknown"
    # strip accents
    nfkd = unicodedata.normalize("NFKD", str(text))
    ascii_text = nfkd.encode("ascii", "ignore").decode("ascii")
    ascii_text = ascii_text.lower()
    ascii_text = re.sub(r"[^a-z0-9]+", "-", ascii_text)
    ascii_text = ascii_text.strip("-")
    return ascii_text or "unknown"


def split_resident_name(full_name: str) -> tuple[str, str]:
    """
    Split 'Voornaam ACHTERNAAM' into (achternaam, voornaam).
    Convention in the source xlsx: first name(s) in Title Case, surname in UPPERCASE
    (possibly multi-token like 'VAN DEN NEST', 'DE SLOOVERE').

    Algorithm: starting from the right, collect tokens that are fully uppercase as the
    surname. Remaining tokens form the first name.
    """
    if not full_name:
        return ("", "")
    tokens = full_name.strip().split()
    if not tokens:
        return ("", "")

    surname_tokens: list[str] = []
    while tokens and tokens[-1].isupper():
        surname_tokens.insert(0, tokens.pop())

    # Fallback: if no uppercase tail was found, assume last token is the surname.
    if not surname_tokens:
        surname_tokens = [tokens.pop()] if tokens else [""]

    achternaam = " ".join(surname_tokens)
    voornaam = " ".join(tokens)
    return (achternaam, voornaam)


def resident_slug(full_name: str) -> str:
    """File-name slug as 'achternaam-voornaam'."""
    achternaam, voornaam = split_resident_name(full_name)
    parts = [p for p in (achternaam, voornaam) if p]
    return slugify(" ".join(parts)) if parts else slugify(full_name)


def fmt_date(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, (datetime, date)):
        return value.strftime("%Y-%m-%d")
    return str(value).strip()


def fmt_num(value: Any) -> str:
    """Render 1 -> '1', 0.5 -> '0.5', None -> '' for time-slot cells."""
    if value is None or value == "":
        return ""
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return str(value)


def md_escape(text: Any) -> str:
    if text is None:
        return ""
    s = str(text).replace("\r\n", " ").replace("\n", " ").replace("|", "\\|")
    return s.strip()


def cnk_link(cnk: Any, medname: str) -> str:
    """
    Build an Obsidian wikilink to the CNK note.

    Note: inside a markdown table the alias separator '|' collides with the table
    column separator, so we escape it as '\\|'. Obsidian renders '\\|' inside
    [[ ]] as a normal alias separator. The escape is also safe outside tables.
    """
    cnk_str = str(cnk).strip() if cnk is not None else ""
    name = md_escape(medname) or "(onbekend)"
    if not cnk_str:
        return name
    return f"[[knowledgebase/cnk/{cnk_str}\\|{name}]]"


def ai_link(slug: str, display: str) -> str:
    """Wikilink to an active-ingredient note, table-safe (escaped pipe)."""
    return f"[[knowledgebase/active-ingredient/{slug}\\|{md_escape(display)}]]"


# ---------------------------------------------------------------------------
# Load active-ingredient mapping from enriched CNK notes
# ---------------------------------------------------------------------------

# In the CNK file's frontmatter the actief_bestanddeel list contains
# YAML-escaped wikilinks like:
#   - "[[knowledgebase/active-ingredient/apixaban\\|apixaban]]"
# The pattern below extracts (slug, display) pairs regardless of how many
# backslashes precede the pipe (handles single- and double-escape variants).
_AI_LINK_RE = re.compile(
    r"\[\[knowledgebase/active-ingredient/([^\\|\]]+)\\*\|([^\]]+)\]\]"
)


def load_cnk_active_ingredients() -> dict[str, list[tuple[str, str]]]:
    """
    Returns: { cnk: [(slug, display), ...] }
    Built by scanning every knowledgebase/cnk/*.md file's frontmatter.
    """
    mapping: dict[str, list[tuple[str, str]]] = {}
    if not CNK_DIR.exists():
        return mapping
    for md in CNK_DIR.glob("*.md"):
        cnk = md.stem
        # Walk only the frontmatter region (between two '---' markers)
        text = md.read_text(encoding="utf-8")
        if not text.startswith("---"):
            continue
        fm_end = text.find("\n---", 4)
        if fm_end == -1:
            continue
        fm = text[3:fm_end]
        # Find the actief_bestanddeel block
        in_block = False
        items: list[tuple[str, str]] = []
        for line in fm.splitlines():
            if line.startswith("actief_bestanddeel:"):
                in_block = True
                continue
            if in_block:
                if line.startswith("  - "):
                    m = _AI_LINK_RE.search(line)
                    if m:
                        items.append((m.group(1), m.group(2)))
                    continue
                # Block ends at the next key (no leading whitespace) or empty line
                if line and not line.startswith(" "):
                    in_block = False
        if items:
            mapping[cnk] = items
    return mapping


def render_ai_cell(cnk: Any, ai_map: dict[str, list[tuple[str, str]]]) -> str:
    """Build the table-cell content for the 'Actief bestanddeel' column."""
    cnk_str = cleanup_str(cnk)
    if not cnk_str:
        return ""
    items = ai_map.get(cnk_str)
    if not items:
        return ""
    return "<br>".join(ai_link(slug, display) for slug, display in items)


def cleanup_str(value: Any) -> str:
    if value is None:
        return ""
    return str(value).strip()


# ---------------------------------------------------------------------------
# Read workbook
# ---------------------------------------------------------------------------

def read_rows(ws, header_to_idx: dict[str, int]) -> list[dict[str, Any]]:
    rows = []
    for i, row in enumerate(ws.iter_rows(values_only=True)):
        if i == 0:
            continue
        if all(c is None or c == "" for c in row):
            continue
        record = {h: row[idx] if idx < len(row) else None for h, idx in header_to_idx.items()}
        rows.append(record)
    return rows


def load_workbook() -> dict[str, list[dict[str, Any]]]:
    wb = openpyxl.load_workbook(SOURCE_XLSX, data_only=True)
    out = {}
    for key, sheet_name in SHEETS.items():
        ws = wb[sheet_name]
        header = [c.value for c in ws[1]]
        header_to_idx = {h: i for i, h in enumerate(header) if h is not None}
        out[key] = read_rows(ws, header_to_idx)
    return out


# ---------------------------------------------------------------------------
# Group by resident
# ---------------------------------------------------------------------------

def resident_key(row: dict[str, Any]) -> tuple[str, str, str]:
    """Identify a resident by (afdeling, kamer, naam)."""
    return (
        cleanup_str(row.get("Afdeling")),
        cleanup_str(row.get("Kamer")),
        cleanup_str(row.get("Resident")),
    )


def build_residents(data: dict[str, list[dict[str, Any]]]) -> dict[tuple, dict[str, Any]]:
    residents: dict[tuple, dict[str, Any]] = {}

    for sheet_key, rows in data.items():
        for row in rows:
            key = resident_key(row)
            if not key[2]:  # skip if no name
                continue
            r = residents.setdefault(
                key,
                {
                    "afdeling": key[0],
                    "kamer": key[1],
                    "resident": key[2],
                    "dokter": cleanup_str(row.get("Dokter")),
                    "tijdstip_opvraging": row.get("Tijdstip opvraging"),
                    "chronisch": [],
                    "tijdelijk": [],
                    "indien_nodig": [],
                    "verboden": [],
                },
            )
            # Use the most recently seen doctor if first occurrence was empty
            if not r["dokter"]:
                r["dokter"] = cleanup_str(row.get("Dokter"))
            r[sheet_key].append(row)

    return residents


# ---------------------------------------------------------------------------
# Markdown rendering
# ---------------------------------------------------------------------------

def render_frontmatter(r: dict[str, Any]) -> str:
    last_updated = ""
    if r["tijdstip_opvraging"]:
        last_updated = fmt_date(r["tijdstip_opvraging"])
    return (
        "---\n"
        f'naam: "{r["resident"]}"\n'
        f'kamer: "{r["kamer"]}"\n'
        f'dokter: "{r["dokter"]}"\n'
        f'afdeling: "{r["afdeling"]}"\n'
        f'carecenter: "{CARECENTER_NAME}"\n'
        f"laatst_bijgewerkt: {last_updated}\n"
        "tags:\n"
        "  - bewoner\n"
        f"  - carecenter/{CARECENTER_NAME}\n"
        f"  - afdeling/{slugify(r['afdeling'])}\n"
        "---\n"
    )


def collect_unique_meds(r: dict[str, Any]) -> list[tuple[str, str, str, str]]:
    """
    Returns sorted list of (cnk, medicatienaam, groep, herkomst-flags).
    Herkomst-flags = 'C/T/N/V' depending on which sheets it appears in.
    """
    seen: dict[tuple[str, str], dict[str, Any]] = {}
    flag_map = {
        "chronisch": "C",
        "tijdelijk": "T",
        "indien_nodig": "N",
        "verboden": "V",
    }
    for sheet_key, flag in flag_map.items():
        for row in r[sheet_key]:
            cnk = cleanup_str(row.get("CNK"))
            name = cleanup_str(row.get("Medicatienaam"))
            groep = cleanup_str(row.get("Medicatiegroep"))
            key = (cnk, name)
            entry = seen.setdefault(key, {"cnk": cnk, "name": name, "groep": groep, "flags": set()})
            entry["flags"].add(flag)
    result = []
    for entry in seen.values():
        flags = "".join(sorted(entry["flags"]))
        result.append((entry["cnk"], entry["name"], entry["groep"], flags))
    result.sort(key=lambda x: (x[2].lower(), x[1].lower()))
    return result


def render_medicatielijst(r: dict[str, Any], ai_map: dict[str, list[tuple[str, str]]]) -> str:
    rows = collect_unique_meds(r)
    if not rows:
        return "## Medicatielijst\n\n*Geen medicatie gevonden in het schema.*\n"

    out = [
        "## Medicatielijst",
        "",
        "Unieke medicatie over alle schema's heen. Herkomst: **C** = chronisch, **T** = tijdelijk, **N** = indien nodig, **V** = verboden.",
        "",
        "| Medicatie | Actief bestanddeel | Medicatiegroep | CNK | Herkomst |",
        "| --- | --- | --- | --- | --- |",
    ]
    for cnk, name, groep, flags in rows:
        link = cnk_link(cnk, name)
        ai_cell = render_ai_cell(cnk, ai_map)
        out.append(f"| {link} | {ai_cell} | {md_escape(groep)} | {md_escape(cnk)} | {flags} |")
    out.append("")
    return "\n".join(out)


def render_schedule_table(
    rows: list[dict[str, Any]], title: str, ai_map: dict[str, list[tuple[str, str]]]
) -> str:
    if not rows:
        return f"### {title}\n\n*Geen.*\n"

    header = (
        "| Medicatie | Actief bestanddeel | Groep | Type | Frequentie | Begin | Einde | Eenheid | "
        + " | ".join(TIME_SLOTS)
        + " | Opmerkingen |"
    )
    sep = "| --- | --- | --- | --- | --- | --- | --- | --- |" + " --- |" * len(TIME_SLOTS) + " --- |"
    body = []
    for row in rows:
        line = "| "
        line += cnk_link(row.get("CNK"), cleanup_str(row.get("Medicatienaam"))) + " | "
        line += render_ai_cell(row.get("CNK"), ai_map) + " | "
        line += md_escape(row.get("Medicatiegroep")) + " | "
        line += md_escape(row.get("Type")) + " | "
        line += md_escape(row.get("Frequentie")) + " | "
        line += fmt_date(row.get("Begindatum")) + " | "
        line += fmt_date(row.get("Einddatum")) + " | "
        line += md_escape(row.get("Eenheid")) + " | "
        for slot in TIME_SLOTS:
            line += fmt_num(row.get(slot)) + " | "
        line += md_escape(row.get("Opmerkingen")) + " |"
        body.append(line)
    return "\n".join([f"### {title}", "", header, sep, *body, ""])


def render_prn_table(rows: list[dict[str, Any]], ai_map: dict[str, list[tuple[str, str]]]) -> str:
    if not rows:
        return "### Indien nodig medicatie\n\n*Geen.*\n"
    header = (
        "| Medicatie | Actief bestanddeel | Groep | Type | Begin | Einde | Eenheid | Min | Max | Max/dag | Geven bij | Interval | Opmerkingen |"
    )
    sep = "|" + " --- |" * 13
    body = []
    for row in rows:
        line = "| "
        line += cnk_link(row.get("CNK"), cleanup_str(row.get("Medicatienaam"))) + " | "
        line += render_ai_cell(row.get("CNK"), ai_map) + " | "
        line += md_escape(row.get("Medicatiegroep")) + " | "
        line += md_escape(row.get("Type")) + " | "
        line += fmt_date(row.get("Begindatum")) + " | "
        line += fmt_date(row.get("Einddatum")) + " | "
        line += md_escape(row.get("Eenheid")) + " | "
        line += fmt_num(row.get("Minimumdosis")) + " | "
        line += fmt_num(row.get("Maximumdosis")) + " | "
        line += fmt_num(row.get("Maximaal aantal dosissen per dag")) + " | "
        line += md_escape(row.get("Geven bij")) + " | "
        line += md_escape(row.get("Tijd tussen toedienen")) + " | "
        line += md_escape(row.get("Opmerkingen")) + " |"
        body.append(line)
    return "\n".join(["### Indien nodig medicatie", "", header, sep, *body, ""])


def render_forbidden_table(rows: list[dict[str, Any]], ai_map: dict[str, list[tuple[str, str]]]) -> str:
    if not rows:
        return "### Verboden medicatie\n\n*Geen.*\n"
    header = "| Medicatie | Actief bestanddeel | Groep | Type | CNK | Reden | Opmerkingen |"
    sep = "| --- | --- | --- | --- | --- | --- | --- |"
    body = []
    for row in rows:
        line = "| "
        line += cnk_link(row.get("CNK"), cleanup_str(row.get("Medicatienaam"))) + " | "
        line += render_ai_cell(row.get("CNK"), ai_map) + " | "
        line += md_escape(row.get("Medicatiegroep")) + " | "
        line += md_escape(row.get("Type")) + " | "
        line += md_escape(row.get("CNK")) + " | "
        line += md_escape(row.get("Reden")) + " | "
        line += md_escape(row.get("Opmerkingen")) + " |"
        body.append(line)
    return "\n".join(["### Verboden medicatie", "", header, sep, *body, ""])


def render_note(r: dict[str, Any], ai_map: dict[str, list[tuple[str, str]]]) -> str:
    parts = [
        render_frontmatter(r),
        "",
        f"# {r['resident']}",
        "",
        f"Kamer **{r['kamer']}** · Afdeling **{r['afdeling']}** · Dokter **{r['dokter']}** · Carecenter **{CARECENTER_NAME}**",
        "",
        render_medicatielijst(r, ai_map),
        "",
        "## Therapieschema",
        "",
        render_schedule_table(r["chronisch"], "Chronische medicatie", ai_map),
        render_schedule_table(r["tijdelijk"], "Tijdelijke medicatie", ai_map),
        render_prn_table(r["indien_nodig"], ai_map),
        render_forbidden_table(r["verboden"], ai_map),
        "",
        "## Reviews",
        "",
        "*Nog geen reviews gemaakt.*",
        "",
    ]
    return "\n".join(parts)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    data = load_workbook()
    residents = build_residents(data)

    # Load enriched active-ingredient mapping (built by enrich_cnk.py).
    # Missing CNKs simply yield an empty cell.
    ai_map = load_cnk_active_ingredients()
    print(f"[ai-map] loaded active ingredients for {len(ai_map)} CNKs")

    base = VAULT_ROOT / "carecenter" / CARECENTER_NAME

    # Stats
    afdelingen: dict[str, int] = defaultdict(int)
    total_meds = 0
    written = 0

    # Clean up any previously generated notes so renames don't leave stale files.
    if base.exists():
        for stale in base.rglob("*.md"):
            stale.unlink()

    for key, r in residents.items():
        afd_slug = slugify(r["afdeling"])
        res_slug = resident_slug(r["resident"])
        afd_dir = base / afd_slug
        afd_dir.mkdir(parents=True, exist_ok=True)

        note_path = afd_dir / f"{res_slug}.md"
        note_path.write_text(render_note(r, ai_map), encoding="utf-8")

        afdelingen[r["afdeling"]] += 1
        total_meds += (
            len(r["chronisch"]) + len(r["tijdelijk"]) + len(r["indien_nodig"]) + len(r["verboden"])
        )
        written += 1

    print(f"Bewoners geschreven: {written}")
    print(f"Totaal medicatieregels verwerkt: {total_meds}")
    print("Per afdeling:")
    for afd, count in sorted(afdelingen.items()):
        print(f"  {afd} ({slugify(afd)}): {count} bewoners")


if __name__ == "__main__":
    main()
