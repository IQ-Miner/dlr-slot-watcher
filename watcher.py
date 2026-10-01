#!/usr/bin/env python3
"""DLR-Slot-Watcher.

Überwacht die Buchungsseite für den DLR-Test der European Flight Academy und
schickt eine Push-Nachricht über ntfy, sobald ein Termin buchbar wird.

Die Angebote stehen nicht im HTML der Seite selbst. Die Seite bindet ein
bookingkit-Widget ein, das seine Inhalte als JSONP (HTML in einem JSON-Objekt)
nachlädt. Genau diese Aufrufe macht das Skript auch, nur ohne Browser:

  1. Seite abrufen und das Widget (Anbieter-ID und Widget-ID) finden
  2. Angebotsliste abrufen: Titel, Ort, Status je Angebot
  3. Kalender der nächsten Monate abrufen: Status je Tag
  4. Tagesansicht nur für neue, geänderte oder buchbare Tage: Status je Termin

Es wird nichts gebucht, nur gelesen und benachrichtigt.
"""

from __future__ import annotations

import argparse
import copy
import json
import os
import re
import sys
import time
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import requests
from bs4 import BeautifulSoup

PAGE_URL = (
    "https://www.lufthansa-aviation-training.com/web/european-flight-academy/"
    "anmeldung-dlr-test"
)
DEFAULT_BASE = "https://eu5.bookingkit.de"
NTFY_SERVER = os.environ.get("NTFY_SERVER", "https://ntfy.sh").rstrip("/")
STATE_FILE = Path(__file__).with_name("state.json")

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"
)
TIMEOUT = (10, 25)  # Sekunden: Verbindungsaufbau, Antwort
RETRY_WAIT = 5  # Sekunden vor dem einzigen zweiten Versuch
REQUEST_PAUSE = 1.0  # Sekunden zwischen zwei Abrufen
MONTHS_AHEAD = 3  # aktueller Monat plus so viele Folgemonate
MAX_DAY_REQUESTS = 12  # Obergrenze für Tagesansichten pro Lauf
ERROR_COOLDOWN = timedelta(hours=6)
MAX_LINES = 12  # Zeilen pro Push-Nachricht

AUSVERKAUFT = "ausverkauft"
BUCHBAR = "buchbar"

PRIO_URGENT, PRIO_HIGH, PRIO_DEFAULT = 5, 4, 3

BERLIN = ZoneInfo("Europe/Berlin")
WOCHENTAGE = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"]

WIDGET_RE = re.compile(
    r"https://([0-9a-f]{32})\.widget\.bookingkit\.net/bkscript/([0-9a-f]{32})"
)
BASE_RE = re.compile(r'"(https://[a-z0-9-]+\.bookingkit\.(?:de|com|net))/onPage/')
SOLD_OUT_TEXT_RE = re.compile(r"ausverkauft|ausgebucht|sold\s*out", re.I)
SOLD_OUT_LD = ("OutOfStock", "SoldOut", "Discontinued")


class WatchError(Exception):
    """Die Seite oder das Widget konnte nicht gelesen werden."""


class NotifyError(Exception):
    """Die Push-Nachricht konnte nicht zugestellt werden."""


def log(text: str = "") -> None:
    print(text, flush=True)


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #


def http_get(session: requests.Session, url: str, params: dict | None = None) -> str:
    """GET mit Timeout und höchstens einem zweiten Versuch."""
    error = "unbekannter Fehler"
    for attempt in (1, 2):
        try:
            response = session.get(url, params=params, timeout=TIMEOUT)
        except requests.RequestException as exc:
            error = type(exc).__name__
        else:
            if response.status_code == 200:
                return response.text
            error = f"HTTP {response.status_code}"
            if response.status_code < 500:
                break  # 403, 404, 429: nicht nachhaken
        if attempt == 1:
            time.sleep(RETRY_WAIT)
    host = re.sub(r"^https?://([^/]+).*$", r"\1", url)
    raise WatchError(f"{host}: {error}")


def unwrap_jsonp(text: str) -> str:
    """Holt das HTML aus BookingKitApp.insertBkContent({"html": "..."}, ...)."""
    marker = "insertBkContent("
    start = text.find(marker)
    if start < 0:
        raise WatchError("Antwort des Widgets hat ein unbekanntes Format")
    try:
        data, _ = json.JSONDecoder().raw_decode(text, start + len(marker))
    except ValueError as exc:
        raise WatchError("Antwort des Widgets ist kein gültiges JSON") from exc
    html = data.get("html") if isinstance(data, dict) else None
    if not isinstance(html, str):
        raise WatchError("Antwort des Widgets enthält kein HTML")
    return html


