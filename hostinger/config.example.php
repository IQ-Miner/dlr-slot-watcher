<?php
// Als config.php neben watcher.php ablegen und das eigene Topic eintragen.
// config.php gehört nicht ins Repo.
return [
    'ntfy_topic' => 'HIER-DEIN-TOPIC',

    // Morgenbericht: einmal am Tag eine Zusammenfassung. Alles optional.
    'name' => '',                 // für die Begrüßung, z. B. "Guten Morgen, Alex"
    'bericht_stunde' => 8,        // ab dieser Stunde (Europe/Berlin)
    // 'bericht_link' => 'https://example.org/meine-uebersicht',  // Ziel beim Antippen
];
