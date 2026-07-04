# GATES PRÉPARÉS — chaque porte réduite à UN MOT d'approbation — 2026-07-04

> **RÉPONSES OWNER (async — coche/écris ici OU dis-le en session, 2 escalades §10 posées le 2026-07-04, AFK ×2)** :
> - G1 M6-002 (Z split, FROZEN) : ☐ OUI applique sous LOCK ☐ NON plus tard
> - G3 ≥30€ : ☐ PARTOUT (patch A) ☐ WEB-ONLY (patch B) ☐ plus tard
> - G2 autoskip : ☐ 12 s j'applique ☐ via cowork ☐ garder 30 s

> Fin de campagne ULTRA : il ne reste AUCUN défaut in-code non-frozen connu. Les 4 portes
> ci-dessous sont préparées clé-en-main (patch exact + plan de test) — dis le mot, j'applique.

## G1 — « APPLIQUE M6-002 » : ventilation split du Z signé (FROZEN `ZReportService`)
- **Quoi** : `applyOrderToTotals` (l.661-668) verse le total INTÉGRAL d'un split dans le bucket
  du tender dominant → X-carte/X-espèces FAUX dans le Z signé pour les paiements mixtes.
  P1 NF525 du catalogue pre-cloud (M6-002). **Aucun LOCK contresigné n'existe encore** (le
  triage 2026-06-04 recommandait le fix mais la porte n'a jamais été ouverte formellement).
- **Patch prêt** (à appliquer sous LOCK, ~8 lignes) :
  ```php
  // Dans applyOrderToTotals, avant le bucket unique :
  $tranches = $order->relationLoaded('payments') ? $order->payments : $order->payments()->get();
  if ($tranches->isNotEmpty()) {
      foreach ($tranches as $t) {
          $byMethod[(string) $t->mode] = ($byMethod[(string) $t->mode] ?? 0) + (float) $t->amount * $sign;
      }
      return; // total_ttc inchangé ; seule la ventilation devient par-tranche.
  }
  // fallback existant : bucket pos_payment_method (mono-tender)
  ```
- **Plan de test** : PHPUnit fiscal (split 15 cash + 10 card → Z `total_by_method` = {CASH:15, CARD:10}) +
  miroir remboursement + `fiscal:verify-chain --all` AVANT/APRÈS + frozen-diff justifié par le LOCK.

## G2 — « RÉDUIS L'AUTOSKIP » : upsell borne 30 s → 12 s (FROZEN `KioskUpsellComponent.vue` + territoire cowork)
- **Quoi** : `AUTO_SKIP_SECONDS = 30` (l.125) — 30 s d'attente forcée sur l'écran « ET POUR
  TERMINER ? » si le client n'interagit pas. Constaté pendant les e2e : trop long pour une borne.
- **Patch prêt** : 1 ligne (`const AUTO_SKIP_SECONDS = 12;`) + rebuild bundle kiosk.
  Double gate : frozen §7 **et** `.vue` = refonte visuelle cowork en cours → à appliquer par
  le cowork OU après leur merge.

## G3 — « ≥30€ PARTOUT » ou « ≥30€ WEB-ONLY » : livraison offerte à la caisse
- **Quoi** : le waiver ≥30€ existe web/kiosk (`FrontendOrderService:538`) mais PAS au POS →
  même panier livraison gratuit sur le site, facturé 4 €+ au téléphone/comptoir.
- **Patch A (« PARTOUT »)** : répliquer le waiver dans `OrderService::posOrderStore` avant la
  persistance du total (miroir exact des lignes 538-542, non-frozen) + test PosWalkInFreeAbove.
- **Patch B (« WEB-ONLY »)** : commentaire d'exclusion explicite dans `posOrderStore` +
  note runbook caisse (« la promo ≥30€ est un incentive en-ligne, la caisse facture la course »).

## G4 — Physique (à ton clavier, tout est prêt côté repo)
- **Déploiement** : `tools/deploy-vps.sh` DURCI ce jour — jeu COMPLET de bundles vérifié via
  mix-manifest (leçon écran-blanc), `migrate --force`, `queue:restart` + **détection du worker
  `--queue=high,default` manquant** (l'omission historique qui tue le temps réel), attestation
  NF525 post-deploy, rollback auto. Une commande : `bash tools/deploy-vps.sh`.
- **TPE/pont ESC/POS** : runbooks existants (`MEGA_PLAN_COWORK_INSTALL_3_MACHINES`).
- **Carte Uber** : plus bloquant pour les noms identiques — la résolution est désormais
  accent/casse-insensible (« Supreme »→« Suprême ») ; `uber_menu_map` ne sert plus qu'aux
  titres DÉCORÉS (« Menu XL Suprême + Boisson ») à mapper au go-live.

## Décision owner enregistrée (rien à faire)
- **Fenêtre Z detect-only** : décision owner explicite 2026-05-29 (« P0 #1 detect-only ») —
  le détecteur schedulé page ; réouvrir ce choix serait contredire une décision stable (§12).
