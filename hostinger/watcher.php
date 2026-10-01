<?php
/**
 * DLR-Slot-Watcher, PHP-Version für einen Cronjob auf dem eigenen Webspace.
 *
 * Macht dasselbe wie watcher.py: liest Angebote, Kalender und einzelne Termine
 * aus dem bookingkit-Widget der Buchungsseite und schickt eine Push-Nachricht
 * über ntfy, sobald ein Termin buchbar wird. Es wird nichts gebucht.
 *
 * Aufruf:  php watcher.php [--dry-run] [--test] [--state=PFAD]
 * Das ntfy-Topic steht in config.php (siehe config.example.php) oder in der
 * Umgebungsvariable NTFY_TOPIC.
 *
 * Neben state.json (letzter Stand) schreibt das Skript verlauf.json: wann der
 * letzte Lauf war und was sich wann geändert hat. Daraus entsteht einmal am
 * Tag der Morgenbericht, und eine eigene Übersichtsseite kann die Datei lesen.
 */

declare(strict_types=1);

// Nur für den Cronjob gedacht, nie über den Browser.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

const PAGE_URL = 'https://www.lufthansa-aviation-training.com/web/european-flight-academy/anmeldung-dlr-test';
const DEFAULT_BASE = 'https://eu5.bookingkit.de';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const CONNECT_TIMEOUT = 10;      // Sekunden
const TIMEOUT = 25;              // Sekunden
const RETRY_WAIT = 5;            // Sekunden vor dem einzigen zweiten Versuch
const REQUEST_PAUSE = 1.0;       // Sekunden zwischen zwei Abrufen
const MONTHS_AHEAD = 3;          // aktueller Monat plus so viele Folgemonate
const MAX_DAY_REQUESTS = 12;     // Obergrenze für Tagesansichten pro Lauf
const ERROR_COOLDOWN = 6 * 3600; // Sekunden
const MAX_LINES = 12;            // Zeilen pro Push-Nachricht
const LOG_MAX_BYTES = 262144;
const JOURNAL_MAX = 300;          // so viele Ereignisse bleiben in verlauf.json
const REPORT_HOUR = 8;            // Morgenbericht ab dieser Stunde (Europe/Berlin)

const AUSVERKAUFT = 'ausverkauft';
const BUCHBAR = 'buchbar';

const PRIO_URGENT = 5;
const PRIO_HIGH = 4;
const PRIO_DEFAULT = 3;

const WOCHENTAGE = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

class WatchError extends Exception
{
}

class NotifyError extends Exception
{
}

function out(string $text = ''): void
{
    echo $text, "\n";
}

// --------------------------------------------------------------------------- //
// HTTP
// --------------------------------------------------------------------------- //

/** GET mit Timeout und höchstens einem zweiten Versuch. */
function http_get(string $url, array $params = []): string
{
    if ($params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $error = 'unbekannter Fehler';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => TIMEOUT,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
                'Referer: https://www.lufthansa-aviation-training.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);

        if ($body === false || $errno !== 0) {
            $error = 'Verbindungsfehler ' . $errno;
        } elseif ($status === 200) {
            return (string) $body;
        } else {
            $error = 'HTTP ' . $status;
            if ($status < 500) {
                break; // 403, 404, 429: nicht nachhaken
            }
        }
        if ($attempt === 1) {
            sleep(RETRY_WAIT);
        }
    }
    throw new WatchError(parse_url($url, PHP_URL_HOST) . ': ' . $error);
}

/** Holt das HTML aus BookingKitApp.insertBkContent({"html": "..."}, ...). */
function unwrap_jsonp(string $text): string
{
    $marker = 'insertBkContent(';
    $start = strpos($text, $marker);
    if ($start === false) {
        throw new WatchError('Antwort des Widgets hat ein unbekanntes Format');
    }
    $start += strlen($marker);
    if (($text[$start] ?? '') !== '{') {
        throw new WatchError('Antwort des Widgets hat ein unbekanntes Format');
    }
    // Ende des JSON-Objekts suchen: Klammern zählen, Zeichenketten überspringen.
    $depth = 0;
    $inString = false;
    $end = null;
    for ($i = $start, $n = strlen($text); $i < $n; $i++) {
        $c = $text[$i];
        if ($inString) {
            if ($c === '\\') {
                $i++;
            } elseif ($c === '"') {
                $inString = false;
            }
        } elseif ($c === '"') {
            $inString = true;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}' && --$depth === 0) {
            $end = $i;
            break;
        }
    }
    $data = $end === null ? null : json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($data)) {
        throw new WatchError('Antwort des Widgets ist kein gültiges JSON');
    }
    if (!isset($data['html']) || !is_string($data['html'])) {
        throw new WatchError('Antwort des Widgets enthält kein HTML');
    }
    return $data['html'];
}

