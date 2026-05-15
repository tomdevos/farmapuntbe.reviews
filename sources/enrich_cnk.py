"""
Enrich CNK knowledgebase notes with active ingredient(s), brand name and ATC info.

Two data sources, queried in order:

  1. Medipim products.csv (sources/products.csv) — PRIMARY source. Local
     export covering the residential-care medication universe, including
     compounded "MB" products (CNKs starting with 9999...), medical devices
     and food supplements. Medipim's metadata (naam, merk, categorie,
     is_robot) is taken as canonical.

  2. BCFI EMD database (https://www.bcfi.be/nl/download) — supplementary.
     Used to fill in the active ingredient when Medipim has no AI for a
     given CNK, and as the sole source for CNKs not in Medipim. Monthly
     CSV export with MPP (CNK → brand), SAM (CNK → active ingredient
     links), INNM (INN names) and MP (brand metadata) tables.

Usage:
    python3 enrich_cnk.py <CNK>                # enrich one CNK
    python3 enrich_cnk.py --all-unenriched     # enrich anything not fully verrijkt
    python3 enrich_cnk.py --reprocess-all      # re-process every CNK (incl. already verrijkt)
    python3 enrich_cnk.py --cnk-list cnks.txt  # enrich a list (one CNK per line)
    python3 enrich_cnk.py --refresh-emd        # force re-download of the EMD zip
    python3 enrich_cnk.py --dry-run <CNK>      # show what would change; write nothing
    python3 enrich_cnk.py --no-medipim <CNK>   # skip Medipim (BCFI-only)
    python3 enrich_cnk.py --no-bcfi <CNK>      # skip BCFI (Medipim-only)

For each enriched CNK we update knowledgebase/cnk/<CNK>.md:
    - status: verrijkt  (or 'deels-verrijkt' if only brand/name found, no INN)
    - bron: medipim | medipim+bcfi | bcfi
    - actief_bestanddeel: list of wikilinks to active-ingredient/<inn>.md
    - merknaam, categorie, is_robot (filled where available)
    - For every new INN, create knowledgebase/active-ingredient/<inn>.md as a stub.
"""

from __future__ import annotations

import argparse
import csv
import io
import re
import shutil
import sys
import unicodedata
import urllib.request
import zipfile
from collections import defaultdict
from datetime import date
from pathlib import Path
from typing import Iterable

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

VAULT_ROOT = Path(__file__).resolve().parent.parent  # sources/ → vault root
CNK_DIR = VAULT_ROOT / "knowledgebase" / "cnk"
INN_DIR = VAULT_ROOT / "knowledgebase" / "active-ingredient"
CACHE_DIR = VAULT_ROOT / "sources" / ".cache" / "bcfi"
MEDIPIM_CSV = VAULT_ROOT / "sources" / "products.csv"

# EMD download URL — YYMM tag updates monthly around the 18th.
EMD_DOWNLOAD_BASE = "https://www.bcfi.be/nl/downloads/file"
EMD_LANG = "Nl"  # 'Nl' or 'Fr'

# Files inside the zip that we actually need. BCFI's EMD zip uses different file
# names than the documentation suggests: the INNM table is in `Stof.csv` and the
# SAM table in `Sam.csv` (case may also differ across months). We map each
# logical role to a list of candidate names.
NEEDED_CSV: dict[str, tuple[str, ...]] = {
    "MPP":  ("MPP.csv", "Mpp.csv"),
    "MP":   ("MP.csv", "Mp.csv"),
    "SAM":  ("SAM.csv", "Sam.csv"),
    "INNM": ("INNM.csv", "Stof.csv", "STOF.csv"),
    # ATCDPP is a separate download (not in the EMD zip). Optional.
    "ATC":  ("ATCDPP.csv",),
}


# ---------------------------------------------------------------------------
# Slug + frontmatter helpers
# ---------------------------------------------------------------------------

def slugify(text: str) -> str:
    nfkd = unicodedata.normalize("NFKD", str(text))
    ascii_text = nfkd.encode("ascii", "ignore").decode("ascii").lower()
    ascii_text = re.sub(r"[^a-z0-9]+", "-", ascii_text).strip("-")
    return ascii_text or "unknown"


