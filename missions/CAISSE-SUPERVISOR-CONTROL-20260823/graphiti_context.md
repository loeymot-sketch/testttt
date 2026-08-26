Graphiti MCP n'est pas chargé dans cette session ; la mémoire disque et le code courant sont autoritaires.
Le backend reste l'unique source de vérité de prix ; `branch_id` est exact, `OrderStatus` utilise l'enum canonique et les événements sont dispatchés après commit.
Les files hors-ligne non signées ne doivent jamais être rejouées ; une sonde de santé en erreur doit échouer honnêtement, jamais produire un faux vert.
Le cycle Wheel et son gate UX humain restent séparés et hors scope.
