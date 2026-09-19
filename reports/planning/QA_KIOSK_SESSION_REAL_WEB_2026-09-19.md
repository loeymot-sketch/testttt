# QA — session borne sur matériel réel — 2026-09-19

## Signal utilisateur

Après une veille prolongée ou une mise à jour, la borne affiche « Borne
indisponible pour le moment ». Fermer puis rouvrir l'application de borne la
fait à nouveau arriver en plein écran sur `/kiosk` et fonctionner.

## Faits déjà prouvés

- `e75c46e3b` est déployé : le jeton borne est renouvelé avant son TTL Sanctum
  de 8 h et un lien `machine_key` valide crée un cookie chiffré de 14 jours.
- Le rapport Chromium du 17 septembre couvre l'inscription fidélité et la
  conservation de deux sauces POS, mais pas une reprise de borne après veille
  réelle.
- Une visite depuis un navigateur externe non autorisé doit continuer à
  afficher cet écran : elle ne peut jamais recevoir les identifiants de la
  machine.

## Hypothèses à départager

1. L'application borne rouvre une URL sans `machine_key` avant qu'un grant
   persistant ait été créé ou alors que ses cookies ont été effacés.
2. La borne est bien provisionnée, mais le proxy/cache ou son chemin de
   lancement ne conserve pas le cookie `/kiosk` après une mise à jour.
3. L'auto-login reçoit les identifiants mais l'API de connexion/refresh échoue
   après le réveil ; l'écran doit alors proposer un redémarrage contrôlé, sans
   exposer de secret.

## Contrat de sécurité

- Ne pas exposer `KIOSK_MACHINE_*`, `machine_key` ou un payload de connexion à
  un navigateur public.
- Ne pas créer de commande ni de paiement lors des essais production.
- Une correction reste compatible avec l'isolation `branch_id` et ne modifie
  aucune règle de prix.

## Vérification à réaliser

- Lecture seule sur le VPS : confirmer la configuration effective sans lire de
  valeur secrète (`payload_present`, grant, TTL, allowlist).
- Rejouer localement le cycle complet `machine_key` -> cookie sécurisé -> URL
  propre -> renouvellement jeton -> réouverture ; le test doit aussi prouver
  que l'URL publique sans grant reste bloquée.
- Vérifier en navigateur réel la page publique et la santé production. La
  validation terminale de la tablette reste nécessaire pour couvrir son
  navigateur, son raccourci et ses cookies, qui ne sont pas accessibles depuis
  ce poste.

## Routage et stratégie de test

`auth` + borne + reprise de session est un flux critique multi-domaines :
`playwright-critical-flow`, tests Feature Laravel et test navigateur réel.
Toute modification applicative exige un nouveau cycle, gate si une zone frozen
est touchée, puis tests ciblés et déploiement séparé.

## Résultat opérationnel

- Le `/64` IPv6 observé sur la borne Windows les 18 et 19 septembre a été
  ajouté à `KIOSK_AUTO_LOGIN_TRUSTED_IPS` sur le VPS. La résolution serveur
  pour une adresse de ce préfixe est maintenant positive; une adresse hors
  préfixe reste refusée par les tests.
- Cela couvre le cas où le cookie de grant est effacé ou l'application repart
  directement sur `/kiosk/idle`: la borne retrouve l'auto-login sans remettre
  le secret dans son URL.
- Vérification post-changement: `fiscal:verify-chain --all`,
  `fiscal:assert-chain-clean` et `healthz:check --json` sont verts.
- Incident de vérification évité: ne pas exécuter `config:cache` sur ce VPS
  tant que la clé fiscale par branche est lue via `env()` à l'exécution. Le
  cache a été retiré immédiatement, avant toute transaction, et aucune donnée
  fiscale n'a été modifiée.