def yaml_escape(text: str) -> str:
    return text.replace("\\", "\\\\").replace('"', '\\"')


# ---------------------------------------------------------------------------
# EMD download / cache
# ---------------------------------------------------------------------------

def emd_zip_url(yymm: str) -> str:
    name = f"/csv4Emd_{EMD_LANG}_{yymm}A.zip"
    return f"{EMD_DOWNLOAD_BASE}?type=EMD&name={name}"


def current_yymm_candidates() -> list[str]:
    """Try this month first, then previous, then two months back."""
    today = date.today()
    yy, mm = today.year % 100, today.month
    candidates = []
    for _ in range(4):
        candidates.append(f"{yy:02d}{mm:02d}")
        mm -= 1
        if mm == 0:
            mm = 12
            yy -= 1
    return candidates


def fetch_emd_zip(force: bool = False) -> Path:
    """Download and cache the most recent EMD zip we can find."""
    CACHE_DIR.mkdir(parents=True, exist_ok=True)

    if not force:
        # Use newest cached zip if present
        cached = sorted(CACHE_DIR.glob("emd_*.zip"))
        if cached:
            print(f"[cache] using {cached[-1].name}")
            return cached[-1]

    last_err = None
    for yymm in current_yymm_candidates():
        url = emd_zip_url(yymm)
        target = CACHE_DIR / f"emd_{yymm}.zip"
        print(f"[fetch] {url}")
        try:
            req = urllib.request.Request(
                url,
                headers={"User-Agent": "farmapuntbe.reviews/1.0 (medication review system)"},
            )
            with urllib.request.urlopen(req, timeout=60) as resp:
                if resp.status != 200:
                    raise RuntimeError(f"HTTP {resp.status}")
                data = resp.read()
            if len(data) < 100_000:
                # Probably an error page; valid zip is ~1.6 MiB
                raise RuntimeError(f"response too small ({len(data)} bytes), likely not a zip")
            target.write_bytes(data)
            print(f"[fetch] cached {target.name} ({len(data) // 1024} KiB)")
            return target
        except Exception as e:  # noqa: BLE001
            last_err = e
            print(f"[fetch] failed for {yymm}: {e}")
            continue
    raise RuntimeError(f"could not download EMD zip; last error: {last_err}")


def extract_emd_csvs(zip_path: Path) -> dict[str, Path]:
    """
    Extract the CSVs we need to a working folder next to the zip.
    Returns a mapping from logical role (MPP, MP, SAM, INNM, ATC) to extracted file path.
    """
    work = zip_path.parent / zip_path.stem
    work.mkdir(exist_ok=True)
    extracted: dict[str, Path] = {}
    with zipfile.ZipFile(zip_path) as zf:
        names = zf.namelist()
        name_lower_to_actual = {Path(n).name.lower(): n for n in names}
        for role, candidates in NEEDED_CSV.items():
            match = None
            for cand in candidates:
                if cand.lower() in name_lower_to_actual:
                    match = name_lower_to_actual[cand.lower()]
                    break
            if match is None:
                if role != "ATC":  # ATC is optional
                    print(f"[warn] {role} (any of {candidates}) not found in zip")
                continue
            target = work / f"{role}.csv"
            with zf.open(match) as src, open(target, "wb") as dst:
                shutil.copyfileobj(src, dst)
            extracted[role] = target
    return extracted


# ---------------------------------------------------------------------------
# CSV parsing
# ---------------------------------------------------------------------------

def _read_csv(path: Path) -> list[dict[str, str]]:
    """Read a BCFI EMD CSV. They use ';' separator and Latin-1 encoding."""
    # Some BCFI CSVs are UTF-8, some Latin-1. Try utf-8 first, then latin-1.
    for encoding in ("utf-8-sig", "utf-8", "latin-1"):
        try:
            with open(path, encoding=encoding, newline="") as f:
                sample = f.read(4096)
                f.seek(0)
                # Detect separator (';' or ',')
                sep = ";" if sample.count(";") > sample.count(",") else ","
                reader = csv.DictReader(f, delimiter=sep)
                return [row for row in reader]
        except UnicodeDecodeError:
            continue
    raise RuntimeError(f"could not decode {path}")


