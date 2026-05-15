"""
Generate a draft medicatiereview per resident in the apotheker-Bespreking style.

The draft combines:
  - GheOPS-screening hits (from `### YYYY-MM-DD — GheOPS-screening` subsection)
  - Phil-interacties (from `### YYYY-MM-DD — Phil-interacties` subsection)
  - Per-criterium phrasing templates (in CRITERIUM_TEMPLATES) that mimic the
    concise question/action style from the reference Bespreking docx.

Workflow:
  1. `python3 sources/propose_review.py <bewoner-slug>` → prints the draft to
     stdout in markdown. The apotheker reads it (in Obsidian, in the terminal,
     or via the medicatiereview skill).
  2. With `--write`, the script also injects the draft into the bewoner's note
     under `### YYYY-MM-DD — Concept-review`, leaving room for the apotheker to
     replace it with the final `### YYYY-MM-DD — Definitief` block.
  3. `python3 sources/propose_review.py --status` lists every bewoner with the
     state of their review (geen / concept / definitief).

Design choices:
  - Templates are hand-curated for the most common GheOPS criteria seen so
    far. Unknown criteria fall back to a generic phrasing that names the meds.
  - Phil-interacties are copied verbatim because Phil already phrases them
    clinically. Stripped of the cache-source footer.
  - The script never overwrites a `Definitief` block; once the apotheker has
    written one, future `propose --write` runs only update the Concept block.
"""

from __future__ import annotations

import argparse
import re
import sys
from datetime import date
from pathlib import Path

VAULT_ROOT = Path(__file__).resolve().parent.parent
CARECENTER_DIR = VAULT_ROOT / "carecenter"


# ---------------------------------------------------------------------------
# Per-criterium phrasing templates
# ---------------------------------------------------------------------------
# A template receives a `meds` list (display names of matched bewoner INNs)
# and returns one or more concise sentences. The style mirrors the Bespreking
# docx: short factual statements, "X: gevolg" patterns for interactions,
# questions like "Noodzaak X?" for items needing pharmacist confirmation.

def _join(meds: list[str]) -> str:
    if not meds:
        return ""
    if len(meds) == 1:
        return meds[0]
    return ", ".join(meds[:-1]) + " + " + meds[-1]


