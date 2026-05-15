<?php

namespace App\Services\Scraping;

use App\Models\MedicationSchedule;
use App\Models\PhilFinding;
use App\Models\PhilInteraction;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Panther\Client;

/**
 * Port of sources/fetch_phil_interactions.py to Symfony Panther.
 *
 * Phil is a SPA behind sso.apb.be SSO. Strategy:
 *   1. Reuse cached cookies if present (storage/app/phil-cache/session.json).
 *   2. Navigate to PHIL_LANDING; if redirected to sso.apb.be, fill the form.
 *   3. Navigate to /nl-BE/interactions?selectedproducts=<cnks>.
 *   4. Wait for results, dump HTML to cache, parse into PhilInteraction+Finding rows.
 *   5. Translate Phil findings into ReviewFinding rows on the given review.
 *
 * Credentials are read from .env (PHIL_USER / PHIL_PASS). If missing, the
 * service raises so callers can surface a useful error.
 */
class PhilScraper
{
    private const LANDING = 'https://phil.apb.be/nl-BE/';
    private const INTERACTIONS_PATH = '/nl-BE/interactions';
    private const BASE = 'https://phil.apb.be';
    private const SSO_URL = 'https://sso.apb.be/LogonFlow/Logon?appname=&culture=nl-BE&urlredirect=https%3A%2F%2Fsso.apb.be%2FMoreInformation%2Fphil%3Ffrom%3Dhttp%253A%252F%252Fphil.apb.be%252F%26Culture%3DNL-BE';
    private const SEVERITIES = ['Ernstig' => 'ernstig', 'Matig ernstig' => 'matig', 'Gering' => 'gering'];

    public function fetchForResident(Resident $resident, ?Review $review = null): PhilInteraction
    {
        $cnks = $this->cnksForResident($resident);
        if (count($cnks) === 0) {
            throw new \RuntimeException("Bewoner {$resident->slug} heeft geen CNK's in actief schema.");
        }

        $user = (string) env('PHIL_USER', '');
        $pass = (string) env('PHIL_PASS', '');
        if ($user === '' || $pass === '') {
            throw new \RuntimeException('PHIL_USER en PHIL_PASS ontbreken in .env.');
        }

        $hash = substr(sha1(implode(',', $cnks)), 0, 10);
        $cacheDir = storage_path('app/private/phil-cache');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $client = $this->makeClient();
        try {
            $this->restoreSession($client);
            $this->loginIfNeeded($client, $user, $pass);
            $this->persistSession($client);

            // After SSO we're on sso.apb.be/MoreInformation/phil. Land on the
            // Phil SPA first so its router is initialised, otherwise direct
            // deep-links to /interactions sometimes bail out and render an
            // empty page.
            $client->request('GET', self::LANDING);
            $this->waitUntilDomContains($client, ['PhiL', 'Geneesmiddel'], 20);

            $url = self::BASE . self::INTERACTIONS_PATH . '?selectedproducts=' . implode(',', $cnks) . '&selectedsubstances=&';
            $client->request('GET', $url);

            // First wait for the interactions UI to mount, then wait for the
            // loading spinners inside the results-cards to disappear. Phil
            // shows `<i class="fa-spinner fa-spin">` while the pairwise
            // analysis is in progress — that's our cue.
            $this->waitUntilDomContains($client, ['Geneesmiddeleninteracties'], 60);
            $this->waitUntilSpinnersGone($client, 90);

            $html = $client->getCrawler()->html();
            $plain = $this->stripHtml($html);
            file_put_contents("{$cacheDir}/{$hash}.html", $html);
            file_put_contents("{$cacheDir}/{$hash}.txt", $plain);

            $parsed = $this->parseWarningsFromHtml($html);
        } finally {
            $client->quit();
        }

        $interaction = PhilInteraction::create([
            'resident_id' => $resident->id,
            'fetched_at' => now(),
            'cnk_hash' => $hash,
            'raw_html_path' => "phil-cache/{$hash}.html",
            'parsed_json' => $parsed,
        ]);

        $this->persistFindings($interaction, $parsed);
        if ($review) {
            $this->mirrorIntoReview($interaction, $review);
        }

        return $interaction;
    }

