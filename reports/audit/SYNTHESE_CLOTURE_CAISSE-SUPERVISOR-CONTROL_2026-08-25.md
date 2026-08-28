# Synthèse de clôture — cycle `CAISSE-SUPERVISOR-CONTROL-20260823`

- **Tâche** : T-6.1.2 du GOAL `CONSOLIDATION_V1_PRODUCTION_20260825` (vague W1)
- **Date** : 2026-08-25 · **HEAD** : `43b120c7d` · branche `pos/category-first-caisse-2026-06-23`
- **Objet** : remplacer 5 rapports dispersés par **une** lecture. Les rapports d'origine restent en place.

---

## 1. Le point qui conditionne la clôture

**Le canal Codex/GPT n'a jamais produit une seule ligne.**
`missions/CAISSE-SUPERVISOR-CONTROL-20260823/output_codex.json` contient une erreur **HTTP 400** :
« The 'gpt-5.5-pro' model is not supported when using Codex with a ChatGPT account ». Le binaire natif
est par ailleurs absent (`ENOENT`).

Conséquence assumée : **il n'existe aucun verdict d'audit GPT, et aucun n'a été fabriqué.** Le
« challenge à deux intelligences » a tourné à une seule. C'est la raison d'être du gate **G1** :
clore le cycle en le disant, plutôt que de laisser croire à une contre-expertise qui n'a pas eu lieu.

---

## 2. Ce qui a été réparé, et qui tient

| Domaine | Défaut trouvé | État |
|---|---|---|
| **Santé caisse** | Succursale non sélectionnée → HTTP 422 opaque ; panne socket dure rétrogradée par un voisin « inconnu » ; bannières hors-ligne et quarantaine mutuellement exclusives | Corrigé — 200 + `branch_required`, sévérité ordonnée, bannières cumulables |
| **Accessibilité borne** | Touche maintenue empilait les événements ; démarrage clavier absent | Corrigé — `Entrée`/`Espace` + garde `evenement.repeat` |
| **Préréglages de dates** | 12 composants sans le mécanisme de créneau ; étiquettes anglaises (ADR-007) ; doublons de démo | Corrigé sur **les 12**, plus une sentinelle qui **se découvre elle-même** |
| **Semences (seeders)** | `db:seed` non rejouable (violation UNIQUE) ; `GuardDoesNotMatch` sur les rôles | Corrigé — `upsert` + filtrage de garde ; deux exécutions propres consécutives |
| **Idempotence** | 3 routes mutantes sans clé | Ajoutées à `config/idempotency.php` |
| **Sécurité composer** | 56 avis | **7**, dont **0 critique** — non-régression prouvée en restaurant l'ancien `composer.lock` |
| **Harnais E2E** | 11 specs pourries par la dérive de fixtures | **9 restaurées** ; résolveur partagé + gardes de nettoyage renforcées |

---

## 3. Ce que j'ai cru trouver, et qui était faux

Cette section vaut la précédente. Trois de mes propres signalements n'ont pas survécu à la vérification :

1. **« Les touches F1–F12 sont mortes »** — faux. `keyboard.press('F2')` est **inerte** en Playwright
   headless. Le produit marchait ; c'est l'instrument qui ne mesurait rien.
2. **« La recherche ne tolère ni la casse ni les correspondances partielles »** — faux. Elle est
   insensible aux accents, à la casse, et accepte les sous-chaînes. Mes cas de test (`poulet`,
   `creme`) **n'existent tout simplement pas au menu**.
