---
tags: [prompt, podcast, monteur-podcast, claude-code]
erstellt: 2026-10-07
aktualisiert: 2026-10-07
quelle: claude
status: entwurf
fassung: 2026-10-07_08-57
ersetzt: Master-Prompt Podcast-Cockpit 2026-10-07_08-24
---
# Master-Prompt: Podcast-Cockpit für den Monteur-Podcast (Claude Code)

Ein Analytics-Bereich im podcast-admin von monteur-podcast.de: alle Aufrufe und Downloads der Folgen über alle Portale, zusammengerechnet und je Portal, plus ein gemeinsames Postfach für alle Kommentare mit Antwortfunktion. Markus macht nichts von Hand. Der Server holt alles, was eine Schnittstelle hat, selbst. Den Rest holt Claude einmal pro Woche oder auf Zuruf über eine Routine. Veröffentlicht wird nur, wenn Markus es anweist; dann führt Claude die Routine „Cockpit veröffentlichen“ aus. Gebaut mit React, Vite, Tailwind und Express.

**So nutzt du den Prompt (für Markus):** Leeren Ordner anlegen, z. B. `~/Projekte/podcast-cockpit`. Diese Datei als `MASTER-PROMPT.md` hineinlegen. Im Ordner Claude Code starten und schreiben: „Lies MASTER-PROMPT.md und starte mit Stufe 0.“ Claude Code stellt zuerst die Fragen aus Abschnitt 4 und baut erst nach deiner Freigabe. Später reichen drei Sätze:
- „**Veröffentliche das Cockpit.**“ → Claude stellt die aktuelle Fassung live (Abschnitt 8.3).
- „**Hol die Podcast-Zahlen.**“ → Claude holt sofort alle Daten (Abschnitt 8.2).
- „**Richte die Cockpit-Routine ein.**“ → Claude legt die wöchentliche Routine an (Abschnitt 8.2).

---

## 1. Rolle und Ziel

Du bist Full-Stack-Entwickler und baust das **Podcast-Cockpit** für „Der Monteur-Podcast – Vermietung an Fachkräfte und Monteure“ (monteur-podcast.de), Host Markus Wanning. Markus veröffentlicht zwei Folgen pro Woche über einen selbst gehosteten RSS-Feed. Die Folgen laufen auf Spotify, Apple Podcasts, Amazon Music, Deezer, Podcast Index, YouTube und im Player auf monteur-podcast.de. Markus ruft das Cockpit im bestehenden Admin-Bereich auf: `https://monteur-podcast.de/podcast-admin/` → Menüpunkt „Analytics“.

Das Cockpit beantwortet drei Fragen:
1. Wie viele Menschen erreicht der Podcast, gesamt und je Portal?
2. Welche Folge läuft wie, auf welchem Portal?
3. Welche Kommentare und Fragen sind offen, und wie antworte ich schnell darauf?

**Leitsatz:** Markus soll keine Zahlen abtippen, keine CSV hochladen und keine Seite manuell aktualisieren. Was er tun muss, ist Lesen, Antworten und Freigeben.

## 2. Arbeitsregeln (verbindlich)

1. **Erst fragen, dann bauen.** Stelle die Fragen aus Abschnitt 4, zeige danach einen kurzen Plan für Stufe 1 und warte auf Markus' Freigabe. Arbeite in den Ausbaustufen aus Abschnitt 16 und zeige nach jeder Stufe das Ergebnis.
2. **Nichts geht nach außen ohne Markus' ausdrückliches Ja.** Das gilt für jede Kommentarantwort, jede Moderation, jede E-Mail, jede DNS-Änderung und jede Änderung an monteur-podcast.de. Die Freigabe gilt immer nur für den einen Vorgang. Ein Klick von Markus auf „Antwort senden“ im Cockpit ist die Freigabe für genau diese Antwort.
3. **Live geht nur etwas auf Markus' Anweisung.** Entwickelt wird lokal. Hochgeladen wird ausschließlich über die Routine „Cockpit veröffentlichen“ (Abschnitt 8.3), wenn Markus es sagt.
4. **Bestehendes System schonen.** `feed.xml`, `podcast/folgen.js`, `index.html` und die Upload-, Feed- und Newsletter-Logik des podcast-admin bleiben unverändert. Am podcast-admin sind nur Ergänzungen erlaubt: ein neuer Ordner `podcast-admin/analytics/`, ein Menüpunkt „Analytics“ und ein kleines Token-Skript (Abschnitt 10). Vorher den betroffenen Stand sichern. Wo das Cockpit an der Homepage etwas braucht (Player-Beacon, Download-Präfix), lieferst du einen Änderungsvorschlag als Text; eingebaut wird erst nach Freigabe.
5. **Keine Zugangsdaten** in Code, Commits, Logs, Chat, Notizen oder Routine-Texten. Nur `.env` auf dem Server, im Repo nur `.env.example`. FTP- und SSH-Zugänge nur über SSH-Schlüssel bzw. macOS-Schlüsselbund. Passwörter oder Codes gibst du nie selbst ein; fehlt eine Anmeldung, bittest du Markus darum.
6. **Keine riskanten Knöpfe.** Kein Löschen von Kommentaren, kein „Alles zurücksetzen“, kein „Daten neu aufbauen“ in der Oberfläche. Moderation (zurückhalten, Spam) nur mit Bestätigungsdialog.
7. **Zahlen nie erfinden.** Fehlt ein Wert, zeige „–“ mit Grund („Spotify zuletzt am 12.10. geholt“). Nie 0 statt „keine Daten“. Keine Beispieldaten im Produktivbetrieb; Testdaten nur in Tests und klar markiert.
8. **Aktuelle Versionen prüfen.** Aktuelle stabile Paketversionen nutzen und APIs in der offiziellen Doku nachschlagen, nicht aus dem Gedächtnis.
9. **Design-Skills nutzen.** Für jede Oberfläche den Skill `impeccable` verwenden (und `taste`, falls installiert), damit nichts nach KI-Standardlook aussieht.
10. **Sprache:** Oberfläche Deutsch, Du-Form, kurze Sätze, Knöpfe nennen, was passiert („Antwort senden“, „Jetzt aktualisieren“). Code, Code-Kommentare und Commits auf Englisch.
11. **Dokumentieren.** `README.md` (lokal starten, Veröffentlichen, ENV, Token erneuern, Backup zurückspielen, Routinen) und `CLAUDE.md` mit der Kurzfassung dieser Regeln. Beides bei jeder Stufe aktuell halten.

## 3. Technik und Hosting

