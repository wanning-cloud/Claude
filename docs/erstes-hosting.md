# Cockpit veröffentlichen – erstes Hosting

Für die Claude-Sitzung auf Markus' Mac (Chrome, WebFTP und KAS von all-inkl). Grundlage: `.claude/skills/cockpit-veroeffentlichen/SKILL.md` im Repo wanning-cloud/Claude, Branch claude/analytics-podcast-md-d0zjoo. Markus hat am 08.10.2026 „veröffentliche“ gesagt und den Menülink freigegeben. Trotzdem jeden Schritt am Server kurz ankündigen. Nie Passwörter oder Schlüssel eintippen, in den Chat schreiben oder notieren.

## Inhalt

- `analytics/` → kommt nach `podcast-admin/analytics/` (zuerst als `podcast-admin/analytics-neu/` hochladen, dann umbenennen)
- `podcast-admin-patch/index.php`, `podcast-admin-patch/newsletter.php` → ersetzen die gleichnamigen Dateien in `podcast-admin/` (nur der Menülink „Analytics“, siehe `menu-link.diff`)
- `podcast-admin-patch/orig/` → Stand aus Markus' Datei-Export, zum Vergleich
- Das Paket baut `npm run release`; die Admin-Dateien entstehen aus dem Datei-Export mit `docs/podcast-admin-menu-link.diff`.

## Reihenfolge

1. **Einstellungsdatei**: Im WebFTP einen Ordner **außerhalb** des Web-Ordners der Domain anlegen, z. B. `analytics-private/` im FTP-Hauptordner (neben `logs/`). Darin `analytics.env` nach Vorlage `apps/api/analytics.env.example` anlegen:
   - `DATABASE_PATH=<absoluter Pfad>/analytics-private/cockpit.sqlite`, `BACKUP_DIR=<absoluter Pfad>/analytics-private/backups`
   - `ENCRYPTION_KEY`, `ADMIN_TOKEN_SECRET`, `CRON_KEY`: **Markus** erzeugt sie (Passwort-Manager, 32+ Zeichen; `ENCRYPTION_KEY` muss Base64 von 32 Byte sein: Terminal `openssl rand -base64 32`) und trägt sie selbst ein.
   - `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`: trägt Markus selbst ein. `GOOGLE_REDIRECT_URI=https://monteur-podcast.de/podcast-admin/analytics/api/youtube/callback`
   - `ACCESS_LOG_DIR=logs`, `ACCESS_LOG_GLOB=access_log_monteur-podcast_de_*.gz`, `APP_ENV=prod`
   - Den absoluten Pfad zeigt der WebFTP bzw. der KAS (FTP-Hauptordner, meist `/www/htdocs/w…/`).
2. **Hochladen**: Inhalt von `analytics/` nach `podcast-admin/analytics-neu/`. Danach `podcast-admin/analytics-neu/api/app/env-path.txt` anlegen, Inhalt: absoluter Pfad zur `analytics.env` (eine Zeile).
3. **Umschalten**: `analytics-neu/` → `analytics/` (beim ersten Mal gibt es noch kein `analytics/`).
4. **Prüfen**: `https://monteur-podcast.de/podcast-admin/analytics/api/health` zeigt `"ok":true,"db":true`. Fehlt das: env-path.txt und Pfade prüfen.
5. **Menülink**: `podcast-admin/index.php` und `newsletter.php` vom Server laden und mit `orig/` vergleichen. Gleich → Server-Dateien als `podcast-admin/backup/index.php.bak-20261008` und `newsletter.php.bak-20261008` sichern, dann die Dateien aus `podcast-admin-patch/` hochladen. Nicht gleich → nicht ersetzen, sondern nur die eine Zeile aus `menu-link.diff` von Hand ändern.
6. **Anmelden und ansehen**: podcast-admin öffnen, anmelden, Link „Analytics“. Übersicht und Postfach laden. Upload-Helfer und Newsletter-Seite öffnen sich unverändert.
7. **Erste Daten**: Cockpit › Automatik › „Jetzt aktualisieren“. Feed muss „OK“ zeigen. Downloads holen den Rückstand in Etappen („Noch … Log-Dateien offen“). Meldet Downloads „Zeilen im unbekannten Format“: drei Log-Zeilen mit unkenntlich gemachter IP an Claude geben.
8. **YouTube**: Automatik › „YouTube verbinden“, mit dem Konto von @DerMonteurPodcast anmelden. Die Warnung „App nicht überprüft“ über „Erweitert“ bestätigen.
9. **Cronjob** im KAS (Tools › Cronjobs), alle 30 Minuten: `https://monteur-podcast.de/podcast-admin/analytics/api/cron?job=due&key=<CRON_KEY>` – den Schlüssel trägt Markus ein.
10. **PHP-Fehlerprotokoll** im KAS ansehen (PHP 8.5, Hinweise „deprecated“?).

## Wenn etwas schiefgeht

Cockpit: `analytics/` → `analytics-kaputt/`. podcast-admin: die `.bak-…`-Dateien zurück an ihren Platz. Bericht mit Fehlermeldung an Claude.
