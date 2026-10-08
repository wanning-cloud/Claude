# Lighthouse (mobil)

Lauf vom 08.10.2026 gegen `vite preview` mit TESTDATEN (`apps/api/bin/seed-test.php`), Lighthouse 12, Profil „mobil“ (simuliertes langsames 4G, 4× CPU-Drosselung). Bericht: `uebersicht-mobil.report.html`.

| Kategorie | Ergebnis | Ziel |
| --- | --- | --- |
| Performance | 95 | ≥ 90 ✓ |
| Barrierefreiheit | 100 | ≥ 95 ✓ |
| Best Practices | 100 | ≥ 95 ✓ |
| SEO | 60 | gewollt: `noindex, nofollow` für den internen Bereich |

| Messwert | Ergebnis | Ziel |
| --- | --- | --- |
| LCP | 2,5 s | < 2,0 s ✗ |
| CLS | 0 | < 0,05 ✓ |
| TBT (Ersatz für INP im Labor) | 10 ms | INP < 200 ms ✓ |

**LCP offen:** `vite preview` liefert ohne Kompression aus; auf dem Server komprimiert Apache per `.htaccess` (`mod_deflate`). Nach der ersten Veröffentlichung gegen monteur-podcast.de neu messen. Bleibt die LCP über 2,0 s, sind die nächsten Hebel: Übersichts-Daten mit der Sitzung in einer Anfrage laden und den Router-Code aus dem ersten Paket lösen.

Initiales JavaScript: rund 120 KB gzip (Budget 150 KB), CSS 6,5 KB gzip (Budget 30 KB).