| Bereich | Vorgabe |
| --- | --- |
| Frontend | React + Vite + TypeScript (strict) + Tailwind CSS v4 (`@tailwindcss/vite`, Design-Tokens per `@theme`). Build mit `base: '/podcast-admin/analytics/'` |
| Routing, Daten | React Router, TanStack Query, zod (Schemas geteilt mit dem Server) |
| Backend | Express (aktuelle Hauptversion) + TypeScript auf Node.js LTS |
| Datenbank | SQLite (better-sqlite3) mit Drizzle ORM und Migrationen |
| Hintergrundjobs | node-cron im Server-Prozess, jeder Lauf in `sync_runs` protokolliert |
| Struktur | npm-Workspaces: `apps/web`, `apps/server`, `packages/shared`, Routinen unter `.claude/skills/` |
| Betrieb | Express hinter Caddy (HTTPS automatisch, Brotli/gzip), Start über systemd, Releases in `releases/<datum>` mit Symlink `current` |
| Tests | Vitest (Parser, Zählregeln, Import), Playwright (Login, Übersicht, Postfach, 360 px) |
| Qualität | ESLint, Prettier, `tsc --noEmit`, gitleaks |

**Hosting:** Website, Feed, MP3s und podcast-admin liegen bei all-inkl.com (Shared Hosting, PHP, WebFTP). Laut Nutzerberichten und Support-Aussagen in Foren lässt sich dort kein dauerhaft laufender Node-/Express-Server betreiben. Daraus folgen zwei Varianten (Entscheidung in Stufe 0):

- **Variante 1, Standard (Technik bleibt wie vorgegeben):** Die React-App liegt als fertiger Build im podcast-admin bei all-inkl (`/podcast-admin/analytics/`). Die Express-API läuft auf einem kleinen eigenen Server in Deutschland unter `api.monteur-podcast.de`. Markus sieht alles im podcast-admin, merkt vom zweiten Server nichts.
- **Variante 2, ohne eigenen Server:** React-App wie oben. Der Server-Teil läuft als PHP-Endpunkte im podcast-admin bei all-inkl, Zeitpläne über die all-inkl-Cronjobs. Kein Zusatzserver, keine Zusatzkosten, aber kein Express. Wählt Markus diese Variante, übertrage den Server-Teil aus Abschnitt 8.1, 10 und 13 sinngemäß auf PHP 8 mit PDO/SQLite.

## 4. Stufe 0: Vor dem ersten Code klären

Stelle diese Fragen gesammelt, mit deinem Vorschlag als Standard.

1. **Variante 1 oder 2** (Abschnitt 3)? Vorschlag: Variante 1. Den Server bestellt und bezahlt Markus selbst; alles Weitere richtet die Routine „Cockpit veröffentlichen“ ein.
2. **podcast-admin lesen (nur lesen):** Darf ich den Code des podcast-admin über Markus' WebFTP-Sitzung ansehen, um Login, Menü und Ordner zu verstehen? Nichts wird geändert.
3. **Download-Messung für alle Apps, die die MP3 laden (Apple, Amazon, Overcast, Pocket Casts usw.):**
   - **A Server-Logs von all-inkl.** Kein Eingriff in den Feed, Daten bleiben bei Markus. Voraussetzung: Zugriffs-Logs sind als Datei abrufbar und mindestens teilanonymisiert (Log-Stufe im KAS). Erst prüfen.
   - **B OP3-Präfix** (op3.dev, kostenlos, offen). Robust. Der Upload-Helfer muss `https://op3.dev/e/` vor die MP3-Adressen im Feed setzen. Drittanbieter, gehört in die Datenschutzerklärung.
   - **C Eigener Präfix-Endpunkt** im Express-Server (`/e/...` zählt und leitet per 302 weiter). Daten bleiben bei Markus. Fällt der Server aus, spielt keine App mehr die Folgen ab. Nur mit Überwachung.
   - Vorschlag: A, wenn die Logs abrufbar sind, sonst B. Bei B und C gibt es Daten erst ab dem Einbau.
4. **YouTube:** Markus legt ein Google-Cloud-Projekt an, aktiviert „YouTube Data API v3“ und „YouTube Analytics API“, erstellt einen OAuth-Client (Webanwendung) und stellt den Zustimmungsbildschirm auf „In Produktion“ (im Modus „Test“ laufen Refresh-Tokens nach 7 Tagen ab). Du führst Schritt für Schritt durch. Danach einmal im Cockpit „YouTube verbinden“ klicken; ab dann läuft YouTube ohne Zutun.
5. **Wochen-Routine auf dem Mac:** Die Routine braucht den Mac wach, Chrome mit Claude-Erweiterung offen und Anmeldungen bei Spotify for Creators, Apple Podcasts Connect, Amazon Music for Podcasters und im podcast-admin. Passt Montag 08:20 (vor der Produktions-Session um 09:00)? Ist „Computer wach halten“ in der Claude-App an?
6. **Beispiel-Daten:** Darf ich in Stufe 0 einmal über Chrome eine Spotify-CSV exportieren und die Apple- und Amazon-Seiten ansehen, damit Import und Ableseschritte auf die echten Seiten passen? Nur lesen, nichts ändern.
7. **Apple Podcasters Program:** Mitglied (kostenpflichtig)? Nur dann gibt es die Reporter-Schnittstelle; sonst liest die Routine die Werte im Browser ab.
8. **Stufe 3:** Gewünscht sind KI-Antwortentwürfe, das Postfach frag@monteur-podcast.de im Cockpit und eine eigene Kommentarfunktion auf den Folgenseiten? (Ja/Nein je Punkt.)

## 5. Anforderungen (was Markus braucht)

Muss = Stufe 1, Soll = Stufe 2, Kann = Stufe 3.

### A Zahlen

| Nr. | Prio | Anforderung |
| --- | --- | --- |
| A1 | Muss | **Übersicht:** Reichweite gesamt im gewählten Zeitraum (7 / 28 / 90 Tage, seit Start, frei), Veränderung zur Vorperiode, Aufteilung nach Portal, Verlauf pro Tag, Top 5 Folgen, offene Kommentare. |
| A2 | Muss | **Je Portal eine Seite:** Leitwert, Zusatzwerte, Top-Folgen, Datenstand („Stand 07.10.2026, 06:00 · Quelle: API / Routine“). |
| A3 | Muss | **Je Folge:** Tabelle Folgen × Portale, sortierbar. Detailseite mit Verlauf seit Veröffentlichung und Werten nach 1, 3, 7 und 30 Tagen, damit neue und alte Folgen fair vergleichbar sind. |
| A4 | Muss | **Keine Doppelzählung** nach den Regeln in Abschnitt 7. |
| A5 | Soll | **Bester Veröffentlichungszeitpunkt:** Aufrufe und Downloads der ersten 72 Stunden nach Wochentag und Uhrzeit der Veröffentlichung, als Empfehlung für die Planung der zwei Folgen pro Woche. |
| A6 | Soll | **Hörer-Details:** Apps und Länder (aus der Download-Messung), YouTube-Wiedergabedauer und -Anteil je Folge. |
| A7 | Kann | CSV-Export jeder Tabelle; Zahl der Newsletter-Abonnenten als Kennzahl. |