def normalize_cnk(cnk: str) -> str:
    """BCFI CNK is 7 digits, left-padded with zeros."""
    digits = re.sub(r"\D", "", str(cnk))
    if not digits:
        return ""
    return digits.zfill(7)


def build_index(csv_files: dict[str, Path]) -> dict[str, dict]:
    """
    Returns:
      {
        'mpp_by_cnk': {cnk: mpp_row},
        'mp_by_mpcv': {mpcv: mp_row},
        'sam_by_cnk': {cnk: [sam_row, ...]},
        'innm_by_stofcv': {stofcv: innm_row},
        'atc_by_cnk':   {cnk: atc_row}   # optional, only if ATCDPP.csv present
      }
    """
    idx: dict[str, dict] = {
        "mpp_by_cnk": {},
        "mp_by_mpcv": {},
        "sam_by_cnk": defaultdict(list),
        "innm_by_stofcv": {},
        "atc_by_cnk": {},
    }

    def col(row: dict, *names: str) -> str:
        """Return value for the first matching column name (case-insensitive)."""
        for n in names:
            for k, v in row.items():
                if k and k.lower() == n.lower():
                    return (v or "").strip()
        return ""

    if "MPP" in csv_files:
        for row in _read_csv(csv_files["MPP"]):
            cnk = normalize_cnk(col(row, "MPPCV"))
            if cnk:
                idx["mpp_by_cnk"][cnk] = row

    if "MP" in csv_files:
        for row in _read_csv(csv_files["MP"]):
            mpcv = col(row, "MPCV").strip()
            if mpcv:
                idx["mp_by_mpcv"][mpcv] = row

    if "SAM" in csv_files:
        for row in _read_csv(csv_files["SAM"]):
            cnk = normalize_cnk(col(row, "MPPCV"))
            if cnk:
                idx["sam_by_cnk"][cnk].append(row)

    if "INNM" in csv_files:
        for row in _read_csv(csv_files["INNM"]):
            stof = col(row, "STOFCV", "StofCv").strip()
            if stof:
                idx["innm_by_stofcv"][stof] = row

    if "ATC" in csv_files:
        for row in _read_csv(csv_files["ATC"]):
            cnk = normalize_cnk(col(row, "MPPCV", "mppcv"))
            if cnk:
                idx["atc_by_cnk"][cnk] = row

    return idx


# ---------------------------------------------------------------------------
# Lookup → enrichment payload
# ---------------------------------------------------------------------------

def lookup(cnk: str, idx: dict) -> dict | None:
    """
    Look up a CNK in the BCFI EMD index. Returns enrichment payload or None.
    """
    cnk = normalize_cnk(cnk)
    mpp = idx["mpp_by_cnk"].get(cnk)
    if not mpp:
        return None

    def col(row, *names):
        for n in names:
            for k, v in row.items():
                if k and k.lower() == n.lower():
                    return (v or "").strip()
        return ""

    mpcv = col(mpp, "MPCV")
    mp_row = idx["mp_by_mpcv"].get(mpcv, {})
    brand = col(mp_row, "MPNm", "MPNM")

    ingredients: list[tuple[str, str, str, str, str, str]] = []
    for sam_row in idx["sam_by_cnk"].get(cnk, []):
        stof = col(sam_row, "STOFCV", "StofCV")
        innm_row = idx["innm_by_stofcv"].get(stof, {})
        inn = col(innm_row, "NINNM", "NBASE") or col(sam_row, "Stofnm_")
        if not inn:
            continue
        qty = col(sam_row, "INQ")
        unit = col(sam_row, "INU")
        denom = col(sam_row, "INBASQ")
        denom_unit = col(sam_row, "INBASU")
        ingredients.append((stof, inn, qty, unit, denom, denom_unit))

    atc_row = idx["atc_by_cnk"].get(cnk, {})
    atc_code = col(atc_row, "atc", "ATC")
    atc_label = col(atc_row, "atcnm_n", "ATCNM_N")

    return {
        "cnk": cnk,
        "source": "bcfi",
        "mpp_name": col(mpp, "MPPNM"),
        "brand_name": brand,
        "atc_code": atc_code,
        "atc_label": atc_label,
        "ingredients": ingredients,
    }