function widget_get(array $widget, string $path, array $extra = []): string
{
    $params = [
        'cw' => $widget['cw'],
        'targetId' => 'bookingKitContainer',
        'browserlang' => 'de-DE',
        'url' => PAGE_URL,
        'v' => $widget['vendor'],
    ] + $extra;
    usleep((int) (REQUEST_PAUSE * 1000000));
    return unwrap_jsonp(http_get(($widget['base'] ?? DEFAULT_BASE) . '/onPage/' . $path, $params));
}

// --------------------------------------------------------------------------- //
// HTML-Helfer
// --------------------------------------------------------------------------- //

function load_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($doc);
}

function has_class(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
}

function classes(DOMElement $node): array
{
    return preg_split('/\s+/', trim($node->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function text(?DOMNode $node): string
{
    return $node === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
}

function is_sold_out_text(string $text): bool
{
    return preg_match('/ausverkauft|ausgebucht|sold\s*out/i', $text) === 1;
}

// --------------------------------------------------------------------------- //
// Auslesen
// --------------------------------------------------------------------------- //

/** Findet die bookingkit-Widgets auf der Seite. */
function discover_widgets(array $old, array &$problems): array
{
    $known = [];
    foreach ($old['widgets'] ?? [] as $widget) {
        $known[$widget['cw']] = $widget;
    }
    try {
        $page = http_get(PAGE_URL);
    } catch (WatchError $e) {
        if ($known) {
            out('WARNUNG: Seite nicht abrufbar (' . $e->getMessage() . '), nutze das bekannte Widget');
            return array_values($known);
        }
        $problems[] = 'Seite nicht abrufbar (' . $e->getMessage() . ')';
        return [];
    }

    preg_match_all(
        '~https://([0-9a-f]{32})\.widget\.bookingkit\.net/bkscript/([0-9a-f]{32})~',
        $page,
        $matches,
        PREG_SET_ORDER
    );
    $found = [];
    foreach ($matches as $match) {
        $found[$match[2]] = $match[1];
    }
    if (!$found) {
        $problems[] = 'Kein Buchungs-Widget auf der Seite gefunden (umgebaut?)';
        return array_values($known);
    }

    $widgets = [];
    foreach ($found as $cw => $vendor) {
        $cached = $known[$cw] ?? null;
        if ($cached && ($cached['vendor'] ?? '') === $vendor && !empty($cached['base'])) {
            $widgets[] = $cached;
            continue;
        }
        $widget = ['vendor' => $vendor, 'cw' => (string) $cw];
        try {
            usleep((int) (REQUEST_PAUSE * 1000000));
            $script = http_get("https://$vendor.widget.bookingkit.net/bkscript/$cw/");
            if (preg_match('~"(https://[a-z0-9-]+\.bookingkit\.(?:de|com|net))/onPage/~', $script, $m)) {
                $widget['base'] = $m[1];
            }
        } catch (WatchError $e) {
            out('WARNUNG: Widget-Skript nicht abrufbar (' . $e->getMessage() . '), nutze Standard');
        }
        $widgets[] = $widget;
    }
    return $widgets;
}

/** Liest die Termine aus dem JSON-LD-Block des Widgets. */
function ld_events(DOMXPath $xp): array
{
    $events = [];
    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $data = json_decode($script->textContent, true);
        if (!is_array($data)) {
            continue;
        }
        $graph = $data['@graph'] ?? [$data];
        foreach (is_array($graph) ? $graph : [] as $node) {
            if (!is_array($node) || ($node['@type'] ?? '') !== 'Event') {
                continue;
            }
            $id = (string) ($node['@id'] ?? '');
            $start = (string) ($node['startDate'] ?? '');
            if ($id === '' || $start === '') {
                continue;
            }
            $offer = $node['offers'] ?? null;
            if (is_array($offer) && isset($offer[0])) {
                $offer = $offer[0];
            }
            $availability = is_array($offer) ? (string) ($offer['availability'] ?? '') : '';
            $name = (string) ($node['name'] ?? '');
            $cut = strrpos($name, ' | ');
            $events[] = [
                'id' => $id,
                'angebot' => explode('-', $id, 2)[0],
                'titel' => trim($cut === false ? $name : substr($name, 0, $cut)),
                'start' => $start,
                // null = keine Angabe, dann entscheidet die Angebotskarte
                'sold_out' => $availability === ''
                    ? null
                    : preg_match('/(OutOfStock|SoldOut|Discontinued)$/', $availability) === 1,
            ];
        }
    }
    return $events;
}

/** Liest die Angebotskarten: Titel, Ort, Button, ausverkauft ja/nein. */
function card_infos(DOMXPath $xp): array
{
    $cards = [];
    foreach ($xp->query('//*[' . has_class('bk-events-item') . ']') as $item) {
        $id = null;
        if (preg_match('/[0-9a-f]{32}/', $item->getAttribute('id'), $m)) {
            $id = $m[0];
        } else {
            $link = $xp->query('.//a[starts-with(@href, "#!/e/")]', $item)->item(0);
            if ($link && preg_match('/[0-9a-f]{32}/', $link->getAttribute('href'), $m)) {
                $id = $m[0];
            }
        }
        if ($id === null) {
            continue;
        }
        $title = $xp->query('.//h2 | .//*[' . has_class('bk-medium-title') . ']', $item)->item(0);
        $place = $xp->query('.//*[' . has_class('bk_list_location') . ']', $item)->item(0);
        $buttons = [];
        foreach ($xp->query('.//*[' . has_class('bk-date-btn') . ']', $item) as $button) {
            $buttons[text($button)] = true;
        }
        $buttons = array_map('strval', array_keys($buttons));
        $soldOut = in_array('bk-sold-out', classes($item), true)
            || ($buttons && count(array_filter($buttons, 'is_sold_out_text')) === count($buttons));
        $cards[$id] = [
            'titel' => $title ? text($title) : '(ohne Titel)',
            'ort' => text($place),
            'button' => implode(' / ', $buttons),
            'sold_out' => $soldOut,
        ];
    }
    return $cards;
}

function parse_offers(string $html): array
{
    $xp = load_dom($html);
    $events = ld_events($xp);
    $offers = [];
    foreach (card_infos($xp) as $offerId => $card) {
        $own = array_values(array_filter($events, function ($e) use ($offerId) {
            return $e['angebot'] === $offerId;
        }));
        usort($own, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });
        // Im Zweifel "buchbar": lieber ein Fehlalarm als ein verpasster Termin.
        $anyFree = false;
        foreach ($own as $event) {
            $anyFree = $anyFree || $event['sold_out'] === false;
        }
        $offers[$offerId] = [
            'titel' => $card['titel'],
            'ort' => $card['ort'],
            'status' => ($card['sold_out'] && !$anyFree) ? AUSVERKAUFT : BUCHBAR,
            'button' => $card['button'],
            'naechster_termin' => $own ? $own[0]['start'] : '',
        ];
    }
    return $offers;
}

/** Liefert [Datum => Status] für alle Tage eines Monats, an denen es Termine gibt. */
function parse_calendar(string $html): array
{
    $xp = load_dom($html);
    $cells = $xp->query('//*[' . has_class('calendar-day-number') . '][@data-date]');
    if ($cells->length === 0) {
        throw new WatchError('Kalender des Widgets hat ein unbekanntes Format');
    }
    $days = [];
    foreach ($cells as $cell) {
        $classes = classes($cell);
        $date = $cell->getAttribute('data-date');
        if (in_array('sold-out-day', $classes, true)
            || $xp->query('.//*[' . has_class('bk-sold-out-center') . ']', $cell)->length > 0) {
            $days[$date] = AUSVERKAUFT;
        } elseif (array_intersect($classes, ['bk-green-day', 'bk-cal-action'])
            || $xp->query('.//a', $cell)->length > 0) {
            $days[$date] = BUCHBAR;
        }
    }
    return $days;
}

/** Liefert die einzelnen Termine eines Tages. */
function parse_day(string $html, string $day): array
{
    $xp = load_dom($html);
    $cards = card_infos($xp);
    $slots = [];
    foreach (ld_events($xp) as $event) {
        if (strpos($event['start'], $day) !== 0) {
            continue;
        }
        $card = $cards[$event['angebot']] ?? null;
        $soldOut = $event['sold_out'] !== false && ($card === null || $card['sold_out']);
        $slots[$event['id']] = [
            'angebot' => $event['angebot'],
            'titel' => $event['titel'] !== '' ? $event['titel'] : ($card['titel'] ?? ''),
            'start' => $event['start'],
            'status' => $soldOut ? AUSVERKAUFT : BUCHBAR,
        ];
    }
    return $slots;
}

function months_to_check(DateTimeImmutable $today): array
{
    $months = [];
    $year = (int) $today->format('Y');
    $month = (int) $today->format('n');
    for ($i = 0; $i <= MONTHS_AHEAD; $i++) {
        $months[] = [$year, $month];
        if (++$month > 12) {
            $month = 1;
            $year++;
        }
    }
    return $months;
}

function read_offers(array $widgets, array &$problems): ?array
{
    $offers = [];
    try {
        foreach ($widgets as $widget) {
            $offers = parse_offers(widget_get($widget, 'list/')) + $offers;
        }
    } catch (WatchError $e) {
        $problems[] = 'Angebotsliste nicht lesbar (' . $e->getMessage() . ')';
        return null;
    }
    if (!$offers) {
        $problems[] = '0 Angebote gefunden (Seite umgebaut oder Widget leer?)';
        return null;
    }
    return $offers;
}

/** Liest Kalender und, wo nötig, die Tagesansichten. */
function read_days(array $widgets, ?array $oldDays, DateTimeImmutable $today, array &$problems): ?array
{
    $todayIso = $today->format('Y-m-d');
    $calendar = [];
    try {
        foreach ($widgets as $widget) {
            foreach (months_to_check($today) as [$year, $month]) {
                $html = widget_get($widget, 'calendar', ['month' => $month, 'year' => $year]);
                foreach (parse_calendar($html) as $day => $status) {
                    if ($day < $todayIso) {
                        continue;
                    }
                    if (($calendar[$day] ?? null) !== BUCHBAR) {
                        $calendar[$day] = $status;
                    }
                }
            }
        }
    } catch (WatchError $e) {
        $problems[] = 'Terminkalender nicht lesbar (' . $e->getMessage() . ')';
        return null;
    }

    $oldDays = $oldDays ?? [];
    $days = [];
    $wanted = [];
    foreach ($calendar as $day => $status) {
        $days[$day] = ['status' => $status];
        $previous = $oldDays[$day] ?? null;
        if ($previous !== null && array_key_exists('termine', $previous)) {
            $days[$day]['termine'] = $previous['termine'];
        }
        if ($previous === null
            || !array_key_exists('termine', $previous)
            || $previous['status'] !== $status
            || $status === BUCHBAR) {
            $wanted[] = $day;
        }
    }

    // Buchbare Tage zuerst, falls die Obergrenze greift.
    usort($wanted, function ($a, $b) use ($calendar) {
        return [$calendar[$a] !== BUCHBAR, $a] <=> [$calendar[$b] !== BUCHBAR, $b];
    });
    foreach (array_slice($wanted, 0, MAX_DAY_REQUESTS) as $day) {
        try {
            $slots = [];
            foreach ($widgets as $widget) {
                $slots = parse_day(widget_get($widget, 'eventsByDate/', ['date' => $day]), $day) + $slots;
            }
            $days[$day]['termine'] = $slots;
        } catch (WatchError $e) {
            out("WARNUNG: Tagesansicht $day nicht lesbar (" . $e->getMessage() . ')');
        }
    }
    if (count($wanted) > MAX_DAY_REQUESTS) {
        out('Hinweis: ' . (count($wanted) - MAX_DAY_REQUESTS) . ' Tagesansichten folgen im nächsten Lauf.');
    }
    return $days;
}

// --------------------------------------------------------------------------- //
// Vergleich
// --------------------------------------------------------------------------- //

function fmt_start(string $iso): string
{
    try {
        $moment = new DateTimeImmutable($iso);
    } catch (Exception $e) {
        return $iso;
    }
    return WOCHENTAGE[(int) $moment->format('N')] . ' ' . $moment->format('d.m.Y H:i');
}

function fmt_day(string $iso): string
{
    try {
        $day = new DateTimeImmutable($iso);
    } catch (Exception $e) {
        return $iso;
    }
    return WOCHENTAGE[(int) $day->format('N')] . ' ' . $day->format('d.m.Y');
}

function slot_line(array $slot): string
{
    return fmt_start($slot['start']) . ': ' . $slot['titel'];
}

function sort_by_start(array $slots): array
{
    uasort($slots, function ($a, $b) {
        return strcmp($a['start'], $b['start']);
    });
    return $slots;
}

/** Liefert [frei gewordene, neu aufgetauchte] Angebote und Termine als Textzeilen. */
function compare(array $old, array $new): array
{
    $free = [];
    $fresh = [];

    $oldOffers = $old['angebote'] ?? null;
    foreach ($new['angebote'] ?? [] as $offerId => $offer) {
        $previous = $oldOffers[$offerId] ?? null;
        if ($offer['status'] === BUCHBAR && ($previous === null || $previous['status'] !== BUCHBAR)) {
            $free[] = 'Angebot buchbar: ' . $offer['titel'];
        } elseif ($previous === null && $oldOffers !== null) {
            $fresh[] = 'Neues Angebot (' . $offer['status'] . '): ' . $offer['titel'];
        }
    }

    $oldDays = $old['tage'] ?? null;
    $newDays = $new['tage'] ?? [];
    ksort($newDays, SORT_STRING);
    foreach ($newDays as $day => $info) {
        $day = (string) $day;
        $previous = $oldDays[$day] ?? null;
        $slots = $info['termine'] ?? [];
        $oldSlots = $previous['termine'] ?? null;
        $dayIsNew = $previous === null;
        $dayOpened = $info['status'] === BUCHBAR && ($dayIsNew || $previous['status'] !== BUCHBAR);

        if (!$slots) {
            if ($dayOpened) {
                $free[] = 'Termin buchbar: ' . fmt_day($day);
            } elseif ($dayIsNew && $oldDays !== null) {
                $fresh[] = 'Neuer Termin (' . $info['status'] . '): ' . fmt_day($day);
            }
            continue;
        }

        $freeBefore = count($free);
        foreach (sort_by_start($slots) as $slotId => $slot) {
            $before = $oldSlots[$slotId] ?? null;
            $slotIsNew = $before === null && ($dayIsNew || $oldSlots !== null);
            if ($slot['status'] === BUCHBAR
                && ($slotIsNew || $dayOpened || ($before !== null && $before['status'] !== BUCHBAR))) {
                $free[] = 'Termin buchbar: ' . slot_line($slot);
            } elseif ($slotIsNew && $oldDays !== null) {
                $fresh[] = 'Neuer Termin (' . $slot['status'] . '): ' . slot_line($slot);
            }
        }
        if ($dayOpened && count($free) === $freeBefore) {
            // Kalender meldet den Tag als buchbar, die Tagesansicht nennt keinen Termin.
            $free[] = 'Termin buchbar: ' . fmt_day($day);
        }
    }

    return [array_values(array_unique($free)), array_values(array_unique($fresh))];
}

// --------------------------------------------------------------------------- //
// Benachrichtigung und Status
// --------------------------------------------------------------------------- //

function notify(string $topic, string $title, array $lines, int $priority, bool $dryRun, string $click = PAGE_URL): void
{
    $shown = array_slice($lines, 0, MAX_LINES);
    if (count($lines) > MAX_LINES) {
        $shown[] = 'und ' . (count($lines) - MAX_LINES) . ' weitere';
    }
    out("Push (Priorität $priority): $title");
    foreach ($shown as $line) {
        out('    ' . $line);
    }
    if ($dryRun) {
        out('    [Probelauf: nicht gesendet]');
        return;
    }
    // Das Topic steht nur im Request-Body, nie in URL oder Log.
    $payload = json_encode([
        'topic' => $topic,
        'title' => $title,
        'message' => implode("\n", $shown),
        'priority' => $priority,
        'click' => $click,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $server = rtrim(getenv('NTFY_SERVER') ?: 'https://ntfy.sh', '/');
    $error = 'unbekannter Fehler';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($server);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => TIMEOUT,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: dlr-slot-watcher'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        if ($body !== false && $errno === 0 && $status === 200) {
            out('    gesendet');
            return;
        }
        $error = $errno !== 0 ? 'Verbindungsfehler ' . $errno : 'HTTP ' . $status;
        if ($attempt === 1) {
            sleep(RETRY_WAIT);
        }
    }
    throw new NotifyError('ntfy: ' . $error);
}

function load_state(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $state = json_decode((string) file_get_contents($path), true);
    if (!is_array($state)) {
        out('WARNUNG: state.json ist nicht lesbar, starte mit leerem Stand');
        return [];
    }
    return $state;
}

function sort_keys($value)
{
    if (is_array($value)) {
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = sort_keys($item);
        }
    }
    return $value;
}