### B Kommentare

| Nr. | Prio | Anforderung |
| --- | --- | --- |
| B1 | Muss | **Ein Postfach für alle Portale:** neueste zuerst, Filter nach Portal, Folge und Status, Zähler „neu“ in der Navigation. |
| B2 | Muss | **Status je Kommentar:** Neu, Beantwortet, Erledigt (ohne Antwort), Später. Interne Notiz möglich. |
| B3 | Muss | **YouTube: Antworten im Cockpit.** Antwortfeld mit Vorschau, „Antwort senden“ schickt sofort über die API. Die Antwort erscheint im Thread, Status springt auf „Beantwortet“. |
| B4 | Muss | **Apple-Bewertungen** automatisch einlesen (nur lesen). Hinweis „Antwort bei Apple nicht möglich“. |
| B5 | Soll | **Spotify-Kommentare im Postfach:** Die Routine liest neue Kommentare aus Spotify for Creators und importiert sie. Antwortet Markus im Cockpit auf einen Spotify-Kommentar, landet die Antwort in der Warteschlange („wird beim nächsten Routine-Lauf gesendet“) und die Routine postet sie in Spotify for Creators. Bis dahin: Direktlink zu Spotify for Creators › Kommentare. |
| B6 | Soll | **YouTube-Moderation:** „Zurückhalten“ und „Als Spam markieren“ mit Bestätigung. Kein Löschen. |
| B7 | Soll | **Kennzeichnen:** Frage, Lob, Kritik, Themenidee, Gast-Anfrage. Eigene Liste „Themenideen“ für den Folgenplan. |
| B8 | Soll | **Benachrichtigung** bei neuen Kommentaren: höchstens eine Mail pro Tag an Markus, oder Web-Push. |
| B9 | Kann | **KI-Antwortentwurf** (Anthropic API, Modell per ENV). Nur Entwurf im Antwortfeld, Senden immer von Hand. Regeln: duzen, kurz und direkt, keine Floskeln, nie das Wort „Kurzzeitvermietung“ (Buchungen laufen Wochen bis Monate), KWATERA24, MMW Holding, Einheitenzahlen und Arbeitgeber nicht nennen, keine Rechts- oder Steuerberatung, bei Gast-Interesse auf show@monteur-podcast.de verweisen. |
| B10 | Kann | **Hörerfragen aus frag@monteur-podcast.de** per IMAP (nur lesen) im Postfach. Antwort per Mail erst nach Klick. |
| B11 | Kann | **Eigene Kommentare auf den Folgenseiten** von monteur-podcast.de: Freigabe vor Veröffentlichung, Datenschutzhinweis, Spam-Schutz ohne Google reCAPTCHA (Honeypot, Rate-Limit). |

### C Automatik (nichts von Hand)

| Nr. | Prio | Anforderung |
| --- | --- | --- |
| C1 | Muss | **Server-Sync:** Alles mit Schnittstelle (Feed, YouTube, Apple-Bewertungen, Downloads) holt der Server selbst nach Zeitplan (Abschnitt 8.1). |
| C2 | Muss | **Knopf „Jetzt aktualisieren“** im Cockpit startet den Server-Sync sofort und zeigt den Fortschritt je Quelle. |
| C3 | Muss | **Claude-Routine „Podcast-Zahlen holen“** für alle Portale ohne Schnittstelle (Abschnitt 8.2): wöchentlich, auf Zuruf im Chat und, falls technisch möglich, per Knopf „Plattform-Daten holen“ im Cockpit. Stufe 1: Spotify-Zahlen. Stufe 2: Apple, Amazon, Spotify-Kommentare und Antwort-Warteschlange. |
| C4 | Muss | **Import-Schnittstelle für die Routine** im Cockpit: CSV-Upload und JSON-Import nach festem Schema (Abschnitt 8.2), mit Prüfung, Vorschau und idempotentem Speichern. Für Markus nur als Rückfalllösung sichtbar. |
| C5 | Muss | **Seite „Automatik“:** letzter Lauf je Quelle (Server und Routine), Ergebnis, nächster geplanter Lauf, Fehler in Klartext („Bei Apple abgemeldet, bitte in Chrome neu anmelden“). Warnung in der Übersicht, wenn eine Quelle älter als 8 Tage ist. |
| C6 | Muss | **Routine „Cockpit veröffentlichen“** als Skill im Repo (Abschnitt 8.3). Live geht nur etwas über diese Routine. |
| C7 | Soll | **Wochenbericht** nach jedem Routine-Lauf: Kurzbericht in der Sitzung und als Notiz im Obsidian-Vault unter `50 Analysen/Podcast-Wochenbericht JJJJ-KWNN.md`. |

### D Bedienung und Betrieb

| Nr. | Prio | Anforderung |
| --- | --- | --- |
| D1 | Muss | Aufruf im podcast-admin über den Menüpunkt „Analytics“, ohne zweites Login (Abschnitt 10). |
| D2 | Muss | Am Handy voll bedienbar (Übersicht und Postfach), ab 360 px Breite, keine seitliche Scrollleiste. |
| D3 | Muss | Seiten laden aus der eigenen Datenbank, nie live von Plattform-APIs beim Seitenaufruf. |
| D4 | Muss | Tägliche Sicherung der Datenbank, 14 Tage aufbewahren, Rücksicherung im README beschrieben. |

## 6. Datenquellen je Portal (Recherche-Stand 07.10.2026)

| Portal | Zahlen | Weg ins Cockpit | Kommentare | Antworten |
| --- | --- | --- | --- | --- |
| YouTube | Aufrufe, Wiedergabezeit, Ø Wiedergabedauer und -anteil, Likes, Kommentare, neue Abonnenten je Video und Tag | Server: YouTube Analytics API `reports.query` (`ids=channel==MINE`, `dimensions=video` bzw. `day`), Data API `videos.list` (`statistics`) | Server: `commentThreads.list` mit `allThreadsRelatedToChannelId` | Server: `comments.insert`, `comments.setModerationStatus` |
| Spotify | Plays/Starts/Streams, Hörer, Follower, je Folge | Kein Creator-API. Routine exportiert in Chrome die CSV aus Spotify for Creators (Export nur im Web) und importiert sie | Routine liest den Kommentare-Tab in Spotify for Creators | Routine postet freigegebene Antworten aus der Warteschlange |
| Apple Podcasts | Plays, Hörer, engagierte Hörer (mind. 20 Min. oder 40 %), Follower; gezählt werden Geräte | Routine liest Podcasts Connect › Analytics in Chrome ab. Reporter-API nur mit kostenpflichtigem Apple Podcasters Program. Downloads aus Apple-Apps zusätzlich über die Download-Messung | Server: öffentlicher Bewertungs-Feed, bis ca. 500 je Länder-Store | Nicht möglich |
| Amazon Music | Werte im Dashboard „Amazon Music for Podcasters“ (nach Beanspruchen) | Routine liest in Chrome ab | Keine bekannt | – |
| Deezer | Streams, Hörer, Fans (App „Analytics by Deezer“) | Routine nur, falls die Statistik im Browser erreichbar ist; Stand in Stufe 0 prüfen | Keine bekannt | – |
| Podcast Index | Nur Verzeichnis | – | – | – |
| Feed-Downloads | Downloads nach IAB-Regeln, Apps, Länder | Server: Download-Messung A, B oder C | – | – |
| Website-Player | Starts, Hördauer | Server: eigener cookieloser Beacon | B11 | B11 |

