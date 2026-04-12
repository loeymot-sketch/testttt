# Playwright sous Windows (FoodKing bot)

Guide court pour l’erreur **`import_error_playwright`** et la suite (profils navigateur).

## Diagnostic

Le message **`import_error_playwright`** signifie : l’interpréteur Python utilisé par **`bot-cli.ps1`** ne trouve pas le paquet **`playwright`**. Souvent :

- Playwright installé dans **un autre** Python que celui choisi par `bot-cli.ps1`, ou  
- pas installé du tout.

## Installation recommandée (dépôt avec `.venv`)

`bot-cli.ps1` préfère **`.venv\Scripts\python.exe`** s’il existe. Installe dans **ce** Python :

```powershell
cd C:\chemin\vers\ton\repo
.\.venv\Scripts\python.exe -m pip install playwright
.\.venv\Scripts\python.exe -m playwright install chromium
```

Vérification :

```powershell
.\.venv\Scripts\python.exe -c "import playwright; print('playwright-ok')"
```

Tu dois voir **`playwright-ok`**.

Les binaires Chromium sont généralement sous  
`%LOCALAPPDATA%\ms-playwright\`.

Ensuite, relancer par exemple :

```powershell
cd C:\chemin\vers\ton\repo
.\bot\scripts\run_auto_cycle.ps1 -MaxSteps 30
```

Tu ne devrais plus t’arrêter sur **`import_error_playwright`** pour cette seule raison.

## Si ça échoue encore

### Variable `FOODKING_PYTHON`

Si tu pointes **`FOODKING_PYTHON`** vers un `python.exe` précis, installe Playwright **dans ce** Python :

```powershell
& $env:FOODKING_PYTHON -m pip install playwright
& $env:FOODKING_PYTHON -m playwright install chromium
```

### Pas de `.venv`

Utilise le même **`python.exe`** que celui résolu par `bot-cli.ps1` (voir la logique dans `bot-cli.ps1` : venv, puis installs typiques, puis `python` du PATH hors Store).

## Après Playwright : profils et UI

Pour que **`browser-run-step`** aille au bout (pas seulement « import OK ») :

1. Copier **`bot/browser_runner/browser_profiles.example.json`** → **`bot/browser_runner/browser_profiles.json`**.
2. Renseigner une vraie **`claude.start_url`** (projet Claude).
3. Utiliser un **profil Chromium persistant** déjà connecté (login une fois en mode fenêtré).
4. Ajuster les **`selectors`** si l’UI Claude a changé (voir commentaires dans l’exemple).

## Vérification de conversation (Claude)

Après `page.goto`, le runner compare **`page.url`** à **`claude.expected_chat_url`** si renseigné, sinon à **`claude.start_url`** (même UUID dans `/chat/<uuid>`). Si ça correspond, la cible est acceptée même si le libellé `00_ORCHESTRATOR` n’apparaît pas dans le HTML. Sinon repli sur le texte **`conversation_label`** (bridge / session). Voir `bot/browser_runner/guardrails.md`.

## Affichage « RÃ©sumÃ© » dans la console

Si le titre du résumé s’affiche mal (mojibake), la console n’est pas en UTF-8. Tu peux avant le script :

```powershell
chcp 65001
```

Ou ignorer : **ce n’est que l’affichage**, pas la logique du bot ni le JSON final.

## Voir aussi

- `bot/browser_runner/README.md` — commandes `browser-*` et profils.  
- `bot/docs/AUTO_CYCLE_RUNNER.md` — boucle `run_auto_cycle.ps1`.