function save_state(string $path, array $state): bool
{
    // Leere Terminlisten als {} schreiben, damit das Format zur Python-Version passt.
    foreach ($state['tage'] ?? [] as $day => $info) {
        if (array_key_exists('termine', $info) && !$info['termine']) {
            $state['tage'][$day]['termine'] = new stdClass();
        }
    }
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $text = json_encode(sort_keys($state), $flags) . "\n";
    if (is_file($path) && file_get_contents($path) === $text) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $text) === false || !rename($tmp, $path)) {
        throw new RuntimeException('state.json konnte nicht geschrieben werden');
    }
    return true;
}

function print_overview(array $state): void
{
    $offers = $state['angebote'] ?? [];
    out('Angebote (' . count($offers) . '):');
    foreach ($offers as $offer) {
        out('  [' . $offer['status'] . '] ' . $offer['titel']);
        out('      Ort: ' . ($offer['ort'] !== '' ? $offer['ort'] : 'unbekannt'));
        out('      Button: ' . ($offer['button'] !== '' ? $offer['button'] : 'keiner'));
        if ($offer['naechster_termin'] !== '') {
            out('      Nächster Termin: ' . fmt_start($offer['naechster_termin']));
        }
    }
    if (!isset($state['tage'])) {
        out('Termine: in diesem Lauf nicht gelesen');
        return;
    }
    $days = $state['tage'];
    ksort($days, SORT_STRING);
    out('Tage mit Terminen (' . count($days) . '):');
    foreach ($days as $day => $info) {
        out('  [' . $info['status'] . '] ' . fmt_day((string) $day));
        foreach (sort_by_start($info['termine'] ?? []) as $slot) {
            out('      [' . $slot['status'] . '] ' . slot_line($slot));
        }
    }
}