**Feste Daten**

| Was | Wert |
| --- | --- |
| Feed | https://monteur-podcast.de/feed.xml |
| Homepage | https://monteur-podcast.de |
| podcast-admin | https://monteur-podcast.de/podcast-admin/ (Cockpit unter `/podcast-admin/analytics/`) |
| Folgen-Thumbnails | `https://monteur-podcast.de/media/img/folgen/folge-NN.jpg` |
| YouTube-Kanal-ID | UChfzQnxKs6On8gsVByAPZRw (@DerMonteurPodcast) |
| Spotify | https://open.spotify.com/show/0Sg4eFxoVIm0pCeKfCrQHf |
| Apple Podcasts | ID 6819469570 |
| Amazon Music | https://music.amazon.de/podcasts/08e83ef8-bf25-45a0-9e2e-638d2e29c25b/der-monteurpodcast |
| Deezer | https://www.deezer.com/de/show/1003641082 |
| Podcast Index | https://podcastindex.org/podcast/8060613 |
| E-Mail | podcast@ (kurz), show@ (Gäste, ausführlich), frag@ (Hörerfragen) @monteur-podcast.de |

**Hinweise zu den Quellen**

- **YouTube-Kontingent:** 10.000 Einheiten pro Tag und Projekt. Lesen kostet 1 Einheit, `comments.insert` und `setModerationStatus` je 50. `search.list` (100) nicht verwenden; Videos über die Uploads-Playlist (`playlistItems.list`, 1). Kommentare alle 30 Minuten abrufen kostet rund 50 Einheiten am Tag.
- **YouTube-Scopes:** `youtube.force-ssl`, `yt-analytics.readonly`, `youtube.readonly`.
- **YouTube-Verzögerung:** Analytics liefert Tageswerte mit Verzug; aktueller Gesamtzähler aus `videos.list`. Beides klar beschriften.
- **Spotify und der eigene Server:** Ob Spotify die MP3 bei jedem Abruf von monteur-podcast.de lädt oder zwischenspeichert, ist nicht sicher („Passthrough“ wird beim Spotify-Support beantragt). Spotify-Zahlen deshalb immer aus der CSV. Begriffe (Plays, Starts, Streams) aus dem Spotify-Glossar übernehmen.
- **Spotify-CSV-Import:** Spaltennamen nicht hart verdrahten; Zuordnung einmal mit Markus festlegen und speichern, Originaldatei aufbewahren, wiederholter Import derselben Datei ändert nichts.
- **Apple-Bewertungs-Feed:** `https://itunes.apple.com/de/rss/customerreviews/id=6819469570/sortBy=mostRecent/json`. Format vor Umsetzung prüfen. Täglich, Storefront `de`, optional `at` und `ch`.
- **OP3 (falls B):** API mit Bearer-Token. Downloads je Folge nach 1, 3, 7, 30 Tagen, Apps und Länder. Speichert laut eigener Angabe keine rohen IP-Adressen. GUIDs im Feed bleiben gleich.
- **Server-Logs (falls A):** Log-Stufe bei all-inkl wählbar (volle IP, teilanonymisiert, ohne IP, keine). Für die Zählung nach IAB mindestens „teilanonymisiert“. Logs nur einlesen, nie dauerhaft mit IP speichern.
- **Browser statt Cookie-Klau:** Portale ohne Schnittstelle liest Claude in Markus' eigenem Chrome ab, in dem Markus angemeldet ist. Kein Server-Skript mit kopierten Browser-Cookies und keine inoffiziellen Endpunkte.

## 7. Zählregeln (Kern des Cockpits)

1. **Jedes Portal hat genau einen Leitwert.** Nur Leitwerte gehen in „Reichweite gesamt“:
   - YouTube: Aufrufe.
   - Spotify: der Hauptwert aus Spotify for Creators (Plays bzw. Starts, je nach CSV).
   - Feed-Downloads: Downloads nach IAB-Richtlinie 2.2 (Punkt 3).
   - Website-Player: Starts mit mindestens 60 Sekunden Wiedergabe (eigene Definition, so beschriften).
2. **Zusatzwerte zählen nicht in die Summe.** Apple-Plays und -Hörer, Amazon und Deezer stehen auf den Portal-Seiten; diese Hörer stecken schon in den Feed-Downloads. Downloads mit Spotify-User-Agent fliegen aus der Summe, sobald Spotify-Werte für den Zeitraum vorliegen.
3. **Download nach IAB 2.2:**
   - Gezählt wird ein Abruf erst ab einer Minute übertragener Audiodaten. Schwelle je Datei: `Bytes pro Minute = enclosure length / Dauer in Minuten` (aus dem Feed). Bei 192 kbit/s rund 1,44 MB.
   - Gleiche Kombination aus IP, User-Agent und Folge innerhalb von 24 Stunden zählt einmal (Fenster ab dem ersten Abruf); Methode in der Oberfläche nennen.
   - Raus: Bots (OPAWG-Liste), `HEAD`-Anfragen, Prüf-Anfragen auf die ersten Bytes (`Range: bytes=0-1`), Fehlerstatus. Apps aus dem User-Agent bestimmen (OPAWG-Liste).
4. **Beschriftung:** „Reichweite = Summe der Leitwerte. Keine Personenzahl.“ Tooltip mit der Definition je Portal.
5. **Zeit und Format:** Zeitzone Europe/Berlin, Wochen Montag bis Sonntag, Zahlen `1.234`, Datum `07.10.2026`.
6. **Prüfbar:** Für jede Kennzahl ist sichtbar, aus welcher Quelle und von wann sie stammt. Die Summe lässt sich aus den angezeigten Leitwerten nachrechnen.

## 8. Automatik: drei Wege, nichts von Hand

### 8.1 Server-Sync (ohne Claude)