def widget_get(session: requests.Session, widget: dict, path: str, **extra) -> str:
    params = {
        "cw": widget["cw"],
        "targetId": "bookingKitContainer",
        "browserlang": "de-DE",
        "url": PAGE_URL,
        "v": widget["vendor"],
        **extra,
    }
    base = widget.get("base", DEFAULT_BASE)
    time.sleep(REQUEST_PAUSE)
    return unwrap_jsonp(http_get(session, f"{base}/onPage/{path}", params))


# --------------------------------------------------------------------------- #
# Auslesen
# --------------------------------------------------------------------------- #


def discover_widgets(session: requests.Session, old: dict, problems: list) -> list:
    """Findet die bookingkit-Widgets auf der Seite."""
    known = {w["cw"]: w for w in old.get("widgets", [])}
    try:
        page = http_get(session, PAGE_URL)
    except WatchError as exc:
        if known:
            log(f"::warning::Seite nicht abrufbar ({exc}), nutze das bekannte Widget")
            return list(known.values())
        problems.append(f"Seite nicht abrufbar ({exc})")
        return []

    found = list(dict.fromkeys(WIDGET_RE.findall(page)))
    if not found:
        problems.append("Kein Buchungs-Widget auf der Seite gefunden (umgebaut?)")
        return list(known.values())

    widgets = []
    for vendor, cw in found:
        cached = known.get(cw)
        if cached and cached.get("vendor") == vendor and cached.get("base"):
            widgets.append(cached)
            continue
        widget = {"vendor": vendor, "cw": cw}
        try:
            time.sleep(REQUEST_PAUSE)
            script = http_get(
                session, f"https://{vendor}.widget.bookingkit.net/bkscript/{cw}/"
            )
            match = BASE_RE.search(script)
            if match:
                widget["base"] = match.group(1)
        except WatchError as exc:
            log(f"::warning::Widget-Skript nicht abrufbar ({exc}), nutze Standard")
        widgets.append(widget)
    return widgets


def ld_events(soup: BeautifulSoup) -> list:
    """Liest die Termine aus dem JSON-LD-Block des Widgets."""
    events = []
    for script in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(script.string or script.get_text())
        except ValueError:
            continue
        graph = data.get("@graph", [data]) if isinstance(data, dict) else data
        for node in graph if isinstance(graph, list) else []:
            if not isinstance(node, dict) or node.get("@type") != "Event":
                continue
            ident = str(node.get("@id", ""))
            start = str(node.get("startDate", ""))
            if not ident or not start:
                continue
            offers = node.get("offers")
            offer = offers[0] if isinstance(offers, list) and offers else offers
            availability = ""
            if isinstance(offer, dict):
                availability = str(offer.get("availability", ""))
            events.append(
                {
                    "id": ident,
                    "angebot": ident.split("-", 1)[0],
                    "titel": str(node.get("name", "")).rsplit(" | ", 1)[0].strip(),
                    "start": start,
                    # None = keine Angabe, dann entscheidet die Angebotskarte
                    "sold_out": availability.endswith(SOLD_OUT_LD) if availability else None,
                }
            )
    return events


def card_infos(soup: BeautifulSoup) -> dict:
    """Liest die Angebotskarten: Titel, Ort, Button, ausverkauft ja/nein."""
    cards = {}
    for item in soup.select(".bk-events-item"):
        match = re.search(r"([0-9a-f]{32})", item.get("id", ""))
        if not match:
            link = item.select_one('a[href^="#!/e/"]')
            match = re.search(r"([0-9a-f]{32})", link["href"]) if link else None
        if not match:
            continue
        title = item.select_one("h2, .bk-medium-title")
        place = item.select_one(".bk_list_location")
        buttons = list(
            dict.fromkeys(
                b.get_text(" ", strip=True) for b in item.select(".bk-date-btn")
            )
        )
        sold_out = "bk-sold-out" in item.get("class", []) or (
            bool(buttons) and all(SOLD_OUT_TEXT_RE.search(b) for b in buttons)
        )
        cards[match.group(1)] = {
            "titel": title.get_text(" ", strip=True) if title else "(ohne Titel)",
            "ort": place.get_text(" ", strip=True) if place else "",
            "button": " / ".join(buttons),
            "sold_out": sold_out,
        }
    return cards


