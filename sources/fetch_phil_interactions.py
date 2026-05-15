"""
Fetch drug-interaction warnings from APB's Phil portal (phil.apb.be) for each
resident, and write them into the resident's `## Reviews / <date> — Phil-interacties`
subsection.

WHY PLAYWRIGHT? phil.apb.be is a single-page app behind sso.apb.be SSO; the
interaction details only appear after the JS finishes rendering, so plain
`requests` cannot scrape it. Playwright drives a real headless Chromium.

PREREQUISITES (on your machine, not in the sandbox):
    pip install playwright python-dotenv beautifulsoup4
    playwright install chromium

ENV (sources/.env, gitignored):
    PHIL_USER=kirsty_winters@hotmail.com
    PHIL_PASS=...

USAGE:
    python3 sources/fetch_phil_interactions.py <bewoner-slug>
    python3 sources/fetch_phil_interactions.py --afdeling marga
    python3 sources/fetch_phil_interactions.py --all
    python3 sources/fetch_phil_interactions.py --headed <bewoner>   # show browser (debug)
    python3 sources/fetch_phil_interactions.py --refresh-session    # discard cached login
    python3 sources/fetch_phil_interactions.py --dry-run --all      # report only, write nothing

CACHING:
    sources/.cache/phil/session.json   — Playwright storage_state (login cookies)
    sources/.cache/phil/<cnks-hash>.html  — raw response HTML per CNK-set
    sources/.cache/phil/<cnks-hash>.txt   — extracted text (for parser dev)
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import os
import re
import sys
from datetime import date
from pathlib import Path

try:
    from dotenv import load_dotenv  # type: ignore[import-not-found]
    _HAS_DOTENV = True
except ImportError:
    _HAS_DOTENV = False

    def load_dotenv(path: Path | str | None = None) -> bool:  # type: ignore[misc]
        """Minimal .env reader used when python-dotenv isn't installed."""
        if path is None or not Path(path).exists():
            return False
        for line in Path(path).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, val = line.partition("=")
            key = key.strip()
            val = val.strip().strip('"').strip("'")
            os.environ.setdefault(key, val)
        return True

try:
    from playwright.async_api import async_playwright, Page, TimeoutError as PWTimeout
except ImportError:
    print("[fatal] playwright ontbreekt — run: pip install playwright && playwright install chromium", file=sys.stderr)
    raise

try:
    from bs4 import BeautifulSoup
except ImportError:
    print("[fatal] beautifulsoup4 ontbreekt — run: pip install beautifulsoup4", file=sys.stderr)
    raise


VAULT_ROOT = Path(__file__).resolve().parent.parent
CACHE_DIR = VAULT_ROOT / "sources" / ".cache" / "phil"
CARECENTER_DIR = VAULT_ROOT / "carecenter"
ENV_PATH = VAULT_ROOT / "sources" / ".env"

PHIL_BASE = "https://phil.apb.be"
PHIL_INTERACTIONS_PATH = "/nl-BE/interactions"
PHIL_LANDING = f"{PHIL_BASE}/nl-BE/"
SSO_URL = (
    "https://sso.apb.be/LogonFlow/Logon"
    "?appname=&culture=nl-BE"
    "&urlredirect=https://sso.apb.be/MoreInformation/phil"
    "?from=http%3A%2F%2Fphil.apb.be/&Culture=NL-BE"
)


# ---------------------------------------------------------------------------
# Bewoner discovery + CNK extraction
# ---------------------------------------------------------------------------

_CNK_RE = re.compile(r"knowledgebase/cnk/(\d+)")


def gather_resident_paths(args) -> list[Path]:
    if args.bewoner:
        matches = list(CARECENTER_DIR.rglob(f"{args.bewoner}.md"))
        if not matches:
            raise SystemExit(f"Bewoner '{args.bewoner}' niet gevonden onder {CARECENTER_DIR}")
        return matches
    if args.afdeling:
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


def extract_cnks_for_resident(path: Path) -> list[str]:
    """Distinct CNKs referenced in the bewoner's medication tables (order-preserving)."""
    text = path.read_text(encoding="utf-8")
    seen: set[str] = set()
    out: list[str] = []
    for m in _CNK_RE.finditer(text):
        cnk = m.group(1)
        if cnk not in seen:
            seen.add(cnk)
            out.append(cnk)
    return out


def cnks_hash(cnks: list[str]) -> str:
    h = hashlib.sha1(",".join(cnks).encode("utf-8")).hexdigest()[:10]
    return h


# ---------------------------------------------------------------------------
# Phil scraping
# ---------------------------------------------------------------------------

