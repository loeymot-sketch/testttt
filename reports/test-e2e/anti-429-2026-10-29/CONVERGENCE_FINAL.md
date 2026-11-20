# Audit E2E anti-429 + photos pain/galette — 2026-10-29

Périmètre : borne (pain/galette, panier, bandeau, récap), écran cuisine (liste + bump), site (étape pain/galette, panier, récap, validation de commande), plafonds serveur.
Instruments prouvés avant produit : un contrôle « sans rejeu » fait apparaître le toast (B06, C03) ; chaque test ajouté a été vu ROUGE sous mutation.

## Convergence
Rounds 12 et 13 : P0+P1 = 0, ensembles de résultats identiques (B 11 / C 8 / D 21 contrôles verts) ; le superviseur adversaire clôt R11-01/R11-02 sur le round 12 (« P0+P1 ouverts : 0 »). Les rounds 10-11 étaient déjà identiques ; la dernière correction (message d'envoi dans le bouton fixe du site) a été relue par le superviseur sur le round 12.

## Constats du superviseur adversaire et sort
| id | sév. | sort |
|---|---|---|
| ADV-01 rejeux partagés par le dédoublonnage (toast à tort dès 5 GET identiques) | P1 | corrigé (compteur sur copie du cfg) + test rouge/vert |
| ADV-02 protections anti-piratage sans preuve verte | P1 | prouvé : PHPUnit RateLimitTest + KioskThrottleKeysTest (18), sondes sur serveur à valeurs par défaut (connexion 10 puis 429, code SMS 5 puis 429), limiteurs inchangés dans le diff. Sonde mot-de-passe-oublié non jouée (route 405 : mauvais chemin) — couverte par PHPUnit seulement |
| ADV-03 panier/récap du site : « Galette » sous photo de sandwich | P1 | corrigé (image de la ligne = photo du choix) |
| ADV-04 borne : photo galette non vérifiée (panier, bandeau, récap) | P1 | corrigé + capturé (B02, B03, récap) |
| ADV-05 site : validation pilotée par API directe | P1 | pilotée par le vrai entonnoir (D06–D10), attente 12 s / 3 s |
| ADV-06 corbeille chevauchant « + » (borne) | P1 | corrigé, chevauchement 0 px² mesuré |
| R8-01 bandeau panier borne | P1 | corrigé |
| R8-02 récap de l'assistant borne | P1 | corrigé |
| R11-01 message d'envoi lent invisible (hors du bouton fixe) | P1 | corrigé : libellé dans le bouton fixe, PNG relu par le superviseur |
| R11-02 Retour bloqué pendant une redirection 3-DS | P2 | corrigé (`disabled={submitting && !threeDs}`) |
| ADV-07 verrou long fige l'écran 2 min | P2 | corrigé (Retry-After > 60 s non rejoué) |
| ADV-08 motif « unlock » / query | P2 | chemin seul ; `unlock` conservé exprès (PIN roue) |
| ADV-11 « Type de Pain » affiché à la place du choix | P2 | corrigé (nom lu au catalogue) |
| R8-03/04 attente longue sans message, Retour actif | P2 | corrigé (message après 10 s, Retour bloqué) |

## Défaut trouvé par la mesure, pas par la lecture
`config/cors.php` n'exposait pas `Retry-After` : le site et l'application des stores ne le lisaient jamais ; les bancs précédents le leur donnaient. Corrigé + test (rouge sans le correctif).
Vraie saturation locale : 429 avec `Retry-After: 47` — le rejeu des écrans d'administration (4 × 8 s) abandonnait à 32 s ; porté à 30 s par essai.

## Divulgué, non corrigé
- R12-01 : le bouton portefeuille (Apple/Google Pay) garde « Paiement… » sans message d'envoi lent (P2).
- R12-02 : contraste du libellé sur le bouton d'envoi désactivé (~2:1, opacité 50 %) (P2).
- Vignette « RÉCAP » de la barre d'étapes du wizard borne : sandwich (KioskWizardComponent, zone gelée).
- ADV-09 : plancher 600/min sur toute l'écriture d'administration (arbitrage propriétaire : jamais bloquer ; réduit la défense anti-force-brute du CRUD).
- ADV-13 : 401 au démarrage de la borne (avant connexion) et erreurs console 429 injectées = bruit attendu, à inscrire à la liste blanche du protocole.
- ADV-14 : double clic sur le bump cuisine pendant une attente 429 — raisonné, non reproduit.
- `GET /api/admin/pos-order` épuise 128 Mo sur la base locale (5 000 commandes) : sans rapport avec les plafonds, à surveiller.
- app/www (application des stores) périmé : `npm run check:www` puis `build:www` avant tout build.
- Aucune garantie « 1000 % » : un blocage serveur de plus de 60 s remonte à l'écran ; php-fpm `max_children=5` en production reste un goulot hors code.

## Régression finale
- vitest : 184 fichiers / 1412 tests verts (kiosk, sentinelles, rejeu 429, panier galette, assistant pain).
- PHPUnit : famille plafonds 68 tests verts ; CorsExposeRetryAfterTest ; RateLimitTest + KioskThrottleKeysTest 18 verts.
- Site : attente-429 (3 contrôles), option-caisse-seule (7), upsell-choix-obligatoire, wizard-cartes-immobiles (27), panier-modifier-abus, ux-continuity, compteurs-articles : verts.
- Zone gelée : diff vide. Chaîne NF525 : OK sur les 7 branches.
