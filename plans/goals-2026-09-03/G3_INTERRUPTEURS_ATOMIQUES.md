# G3 — Un audit n'atteste que ce qui a réellement eu lieu

Défauts couverts : **V-07** (P1) · **V-15** (P3)
Dépendances : aucune. Même classe de défaut que V-05 (G2/T2.4) — traiter les deux ensemble.

---

## Le défaut, dit simplement

Basculer un interrupteur système écrit d'abord une ligne dans `audit_logs` — le journal immuable,
signé en chaîne HMAC — **puis** applique la bascule.

Si la bascule échoue après coup, la preuve immuable affirme un changement qui n'a jamais eu lieu.
Et comme `audit_logs` est chaîné et append-only, cette ligne fausse ne peut plus jamais être
retirée : elle reste dans la chaîne pour les six ans de rétention.

C'est une inversion de l'ordre entre la preuve et le fait. Un journal d'audit n'a de valeur que
s'il ne peut pas devancer l'événement qu'il atteste.

## Ancres vérifiées (2026-09-03)

| Fichier | Ligne | Constat |
|---|---|---|
| `app/Http/Controllers/**/InterrupteurController.php` | 94 | `write()` de l'audit |
| `app/Http/Controllers/**/InterrupteurController.php` | 110 | `InterrupteurService::regler()` — **après** |
| `app/Services/**/InterrupteurService.php` | 120 | l'écriture en base qui peut échouer |
| `InterrupteurController.php` | (bloc `catch`) | n'attrape que `InvalidArgumentException` — une `QueryException` passe à travers |
| `resources/js/components/admin/observability/SystemHealthComponent.vue` | 119 | texte périmé : « journal serveur, pas le journal fiscal NF525 » alors que l'écriture va bien dans `audit_logs` |

## Tâches

- **T3.1 — Prouver l'inversion.**
  Banc : `(À CRÉER) tests/Feature/Pilotage/InterrupteurAuditApresMutationTest.php`
  Cas 1 : faire échouer `InterrupteurService::regler()` (mock levant une `QueryException`) ;
  exiger **zéro** nouvelle ligne `audit_logs` pour cette bascule, et un état inchangé en base.
  Cas 2 : bascule réussie ⇒ exactement une ligne, avec acteur, avant/après, branche, corrélation.
  **Le cas 1 doit rougir avant correctif.** Consigner dans `reports/supervision/2026-09-03/G3-banc-mord.txt`.

- **T3.2 — Inverser l'ordre, sans casser la chaîne.**
  Appeler `regler()` d'abord, écrire l'audit ensuite, avec la valeur **réellement** appliquée
  (relue, pas celle demandée).
  ⚠️ `audit_logs` est chaîné HMAC (CLAUDE.md §8) : on n'écrit qu'en ajout, jamais de correction
  a posteriori. Le seul changement autorisé ici est **l'ordre des appels**, pas la mécanique
  de chaînage. `AuditLogService.php` est en zone gelée : **ne pas le toucher**.

- **T3.3 — Élargir le `catch` à ce qui peut réellement arriver.**
  Une `QueryException`, un `Throwable` de lock, un timeout : tous doivent produire une réponse
  d'erreur explicite et **aucune** trace d'audit affirmant la bascule.

- **T3.4 — V-15, dire la vérité à l'écran.**
  `SystemHealthComponent.vue:119` doit dire que la bascule est consignée dans le journal d'audit
  métier (`audit_logs`), en précisant qu'il ne s'agit pas d'une écriture fiscale NF525 au sens du
  Z. Ni sous-vendre (« journal serveur ») ni sur-vendre (« journal fiscal »).
  Banc : `(À CRÉER) tests/js/systemHealthTexteAuditExact.spec.js`.

- **T3.5 — Attestation post-correctif.**
  Après les correctifs G2/T2.4 et G3/T3.2, relever la chaîne :
  `php artisan fiscal:verify-chain --all` doit rendre CHAIN OK, et
  `SELECT count(*), MAX(current_hash) FROM audit_logs` doit montrer un ajout, jamais une
  réécriture. Consigner dans `reports/supervision/2026-09-03/G3-chaine-nf525.txt`.

## Acceptation

- `tests/Feature/Pilotage/InterrupteurAuditApresMutationTest.php` — VERT, prouvé rouge sans correctif.
- `tests/js/systemHealthTexteAuditExact.spec.js` — VERT.
- Non-régression VERTE : `tests/Feature/Pilotage/InterrupteurBasculeEstAuditeeTest.php` ·
  `InterrupteurLectureGardeeTest.php` · `InterrupteurCatalogueTest.php` · `InterrupteurTest.php` ·
  `tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php`.
- `php artisan fiscal:verify-chain --all` — CHAIN OK.
- Zone gelée : `app/Services/Fiscal/AuditLogService.php` — diff nul.

## Surface visuelle

`http://127.0.0.1:8766/admin/observability/system` — compte Admin.
Trois captures analysées : confirmation affichée · bascule réussie avec retour visible ·
bascule refusée par le serveur, l'écran reste sur l'ancien état et annonce l'échec (`role="alert"`).

## Condition de sortie

Deux rondes identiques, chaîne NF525 attestée, texte de l'écran exact, zone gelée intacte.
