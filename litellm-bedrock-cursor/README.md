# LiteLLM — proxy local AWS Bedrock → Cursor (OpenAI-compatible)

Ce dossier sert de proxy [LiteLLM](https://github.com/BerriAI/litellm) : **Cursor** parle en OpenAI sur `localhost`, le proxy appelle **Amazon Bedrock** (Claude).

## macOS — tout en une fois

1. Remplis **`.env`** (copie depuis `.env.example` si besoin) : clés IAM + `AWS_REGION_NAME` + `LITELLM_MASTER_KEY`.
2. **Installation** (venv + paquets) :
   ```bash
   cd litellm-bedrock-cursor
   bash setup-all-mac.sh
   ```
3. Suis les 4 blocs que le script affiche (diagnostic → proxy → `test.sh` → réglages Cursor).  
   **Optionnel** : `brew install awscli` seulement si tu veux `aws` en CLI en plus du proxy.

## Prérequis

- Python 3
- Un compte AWS avec accès Bedrock, modèles activés (Model access), et identifiants IAM valides
- Fichier `.env` (copiez `.env.example`, ne commitez jamais les vraies clés)

## Lancer

**macOS / Linux :**

```bash
bash start-mac-linux.sh
```

Si le **port 4000 est déjà utilisé** (erreur de bind, ou un autre service) :

```bash
LITELLM_PORT=4001 bash start-mac-linux.sh
```

Puis dans Cursor, utilisez `http://localhost:4001/v1` et `LITELLM_PORT=4001 bash test.sh`.

**Windows (PowerShell) :**

```powershell
powershell -ExecutionPolicy Bypass -File start-windows.ps1
```

Pour un port autre que 4000 : `$env:LITELLM_PORT=4001` avant le script, puis base URL `http://localhost:4001/v1`.

## Vérifier d’où vient l’erreur (sans le proxy)

```bash
cd litellm-bedrock-cursor && . .venv/bin/activate && python3 diagnose_bedrock.py
# Autre région (ex.) :  AWS_REGION_NAME=eu-west-1 python3 diagnose_bedrock.py
```

- Si le script **réussit** : le compte OK → chercher côté LiteLLM.  
- Si le script donne **ThrottlingException** / 429 : **c’est le compte AWS (quota), pas le fichier de config ici** — seul le **Support AWS** ou l’ouverture de **quota** peut trancher.

**Codes de sortie de `diagnose_bedrock.py`** (pour scripts / CI) :  
`0` = appel `converse()` OK, réponse reçue · `1` = erreur locale (`.env` incomplet, `pip` manquant) · `2` = throttle côté AWS (TPM, *Too many tokens*, 429) · `3` = autre `ClientError` (AccessDenied, `ValidationException`, mauvaise région, etc.).

**Côté AWS (à faire toi, dans la console)** : Service Quotas → *Amazon Bedrock* → vérifier TPM/RPM (demande d’augmentation si 0) · Bedrock **Model access** pour Opus 4.7 · compte = bon compte (pas enfant / sans Bedrock) · si l’org a des **SCP**, vérifier qu’elles n’empêchent pas `bedrock:Invoke*`. Même en **CloudShell** (copier le test ou lancer un `converse` minimal) : le résultat doit coller *localement*.

## Tester l’API

Avec le proxy en cours d’exécution sur le port 4000 :

```bash
bash test.sh
```

## Configuration Cursor

- **Base URL :** `http://localhost:4000/v1` (remplacez le port si vous avez utilisé `LITELLM_PORT`, ex. `4001`)
- **Clé API :** `sk-local-bedrock` (ou la valeur de `LITELLM_MASTER_KEY` dans votre `.env`)
- **Modèle :** `bedrock-claude-opus` (Opus 4.7, profil d’inférence `us.…` dans `config.yaml`).  
  **Si 429 (throttle)** : essaie l’autre alias **`bedrock-claude-opus-global`** (profil `global.…` cross-région).  
  Le proxy fixe un **`max_tokens` max de sortie par appel (32768 = 32k par défaut)** : tu peux le changer dans `config.yaml` (ex. 8192, 16k, 64k) selon 429/usage. Dans Cursor, ajuste aussi le plafond de sortie si l’UI le permet.

## Dépannage

| Erreur | Piste |
|--------|--------|
| `403` / `AccessDenied` | Vérifier l’accès aux modèles Bedrock (console) et les politiques IAM |
| `ValidationException` (modèle) | Vérifier la **région** et l’**ID de modèle** Bedrock |
| Erreur d’identifiants | Vérifier le fichier `.env` (sans espaces, bonnes clés) |
| Port 4000 occupé | Lancer sur un autre port, ex. : `litellm --config config.yaml --port 4001` et adapter l’URL dans Cursor et `test.sh` |

## Sécurité

- Ne pas committer `.env` ni coller de secrets dans les tickets ou logs.
- Après un test sur une machine partagée ou en cas d’exposition, **régénérer ou désactiver** la clé d’accès AWS utilisée.