# ---------------------------------------------------------------------------
# Medipim fallback source
# ---------------------------------------------------------------------------

def load_medipim_index() -> dict[str, dict[str, str]]:
    """
    Returns: {normalized_cnk: {sku, name, brand, active_ingredient, categorie, is_robot, prescription}}
    Returns empty dict if the file is missing.
    """
    if not MEDIPIM_CSV.exists():
        return {}
    out: dict[str, dict[str, str]] = {}
    with open(MEDIPIM_CSV, encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        for row in reader:
            sku = (row.get("sku") or "").strip()
            if not sku:
                continue
            out[normalize_cnk(sku)] = row
    return out


def medipim_lookup(cnk: str, medipim_idx: dict[str, dict[str, str]]) -> dict | None:
    """Return enrichment payload from Medipim, or None if CNK absent."""
    row = medipim_idx.get(normalize_cnk(cnk))
    if not row:
        return None
    ai = (row.get("active_ingredient") or "").strip()
    ingredients: list[tuple[str, str, str, str, str, str]] = []
    if ai and ai.upper() != "NULL":
        # Medipim stores one ingredient string per product, sometimes with parenthetical
        # extra info like "(extract)" or comma-separated substrains. We keep the full
        # string as-is so the wikilink alias matches the source — splitting heuristics
        # would be error-prone for combination preparations.
        ingredients.append(("", ai, "", "", "", ""))
    return {
        "cnk": normalize_cnk(cnk),
        "source": "medipim",
        "mpp_name": (row.get("name") or "").strip(),
        "brand_name": (row.get("brand") or "").strip(),
        "atc_code": "",
        "atc_label": "",
        "ingredients": ingredients,
        "categorie": (row.get("categorie") or "").strip(),
        "is_robot": (row.get("is_robot") or "").strip(),
        "prescription": (row.get("prescription") or "").strip(),
    }


def merge_payloads(bcfi: dict | None, medipim: dict | None) -> dict | None:
    """
    Combine the two sources with Medipim as primary.

    Strategy:
    - If only one source has the CNK, return it as-is.
    - If both have it: start from Medipim (canonical product metadata) and
      only fall back to BCFI when Medipim is missing the active ingredient.
      In that case bron = "medipim+bcfi".
    - If Medipim already has the active ingredient, BCFI's structured
      ingredient list is intentionally ignored to keep one source of truth
      for the AI string. (For combination preparations this means Medipim's
      single-string representation wins.)
    """
    if medipim and not bcfi:
        return medipim
    if bcfi and not medipim:
        return bcfi
    if not bcfi and not medipim:
        return None

    combined = dict(medipim)  # type: ignore[arg-type]
    # Medipim brand may be empty; BCFI's MPNm is a reasonable fallback
    if not combined.get("brand_name") and bcfi.get("brand_name"):  # type: ignore[union-attr]
        combined["brand_name"] = bcfi["brand_name"]
    # If Medipim has no active ingredient, take BCFI's structured list
    if not combined.get("ingredients") and bcfi.get("ingredients"):  # type: ignore[union-attr]
        combined["ingredients"] = bcfi["ingredients"]
        combined["source"] = "medipim+bcfi"
    else:
        combined["source"] = "medipim"
    # ATC code only comes from BCFI (if it ever shows up)
    if bcfi.get("atc_code"):  # type: ignore[union-attr]
        combined["atc_code"] = bcfi["atc_code"]
        combined["atc_label"] = bcfi.get("atc_label", "")  # type: ignore[union-attr]
    return combined


# ---------------------------------------------------------------------------
# Write CNK note + active-ingredient stubs
# ---------------------------------------------------------------------------

def render_active_ingredient_stub(inn_name: str) -> str:
    return (
        "---\n"
        f'naam: "{yaml_escape(inn_name)}"\n'
        "atc_code: \"\"\n"
        "klasse: \"\"\n"
        "status: nog-niet-verrijkt\n"
        "tags:\n"
        "  - active-ingredient\n"
        "  - status/nog-niet-verrijkt\n"
        "---\n"
        f"\n# {inn_name}\n"
        "\n## Werkingsmechanisme\n\n*Nog niet verrijkt.*\n"
        "\n## Indicaties\n\n*Nog niet verrijkt.*\n"
        "\n## Belangrijkste bijwerkingen\n\n*Nog niet verrijkt.*\n"
        "\n## Interacties\n\n*Nog niet verrijkt.*\n"
        "\n## Bronnen\n\n*Nog niet verrijkt.*\n"
    )


def ensure_ingredient_stub(inn_name: str, dry_run: bool) -> tuple[str, Path, bool]:
    """Ensure an active-ingredient/<slug>.md exists. Returns (slug, path, was_created)."""
    slug = slugify(inn_name)
    INN_DIR.mkdir(parents=True, exist_ok=True)
    path = INN_DIR / f"{slug}.md"
    created = False
    if not path.exists():
        if not dry_run:
            path.write_text(render_active_ingredient_stub(inn_name), encoding="utf-8")
        created = True
    return slug, path, created


_FRONTMATTER_RE = re.compile(r"\A---\n(.*?)\n---\n(.*)", re.DOTALL)


def parse_frontmatter(text: str) -> tuple[dict[str, str | list[str]], str]:
    """
    Very small YAML-frontmatter parser, sufficient for our generated files:
    handles `key: "value"` and `key:` followed by lines of `  - "item"`.
    Returns (dict, body).
    """
    m = _FRONTMATTER_RE.match(text)
    if not m:
        return {}, text
    fm_raw, body = m.group(1), m.group(2)
    data: dict[str, str | list[str]] = {}
    current_key: str | None = None
    for line in fm_raw.splitlines():
        if line.startswith("  - "):
            if current_key is None or not isinstance(data.get(current_key), list):
                if current_key is not None:
                    data[current_key] = []
            item = line[4:].strip().strip('"')
            assert current_key is not None
            existing = data.get(current_key)
            if not isinstance(existing, list):
                data[current_key] = []
            data[current_key].append(item)  # type: ignore[union-attr]
            continue
        if ":" not in line:
            continue
        key, _, val = line.partition(":")
        key = key.strip()
        val = val.strip()
        current_key = key
        if val == "":
            data[key] = []  # placeholder; following indented `- ...` lines will fill it
        else:
            data[key] = val.strip('"')
    return data, body


def format_frontmatter(data: dict[str, str | list[str]]) -> str:
    out = ["---"]
    for key, val in data.items():
        if isinstance(val, list):
            if not val:
                out.append(f"{key}:")
                continue
            out.append(f"{key}:")
            for item in val:
                out.append(f'  - "{yaml_escape(item)}"')
        else:
            out.append(f'{key}: "{yaml_escape(str(val))}"')
    out.append("---")
    return "\n".join(out)


def update_cnk_note(payload: dict, ingredient_slugs: list[tuple[str, str]], dry_run: bool) -> tuple[Path, bool]:
    """
    Update knowledgebase/cnk/<cnk>.md with enriched info.
    ingredient_slugs: list of (slug, displayname) tuples.
    Returns (path, written).
    """
    cnk = payload["cnk"]
    path = CNK_DIR / f"{cnk}.md"
    if not path.exists():
        # Create a minimal frontmatter scaffold using the EMD name
        path.write_text(
            "---\n"
            f'cnk: "{cnk}"\n'
            f'medicatienaam: "{yaml_escape(payload["mpp_name"])}"\n'
            "status: nog-niet-verrijkt\n"
            "tags:\n"
            "  - cnk\n"
            "  - status/nog-niet-verrijkt\n"
            "---\n"
            f"\n# {payload['mpp_name']}\n"
            f"\nCNK: **{cnk}**\n",
            encoding="utf-8",
        )

    existing = path.read_text(encoding="utf-8")
    fm, body = parse_frontmatter(existing)

    # Build active-ingredient wikilinks (escaped pipe for markdown-table safety)
    ingredient_links = [
        f"[[knowledgebase/active-ingredient/{slug}\\|{display}]]"
        for slug, display in ingredient_slugs
    ]

    # Update frontmatter fields
    if ingredient_links:
        fm["actief_bestanddeel"] = ingredient_links
    if payload["atc_code"]:
        fm["atc_code"] = payload["atc_code"]
    if payload["brand_name"]:
        fm["merknaam"] = payload["brand_name"]
    if payload["mpp_name"] and not fm.get("medicatienaam"):
        fm["medicatienaam"] = payload["mpp_name"]
    if payload.get("source"):
        fm["bron"] = payload["source"]
    # Carry over Medipim-only fields when present
    for fm_key, payload_key in (("categorie", "categorie"), ("is_robot", "is_robot")):
        if payload.get(payload_key):
            fm[fm_key] = payload[payload_key]

    # Status: 'verrijkt' if we have at least one active ingredient, otherwise
    # 'deels-verrijkt' (we have brand/name/category but no INN yet).
    new_status = "verrijkt" if ingredient_links else "deels-verrijkt"
    fm["status"] = new_status

    # Update tags
    tags = fm.get("tags", [])
    if not isinstance(tags, list):
        tags = [tags]  # type: ignore[list-item]
    tags = [t for t in tags if t and not t.startswith("status/")]
    if "cnk" not in tags:
        tags.insert(0, "cnk")
    tags.append(f"status/{new_status}")
    fm["tags"] = tags

    # Compose a fresh "Actief bestanddeel" body section (replaces the existing stub section)
    if ingredient_links:
        ing_lines = ["## Actief bestanddeel", ""]
        if len(ingredient_slugs) == 1:
            ing_lines.append(ingredient_links[0])
        else:
            for link, sam_row in zip(
                ingredient_links,
                payload["ingredients"],
            ):
                _, _, qty, unit, denom, denom_unit = sam_row
                dose = ""
                if qty:
                    dose = f" — {qty}{unit or ''}"
                    if denom and denom not in {"0", "0,0000", "0.0000"}:
                        dose += f" / {denom}{denom_unit or ''}"
                ing_lines.append(f"- {link}{dose}")
        if payload["atc_code"]:
            ing_lines.extend(
                ["", f"**ATC-code:** {payload['atc_code']}" + (f" — _{payload['atc_label']}_" if payload["atc_label"] else "")]
            )
        new_section = "\n".join(ing_lines) + "\n"
        body = re.sub(
            r"## Actief bestanddeel\n.*?(?=\n## |\Z)",
            new_section + "\n",
            body,
            count=1,
            flags=re.DOTALL,
        )

    new_text = format_frontmatter(fm) + "\n" + body
    if dry_run:
        print(f"[dry-run] would update {path}")
        print(new_text[:600] + "\n...")
        return path, False
    path.write_text(new_text, encoding="utf-8")
    return path, True


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def gather_targets(args: argparse.Namespace) -> list[str]:
    if args.cnk:
        return [normalize_cnk(args.cnk)]
    if args.cnk_list:
        return [
            normalize_cnk(line.strip())
            for line in Path(args.cnk_list).read_text().splitlines()
            if line.strip()
        ]
    if args.reprocess_all:
        return sorted(p.stem for p in CNK_DIR.glob("*.md"))
    if args.all_unenriched:
        targets: list[str] = []
        for p in sorted(CNK_DIR.glob("*.md")):
            try:
                fm, _ = parse_frontmatter(p.read_text(encoding="utf-8"))
            except Exception:  # noqa: BLE001
                continue
            # Re-process anything that hasn't been fully enriched yet
            if fm.get("status") in ("nog-niet-verrijkt", "deels-verrijkt"):
                targets.append(p.stem)
        return targets
    raise SystemExit("specify a CNK, --cnk-list, --all-unenriched or --reprocess-all")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("cnk", nargs="?", help="single CNK number to enrich")
    parser.add_argument("--cnk-list", help="text file with one CNK per line")
    parser.add_argument("--all-unenriched", action="store_true", help="process every CNK with status (deels-)?nog-niet-verrijkt")
    parser.add_argument("--reprocess-all", action="store_true", help="process every CNK note, regardless of current status")
    parser.add_argument("--refresh-emd", action="store_true", help="force re-download of the BCFI EMD zip")
    parser.add_argument("--no-medipim", action="store_true", help="skip the Medipim primary source")
    parser.add_argument("--no-bcfi", action="store_true", help="skip the BCFI supplementary source")
    parser.add_argument("--dry-run", action="store_true", help="print changes without writing files")
    args = parser.parse_args()

    targets = gather_targets(args)
    print(f"[targets] {len(targets)} CNK(s) to process")

    idx: dict = {"mpp_by_cnk": {}, "mp_by_mpcv": {}, "sam_by_cnk": {}, "innm_by_stofcv": {}, "atc_by_cnk": {}}
    csv_files: dict[str, Path] = {}
    if not args.no_bcfi:
        zip_path = fetch_emd_zip(force=args.refresh_emd)
        csv_files = extract_emd_csvs(zip_path)
        for required in ("MPP", "SAM", "INNM"):
            if required not in csv_files:
                raise SystemExit(f"[fatal] required EMD table {required} missing from zip")
        print(f"[csv] loaded: {sorted(csv_files)}")
        idx = build_index(csv_files)
    else:
        print("[bcfi] skipped (--no-bcfi)")

    if not args.no_bcfi:
        print(
            f"[index] MPP rows={len(idx['mpp_by_cnk'])}  "
            f"MP rows={len(idx['mp_by_mpcv'])}  "
            f"SAM rows={sum(len(v) for v in idx['sam_by_cnk'].values())}  "
            f"INNM rows={len(idx['innm_by_stofcv'])}  "
            f"ATC rows={len(idx['atc_by_cnk'])}"
        )

    medipim_idx: dict[str, dict[str, str]] = {}
    if not args.no_medipim:
        medipim_idx = load_medipim_index()
        if medipim_idx:
            print(f"[medipim] loaded {len(medipim_idx)} rows from {MEDIPIM_CSV.name}")
        else:
            print(f"[medipim] {MEDIPIM_CSV.name} not found — primary source disabled")

    enriched = 0
    partial = 0
    not_found = 0
    new_ingredients_created = 0
    by_source: dict[str, int] = {}

    for cnk in targets:
        bcfi_payload = lookup(cnk, idx) if (not args.no_bcfi and idx["mpp_by_cnk"]) else None
        medipim_payload = medipim_lookup(cnk, medipim_idx) if medipim_idx else None
        payload = merge_payloads(bcfi_payload, medipim_payload)
        if not payload:
            print(f"[miss] {cnk} not found in either BCFI or Medipim")
            not_found += 1
            continue
        slugs: list[tuple[str, str]] = []
        for _, inn_name, *_ in payload["ingredients"]:
            slug, _, was_created = ensure_ingredient_stub(inn_name, args.dry_run)
            slugs.append((slug, inn_name))
            if was_created:
                new_ingredients_created += 1
        path, written = update_cnk_note(payload, slugs, args.dry_run)
        ing_str = ", ".join(d for _, d in slugs) or "(geen actief bestanddeel bekend)"
        src_label = payload.get("source", "?")
        flag = "ok " if slugs else "prt"
        print(f"[{flag}] {cnk}  [{src_label}]  →  {ing_str}")
        by_source[src_label] = by_source.get(src_label, 0) + 1
        if written:
            if slugs:
                enriched += 1
            else:
                partial += 1

    print()
    print(
        f"[summary] verrijkt: {enriched}  deels-verrijkt: {partial}  "
        f"niet gevonden: {not_found}  nieuwe stofnamen: {new_ingredients_created}"
    )
    print(f"[sources] bcfi={by_source.get('bcfi', 0)}  medipim={by_source.get('medipim', 0)}  "
          f"bcfi+medipim={by_source.get('bcfi+medipim', 0)}")


if __name__ == "__main__":
    main()