    private function makeClient(): Client
    {
        if ($chrome = env('CHROME_PATH')) {
            $_SERVER['PANTHER_CHROME_BINARY'] = $chrome;
        }
        $args = array_values(array_filter([
            env('PHIL_HEADLESS', true) ? '--headless=new' : null,
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1280,900',
        ]));
        $opts = [
            'connection_timeout_in_ms' => 60_000,
            'request_timeout_in_ms' => 120_000,
        ];
        $driver = env('CHROMEDRIVER_PATH') ?: null;
        return Client::createChromeClient($driver, $args, $opts);
    }

    private function loginIfNeeded(Client $client, string $user, string $pass): void
    {
        // Fast path: try Phil directly. With a still-valid session cookie we
        // land on the SPA without any sso.apb.be detour, so we can skip the
        // (slow) Azure B2C form fill entirely.
        $client->request('GET', self::LANDING);
        try {
            $client->wait(2)->until(fn () => true);
        } catch (\Throwable) {
            // ignore: best-effort settling
        }
        usleep(800_000);
        $url = $client->getCurrentURL();
        if (str_contains($url, 'phil.apb.be') && ! str_contains($url, 'sso.apb.be') && ! str_contains($url, 'login.apb.be')) {
            return;
        }

        // Otherwise do the full SSO flow.
        $client->request('GET', self::SSO_URL);

        // Azure AD B2C renders the form client-side. Wait for either the B2C
        // form (#signInName + #password + #next) or the classic APB LogonFlow.
        try {
            $client->waitFor('#signInName, input#Username', 25);
        } catch (\Throwable) {
            // maybe SSO recognised our cookies and bounced us straight back
        }

        $url = $client->getCurrentURL();
        if (str_contains($url, 'phil.apb.be') && ! str_contains($url, 'login.apb.be') && ! str_contains($url, 'sso.apb.be/LogonFlow')) {
            return;
        }
        if (str_contains($url, 'sso.apb.be/MoreInformation')) {
            return;
        }

        $crawler = $client->getCrawler();
        if ($crawler->filter('#signInName')->count() > 0 && $crawler->filter('#password')->count() > 0) {
            // B2C flow: bypass element.click() (stale ref after submit) and
            // dispatch input/change events so B2C's JS validator unlocks #next.
            $this->fillViaJs($client, '#signInName', $user);
            $this->fillViaJs($client, '#password', $pass);
            try {
                $client->executeScript('document.querySelector("#next").click();');
            } catch (\Throwable) {
                // page may already have navigated
            }
        } elseif ($crawler->filter('input#Username')->count() > 0 && $crawler->filter('input#Password')->count() > 0) {
            // Classic LogonFlow fallback
            $crawler->filter('input#Username')->first()->sendKeys($user);
            $crawler->filter('input#Password')->first()->sendKeys($pass);
            $btn = $crawler->filter('button[type="submit"], input[type="submit"]')->first();
            if ($btn->count() > 0) {
                $btn->click();
            }
        } else {
            $this->dumpDebug($client, 'login-form-missing');
            throw new \RuntimeException('Login-form niet herkend (zowel B2C als classic getest). Zie storage/app/private/phil-cache/login-form-missing.html.');
        }

        // After successful login B2C redirects to sso.apb.be/MoreInformation/phil
        // (or directly back to phil.apb.be). Wait for either.
        try {
            $this->waitUntilUrlMatches($client, ['sso.apb.be/MoreInformation', 'phil.apb.be/nl-BE'], 45);
        } catch (\Throwable) {
            $this->dumpDebug($client, 'login-no-redirect');
            throw new \RuntimeException('Geen redirect na login — credentials fout? Zie storage/app/private/phil-cache/login-no-redirect.html.');
        }
    }

    private function sessionPath(): string
    {
        return storage_path('app/private/phil-cache/session.json');
    }