async def login_if_needed(page: Page, user: str, password: str) -> None:
    """Go to Phil; if we hit a login wall, fill credentials and submit."""
    await page.goto(PHIL_LANDING, wait_until="domcontentloaded")
    # Wait briefly for any SSO redirect to kick in
    try:
        await page.wait_for_url(re.compile(r"sso\.apb\.be|phil\.apb\.be"), timeout=15_000)
    except PWTimeout:
        pass

    current = page.url
    if "sso.apb.be" in current or "Logon" in current:
        print("[phil] login form detected — submitting credentials")
        # The login form selectors below are first-best-guess; Phil/APB may
        # rename fields. We'll fail clearly so the user can fix them.
        candidates_user = [
            'input[name="username" i]',
            'input[name="UserName" i]',
            'input[id="username" i]',
            'input[type="email"]',
        ]
        candidates_pass = [
            'input[name="password" i]',
            'input[id="password" i]',
            'input[type="password"]',
        ]
        candidates_submit = [
            'button[type="submit"]',
            'input[type="submit"]',
            'button:has-text("Aanmelden")',
            'button:has-text("Inloggen")',
            'button:has-text("Login")',
        ]
        async def try_fill(selectors: list[str], value: str, label: str) -> bool:
            for sel in selectors:
                el = await page.query_selector(sel)
                if el:
                    await el.fill(value)
                    print(f"[phil] {label}: gevuld via {sel}")
                    return True
            return False
        ok_u = await try_fill(candidates_user, user, "username")
        ok_p = await try_fill(candidates_pass, password, "password")
        if not (ok_u and ok_p):
            html_dump = CACHE_DIR / "login_debug.html"
            CACHE_DIR.mkdir(parents=True, exist_ok=True)
            html_dump.write_text(await page.content(), encoding="utf-8")
            raise SystemExit(
                f"[fatal] login-velden niet gevonden. Pagina-dump in {html_dump}. "
                "Inspecteer het HTML en pas de selector-lijst aan."
            )
        clicked = False
        for sel in candidates_submit:
            el = await page.query_selector(sel)
            if el:
                await el.click()
                clicked = True
                print(f"[phil] submit: geklikt op {sel}")
                break
        if not clicked:
            await page.keyboard.press("Enter")
            print("[phil] submit: Enter (geen knop gevonden)")

        # Wait until we land back on phil.apb.be
        try:
            await page.wait_for_url(re.compile(r"phil\.apb\.be"), timeout=30_000)
        except PWTimeout:
            html_dump = CACHE_DIR / "login_post_submit.html"
            html_dump.write_text(await page.content(), encoding="utf-8")
            raise SystemExit(
                f"[fatal] login lijkt mislukt — niet teruggekeerd naar phil.apb.be. "
                f"Pagina-dump in {html_dump}."
            )
        print("[phil] login geslaagd")
    else:
        print("[phil] reeds ingelogd via gecachte sessie")


