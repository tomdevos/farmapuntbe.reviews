# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

An **Obsidian vault** (note the `.obsidian/` folder) that powers medication reviews for Belgian residential-care residents. The Python scripts in `sources/` are an ingestion pipeline: they read external source data (medication-schema xlsx exports, Medipim's `products.csv`, BCFI's monthly EMD zip, the GheOP³S-tool PDF, phil.apb.be) and write generated markdown into `carecenter/` and `knowledgebase/`. The end product is a knowledge graph the pharmacist navigates inside Obsidian.

The vault content is in Dutch (Belgium); script comments are English, log output is Dutch.

## Top-level layout

```
carecenter/<carecenter>/<afdeling>/<resident-slug>.md   ← per-resident notes (generated)
knowledgebase/
  cnk/<7-digit-CNK>.md                                  ← per-product notes (CNK = Belgian product code)
  active-ingredient/<inn-slug>.md                        ← per-INN notes (stubs unless enriched)
  gheops/lijst-{1..5}/<nr>-<slug>.md + index.md          ← parsed GheOPS criteria
sources/
  *.py                                                  ← the pipeline
  products.csv                                          ← Medipim export (PRIMARY enrichment source)
  gheops/GheOP³S-tool versie 2_maart_2023.pdf            ← GheOPS source PDF
  .cache/{bcfi,phil}/                                   ← downloaded zips, scraped HTML (gitignored)
  .env                                                  ← PHIL_USER/PHIL_PASS (gitignored)
```

## Pipeline (run order matters)

Scripts share a vault layout but feed each other; the typical sequence after a new medication-schema xlsx lands:

1. **`build_resident_notes.py`** — reads the carecenter's medication-schema xlsx (chronische / tijdelijke / indien-nodig / verboden sheets) and writes one md per resident with a unified `Medicatielijst` plus four schedule tables. It also wipes the carecenter's `*.md` first so renames don't leave stale files. Uses `load_cnk_active_ingredients()` to fill the "Actief bestanddeel" column from already-enriched CNK frontmatter.
2. **`build_cnk_stubs.py`** — for every CNK in the xlsx that doesn't yet have a `knowledgebase/cnk/<CNK>.md`, creates a stub with `status: nog-niet-verrijkt`. Existing CNK notes are left alone.
3. **`enrich_cnk.py`** — fills the stubs. **Medipim `products.csv` is the canonical source**; BCFI EMD (downloaded monthly from bcfi.be, cached in `sources/.cache/bcfi/`) is a fallback for missing active ingredients and the only source for ATC. For every new INN it creates `knowledgebase/active-ingredient/<slug>.md`. Status transitions: `nog-niet-verrijkt` → `deels-verrijkt` (brand/category but no INN) → `verrijkt` (has INN). Sets `bron: medipim | medipim+bcfi | bcfi`.
4. **`build_gheops_kb.py`** — parses the GheOPS PDF into per-criterium notes. Extraction is by word X-coordinates per page (`extract_columns_by_position`) because pdfplumber's table extraction is unreliable for this layout. `PAGE_LIST_MAP` hard-codes which PDF page belongs to which lijst — update it when the PDF version changes. `stoffen` and `groepen` frontmatter are extracted **from the criterium NAME only**, not rationale/alternatief, to avoid false positives where a drug is mentioned as an alternative.
5. **`screen_resident.py`** — cross-references each resident's INNs against GheOPS criteria (three match modes: direct stof, base-name text-match in criterium title, drug-class match via the hand-curated `CLASS_INGREDIENTS` map). Lijst 4 (interactions) additionally requires matches on both sides of a `+` in the criterium title. Writes results under `## Reviews / ### <YYYY-MM-DD> — GheOPS-screening`; same-day runs replace that day's block.
6. **`fetch_phil_interactions.py`** — Playwright scraper for phil.apb.be (single-page app behind SSO at sso.apb.be). Reads CNKs from each resident note via the wikilink regex, fetches interactions, writes them under `## Reviews / ### <YYYY-MM-DD> — Phil-interacties`. Caches session storage_state in `sources/.cache/phil/session.json` and per-CNK-set HTML.