Läuft im Express-Server, kostet nichts und braucht weder Mac noch Claude.

| Quelle | Zeitplan | Sofort per Knopf |
| --- | --- | --- |
| Feed (Folgen-Stammdaten) | stündlich | ja |
| YouTube-Kommentare | alle 30 Minuten | ja |
| YouTube-Zahlen | täglich 06:00 | ja |
| Apple-Bewertungen | täglich 06:10 | ja |
| Downloads | stündlich (Logs) bzw. täglich (OP3) | ja |
| Datenbank-Sicherung | täglich 03:00 | nein |

Jobs laufen nie parallel zur gleichen Quelle. Jeder Lauf schreibt `sync_runs`. Der Knopf „Jetzt aktualisieren“ ruft `POST /api/sync` auf und zeigt den Fortschritt.

### 8.2 Claude-Routine „Podcast-Zahlen holen“ (Portale ohne Schnittstelle)

**Auslöser:**
- **Zeitplan:** wöchentlich Montag 08:20, vor der Produktions-Session.
- **Zuruf:** Markus schreibt im Claude-Chat „Hol die Podcast-Zahlen“ oder klickt bei der geplanten Aufgabe auf „Jetzt ausführen“.
- **Knopf im Cockpit „Plattform-Daten holen“:** Cloud-Routinen von Claude Code haben einen API-Auslöser (`POST …/routines/<id>/fire` mit Bearer-Token). Prüfe in Stufe 0, ob die auf dem Mac laufende Routine so einen Auslöser hat. Wenn ja: Der Express-Server ruft ihn auf, der Token liegt nur in der Server-`.env`. Wenn nein: Der Knopf zeigt „Im Claude-Chat ‚Hol die Podcast-Zahlen‘ schreiben“ und den Link zur Routine.

**Einrichten:** Wenn Stufe 1 live ist, sagt Markus im Claude-Chat „Richte die Cockpit-Routine ein“. Claude legt dann eine geplante Aufgabe an, die diesen Mac braucht, mit dem Text aus `.claude/skills/podcast-zahlen-holen/SKILL.md`. Du schreibst diesen Skill im Repo (Entwurf unten) und hältst ihn aktuell.

**JSON-Schema für den Import** (in `packages/shared`, mit zod geprüft):

```json
{
  "source": "spotify | apple | amazon | deezer",
  "kind": "metrics | comments | reply_sent",
  "captured_at": "2026-10-12T08:31:00+02:00",
  "period": { "from": "2026-10-05", "to": "2026-10-11" },
  "show": { "followers": 0 },
  "episodes": [
    { "guid": "…", "title": "Folge 07: …", "metrics": { "plays": 0, "listeners": 0, "engaged": 0 } }
  ],
  "comments": [
    { "external_ref": "…", "episode_title": "…", "author": "…", "text": "…", "posted_at": "…" }
  ]
}
```

Unbekannte Folgen werden nicht verworfen, sondern zur Zuordnung vorgelegt. Doppelte Importe ändern nichts.

**Entwurf `.claude/skills/podcast-zahlen-holen/SKILL.md`:**

```markdown
---
name: podcast-zahlen-holen
description: Holt Zahlen und Kommentare aus Spotify for Creators, Apple Podcasts Connect und Amazon Music for Podcasters und lädt sie ins Podcast-Cockpit. Nutzen bei „Hol die Podcast-Zahlen“ und in der Wochen-Routine.
---
# Podcast-Zahlen holen

Ziel: Das Podcast-Cockpit (monteur-podcast.de/podcast-admin/analytics/) hat danach die Werte aller Portale bis gestern. Markus muss nichts tun.

## Voraussetzungen
- Chrome mit Claude-Erweiterung ist verbunden. Neuer Tab, keine offenen Tabs von Markus verändern.
- Markus ist angemeldet: podcast-admin, Spotify for Creators, Apple Podcasts Connect, Amazon Music for Podcasters.
- Nie Passwörter oder Codes eingeben. Fehlt eine Anmeldung: diese Quelle überspringen und im Bericht nennen.

## Ablauf
1. Cockpit › Automatik öffnen. „Jetzt aktualisieren“ klicken (Server-Quellen). Zeitraum seit dem letzten erfolgreichen Routine-Lauf ablesen.
2. Spotify for Creators › Analytics: CSV-Export Übersicht und alle Folgen für den Zeitraum (mindestens 28 Tage). Im Cockpit › Automatik › Import hochladen, Vorschau prüfen, bestätigen.
3. Spotify for Creators › Kommentare: neue Kommentare seit dem letzten Lauf als JSON (kind: comments) importieren. Danach im Cockpit die Antwort-Warteschlange öffnen. Nur Antworten mit Status „von Markus freigegeben“ in Spotify for Creators beim richtigen Kommentar posten, wortgleich, und im Cockpit als gesendet melden (kind: reply_sent). Nie selbst Antworten formulieren oder senden.
4. Apple Podcasts Connect › Analytics: je Folge Plays, Hörer, engagierte Hörer und Follower für den Zeitraum ablesen, als JSON (kind: metrics) importieren.
5. Amazon Music for Podcasters: Werte ablesen, als JSON importieren.
6. Deezer: nur wenn die Statistik im Browser erreichbar ist.
7. Plausibilität: Gesamtzähler dürfen nicht sinken. Abweichungen über 50 % zur Vorwoche im Bericht nennen.
8. Bericht: eine Zeile je Quelle (geholt / übersprungen mit Grund), die drei wichtigsten Zahlen der Woche, Zahl offener Kommentare. In der Sitzung ausgeben und als Notiz im Vault unter `50 Analysen/Podcast-Wochenbericht JJJJ-KWNN.md` ablegen.

## Was nicht passieren darf
- Keine Einstellungen in Spotify, Apple oder Amazon ändern, nichts löschen, nichts veröffentlichen außer freigegebenen Antworten.
- Keine Mails oder Nachrichten an andere.
- Keine Zugangsdaten in Notizen, Berichten oder Chat.
```

### 8.3 Claude-Routine „Cockpit veröffentlichen“ (Hosting auf Anweisung)

Markus sagt „Veröffentliche das Cockpit“ (oder „Cockpit hosten“, „Cockpit-Update live stellen“). Dann führt Claude diese Routine aus. Du schreibst sie als `.claude/skills/cockpit-veroeffentlichen/SKILL.md` (Entwurf unten) und testest sie einmal vollständig mit Rücksprung auf die Vorversion.

**Entwurf `.claude/skills/cockpit-veroeffentlichen/SKILL.md`:**