async def fetch_interactions(page: Page, cnks: list[str], debug_tag: str) -> tuple[str, str]:
    """
    Visit the interactions page for the given CNKs and return (raw_html, plain_text).

    Phil is a single-page app, so a direct hit to /nl-BE/interactions?... is
    sometimes aborted by the SPA's own router. We work around that by first
    landing on the SPA root (if we're not already there), then triggering the
    in-app navigation via the address bar. On any error we snapshot the page
    for debugging.
    """
    url = f"{PHIL_BASE}{PHIL_INTERACTIONS_PATH}?selectedproducts={','.join(cnks)}&selectedsubstances=&"

    async def snapshot(reason: str) -> None:
        CACHE_DIR.mkdir(parents=True, exist_ok=True)
        try:
            html_path = CACHE_DIR / f"{debug_tag}.error.html"
            html_path.write_text(await page.content(), encoding="utf-8")
            png_path = CACHE_DIR / f"{debug_tag}.error.png"
            await page.screenshot(path=str(png_path), full_page=True)
            print(f"    [snapshot] {reason} → {html_path.name} + {png_path.name}")
            print(f"    [snapshot] current url: {page.url}")
        except Exception as e:  # noqa: BLE001
            print(f"    [snapshot-fail] {e}")

    # Step 1: ensure we're on the SPA so the JS app is alive.
    if not page.url.startswith(PHIL_BASE):
        try:
            await page.goto(PHIL_LANDING, wait_until="domcontentloaded", timeout=30_000)
        except Exception as e:  # noqa: BLE001
            print(f"    [warn] landing-navigatie faalt: {e}")
            await snapshot("landing-fail")

    # Step 2: try the deep-link navigation. The SPA may abort the initial
    # request and instead route client-side; that's fine for us. We treat any
    # "navigation interrupted by SPA routing" signal as success and continue.
    def is_spa_route_signal(err: Exception) -> bool:
        s = str(err)
        return any(token in s for token in (
            "ERR_ABORTED",
            "Execution context was destroyed",
            "frame was detached",
            "Navigation failed because page was closed",
        ))

    last_err: Exception | None = None
    for attempt in range(3):
        try:
            await page.goto(url, wait_until="commit", timeout=30_000)
            print(f"    [nav] goto ok — landed at {page.url}")
            last_err = None
            break
        except Exception as e:  # noqa: BLE001
            last_err = e
            if is_spa_route_signal(e) and "phil.apb.be" in page.url:
                print(f"    [nav] goto interrupted by SPA, URL is now {page.url} — continuing")
                last_err = None
                break
            if attempt == 0:
                # Alternative: in-page navigation. The very act of navigating
                # destroys the JS execution context, so `evaluate` always
                # raises here — we catch that and proceed.
                try:
                    await page.evaluate(f"window.location.assign({url!r})")
                except Exception as e2:  # noqa: BLE001
                    if is_spa_route_signal(e2):
                        print("    [nav] in-page assign kicked off (context destroyed as expected)")
                    else:
                        print(f"    [warn] in-page nav onverwachte fout: {e2}")
                try:
                    await page.wait_for_url(re.compile(r"interactions"), timeout=15_000)
                    print(f"    [nav] na in-page assign url = {page.url}")
                    last_err = None
                    break
                except PWTimeout:
                    print(f"    [warn] in-page assign: url niet veranderd in 15s, page.url = {page.url}")
            await asyncio.sleep(1.5 * (attempt + 1))
    if last_err is not None:
        await snapshot("goto-fail")
        raise last_err

    # Phil's interaction UI renders client-side — wait for likely results.
    # Reduced per-selector timeout to 3s so we don't hang for a full minute on
    # the unlikely-to-exist ones. We just need *something* meaningful on the page.
    candidate_ready = [
        '[data-testid="interactions-results"]',
        '[data-test="interactions"]',
        '.interactions',
        'main',
        '#app',
    ]
    for sel in candidate_ready:
        try:
            await page.wait_for_selector(sel, timeout=3_000)
            print(f"    [ready] selector '{sel}' aanwezig")
            break
        except PWTimeout:
            continue
    try:
        await page.wait_for_load_state("networkidle", timeout=10_000)
        print("    [ready] networkidle")
    except PWTimeout:
        print("    [ready] networkidle timed out (10s) — gaan door met huidige inhoud")

    raw_html = await page.content()
    plain = extract_text_from_html(raw_html)
    return raw_html, plain


def extract_text_from_html(html: str) -> str:
    soup = BeautifulSoup(html, "html.parser")
    # Strip nav/footer/script to keep the interaction body
    for tag in soup(["script", "style", "noscript", "header", "footer", "nav"]):
        tag.decompose()
    text = soup.get_text("\n", strip=True)
    # Collapse repeated blank lines
    return re.sub(r"\n{3,}", "\n\n", text)


# ---------------------------------------------------------------------------
# Parsing Phil's interaction text into structured warnings
# ---------------------------------------------------------------------------
#
# Phil's results page (in plain text) follows a stable shape:
#
#   PHIL
#   …navigation boilerplate…
#   Geselecteerde elementen:
#   Een element toevoegen
#   Selectie wissen
#   <line per selected product>
#   Geneesmiddeleninteracties
#   Ernstig            (severity header)
#   <product A>
#   <product B>        (pair = one drug-drug interaction)
#   <product A2>
#   <product B2>
#   Matig ernstig      (severity header)
#   …
#   Interacties met voedings- of genotmiddelen
#   Ernstig
#   <product>
#   <food/lifestyle>   (pair = one drug-food interaction)
#   …
#   Contact / Disclaimer / Privacy / © Copyright …
#
# Each product line has the form: 'BRAND NAME 30X 20MG (Active ingredient)'.

_SEVERITIES = ("Ernstig", "Matig ernstig", "Gering")
_DRUG_LINE_RE = re.compile(r"^(.+?)\s*\(([^)]+)\)\s*$")


def _parse_drug_line(line: str) -> dict[str, str]:
    m = _DRUG_LINE_RE.match(line)
    if m:
        return {"brand": m.group(1).strip(), "inn": m.group(2).strip()}
    return {"brand": line.strip(), "inn": ""}