def parse_offers(html: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    events = ld_events(soup)
    offers = {}
    for offer_id, card in card_infos(soup).items():
        own = sorted((e for e in events if e["angebot"] == offer_id), key=lambda e: e["start"])
        # Im Zweifel "buchbar": lieber ein Fehlalarm als ein verpasster Termin.
        sold_out = card["sold_out"] and not any(e["sold_out"] is False for e in own)
        offers[offer_id] = {
            "titel": card["titel"],
            "ort": card["ort"],
            "status": AUSVERKAUFT if sold_out else BUCHBAR,
            "button": card["button"],
            "naechster_termin": own[0]["start"] if own else "",
        }
    return offers


def parse_calendar(html: str) -> dict:
    """Liefert {Datum: Status} für alle Tage eines Monats, an denen es Termine gibt."""
    soup = BeautifulSoup(html, "html.parser")
    cells = soup.select(".calendar-day-number[data-date]")
    if not cells:
        raise WatchError("Kalender des Widgets hat ein unbekanntes Format")
    days = {}
    for cell in cells:
        classes = set(cell.get("class", []))
        if "sold-out-day" in classes or cell.select_one(".bk-sold-out-center"):
            days[cell["data-date"]] = AUSVERKAUFT
        elif classes & {"bk-green-day", "bk-cal-action"} or cell.find("a"):
            days[cell["data-date"]] = BUCHBAR
    return days


def parse_day(html: str, day: str) -> dict:
    """Liefert die einzelnen Termine eines Tages."""
    soup = BeautifulSoup(html, "html.parser")
    cards = card_infos(soup)
    slots = {}
    for event in ld_events(soup):
        if not event["start"].startswith(day):
            continue
        card = cards.get(event["angebot"])
        sold_out = event["sold_out"] is not False and (card is None or card["sold_out"])
        slots[event["id"]] = {
            "angebot": event["angebot"],
            "titel": event["titel"] or (card["titel"] if card else ""),
            "start": event["start"],
            "status": AUSVERKAUFT if sold_out else BUCHBAR,
        }
    return slots


def months_to_check(today: date) -> list:
    months, year, month = [], today.year, today.month
    for _ in range(MONTHS_AHEAD + 1):
        months.append((year, month))
        year, month = (year + 1, 1) if month == 12 else (year, month + 1)
    return months


def read_offers(session, widgets, problems) -> dict | None:
    offers = {}
    try:
        for widget in widgets:
            offers.update(parse_offers(widget_get(session, widget, "list/")))
    except WatchError as exc:
        problems.append(f"Angebotsliste nicht lesbar ({exc})")
        return None
    if not offers:
        problems.append("0 Angebote gefunden (Seite umgebaut oder Widget leer?)")
        return None
    return offers


def read_days(session, widgets, old_days, today, problems) -> dict | None:
    """Liest Kalender und, wo nötig, die Tagesansichten."""
    calendar = {}
    try:
        for widget in widgets:
            for year, month in months_to_check(today):
                html = widget_get(session, widget, "calendar", month=month, year=year)
                for day, status in parse_calendar(html).items():
                    if day < today.isoformat():
                        continue
                    if calendar.get(day) != BUCHBAR:
                        calendar[day] = status
    except WatchError as exc:
        problems.append(f"Terminkalender nicht lesbar ({exc})")
        return None

    old_days = old_days or {}
    days = {}
    for day, status in calendar.items():
        days[day] = {"status": status}
        if "termine" in old_days.get(day, {}):
            days[day]["termine"] = old_days[day]["termine"]

    def needs_details(day: str) -> bool:
        previous = old_days.get(day)
        return (
            previous is None
            or "termine" not in previous
            or previous["status"] != calendar[day]
            or calendar[day] == BUCHBAR
        )

    # Buchbare Tage zuerst, falls die Obergrenze greift.
    wanted = sorted(filter(needs_details, calendar), key=lambda d: (calendar[d] != BUCHBAR, d))
    for day in wanted[:MAX_DAY_REQUESTS]:
        try:
            slots = {}
            for widget in widgets:
                slots.update(parse_day(widget_get(session, widget, "eventsByDate/", date=day), day))
            days[day]["termine"] = slots
        except WatchError as exc:
            log(f"::warning::Tagesansicht {day} nicht lesbar ({exc})")
    if len(wanted) > MAX_DAY_REQUESTS:
        log(f"Hinweis: {len(wanted) - MAX_DAY_REQUESTS} Tagesansichten folgen im nächsten Lauf.")
    return days


# --------------------------------------------------------------------------- #
# Vergleich
# --------------------------------------------------------------------------- #


def fmt_start(iso: str) -> str:
    try:
        moment = datetime.fromisoformat(iso)
    except ValueError:
        return iso
    return f"{WOCHENTAGE[moment.weekday()]} {moment:%d.%m.%Y %H:%M}"


def fmt_day(iso: str) -> str:
    try:
        day = date.fromisoformat(iso)
    except ValueError:
        return iso
    return f"{WOCHENTAGE[day.weekday()]} {day:%d.%m.%Y}"


def slot_line(slot: dict) -> str:
    return f"{fmt_start(slot['start'])}: {slot['titel']}"


def compare(old: dict, new: dict) -> tuple[list, list]:
    """Liefert (frei gewordene, neu aufgetauchte) Angebote und Termine als Textzeilen."""
    free, fresh = [], []

    old_offers = old.get("angebote")
    for offer_id, offer in new.get("angebote", {}).items():
        previous = (old_offers or {}).get(offer_id)
        if offer["status"] == BUCHBAR and (previous is None or previous["status"] != BUCHBAR):
            free.append(f"Angebot buchbar: {offer['titel']}")
        elif previous is None and old_offers is not None:
            fresh.append(f"Neues Angebot ({offer['status']}): {offer['titel']}")

    old_days = old.get("tage")
    for day, info in sorted(new.get("tage", {}).items()):
        previous = (old_days or {}).get(day)
        slots = info.get("termine", {})
        old_slots = (previous or {}).get("termine")
        day_is_new = previous is None
        day_opened = info["status"] == BUCHBAR and (day_is_new or previous["status"] != BUCHBAR)

        if not slots:
            if day_opened:
                free.append(f"Termin buchbar: {fmt_day(day)}")
            elif day_is_new and old_days is not None:
                fresh.append(f"Neuer Termin ({info['status']}): {fmt_day(day)}")
            continue

        free_before = len(free)
        for slot_id, slot in sorted(slots.items(), key=lambda kv: kv[1]["start"]):
            before = (old_slots or {}).get(slot_id)
            slot_is_new = before is None and (day_is_new or old_slots is not None)
            if slot["status"] == BUCHBAR and (
                slot_is_new or day_opened or (before and before["status"] != BUCHBAR)
            ):
                free.append(f"Termin buchbar: {slot_line(slot)}")
            elif slot_is_new and old_days is not None:
                fresh.append(f"Neuer Termin ({slot['status']}): {slot_line(slot)}")
        if day_opened and len(free) == free_before:
            # Kalender meldet den Tag als buchbar, die Tagesansicht nennt keinen Termin.
            free.append(f"Termin buchbar: {fmt_day(day)}")

    return list(dict.fromkeys(free)), list(dict.fromkeys(fresh))


# --------------------------------------------------------------------------- #
# Benachrichtigung und Status
# --------------------------------------------------------------------------- #


def notify(topic: str, title: str, lines: list, priority: int, dry_run: bool) -> None:
    shown = lines[:MAX_LINES]
    if len(lines) > MAX_LINES:
        shown.append(f"und {len(lines) - MAX_LINES} weitere")
    payload = {
        "topic": topic,
        "title": title,
        "message": "\n".join(shown),
        "priority": priority,
        "click": PAGE_URL,
    }
    log(f"Push (Priorität {priority}): {title}")
    for line in shown:
        log(f"    {line}")
    if dry_run:
        log("    [Probelauf: nicht gesendet]")
        return
    # Das Topic steht nur im Request-Body, nie in URL oder Log.
    error = "unbekannter Fehler"
    for attempt in (1, 2):
        try:
            response = requests.post(
                NTFY_SERVER,
                json=payload,
                timeout=TIMEOUT,
                headers={"User-Agent": "dlr-slot-watcher"},
            )
        except requests.RequestException as exc:
            error = type(exc).__name__
        else:
            if response.status_code == 200:
                log("    gesendet")
                return
            error = f"HTTP {response.status_code}"
        if attempt == 1:
            time.sleep(RETRY_WAIT)
    raise NotifyError(f"ntfy: {error}")


def load_state(path: Path) -> dict:
    if not path.exists():
        return {}
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
    except ValueError:
        log("::warning::state.json ist nicht lesbar, starte mit leerem Stand")
        return {}
    return state if isinstance(state, dict) else {}


def save_state(path: Path, state: dict) -> bool:
    text = json.dumps(state, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if path.exists() and path.read_text(encoding="utf-8") == text:
        return False
    path.write_text(text, encoding="utf-8")
    return True


def print_overview(state: dict) -> None:
    offers = state.get("angebote", {})
    log(f"Angebote ({len(offers)}):")
    for offer in offers.values():
        log(f"  [{offer['status']}] {offer['titel']}")
        log(f"      Ort: {offer['ort'] or 'unbekannt'}")
        log(f"      Button: {offer['button'] or 'keiner'}")
        if offer["naechster_termin"]:
            log(f"      Nächster Termin: {fmt_start(offer['naechster_termin'])}")
    days = state.get("tage")
    if days is None:
        log("Termine: in diesem Lauf nicht gelesen")
        return
    log(f"Tage mit Terminen ({len(days)}):")
    for day, info in sorted(days.items()):
        log(f"  [{info['status']}] {fmt_day(day)}")
        for slot in sorted(info.get("termine", {}).values(), key=lambda s: s["start"]):
            log(f"      [{slot['status']}] {slot_line(slot)}")


def error_due(state: dict, now: datetime) -> bool:
    last = state.get("fehler", {}).get("letzte_meldung", "")
    try:
        return now - datetime.fromisoformat(last) >= ERROR_COOLDOWN
    except ValueError:
        return True


def main() -> int:
    parser = argparse.ArgumentParser(description="Überwacht die DLR-Testtermine der EFA.")
    parser.add_argument("--test", action="store_true", help="nur eine Testnachricht schicken")
    parser.add_argument("--dry-run", action="store_true", help="nichts senden, nur anzeigen")
    parser.add_argument("--state", type=Path, default=STATE_FILE, help="Pfad zur state.json")
    args = parser.parse_args()
    test_mode = args.test or os.environ.get("TEST_MODE", "").lower() in ("1", "true", "yes")

    topic = os.environ.get("NTFY_TOPIC", "").strip()
    if not topic and not args.dry_run:
        log("::error::Das Secret NTFY_TOPIC ist nicht gesetzt.")
        return 2

    now = datetime.now(timezone.utc).replace(microsecond=0)
    log(f"DLR-Slot-Watcher, Lauf vom {now:%d.%m.%Y %H:%M} UTC")

    if test_mode:
        try:
            notify(
                topic,
                "DLR-Watcher: Test",
                ["Testnachricht. Der Watcher läuft und kann dich erreichen."],
                PRIO_DEFAULT,
                args.dry_run,
            )
        except NotifyError as exc:
            log(f"::error::Testnachricht nicht zugestellt ({exc})")
            return 1
        return 0

    old = load_state(args.state)
    new = copy.deepcopy(old)
    new["version"] = 1
    problems: list = []

    session = requests.Session()
    session.headers.update(
        {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "de-DE,de;q=0.9,en;q=0.8",
            "Referer": "https://www.lufthansa-aviation-training.com/",
        }
    )

    widgets = discover_widgets(session, old, problems)
    if widgets:
        new["widgets"] = widgets
        log(f"Buchungs-Widgets gefunden: {len(widgets)}")
        offers = read_offers(session, widgets, problems)
        if offers is not None:
            new["angebote"] = offers
            days = read_days(session, widgets, old.get("tage"), datetime.now(BERLIN).date(), problems)
            if days is not None:
                new["tage"] = days

    log()
    print_overview(new)
    log()

    free, fresh = compare(old, new)
    if not old.get("angebote") and new.get("angebote"):
        log("Erster Lauf: Stand wird als Ausgangspunkt gespeichert.")
    if not free and not fresh:
        log("Änderungen: keine")

    try:
        if free:
            notify(topic, "DLR-Termin frei!", free, PRIO_URGENT, args.dry_run)
        if fresh:
            notify(topic, "DLR: Neues Angebot oder neuer Termin", fresh, PRIO_HIGH, args.dry_run)
    except NotifyError as exc:
        # Stand nicht speichern, damit der nächste Lauf die Änderung erneut meldet.
        log(f"::error::Push nicht zugestellt ({exc}). Stand bleibt unverändert.")
        return 1

    exit_code = 0
    if problems:
        for problem in problems:
            log(f"::warning::{problem}")
        if error_due(old, now):
            try:
                notify(topic, "DLR-Watcher: Problem", problems, PRIO_DEFAULT, args.dry_run)
                new["fehler"] = {"letzte_meldung": now.isoformat()}
            except NotifyError as exc:
                log(f"::error::Fehlermeldung nicht zugestellt ({exc})")
                exit_code = 1
        else:
            log("Fehlermeldung unterdrückt (höchstens eine alle 6 Stunden).")

    if save_state(args.state, new):
        log("state.json aktualisiert.")
    else:
        log("state.json unverändert.")
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
