"""
Parse the GheOP³S-tool PDF into per-criterium Obsidian notes.

Output layout:
    knowledgebase/gheops/lijst-1/<nr>-<slug>.md
    knowledgebase/gheops/lijst-2/<nr>-<slug>.md
    ...
    knowledgebase/gheops/index.md             ← overview of all criteria

Each note has frontmatter:
    nr: <int>
    lijst: <int>
    criterium: "<full criterium name>"
    comorbiditeit: "<only for lijst 2>"
    stoffen: ["<inn>", ...]               ← extracted by matching against active-ingredient slugs
    groepen: ["<drug-class>", ...]         ← captured terms like 'PPI', 'NSAID', 'β-blokker', ...
    bron: gheops
    status: verrijkt
    tags: [gheops, gheops/lijst-N, status/verrijkt]

And body sections: ## Criterium, ## Rationale, ## Alternatief, ## Bron.

Coverage:
    Lijsten 1, 2, 3 — standard "criterium / rationale / alternatief" structure.
    Lijst 4         — interactions: "criterium / rationale" (no alternatief column).
    Lijst 5         — pharmaceutical-care criteria (page 9 only for v1; the
                       sub-tables on pages 10-15 are skipped — they list specific
                       indicators rather than evaluable criteria).
"""

from __future__ import annotations

import re
import unicodedata
from collections import defaultdict
from pathlib import Path

import pdfplumber

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

VAULT_ROOT = Path(__file__).resolve().parent.parent  # sources/ → vault root
GHEOPS_PDF = VAULT_ROOT / "sources" / "gheops" / "GheOP³S-tool versie 2_maart_2023.pdf"
GHEOPS_DIR = VAULT_ROOT / "knowledgebase" / "gheops"
INN_DIR = VAULT_ROOT / "knowledgebase" / "active-ingredient"

# Map page-number → which list the page belongs to. Determined by reading the
# document; updated when the PDF version changes.
PAGE_LIST_MAP: dict[int, int] = {
    2: 1, 3: 1, 4: 1,
    5: 2,
    6: 3,
    7: 4, 8: 4,
    9: 5,
}

# Known drug-class identifiers we surface as `groepen` in the frontmatter.
# Order matters only for readability; matching prefers the LONGEST hit so a
# narrow class (e.g. "Niet-cardioselectieve β-blokker") wins over a broader
# overlap ("β-blokker"). The screener uses this narrowing in CLASS_INGREDIENTS.
DRUG_CLASS_TERMS = [
    # Narrow variants first (longer string usually = more specific)
    "Niet-cardioselectieve β-blokker", "niet-cardioselectieve betablokker",
    "tricyclisch antidepressivum",
    "kaliumsparend diureticum",
    "anticholinerge medicatie", "anticholinergicum",
    "RAAS-inhibitor", "ACE-inhibitor",
    "vitamine K-antagonist",
    "DOAC", "NOAC",
    # Broader classes
    "PPI", "NSAID", "sartaan",
    "β-blokker", "betablokker", "α-blokker", "alfa-blokker",
    "calciumantagonist", "antihypertensiva", "lisdiureticum", "thiazide", "diureticum",
    "anticoagulantia", "antiaggregans", "antiaggregantia",
    "antidepressivum", "SSRI", "TCA",
    "antipsychoticum", "neurolepticum",
    "benzodiazepine", "Z-medicatie",
    "macrolide", "fluorochinolon", "fluoroquinolon", "antibioticum",
    "corticosteroïd", "corticosteroïden",
    "opioïd", "opioïden",
    "statine",
    "bisfosfonaat",
    "antimuscarinicum",
]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def slugify(text: str, maxlen: int = 60) -> str:
    nfkd = unicodedata.normalize("NFKD", str(text))
    ascii_text = nfkd.encode("ascii", "ignore").decode("ascii").lower()
    ascii_text = re.sub(r"[^a-z0-9]+", "-", ascii_text).strip("-")
    return ascii_text[:maxlen].rstrip("-") or "unknown"


def yaml_escape(text: str) -> str:
    return text.replace("\\", "\\\\").replace('"', '\\"')


_FOOTER_PATTERNS = [
    r"GheOP3S-tool\s*[–-]\s*Versie\s*maart\s*2023.*?Universiteit\s*Gent",
    r"©\s*Eenheid Farmaceutische Zorg\s*[–-]\s*Universiteit Gent",
    r"\bVersie maart 2023\b",
]

