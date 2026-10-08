# podcast-admin: Analyse (nur gelesen)

Stand 08.10.2026. Quelle: Datei-Export von monteur-podcast.de, den Markus in die Sitzung hochgeladen hat. Nichts davon liegt im Repo. Zugangsdaten, `data/auth.json`, `config.php` und die Abonnentenliste wurden nicht übernommen.

## Aufbau

```
podcast-admin/
  index.php            Upload-Helfer (Login, Folgen anlegen, Feed/Homepage neu bauen)
  newsletter.php       Newsletter-Versand (gleiche Sitzung)
  cron.php             Zeitsteuerung, ?key=<cron_key>
  lib.php, config.php, seed.php   per .htaccess gesperrt
  folgen-renderer.js   per .htaccess gesperrt (.js)
  data/                komplett gesperrt (auth.json, episodes.json, settings.json)
  backup/              gesperrt
```

`podcast-admin/.htaccess` sperrt alle `.json .js .md .txt .bak` sowie `lib.php`, `config.php` und `seed.php`. Für das Cockpit unter `podcast-admin/analytics/` heißt das: Die React-App braucht eine eigene `.htaccess`, die `.js` und `.css` im Ordner `analytics/` wieder erlaubt (siehe `apps/web/public/.htaccess`).

## Anmeldung

- `session_name('mp_admin')`, Cookie-Pfad `/podcast-admin/` (aus `dirname($_SERVER['SCRIPT_NAME'])`), `httponly`, `SameSite=Lax`, `secure` bei HTTPS.
- Nach dem Login: `$_SESSION['ok'] = true` (Passwort-Hash in `data/auth.json`, `password_verify`).
- Abmelden leert die Sitzung.
- Kein zweiter Faktor. **Vorschlag (nicht gebaut):** TOTP als zweiter Schritt nach dem Passwort, Geheimnis in `data/auth.json`, Wiederherstellungscodes einmalig anzeigen. Umsetzung nur nach Freigabe.

Das Cockpit liest diese Sitzung nur (`session_start(['read_and_close' => true])`), schreibt nie hinein und braucht deshalb kein zweites Login. Einstellungen: `ADMIN_SESSION_NAME=mp_admin`, `ADMIN_SESSION_KEY=ok` (beides Standard).

## Menü

Es gibt kein Menü-Array. Die Kopfzeile steht direkt in `index.php` (Zeile 227) und `newsletter.php` (Zeile 232–233).

**Änderung (von Markus am 08.10.2026 freigegeben; wird beim ersten Veröffentlichen mit der Routine „Cockpit veröffentlichen“ eingebaut, vorher Sicherung):**

`index.php`, Zeile 227, vor dem Link „Newsletter“:

```php
<a href="analytics/" style="color:#f2f6fa;margin-left:auto;margin-right:16px">Analytics</a><a href="newsletter.php" style="color:#f2f6fa;margin-right:16px">Newsletter</a>
```

(Beim bestehenden Newsletter-Link entfällt dann `margin-left:auto`.)

`newsletter.php`, Zeile 233, nach „Zum Upload-Helfer“:

```php
<a href="analytics/">Analytics</a>
```

## Feed und Website

- Feed: `https://monteur-podcast.de/feed.xml`, 7 Folgen, `guid` wie `monteur-podcast-folge-07`, `itunes:episode` gesetzt, `itunes:duration` in Sekunden, MP3s unter `/media/audio/`. Der Parser des Cockpits liest den echten Feed korrekt (geprüft).
- Vorschaubilder: `podcast/folgen.js` (`window.MP_FOLGEN`, Feld `poster`, Dateiname mit Version, z. B. `media/img/folgen/folge-01-v20261007015507.jpg`). Das Cockpit liest sie von dort.
- Der Website-Player spielt MP4-Videos (`media/video/folge-NN.mp4`). Die Feed-Downloads zählen nur die MP3s aus dem Feed. Der Player bekommt in Stufe 2 seinen eigenen Beacon.
- `robots.txt` enthält bereits `Disallow: /podcast-admin/`.
- Zeitzone, PHP-Version: Der Upload-Helfer verlangt PHP 8.0 oder neuer. Das Cockpit braucht PHP 8.2 oder neuer (im KAS prüfen).

## Noch offen

- Erledigt: Logs liegen unter `/logs/access_log_monteur-podcast_de_JJJJ-MM-TT.gz` (Log-Stufe „vollständig“, 190 Tage), PHP ist 8.5 (Angaben von Markus, 08.10.2026).