CRITERIUM_TEMPLATES: dict[int, callable] = {  # type: ignore[type-arg]
    # ---- Lijst 1 ----
    1: lambda meds: f"{_join(meds)} (centraal werkend antihypertensivum): risico op bradycardie/orthostase — overweeg veiliger alternatief.",
    2: lambda meds: f"Digoxine: verifieer dosis ≤ 0,125 mg/dag en controleer serumspiegel bij nierfunctieverandering.",
    3: lambda meds: f"Acetylsalicylzuur: verifieer dosis — bij > 100 mg/dag verhoogd bloedingsrisico (verkies 75–100 mg, eventueel met PPI).",
    4: lambda meds: f"Dipyridamol: veiliger alternatief beschikbaar (acetylsalicylzuur 75–100 mg).",
    5: lambda meds: f"Noodzaak {_join(meds)}? PPI al langer dan 8 weken — risico op B12-tekort, hypomagnesiëmie, fracturen.",
    6: lambda meds: f"Alizapride: liever stoppen of vervangen door domperidon kortdurend (max 7 dagen).",
    7: lambda meds: f"Metoclopramide: extrapiramidaal risico — beperk tot 3×5 mg/dag, max 5 dagen.",
    8: lambda meds: f"Vloeibare paraffine: stop wegens risico op hypocalciëmie/hypokaliëmie en lipoïdpneumonie.",
    9: lambda meds: f"Contactlaxativa langdurig: evalueer en overweeg osmotisch laxativum als alternatief.",
    10: lambda meds: f"Theofylline: smal therapeutisch venster — overweeg veiliger alternatief (GINA/GOLD).",
    11: lambda meds: f"Narcotische antitussiva: niet-farmacologische aanpak verkiezen.",
    12: lambda meds: f"Langwerkende sulfonylurea: hoog risico op langdurige hypoglykemie — schakel over.",
    13: lambda meds: f"Desmopressine: bij ouderen risico op hyponatriëmie — controleer natrium.",
    14: lambda meds: f"{_join(meds)} (opioïd): valrisico, sedatie, constipatie — combineer met laxativum, evalueer noodzaak.",
    15: lambda meds: f"{_join(meds)} (systemisch NSAID): bloeddruk + nierfunctie controleren, kortste duur, PPI overwegen.",
    17: lambda meds: f"{_join(meds)} (benzodiazepine/Z-medicatie): valrisico, cognitief — geleidelijk afbouwen indien mogelijk.",
    18: lambda meds: f"{_join(meds)} (tricyclisch antidepressivum): anticholinerge belasting, valrisico — overweeg veiliger.",
    19: lambda meds: f"{_join(meds)} (anticholinergicum): cognitief + valrisico — minimaliseer.",
    20: lambda meds: f"{_join(meds)} (antihistaminicum 1e generatie): sedatie — verkies 2e generatie.",
    21: lambda meds: f"{_join(meds)} (antipsychoticum): valrisico, CVA, sterfte bij dementie — kortst mogelijke duur.",
    22: lambda meds: f"Eerste-generatie sulfonamides (langdurig): risico op SJS/TEN, controleer indicatie.",
    23: lambda meds: f"Nitrofurantoïne langdurig: longschade/leverschade — vermijd bij ouderen met gestoorde nierfunctie.",
    24: lambda meds: f"Spironolacton > 25 mg/dag: hyperkaliëmie + verminderde nierfunctie — kalium en eGFR opvolgen.",
    25: lambda meds: f"{_join(meds)} (alfa-blokker bij BPH): orthostatische hypotensie + valrisico.",
    # ---- Lijst 2 ----
    26: lambda meds: f"Thiazide/lisdiureticum bij jicht: dosisverlaging of switch overwegen, urinezuurspiegel volgen.",
    27: lambda meds: f"Niet-cardioselectieve β-blokker bij astma/COPD: switch naar cardioselectieve β-blokker.",
    28: lambda meds: f"NSAID bij hartfalen/hypertensie/CVD: vermijd of korte duur met monitoring.",
    29: lambda meds: f"NSAID bij nierfunctiedaling: vermijd of kortdurend, eGFR + bloeddruk volgen.",
    30: lambda meds: f"Anticholinergicum bij dementie/cognitieve stoornis: vermijd — verergert verwardheid.",
    31: lambda meds: f"Benzodiazepine bij valhistoriek/COPD: vermijd of geleidelijk afbouwen.",
    32: lambda meds: f"Centraal werkend antihypertensivum bij depressie/orthostase: switch overwegen.",
    33: lambda meds: f"Niet-selectieve NSAID bij anticoagulatie: vermijd — bloedingsrisico cumuleert.",
    # ---- Lijst 3 (ontbrekende medicatie) ----
    34: lambda meds: f"Predniso(lo)n ≥ 7,5 mg/dag ≥ 3 maanden zonder calcium/vit D + bisfosfonaat: suppletie overwegen.",
    35: lambda meds: f"Opioïd zonder laxativum: voeg osmotisch laxativum (bv. macrogol) toe.",
    36: lambda meds: f"Methotrexaat zonder foliumzuursupplement: 1×/week 5–10 mg foliumzuur (1 dag na methotrexaat) of dagelijks 1 mg.",
    37: lambda meds: f"{_join(meds)} (osteoporosetherapie) zonder calcium/vit D: controleer en start zo nodig calcium 0,5–1,2 g + vit D 400–800 IE.",
    38: lambda meds: f"Anticoagulatie zonder gepaste indicatie/duur: heroverweeg.",
    39: lambda meds: f"PPI bij chronische NSAID-/aspirine-gebruik: controleer of gastroprotectie aanwezig is.",
    # ---- Lijst 4 (interacties) ----
    40: lambda meds: f"Digoxinetoxiciteit-combinatie ({_join(meds)}): controleer spiegel + ECG bij bijkomende symptomen.",
    41: lambda meds: f"{_join(meds)}: ↑ hypotensie, sinusbradycardie, AV-blok — controle hartslag en bloeddruk.",
    42: lambda meds: f"{_join(meds)}: ↑ hypotensie/shock door CYP3A4-remming — switch CYP3A4-remmer of monitor.",
    43: lambda meds: f"{_join(meds)}: ↑ hyperkaliëmie — kalium + eGFR opvolgen, dosis aanpassen.",
    44: lambda meds: f"{_join(meds)} (RAAS + trimethoprim/cotrim): hyperkaliëmie + AKI — kortst mogelijke duur, kalium volgen.",
    45: lambda meds: f"{_join(meds)}: ↑ bloedingsrisico — gastroprotectie en bloedingssignalen opvolgen.",
    46: lambda meds: f"{_join(meds)}: ↑ bloedingsrisico (SSRI/SNRI + antitrombotica) — PPI overwegen.",
    47: lambda meds: f"{_join(meds)}: serotoninesyndroom mogelijk — let op verwardheid, agitatie, hyperthermie, tremor.",
    48: lambda meds: f"{_join(meds)}: ↑ bloedingsneiging — PPI overwegen, monitor.",
    49: lambda meds: f"{_join(meds)}: ↓ effect van anticoagulans/antiaggregans — controleer INR/effectparameters.",
    50: lambda meds: f"{_join(meds)}: hypoglykemierisico stijgt — bloedsuiker monitoren.",
    51: lambda meds: f"{_join(meds)}: ↓ antihypertensief effect + ↓ nierfunctie + hyperkaliëmie — bloeddruk + creatinine opvolgen.",
    52: lambda meds: f"{_join(meds)}: QT-verlenging — controleer ECG voor en tijdens combinatie.",
    53: lambda meds: f"{_join(meds)}: ↑ valrisico (centraal werkende combinatie) — evalueer noodzaak.",
    54: lambda meds: f"{_join(meds)}: ↑ sedatie en ademhalingsdepressie — vermijd combinatie.",
    55: lambda meds: f"{_join(meds)}: ↑ anticholinerge belasting (cumulatief) — minimaliseer.",
    56: lambda meds: f"{_join(meds)}: ↑ effect van levodopa beïnvloed door eiwitrijke maaltijd — innametiming.",
    57: lambda meds: f"{_join(meds)}: ↑ digoxinespiegel — spiegel opvolgen.",
    # ---- Lijst 5 (zorgcriteria) ----
    58: lambda meds: f"Medicatiedossier: medicatiereconciliatie uitvoeren (inhalatoren, zalven, OTC, supplementen).",
    59: lambda meds: f"Contra-indicaties expliciet documenteren in dossier.",
    60: lambda meds: f"Medicatie met twijfelachtige werkzaamheid: heroverweeg.",
    61: lambda meds: f"Niet-toedienen-richtlijnen: maak duidelijk bij wie/wat niet aangewezen is.",
    62: lambda meds: f"Toedieningstijdstippen: optimaliseer (nuchter, met voeding, interval).",
    63: lambda meds: f"Vermijdbare interacties met voedsel: pompelmoes, zoethout, knoflook controleren.",
    64: lambda meds: f"Therapietrouw: bespreek met patiënt/zorgteam.",
}