# Phrases that very reliably signal the START of a rationale or alternatief
# section bleeding into the criterium field due to imperfect column extraction.
_BLEED_TRUNCATE_RE = re.compile(
    r"\s+(Hoog risico\b|Ongunstige\b|Verhoogd risico\b|Veiligere alternatieven\b|"
    r"Geen bewijs\b|Beperkte evidentie\b|Potentieel\b|Het risico kan\b|"
    r"Kan slaperigheid\b|Risico op\b|Kunnen\b|Kan leiden tot\b|"
    r"Hoger risico\b|Langdurig gebruik\b|"
    r"\d{1,2}\.\s)",
    re.IGNORECASE,
)


def trim_criterium_name(text: str) -> str:
    """Cut off rationale/alternatief that bled into the criterium-name field."""
    m = _BLEED_TRUNCATE_RE.search(text)
    if m and m.start() > 8:  # keep enough text to be a meaningful name
        return text[: m.start()].rstrip(" ,;:-–—")
    return text


def clean_cell(text: str | None) -> str:
    """Collapse internal whitespace, strip, and remove known page-footer noise."""
    if not text:
        return ""
    s = re.sub(r"\s+", " ", text).strip()
    for pat in _FOOTER_PATTERNS:
        s = re.sub(pat, " ", s, flags=re.IGNORECASE)
    # Strip lonely page numbers that drift in (e.g. ' 2 ' or trailing ' 3')
    s = re.sub(r"\s+\d{1,2}\s+", " ", s)
    s = re.sub(r"\s+\d{1,2}$", "", s)
    return re.sub(r"\s+", " ", s).strip(" -–—")


# ---------------------------------------------------------------------------
# Per-page table parsing
# ---------------------------------------------------------------------------

def is_criterion_start_value(value: str) -> bool:
    """A cell that looks like a leading criterion number (1..999)."""
    s = (value or "").strip()
    return bool(s) and s.isdigit() and len(s) <= 3


def split_packed_row(row: list[str], expected_cols: int) -> list[str] | None:
    """
    Some PDF rows collapse all columns into a single cell, e.g.
       ['2 Digoxine > 0,125 mg/dag Ongunstige risico... 1. Evalueer...']
    For these we recover (nr, criterium-name, rationale-blob).
    The packed text typically has structure:
        '<nr> <criterium-line>  <rationale-line>  <alternatief-line>\\n<crit-cont> ...'
    where columns are separated by runs of 2+ spaces on each visual line.

    Strategy (pragmatic):
      1. Match leading `<nr> ` to extract the number.
      2. Take the rest as one blob; place its first wide-space-delimited
         portion in `criterium`, the remainder in `rationale`. We don't try to
         recover `alternatief` separately — the full text is preserved in the
         rationale blob, which is enough for the pharmacist to read.

    Returns None if we can't confidently split.
    """
    non_empty = [c for c in row if c]
    if len(non_empty) != 1:
        return None
    text = non_empty[0]
    m = re.match(r"^(\d{1,3})\s+(.+)$", text, re.DOTALL)
    if not m:
        return None
    nr = m.group(1)
    rest = m.group(2).strip()

    # Try to split criterium-name from rationale on the first wide-space run
    # (≥ 2 spaces) within the FIRST visual line. Multi-line content typical of
    # rationale wrapping is then folded into the rationale blob.
    first_line, _, more = rest.partition("\n")
    parts = re.split(r" {2,}", first_line, maxsplit=1)
    if len(parts) == 2:
        criterium, rest_of_first_line = parts
    else:
        # Fallback: take up to the first sentence/clause boundary as criterium.
        # Heuristic: ' Hoog risico', ' Risico', ' Kan', ' Ongunstige', ' Verhoogd',
        # ' Beperkte', ' Veiligere'... too many to enumerate. Default to first
        # 80 chars or the whole line.
        criterium = first_line[:80].strip()
        rest_of_first_line = first_line[len(criterium):].strip()

    rationale_lines = [rest_of_first_line] if rest_of_first_line else []
    if more:
        # Normalise the multi-line wrap: keep each line as-is, the reader can
        # follow it. We collapse only excessive whitespace.
        for ln in more.splitlines():
            ln = re.sub(r"\s+", " ", ln).strip()
            if ln:
                rationale_lines.append(ln)
    rationale = " ".join(rationale_lines).strip()

    cells = [nr, criterium.strip(), rationale] + [""] * max(0, expected_cols - 3)
    return cells


