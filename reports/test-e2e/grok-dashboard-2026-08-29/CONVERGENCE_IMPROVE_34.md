# Convergence improve-3 + improve-4

P0+P1 = 0, même set. Parent a lu les PNG.

| Mesure | improve-3 | improve-4 |
|---|---|---|
| Studio rayons | 14 | 14 |
| Toutes les catégories | **55** | **55** |
| KPI dashboard | 55 | 55 |
| Interne dans le rail | oui (3) | oui (3) |
| Drapeau affiché | `/images/language/english.png` | idem |
| E2E_PLAYWRIGHT | non | non |

58→55 : « toutes » exclut interne ; le rayon Technique reste.

P2 : GET `/storage/1/english.png` encore 1× au login (page login pas BackendNavbar). Debugbar. 64 junk SQL. Borne hors `list()`.
