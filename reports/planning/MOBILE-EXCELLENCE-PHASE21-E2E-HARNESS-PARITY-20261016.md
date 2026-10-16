# Planification QA — Phase 21 — Harnais E2E mobile

## Source

Le dernier manifeste Playwright (`reports/antigravity/mobile-loyalty-latest.json`) confirme
que le testMatch mobile inclut les vagues design historiques et la vague kiosk E, tandis que
la configuration démarre le serveur mobile sur 8087. Le run global récent a montré des URLs
8081 refusées, des attentes D obsolètes et un conflit de tracing.

## Revue du plan

La première revue fallback a demandé `REWORK` : réduire la discovery aux tests mobiles,
fixer le contrat 8087, aligner Wave D sur les données courantes, retirer le tracing manuel,
produire `reports/antigravity/latest.md`, et documenter Wave E comme exécution kiosk séparée.
Ces corrections sont intégrées ; la seconde revue fallback a relevé quatre derniers points,
puis la dernière revue a confirmé `PASS` après leur correction.

`PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)`

`FALLBACK_REASON: codex CLI rejected gpt-5.5-pro on the signed-in ChatGPT account after two attempts.`

## Action recommandée

1. Paramétrer les vagues mobile avec `MOBILE_URL` et le défaut 8087.
2. Retirer Wave E du projet mobile-only et garder sa validation kiosk séparée.
3. Remplacer dans Wave D les valeurs fictives par des assertions de contrat (profil non vide,
   téléphone valide, historique réellement rendu, tiers configurés).
4. Laisser la configuration Playwright gérer les traces et supprimer les démarrages manuels
   concurrents dans Fluidity.
5. Relancer d’abord les fichiers ciblés, puis le projet mobile-only allowlisté, avec le reporter Markdown.

Wave E doit être relancée séparément via `playwright.config.js` et le runtime Laravel/kiosk ; elle ne
doit pas être mélangée à ce verdict mobile.

## Critère de sortie

La suite mobile-only ne doit plus échouer pour connexion au mauvais port, donnée de profil
historique ou tracing déjà démarré. Les éventuels échecs métier restants seront listés
séparément et routés vers une phase produit dédiée.
