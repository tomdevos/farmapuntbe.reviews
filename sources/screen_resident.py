"""
Screen one or more residents against the GheOPS criteria.

For each bewoner the script:
  1. Loads the set of active-ingredient slugs from their note (from the
     'Actief bestanddeel' column we render in build_resident_notes.py).
  2. Walks every knowledgebase/gheops/*/*.md file and decides whether it
     matches that bewoner via three mechanisms:
        a) direct stof-match: an INN in the criterium frontmatter `stoffen`
           equals an INN of the bewoner;
        b) text-match: the bewoner's INN base name occurs as a word in the
           criterium's title/rationale/alternatief;
        c) group-match: the criterium's `groepen` field contains a class
           (PPI, NSAID, β-blokker, …) for which the bewoner has at least one
           ingredient (uses a small hand-curated CLASS_INGREDIENTS map).
  3. For Lijst 4 (drug-drug interactions) we additionally require matches on
     BOTH sides of a '+' in the criterium title — otherwise it's not a real
     interaction hit.
  4. Hits are written into the bewoner's `## Reviews` section under a dated
     subheading `### <YYYY-MM-DD> — GheOPS-screening`. Repeated runs on the
     same date replace that day's block in place; older runs are kept.

Usage:
    python3 sources/screen_resident.py <bewoner-slug>         # one bewoner
    python3 sources/screen_resident.py --afdeling marga       # whole afdeling
    python3 sources/screen_resident.py --all                  # whole carecenter
    python3 sources/screen_resident.py --dry-run --all        # preview only
    python3 sources/screen_resident.py --date 2026-05-07 ...  # override date
"""

from __future__ import annotations

import argparse
import re
import unicodedata
from collections import defaultdict
from datetime import date
from pathlib import Path

VAULT_ROOT = Path(__file__).resolve().parent.parent
CNK_DIR = VAULT_ROOT / "knowledgebase" / "cnk"
INN_DIR = VAULT_ROOT / "knowledgebase" / "active-ingredient"
GHEOPS_DIR = VAULT_ROOT / "knowledgebase" / "gheops"
CARECENTER_DIR = VAULT_ROOT / "carecenter"


# ---------------------------------------------------------------------------
# Drug-class → INN mapping. Hand-curated, focused on what we actually see in
# the parkhof schema. Extend as needed.
# ---------------------------------------------------------------------------

