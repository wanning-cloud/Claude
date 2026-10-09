# CLAUDE.md – Podcast-Cockpit

Auftrag: `MASTER-PROMPT.md`. Stand und Entscheidungen: `docs/stufe-0.md`. Variante 2: React-Build + PHP/SQLite-API bei all-inkl unter `/podcast-admin/analytics/`.

## Regeln (verbindlich, Kurzfassung aus dem Master-Prompt)

1. Erst fragen, dann bauen. Nach jeder Stufe Ergebnis zeigen.
2. Nichts geht nach außen ohne Markus' ausdrückliches Ja: keine Kommentarantwort, Moderation, Mail, DNS-Änderung, keine Änderung an monteur-podcast.de. Ein Klick auf „Antwort senden“ ist die Freigabe für genau diese Antwort.
3. Live nur über `.claude/skills/cockpit-veroeffentlichen/SKILL.md` und nur auf Markus' Anweisung.
4. `feed.xml`, `podcast/folgen.js`, `index.html` und die Upload-, Feed- und Newsletter-Logik des podcast-admin bleiben unverändert. Erlaubt: Ordner `podcast-admin/analytics/` und der Menülink „Analytics“ (Vorschlag in `docs/podcast-admin-analyse.md`), vorher sichern.
5. Keine Zugangsdaten in Code, Commits, Logs, Chat, Notizen. Nur `analytics.env` auf dem Server; im Repo nur `apps/api/analytics.env.example`. Passwörter nie selbst eingeben.
6. Keine riskanten Knöpfe: kein Löschen von Kommentaren, kein „Alles zurücksetzen“.
7. Zahlen nie erfinden. Fehlt ein Wert: „–“ mit Grund, nie 0. Testdaten nur in Tests und `bin/seed-test.php`, als TESTDATEN markiert.
8. APIs in der offiziellen Doku prüfen. Was nicht geprüft werden konnte, steht in `docs/offene-pruefungen.md`.
9. Oberfläche mit dem Skill `impeccable` gestalten, Designsystem in `DESIGN.md` (Plakat, Werkzeug-Fassung).
10. Oberfläche Deutsch, du-Form, kurze Sätze, Knöpfe nennen, was passiert. Code, Kommentare, Commits Englisch.
11. README und diese Datei bei jeder Stufe aktuell halten.
12. Nie „Kurzzeitvermietung“; KWATERA24, MMW Holding, Einheitenzahlen und Arbeitgeber nicht nennen.

## Befehle

```sh
npm run lint && npm test && npm run test:e2e && npm run secrets && npm run build
npm run release      # baut release/analytics/ für den Upload
```

## Wo was liegt

- Zählregeln: `apps/api/src/Counting/IabCounter.php`, Reichweite: `apps/api/src/Repo/Metrics.php`
- Connectoren: `apps/api/src/Connectors/`, Zeitplan: `apps/api/src/Sync/Schedule.php`
- Import + echte Exportformate: `apps/api/src/Import/` (Schema-Quelle: `packages/shared/src/import.ts`, danach `npm run schema`)
- Anmeldung: `apps/api/src/Auth/AdminSession.php` (Sitzung `mp_admin`, Schlüssel `ok`, nur lesen)
- Oberfläche: `apps/web/src/pages/`, Tokens: `apps/web/src/styles.css`
- Social Media (Instagram, Facebook-Seite, Shorts): `apps/api/src/Repo/Social.php`, `apps/api/src/Meta/`, Connectoren `Instagram*`, `Facebook*`, `MetaComments*`, Seite `apps/web/src/pages/Social.tsx`. Regel: Social-Werte nie in `metric_*`-Tabellen oder in die Reichweite; Shorts nie einer Folge zuordnen.