def extract_columns_by_position(page, column_names: list[str]) -> list[list[str]]:
    """
    Use word X-coordinates to assign each word on the page to a column.
    Returns rows of column-text values (only data rows, header excluded).

    column_names: ordered list like ['Nr.', 'Criterium', 'Rationale', 'Alternatief']
                  (used to locate the header row and establish column x_start positions)
    """
    words = page.extract_words(use_text_flow=True, keep_blank_chars=False)
    if not words:
        return []

    # Find the header row: a horizontal band of words containing the first column name
    header_word = None
    for w in words:
        if w["text"].rstrip(".") == column_names[0].rstrip("."):
            header_word = w
            break
    if header_word is None:
        return []

    header_y = header_word["top"]
    # All words within a few px of the header's Y form the header row
    header_band = [w for w in words if abs(w["top"] - header_y) < 4]

    # Find each column's x0 from the header band
    col_xs: list[tuple[str, float]] = []
    for cname in column_names:
        target = cname.rstrip(".")
        for w in header_band:
            if w["text"].rstrip(".") == target:
                col_xs.append((cname, w["x0"]))
                break

    if len(col_xs) != len(column_names):
        return []

    # Establish column boundaries: column N spans [x_start_N, x_start_N+1)
    boundaries = [x for _, x in col_xs]
    # Add a sentinel for the rightmost edge
    boundaries.append(page.width + 1)

    def x_to_col(x: float) -> int:
        for i in range(len(col_xs)):
            if boundaries[i] <= x < boundaries[i + 1]:
                return i
        return len(col_xs) - 1

    # Bucket words below the header but above the page footer.
    # Footer = page-number line + "GheOP3S-tool – Versie ..." copyright, both at
    # the very bottom of the page (within ~30 px of bottom).
    footer_margin = 40  # px from bottom
    footer_y = page.height - footer_margin
    body_words = [w for w in words if w["top"] > header_y + 4 and w["top"] < footer_y]

    # Group into rows by Y position with a small tolerance
    body_words.sort(key=lambda w: (w["top"], w["x0"]))
    rows: list[list[list[str]]] = []  # rows[i][col] = list of words
    current_row: list[list[str]] | None = None
    current_y: float | None = None
    for w in body_words:
        if current_y is None or abs(w["top"] - current_y) > 4:
            current_row = [[] for _ in col_xs]
            rows.append(current_row)
            current_y = w["top"]
        col = x_to_col(w["x0"])
        current_row[col].append(w["text"])

    # Join words in each cell with spaces
    return [[" ".join(cells) for cells in row] for row in rows]


def parse_position_rows(
    rows: list[list[str]],
    has_comorbidity: bool,
    has_alternative: bool,
) -> list[dict[str, str]]:
    """
    Group position-extracted rows into criteria. A new criterion starts whenever
    column 0 (Nr) contains a numeric value; subsequent rows with empty Nr append.
    """
    criteria: list[dict[str, str]] = []
    current: dict[str, str] | None = None

    def stash():
        nonlocal current
        if current is not None and current.get("nr"):
            for k in ("criterium", "rationale", "alternatief", "comorbiditeit"):
                current[k] = clean_cell(current.get(k, ""))
            criteria.append(current)
        current = None

    for row in rows:
        if not row:
            continue
        nr_cell = (row[0] or "").strip()
        if is_criterion_start_value(nr_cell):
            stash()
            current = {"nr": nr_cell, "criterium": "", "rationale": "", "alternatief": "", "comorbiditeit": ""}
            idx = 1
            if idx < len(row):
                current["criterium"] = row[idx]
            idx += 1
            if has_comorbidity:
                if idx < len(row):
                    current["comorbiditeit"] = row[idx]
                idx += 1
            if idx < len(row):
                current["rationale"] = row[idx]
            idx += 1
            if has_alternative and idx < len(row):
                current["alternatief"] = row[idx]
            continue

        if current is None:
            continue
        idx = 1
        if idx < len(row) and row[idx]:
            current["criterium"] = (current["criterium"] + " " + row[idx]).strip()
        idx += 1
        if has_comorbidity:
            if idx < len(row) and row[idx]:
                current["comorbiditeit"] = (current["comorbiditeit"] + " " + row[idx]).strip()
            idx += 1
        if idx < len(row) and row[idx]:
            current["rationale"] = (current["rationale"] + " " + row[idx]).strip()
        idx += 1
        if has_alternative and idx < len(row) and row[idx]:
            current["alternatief"] = (current["alternatief"] + " " + row[idx]).strip()

    stash()
    return criteria