def parse_warnings(plain_text: str) -> dict[str, list[dict]]:
    """
    Parse Phil's plain-text export into structured interactions.

    Returns:
        {
          "drug_drug": [{"severity": "ernstig", "a": {brand,inn}, "b": {brand,inn}}, ...],
          "drug_food": [{"severity": "ernstig", "drug": {brand,inn}, "item": str}, ...],
          "selected":  [{"brand": ..., "inn": ...}],
        }
    """
    lines = [ln.strip() for ln in plain_text.splitlines() if ln.strip()]
    out: dict[str, list[dict]] = {"drug_drug": [], "drug_food": [], "selected": []}

    def idx_of(token: str) -> int:
        for i, ln in enumerate(lines):
            if ln == token:
                return i
        return -1

    sel_start = idx_of("Geselecteerde elementen:")
    ddi_start = idx_of("Geneesmiddeleninteracties")
    food_start = idx_of("Interacties met voedings- of genotmiddelen")
    trailer = next(
        (i for i, ln in enumerate(lines) if ln in ("Contact", "Disclaimer", "Privacy", "Veuillez autoriser les cookies pour pouvoir visiter ce site-web")),
        len(lines),
    )

    # Selected products section ends at "Geneesmiddeleninteracties"
    if sel_start != -1:
        end = ddi_start if ddi_start != -1 else trailer
        for ln in lines[sel_start + 1: end]:
            if ln in ("Een element toevoegen", "Selectie wissen"):
                continue
            out["selected"].append(_parse_drug_line(ln))

    def parse_pairs(start: int, end: int, is_food: bool) -> list[dict]:
        items: list[dict] = []
        current_sev: str = ""
        i = start + 1
        while i < end:
            ln = lines[i]
            if ln in _SEVERITIES:
                current_sev = ln.lower()
                i += 1
                continue
            # A pair takes the next two non-severity lines
            if i + 1 < end:
                a = _parse_drug_line(ln)
                b_line = lines[i + 1]
                if b_line in _SEVERITIES:
                    # Lone product without a partner — skip
                    i += 1
                    continue
                if is_food:
                    items.append({
                        "severity": current_sev,
                        "drug": a,
                        "item": b_line,
                    })
                else:
                    items.append({
                        "severity": current_sev,
                        "a": a,
                        "b": _parse_drug_line(b_line),
                    })
                i += 2
            else:
                i += 1
        return items

    if ddi_start != -1:
        ddi_end = food_start if food_start != -1 else trailer
        out["drug_drug"] = parse_pairs(ddi_start, ddi_end, is_food=False)
    if food_start != -1:
        out["drug_food"] = parse_pairs(food_start, trailer, is_food=True)

    return out


# ---------------------------------------------------------------------------
# Writing into the bewoner note
# ---------------------------------------------------------------------------

def render_phil_block(cnks: list[str], warnings: dict[str, list[dict]], run_date: str) -> str:
    """
    Render the Phil interactions in a stable, readable layout per severity.
    Mirrors the structure of Phil's own page (drug-drug first, then drug-food)
    but uses our INN names so downstream tools can reason about them.
    """
    out = [f"### {run_date} — Phil-interacties", ""]
    out.append(
        f"Automatisch opgehaald van [phil.apb.be]({PHIL_BASE}{PHIL_INTERACTIONS_PATH}"
        f"?selectedproducts={','.join(cnks)}) voor {len(cnks)} CNK('s). "
        "De ruwe pagina staat in `sources/.cache/phil/`."
    )
    out.append("")

    drug_drug = warnings.get("drug_drug", [])
    drug_food = warnings.get("drug_food", [])
    if not drug_drug and not drug_food:
        out.append("_Geen interactiewaarschuwingen gevonden of pagina kon niet geparsed worden — zie cache._")
        out.append("")
        return "\n".join(out)

    severity_order = ("ernstig", "matig ernstig", "gering")
    severity_label = {"ernstig": "Ernstig", "matig ernstig": "Matig ernstig", "gering": "Gering"}

    if drug_drug:
        out.append("**Geneesmiddel-geneesmiddel interacties:**")
        out.append("")
        for sev in severity_order:
            items = [w for w in drug_drug if w["severity"] == sev]
            if not items:
                continue
            out.append(f"_{severity_label[sev]}:_")
            out.append("")
            for w in items:
                a = w["a"]["inn"] or w["a"]["brand"]
                b = w["b"]["inn"] or w["b"]["brand"]
                out.append(f"- {a} + {b}")
            out.append("")

    if drug_food:
        out.append("**Voedings- en genotmiddelen-interacties:**")
        out.append("")
        for sev in severity_order:
            items = [w for w in drug_food if w["severity"] == sev]
            if not items:
                continue
            out.append(f"_{severity_label[sev]}:_")
            out.append("")
            for w in items:
                drug = w["drug"]["inn"] or w["drug"]["brand"]
                item = w["item"]
                out.append(f"- {drug} + {item}")
            out.append("")

    return "\n".join(out).rstrip() + "\n"


