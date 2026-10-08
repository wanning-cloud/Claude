---
name: cockpit-veroeffentlichen
description: Stellt das Podcast-Cockpit auf monteur-podcast.de live (React-Build und PHP-API in podcast-admin/analytics/ bei all-inkl, Variante 2). Nur nutzen, wenn Markus es ausdrücklich anweist, z. B. „Veröffentliche das Cockpit“, „Cockpit hosten“, „Cockpit-Update live stellen“.
---

# Cockpit veröffentlichen (Variante 2: alles bei all-inkl)

Live geht nur etwas über diese Routine und nur nach Markus' ausdrücklichem Ja für genau diese Veröffentlichung.

## Vor dem Hochladen

1. Arbeitsstand sauber (`git status`), dann `npm run lint`, `npm test`, `npm run test:e2e` und `npm run secrets` ohne Fehler.
2. `./scripts/build-release.sh` bauen. Ergebnis: `release/analytics/` (Inhalt siehe Skript, keine `.env`, keine Datenbank).
3. Markus zeigen: Commits seit der letzten Veröffentlichung (`app/VERSION` auf dem Server vs. `git log`), neue Migrationen in `apps/api/migrations/`, Änderungen am podcast-admin. Auf sein Ja warten.

## Erstes Hosting (einmalig)

Alles in Markus' Chrome (WebFTP und KAS von all-inkl), Freigabe für jeden Schritt einzeln:

1. **PHP-Version** der Domain im KAS prüfen: mindestens 8.2.
2. **Einstellungsdatei außerhalb des Web-Ordners** anlegen, z. B. `/analytics-private/analytics.env` neben dem Domain-Ordner (Werte siehe `apps/api/analytics.env.example`, Geheimnisse erzeugt Markus oder gibt sie selbst ein). Darin `DATABASE_PATH` und `BACKUP_DIR` ebenfalls außerhalb des Web-Ordners. Nie Zugangsdaten in den Chat, in Notizen oder ins Repo.
3. Nach dem ersten Upload `podcast-admin/analytics/api/app/env-path.txt` mit dem absoluten Pfad zu dieser Datei anlegen (der Ordner `app/` ist per `.htaccess` gesperrt).
4. **Cronjob** im KAS unter „Tools › Cronjobs“: alle 30 Minuten
   `https://monteur-podcast.de/podcast-admin/analytics/api/cron?job=due&key=<CRON_KEY>`
   Den Schlüssel trägt Markus selbst ein.
5. **Zugriffs-Logs** prüfen (Variante A der Download-Messung): Log-Stufe mindestens „teilanonymisiert“, Ordner der Rohlogs als `ACCESS_LOG_DIR` eintragen. Gibt es keine Rohlogs: Markus fragen, ob OP3 (Variante B) eingerichtet werden soll.
6. **podcast-admin ergänzen** (siehe `docs/podcast-admin-analyse.md`): vorher `index.php` und `newsletter.php` als `*.bak-<datum>` sichern, dann nur den Menülink „Analytics“ einfügen. Sonst nichts am podcast-admin ändern.

## Hochladen

1. **Sicherung**: Auf dem Server `podcast-admin/analytics/` als `analytics-alt/` umbenennen ist der Rückweg. Vorher die Datenbank sichern (`…/api/cron?job=backup&key=…` oder Sicherungsordner prüfen).
2. **Hochladen nach `podcast-admin/analytics-neu/`**: den Inhalt von `release/analytics/` per WebFTP (oder FTP mit Zugang aus dem macOS-Schlüsselbund). `api/app/env-path.txt` aus der laufenden Fassung mitkopieren.
3. **Umschalten**: `analytics/` → `analytics-alt/`, dann `analytics-neu/` → `analytics/`. So ist nie ein halber Stand live. Migrationen laufen beim ersten API-Aufruf automatisch.
4. **Prüfen**:
   - `https://monteur-podcast.de/podcast-admin/analytics/api/health` zeigt `"ok":true`.
   - podcast-admin › Analytics: Übersicht lädt, Postfach lädt, „Jetzt aktualisieren“ läuft durch.
   - Ansicht bei 360 px ohne seitliches Scrollen.
   - Kurzer Lighthouse-Lauf (mobil), Bericht nach `docs/lighthouse/`.

## Wenn etwas schiefgeht

Sofort zurück: `analytics/` → `analytics-kaputt/`, `analytics-alt/` → `analytics/`. Datenbank aus der Sicherung nur nach Rückfrage zurückspielen (siehe README). Danach Bericht mit Fehlerursache.

## Bericht an Markus

Version (`app/VERSION`), was neu ist, Ergebnis der Prüfung, Link zum Cockpit. Keine Zugangsdaten im Bericht.
