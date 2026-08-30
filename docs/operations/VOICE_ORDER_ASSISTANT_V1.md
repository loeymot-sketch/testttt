# Assistant commandes téléphone V1 — exploitation

## Résultat attendu

Le téléphone SIP de l’employé sonne normalement. L’employé parle au client. Après avoir informé le client et appuyé sur **Client informé — démarrer**, Asterisk ouvre un flux audio éphémère vers Deepgram. FoodKing affiche la transcription, propose les produits reconnus et les questions à poser. L’employé ouvre chaque produit dans le wizard existant, corrige, puis utilise le bouton **Commande téléphone** existant.

La commande est alors créée par le backend FoodKing, au prix calculé par le backend, envoyée dans le flux cuisine existant et placée en attente d’encaissement. Le copilot ne crée jamais une commande de lui-même.

## Choix techniques et coût

- **PBX** : Asterisk local, logiciel libre. Aucun opérateur intermédiaire Twilio/Telnyx.
- **Téléphonie** : identifiants SIP de la ligne VoIP Free Pro.
- **Transcription** : Deepgram Flux Multilingual, région UE, PCMU 8 kHz. La page tarifaire Deepgram reste la source à vérifier avant activation ; aucun SDK n’est installé.
- **Structuration** : rapprochement catalogue déterministe inclus. OpenAI est facultatif et désactivé par défaut ; s’il est activé, le modèle économique est configurable.
- **Stockage** : texte final seulement dans `action_logs`, rétention 30 jours par défaut. Aucun fichier audio.

Références officielles :

- Free Pro, configuration d’un poste VoIP : https://support-pro.free.fr/comment-parametrer-mon-poste-telephonique-voip/
- Asterisk, enregistrement sortant PJSIP : https://docs.asterisk.org/Configuration/Channel-Drivers/SIP/Configuring-res_pjsip/Configuring-Outbound-Registrations/
- Asterisk, ARI External Media : https://docs.asterisk.org/Development/Reference-Information/Asterisk-Framework-and-API-Examples/External-Media-and-ARI/
- Deepgram Flux : https://developers.deepgram.com/docs/flux/quickstart
- Deepgram tarifs : https://deepgram.com/pricing

## 1. Obtenir les paramètres Free Pro

Dans l’espace Free Pro, récupérer les paramètres de configuration VoIP du numéro :

1. serveur/registrar SIP ;
2. identifiant SIP ;
3. mot de passe SIP ;
4. éventuel proxy sortant ;
5. codecs autorisés ;
6. règle Free concernant le nombre d’enregistrements simultanés.

Ne jamais envoyer le mot de passe SIP dans un ticket, un chat ou un commit. Les exemples `services/voice-gateway/asterisk/*.example` contiennent uniquement des marqueurs.

Si Free Pro fournit plutôt un renvoi vers une URI SIP, pointer cette URI vers le trunk Asterisk protégé. Le principe FoodKing ne change pas : Asterisk reçoit l’appel, fait sonner le poste employé, puis duplique le média uniquement après consentement.

## 2. Installer Asterisk sur la passerelle locale

Machine recommandée : mini-PC Linux filaire, IP locale fixe, onduleur, horloge NTP, accès SSH limité. Ne pas exposer ARI sur Internet.

1. installer Asterisk depuis les paquets maintenus de la distribution ;
2. fusionner les exemples `pjsip.conf.example`, `extensions.conf.example`, `ari.conf.example` et `http.conf.example` dans la configuration locale ;
3. remplacer tous les marqueurs `FREE_*`, `EMPLOYEE_PHONE_RANDOM_PASSWORD` et `ARI_RANDOM_PASSWORD` hors dépôt ;
4. configurer le combiné de l’employé sur l’extension SIP `100` ;
5. vérifier d’abord le contexte de repli `from-free-pro-bypass` : appel entrant, audio dans les deux sens, appel sortant si nécessaire ;
6. seulement ensuite basculer le trunk entrant sur `from-free-pro`.

Commandes Asterisk utiles :

```text
pjsip show registrations
pjsip show endpoints
ari show status
core set verbose 3
```

Les sorties ne doivent pas être copiées dans FoodKing si elles contiennent des identifiants ou des numéros clients.

## 3. Configurer FoodKing

Générer un secret HMAC d’au moins 32 octets, par exemple avec le gestionnaire de secrets de l’hébergeur. Renseigner dans l’environnement serveur :

