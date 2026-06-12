# Contreseing requis — rebaseline SHA-256 du wizard POS (1 ligne)

## Constat (W-INT 2026-06-12, preuves hash par branche)
La sentinelle `FrozenZoneSha256BaselineSentinelTest` est ROUGE sur le tronc intégré — **ce n'est PAS une violation frozen** :
- `pos-wizard.js` du tronc = `3264f41b…` = **bit-identique au spine `release/v1-2026-06-10`** (lignée des vagues wizard owner-gatées LOCK-W6/LOCK_CAISSE-01, supervisor verdict `eccebe57d`). Vérifié aussi : cms-spine identique ; frozen-diff tronc-vs-spine = 0 ligne sur les 15 fichiers §7.
- La baseline JSON (`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`, héritée de cms-spine commit `5682fe06e`) photographie l'ANCIEN hash `13cc2e54…` (wizard d'avant la vague spine).

## Pourquoi je ne l'ai pas fait moi-même
Mettre à jour une baseline d'intégrité est exactement le geste qu'un acteur malveillant ferait pour masquer un vrai tampering — le garde-fou l'a réservé à ton contreseing, et c'est sain. La sentinelle elle-même prescrit : « update baseline in the SAME commit, reference the LOCK doc ».

## Action owner (1 commande, dans le worktree d'intégration)
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/integration-v1-2026-06-12
python3 -c "
import json; p='tests/Feature/Sentinels/frozen-zone-sha256-baseline.json'
d=json.load(open(p)); d['public/js/pos-wizard.js']='3264f41bf5e4003434bee0c5671e37d0af727236cd50c8eed0c34dec60ace0b1'
json.dump(d,open(p,'w'),indent=2)"
./vendor/bin/phpunit tests/Feature/Sentinels/FrozenZoneSha256BaselineSentinelTest.php   # attendu : OK
git add tests/Feature/Sentinels/frozen-zone-sha256-baseline.json
git commit -m "test(sentinel): rebaseline pos-wizard.js SHA → lignée spine owner-gatée (LOCK-W6/LOCK_CAISSE-01, verdict eccebe57d) — contresigné owner"
```
**Attendu** : sentinelle verte ; aucun fichier frozen modifié (seule la donnée de référence du test change).