```markdown
---
name: cockpit-veroeffentlichen
description: Stellt das Podcast-Cockpit live (Express-API auf dem Server, React-App im podcast-admin). Nur nutzen, wenn Markus es ausdrücklich anweist, z. B. „Veröffentliche das Cockpit“.
---
# Cockpit veröffentlichen

## Vor dem Hochladen
1. Arbeitsstand sauber (git status), `npm run lint`, `npm test`, Playwright und `npm run build` ohne Fehler.
2. Änderungsliste zeigen: Commits seit der letzten Veröffentlichung, Datenbank-Migrationen, Änderungen am podcast-admin. Markus' Freigabe abwarten.

## Erstes Hosting (einmalig, Variante 1)
- Server bestellt und bezahlt Markus. Zugang nur per SSH-Schlüssel.
- Server einrichten: Node.js LTS, Caddy, systemd-Dienst, Firewall (nur 22, 80, 443), automatische Sicherheitsupdates, Backup-Cron, Ordner `releases/` mit Symlink `current`.
- DNS-Eintrag `api.monteur-podcast.de` im all-inkl-KAS: nur nach Markus' Freigabe, in seinem Chrome.
- podcast-admin ergänzen: Ordner `analytics/`, Menüpunkt „Analytics“, Token-Skript. Vorher Sicherung der geänderten Dateien.

## Hochladen
1. Sicherung: Datenbank auf dem Server sichern; aktuellen Stand von `podcast-admin/analytics/` als `analytics-alt/` behalten.
2. Backend: neues Release per rsync über SSH nach `releases/<datum>`, `npm ci --omit=dev`, Migrationen, Symlink `current` umstellen, Dienst neu starten, `GET /api/health` prüfen.
3. Frontend: Vite-Build zuerst nach `podcast-admin/analytics-neu/` hochladen (FTP mit Zugang aus dem macOS-Schlüsselbund oder WebFTP in Markus' Chrome), dann `analytics/` → `analytics-alt/` und `analytics-neu/` → `analytics/` umbenennen. So ist nie ein halber Stand live.
4. Prüfen: Aufruf über podcast-admin › Analytics, Übersicht lädt, Postfach lädt, „Jetzt aktualisieren“ läuft, Ansicht bei 360 px, kurzer Lighthouse-Lauf.

## Wenn etwas schiefgeht
Sofort zurück: Symlink auf das vorige Release, `analytics-alt/` wieder zu `analytics/`, Datenbank aus der Sicherung nur nach Rückfrage. Dann Bericht mit Fehlerursache.

## Bericht an Markus
Version, was neu ist, Ergebnis der Prüfung, Link zum Cockpit. Keine Zugangsdaten im Bericht.
```

## 9. Folgen-Stammdaten und Zuordnung

- **Quelle der Wahrheit ist der Feed** (`guid`, Titel, Folgennummer, `pubDate`, MP3-Adresse, Länge, Dauer). Stündlich einlesen. Geplante Folgen erscheinen erst ab Veröffentlichung.
- **YouTube-Video zu Folge:** über „Folge NN“ im Videotitel, in der Oberfläche korrigierbar. Videos ohne Folge (Trailer, Intro-Videos, Shorts) unter „Ohne Folge“.
- **Spotify-, Apple-, Amazon-Zeile zu Folge:** über Titelähnlichkeit und Datum. Unsichere Treffer legt das Cockpit Markus einmal zur Bestätigung vor; die Zuordnung wird gespeichert und gilt ab dann automatisch.

## 10. Architektur

- **Anmeldung (ein Login):** Markus meldet sich wie gewohnt im podcast-admin an. Ein kleines PHP-Skript `podcast-admin/analytics/token.php` prüft die bestehende Admin-Sitzung und gibt ein kurzlebiges signiertes Token aus (HS256, 15 Minuten, gemeinsames Geheimnis nur in der Server-`.env` und in einer geschützten PHP-Konfigurationsdatei außerhalb des Repos). Die React-App holt das Token und schickt es als Bearer an die Express-API. Ohne Admin-Sitzung leitet die App zum podcast-admin-Login. Hat der podcast-admin keinen zweiten Faktor, schlägst du einen vor, baust ihn aber nicht eigenmächtig ein.
- `apps/server/src/connectors/`: `feed`, `youtube`, `youtubeAnalytics`, `appleReviews`, `downloadsLogs`, `downloadsOp3`, `downloadsPrefix`, `websiteBeacon`, `routineImport`. Gemeinsame Schnittstelle `sync(range) → SyncResult`.
- `apps/server/src/routes/`: `health`, `overview`, `episodes`, `platforms`, `comments`, `replies`, `sync`, `import`, `automation`, `beacon`, `connections`. REST unter `/api`, Ein- und Ausgaben mit zod geprüft. CORS nur für `https://monteur-podcast.de`.
- **Antwortweg YouTube:** Antwort lokal als „wird gesendet“ speichern, dann `comments.insert`, dann Status und `external_id` setzen. Bei Fehler Text behalten, Fehler in Klartext.
- **Antwortweg Spotify:** Antwort mit Status „von Markus freigegeben“ und Zeitstempel in `reply_queue`; die Routine meldet „gesendet“ zurück.
- **Beacon für den Website-Player:** `POST /api/b` per `navigator.sendBeacon`, kein Cookie, kein Fingerprinting, IP nur gekürzt und mit Tagessalz gehasht, nicht gespeichert. Snippet für die Homepage als Vorschlag, Einbau nach Freigabe.

**Datenmodell (Vorschlag):**
- `episodes` (guid, nummer, titel, veröffentlicht_am, mp3_url, bytes, dauer_s, youtube_video_id)
- `episode_aliases` (Portal, fremder Titel oder ID → episode_id)
- `metric_daily` (episode_id oder null für Show, platform, metric, datum, wert, source, imported_at), eindeutiger Schlüssel für wiederholbare Läufe
- `metric_totals` (Gesamtzähler je Folge und Portal mit Zeitstempel)
- `downloads_raw` nur so lange wie für die Zählung nötig, IP gehasht mit Tagessalz, danach löschen
- `comments` (platform, external_id oder external_ref, episode_id, parent_id, autor_name, autor_url, text, veröffentlicht_am, status, tags, notiz, beantwortet_am, raw_json)
- `reply_queue` (comment_id, text, freigegeben_am, gesendet_am, external_id, kanal: api | routine)
- `imports` (Typ, Quelle, Prüfsumme, Zeilen, Zeitpunkt, Original)
- `sync_runs` und `routine_runs` (Quelle, Start, Ende, Ergebnis, Fehlertext)
- `connections` (Portal, verschlüsselte Tokens, läuft_ab_am)
- `audit_log` (jede Antwort, Moderation, Veröffentlichung), `settings`

## 11. Oberfläche (Designsystem „Plakat“, für ein Arbeitswerkzeug gedämpft)

**Tokens (fest):**

