---
name: podcast-zahlen-holen
description: Holt Zahlen und Kommentare aus Spotify for Creators (ab Stufe 2 auch Apple Podcasts Connect und Amazon Music for Podcasters) und lädt sie ins Podcast-Cockpit. Nutzen bei „Hol die Podcast-Zahlen“ und in der Wochen-Routine (Montag 08:20).
---

# Podcast-Zahlen holen

Ziel: Das Podcast-Cockpit (https://monteur-podcast.de/podcast-admin/analytics/) hat danach die Werte aller Portale bis gestern. Markus muss nichts tun.

## Voraussetzungen

- Läuft auf Markus' Mac in der Claude-App mit Chrome und Claude-Erweiterung. Neuer Tab, keine offenen Tabs von Markus verändern.
- Markus ist angemeldet: podcast-admin, Spotify for Creators, Apple Podcasts Connect, Amazon Music for Podcasters.
- Nie Passwörter oder Codes eingeben. Fehlt eine Anmeldung: diese Quelle überspringen und im Bericht nennen („Bei Spotify abgemeldet, bitte in Chrome neu anmelden“).
- Dateien hochladen: nach dem Skill `browser-bild-upload` vorgehen. Geht der Dateidialog nicht, die CSV aus `~/Downloads` lesen und den Inhalt in das Feld „Oder Inhalt einfügen“ kopieren.

## Ablauf

1. **Cockpit › Automatik** öffnen. „Jetzt aktualisieren“ klicken (Server-Quellen) und warten, bis alle Zeilen „fertig“ oder „Fehler“ zeigen. In der Tabelle „Claude-Routine“ den letzten erfolgreichen Spotify-Lauf ablesen.

2. **Spotify for Creators › Analytics › Übersicht** (`https://creators.spotify.com/pod/dashboard/analytics`):
   - Zeitraum: „Letzte 30 Tage“ (mindestens seit dem letzten Lauf, sonst länger wählen).
   - Bei „Leistung“ auf „Exportieren“ klicken. Datei: `DerMonteur-Podcast_Performance_<von>--<bis>.csv` mit den Spalten `Datum, Wiedergaben, Hörer*innen`.
   - Bei „Trends“ (Folgenvergleich) auf „Exportieren“ klicken. Datei: `DerMonteur-Podcast_TrendsChart_Plays_First7Days.csv`.
   - Beide Dateien im Cockpit unter **Automatik › Import (Rückfalllösung)** nacheinander hochladen. Das Cockpit erkennt das Format („Spotify: Leistung pro Tag“ bzw. „Spotify: Wiedergaben in den ersten 7 Tagen je Folge“). „Prüfen“, Vorschau lesen, „Import speichern“. Meldet die Vorschau „schon importiert“, ist nichts zu tun.
   - Hinweis: Spotify zählt eine Wiedergabe seit 11.6.2026 ab 30 Sekunden. „Streams“ (ab 60 Sekunden) nur mitnehmen, wenn die CSV die Spalte hat.

3. **Spotify for Creators › Kommentare** (Menü links): Neue Kommentare seit dem letzten Lauf als JSON importieren (Import-Feld „Oder Inhalt einfügen“):

   ```json
   {
     "source": "spotify",
     "kind": "comments",
     "captured_at": "2026-10-12T08:31:00+02:00",
     "comments": [
       { "external_ref": "<eindeutige ID oder Autor+Datum>", "episode_title": "<Titel wie bei Spotify>", "author": "<Name>", "text": "<Kommentar wortgleich>", "posted_at": "2026-10-11T19:04:00+02:00" }
     ]
   }
   ```

   Danach **Cockpit › Automatik › Antwort-Warteschlange** öffnen. Nur dort stehende Antworten (von Markus freigegeben) in Spotify for Creators beim richtigen Kommentar posten, wortgleich. Danach jede gesendete Antwort melden:

   ```json
   { "source": "spotify", "kind": "reply_sent", "captured_at": "<jetzt>", "replies": [{ "reply_id": 12, "sent_at": "<Zeitpunkt>" }] }
   ```

   Die `reply_id` steht nicht in der Oberfläche; sie kommt aus `https://monteur-podcast.de/podcast-admin/analytics/api/routine/queue` (im selben Chrome-Tab öffnen, JSON lesen). Nie selbst Antworten formulieren oder senden.

4. **Ab Stufe 2: Apple Podcasts Connect** (`https://podcastsconnect.apple.com/analytics/show/der-monteur-podcast/6819469570/overview`): Es gibt keinen Export. Je Folge Wiedergaben, Hörer:innen, Treue Hörer:innen und Follower für den Zeitraum ablesen (Apple rechnet in UTC, bis zu 72 Stunden Verzug) und importieren:

   ```json
   { "source": "apple", "kind": "metrics", "captured_at": "<jetzt>", "period": { "from": "2026-10-05", "to": "2026-10-11" },
     "show": { "followers": 0 },
     "episodes": [{ "guid": "monteur-podcast-folge-07", "title": "<Titel>", "metrics": { "plays": 0, "listeners": 0, "engaged": 0 } }] }
   ```

   Zeigt Apple „nicht genügend Daten“ oder „–“: nichts importieren, im Bericht nennen. Nie 0 eintragen, wenn Apple keinen Wert zeigt.

5. **Ab Stufe 2: Amazon Music for Podcasters** (`https://podcasters.amazon.com/podcasts`): Zeitraum „Letzte 7 Tage“, Download-Symbol über der Podcast-Tabelle. Datei `Überblick_<TT.MM.JJJJ>-<TT.MM.JJJJ>.csv` im Cockpit importieren. Das Cockpit liest den Zeitraum aus dem Dateinamen. Wird der Inhalt eingefügt statt hochgeladen, den Zeitraum von Hand angeben.

6. **Deezer**: nur wenn die Statistik im Browser erreichbar ist (zurzeit nicht).

7. **Plausibilität**: Gesamtzähler dürfen nicht sinken. Abweichungen über 50 % zur Vorwoche im Bericht nennen.

8. **Bericht**: eine Zeile je Quelle (geholt / übersprungen mit Grund), die drei wichtigsten Zahlen der Woche aus der Cockpit-Übersicht, Zahl offener Kommentare. In der Sitzung ausgeben und als Notiz im Vault unter `50 Analysen/Podcast-Wochenbericht JJJJ-KWNN.md` ablegen (ab Stufe 2).

## Was nicht passieren darf

- Keine Einstellungen in Spotify, Apple oder Amazon ändern, nichts löschen, nichts veröffentlichen außer freigegebenen Antworten aus der Warteschlange.
- Keine Mails oder Nachrichten an andere.
- Keine Zugangsdaten in Notizen, Berichten oder Chat.
- Keine Zahlen erfinden oder schätzen. Fehlt ein Wert, fehlt er.