    /**
     * Load cookies from a previous successful login (if any) so a fresh Chrome
     * instance can fast-path past the Azure B2C form when the session is
     * still valid (~12-24h typically).
     */
    private function restoreSession(Client $client): void
    {
        $path = $this->sessionPath();
        if (! is_file($path)) {
            return;
        }
        $cookies = json_decode((string) file_get_contents($path), true);
        if (! is_array($cookies) || count($cookies) === 0) {
            return;
        }

        // Selenium cookie API requires being on the same domain when adding
        // cookies. Group by domain and visit each before injecting.
        $byDomain = [];
        foreach ($cookies as $c) {
            $dom = $c['domain'] ?? null;
            if (! $dom) continue;
            $byDomain[ltrim($dom, '.')][] = $c;
        }
        foreach ($byDomain as $domain => $list) {
            try {
                $client->request('GET', 'https://' . $domain . '/');
                $jar = $client->getCookieJar();
                foreach ($list as $c) {
                    $jar->set(new \Symfony\Component\BrowserKit\Cookie(
                        name: (string) ($c['name'] ?? ''),
                        value: (string) ($c['value'] ?? ''),
                        expires: isset($c['expiry']) ? (string) (int) $c['expiry'] : null,
                        path: $c['path'] ?? '/',
                        domain: $c['domain'] ?? $domain,
                        secure: (bool) ($c['secure'] ?? false),
                        httponly: (bool) ($c['httpOnly'] ?? false),
                        encodedValue: false,
                        sameSite: $c['sameSite'] ?? null,
                    ));
                }
            } catch (\Throwable) {
                // best-effort; failing here just means we'll fall through to full login
            }
        }
    }

