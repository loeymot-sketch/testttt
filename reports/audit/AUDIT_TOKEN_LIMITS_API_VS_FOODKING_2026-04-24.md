# Audit : limites de tokens — **ton Mac / FoodKing** vs **serveur (proxy / modèle / CDN)**

**Date** : 2026-04-24  
**Périmètre** : appels **`POST …/v1/chat/completions`** (Happy / proxy OpenAI-compatible) via le dépôt FoodKing, **différemment** de l’**usage affiché dans l’UI Cursor** (Claude, contexte, etc.).

---

## 1) Ce qu’on peut prouver à **100 %** (sans spéculer sur le back-office du fournisseur)

### 1.1 Côté **dépôt FoodKing** (lecture de code, vérif reproductible)

| Vérification | Résultat | Preuve |
|--------------|----------|--------|
| Le runner tronque-t-il le texte assistant pour le ramener à ~5–10k tokens ? | **Non** | `agents/codex.runner.mjs` : seule l’**erreur** est tronquée en log ; le contenu est écrit tel quel dans `output_codex.json` |
| Le client impose-t-il un `max_tokens` bas caché ? | **Non par défaut** | Le plafond de **sortie** n’est ajouté que si **`CODEX_MAX_COMPLETION_TOKENS`** / **`CODEX_MAX_TOKENS`** est défini (voir mêmes lignes) |
| Le `smoke` / scripts « économisent-ils » des crédits ? | Ils n’imposent qu’un **texte requis** ; le coût réel = **réponse du modèle** + **prompt** (usage renvoyé par l’API) | `agents/codex.smoke.mjs` (prompt court) |

**Conclusion technique (fermée)** : **Rien, dans le code du client FoodKing, ne cible 5k–10k tokens comme plafond de génération.**  
Si l’indicateur Happy / dashboard montre de petits montants par appel, ce n’est **pas** parce qu’une brique locale « coupe » en dessous d’un plafond secret ici.

**Double vérification (wire)** : script d’audit dédié qui envoie le corps JSON **lui-même** (hors `codex.runner.mjs` pour l’A/B) :

- Commande : `npm run codex:audit-limits`  
- Fichier : `scripts/codex-audit-api-limits.mjs`  
- Fichier JSON généré (exemple) : `reports/audit/codex_api_audit_payload_*.json`

Dans l’exécution retenue pour ce rapport, les appels **A** et **B** répondent **200** et renvoient un objet `usage` avec des **`completion_tokens` modestes** parce que la consigne est **courte** (réponse “OK” ou une phrase) — c’est le **comportement attendu d’un modèle qui a fini** (`finish_reason`: **`stop`**, arrêt **naturel**, pas `length`).

Extrait de log (réel, 2026-04-23) :

- Test A : `completion_tokens`: **~15–17** ; `max_completion_tokens` **envoyé** : **262144** (le plafond haut côté *requête* n’impose **pas** une sortie de 256k)  
- Test B : `completion_tokens`: **~36–41**

**Cela prouve** : le serveur d’API **accepte** un plafond **très élevé** dans le JSON, et comptabilise honnêtement de **petits** `completion_tokens` quand le travail est **minuscule** — preuve d’**absence** d’un plafond client à 5k, et d’**absence** d’“obligation d’économiser” dans l’appli : c’est l’**usage réel** qui compte.

---

## 2) Côté **serveur / fournisseur** : ce qu’on observe, sans accès “100 % interne”

L’**observateur externe** (même en audit sérieux) **ne peut pas** lire le code de facturation exact du portail “Happy” ni toutes les règles internes du data center. On reste sur des **faits reproductibles** + catégories d’explication.

### 2.1 Limite d’infrastructure (CDN / **504**)

Test **C** (même auditeur, requête **grosse**, **non-stream** — comme `CODEX_DISABLE_STREAM=1`) : **HTTP 504** (page *Gateway time-out* Cloudflare) après **~60 s** sur la chaîne de test, **alors que** `max_completion_tokens` est déjà haut (262144).

- **C’est une limite côté réseau / edge / passerelle** (délai max avant fin de génération), **pas** une limite de 5k tokens côté FoodKing.
- Le runner recommande d’ailleurs le **stream** par défaut pour des prompts longs pour cette raison (voir `docs/orchestration/CODEX_API_DELEGATION.md`).

**Vérification croisée (autre exécution, déjà en base)** : mission lourde avec **`codex.runner.mjs` en stream (défaut)** a pu tourner **~125 s** et produire un gros texte, alors qu’un géniteur en **one-shot** massif a pu être **coupé par le gateway** — ceci **renforce** la distinction *timeout edge* / *coupe 5k tokens app* (voir `reports/execution/RUN_STRESS_TOK_2026-04-24.md` + `missions/STRESS-HAPPY-TOK-001/output_codex.json`).