# ---------------------------------------------------------------------------
# Bewoner note parsing
# ---------------------------------------------------------------------------

_FRONTMATTER_RE = re.compile(r"\A---\n(.*?)\n---\n", re.DOTALL)
_AI_LINK_RE = re.compile(r"\[\[knowledgebase/active-ingredient/[^\\|\]]+\\*\|([^\]]+)\]\]")
_HIT_LINE_RE = re.compile(
    # Title may itself contain '*' (e.g. 'Systemische* NSAID's'); accept any
    # character except newline and stop at the closing '**' of the bold span.
    r"^- \*\*Lijst (\d+), Criterium (\d+):(.+?)\*\*\s*—\s*matched op _([^_]+)_",
    re.MULTILINE,
)


def parse_frontmatter(text: str) -> dict[str, str]:
    m = _FRONTMATTER_RE.match(text)
    if not m:
        return {}
    fm: dict[str, str] = {}
    for line in m.group(1).splitlines():
        if ":" in line and not line.startswith("  "):
            key, _, val = line.partition(":")
            fm[key.strip()] = val.strip().strip('"')
    return fm


def extract_section(text: str, heading: str) -> str:
    """Return the body under `### {heading}` until the next `### ` or `## `."""
    pat = re.compile(
        rf"^### {re.escape(heading)}\s*\n(.*?)(?=\n### |\n## |\Z)",
        re.MULTILINE | re.DOTALL,
    )
    m = pat.search(text)
    return m.group(1).strip() if m else ""


