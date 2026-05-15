---
name: medicatiereview
description: Voer een medicatiereview uit per bewoner van het WZC. Combineert GheOPS-screening + Phil-interacties + apotheker-input tot een definitieve review-tekst, en stuurt op naar een eind-docx.
triggers:
  - "medicatiereview"
  - "review bewoner"
  - "voer review uit"
  - "concept-review"
  - "definitief maken"
---

# Medicatiereview-skill

Deze skill helpt de apotheker om systematisch alle bewoners van een
woonzorgcentrum te reviewen op basis van het therapieschema, met
voorbereide input uit GheOPS en Phil. Aan het einde produceert het
systeem een gepolijst eindrapport in het Parkhof 2026-format.

## Werkomgeving

- Vault root: `/Users/tomdevos/PhpstormProjects/hyperfox/farmapuntbe.reviews`
- Bewoner-notities: `carecenter/<carecenter>/<afdeling>/<achternaam-voornaam>.md`
- Skill-scripts: `sources/*.py`
- Kennisbank: `knowledgebase/{gheops,cnk,active-ingredient,review-examples}/`

## Wanneer aanroepen

Roep deze skill aan wanneer de gebruiker zegt: "doe medicatiereview voor X",
"volgende bewoner reviewen", "maak concept-review", "finaliseer review",
"toon review-status", of "exporteer reviews naar docx".

## Standaard workflow

### Stap 1 — Status checken

```bash
python3 sources/propose_review.py --status
```

Toont per status (`geen`, `ruwe input`, `concept`, `definitief`) hoeveel
bewoners er in welke fase zitten. Gebruik dit om aan de gebruiker te
melden waar we staan en welke bewoner als volgende aan de beurt is.

### Stap 2 — Voorbereidende data zeker

Voor de bewoner die we gaan reviewen MOET er recente GheOPS-screening en
(ideaal) Phil-interactie-data zijn.

**Wanneer een nieuw maandelijks therapieschema binnenkomt**, draai eerst
de volledige pipeline (in deze volgorde, idempotent — herhaalbaar):

```bash
# 1. Bewoner-bestanden hergenereren uit de xlsx
python3 sources/build_resident_notes.py

# 2. Eventueel nieuwe CNK stubs aanmaken
python3 sources/build_cnk_stubs.py

# 3. CNK's verrijken met actief bestanddeel (Medipim eerst, BCFI fallback)
python3 sources/enrich_cnk.py --all-unenriched

# 4. Opnieuw build_resident_notes — pikt de nu-verrijkte AI's op in de tabellen
python3 sources/build_resident_notes.py

# 5. GheOPS-kennisbank rebuild (alleen bij nieuwe PDF-versie)
python3 sources/build_gheops_kb.py

# 6. Screening + Phil-interacties (zie hieronder)
```

Per bewoner (of in batch):

```bash
# GheOPS-screening (snel, offline)
python3 sources/screen_resident.py <bewoner-slug>
python3 sources/screen_resident.py --afdeling marga
python3 sources/screen_resident.py --all

# Phil-interacties (online, vraagt login bij eerste run; sessie cached)
python3 sources/fetch_phil_interactions.py <bewoner-slug>
python3 sources/fetch_phil_interactions.py --afdeling marga
python3 sources/fetch_phil_interactions.py --all

# Bij login-problemen of expired session:
python3 sources/fetch_phil_interactions.py --refresh-session --headed <bewoner-slug>
```

De Phil-parser levert nu gestructureerde geneesmiddel-geneesmiddel én
voeding-/genotmiddel-interacties per severity (`Ernstig` / `Matig ernstig` /
`Gering`). Zie `sources/.cache/phil/<hash>.txt` voor het ruwe pagina-uittreksel.

### Stap 3 — Concept-review genereren

```bash
python3 sources/propose_review.py <bewoner-slug> --write
```

Dit schrijft een `### <datum> — Concept-review` blok onder `## Reviews`
in het bewoner-bestand. De stdout-output is direct leesbaar in Bespreking-stijl
en gebruikt per-criterium templates (CRITERIUM_TEMPLATES in propose_review.py).

### Stap 4 — Apotheker laten reviewen

Toon de concept-tekst aan de gebruiker (eventueel uitgelicht met de medicatielijst
in het bewoner-bestand) en stel concreet voor om:

1. **Te accepteren zoals voorgesteld** → dan kopieer je het Concept-blok 1:1
   naar een nieuw `### <datum> — Definitief` blok.
2. **Aanpassingen door te voeren** → de gebruiker geeft toevoegingen of
   wijzigingen door, jij verwerkt ze in een nieuwe Definitief-blok.
3. **Bewoner over te slaan** → markeer de bewoner als "geen advies"
   in een Definitief-blok met enkel "Alles ok." (zoals in de Bespreking-doc
   voor sommige bewoners).

Schrijf het Definitief-blok rechtstreeks in het bewoner-bestand via de
Edit-tool. Het block hoort onder `## Reviews`, ná de Concept-review.

Voorbeeld-vorm (mimick van het Parkhof 2026-eindrapport):