### 2.2 Limite **modèle / génération** (une seule complétion)

Dans le stress en **stream**, le modèle a indiqué lui-même un **`ARRET_LIMITE_MODELE`** et un ordre de grandeur d’`~4,3k` tokens de sortie **indicatifs**, sans atteindre 8000 mots demandés, **malgré** le plafond haut côté requête.  
→ **C’est** une forme de **plafond ou de politique de génération par complétion** côté moteur / route modèle, **hors** du dépôt.

### 2.3 L’**UI Cursor** n’est **pas** le même compteur

| Contexte | Ce qui est mesuré |
|----------|-------------------|
| **Cursor (session, Claude, onglet Usage)** | Contexte éditeur, historique, gros `input` d’analyse, **souvent** 5k–20k+ **affiché** / tour — **et par le passé** 100k+ côté **produit Cursor** (mémoire de session) |
| **Appel `chat/completions` (ton proxy, clé Happy)** | **Un** aller par requête : `usage.prompt_tokens` + `usage.completion_tokens` — **décorellé** des économies Graphiti / handoff, qui réduisent le **récit inutile dans le prompt** mais **n’imposent pas** un toit 5k sur la génération dans le code PHP/JS ici |

Les **économies Graphiti** concernent plutôt **moins** de *contexte injecté* et **moins** de tâches redondantes — ce n’est **pas** le même drapeau que “le proxy limite toute sortie à 5k”.

---

## 3) Synthèse « faut-il “libérer” quelque chose sur mon PC ? »

| Emplacement d’une limite | Peux-tu la “libérer” localement ? | Commentaire factuel |
|-------------------------|-----------------------------------|----------------------|
| Code FoodKing (runner) | **N/A** — **pas** de toit 5k sur la sortie | Prouvable par le script + lecture du code |
| Ta machine / disque / RAM | **Aucun rapport** direct avec 5k tokens par réponse | Les tokens sont comptés **côté API** |
| Fournisseur (proxy, modèle) | Seulement via leurs paramètres / forfait (clé, modèle, règles) | Télécharge `usage` (audit) + dashboard fournisseur |
| Cloudflare / timeout sur **one-shot** long | Pas “liberable” dans l’**appli** ; contournement = **stream** (déjà défaut) ou **découper** le travail | 504 prouvée en test C (non-stream) |
| Cible 100k+ **sortie** d’un seul appel | **En pratique souvent irréaliste** (modèles, politique, contenu) ; la stratique produit c’est plutôt **enchaîner** des requêtes ciblées | C’est de l’orchestration, pas un réglage “caché” dans le repo à activer |

---

## 4) Règle d’honnêteté méthodologique (pas d’opinion)

- **Prouvable à 100 %** ici : **(a)** l’appli ne fixe **pas** un toit 5k–10k, **(b)** le serveur d’API renvoie des `usage` cohérents, **(c)** un gros **non-stream** a pu générer un **504** côté gateway, **(d)** un gros **stream** a pu durer >60 s.  
- **Non prouvable à 100 % sans compte opérateur** : toutes les règles internes exactes de facturation “Happy” (tarifs, promotions, tranches), ni le plafond exact interne de chaque route de modèle — cela exige le **fournisseur** (contrat / support / doc technique).

Recommandation opérationnelle (basée sur les preuves ci-dessus) : pour le **max d’intelligence** sans te battre avec les timeouts, combine **(1)** **stream** pour les tâches longues, **(2)** `CODEX_MAX_COMPLETION_TOKENS` haut seulement quand c’est justifié, **(3)** `CODEX_LOG_USAGE=1` (et one-shot de diagnostic si besoin) pour cadrer avec le dashboard, **(4)** **découpage** (plusieurs missions / appels) pour dépasser ce qu’**une** complétion ne livrera pas, même si tu « libères » l’environnement local.

---

## 5) Artefacts à réutiliser

- Script : `scripts/codex-audit-api-limits.mjs`  
- `npm run codex:audit-limits` (ajouter `AUDIT_STRESS=1` pour le scénario C)  
- Derniers JSON : `reports/audit/codex_api_audit_payload_*.json`  
- Montée en charge stream : `reports/execution/RUN_STRESS_TOK_2026-04-24.md`

**Clarification (2026-04) — sortie ~10k vs « économie »** : le runner peut envoyer un plafond **élevé** (désormais **2M** par défaut, pas `Infinity` côté JSON) ; le fait de voir souvent des **`completion_tokens`** autour de quelques milliers à **~10–15k** tient d’abord à la **fin naturelle** de génération (`stop`) / à la **politique modèle+proxy** sur *une* complétion, **pas** à un mode « d’économie » dans l’appli FoodKing, ni à Graphiti (qui cible plutôt le *prompt* / la mémoire), ni au même compteur qu’**Cursor**.

**Fin de l’audit (version dépôt, reproductible).**
