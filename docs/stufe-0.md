# Stufe 0: Entscheidungen und Plan für Stufe 1

Stand 07.10.2026. Grundlage: `MASTER-PROMPT.md` (Fassung 2026-10-07_08-57).

## Entscheidungen von Markus

| Nr. | Frage | Entscheidung |
| --- | --- | --- |
| 1 | Hosting | **Variante 2:** alles bei all-inkl. React-App unter `/podcast-admin/analytics/`, Server-Teil als PHP 8 mit PDO/SQLite unter `/podcast-admin/analytics/api/`, Zeitpläne über all-inkl-Cronjobs. Kein Express, kein Zusatzserver. |
| 2 | podcast-admin lesen | Erlaubt, nur lesen. |
| 3 | Download-Messung | Zuerst **A** (all-inkl-Zugriffs-Logs) prüfen. Sind sie nicht abrufbar oder nicht teilanonymisiert, dann **B** (OP3-Präfix). |
| 6 | Beispieldaten | Erlaubt, nur lesen: eine Spotify-CSV exportieren, die Apple- und Amazon-Seiten ansehen. |
| 7 | Apple Podcasters Program | Kein Mitglied. Die Routine liest die Apple-Werte im Browser ab. |
| 8 | Stufe 3 | Ja: KI-Antwortentwürfe und das Postfach frag@. Nein: Kommentare auf den Folgenseiten (B11) und CSV-Export (A7). |

## Nachtrag 08.10.2026

- **podcast-admin gelesen** (Datei-Export von Markus): Anmeldung über Sitzung `mp_admin`, `$_SESSION['ok']`. Details: `docs/podcast-admin-analyse.md`.
- **Beispieldaten**: Markus hat die Spotify-Exporte („Leistung“, „Trends“), den Amazon-Export und die Notiz „podcast-analytics-beispieldaten“ geliefert. Der Import erkennt diese Formate automatisch.
- **Apple**: kein Export in Podcasts Connect, Werte zurzeit „nicht genügend Daten“. Die Routine liest ab Stufe 2 ab.
- **Freigabe für Stufe 1**: erteilt am 08.10.2026 („do it“). Stufe 1 ist gebaut, siehe README. Live geht sie erst mit „Veröffentliche das Cockpit“.

## Noch offen

- **Frage 4, YouTube:** Google-Cloud-Projekt anlegen, beide APIs aktivieren, OAuth-Client einrichten, Zustimmungsbildschirm auf „In Produktion“ stellen. Ich führe Schritt für Schritt durch, sobald Stufe 1 so weit ist. Bei Variante 2 lautet die Redirect-URI `https://monteur-podcast.de/podcast-admin/analytics/api/youtube/callback`.
- **Frage 5, Wochen-Routine:** Montag 08:20 auf dem Mac (wach, Chrome mit Claude-Erweiterung, angemeldet bei Spotify, Apple, Amazon und im podcast-admin)?
- **Zugriffs-Logs:** Log-Stufe und Ablageort im KAS prüfen (entscheidet Variante A oder B der Download-Messung).

## Was Variante 2 am Master-Prompt ändert

- `apps/server` (Express) entfällt. Dafür gibt es `apps/api` (PHP 8.2+, ohne Framework, PDO/SQLite, Composer nur für Tests). Die Endpunkte und Datenformate bleiben wie in Abschnitt 10 beschrieben.
- Die zod-Schemas in `packages/shared` bleiben für das Frontend. Für PHP entsteht daraus ein JSON-Schema, gegen das die API prüft. So gibt es eine einzige Quelle.
- Anmeldung: `token.php` entfällt. Die API liegt unter `/podcast-admin/` und liest die Sitzung `mp_admin` des Upload-Helfers direkt (nur lesen). Für schreibende Anfragen gibt es zusätzlich einen CSRF-Token.
- Zeitpläne: all-inkl-Cronjobs rufen `api/cron.php?job=…` mit einem geheimen Schlüssel auf. Jeder Lauf wird in `sync_runs` protokolliert, und eine Sperrdatei je Quelle verhindert parallele Läufe.
- Datenbank: SQLite-Datei außerhalb des Web-Verzeichnisses, falls all-inkl das erlaubt. Sonst in einem Ordner mit `Deny from all`.
- Routine „Cockpit veröffentlichen“: Upload nur per FTP/WebFTP, Wechsel über `analytics-neu/` → `analytics/` (Frontend und API zusammen), Rücksprung über `analytics-alt/`.
- Der Knopf „Jetzt aktualisieren“ arbeitet die Quellen nacheinander ab, je Quelle eine Anfrage. So greift das Zeitlimit von PHP-Anfragen bei all-inkl nicht.

## Plan Stufe 1 (freigegeben und umgesetzt)

1. **Gerüst:** npm-Workspaces `apps/web`, `packages/shared`, dazu `apps/api` (PHP). Lint, Prettier, `tsc`, Vitest, PHPUnit, Playwright, gitleaks. `README.md` und `CLAUDE.md` anlegen.
2. **Datenbank:** Migrationen für das Datenmodell aus Abschnitt 10.
3. **Feed-Connector:** Folgen-Stammdaten aus `feed.xml`, stündlich.
4. **Zählregeln:** IAB-2.2-Zählung mit Tests (Duplikate, Bots, HEAD, Range 0-1, Spotify-User-Agent). Log-Import nach Prüfung von A.
5. **YouTube:** Verbinden über OAuth, Zahlen täglich, Kommentare alle 30 Minuten, Antworten senden.
6. **Apple-Bewertungen** täglich.
7. **Import-Schnittstelle** (CSV und JSON nach Schema, idempotent) und Skill `podcast-zahlen-holen` (Spotify-Zahlen).
8. **Oberfläche** im Designsystem „Plakat“: Übersicht, Folgen, Portale, Postfach, Automatik. Ab 360 px, Performance-Budget aus Abschnitt 12.
9. **Sicherung** täglich, 14 Tage aufbewahren. Skill `cockpit-veroeffentlichen` für Variante 2.
10. **Abnahme** nach Abschnitt 17. Live geht erst etwas, wenn du „Veröffentliche das Cockpit“ sagst.
