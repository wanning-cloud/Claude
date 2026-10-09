# Offene Prüfungen vor dem Go-live

In der Cloud-Sitzung vom 08.10.2026 waren monteur-podcast.de, itunes.apple.com, op3.dev und developers.google.com gesperrt. Die folgenden Punkte sind nach bestem Wissen und Doku-Stand gebaut, aber nicht live gegen die echten Schnittstellen getestet. Bei der ersten Veröffentlichung einmal prüfen.

| Punkt | Annahme im Code | Wo | Prüfen |
| --- | --- | --- | --- |
| Feed | geprüft mit echtem `feed.xml` aus dem Datei-Export | `FeedConnector` | erledigt |
| Vorschaubilder | `window.MP_FOLGEN` in `podcast/folgen.js`, Feld `poster` | `FeedConnector::parsePosters` | erledigt (Export) |
| Spotify-CSV „Leistung“, „Trends“, Amazon „Überblick“ | Spaltennamen der echten Exporte vom 08.10.2026 | `CsvProfiles` + `RealExportsTest` | erledigt |
| Anmeldung podcast-admin | Sitzung `mp_admin`, `$_SESSION['ok'] === true`, Cookie-Pfad `/podcast-admin/` | `AdminSession` | nach Upload: im eingeloggten Browser `…/api/session` aufrufen |
| `.htaccess` | podcast-admin sperrt `.js`; `analytics/.htaccess` gibt `.js/.css` wieder frei (`Require all granted`) | `apps/web/public/.htaccess` | nach Upload: Cockpit lädt ohne 403 |
| Apple-Bewertungs-Feed | JSON `feed.entry[]` mit `im:rating.label`, `title.label`, `content.label`, `author.name.label`, `id.label`, `updated.label`; Seiten `page=N` | `AppleReviewsConnector` | einmal „Jetzt holen“, Ergebnis ansehen |
| YouTube Data API | `playlistItems.list` (Uploads-Playlist `UU…`), `videos.list` (`statistics`), `commentThreads.list` mit `allThreadsRelatedToChannelId`, `comments.list` (`parentId`), `comments.insert` (`snippet.parentId`, `snippet.textOriginal`), `channels.list?mine=true` | `YouTube*Connector`, `App::youtubeCallback` | nach „YouTube verbinden“: Zahlen holen, eine echte Antwort nach Freigabe |
| YouTube Analytics API | `reports.query` v2, `ids=channel==MINE`, `dimensions=day`, optional `filters=video==ID`, Metriken `views, estimatedMinutesWatched, averageViewDuration, averageViewPercentage, likes, comments, subscribersGained` | `YouTubeStatsConnector` | Metrik-Kombination mit `dimensions=day` prüfen; bei Fehler 400 die Liste kürzen |
| Zugriffs-Logs all-inkl | `/logs/access_log_monteur-podcast_de_JJJJ-MM-TT.gz` (von Markus bestätigt), Zeilenformat Apache „combined“ angenommen, optional mit Hostname vorn | `LogParser`, `DownloadsLogsConnector` | nach Upload „Downloads › Jetzt holen“: Meldung „N Zeilen im unbekannten Format“ muss fehlen; sonst drei Beispielzeilen (IP unkenntlich) an Claude geben |
| Log-Ordner erreichbar | `ACCESS_LOG_DIR=logs` wird vom Code-Ordner aufwärts gesucht; PHP darf dorthin lesen (`open_basedir`) | `App::resolveLogDir` | Fehlermeldung „Zugriffs-Logs nicht gefunden“ heißt: absoluten Pfad aus dem WebFTP eintragen |
| Erster Lauf | bis 190 Tage Rückstand, je Lauf ca. 200 Sekunden | `DownloadsLogsConnector` | nach Upload einige Male „Jetzt holen“ oder den Cron arbeiten lassen, bis „Noch … Log-Dateien offen“ verschwindet |
| OP3 (Variante B) | nicht gebaut, Markus hat Variante A gewählt | – | entfällt |
| PHP 8.5 | lokal mit 8.3 getestet; Code nach in 8.4/8.5 veralteten Mustern durchsucht (keine Funde) | – | nach Upload `…/api/health` und Fehlerprotokoll im KAS ansehen |
| Cron | ein Job alle 30 Minuten `…/api/cron?job=due&key=…` | `Schedule` | im KAS anlegen |
| YouTube Shorts getrennt (09.10.2026) | Kanalbericht mit `dimensions=day,creatorContentType` (laut Doku optionale Dimension der zeitbasierten Berichte, kein Filter); Shorts-Liste aus der Playlist `UUSH…` (inoffiziell), sonst Videos bis 3 Minuten | `YouTubeStatsConnector` | nach Upload „YouTube-Zahlen › Jetzt holen“: Meldung „N Videos und M Shorts“; Podcast-Zahl von YouTube sinkt um die Shorts-Aufrufe (gewollt) |
| Meta Graph API (09.10.2026) | Version `v25.0`; IG-Beitragswerte `views, reach, saved, shares, total_interactions`, Reels zusätzlich `ig_reels_avg_watch_time`; Konto `reach, views, accounts_engaged, total_interactions, …` mit `metric_type=total_value`; Seite `page_media_view, page_total_media_view_unique, page_post_engagements`; Beitrag `post_media_view, post_total_media_view_unique, post_clicks`. Abgelehnte Metriken werden übersprungen und im Lauf gemeldet | `InstagramConnector`, `FacebookConnector`, `MetaApi::insights` | nach „Meta verbinden“ einmal „Jetzt holen“ je Quelle; Meldung „übersprungen: …“ an Claude geben |
| Meta-Berechtigungen ohne App-Review | Standardzugriff reicht für Markus' eigene Seite, weil er Admin der App ist | `MetaAuth::SCOPES` | beim Verbinden sehen, ob Meta alle Berechtigungen anbietet; sonst `META_CONFIG_ID` (Login for Business) |
| Instagram-Vorschaubilder | URLs von `*.cdninstagram.com` / `*.fbcdn.net`, in der CSP erlaubt; laufen nach einigen Tagen ab (dann Platzhalter) | `apps/web/public/.htaccess` | Social-Seite ansehen |