function error_due(array $state, int $now): bool
{
    $last = strtotime((string) ($state['fehler']['letzte_meldung'] ?? ''));
    return $last === false || $now - $last >= ERROR_COOLDOWN;
}

/** Eine Zeile pro Lauf in watcher.log, damit man den Verlauf nachsehen kann. */
function write_log(string $dir, string $line): void
{
    $path = $dir . '/watcher.log';
    if (is_file($path) && filesize($path) > LOG_MAX_BYTES) {
        $lines = file($path) ?: [];
        file_put_contents($path, implode('', array_slice($lines, (int) (count($lines) / 2))));
    }
    file_put_contents($path, gmdate('Y-m-d H:i:s') . ' UTC  ' . $line . "\n", FILE_APPEND);
}

function read_config(): array
{
    $file = __DIR__ . '/config.php';
    $config = is_file($file) ? require $file : [];
    return is_array($config) ? $config : [];
}

function read_topic(array $config): string
{
    $topic = getenv('NTFY_TOPIC');
    if (is_string($topic) && trim($topic) !== '') {
        return trim($topic);
    }
    return trim((string) ($config['ntfy_topic'] ?? ''));
}

// --------------------------------------------------------------------------- //
// Verlauf und Morgenbericht
// --------------------------------------------------------------------------- //