def upsert_block(text: str, block: str, run_date: str) -> str:
    """
    Insert/replace `### <run_date> — Phil-interacties` block under `## Reviews`.
    Keeps GheOPS-screening block and other dated entries intact.
    """
    m = re.search(r"^## Reviews\s*\n", text, flags=re.MULTILINE)
    if not m:
        return text.rstrip() + "\n\n## Reviews\n\n" + block + "\n"
    head = text[: m.end()]
    tail = text[m.end():]
    next_section = re.search(r"\n## \S", tail)
    body = tail[: next_section.start()] if next_section else tail
    rest = tail[next_section.start():] if next_section else ""
    body = re.sub(
        rf"### {re.escape(run_date)} — Phil-interacties\n.*?(?=\n### \d{{4}}-|\Z)",
        "",
        body,
        count=1,
        flags=re.DOTALL,
    )
    body = body.rstrip() + ("\n\n" if body.strip() else "") + block.rstrip() + "\n"
    return head + body + ("\n" + rest.lstrip("\n") if rest else "\n")


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

async def run(args) -> None:
    load_dotenv(ENV_PATH if ENV_PATH.exists() else None)
    user = os.getenv("PHIL_USER")
    password = os.getenv("PHIL_PASS")
    if not user or not password:
        raise SystemExit(
            f"[fatal] PHIL_USER en/of PHIL_PASS niet gezet (verwacht in {ENV_PATH} of als env-var)"
        )

    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    session_path = CACHE_DIR / "session.json"
    if args.refresh_session and session_path.exists():
        session_path.unlink()
        print("[phil] gecachte sessie verwijderd")

    targets = gather_resident_paths(args)
    print(f"[phil] {len(targets)} bewoner(s)")

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=not args.headed)
        context_kwargs = {}
        if session_path.exists():
            context_kwargs["storage_state"] = str(session_path)
        context = await browser.new_context(**context_kwargs)
        page = await context.new_page()

        await login_if_needed(page, user, password)
        # Persist session for the next runs
        await context.storage_state(path=str(session_path))

        run_date = args.date
        total_warnings = 0
        for path in targets:
            cnks = extract_cnks_for_resident(path)
            if not cnks:
                print(f"  {path.stem}: 0 CNKs — overslaan")
                continue
            h = cnks_hash(cnks)
            print(f"  {path.stem}: {len(cnks)} CNKs (hash {h}) …")
            try:
                html, plain = await fetch_interactions(page, cnks, debug_tag=f"{path.stem}-{h}")
            except Exception as e:  # noqa: BLE001
                print(f"    [err] {e}")
                continue

            (CACHE_DIR / f"{h}.html").write_text(html, encoding="utf-8")
            (CACHE_DIR / f"{h}.txt").write_text(plain, encoding="utf-8")
            warnings = parse_warnings(plain)
            total_warnings += len(warnings)
            print(f"    → {len(warnings)} parsed warning(s)")
            if not args.dry_run:
                block = render_phil_block(cnks, warnings, run_date)
                cur = path.read_text(encoding="utf-8")
                new = upsert_block(cur, block, run_date)
                if new != cur:
                    path.write_text(new, encoding="utf-8")

        await context.close()
        await browser.close()

    print(f"\n[done] {total_warnings} totaal waarschuwing(en) over {len(targets)} bewoners")
    print(f"[cache] ruwe HTML/tekst staan in {CACHE_DIR}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("bewoner", nargs="?", help="bewoner slug (e.g. 'd-hooghe-yolande')")
    parser.add_argument("--afdeling", help="all residents in this afdeling (slug)")
    parser.add_argument("--all", action="store_true", help="all residents in the vault")
    parser.add_argument("--date", default=date.today().isoformat(), help="run-date (YYYY-MM-DD)")
    parser.add_argument("--headed", action="store_true", help="show the browser (debug login flow)")
    parser.add_argument("--refresh-session", action="store_true", help="discard cached login and re-authenticate")
    parser.add_argument("--dry-run", action="store_true", help="report only, write nothing")
    args = parser.parse_args()
    asyncio.run(run(args))


if __name__ == "__main__":
    main()