def find_latest_subsection(text: str, suffix: str) -> tuple[str, str] | None:
    """
    Find the most recent `### YYYY-MM-DD — {suffix}` block.
    Returns (date_str, body) or None.
    """
    pat = re.compile(
        rf"^### (\d{{4}}-\d{{2}}-\d{{2}}) — {re.escape(suffix)}\s*\n(.*?)(?=\n### |\n## |\Z)",
        re.MULTILINE | re.DOTALL,
    )
    matches = list(pat.finditer(text))
    if not matches:
        return None
    # Pick the alphabetically-latest date (ISO-format makes this a chronological max)
    best = max(matches, key=lambda m: m.group(1))
    return best.group(1), best.group(2).strip()


def parse_gheops_hits(body: str) -> list[tuple[int, int, list[str]]]:
    """Return list of (lijst, nr, [matched meds])."""
    out: list[tuple[int, int, list[str]]] = []
    for m in _HIT_LINE_RE.finditer(body):
        lijst = int(m.group(1))
        nr = int(m.group(2))
        # Group 3 is the criterium title (unused for now), group 4 the meds list
        meds = [x.strip() for x in m.group(4).split(",")]
        out.append((lijst, nr, meds))
    return out


# ---------------------------------------------------------------------------
# Draft synthesis
# ---------------------------------------------------------------------------

def render_review_draft(path: Path, run_date: str) -> str:
    text = path.read_text(encoding="utf-8")
    fm = parse_frontmatter(text)

    arts = fm.get("dokter", "?")
    naam = fm.get("naam", path.stem)

    gheops = find_latest_subsection(text, "GheOPS-screening")
    phil = find_latest_subsection(text, "Phil-interacties")

    lines: list[str] = []
    lines.append(f"## Concept-review — {run_date}")
    lines.append("")
    lines.append(f"**{naam}** — Behandelend arts: Dr. {arts}")
    lines.append("")

    # GheOPS-derived suggestions
    if gheops:
        hits = parse_gheops_hits(gheops[1] if isinstance(gheops, tuple) else gheops)
        if hits:
            for lijst, nr, meds in hits:
                tmpl = CRITERIUM_TEMPLATES.get(nr)
                if tmpl is not None:
                    sentence = tmpl(meds)
                else:
                    sentence = f"{_join(meds)} — GheOPS Lijst {lijst}.{nr}: zie criterium."
                lines.append(f"- {sentence}  _(GheOPS {lijst}.{nr})_")
            lines.append("")
        else:
            lines.append("_Geen GheOPS-hits voor deze bewoner._")
            lines.append("")
    else:
        lines.append("_GheOPS-screening nog niet uitgevoerd. Run `python3 sources/screen_resident.py {slug}` eerst._".format(slug=path.stem))
        lines.append("")

    # Phil interactions — pass through the structured block (severity + pairs)
    # mostly verbatim so the apotheker keeps the original grouping. We render
    # each interaction as a one-line bullet with a short rationale where Phil's
    # cache makes it derivable; the parser writes them with severity headers
    # already, so we just copy from the section.
    if phil:
        phil_body = phil[1] if isinstance(phil, tuple) else phil
        # Keep the structured Phil section intact (severity headers + blank
        # lines for grouping); only drop the "Automatisch opgehaald van …"
        # metadata line that's only useful in the Reviews-section view.
        phil_lines = [
            ln for ln in phil_body.splitlines()
            if not ln.startswith("Automatisch opgehaald")
        ]
        # Strip leading/trailing empties
        while phil_lines and not phil_lines[0].strip():
            phil_lines.pop(0)
        while phil_lines and not phil_lines[-1].strip():
            phil_lines.pop()
        if phil_lines:
            lines.append("### Phil-interacties")
            lines.append("")
            lines.extend(phil_lines)
            lines.append("")
    else:
        lines.append("_Phil-interacties nog niet opgehaald. Run `python3 sources/fetch_phil_interactions.py {slug}` indien gewenst._".format(slug=path.stem))
        lines.append("")

    lines.append("---")
    lines.append("_Apotheker: schrap/wijzig hierboven naar wens, en kopieer de definitieve tekst naar een `### {run_date} — Definitief` subsectie onder `## Reviews`._".format(run_date=run_date))
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Status overview
# ---------------------------------------------------------------------------

