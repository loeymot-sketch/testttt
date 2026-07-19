# Ultra-audit web — convergence · 2026-07-19

Site `https://site-lecayenne.vercel.app` → VPS. Commit web `f4d6aa9` (?v=g). Boucle : audit adversaire
(4 agents + passe visuelle orchestrateur) → 26 correctifs (moi + 3 implémenteurs //) → deploy → re-audit live.

## Méthode
- **Round 1 audit** : 4 agents adversaires (funnel · catalogue/wizards · compte/fidélité · statique/a11y) +
  passe visuelle 13 surfaces. Anti-hallucination (file:line + repro), respect du déjà-corrigé.
- **Round 1 fix** : partition par fichiers disjoints — moi (funnel/index/flows), impl.A (legal), impl.B
  (screens/screens-v3), impl.C (orders/account/upsell). Tous parse-validés via @babel/parser + @babel/standalone.
- **Round 2 re-audit LIVE** : render OK, money-path e2e, spot-checks des correctifs sur le déployé.
- **Round 2 régression** : 1 agent chasseur de régressions sur le diff des 26 (en cours au moment de l'écriture).

## Correctifs (26, tous non-frozen, déployés)
**P1** — #1 expected_total hoisté→undefined = 422 sur TOUT coupon (funnel, renommé paidTotal) · #2 recherche
accent-insensible (screens, « mega »→Méga) · #3 pts cumulés fabriqués (orders, retiré) · #4 CGV Art.6
carte-en-ligne/Apple/Google Pay (cgv.html → comptoir) · #5 clé idempotence scopée panier (funnel, plus de 409).
**P2** — #6 QR factice → encode le vrai code (funnel, window.qrcode) · #7 note allergie fuit commande suivante
(index) · #8 upsell double-clic ×2 (upsell) · #9 86 propagé au panier (flows) · #10 faux « 0 pts » expiration
(screens+orders) · #11 renvoi OTP avalé (account) · #12 « OUVERT » H24 (screens, isOpenNow) · #13 « on
rembourse »/prep SLA (screens-v3) · #14 bandeau cookies fantôme · #15 hébergeur contradiction · #16 « Commander
à nouveau » stub (orders→« Voir le menu ») · #17 favoris mensonger retiré (screens).
**P3** — WebAbout MORT + fausses citations presse (Voix du Nord/TF1/1200-inscrits) supprimé · React prod build
(SRI) · prix Méga dérivé · slug modal · a2/a8 trophées · code mort forgot/socialNotice · double-# · promo
retirable · copie 1€=1pt via config · OTP 2xx=token · date/statut historique · fallback earn tracking.
**#25 stepper** : vérifié NON-bug (enum backend 9 valeurs entièrement couvert).

## Validé LIVE sur le déployé (round 2)
- **Render OK** : `.lc-app` monté, 38 items, React prod, 0 écran blanc malgré suppressions+prod-build.
- **Money-path e2e INTACT** : commande Coca → panier 1,90 = checkout 1,90 = paiement 1,90 = confirmation
  **#190726172 1,90 €** = backend order **#172 source=5 total=1.90**. Le renommage expected_total n'a rien cassé.
- **QR RÉEL (#6)** : `.lcf-ticket-qr-real svg` présent (mock absent), aria « QR de retrait — commande 190726172 »
  → scannable en caisse (avant : motif décoratif encodant rien).
- **Recherche (#2)** : « mega »→[Méga], « supreme »→[Suprême].
- **Fausses presse (#5)** : WebAbout/PressStrip/TeamStrip = undefined (supprimés, 0 référence).
- **Badge OUVERT (#12)** : affiche « Horaires 18h – 00h » hors horaires (plus de faux « OUVERT »).
- Balayage malhonnêteté = 0 (les 2 hits « Apple Pay »/« Voix du Nord » = commentaires documentant la suppression).

## Reste OWNER-gated (non-autonome — je ne fabrique pas de données)
- **Données d'entité légale** : mentions (forme juridique, capital, RCS, code APE, directeur publication),
  cgv (médiateur ×3), allergens (halal), privacy (§5 transferts hors-UE Vercel-US = SCC/DPF à déclarer, +
  prestataires e-mails/SMS). ~15 `[À COMPLÉTER]` restants (les dérivables ont été posés : responsable=E.DELICE
  SAS, hébergeur=Vercel, DPO=aucun désigné).
- **Déco app mobile** : label « Bientôt sur iOS/Android » + badges footer (toast « démo V1 ») — garder/retirer ?
- **Flags Veggie bols** : quels bols marquer is_vegetarian ?

## Round 2 régression — VERDICT : CONVERGENCE (rien de cassé)
1 agent chasseur de régressions a stress-testé les 26 correctifs point par point sur le diff `f4d6aa9` :
expected_total→paidTotal lie bien le grand total OUTER (pas d'undefined) · SRI React vérifiés par
download+openssl (exacts) · 0 référence pendante aux composants supprimés (WebAbout/PressStrip/TeamStrip/
fav/socialNotice/forgot/totalPts) · 0 fausse promesse légale restante · les 7 .jsx = équilibre accolades
parfait · le déployé contient bien `paidTotal`. **Verdict : rien de cassé = signal de convergence.**

## Convergence : 2 cycles propres consécutifs (round 2 validation live + round 2 régression) → CONVERGÉ.

## Ordres test laissés (staging, PENDING, non-fiscalisés) : #171, #172 — annulables caisse.