3. **« Le serveur ne partage pas la base vérifiée »** — faux positif de ma propre garde : le jeton
   avait été légitimement révoqué (#10711 supprimé, #10713 créé).

Un audit qui ne publie que ses trouvailles confirmées se ment sur son taux d'erreur.

---

## 4. Ce qui reste ouvert, et pourquoi

| Sujet | État | Pourquoi ce n'est pas fermé |
|---|---|---|
| **Vague D** (borne → KDS) | Rouge, assumée | L'API **retourne** la commande (`{id:6920, status:4}`). Le backend est correct ; le rendu de lane ne l'est pas. Laissée rouge plutôt que maquillée. |
| **Vague F** (caisse → KDS) | Partielle | Même amas que D. |
| **8 specs, 1 seul préfixe** | Ouvert | `AUDIT-KIOSK-WAVE-E` partagé → elles se nettoient mutuellement en parallèle. |
| **Laravel 9 EOL** | Ouvert | Verrouille les 7 derniers avis. Chantier à budget propre (**G5**). |
| **Ergonomie caisse** | Ouvert | Grille de vente sous la ligne de flottaison ; décalage F1 ; portée de recherche. **Décisions propriétaire G3/G4.** |
| **3 escalades runtime** | Ouvert | `foodking:ensure-admin` sans garde production · `HealthzController` qui n'ouvre aucune connexion hors `pusher` · `slaAlerts()` sans borne basse. |

---

## 5. Un fait de terrain qui a validé le travail

Pendant le cycle, le worker de file était **absent** — 436 travaux en attente. La pastille de santé,
tout juste corrigée, l'a signalé honnêtement (« Traitement en retard ») et est repassée au vert après
redémarrage. Le correctif s'est fait valider par une panne réelle, pas par un test.

---

## 6. Preuves de sortie de cycle

- PHPUnit **4862 passés / 36 sautés / code de sortie 0**, zéro `⨯`
- Vitest **440 fichiers, 3609 passés, 3 sautés**
- `npm run pos:lint:status` OK
- **Diff zone gelée : 0 ligne**
- Chaîne NF525 : `audit_logs` = 8 095 entrées, dernier condensat `7caed138…`, `z_reports` = 33 — **ajout seul**
- Rien commité, rien poussé — l'arbre du propriétaire est intact (75 modifiés, 47 non suivis)

---

## 7. Décision demandée — **G1**

Clore `CAISSE-SUPERVISOR-CONTROL-20260823` **sans verdict GPT**, en inscrivant au journal que le canal
n'a jamais répondu.

- **A)** Clore ainsi. *(recommandé — la suite est déjà cadrée par le GOAL `CONSOLIDATION_V1_PRODUCTION_20260825`)*
- **B)** Garder le cycle ouvert jusqu'à ce qu'un second modèle puisse réellement contre-auditer.
- **C)** Clore, et rouvrir un cycle dédié uniquement à la contre-expertise.

**Rapports d'origine conservés** : `reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md` ·
`CAISSE_ERGONOMIE_CAISSIER_2026-08-24.md` · `INVENTAIRE_MISSIONS_OUVERTES_2026-08-24.md` ·
`COMPOSER_SECURITE_2026-08-25.md` · `E2E_DERIVE_FIXTURES_2026-08-25.md` ·
`reports/execution/RUN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md`


---

# ⚠️ CORRECTION MAJEURE (même jour) — un verdict GPT existe

Le §1 et le §7 de cette synthèse affirment que « le canal Codex/GPT n'a jamais produit une seule
ligne ». **C'est faux, et je le corrige ici plutôt que de réécrire silencieusement au-dessus.**

`reports/audit/GPT_FINAL_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823.md` porte
**`GPT_FINAL_AUDIT_VERDICT: REWORK`** et six constats, produits par un **canal de repli**
(`foodking-complex-implementer`). L'échec HTTP 400 que j'avais trouvé concernait le canal
`gpt-5.5-pro` — je l'ai généralisé à tort à l'absence de toute sortie.

**Vérification du 2026-08-25 : les six constats sont CLOS dans le code** (P0 garde E2E, P1 ordre
`queuePendingCount`, P1 teardown multi-produits, P1 restauration machine borne, P1 `input.json`,
P2 bouton « Réessayer »). Preuve point par point :
`reports/audit/CORRECTION_VERDICT_GPT_EXISTE_2026-08-25.md`.

**G1 se reformule** : non plus « clore sans verdict GPT », mais **« verdict REWORK du 2026-08-23,
six constats, tous vérifiés clos le 2026-08-25 → clôture en REWORK RÉSOLU »**. C'est une clôture
plus solide, appuyée sur une contre-expertise réelle.
