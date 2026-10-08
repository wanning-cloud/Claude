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
| Zugriffs-Logs all-inkl | Apache „combined“, optional mit Hostname vorn; Dateien `access*log*`, auch `.gz` | `LogParser`, `DownloadsLogsConnector` | Log-Stufe und Ablageort im KAS prüfen; drei Zeilen mit `LogParser` testen |
| OP3 (Variante B) | nicht gebaut, erst wenn A nicht geht | – | nur bei Bedarf |
| PHP-Version | 8.2+ | – | KAS › Domain |
| Cron | ein Job alle 30 Minuten `…/api/cron?job=due&key=…` | `Schedule` | im KAS anlegen |