```dotenv
VOICE_ORDER_ENABLED=false
VOICE_ORDER_BRANCH_ID=<id exact du restaurant>
VOICE_ORDER_GATEWAY_ID=restaurant-main
VOICE_ORDER_GATEWAY_SECRET=<secret HMAC partagé>
VOICE_ORDER_RETENTION_DAYS=30
VOICE_ORDER_OPENAI_ENABLED=false
```

Le cache de production doit être partagé et atomique (`redis` recommandé) : l’anti-rejeu HMAC et l’état en direct ne doivent pas utiliser un cache mémoire propre à chaque processus.

Planifier la purge quotidienne :

```text
php artisan voice-order:purge-transcripts
```

## 4. Configurer le service passerelle

Sur la machine Asterisk, dans un fichier d’environnement lisible uniquement par le compte du service :

```dotenv
VOICE_GATEWAY_FOODKING_BASE_URL=https://caisse.exemple.fr
VOICE_ORDER_GATEWAY_ID=restaurant-main
VOICE_ORDER_GATEWAY_SECRET=<même secret HMAC>
VOICE_GATEWAY_DEEPGRAM_API_KEY=<clé Deepgram>
VOICE_GATEWAY_ARI_URL=http://127.0.0.1:8088/ari
VOICE_GATEWAY_ARI_USERNAME=foodking
VOICE_GATEWAY_ARI_PASSWORD=<mot de passe ARI>
VOICE_GATEWAY_EMPLOYEE_ENDPOINT=PJSIP/100
VOICE_GATEWAY_RTP_ADVERTISE_HOST=127.0.0.1
```

Installation isolée :

```text
python3 -m venv /opt/foodking-voice/venv
/opt/foodking-voice/venv/bin/pip install -r services/voice-gateway/requirements.txt
/opt/foodking-voice/venv/bin/python services/voice-gateway/main.py
```

Le service ouvre Deepgram sur `wss://api.eu.deepgram.com/v2/listen` avec `flux-general-multi`, `language_hint=fr`, `encoding=mulaw`, `sample_rate=8000`. Il ne crée ni fichier WAV ni tampon pré-consentement.

## 5. Information du client

Texte opérationnel proposé, à faire valider par le responsable avant activation :

> Pour bien noter votre commande, notre outil transcrit cet appel. Aucun audio n’est enregistré.

L’employé doit prononcer cette phrase avant d’appuyer sur le bouton. Le bouton autorise un seul appel et ne peut pas autoriser un autre appel ou une autre filiale.

La transcription est une aide opérationnelle, pas une preuve juridique infaillible. L’écran affiche les incertitudes et impose une vérification humaine.

## 6. Procédure de test avant activation

Conserver `VOICE_ORDER_ENABLED=false` jusqu’au test planifié dans `docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md`.

1. activer temporairement dans un environnement de recette ;
2. appeler la ligne Free Pro ;
3. confirmer que le combiné sonne et que l’audio reste bidirectionnel ;
4. parler avant consentement et vérifier qu’aucun texte/flux Deepgram n’apparaît ;
5. informer le client, appuyer sur le bouton, dicter une commande personnalisée ;
6. corriger chaque produit dans le wizard ;
7. envoyer par **Commande téléphone** ;
8. vérifier cuisine, file d’encaissement, numéro/nom, total backend et transcription rattachée ;
9. couper Deepgram puis le gateway et vérifier que le mode manuel reste utilisable ;
10. seulement après signature humaine, activer en production.

## 7. Mode dégradé

- Deepgram indisponible : l’employé continue la saisie manuelle ; aucune commande n’est bloquée.
- OpenAI indisponible : le rapprochement déterministe continue et les ambiguïtés restent à valider.
- Gateway indisponible : basculer le trunk sur `from-free-pro-bypass` pour faire sonner directement le poste.
- Lien transcription→commande en échec : la commande n’est jamais recréée. La caisse conserve pendant 24 h le petit enregistrement technique `call_id/order_id/branch_id/user_id` et retente uniquement le lien.

## 8. Protection des données

- pas d’audio stocké ;
- pas de transcript, téléphone, clé ou secret dans les logs applicatifs ;
- accès écran sous session staff et permission `pos` ;
- requêtes et cache strictement scindés par `branch_id` ;
- les e-mails et numéros détectables sont masqués avant l’extraction OpenAI facultative ; une personne peut néanmoins épeler ou paraphraser une donnée, donc l’anonymisation ne peut pas être garantie par regex seule ;
- configurer les options de résidence/rétention adaptées dans les comptes fournisseurs avant l’activation réelle.