def status_for(path: Path) -> str:
    text = path.read_text(encoding="utf-8")
    if find_latest_subsection(text, "Definitief"):
        return "definitief"
    if find_latest_subsection(text, "Concept-review"):
        return "concept"
    if find_latest_subsection(text, "GheOPS-screening") or find_latest_subsection(text, "Phil-interacties"):
        return "ruwe input"
    return "geen"


def print_status() -> None:
    by_status: dict[str, list[str]] = {}
    for p in sorted(CARECENTER_DIR.rglob("*.md")):
        s = status_for(p)
        by_status.setdefault(s, []).append(f"{p.parent.name}/{p.stem}")
    order = ("geen", "ruwe input", "concept", "definitief")
    total = sum(len(v) for v in by_status.values())
    print(f"Totaal bewoners: {total}")
    for s in order:
        items = by_status.get(s, [])
        print(f"  {s:12s} {len(items):3d}  {' '.join(items[:3])}{'…' if len(items)>3 else ''}")


# ---------------------------------------------------------------------------
# Write the draft into the bewoner note
# ---------------------------------------------------------------------------

def upsert_concept(text: str, draft: str, run_date: str) -> str:
    """
    Insert/replace `### <run_date> — Concept-review` block under `## Reviews`.
    Leaves any `Definitief` block untouched. The draft already includes the
    `## Concept-review — <date>` header for stdout display; here we strip that
    header and add a `### <date> — Concept-review` heading instead so it slots
    into the Reviews section consistently with our other dated blocks.
    """
    body_lines = draft.splitlines()
    # Drop the `## Concept-review — date` header line and the empty line after
    while body_lines and (body_lines[0].startswith("## ") or not body_lines[0].strip()):
        body_lines.pop(0)
    block = f"### {run_date} — Concept-review\n\n" + "\n".join(body_lines).strip() + "\n"

    m = re.search(r"^## Reviews\s*\n", text, flags=re.MULTILINE)
    if not m:
        return text.rstrip() + "\n\n## Reviews\n\n" + block + "\n"
    head = text[: m.end()]
    tail = text[m.end():]
    next_section = re.search(r"\n## \S", tail)
    rev_body = tail[: next_section.start()] if next_section else tail
    rest = tail[next_section.start():] if next_section else ""

    rev_body = re.sub(
        rf"### {re.escape(run_date)} — Concept-review\n.*?(?=\n### |\Z)",
        "",
        rev_body,
        count=1,
        flags=re.DOTALL,
    )
    rev_body = rev_body.rstrip() + ("\n\n" if rev_body.strip() else "") + block.rstrip() + "\n"
    return head + rev_body + ("\n" + rest.lstrip("\n") if rest else "\n")


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("bewoner", nargs="?", help="bewoner slug")
    parser.add_argument("--write", action="store_true", help="schrijf de draft naar het bewoner-bestand (### Concept-review)")
    parser.add_argument("--status", action="store_true", help="overzicht van review-status per bewoner")
    parser.add_argument("--date", default=date.today().isoformat(), help="datum voor de Concept-review subsectie (YYYY-MM-DD)")
    args = parser.parse_args()

    if args.status:
        print_status()
        return

    if not args.bewoner:
        parser.error("specifieer een bewoner-slug, of gebruik --status")

    matches = list(CARECENTER_DIR.rglob(f"{args.bewoner}.md"))
    if not matches:
        sys.exit(f"Bewoner '{args.bewoner}' niet gevonden onder {CARECENTER_DIR}")
    if len(matches) > 1:
        print(f"[warn] meerdere matches: {[str(p) for p in matches]}", file=sys.stderr)
    path = matches[0]

    draft = render_review_draft(path, args.date)
    print(draft)

    if args.write:
        text = path.read_text(encoding="utf-8")
        new = upsert_concept(text, draft, args.date)
        if new != text:
            path.write_text(new, encoding="utf-8")
            print(f"\n[written] Concept-review weggeschreven in {path}")
        else:
            print("\n[unchanged] al up-to-date")


if __name__ == "__main__":
    main()