| Token | Wert | Einsatz |
| --- | --- | --- |
| Tinte | #0A0F16 | Text, Rahmen, Schatten, dunkle Flächen |
| Podcast-Blau | #2E75B5 | Flächen, aktive Navigation, Fokusrahmen |
| Rosa | #E48B9B | Hervorhebungen, Hauptknopf, Aufkleber „NEU“ |
| Weiß | #FFFFFF | Karten, Hintergrund |
| Grau | #8B98A8 | nur deaktivierte Elemente |
| Schrift | Geist 400 bis 900, Geist Mono 600 | lokal ausgeliefert (woff2, latin, `font-display: swap`), kein Google-Fonts-CDN |
| Rahmen | 3 bis 4 px Tinte | Karten, Knöpfe, Eingabefelder |
| Schatten | hart versetzt 4 bis 8 px, ohne Unschärfe | Karten, Knöpfe |
| Ecken | keine Rundung | überall |

**Regeln:**
- Werkzeug vor Plakat: überwiegend Weiß mit Tinte, Plakat-Elemente gezielt. Seitentitel 32 bis 48 px in Geist 900 Großbuchstaben, Fließtext 16 bis 17 px, Etiketten in Geist Mono 12 bis 13 px Großbuchstaben.
- Keine Verläufe, kein Glas, kein Glow, keine weiteren Akzentfarben, keine Markenfarben der Plattformen. Plattformen erkennbar an Logo (Simple Icons, in Tinte) und Namen darunter.
- **Kontrast:** Weiß auf Blau passt (ca. 4,9:1). Tinte auf Blau nur für große Schrift (ca. 3,9:1). Rosa nie als Textfarbe auf Weiß (ca. 2,5:1), nur als Fläche mit Tinte-Text.
- **Diagramme:** Reihen in Blau, Rosa, Tinte und Tinte schraffiert; Beschriftung direkt am Balken oder an der Linie; zu jedem Diagramm eine Tabellenansicht; höchstens vier Reihen, Rest als „Weitere“.
- Knöpfe: beim Daraufgehen 3 px nach rechts unten, Schatten schrumpft. Hauptknopf rosa, zweiter weiß, Senden schwarz mit blauem Schatten.
- Die Seiten fügen sich in den podcast-admin ein: gleiche Kopfzeile bzw. sichtbarer Rückweg „Zurück zum podcast-admin“.
- Navigation: Desktop eine Leiste oben (Übersicht · Folgen · Portale · Kommentare mit Zähler · Automatik). Handy: Leiste unten mit vier Punkten, Rest im Menü.

**Seiten:**
1. **Übersicht:** Zeitraum-Umschalter, Kennzahlen-Reihe (Reichweite gesamt, YouTube, Spotify, Feed-Downloads, offene Kommentare), Balken nach Portal, Verlauf, Top 5 Folgen, Hinweise (veraltete Quellen, Fehler), Knopf „Jetzt aktualisieren“.
2. **Folgen:** Tabelle, Detailseite je Folge mit Thumbnail, Verlauf, 1/3/7/30-Tage-Werten und Kommentaren der Folge.
3. **Portale:** eine Seite je Portal.
4. **Kommentare:** Desktop zweispaltig (Liste | Verlauf mit Antwortfeld), Handy Liste und Detail. Tastenkürzel `j`/`k` weiter, `r` antworten, `e` erledigt. Bei Spotify zeigt der Senden-Knopf „In Warteschlange (nächster Routine-Lauf)“.
5. **Automatik:** letzter und nächster Lauf je Quelle, „Jetzt aktualisieren“, „Plattform-Daten holen“, Antwort-Warteschlange, Verbindungen (YouTube verbinden, Ablaufdaten), Import als Rückfalllösung.

Leere Zustände sagen, was passiert („Spotify-Daten kommen mit dem nächsten Routine-Lauf am Montag, 12.10., 08:20.“).

## 12. Performance, SEO, Responsive (verbindlich)

**Performance**
- Budget: initiales JavaScript höchstens 150 KB gzip, CSS höchstens 30 KB gzip.
- Code-Splitting je Route. Diagramm-Code nur per Lazy Import auf Seiten mit Diagrammen; leichte Lösung (z. B. uPlot oder eigene SVG-Komponenten).
- Lighthouse mobil: Performance mind. 90, Barrierefreiheit mind. 95, Best Practices mind. 95. LCP unter 2,0 s, CLS unter 0,05, INP unter 200 ms.
- Assets mit Hash im Namen und langer Cache-Zeit (bei all-inkl per `.htaccess` im Ordner `analytics/`: `Cache-Control: public, max-age=31536000, immutable` für `assets/`, `no-cache` für `index.html`; gzip/Brotli, soweit der Server es kann). API mit ETag, Brotli/gzip über Caddy.
- Postfach mit Seiten zu 50 Einträgen, virtuelle Liste ab 500. Thumbnails mit `width`/`height` und `loading="lazy"`.

**SEO**
- Das Cockpit ist intern: `<meta name="robots" content="noindex, nofollow">`, Header `X-Robots-Tag: noindex, nofollow` (API und `analytics/` per `.htaccess`), `Disallow: /podcast-admin/` in der `robots.txt` als Vorschlag. Trotzdem sauberes HTML: `lang="de"`, ein `h1` je Seite, eindeutige Seitentitel.
- Öffentliche Teile (Beacon-Skript, B11): laden asynchron und dürfen LCP und CLS der Homepage nicht verschlechtern (vorher und nachher messen). Kommentare auf Folgenseiten serverseitig als HTML ausliefern, Links mit `rel="ugc nofollow"`, strukturierte Daten `Comment` innerhalb `PodcastEpisode`.

**Responsive**
- Mobile-first. Prüfen bei 360, 390, 768, 1024 und 1440 px.
- Keine Seite scrollt seitlich. Tabellen am Handy als Karten oder mit fester erster Spalte; seitliches Scrollen nur innerhalb der Tabelle.
- Tippziele mind. 44 × 44 px. Antwortfeld bleibt am Handy über der Tastatur sichtbar.
- Barrierefreiheit nach WCAG 2.2 AA: volle Tastaturbedienung, sichtbarer Fokus (4 px Blau), Diagramme mit Tabellen-Alternative, Formulare mit Labels.

## 13. Sicherheit und Datenschutz

