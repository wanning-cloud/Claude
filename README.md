# Podcast-Cockpit · Der Monteur-Podcast

Analytics-Bereich im podcast-admin von monteur-podcast.de: Aufrufe und Downloads aller Portale (gesamt und je Portal), Werte je Folge und ein gemeinsames Postfach für Kommentare mit Antwortfunktion. Auftrag: [`MASTER-PROMPT.md`](MASTER-PROMPT.md). Entscheidungen aus Stufe 0: [`docs/stufe-0.md`](docs/stufe-0.md).

**Variante 2 (alles bei all-inkl):** React-App unter `/podcast-admin/analytics/`, API als PHP 8 mit SQLite unter `/podcast-admin/analytics/api/`, Zeitpläne über einen all-inkl-Cronjob. Kein Zusatzserver.

## Aufbau

```
apps/web          React + Vite + TypeScript + Tailwind v4 (Oberfläche, Designsystem „Plakat“, DESIGN.md)
apps/api          PHP 8.2+ API, SQLite, Connectoren, Zählregeln, Import (PHPUnit-Tests)
packages/shared   Typen, Formatierung, zod-Schema des Imports (→ apps/api/schema/import.schema.json)
.claude/skills    Routinen „podcast-zahlen-holen“ und „cockpit-veroeffentlichen“
e2e               Playwright (Login, Übersicht, Postfach, 360 px)
scripts           Release-Build, Secret-Scan
docs              Stufe 0, podcast-admin-Analyse, Datenschutz-Vorschlag, offene Prüfungen
```

## Lokal starten

Voraussetzungen: Node.js 22+, PHP 8.2+ mit `pdo_sqlite`, `curl`, `openssl`, `mbstring`; Composer (nur für Tests).

```sh
npm install
composer --working-dir=apps/api install

# Testdaten (nur lokal, klar als TESTDATEN markiert) und API starten
cp apps/api/analytics.env.example /tmp/dev.env   # APP_ENV=dev, DEV_AUTH_USER=Markus, DATABASE_PATH=/tmp/dev.sqlite eintragen
APP_ENV=dev DATABASE_PATH=/tmp/dev.sqlite php apps/api/bin/seed-test.php
ANALYTICS_ENV_FILE=/tmp/dev.env npm run dev:api     # http://127.0.0.1:8787

npm run dev                                          # http://localhost:5173/podcast-admin/analytics/
```

`DEV_AUTH_USER` wirkt nur mit `APP_ENV=dev` im PHP-Entwicklungsserver. Auf dem Server gilt immer die Sitzung des podcast-admin.

## Prüfen

```sh
npm run lint        # ESLint, Prettier, tsc, php -l
npm test            # Vitest (shared) + PHPUnit (API, inkl. Zählregeln und echte Export-Formate)
npm run test:e2e    # Playwright, Desktop und 360 px
npm run secrets     # gitleaks, sonst eingebaute Prüfung
npm run build       # Schema-Export + Vite-Build
```

## Zählregeln (Kurzfassung)

- Reichweite = Summe der Leitwerte von YouTube (Aufrufe), Spotify (Wiedergaben), Feed-Downloads (IAB 2.2) und Website-Player (ab Stufe 2). Keine Personenzahl.
- Apple, Amazon, Deezer stehen auf ihren Seiten, zählen aber nicht in die Summe (diese Hörer stecken in den Feed-Downloads).
- Downloads der Spotify-App fallen aus den Downloads, sobald Spotify-Werte für den Zeitraum vorliegen.
- Fehlende Werte sind „–“ mit Grund, nie 0. Jede Zahl zeigt Quelle und Stand.
- IAB 2.2: ab einer Minute Audio (Bytes pro Minute aus dem Feed), gleiche IP + App + Folge in 24 Stunden einmal, ohne Bots, HEAD, Range-Proben, Fehlerstatus. IPs werden nie gespeichert (HMAC mit Tagessalz, nach 48 Stunden gelöscht).

Code: `apps/api/src/Counting/`, `apps/api/src/Repo/Metrics.php`, Tests: `apps/api/tests/IabCounterTest.php`, `MetricsAndImportTest.php`.

## Automatik