def parse_list_table(
    rows: list[list[str | None]],
    has_comorbidity: bool,
    has_alternative: bool,
) -> list[dict[str, str]]:
    """
    Group consecutive table rows into criteria. A new criterion starts whenever
    the first column (Nr) contains a numeric value.

    Column mapping per list:
        lijst 1, 3, 5: [Nr, Criterium, Rationale, Alternatief]
        lijst 2:       [Nr, Criterium, Comorbiditeit, Rationale, Alternatief]
        lijst 4:       [Nr, Criterium, Rationale]
    """
    expected_cols = 4
    if has_comorbidity:
        expected_cols = 5
    elif not has_alternative:
        expected_cols = 3

    criteria: list[dict[str, str]] = []
    current: dict[str, str] | None = None

    def stash():
        nonlocal current
        if current is not None:
            for k in ("criterium", "rationale", "alternatief", "comorbiditeit"):
                current[k] = clean_cell(current.get(k, ""))
            if current.get("nr"):
                criteria.append(current)
        current = None

    for row in rows:
        if not row:
            continue
        # Pad to expected length so indexing is safe.
        row = list(row) + [None] * (expected_cols - len(row))
        # Recover packed single-cell rows.
        packed = split_packed_row(row, expected_cols)
        if packed is not None:
            row = packed

        nr_cell = (row[0] or "").strip()
        # Skip header rows.
        if nr_cell.lower() in {"nr.", "nr", "criterium"}:
            continue

        if is_criterion_start_value(nr_cell):
            stash()
            current = {"nr": nr_cell}
            i = 1
            current["criterium"] = (row[i] or ""); i += 1
            if has_comorbidity:
                current["comorbiditeit"] = (row[i] or ""); i += 1
            current["rationale"] = (row[i] or ""); i += 1
            if has_alternative:
                current["alternatief"] = (row[i] or "")
            else:
                current["alternatief"] = ""
            continue

        # Continuation row — append non-empty cells to the matching field.
        if current is None:
            continue
        i = 1
        if row[i]:
            current["criterium"] = (current.get("criterium", "") + " " + row[i]).strip()
        i += 1
        if has_comorbidity:
            if row[i]:
                current["comorbiditeit"] = (current.get("comorbiditeit", "") + " " + row[i]).strip()
            i += 1
        if row[i]:
            current["rationale"] = (current.get("rationale", "") + " " + row[i]).strip()
        i += 1
        if has_alternative and i < len(row) and row[i]:
            current["alternatief"] = (current.get("alternatief", "") + " " + row[i]).strip()

    stash()
    return criteria


# ---------------------------------------------------------------------------
# Drug + class extraction
# ---------------------------------------------------------------------------

def load_known_ingredients() -> dict[str, str]:
    """
    Map lowercase ingredient slugs / names to their canonical name (for wikilinks).
    Returns: {match_term_lowercase: 'display name'}
    """
    out: dict[str, str] = {}
    if not INN_DIR.exists():
        return out
    for p in INN_DIR.glob("*.md"):
        slug = p.stem
        # Derive a display name from the file's H1 if present, else from the slug
        first_line = ""
        try:
            text = p.read_text(encoding="utf-8")
            m = re.search(r"^# (.+)$", text, re.MULTILINE)
            if m:
                first_line = m.group(1).strip()
        except Exception:  # noqa: BLE001
            pass
        display = first_line or slug.replace("-", " ")
        out[display.lower()] = display
        # Also index by slug-derived spaceform
        out[slug.replace("-", " ").lower()] = display
        # And by base name (drop salt-form suffixes after the first space)
        base = display.split(" ")[0].lower()
        if base and base not in out:
            out[base] = display
    return out


def find_mentioned_ingredients(text: str, known: dict[str, str]) -> list[str]:
    """Return canonical INN display names mentioned in text (case-insensitive, word-boundary)."""
    if not text:
        return []
    lower = text.lower()
    hits: list[str] = []
    seen: set[str] = set()
    for term, display in known.items():
        if len(term) < 4:  # avoid noisy 3-letter matches
            continue
        # Word-boundary on either side; allow accents/diacritics naturally.
        pattern = r"\b" + re.escape(term) + r"\b"
        if re.search(pattern, lower):
            if display.lower() not in seen:
                hits.append(display)
                seen.add(display.lower())
    return sorted(hits, key=str.lower)