## Hardcoded paths — must edit before running locally

`build_resident_notes.py` and `build_cnk_stubs.py` have **sandbox paths** baked in:

```python
SOURCE_XLSX = Path("/sessions/happy-magical-goldberg/mnt/uploads/parkhof_2026-05-07_medication-schema.xlsx")
VAULT_ROOT  = Path("/sessions/happy-magical-goldberg/mnt/farmapuntbe.reviews")
```

These won't exist on the dev machine — point them at the local xlsx and at `Path(__file__).resolve().parent.parent` before running, the way the newer scripts already do.

## Common commands

```bash
# Install (note: the requirements file lives under sources/)
pip install -r sources/requirements.txt
playwright install chromium      # only needed for fetch_phil_interactions.py

# Pipeline (run from repo root)
python3 sources/build_resident_notes.py
python3 sources/build_cnk_stubs.py
python3 sources/enrich_cnk.py --all-unenriched      # or: <CNK>, --cnk-list, --reprocess-all
python3 sources/enrich_cnk.py --refresh-emd <CNK>   # force re-download BCFI zip
python3 sources/enrich_cnk.py --dry-run <CNK>       # preview, no writes
python3 sources/build_gheops_kb.py

# Reviewing
python3 sources/screen_resident.py <bewoner-slug>
python3 sources/screen_resident.py --afdeling marga
python3 sources/screen_resident.py --all --dry-run
python3 sources/fetch_phil_interactions.py --all
python3 sources/fetch_phil_interactions.py --headed <bewoner>   # debug: show browser
python3 sources/fetch_phil_interactions.py --refresh-session    # discard cached SSO login
```

No tests, no lint config, no build system — these are standalone scripts.

## Conventions that bite

- **Markdown wikilinks inside tables must use an escaped pipe**: `[[knowledgebase/cnk/123\|name]]`. The `\|` is so the alias separator doesn't collide with the table column separator. Obsidian renders `\|` correctly inside `[[ ]]`. Every script that emits links does this; preserve it.
- **CNKs are 7-digit zero-padded** when normalized (`normalize_cnk()`). The xlsx may show them unpadded; BCFI expects padded; filenames in `knowledgebase/cnk/` use the padded form.
- **Slugify is consistent across scripts** but each defines its own copy: NFKD-strip-accents → lowercase → `[^a-z0-9]+` to `-` → trim. Don't introduce a different slug function — the wikilinks won't resolve.
- **Resident name convention** in the xlsx is `Voornaam ACHTERNAAM` (surname all-uppercase, possibly multi-token like `VAN DEN NEST`). `split_resident_name()` walks tokens from the right collecting all-uppercase ones as the surname.
- **Resident frontmatter and `## Reviews` are the integration point** — both `screen_resident.py` and `fetch_phil_interactions.py` rewrite dated subsections inside `## Reviews` in place. Don't change the heading hierarchy without updating both.
- **`build_resident_notes.py` deletes all `*.md` under the carecenter directory before regenerating.** Don't put hand-written notes there.
- **The `Reviews` subsection is preserved across runs** by the screening/phil scripts (they only replace their own dated block), but `build_resident_notes.py` will wipe it — re-run reviews after regenerating residents.
- **GheOPS extraction is fragile.** The position-based extractor depends on the header row being recognisable on each page; `PAGE_LIST_MAP`, `_BLEED_TRUNCATE_RE`, and `DRUG_CLASS_TERMS` are tuned to v2 (maart 2023) of the PDF and will need adjusting for any new version.
- **Phil credentials are in `sources/.env`** (gitignored). `sources/.env.example` shows the schema. Never commit `.env`.