/** Änderungen ohne Push: wieder ausverkauft oder entfallen. Nur für den Verlauf. */
function quiet_changes(array $old, array $new, string $todayIso): array
{
    $events = [];
    foreach ($old['angebote'] ?? [] as $offerId => $offer) {
        $now = $new['angebote'][$offerId] ?? null;
        if ($now === null) {
            $events[] = ['entfallen', 'Angebot entfernt: ' . $offer['titel']];
        } elseif ($offer['status'] === BUCHBAR && $now['status'] !== BUCHBAR) {
            $events[] = ['ausverkauft', 'Wieder ausverkauft: Angebot ' . $offer['titel']];
        }
    }
    if (!isset($new['tage'])) {
        return $events;
    }
    $oldDays = $old['tage'] ?? [];
    ksort($oldDays, SORT_STRING);
    foreach ($oldDays as $day => $info) {
        $day = (string) $day;
        if ($day < $todayIso) {
            continue; // vergangene Tage fallen still heraus
        }
        $now = $new['tage'][$day] ?? null;
        $slots = sort_by_start($info['termine'] ?? []);
        if ($now === null) {
            if (!$slots) {
                $events[] = ['entfallen', 'Termin entfallen: ' . fmt_day($day)];
            }
            foreach ($slots as $slot) {
                $events[] = ['entfallen', 'Termin entfallen: ' . slot_line($slot)];
            }
            continue;
        }
        if (!array_key_exists('termine', $now)) {
            continue;
        }
        foreach ($slots as $slotId => $slot) {
            $after = $now['termine'][$slotId] ?? null;
            if ($after === null) {
                $events[] = ['entfallen', 'Termin entfallen: ' . slot_line($slot)];
            } elseif ($slot['status'] === BUCHBAR && $after['status'] !== BUCHBAR) {
                $events[] = ['ausverkauft', 'Wieder ausverkauft: ' . slot_line($slot)];
            }
        }
    }
    return $events;
}