def find_mentioned_classes(text: str) -> list[str]:
    """
    Return drug-class terms found in `text`. Prefers narrower (longer-string)
    matches: if both "Niet-cardioselectieve β-blokker" and "β-blokker" match,
    only the narrower one is kept. Critical to avoid false-positive class
    matches in the screener.
    """
    if not text:
        return []
    hits: list[str] = []
    seen: set[str] = set()
    for term in DRUG_CLASS_TERMS:
        if re.search(re.escape(term), text, re.IGNORECASE):
            key = term.lower()
            if key not in seen:
                hits.append(term)
                seen.add(key)
    # Drop any broader term whose lowercase form is a substring of a longer
    # matched term. E.g. "β-blokker" gets dropped when "Niet-cardioselectieve
    # β-blokker" is also present.
    filtered: list[str] = []
    for term in hits:
        low = term.lower()
        is_subset_of_other = any(
            other.lower() != low and low in other.lower() for other in hits
        )
        if not is_subset_of_other:
            filtered.append(term)
    return filtered


# ---------------------------------------------------------------------------
# Output rendering
# ---------------------------------------------------------------------------

def render_note(c: dict[str, str], lijst: int, ingredients: list[str], classes: list[str]) -> str:
    nr = c["nr"]
    crit = c["criterium"]
    rationale = c.get("rationale", "")
    alternatief = c.get("alternatief", "")
    comorbiditeit = c.get("comorbiditeit", "")

    fm = ["---"]
    fm.append(f"nr: {nr}")
    fm.append(f"lijst: {lijst}")
    fm.append(f'criterium: "{yaml_escape(crit)}"')
    if comorbiditeit:
        fm.append(f'comorbiditeit: "{yaml_escape(comorbiditeit)}"')
    fm.append("stoffen:")
    for ing in ingredients:
        slug = slugify(ing)
        fm.append(
            f'  - "[[knowledgebase/active-ingredient/{slug}\\\\|{yaml_escape(ing)}]]"'
        )
    fm.append("groepen:")
    for g in classes:
        fm.append(f'  - "{yaml_escape(g)}"')
    fm.append("bron: gheops")
    fm.append("status: verrijkt")
    fm.append("tags:")
    fm.append("  - gheops")
    fm.append(f"  - gheops/lijst-{lijst}")
    fm.append("  - status/verrijkt")
    fm.append("---")

    body = [
        "",
        f"# GheOPS Lijst {lijst} · Criterium {nr}",
        "",
        f"**{crit}**",
        "",
    ]
    if comorbiditeit:
        body.append(f"_Comorbiditeit:_ {comorbiditeit}\n")

    body.append("## Rationale\n")
    body.append(rationale or "_(niet beschikbaar)_")
    body.append("")

    if alternatief:
        body.append("## Alternatief\n")
        body.append(alternatief)
        body.append("")

    if ingredients:
        body.append("## Genoemde stoffen\n")
        for ing in ingredients:
            slug = slugify(ing)
            body.append(f"- [[knowledgebase/active-ingredient/{slug}\\|{ing}]]")
        body.append("")
    if classes:
        body.append("## Genoemde geneesmiddelgroepen\n")
        for g in classes:
            body.append(f"- {g}")
        body.append("")

    body.append("## Bron")
    body.append("")
    body.append("GheOP³S-tool versie 2 (maart 2023) — © Eenheid Farmaceutische Zorg, Universiteit Gent. Zie `sources/gheops/`.")
    body.append("")
    return "\n".join(fm) + "\n".join(body)