CLASS_INGREDIENTS: dict[str, set[str]] = {
    "ppi": {"pantoprazol", "omeprazol", "esomeprazol", "lansoprazol", "rabeprazol"},
    "nsaid": {
        "ibuprofen", "naproxen", "diclofenac", "ketoprofen", "etoricoxib",
        "celecoxib", "meloxicam", "indometacine", "piroxicam", "nimesulide",
    },
    "β-blokker": {
        "metoprolol", "atenolol", "bisoprolol", "propranolol", "sotalol",
        "nebivolol", "carvedilol", "labetalol", "esmolol", "timolol",
    },
    "betablokker": {
        "metoprolol", "atenolol", "bisoprolol", "propranolol", "sotalol",
        "nebivolol", "carvedilol", "labetalol", "esmolol", "timolol",
    },
    # Narrow subset: ONLY the non-cardioselective β-blokkers, used by criterium
    # 2.27. Cardioselective agents (bisoprolol/metoprolol/atenolol/nebivolol)
    # are deliberately excluded.
    "niet-cardioselectieve β-blokker": {
        "carvedilol", "propranolol", "sotalol", "labetalol", "timolol",
    },
    "niet-cardioselectieve betablokker": {
        "carvedilol", "propranolol", "sotalol", "labetalol", "timolol",
    },
    "statine": {
        "simvastatine", "atorvastatine", "rosuvastatine", "pravastatine",
        "fluvastatine",
    },
    "opioïd": {
        "morfine", "oxycodon", "fentanyl", "tramadol", "tilidine",
        "buprenorfine", "codeïne", "hydromorfon", "methadon", "tapentadol",
        "pethidine",
    },
    "opioïden": {
        "morfine", "oxycodon", "fentanyl", "tramadol", "tilidine",
        "buprenorfine", "codeïne", "hydromorfon", "methadon", "tapentadol",
        "pethidine",
    },
    "benzodiazepine": {
        "alprazolam", "bromazepam", "clobazam", "clonazepam", "diazepam",
        "lorazepam", "oxazepam", "lormetazepam", "temazepam", "triazolam",
        "midazolam", "flurazepam", "nitrazepam",
    },
    "z-medicatie": {"zolpidem", "zopiclon", "zaleplon"},
    "antipsychoticum": {
        "haloperidol", "risperidon", "quetiapine", "olanzapine", "aripiprazol",
        "clozapine", "paliperidon", "pipamperon", "sulpiride", "tiapride",
        "amisulpride", "promazine", "zuclopenthixol",
    },
    "anticholinergicum": {
        "tolterodine", "oxybutynine", "solifenacine", "scopolamine",
        "butylhyoscine", "trospium", "fesoterodine", "darifenacine",
        "biperiden", "procyclidine",
    },
    "raas-inhibitor": {
        "captopril", "enalapril", "lisinopril", "perindopril", "ramipril",
        "fosinopril", "quinapril", "trandolapril",
        "losartan", "valsartan", "irbesartan", "candesartan", "olmesartan",
        "telmisartan", "eprosartan", "azilsartan",
    },
    "ace-inhibitor": {
        "captopril", "enalapril", "lisinopril", "perindopril", "ramipril",
        "fosinopril", "quinapril", "trandolapril",
    },
    "sartaan": {
        "losartan", "valsartan", "irbesartan", "candesartan", "olmesartan",
        "telmisartan", "eprosartan", "azilsartan",
    },
    "lisdiureticum": {"bumetanide", "furosemide", "torasemide"},
    "thiazide": {
        "hydrochloorthiazide", "chloortalidon", "indapamide", "metolazon",
        "bendroflumethiazide",
    },
    "kaliumsparend diureticum": {"amiloride", "spironolacton", "eplerenon", "triamtereen"},
    "anticoagulantia": {
        "warfarine", "acenocoumarol", "fenprocoumon",
        "apixaban", "rivaroxaban", "edoxaban", "dabigatran",
        "enoxaparine", "nadroparine", "dalteparine", "tinzaparine",
        "fondaparinux",
    },
    "doac": {"apixaban", "rivaroxaban", "edoxaban", "dabigatran"},
    "antiaggregans": {
        "acetylsalicylzuur", "clopidogrel", "ticagrelor", "prasugrel",
        "dipyridamol",
    },
    "antiaggregantia": {
        "acetylsalicylzuur", "clopidogrel", "ticagrelor", "prasugrel",
        "dipyridamol",
    },
    "calciumantagonist": {
        "amlodipine", "nifedipine", "felodipine", "lercanidipine",
        "verapamil", "diltiazem", "nicardipine", "nimodipine", "lacidipine",
    },
    "corticosteroïd": {
        "prednisolon", "prednison", "methylprednisolon", "dexamethason",
        "hydrocortison", "betamethason", "triamcinolon",
    },
    "corticosteroïden": {
        "prednisolon", "prednison", "methylprednisolon", "dexamethason",
        "hydrocortison", "betamethason", "triamcinolon",
    },
    "macrolide": {"azitromycine", "claritromycine", "erythromycine", "roxitromycine"},
    "ssri": {
        "fluoxetine", "paroxetine", "sertraline", "citalopram", "escitalopram",
        "fluvoxamine",
    },
    "antidepressivum": {
        "fluoxetine", "paroxetine", "sertraline", "citalopram", "escitalopram",
        "fluvoxamine", "mirtazapine", "venlafaxine", "duloxetine", "amitriptyline",
        "nortriptyline", "trazodon", "bupropion", "agomelatine", "vortioxetine",
    },
    "tricyclisch antidepressivum": {
        "amitriptyline", "nortriptyline", "clomipramine", "imipramine",
        "doxepine", "maprotiline",
    },
    "tca": {
        "amitriptyline", "nortriptyline", "clomipramine", "imipramine",
        "doxepine", "maprotiline",
    },
    # NB: GheOPS criterium 1 ("Centraal werkende antihypertensiva") is the only
    # one that names the broad class "antihypertensiva" — but it specifically
    # targets centrally-acting agents. We narrow the class to that subset to
    # avoid false-positive matches against ACE-inhibitors, calcium antagonists
    # or β-blokkers that are mentioned elsewhere as "broader antihypertensiva".
    "antihypertensiva": {
        "clonidine", "moxonidine", "methyldopa", "guanfacine",
    },
    "bisfosfonaat": {
        "alendronaat", "risedronaat", "ibandronaat", "zoledronaat",
        "etidronaat", "pamidronaat",
    },
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def slugify(text: str) -> str:
    nfkd = unicodedata.normalize("NFKD", str(text))
    ascii_text = nfkd.encode("ascii", "ignore").decode("ascii").lower()
    return re.sub(r"[^a-z0-9]+", "-", ascii_text).strip("-")


_AI_LINK_RE = re.compile(
    r"\[\[knowledgebase/active-ingredient/([^\\|\]]+)\\*\|([^\]]+)\]\]"
)


def extract_active_ingredients_from_resident(path: Path) -> list[tuple[str, str]]:
    """Returns list of (slug, display) tuples for a bewoner's medication list."""
    text = path.read_text(encoding="utf-8")
    seen: set[str] = set()
    out: list[tuple[str, str]] = []
    for slug, display in _AI_LINK_RE.findall(text):
        if slug in seen:
            continue
        seen.add(slug)
        out.append((slug, display.strip()))
    return out


def parse_gheops_frontmatter(text: str) -> dict:
    """Tiny YAML-frontmatter parser tailored to our GheOPS output."""
    m = re.match(r"\A---\n(.*?)\n---\n", text, re.DOTALL)
    if not m:
        return {}
    fm: dict[str, object] = {}
    current_key: str | None = None
    for line in m.group(1).splitlines():
        if line.startswith("  - "):
            if current_key is None:
                continue
            item = line[4:].strip().strip('"')
            existing = fm.get(current_key)
            if not isinstance(existing, list):
                fm[current_key] = []
            fm[current_key].append(item)  # type: ignore[union-attr]
            continue
        if ":" not in line:
            continue
        key, _, val = line.partition(":")
        key = key.strip()
        val = val.strip()
        current_key = key
        if val == "":
            fm[key] = []
        else:
            fm[key] = val.strip('"')
    return fm


def load_gheops_criteria() -> list[dict]:
    """
    Each dict has: nr, lijst, criterium, rationale, alternatief, comorbiditeit,
    stoffen_slugs (set), groepen (list lowercased), file_path.
    """
    out: list[dict] = []
    for md in sorted(GHEOPS_DIR.rglob("*.md")):
        if md.name == "index.md":
            continue
        text = md.read_text(encoding="utf-8")
        fm = parse_gheops_frontmatter(text)
        # Extract stoffen slugs from wikilinks in the frontmatter list
        stoffen_slugs: set[str] = set()
        for item in (fm.get("stoffen") or []):
            for m in _AI_LINK_RE.finditer(item):
                stoffen_slugs.add(m.group(1))
        groepen = [str(g).lower() for g in (fm.get("groepen") or [])]
        # Body: pull rationale and alternatief sections for text-match purposes
        body = text[text.find("---", 3) + 3:] if text.startswith("---") else text
        out.append({
            "nr": int(fm.get("nr") or 0),
            "lijst": int(fm.get("lijst") or 0),
            "criterium": str(fm.get("criterium") or ""),
            "comorbiditeit": str(fm.get("comorbiditeit") or ""),
            "rationale": _section(body, "Rationale"),
            "alternatief": _section(body, "Alternatief"),
            "stoffen_slugs": stoffen_slugs,
            "groepen": groepen,
            "file_path": md,
        })
    return out


def _section(body: str, name: str) -> str:
    """Extract the text under a `## <name>` heading until the next heading."""
    pattern = re.compile(rf"##\s+{re.escape(name)}\b\s*\n(.*?)(?=\n##\s|\Z)", re.DOTALL)
    m = pattern.search(body)
    if not m:
        return ""
    return m.group(1).strip()


def base_inn(display_or_slug: str) -> str:
    """Return the 'first word' of an INN, useful for word-boundary matching."""
    cleaned = (display_or_slug or "").lower().replace("-", " ").strip()
    parts = cleaned.split()
    return parts[0] if parts else ""


# ---------------------------------------------------------------------------
# Matching
# ---------------------------------------------------------------------------

def match_criterion(
    criterion: dict,
    bewoner_inns: list[tuple[str, str]],
) -> list[tuple[str, str]]:
    """
    Returns list of (slug, display) ingredient pairs that matched this criterion.
    Empty list = no match.
    """
    matches: dict[str, str] = {}
    bewoner_bases = {base_inn(display): (slug, display) for slug, display in bewoner_inns}

    # a) Direct stof match — INN in criterium frontmatter `stoffen` overlaps bewoner
    for slug, display in bewoner_inns:
        if slug in criterion["stoffen_slugs"]:
            matches[slug] = display

    # b) Text match — bewoner's INN base word appears in the criterium NAME.
    # We deliberately do NOT search in rationale/alternatief because those often
    # mention drugs as alternatives or context (e.g. paracetamol in 'Opioïden'
    # criterium's alternatief), which produces false positives.
    crit_name = criterion["criterium"].lower()
    if crit_name:
        for base, (slug, display) in bewoner_bases.items():
            if len(base) < 5:  # avoid noise (very short names)
                continue
            if re.search(rf"\b{re.escape(base)}\b", crit_name):
                matches[slug] = display

    # c) Group match — criterium has groepen, bewoner has any matching ingredient
    for groep in criterion["groepen"]:
        members = CLASS_INGREDIENTS.get(groep.lower())
        if not members:
            continue
        for base, (slug, display) in bewoner_bases.items():
            if base in members:
                matches[slug] = display

    return list(matches.items())


def is_interaction_hit(criterion: dict, matched_pairs: list[tuple[str, str]]) -> bool:
    """
    For Lijst 4 (interactions), require matches on BOTH sides of a '+' split.
    Each side may itself match either a specific drug or a drug class.
    """
    if criterion["lijst"] != 4:
        return True
    crit_text = criterion["criterium"]
    if "+" not in crit_text:
        # Not a real pairwise interaction text (often truncated criterium-name).
        # Require multiple matched ingredients before treating it as an
        # interaction hit, otherwise it's almost certainly noise.
        return len(matched_pairs) >= 2
    sides = [s.strip() for s in crit_text.split("+")]
    # For each side, find at least one matching bewoner ingredient.
    side_hits: list[bool] = []
    for side in sides:
        side_lower = side.lower()
        hit_this_side = False
        for slug, display in matched_pairs:
            # Match if any of: base INN word appears in this side, or the class name does
            b = base_inn(display)
            if b and re.search(rf"\b{re.escape(b)}\b", side_lower):
                hit_this_side = True
                break
        if not hit_this_side:
            # Also accept group-based side hit: if a drug class name in this side
            # corresponds to a class with at least one matched ingredient.
            for groep, members in CLASS_INGREDIENTS.items():
                if not re.search(rf"\b{re.escape(groep)}\b", side_lower):
                    continue
                if any(base_inn(display) in members for _, display in matched_pairs):
                    hit_this_side = True
                    break
        side_hits.append(hit_this_side)
    return all(side_hits) and len(side_hits) >= 2


# ---------------------------------------------------------------------------
# Rendering
# ---------------------------------------------------------------------------

def render_hit(criterion: dict, matches: list[tuple[str, str]]) -> list[str]:
    lijst = criterion["lijst"]
    nr = criterion["nr"]
    slug = criterion["file_path"].stem
    matched_str = ", ".join(d for _, d in matches) or "(groep-match)"
    lines = []
    lines.append(
        f"- **Lijst {lijst}, Criterium {nr}: {criterion['criterium']}** "
        f"— matched op _{matched_str}_"
    )
    if criterion["comorbiditeit"]:
        lines.append(f"    - _Comorbiditeit:_ {criterion['comorbiditeit']}")
    if criterion["rationale"]:
        lines.append(f"    - _Rationale:_ {criterion['rationale']}")
    if criterion["alternatief"]:
        lines.append(f"    - _Alternatief:_ {criterion['alternatief']}")
    lines.append(
        f"    - _Bron:_ [[knowledgebase/gheops/lijst-{lijst}/{slug}\\|GheOPS {lijst}.{nr}]]"
    )
    return lines


def build_review_block(
    bewoner_path: Path,
    matches_by_lijst: dict[int, list[tuple[dict, list[tuple[str, str]]]]],
    run_date: str,
) -> str:
    out = [f"### {run_date} — GheOPS-screening", ""]
    total = sum(len(v) for v in matches_by_lijst.values())
    if total == 0:
        out.append("Geen GheOPS-criteria gematched op de huidige medicatielijst.")
        out.append("")
        return "\n".join(out)
    out.append(
        f"Automatisch gegenereerde voorstellen op basis van de GheOPS-tool "
        f"(versie 2, maart 2023). **{total} mogelijke aandachtspunt(en).** "
        "Deze sectie is een hulpmiddel — de apotheker beoordeelt of een punt relevant is."
    )
    out.append("")
    for lijst, items in sorted(matches_by_lijst.items()):
        out.append(f"**Lijst {lijst}**")
        out.append("")
        for criterion, matches in sorted(items, key=lambda x: x[0]["nr"]):
            out.extend(render_hit(criterion, matches))
        out.append("")
    return "\n".join(out).rstrip() + "\n"


_DATED_BLOCK_RE = re.compile(
    r"### (\d{4}-\d{2}-\d{2}) — GheOPS-screening\n(.*?)(?=\n### \d{4}-|\Z)",
    re.DOTALL,
)


def upsert_review_block(text: str, block: str, run_date: str) -> str:
    """
    Insert/replace `### <run_date> — GheOPS-screening` block under `## Reviews`.
    Keeps any other dated blocks intact. Other content under ## Reviews is
    preserved (placed before the dated blocks).
    """
    # Find the Reviews section
    m = re.search(r"^## Reviews\s*\n", text, flags=re.MULTILINE)
    if not m:
        # Append a Reviews section at end of file
        return text.rstrip() + "\n\n## Reviews\n\n" + block + "\n"

    head = text[: m.end()]
    tail = text[m.end():]

    # Find the end of Reviews section (next ## or EOF)
    next_section = re.search(r"\n## \S", tail)
    reviews_body = tail[: next_section.start()] if next_section else tail
    rest = tail[next_section.start():] if next_section else ""

    # Drop any existing block for the same date
    new_body, n = re.subn(
        rf"### {re.escape(run_date)} — GheOPS-screening\n.*?(?=\n### \d{{4}}-|\Z)",
        "",
        reviews_body,
        count=1,
        flags=re.DOTALL,
    )
    new_body = new_body.rstrip()
    if new_body and not new_body.endswith("\n"):
        new_body += "\n"
    new_body += "\n" + block.rstrip() + "\n"

    return head + new_body + ("\n" + rest.lstrip("\n") if rest else "\n")


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def screen_one(path: Path, criteria: list[dict], run_date: str, dry_run: bool) -> int:
    bewoner_inns = extract_active_ingredients_from_resident(path)
    if not bewoner_inns:
        return 0
    matches_by_lijst: dict[int, list[tuple[dict, list[tuple[str, str]]]]] = defaultdict(list)
    for c in criteria:
        m = match_criterion(c, bewoner_inns)
        if not m:
            continue
        if c["lijst"] == 4 and not is_interaction_hit(c, m):
            continue
        matches_by_lijst[c["lijst"]].append((c, m))
    total = sum(len(v) for v in matches_by_lijst.values())

    block = build_review_block(path, matches_by_lijst, run_date)
    name = path.stem
    print(f"  {name:<35s} {len(bewoner_inns):>2} INNs  →  {total} hit(s)")

    if dry_run:
        return total
    text = path.read_text(encoding="utf-8")
    new_text = upsert_review_block(text, block, run_date)
    if new_text != text:
        path.write_text(new_text, encoding="utf-8")
    return total


def gather_targets(args) -> list[Path]:
    if args.bewoner:
        slug = args.bewoner
        matches = list(CARECENTER_DIR.rglob(f"{slug}.md"))
        if not matches:
            raise SystemExit(f"Bewoner '{slug}' niet gevonden onder {CARECENTER_DIR}")
        return matches
    if args.afdeling:
        d = list(CARECENTER_DIR.rglob(args.afdeling))
        # afdeling is a folder name like 'marga'
        out: list[Path] = []
        for cc in CARECENTER_DIR.iterdir():
            cand = cc / args.afdeling
            if cand.exists():
                out.extend(sorted(cand.glob("*.md")))
        if not out:
            raise SystemExit(f"Afdeling '{args.afdeling}' niet gevonden")
        return out
    if args.all:
        return sorted(CARECENTER_DIR.rglob("*.md"))
    raise SystemExit("Specifieer een bewoner, --afdeling, of --all")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("bewoner", nargs="?", help="bewoner slug (e.g. 'd-hooghe-yolande')")
    parser.add_argument("--afdeling", help="all residents in this afdeling (slug)")
    parser.add_argument("--all", action="store_true", help="all residents in the vault")
    parser.add_argument("--date", default=date.today().isoformat(), help="run-date for the review block (YYYY-MM-DD)")
    parser.add_argument("--dry-run", action="store_true", help="print hits without writing")
    args = parser.parse_args()

    if not GHEOPS_DIR.exists():
        raise SystemExit(f"GheOPS kennisbank niet gevonden onder {GHEOPS_DIR}")

    criteria = load_gheops_criteria()
    print(f"[gheops] {len(criteria)} criteria geladen")

    targets = gather_targets(args)
    print(f"[screen] {len(targets)} bewoner(s) — datum {args.date}")

    total = 0
    for path in targets:
        total += screen_one(path, criteria, args.date, args.dry_run)
    print(f"\n[done] {total} totaal hits over {len(targets)} bewoners")


if __name__ == "__main__":
    main()