    private function persistSession(Client $client): void
    {
        try {
            // Get raw Selenium cookies (Panther's BrowserKit jar lacks expiry/sameSite)
            $cookies = $client->getWebDriver()->manage()->getCookies();
            $serialised = array_map(function ($c) {
                return [
                    'name' => $c->getName(),
                    'value' => $c->getValue(),
                    'domain' => $c->getDomain(),
                    'path' => $c->getPath(),
                    'expiry' => $c->getExpiry(),
                    'secure' => $c->isSecure(),
                    'httpOnly' => $c->isHttpOnly(),
                    'sameSite' => $c->getSameSite(),
                ];
            }, $cookies);
            $path = $this->sessionPath();
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, json_encode($serialised));
        } catch (\Throwable) {
            // non-fatal — next run will just re-login
        }
    }

    private function fillViaJs(Client $client, string $selector, string $value): void
    {
        $client->executeScript(
            'const el = document.querySelector(arguments[0]); if (!el) return;
             el.focus(); el.value = arguments[1];
             el.dispatchEvent(new Event("input", {bubbles:true}));
             el.dispatchEvent(new Event("change", {bubbles:true}));',
            [$selector, $value],
        );
    }

    private function waitUntilUrlMatches(Client $client, array $needles, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $url = $client->getCurrentURL();
            foreach ($needles as $needle) {
                if (str_contains($url, $needle)) {
                    return;
                }
            }
            usleep(500_000);
        }
        throw new \RuntimeException('URL did not match any of [' . implode(', ', $needles) . ']');
    }

    private function waitUntilDomContains(Client $client, array $needles, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            try {
                $text = (string) $client->executeScript('return document.body ? document.body.innerText : "";');
            } catch (\Throwable) {
                $text = '';
            }
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return;
                }
            }
            usleep(750_000);
        }
        // soft-fail: don't throw — caller will save whatever's there
    }

    private function waitUntilSpinnersGone(Client $client, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            try {
                $count = (int) $client->executeScript(
                    'return document.querySelectorAll(".resultsCard .fa-spinner, .resultsCard .fa-spin").length;'
                );
            } catch (\Throwable) {
                $count = 0;
            }
            if ($count === 0) {
                // give Phil a beat to commit the rendered DOM after the spinner is gone
                usleep(750_000);
                return;
            }
            usleep(1_000_000);
        }
    }

    private function dumpDebug(Client $client, string $tag): void
    {
        $dir = storage_path('app/private/phil-cache');
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        try {
            file_put_contents("{$dir}/{$tag}.html", $client->getCrawler()->html());
            file_put_contents("{$dir}/{$tag}.url", $client->getCurrentURL());
        } catch (\Throwable) {
            // ignore
        }
    }

    private function cnksForResident(Resident $resident): array
    {
        return MedicationSchedule::query()
            ->where('resident_id', $resident->id)
            ->whereIn('schedule_type', [
                MedicationSchedule::TYPE_CHRONIC,
                MedicationSchedule::TYPE_TEMP,
                MedicationSchedule::TYPE_PRN,
            ])
            ->with('medication:id,cnk')
            ->get()
            ->pluck('medication.cnk')
            ->filter()
            // Compounded preparations (magistrale bereidingen) start with 9999
            // and aren't in Phil's catalogue — including them causes Phil's
            // SPA to render an empty interactions page.
            ->reject(fn ($cnk) => str_starts_with((string) $cnk, '9999'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function stripHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|header|footer|nav)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $text = strip_tags($html);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Parse Phil's rendered interaction page directly from HTML.
     *
     * The page is structured as two cards: "Geneesmiddeleninteracties" and
     * "Interacties met voedings- of genotmiddelen". Inside each card severity
     * headings ("Ernstig", "Matig ernstig", "Gering") appear in document order,
     * each followed by `<tr>` rows. Each <tr> contains two products of the
     * form `BRAND (INN)`. For food-interactions the second slot is a food/
     * lifestyle item (no parens).
     */
    public function parseWarningsFromHtml(string $html): array
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $out = ['drug_drug' => [], 'drug_food' => [], 'selected' => []];

        // Slice between the two card headers
        if (! preg_match('#Geneesmiddeleninteracties(.*?)(Interacties met voedings[^<]*)(.*?)(?:Contact|Disclaimer|Privacy|$)#s', $html, $m)) {
            return $out;
        }
        $ddiSection = $m[1];
        $foodSection = $m[3];

        $out['drug_drug'] = $this->parseSection($ddiSection, false);
        $out['drug_food'] = $this->parseSection($foodSection, true);
        return $out;
    }

    private function parseSection(string $section, bool $isFood): array
    {
        // Walk severity markers and <tr>...</tr> rows in document order
        if (! preg_match_all('#(<tr[^>]*>.*?</tr>|>Ernstig<|>Matig ernstig<|>Gering<)#s', $section, $tokens)) {
            return [];
        }
        $items = [];
        $sev = 'gering';
        foreach ($tokens[1] as $tok) {
            if (preg_match('#^>(Ernstig|Matig ernstig|Gering)<#', $tok, $sm)) {
                $sev = self::SEVERITIES[$sm[1]] ?? 'gering';
                continue;
            }
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($tok)) ?? '');
            if ($plain === '') continue;

            // Each row contains two products: "BRAND1 (INN1) BRAND2 (INN2)"
            // For food: "BRAND (INN) Food item"
            $row = $this->splitRow($plain, $isFood);
            if ($row === null) continue;
            $items[] = ['severity' => $sev] + $row;
        }
        return $items;
    }

    private function splitRow(string $plain, bool $isFood): ?array
    {
        // Greedy match: capture "<brand> (<inn>)" then the rest
        if (! preg_match('/^(.+?)\s*\(([^()]+)\)\s+(.+)$/u', $plain, $m)) {
            return null;
        }
        $brandA = trim($m[1]);
        $innA = trim($m[2]);
        $rest = trim($m[3]);

        if ($isFood) {
            return ['drug' => ['brand' => $brandA, 'inn' => $innA], 'item' => $rest];
        }
        // Second product also has "(inn)" — match again at the END
        if (preg_match('/^(.+?)\s*\(([^()]+)\)\s*$/u', $rest, $mb)) {
            return [
                'a' => ['brand' => $brandA, 'inn' => $innA],
                'b' => ['brand' => trim($mb[1]), 'inn' => trim($mb[2])],
            ];
        }
        // Fallback: no parens on second product
        return [
            'a' => ['brand' => $brandA, 'inn' => $innA],
            'b' => ['brand' => $rest, 'inn' => ''],
        ];
    }

    public function parseWarnings(string $plain): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $plain)), fn ($l) => $l !== ''));
        $out = ['drug_drug' => [], 'drug_food' => [], 'selected' => []];

        $idxOf = fn (string $token) => array_search($token, $lines, true);
        $ddiStart = $idxOf('Geneesmiddeleninteracties');
        $foodStart = $idxOf('Interacties met voedings- of genotmiddelen');
        $trailer = count($lines);
        foreach (['Contact', 'Disclaimer', 'Privacy', '© Copyright'] as $stop) {
            $t = $idxOf($stop);
            if ($t !== false && $t < $trailer) $trailer = $t;
        }

        $parsePairs = function (int $start, int $end, bool $isFood) use ($lines): array {
            $items = [];
            $sev = '';
            for ($i = $start + 1; $i < $end;) {
                $ln = $lines[$i] ?? '';
                if (isset(self::SEVERITIES[$ln])) {
                    $sev = self::SEVERITIES[$ln];
                    $i++;
                    continue;
                }
                if (! isset($lines[$i + 1])) {
                    $i++;
                    continue;
                }
                $a = $this->parseDrugLine($ln);
                $bLine = $lines[$i + 1];
                if (isset(self::SEVERITIES[$bLine])) {
                    $i++;
                    continue;
                }
                if ($isFood) {
                    $items[] = ['severity' => $sev ?: 'voeding', 'drug' => $a, 'item' => $bLine];
                } else {
                    $items[] = ['severity' => $sev ?: 'gering', 'a' => $a, 'b' => $this->parseDrugLine($bLine)];
                }
                $i += 2;
            }
            return $items;
        };

        if ($ddiStart !== false) {
            $end = $foodStart !== false ? $foodStart : $trailer;
            $out['drug_drug'] = $parsePairs($ddiStart, $end, false);
        }
        if ($foodStart !== false) {
            $out['drug_food'] = $parsePairs($foodStart, $trailer, true);
        }
        return $out;
    }

    private function parseDrugLine(string $line): array
    {
        if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/', $line, $m)) {
            return ['brand' => trim($m[1]), 'inn' => trim($m[2])];
        }
        return ['brand' => trim($line), 'inn' => ''];
    }

    private function persistFindings(PhilInteraction $interaction, array $parsed): void
    {
        foreach ($parsed['drug_drug'] ?? [] as $w) {
            PhilFinding::create([
                'phil_interaction_id' => $interaction->id,
                'severity' => $w['severity'] === 'matig ernstig' ? 'matig' : $w['severity'],
                'med_a' => $w['a']['inn'] ?: $w['a']['brand'],
                'med_b' => $w['b']['inn'] ?: $w['b']['brand'],
            ]);
        }
        foreach ($parsed['drug_food'] ?? [] as $w) {
            PhilFinding::create([
                'phil_interaction_id' => $interaction->id,
                'severity' => 'voeding',
                'med_a' => $w['drug']['inn'] ?: $w['drug']['brand'],
                'med_b' => $w['item'],
            ]);
        }
    }

    private function mirrorIntoReview(PhilInteraction $interaction, Review $review): void
    {
        $review->findings()->where('source', ReviewFinding::SOURCE_PHIL)->delete();
        $position = $review->findings()->max('position') ?? 0;

        foreach ($interaction->findings as $f) {
            $title = match ($f->severity) {
                'voeding' => "{$f->med_a} + {$f->med_b} (voedingsinteractie)",
                default => "{$f->med_a} + {$f->med_b}",
            };
            ReviewFinding::create([
                'review_id' => $review->id,
                'source' => ReviewFinding::SOURCE_PHIL,
                'severity' => $f->severity,
                'title' => $title,
                'body_md' => null,
                'position' => ++$position,
            ]);
        }
    }
}