def render_index(criteria_by_list: dict[int, list[dict[str, str]]]) -> str:
    out = [
        "---",
        "title: GheOPS-index",
        "tags:",
        "  - gheops",
        "  - index",
        "---",
        "",
        "# GheOPS-tool index",
        "",
        "Overzicht van alle criteria uit de GheOP³S-tool (versie 2, maart 2023).",
        "",
        "| Lijst | Onderwerp |",
        "| --- | --- |",
        "| 1 | Potentieel ongeschikte medicatie onafhankelijk van comorbiditeit |",
        "| 2 | Potentieel ongeschikte medicatie afhankelijk van comorbiditeit |",
        "| 3 | Potentieel ontbrekende medicatie |",
        "| 4 | Geneesmiddelinteracties relevant voor oudere personen |",
        "| 5 | Farmaceutische zorggerelateerde criteria |",
        "",
    ]
    for lijst in sorted(criteria_by_list):
        out.append(f"## Lijst {lijst}")
        out.append("")
        for c in criteria_by_list[lijst]:
            short = (c["criterium"][:90] + "…") if len(c["criterium"]) > 90 else c["criterium"]
            slug = slugify(f"{c['nr']}-{c['criterium']}", maxlen=60)
            out.append(f"- [[knowledgebase/gheops/lijst-{lijst}/{slug}\\|{c['nr']}. {short}]]")
        out.append("")
    return "\n".join(out)


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def main() -> None:
    if not GHEOPS_PDF.exists():
        raise SystemExit(f"Cannot find GheOPS PDF at {GHEOPS_PDF}")
    GHEOPS_DIR.mkdir(parents=True, exist_ok=True)

    # Clean prior generation so renumbering doesn't leave stale files
    for p in GHEOPS_DIR.rglob("*.md"):
        if p.name != "index.md":
            p.unlink()

    known_ingredients = load_known_ingredients()
    print(f"[ing] loaded {len(known_ingredients)} ingredient match terms")

    criteria_by_list: dict[int, list[dict[str, str]]] = defaultdict(list)

    # Per list we collect rows from ALL pages first, then parse once. This way
    # continuation rows at the start of a follow-up page correctly append to the
    # criterion that started on the previous page.
    rows_by_list: dict[int, list[list[str]]] = defaultdict(list)

    with pdfplumber.open(GHEOPS_PDF) as pdf:
        for page_idx, page in enumerate(pdf.pages):
            page_num = page_idx + 1
            lijst = PAGE_LIST_MAP.get(page_num)
            if lijst is None:
                continue
            if lijst == 2:
                cols = ["Nr.", "Criterium", "Comorbiditeit", "Rationale", "Alternatief"]
            elif lijst == 4:
                cols = ["Nr.", "Criterium", "Rationale"]
            else:
                cols = ["Nr.", "Criterium", "Rationale", "Alternatief"]
            page_rows = extract_columns_by_position(page, cols)
            if not page_rows:
                # Some continuation pages don't repeat the header — fall back to
                # the table-extract path so we don't lose them.
                tables = page.extract_tables()
                if tables:
                    for table in tables:
                        if lijst == 2:
                            parsed = parse_list_table(table, has_comorbidity=True, has_alternative=True)
                        elif lijst == 4:
                            parsed = parse_list_table(table, has_comorbidity=False, has_alternative=False)
                        else:
                            parsed = parse_list_table(table, has_comorbidity=False, has_alternative=True)
                        for c in parsed:
                            criteria_by_list[lijst].append(c)
                continue
            rows_by_list[lijst].extend(page_rows)

    # Parse the accumulated rows per list
    for lijst, rows in rows_by_list.items():
        if lijst == 2:
            parsed = parse_position_rows(rows, has_comorbidity=True, has_alternative=True)
        elif lijst == 4:
            parsed = parse_position_rows(rows, has_comorbidity=False, has_alternative=False)
        else:
            parsed = parse_position_rows(rows, has_comorbidity=False, has_alternative=True)
        # Merge with anything already collected via fallback
        criteria_by_list[lijst].extend(parsed)

    # Write notes
    out_count = 0
    for lijst, items in criteria_by_list.items():
        d = GHEOPS_DIR / f"lijst-{lijst}"
        d.mkdir(parents=True, exist_ok=True)
        for c in items:
            # Clean rationale-bleed from the criterium NAME so downstream
            # matching is precise.
            c["criterium"] = trim_criterium_name(c.get("criterium", ""))
            # Stoffen is sourced from the criterium NAME ONLY — drugs in
            # rationale/alternatief are usually alternatives or context, not
            # the subject of the criterium.
            ingredients = find_mentioned_ingredients(
                c.get("criterium", ""),
                known_ingredients,
            )
            # Groepen comes from the criterium NAME only for the same reason as
            # stoffen: rationale-mentioned classes are usually context, not the
            # subject of the criterium. Avoids matching "PPI" for an opioïd
            # criterium just because the rationale mentions PPIs as adjunct.
            classes = find_mentioned_classes(c.get("criterium", ""))
            slug = slugify(f"{c['nr']}-{c['criterium']}", maxlen=60)
            (d / f"{slug}.md").write_text(render_note(c, lijst, ingredients, classes), encoding="utf-8")
            out_count += 1

    (GHEOPS_DIR / "index.md").write_text(render_index(criteria_by_list), encoding="utf-8")

    print(f"[done] {out_count} criteria geschreven onder {GHEOPS_DIR}")
    for lijst, items in sorted(criteria_by_list.items()):
        print(f"  Lijst {lijst}: {len(items)} criteria")


if __name__ == "__main__":
    main()
