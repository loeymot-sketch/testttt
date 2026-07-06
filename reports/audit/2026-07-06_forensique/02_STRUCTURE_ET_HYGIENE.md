# FoodKing — Audit de structure & hygiène du dépôt

> Partie 2/7 de l'audit forensique du 2026-07-06. Répond directement à la question : **« mon repo est-il bien structuré ? »**
> Méthode : inventaire statique de l'arbre Git (`git ls-files`), sans installer de dépendances.

## 0. Verdict franc

**Non, le dépôt n'est pas structuré comme un produit livrable.** Le code applicatif Laravel/Vue est correctement organisé (l'ossature Laravel standard est respectée), mais il est **noyé dans une masse d'artefacts non-produit** : sorties d'orchestration IA, archives, exports, images de design, binaires Office, scripts ad hoc, et surtout **des fichiers de secrets et des résidus de debug committés à la racine**.

Sur **3 267 fichiers suivis**, une part majeure n'a rien à faire dans le dépôt de production :

| Catégorie | Fichiers suivis | Devrait être dans le repo produit ? |
|---|---:|---|
| `reports/` (sorties de cycles IA) | 329 | ❌ non (ou branche/dépôt séparé) |
| `_archive/` | 158 | ❌ non |
| `tasks/` | 82 | ❌ non |
| `.cursor/` | 45 | ⚠️ outillage, à isoler |
| `plans/` | 23 | ❌ non |
| Images suivies (`png/jpg/gif/svg`) | 558 | ⚠️ à déplacer/LFS/supprimer |
| Binaires `.docx` | 3 | ❌ non (binaire Office versionné) |
| `prompts/`, `workflows/`, `.agents/`, `cursor-export-new-account/` | 19 | ⚠️ outillage, à isoler |
| **Sous-total non-produit (hors images légitimes)** | **~1 100+** | **≈ un tiers du dépôt** |

> Impact concret : un tiers du dépôt est du bruit. Cela ralentit le clone, pollue la recherche de code, brouille la revue, gonfle l'index Git, et rend le dépôt illisible pour un nouvel arrivant (humain ou agent).

---

## 1. Problèmes **critiques/haute priorité** de structure

### 1.1 🔴 Fichiers de secrets committés à la racine
Trois fichiers JSON contiennent des identifiants en clair, suivis par Git :

```
payload_caissier.json  → {"email":"caissier@lecayenne-henin-beaumont.fr", "password":"password"}
payload_chef.json      → {"email":"chef@lecayenne-henin-beaumont.fr", "password":"password"}
payload_customer.json  → {"email":"customer@example.com", "password":"password"}
```

Même s'il s'agit de comptes de test, ils **ne doivent pas être versionnés** : ils normalisent le mot de passe `password`, exposent des emails réels de branche (`lecayenne-henin-beaumont.fr`), et si ces comptes existent en prod, c'est une porte d'entrée directe. → traité en profondeur dans le rapport **Sécurité (05)**.

### 1.2 🟠 Fichiers-résidus de debug committés à la racine
Six fichiers sans extension, à la racine, sont des **sorties accidentelles de scripts** (redirections shell/PHP capturées dans des fichiers) :

```
id, name, email, url, branch_id, landing_url
```

Leur contenu le prouve — ce sont des fragments de code/log, pas des données :
```
branch_id : "  # . $u- .  [branch= . $u- . ]  . $u- .  < . $u- . > . PHP_EOL"
url       : "  → Redirection simulée: /admin/ . $perm- . PHP_EOL"
```

Ce sont des déchets purs. Ils doivent être **supprimés** et le pattern ajouté au `.gitignore`. Leur présence indique aussi qu'un `git add .` non filtré a été exécuté au moins une fois — un risque récurrent tant que le `.gitignore` ne couvre pas ces cas.

### 1.3 🟠 Dossiers et fichiers avec espaces et accents
Quatre entrées suivies portent des espaces ou apostrophes dans leur nom :

```
borne (Remix)/
design concurrent /       ← finit même par une espace
frontend public/
avis d'expert .md
```

Les espaces et l'apostrophe dans les chemins **cassent régulièrement** les scripts shell, les globs de CI, les outils de build et le tooling agent (les commandes doivent citer/échapper systématiquement). Un chemin qui finit par une espace (`design concurrent `) est un piège classique. → à renommer en `kebab-case` ASCII.

### 1.4 🟠 Triple implémentation du kiosque (drift architectural)
Trois implémentations de la borne coexistent dans le dépôt :

1. `resources/js/store/modules/kiosk*.js` + `bootstrap-kiosk.js` — l'implémentation **Vue/Vuex réelle** (celle servie).
2. `borne (Remix)/` — un prototype parallèle (HTML + composants) avec son propre `AUDIT_FINAL.md`.
3. `kiosk_implementation/` — des documents/snippets d'implémentation (`cart/`, `home/`, `order/`).

Sans convention claire, un développeur ou un agent ne sait pas laquelle fait autorité. C'est du **code mort potentiellement dangereux** (on corrige au mauvais endroit) et une source de confusion permanente. → une seule doit être la source de vérité, les autres archivées **hors** du dépôt produit.

---

## 2. Problèmes **moyens** de structure

### 2.1 Le dépôt mélange 4 natures de contenu sans séparation
Le dépôt empile, au même niveau, quatre choses qui devraient être séparées :

