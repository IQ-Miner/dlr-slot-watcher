# dlr-slot-watcher

Überwacht die Buchungsseite für den DLR-Test der European Flight Academy und schickt eine Push-Nachricht über [ntfy](https://ntfy.sh), sobald ein Termin buchbar wird.

Seite: <https://www.lufthansa-aviation-training.com/web/european-flight-academy/anmeldung-dlr-test>

Es wird nichts gebucht. Das Skript liest nur und benachrichtigt.

## Was es macht

Eine GitHub Action läuft alle 10 Minuten und prüft drei Dinge:

1. **Angebote**: Titel, Ort und Status (ausverkauft oder buchbar) jedes Angebots, zum Beispiel "Termine DLR Zertifikat in Hamburg".
2. **Tage**: der Kalender des aktuellen Monats und der drei folgenden Monate. Für jeden Tag mit Terminen steht dort, ob er ausverkauft ist.
3. **Einzelne Termine**: Angebot, Uhrzeit und Status je Termin. Die Tagesansicht wird nur für neue, geänderte oder buchbare Tage abgerufen, damit die Seite nicht unnötig belastet wird.

Der letzte Stand liegt in `state.json`. Die Action committet die Datei nur, wenn sich etwas geändert hat.

### Wann eine Nachricht kommt

| Ereignis | Priorität | Titel |
| --- | --- | --- |
| Ein Angebot oder Termin ist nicht mehr ausverkauft | urgent | DLR-Termin frei! |
| Ein neues Angebot oder ein neuer Termin taucht auf | high | DLR: Neues Angebot oder neuer Termin |
| 0 Angebote gefunden oder Fehler beim Abruf | default, höchstens einmal in 6 Stunden | DLR-Watcher: Problem |

Jede Nachricht öffnet beim Antippen die Buchungsseite.

Beim allerersten Lauf wird der Stand nur gespeichert. Eine Nachricht kommt dann nur, wenn schon etwas buchbar ist.

### Wie die Seite gelesen wird

Die Angebote stehen nicht im HTML der Seite. Die Seite bindet ein Buchungs-Widget von bookingkit ein, das seine Inhalte nachlädt. Das Skript ruft dieselben Adressen ab wie das Widget im Browser. Ein Browser ist dafür nicht nötig, `requests` und `BeautifulSoup` reichen.

Pro Lauf sind das im Normalfall 6 Abrufe mit einer Sekunde Pause dazwischen, mit normalem Browser-User-Agent, Timeout und höchstens einem zweiten Versuch.

## Einrichten

### 1. ntfy-Topic als Secret setzen

Das Topic steht bewusst nicht im Code, weil das Repo öffentlich ist. Wer das Topic kennt, kann mitlesen und selbst Nachrichten schicken.

Im Browser:

1. Im Repo auf **Settings** gehen
2. Links **Secrets and variables**, dann **Actions**
3. **New repository secret**
4. Name: `NTFY_TOPIC`, Secret: dein Topic-Name (nur der Name, ohne `https://ntfy.sh/`)

Oder mit der GitHub CLI:

```bash
gh secret set NTFY_TOPIC
```

Danach das Topic eintippen und mit Enter bestätigen.

### 2. Testen

Unter **Actions**, **DLR-Slot-Watcher**, **Run workflow** den Haken bei "Nur eine Testnachricht" setzen. Dann kommt nur eine Testnachricht, die Seite wird nicht geprüft.

Mit der CLI:

```bash
gh workflow run watcher.yml -f testmodus=true
```

Ein normaler Lauf von Hand:

```bash
gh workflow run watcher.yml
```

## Pausieren

Im Browser: **Actions**, links **DLR-Slot-Watcher**, oben rechts das Menü mit den drei Punkten, **Disable workflow**. Zum Fortsetzen an derselben Stelle **Enable workflow**.

Mit der CLI:

```bash
gh workflow disable watcher.yml
```

```bash
gh workflow enable watcher.yml
```

## Gut zu wissen

- **Der Zeitplan ist nicht minutengenau.** GitHub startet geplante Läufe bei hoher Last verspätet, manchmal fällt einer aus. Rechne mit 10 bis 20 Minuten Abstand.
- **GitHub schaltet Zeitpläne in öffentlichen Repos nach 60 Tagen ohne Aktivität ab.** Vorher kommt eine E-Mail. Dann unter **Actions** einmal **Enable workflow** klicken.
- **Wenn die Push-Nachricht nicht zugestellt werden kann**, schlägt der Lauf fehl und GitHub schreibt dir eine E-Mail. Der Stand wird dann nicht gespeichert, der nächste Lauf meldet die Änderung erneut.
- **Wenn ein Problem dauerhaft gemeldet wird**, wurde die Seite vermutlich umgebaut. Dann muss `watcher.py` angepasst werden.

## Lokal ausführen

```bash
pip install -r requirements.txt
python watcher.py --dry-run
```

Mit `--dry-run` wird nichts gesendet, die Nachrichten erscheinen nur in der Ausgabe. Für einen echten Lauf muss die Umgebungsvariable `NTFY_TOPIC` gesetzt sein.

## Dateien

- `watcher.py`: das Skript
- `state.json`: letzter bekannter Stand, wird von der Action gepflegt
- `.github/workflows/watcher.yml`: Zeitplan und Testmodus
