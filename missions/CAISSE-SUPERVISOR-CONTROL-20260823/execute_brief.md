# Brief de reprise EXECUTE

1. Inspecter `git diff` et les fichiers non suivis de l'allowlist ; ne pas supposer l'implémentation complète.
2. Vérifier le contrôleur de santé POS pour fuite cross-branch, cache non branché et faux vert sur exception.
3. Vérifier que chaque chemin de la file offline (montage, timer, événement `online`, clic) bloque les payloads legacy non signés tout en les conservant en quarantaine.
4. Vérifier les composants accessibles : sémantique native, pas de bouton imbriqué, nom accessible, focus visible et reduced motion.
5. Vérifier le harness E2E : base dédiée fail-fast, fixture branch-scopée, étapes diagnostiques, aucun cleanup fiscal/destructif et aucun statut muté directement en DB.
6. Renvoyer un JSON unique. Les `code_blocks` doivent être minimaux et applicables ; si le diff est déjà correct, l'indiquer sans réécriture.
7. Ne jamais annoncer un test PASS sans sortie réelle ; les tests complets seront exécutés par la phase VALIDATE après application.