- **(a) Code produit** : `app/`, `resources/`, `routes/`, `database/`, `config/`, `public/`, `tests/`, `borne (Remix)` (si conservée)…
- **(b) Docs produit** : `docs/` (bien), mais aussi des docs éparpillées à la racine (`README.md`, `AGENTS.md`, `MEMORY.md`, `CORRECTION_MENU_URGENT.md`, `PLAN_IMPLEMENTATION_MENU_FINAL.md`…).
- **(c) Outillage d'orchestration IA** : `prompts/`, `tasks/` (82), `plans/` (23), `workflows/`, `reports/` (329), `.agents/`, `.cursor/` (45), `cursor-export-new-account/`.
- **(d) Déchets** : `_archive/` (158), les 6 fichiers-résidus, les 3 `.docx`, les images de `design concurrent /`.

### 2.2 Binaires versionnés
- **3 fichiers `.docx`** (`FoodKing_Audit_Global_2026-04-14.docx`, `audits/*.docx`) : binaires Office non-diffables dans Git. Les audits doivent être en Markdown (diffables) ; le `.docx` est un livrable, pas une source.
- **558 images** suivies : une partie est légitime (assets `public/`), mais `design concurrent /` (captures `IMG_99xx.jpeg`) et divers PNG de design gonflent l'historique. Candidats à Git LFS ou à un stockage externe.

### 2.3 Racine encombrée de fichiers ad hoc
Scripts et notes « one-shot » à la racine : `EXECUTE_MENU_FIX.sh`, `CORRECTION_MENU_URGENT.md`, `PLAN_IMPLEMENTATION_MENU_FINAL.md`. Utiles sur le moment, ils deviennent du bruit permanent. → `scripts/` ou suppression après usage.

### 2.4 Dette de build (voir aussi rapport Version Map 01)
- **`webpack.mix.js` / laravel-mix** comme bundler (aucun `vite.config.*` présent) alors que Vite est le standard Laravel depuis 9.19 → build legacy.
- **Tailwind 3.4 ET Bootstrap 5.2** chargés simultanément → deux systèmes de CSS qui se chevauchent, poids et incohérence visuelle.

---

## 3. Ce qui est **bien** (à préserver)

- ✅ L'**ossature Laravel** est standard et propre : `app/{Http,Services,Models,Events,Listeners,Jobs,Observers,Domain,Enums}`, `routes/`, `database/migrations`, `config/` — un développeur Laravel s'y retrouve immédiatement.
- ✅ La **séparation en couches** existe réellement : `app/Services/` (108 fichiers), `app/Domain/`, observers, listeners, events — l'intention architecturale est là.
- ✅ La **documentation produit** dans `docs/` est riche et thématisée (ARCHITECTURE, ORDER_FLOW, EVENT_CONTRACT, AUTHZ_MATRIX, BUSINESS_RULES…). Sa *fiabilité* est évaluée séparément (rapport Drift docs 07), mais l'effort de documentation est réel.
- ✅ Le frontend Vue est modulaire : `store/modules/` par domaine, `components/`, `services/`, `composables/`.

---

## 4. Arborescence cible proposée

```
foodking/
├── app/ resources/ routes/ database/ config/ public/ bootstrap/ storage/   # code produit (inchangé)
├── tests/                                                                    # tests (inchangé)
├── docs/                          # UNIQUEMENT la doc produit, en Markdown
│   └── audits/                    # audits archivés en .md (pas .docx)
├── kiosk/                         # UNE seule implémentation kiosque, source de vérité
├── tools/                        # optionnel : outillage dev non-produit versionné volontairement
│   └── ai/                        # prompts/, workflows/ SI on veut les garder versionnés
├── .cursor/ .agents/              # config d'agents (garder, mais documenter le rôle)
├── README.md CLAUDE.md AGENTS.md  # rester à la racine (points d'entrée)
└── .gitignore                     # durci (voir §5)

# SORTIS du dépôt produit (branche orpheline dédiée, dépôt séparé, ou artefacts CI) :
#   reports/ (329)  tasks/ (82)  plans/ (23)  _archive/ (158)
#   design concurrent/  *.docx  cursor-export-new-account/
```

## 5. Plan de rangement (étapes sûres, ordre recommandé)

Toutes les étapes sont **réversibles** (l'historique Git conserve tout) et se font par petits commits atomiques.

**P0 — Sécurité & déchets (immédiat)**
1. `git rm payload_caissier.json payload_chef.json payload_customer.json` puis rotation des mots de passe côté comptes. Recréer les payloads en local, non suivis.
2. `git rm id name email url branch_id landing_url` (résidus de debug).
3. Durcir `.gitignore` :
   ```gitignore
   /payload_*.json
   /id
   /name
   /email
   /url
   /branch_id
   /landing_url
   *.docx
   /test-results/
   ```

**P1 — Renommages sûrs (chemins ASCII)**
4. `git mv "borne (Remix)" borne-remix` (ou `kiosk-prototype/` si archivé).
5. `git mv "design concurrent " design-concurrent` — puis évaluer un déplacement hors dépôt.
6. `git mv "frontend public" frontend-public` ; `git mv "avis d'expert .md" docs/avis-expert.md`.

**P2 — Sortir le non-produit**
7. Décider d'une stratégie pour `reports/`, `tasks/`, `plans/`, `_archive/` : soit une **branche orpheline** `ops/ai-artifacts`, soit un **dépôt séparé**, soit `.gitignore` + suppression du suivi. Ne pas perdre le contenu (il porte de la mémoire de projet) — le **déplacer**, pas juste supprimer.
8. Convertir les `.docx` d'audit en Markdown, supprimer les binaires.

**P3 — Dette de build (chantier séparé)**
9. Migrer laravel-mix → **Vite** (chantier à part, testé).
10. Trancher **Tailwind vs Bootstrap** : en retirer un.
11. Résoudre la **triple implémentation kiosque** : désigner la source de vérité, archiver le reste.

---

*Rapport généré dans le cadre de l'audit forensique multi-agents. Les findings de sécurité liés aux secrets committés sont détaillés et vérifiés dans le rapport 05 (Sécurité).*