function load_journal(string $path): array
{
    $journal = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $journal = is_array($journal) ? $journal : [];
    $journal['ereignisse'] = array_values(array_filter($journal['ereignisse'] ?? [], 'is_array'));
    $journal['bericht'] = ($journal['bericht'] ?? []) + ['datum' => '', 'seit' => '', 'laeufe' => 0, 'probleme' => 0];
    return $journal;
}

function save_journal(string $path, array $journal): void
{
    $journal['ereignisse'] = array_slice($journal['ereignisse'], -JOURNAL_MAX);
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, json_encode($journal, $flags) . "\n") !== false) {
        rename($tmp, $path);
    }
}

function greeting(DateTimeImmutable $berlin, string $name): string
{
    $hour = (int) $berlin->format('G');
    $text = $hour < 11 ? 'Guten Morgen' : ($hour < 18 ? 'Hallo' : 'Guten Abend');
    return $name !== '' ? "$text, $name" : $text;
}

function stand_line(array $state): string
{
    $offers = $state['angebote'] ?? [];
    $days = $state['tage'] ?? [];
    $isFree = function ($x) {
        return ($x['status'] ?? '') === BUCHBAR;
    };
    $freeOffers = count(array_filter($offers, $isFree));
    $freeDays = count(array_filter($days, $isFree));
    if ($freeOffers === 0 && $freeDays === 0) {
        return 'Stand: ' . count($offers) . ' Angebote, ' . count($days) . ' Tage mit Terminen, alles ausverkauft.';
    }
    return 'Stand: ' . $freeOffers . ' von ' . count($offers) . ' Angeboten buchbar, '
        . $freeDays . ' von ' . count($days) . ' Tagen buchbar.';
}