- OAuth-Tokens in der Datenbank mit AES-256-GCM verschlüsselt, Schlüssel aus ENV. Der Token für den Routine-Auslöser liegt nur in der Server-`.env`.
- Helmet mit strenger Content-Security-Policy, CSRF-Schutz bzw. Bearer-Token für alle schreibenden Anfragen, Rate-Limits, Audit-Log.
- Server in Deutschland oder der EU. Personenbezogene Daten sparsam; IP-Adressen nie im Klartext speichern.
- Für die Datenschutzerklärung von monteur-podcast.de lieferst du einen Textvorschlag (Download-Messung, Beacon, ggf. OP3). Markus lässt ihn prüfen und baut ihn selbst ein.
- ENV-Variablen (nur Namen ins Repo): `DATABASE_PATH`, `ENCRYPTION_KEY`, `ADMIN_TOKEN_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `YT_CHANNEL_ID`, `APPLE_PODCAST_ID`, `FEED_URL`, `OP3_TOKEN`, `ROUTINE_FIRE_URL`, `ROUTINE_FIRE_TOKEN`, `SMTP_*`, `IMAP_*`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `PUBLIC_ORIGIN`.

## 14. Was Markus am Ende noch selbst tut

- Einmalig: Server bestellen (Variante 1), Google-Cloud-Projekt anlegen (mit Anleitung), einmal „YouTube verbinden“ klicken, Freigaben geben.
- Gelegentlich: sich neu anmelden, wenn Apple, Spotify oder Amazon ihn in Chrome abmelden. Das Cockpit und der Wochenbericht sagen ihm, wann.
- Laufend: lesen, antworten, freigeben. Sonst nichts.

## 15. Abgrenzung

Das Cockpit veröffentlicht keine Folgen und ändert keine Folgen-Daten. Dafür bleibt der Skill „monteur-podcast-veroeffentlichen“ mit dem Upload-Helfer zuständig.

## 16. Ausbaustufen

**Stufe 1 (Muss):** Anmeldung über den podcast-admin, Feed-Stammdaten, YouTube verbinden, YouTube-Zahlen und -Kommentare mit Antworten, Apple-Bewertungen, Download-Messung nach Entscheidung aus Stufe 0, Server-Sync mit Knopf, Import-Schnittstelle, Routine „Podcast-Zahlen holen“ mit Spotify-Zahlen, Übersicht, Folgen, Portale, Postfach, Automatik-Seite, Sicherung, Routine „Cockpit veröffentlichen“, erstes Hosting auf Markus' Anweisung, Tests.

**Stufe 2 (Soll):** Routine erweitert um Apple, Amazon (und Deezer, falls möglich), Spotify-Kommentare und Antwort-Warteschlange, Knopf „Plattform-Daten holen“, Wochenbericht im Vault, Website-Beacon (Einbau nach Freigabe), bester Veröffentlichungszeitpunkt, Apps und Länder, YouTube-Moderation, Kennzeichnen und Themenideen, Benachrichtigung.

**Stufe 3 (Kann, nur wenn in Stufe 0 gewünscht):** KI-Antwortentwürfe, Postfach frag@, Kommentarfunktion auf den Folgenseiten, CSV-Export.

## 17. Abnahme je Stufe

- Alle Punkte der Stufe erfüllt und von Markus im podcast-admin angesehen.
- `npm run build`, `npm run lint`, `npm test` und Playwright laufen ohne Fehler.
- Lighthouse-Bericht mit den Zielwerten aus Abschnitt 12 liegt unter `docs/lighthouse/`.
- Playwright-Screenshots bei 360 px ohne seitliches Scrollen.
- gitleaks findet nichts.
- Die Gesamtreichweite lässt sich aus den angezeigten Leitwerten nachrechnen; ein Test prüft die Zählregeln aus Abschnitt 7 (Duplikate, Bots, HEAD, Range 0-1, Spotify-User-Agent).
- Die Routine „Podcast-Zahlen holen“ lief einmal per „Jetzt ausführen“ vollständig durch, die Werte stehen im Cockpit, ein zweiter Lauf erzeugt keine Dubletten.
- Die Routine „Cockpit veröffentlichen“ lief einmal durch, inklusive geprobtem Rücksprung auf die Vorversion.
- Eine echte YouTube-Antwort wurde einmal gesendet, auf einen von Markus ausgewählten Kommentar, nach seiner Freigabe.
- README und CLAUDE.md sind aktuell.

## 18. Quellen der Recherche (07.10.2026)

- YouTube Data API, Kontingentkosten: https://developers.google.com/youtube/v3/determine_quota_cost
- YouTube Data API, commentThreads: https://googleapis.github.io/google-api-python-client/docs/dyn/youtube_v3.commentThreads.html
- YouTube Analytics API, reports.query: https://developers.google.com/youtube/analytics/reference/reports/query
- YouTube Analytics API, Kanal-Berichte: https://developers.google.com/youtube/analytics/channel_reports
- Spotify for Creators, CSV-Export: https://support.spotify.com/bj-en/creators/article/downloading-your-shows-analytics-data/
- Spotify for Creators, Kommentare: https://support.spotify.com/gm/creators/article/reading-approving-reacting-comments/
- Spotify for Creators, Moderation: https://support.spotify.com/gr/creators/article/comment-settings
- Spotify, Zählweise Plays/Starts/Streams: https://support.spotify.com/am/creators/article/how-we-count-plays
- Spotify-Passthrough und Caching: https://help.tritondigital.com/docs/why-am-i-not-seeing-downloads-from-spotify-in-analytics und https://podnews.net/article/mythbusting-spotify-cache
- Apple Podcasts Reporter (Voraussetzungen, Kennzahlen): https://glama.ai/mcp/servers/conorbronsdon/apple-podcasts-mcp
- Apple-Bewertungs-Feed (Obergrenze ca. 500 je Store): https://apify.com/seemuapps/apple-podcast-reviews-scraper
- Amazon Music for Podcasters: https://www.podderapp.com/post/how-to-rank-on-amazon-music-charts
- Analytics by Deezer: https://newsroom-deezer.com/2020/08/analytics-by-deezer-a-new-tool-for-podcasters/
- OP3 API: https://ci.op3.dev/api/docs und https://op3.dev/releases
- IAB-Zählregeln: https://www.iabuk.com/sites/default/files/public_files/Podcast_measurement%20IAB%20UK.pdf und https://flightcast.com/measurement-methodology
- all-inkl und Node.js (Nutzerberichte): https://www.computerbase.de/forum/threads/suche-gruene-private-alternative-zu-all-inkl.2250399/ und http://mamas-blog.de/2017/05/11/all-inkl-com-node-js-und-composer-im-hosting-einrichten/
- Claude Code Routinen (Zeitplan, API-Auslöser, Jetzt ausführen): https://code.claude.com/docs/en/routines.md
- Claude Code geplante Aufgaben auf dem eigenen Rechner: https://code.claude.com/docs/en/desktop-scheduled-tasks.md

## Verwandte Notizen

[[Monteur-Podcast]] · [[Monteur-Podcast Designsystem Plakat]] · ersetzt [[Master-Prompt Podcast-Cockpit 2026-10-07_08-24]]