```markdown
### 2026-05-13 — Definitief

**Yolande D'HOOGHE** — Behandelend arts: Dr. Anne Hendrikx

Asaflow + Duloxetine: verhoogde bloedingsneiging, neemt ook Pantoprazole dus is normaal ok. Eventueel letten op symptomen: bloed bij stoelgang, hematomen, frequente neusbloedingen, zwarte stoelgang.
Noodzaak Pantoprazole?
```

### Stap 5 — Leerlus voeden (Fase 5)

Wanneer een Definitief-blok afwijkt van het Concept, schrijf het verschil
weg naar `knowledgebase/review-examples/<datum>-<bewoner-slug>.md` met
frontmatter: `bewoner`, `datum`, `medicatieset-hash`, `gheops-hits`. Body:
de definitieve apotheker-tekst. Bij latere reviews voor vergelijkbare
medicatiesets kan je deze voorbeelden ophalen om beter te suggereren.
(Dit deel is nog in opbouw — voor nu mag je het Definitief-blok gewoon
in het bewoner-bestand laten staan.)

### Stap 6 — Export naar docx (Fase 5)

Wanneer alle bewoners van een afdeling/carecenter status `definitief` hebben,
draait de gebruiker:

```bash
python3 sources/export_review.py --carecenter parkhof --date 2026-05-13 \
    --output uitgaande/medicatiereview_parkhof_2026.docx
```

Dit script bestaat nog niet — wordt in een latere iteratie toegevoegd.

## Tone-of-voice voor concept-suggesties

- Korte feitelijke zinnen, geen disclaimers.
- "X + Y: gevolg" patroon voor interacties.
- "Noodzaak X?" voor items die de apotheker moet wegen.
- "Controleer Z" voor monitoring-aanbevelingen.
- Bij twijfel: laat de vraag open en laat de apotheker beslissen.

Mimick exact de Bespreking-stijl uit de referentie-doc — niet de
GheOPS-rationale verbatim overnemen, wel het kernbericht.

**Phil-interacties keuze-richtlijn:**
- **Ernstig** drug-drug interacties → altijd in de Definitief-tekst opnemen
  met kort gevolg (bv. "Trazodon + Rasagiline: symptomen serotoninesyndroom").
- **Matig ernstig** drug-drug → opnemen tenzij apotheker oordeelt dat ze
  geadresseerd zijn (bv. PPI dekt bloedingsrisico).
- **Gering** → alleen opnemen als er een concrete actie is.
- **Voeding-/genotmiddelen** → de meest klinisch relevante (bv. Levodopa +
  eiwitrijk voedsel, Rasagiline + tyramine-rijk voedsel, Alcohol bij sedativa)
  als losse aandachtspunten of in de Algemene aandachtspunten-sectie van het
  eindrapport. Niet alle alcohol-warnings dupliceren per bewoner.

## Foutscenario's

- **Geen GheOPS-hits én geen Phil-data**: meld dat de bewoner mogelijk
  een minimaal therapieschema heeft; vraag of de gebruiker toch handmatig
  een review wil schrijven of de bewoner wil markeren als "Alles ok."
- **Phil-data ontbreekt door scraping-falen**: bouw alleen op GheOPS en
  meld dit expliciet aan de gebruiker. Voorgesteld vervolg: `--refresh-session`
  proberen of de Phil-cache `sources/.cache/phil/<hash>.txt` manueel inspecteren.
- **Bewoner heeft geen `## Reviews`-sectie**: voeg er een toe, de scripts
  doen dat normaal automatisch maar bij handmatige bewerking kan het ontbreken.

## Beperkingen (bekend)

- De screener detecteert geen dosis/duur — alle PPI-gebruikers krijgen een
  "PPI > 8 weken" flag, alle acetylsalicylzuur-gebruikers een "> 100 mg/dag"
  flag. De apotheker valideert tegen de werkelijke dosis in het schema.
- "Zonder X" criteria (methotrexaat zonder foliumzuur, opioïd zonder
  laxativum, osteoporose-therapie zonder calcium/vit D) flaggen ook als
  de bewoner X wèl neemt — apotheker negeert in dat geval. Volgende
  iteratie: een adjunct-check toevoegen.
- Lijst 2 (comorbiditeit-afhankelijk) criteria worden geflagd op stof,
  niet op comorbiditeit. Bv. "Thiazide bij jicht" triggert voor elke
  thiazide-gebruiker; de apotheker beslist of de comorbiditeit speelt.
- 9999xxx-CNK's (magistrale bereiding) zitten niet in BCFI of Phil — die
  worden door fetch_phil_interactions.py automatisch overgeslagen en het
  CNK-bestand blijft `status: deels-verrijkt` (naam + groep wel bekend
  via Medipim).
- Algemene aandachtspunten (L-thyroxine + koffie, ijzer + thee, etc.)
  zijn niet automatisch gegenereerd uit een specifieke bron — die staan
  in `knowledgebase/algemene-aandachtspunten/` (eventueel later toe te
  voegen) en horen in de eind-docx in een eigen sectie vóór de
  bewoner-fiches.