/** Textzeilen für den Morgenbericht: was seit dem letzten Bericht passiert ist. */
function report_lines(array $state, array $journal): array
{
    $report = $journal['bericht'];
    $berlin = new DateTimeZone('Europe/Berlin');
    $since = $report['seit'] !== '' ? strtotime($report['seit']) : 0;
    $events = array_values(array_filter($journal['ereignisse'], function ($e) use ($since) {
        return strtotime((string) ($e['zeit'] ?? '')) > $since;
    }));
    $span = $report['datum'] !== '' ? 'seit gestern' : 'seit dem Start';
    $lines = [];
    if (!$events) {
        $lines[] = "DLR-Termine: keine Änderung $span.";
    } else {
        $lines[] = 'DLR-Termine: ' . count($events) . (count($events) === 1 ? ' Neuigkeit ' : ' Neuigkeiten ') . $span . ':';
        foreach (array_slice($events, -8) as $event) {
            $when = (new DateTimeImmutable((string) $event['zeit']))->setTimezone($berlin);
            $lines[] = WOCHENTAGE[(int) $when->format('N')] . ' ' . $when->format('H:i') . ' ' . $event['text'];
        }
    }
    $lines[] = stand_line($state);
    $lines[] = 'Geprüft: ' . $report['laeufe'] . ' mal, '
        . ($report['probleme'] > 0 ? 'davon ' . $report['probleme'] . ' mal mit Problem.' : 'ohne Problem.');
    return $lines;
}