| Quelle | Weg | Zeitplan |
| --- | --- | --- |
| Feed (Folgen, Vorschaubilder aus `podcast/folgen.js`) | Server | stündlich |
| YouTube-Kommentare | Server, YouTube Data API | alle 30 Minuten (einmal täglich vollständig) |
| YouTube-Zahlen | Server, Data API + Analytics API | täglich 06:00 |
| Apple-Bewertungen | Server, öffentlicher Bewertungs-Feed | täglich 06:10 |
| Downloads | Server, all-inkl-Zugriffslogs (`/logs/`, ein `.gz` pro Tag; heutige Downloads erscheinen am Folgetag) | stündlich geprüft |
| Datenbank-Sicherung | Server, `VACUUM INTO`, 14 Tage | täglich 03:00 |
| Instagram- und Facebook-Kommentare | Server, Graph API (nur Beiträge mit geänderter Kommentarzahl) | alle 15 Minuten (bei 30-Minuten-Cron alle 30) |
| Instagram-Stories | Server, Graph API (Zahlen nur, solange die Story live ist) | alle 2 Stunden |
| Instagram, Facebook-Seite (Beiträge, Tageswerte, Follower) | Server, Graph API | alle 6 Stunden |
| Spotify (ab Stufe 2: Apple, Amazon) | Claude-Routine „Podcast-Zahlen holen“ | Montag 08:20 und auf Zuruf |

Ein einziger all-inkl-Cronjob ruft alle 30 Minuten `…/api/cron?job=due&key=<CRON_KEY>` auf und startet, was fällig ist. „Jetzt aktualisieren“ im Cockpit startet die Server-Quellen sofort, eine nach der anderen.

**Routine einrichten:** Wenn Stufe 1 live ist, im Claude-Chat auf dem Mac „Richte die Cockpit-Routine ein“ sagen. Claude legt eine geplante Aufgabe (Montag 08:20, braucht diesen Mac) mit dem Text aus `.claude/skills/podcast-zahlen-holen/SKILL.md` an.

## Veröffentlichen

Nur auf Markus' Anweisung („Veröffentliche das Cockpit“) über `.claude/skills/cockpit-veroeffentlichen/SKILL.md`. `npm run release` baut `release/analytics/` (ohne Geheimnisse, ohne Datenbank).

## Einstellungen (ENV)

Vorlage: [`apps/api/analytics.env.example`](apps/api/analytics.env.example). Die echte `analytics.env` liegt auf dem Server außerhalb des Web-Ordners; ihr Pfad steht in `podcast-admin/analytics/api/app/env-path.txt`. Im Repo stehen nur die Namen.

## YouTube verbinden (einmalig)

1. console.cloud.google.com › neues Projekt „Monteur-Podcast Cockpit“.
2. APIs aktivieren: „YouTube Data API v3“ und „YouTube Analytics API“.
3. OAuth-Zustimmungsbildschirm: extern, App-Name „Podcast-Cockpit“, Scopes `youtube.force-ssl`, `yt-analytics.readonly`, `youtube.readonly`. Status auf **„In Produktion“** stellen (im Modus „Test“ laufen Refresh-Tokens nach 7 Tagen ab).
4. Anmeldedaten › OAuth-Client-ID › Webanwendung, Weiterleitungs-URI `https://monteur-podcast.de/podcast-admin/analytics/api/youtube/callback`.
5. Client-ID und -Secret in die `analytics.env` eintragen (Markus selbst).
6. Im Cockpit › Automatik › „YouTube verbinden“ und mit dem Konto des Kanals @DerMonteurPodcast anmelden. Ein falscher Kanal wird abgelehnt.

**Token erneuern:** Meldet das Cockpit „Verbindung abgelaufen oder widerrufen“, einfach „Neu verbinden“ klicken. Wird `ENCRYPTION_KEY` geändert, sind gespeicherte Tokens unlesbar: dann ebenfalls neu verbinden.

## Social Media (eigene Statistik)

Seite „Social Media“ im Cockpit: Instagram, Facebook-Seite „Der Monteur Podcast“ und YouTube Shorts. Eigene Tabellen (`social_*`, Migration `002_social.sql`), eigener Endpunkt `GET /api/social`. **Nichts davon zählt in die Podcast-Reichweite**, und YouTube Shorts sind seit dieser Version aus den Podcast-Zahlen von YouTube herausgerechnet (Analytics-Bericht nach `creatorContentType` getrennt; beim ersten Lauf wird die ganze YouTube-Historie einmal neu geholt).