function main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);
    $testMode = in_array('--test', $argv, true);
    $statePath = __DIR__ . '/state.json';
    foreach ($argv as $arg) {
        if (strpos($arg, '--state=') === 0) {
            $statePath = substr($arg, 8);
        }
    }

    $config = read_config();
    $topic = read_topic($config);
    if ($topic === '' && !$dryRun) {
        out('FEHLER: Kein ntfy-Topic gesetzt (config.php oder NTFY_TOPIC).');
        return 2;
    }

    $now = time();
    out('DLR-Slot-Watcher, Lauf vom ' . gmdate('d.m.Y H:i', $now) . ' UTC');

    if ($testMode) {
        try {
            notify(
                $topic,
                'DLR-Watcher: Test',
                ['Testnachricht. Der Watcher läuft und kann dich erreichen.'],
                PRIO_DEFAULT,
                $dryRun
            );
        } catch (NotifyError $e) {
            out('FEHLER: Testnachricht nicht zugestellt (' . $e->getMessage() . ')');
            return 1;
        }
        return 0;
    }

    // Nie zwei Läufe gleichzeitig.
    $lock = fopen($statePath . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        out('Ein anderer Lauf ist noch aktiv, dieser wird übersprungen.');
        return 0;
    }

    $old = load_state($statePath);
    $new = $old;
    $new['version'] = 1;
    $problems = [];

    $widgets = discover_widgets($old, $problems);
    if ($widgets) {
        $new['widgets'] = $widgets;
        out('Buchungs-Widgets gefunden: ' . count($widgets));
        $offers = read_offers($widgets, $problems);
        if ($offers !== null) {
            $new['angebote'] = $offers;
            $today = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
            $days = read_days($widgets, $old['tage'] ?? null, $today, $problems);
            if ($days !== null) {
                $new['tage'] = $days;
            }
        }
    }

    out();
    print_overview($new);
    out();

    [$free, $fresh] = compare($old, $new);
    if (empty($old['angebote']) && !empty($new['angebote'])) {
        out('Erster Lauf: Stand wird als Ausgangspunkt gespeichert.');
    }
    if (!$free && !$fresh) {
        out('Änderungen: keine');
    }

    $summary = count($new['angebote'] ?? []) . ' Angebote, ' . count($new['tage'] ?? []) . ' Tage';
    $logDir = dirname($statePath);
    $journalPath = $logDir . '/verlauf.json';
    $journal = load_journal($journalPath);
    $stamp = gmdate('Y-m-d\TH:i:s+00:00', $now);
    $berlinNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $journal['letzter_lauf'] = $stamp;
    $journal['letzter_lauf_ok'] = !$problems;
    $journal['bericht']['laeufe']++;
    if ($problems) {
        $journal['bericht']['probleme']++;
    }

    try {
        if ($free) {
            notify($topic, 'DLR-Termin frei!', $free, PRIO_URGENT, $dryRun);
        }
        if ($fresh) {
            notify($topic, 'DLR: Neues Angebot oder neuer Termin', $fresh, PRIO_HIGH, $dryRun);
        }
    } catch (NotifyError $e) {
        // Stand nicht speichern, damit der nächste Lauf die Änderung erneut meldet.
        out('FEHLER: Push nicht zugestellt (' . $e->getMessage() . '). Stand bleibt unverändert.');
        write_log($logDir, 'FEHLER Push nicht zugestellt: ' . $e->getMessage());
        $journal['letzter_lauf_ok'] = false;
        save_journal($journalPath, $journal);
        return 1;
    }

    foreach ($free as $line) {
        $journal['ereignisse'][] = ['zeit' => $stamp, 'art' => 'frei', 'text' => $line];
    }
    foreach ($fresh as $line) {
        $journal['ereignisse'][] = ['zeit' => $stamp, 'art' => 'neu', 'text' => $line];
    }
    foreach (quiet_changes($old, $new, $berlinNow->format('Y-m-d')) as [$kind, $line]) {
        $journal['ereignisse'][] = ['zeit' => $stamp, 'art' => $kind, 'text' => $line];
    }

    $exitCode = 0;
    if ($problems) {
        foreach ($problems as $problem) {
            out('WARNUNG: ' . $problem);
        }
        if (error_due($old, $now)) {
            try {
                notify($topic, 'DLR-Watcher: Problem', $problems, PRIO_DEFAULT, $dryRun);
                $new['fehler'] = ['letzte_meldung' => $stamp];
                $journal['ereignisse'][] = ['zeit' => $stamp, 'art' => 'problem', 'text' => 'Problem: ' . implode('; ', $problems)];
            } catch (NotifyError $e) {
                out('FEHLER: Fehlermeldung nicht zugestellt (' . $e->getMessage() . ')');
                $exitCode = 1;
            }
        } else {
            out('Fehlermeldung unterdrückt (höchstens eine alle 6 Stunden).');
        }
    }

    // Morgenbericht: einmal am Tag, beim ersten Lauf ab REPORT_HOUR. Mit 'bericht' => false
    // in config.php bleibt er aus, etwa wenn ein eigenes Tagesbriefing den Verlauf auswertet.
    $reportHour = (int) ($config['bericht_stunde'] ?? REPORT_HOUR);
    $reportOn = ($config['bericht'] ?? true) !== false;
    if ($reportOn && (int) $berlinNow->format('G') >= $reportHour && $journal['bericht']['datum'] !== $berlinNow->format('Y-m-d')) {
        try {
            notify(
                $topic,
                greeting($berlinNow, trim((string) ($config['name'] ?? ''))),
                report_lines($new, $journal),
                PRIO_DEFAULT,
                $dryRun,
                (string) ($config['bericht_link'] ?? PAGE_URL)
            );
            $journal['bericht'] = ['datum' => $berlinNow->format('Y-m-d'), 'seit' => $stamp, 'laeufe' => 0, 'probleme' => 0];
        } catch (NotifyError $e) {
            out('WARNUNG: Morgenbericht nicht zugestellt (' . $e->getMessage() . '), neuer Versuch im nächsten Lauf.');
        }
    }

    out(save_state($statePath, $new) ? 'state.json aktualisiert.' : 'state.json unverändert.');
    save_journal($journalPath, $journal);

    if ($problems) {
        $summary = 'PROBLEM ' . implode('; ', $problems);
    } elseif ($free || $fresh) {
        $summary .= ', GEMELDET: ' . implode('; ', array_merge($free, $fresh));
    } else {
        $summary .= ', keine Änderung';
    }
    write_log($logDir, $summary);
    return $exitCode;
}

// Beim Einbinden aus einem Test nicht automatisch starten.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main(array_slice($_SERVER['argv'] ?? [], 1)));
}