- Kacheln je Kanal: Follower (Veränderung im Zeitraum), erreichte Konten (Summe der Tageswerte), Aufrufe, Interaktionen, Engagement-Rate.
- Engagement-Rate je Beitrag = (Likes + Kommentare + Gespeichert + Geteilt) ÷ Reichweite; Facebook-Likes = alle Reaktionen; Shorts: (Likes + Kommentare) ÷ Aufrufe. Mehrere Beiträge: Summe ÷ Summe.
- Ziele aus der Strategie: 150 Instagram-Follower, 100 YouTube-Abos, 7 Clips pro Woche (höhere Zahl aus Reels und Shorts, nicht die Summe).
- „Welche Serie trägt?“: Serie aus dem Hashtag (`#fehlerderwoche`, `#rechnungin60sekunden`, `#dispofrage`, `#antwortderwoche`), „Clip aus einer Folge“ bei „Folge NN“ in der Caption, sonst von Hand je Beitrag.
- Besuche auf monteur-podcast.de über Links mit `utm_source=instagram|facebook|youtube_shorts|fb_gruppe` (aus den Zugriffs-Logs, ohne Bots, Seitenaufrufe statt Personen).
- Facebook-Gruppen: keine Schnittstelle mehr (Meta hat die Groups API 2024 abgeschaltet). Wochenprotokoll „Antworten in Gruppen“ von Hand.
- Kommentare von Instagram, Facebook und Shorts landen im Postfach (Filter „Bereich“). Antworten gehen erst beim Klick auf „Antwort senden“ raus. Kein Löschen, kein automatisches Antworten.

### Meta verbinden (einmalig)

1. Instagram auf **Profikonto** (Business oder Creator) umstellen und in Instagram › Konten-Center mit der Facebook-Seite „Der Monteur Podcast“ verknüpfen.
2. developers.facebook.com › „App erstellen“, Typ **Business**, Markus als Admin. Produkt **Facebook Login** (oder „Facebook Login for Business“ mit einer Konfiguration, dann deren ID als `META_CONFIG_ID`).
3. Gültige OAuth-Redirect-URI: `https://monteur-podcast.de/podcast-admin/analytics/api/meta/callback`.
4. Berechtigungen: `pages_show_list`, `pages_read_engagement`, `pages_read_user_content`, `pages_manage_engagement`, `read_insights`, `instagram_basic`, `instagram_manage_insights`, `instagram_manage_comments`. Weil die App nur Markus' eigene Seite liest und Markus Admin der App ist, reicht der Standardzugriff; ein App-Review ist dafür nach Meta-Doku nicht nötig (vor dem ersten Verbinden prüfen, siehe `docs/offene-pruefungen.md`).
5. App-ID und App-Geheimnis in die `analytics.env` eintragen (`META_APP_ID`, `META_APP_SECRET`, `META_REDIRECT_URI`), Markus selbst.
6. Cockpit › Automatik › „Meta verbinden“, mit Markus' Facebook-Konto anmelden, die Seite „Der Monteur Podcast“ und das Instagram-Konto freigeben.

Das Seiten-Token läuft nicht ab. Ändert Markus sein Facebook-Passwort oder entfernt er die App, meldet das Cockpit „Meta neu verbinden“.

## Sicherung zurückspielen

Sicherungen liegen in `BACKUP_DIR` als `cockpit-JJJJ-MM-TT_HHMMSS.sqlite` (14 Tage).

1. Cronjob im KAS kurz deaktivieren.
2. Aktuelle Datenbank `DATABASE_PATH` umbenennen (z. B. `cockpit.sqlite.kaputt`), dazu `-wal` und `-shm` löschen.
3. Gewünschte Sicherung nach `DATABASE_PATH` kopieren.
4. `…/api/health` aufrufen, Cockpit prüfen, Cronjob wieder aktivieren.

## Ausbaustufen

- **Stufe 1 (dieses Repo):** Login über podcast-admin, Feed, YouTube (Zahlen, Kommentare, Antworten), Apple-Bewertungen, Downloads aus Logs, Server-Sync mit Knopf, Import mit Erkennung der echten Spotify- und Amazon-Exporte, Routinen, Übersicht, Folgen, Portale, Postfach, Automatik, Sicherung, Tests.
- **Stufe 2:** Apple/Amazon in der Routine, Spotify-Kommentare, Knopf „Plattform-Daten holen“, Wochenbericht, Website-Beacon, bester Veröffentlichungszeitpunkt, Apps/Länder, YouTube-Moderation, Kennzeichnen, Benachrichtigung.
- **Stufe 3 (gewünscht):** KI-Antwortentwürfe, Postfach frag@.
- **Social Media (09.10.2026):** Instagram, Facebook-Seite, YouTube Shorts als eigene Statistik, Kommentare im Postfach, Shorts aus den Podcast-Zahlen getrennt, UTM-Besuche, Wochenprotokoll Facebook-Gruppen.
