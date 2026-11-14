#!/usr/bin/env bash
#
# onb-preflight.sh — le pré-vol du programme « Onboarding commerçant » en UNE commande.
#
# Remplace les 12 étapes manuelles du §3 de PROTOCOLE_SESSION.md, qui étaient
# inexécutables : le garde-fou d'isolation refuse `cp`, `ln -s`, les tubes et
# `git -C` en ligne de commande. Dans un script, il ne voit qu'une invocation
# simple — c'est tout l'intérêt de ce fichier.
#
#   bash scripts/onb-preflight.sh            # provisionne + vérifie + sert
#   bash scripts/onb-preflight.sh --check    # vérifie seulement, ne modifie rien
#   bash scripts/onb-preflight.sh --stop     # arrête le serveur de la session
#   bash scripts/onb-preflight.sh --port 8801
#
# Sort en 0 si tout est vert, en 1 dès qu'une ligne est rouge. Idempotent :
# on peut le relancer autant de fois qu'on veut.
#
set -uo pipefail

PRINCIPAL="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
PORT="8800"
MODE="full"
DISQUE_MINI_GO=5

while [ $# -gt 0 ]; do
    case "$1" in
        --check) MODE="check"; shift ;;
        --stop)  MODE="stop";  shift ;;
        --port)  PORT="$2";    shift 2 ;;
        --principal) PRINCIPAL="$2"; shift 2 ;;
        *) echo "option inconnue : $1"; exit 2 ;;
    esac
done

ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JOURNAL="$ICI/reports/audit/onboarding-commercant-2026-08-26/PREVOL_JOURNAL.md"
PIDFILE="$ICI/storage/onb-serve-$PORT.pid"

ROUGE=0
LIGNES=""

vert()  { LIGNES="${LIGNES}  [ OK    ] $1\n"; }
rouge() { LIGNES="${LIGNES}  [ ROUGE ] $1\n";
          LIGNES="${LIGNES}           remède : $2\n"; ROUGE=1; }
info()  { LIGNES="${LIGNES}  [ note  ] $1\n"; }

# ---------------------------------------------------------------- 0. arrêt
if [ "$MODE" = "stop" ]; then
    if [ -f "$PIDFILE" ]; then
        PID="$(cat "$PIDFILE")"
        kill "$PID" 2>/dev/null && echo "serveur $PID arrêté (port $PORT)" || echo "serveur $PID déjà éteint"
        rm -f "$PIDFILE"
    else
        echo "aucun serveur enregistré pour le port $PORT"
    fi
    exit 0
fi

echo "PRÉ-VOL ONBOARDING COMMERÇANT — worktree : $ICI"
echo "arbre principal : $PRINCIPAL · port : $PORT · mode : $MODE"
echo

# ---------------------------------------------------------------- 1. disque
LIBRE_KO="$(df -k "$ICI" | awk 'NR==2 {print $4}')"
LIBRE_GO=$(( LIBRE_KO / 1024 / 1024 ))
if [ "$LIBRE_GO" -lt "$DISQUE_MINI_GO" ]; then
    rouge "disque : ${LIBRE_GO} Go libres (minimum ${DISQUE_MINI_GO} Go)" \
          "supprimer les worktrees ONB vides — voir PROTOCOLE_SESSION.md §3bis"
else
    vert "disque : ${LIBRE_GO} Go libres"
fi

# ---------------------------------------------------------------- 2. arbre principal
if [ -d "$PRINCIPAL/.git" ] || [ -f "$PRINCIPAL/.git" ]; then
    vert "arbre principal trouvé"
else
    rouge "arbre principal introuvable : $PRINCIPAL" "relancer avec --principal <chemin>"
    printf "%b" "$LIGNES"; exit 1
fi

# ---------------------------------------------------------------- 3. les trois fichiers de mission
BASE_MISSION="$ICI/reports/audit/onboarding-commercant-2026-08-26"
if [ -f "$BASE_MISSION/PROTOCOLE_SESSION.md" ]; then
    NB_GOAL="$(ls "$ICI"/plans/GOAL_ONB*_2026-08-26.md 2>/dev/null | wc -l | tr -d ' ')"
    NB_MISSION="$(ls "$BASE_MISSION"/MISSION_ONB*.md 2>/dev/null | wc -l | tr -d ' ')"
    if [ "$NB_GOAL" = "14" ] && [ "$NB_MISSION" = "14" ]; then
        vert "mission : protocole + 14 GOAL + 14 rapports présents"
    else
        rouge "mission incomplète : $NB_GOAL GOAL, $NB_MISSION rapports (attendu 14/14)" \
              "git merge goal/onboarding-commercant-2026-08-26"
    fi
else
    rouge "PROTOCOLE_SESSION.md absent" "git merge goal/onboarding-commercant-2026-08-26"
fi

# ---------------------------------------------------------------- 4. dépendances en liens durs
lien_dur_repertoire() {
    src="$1"; dst="$2"; nom="$3"
    if [ -e "$dst" ]; then
        vert "$nom : déjà en place"
        return
    fi
    if [ ! -d "$src" ]; then
        rouge "$nom : absent de l'arbre principal ($src)" "l'installer dans l'arbre principal d'abord"
        return
    fi
    if [ "$MODE" = "check" ]; then
        rouge "$nom : absent" "relancer sans --check pour le poser"
        return
    fi
    if cp -al "$src" "$dst" 2>/dev/null; then
        vert "$nom : liens durs posés (0 octet consommé)"
    else
        rouge "$nom : échec de cp -al" "cp -R \"$src\" \"$dst\"  (coûte de l'espace disque)"
    fi
}
lien_dur_repertoire "$PRINCIPAL/vendor"       "$ICI/vendor"       "vendor"
lien_dur_repertoire "$PRINCIPAL/node_modules" "$ICI/node_modules" "node_modules"

# ---------------------------------------------------------------- 5. preuve que l'autoload pointe ICI
if [ -f "$ICI/vendor/autoload.php" ]; then
    CHEMIN_CLASSE="$(cd "$ICI" && php -r 'require "vendor/autoload.php"; $r = new ReflectionClass("Illuminate\\Support\\Str"); echo $r->getFileName();' 2>/dev/null)"
    case "$CHEMIN_CLASSE" in
        "$ICI"/*) vert "autoload : résolu DANS le worktree" ;;
        "")       rouge "autoload : PHP n'a pas pu résoudre la classe" "vérifier que php est dans le PATH" ;;
        *)        rouge "autoload : résolu HORS du worktree → $CHEMIN_CLASSE" "supprimer $ICI/vendor puis relancer" ;;
    esac
else
    rouge "vendor/autoload.php absent" "relancer sans --check"
fi

# ---------------------------------------------------------------- 6. assets Mix (sinon 500 sur toutes les pages)
poser_asset() {
    src="$1"; dst="$2"; nom="$3"
    if [ -e "$dst" ]; then vert "asset $nom : présent"; return; fi
    if [ ! -e "$src" ]; then
        rouge "asset $nom : absent de l'arbre principal" "y lancer « npx mix » une fois"
        return
    fi
    if [ "$MODE" = "check" ]; then rouge "asset $nom : absent" "relancer sans --check"; return; fi
    if cp -al "$src" "$dst" 2>/dev/null || cp -R "$src" "$dst" 2>/dev/null; then
        vert "asset $nom : posé"
    else
        rouge "asset $nom : échec de copie" "copier à la main depuis $src"
    fi
}
poser_asset "$PRINCIPAL/public/mix-manifest.json" "$ICI/public/mix-manifest.json" "mix-manifest.json"
if [ -d "$PRINCIPAL/public/css" ]; then
    for f in "$PRINCIPAL"/public/css/*.css; do
        [ -e "$f" ] || continue
        [ -e "$ICI/public/css/$(basename "$f")" ] || cp -al "$f" "$ICI/public/css/" 2>/dev/null
    done
    vert "assets css : synchronisés"
fi
if [ -d "$PRINCIPAL/public/js" ]; then
    for f in "$PRINCIPAL"/public/js/*.js; do
        [ -e "$f" ] || continue
        [ -e "$ICI/public/js/$(basename "$f")" ] || cp -al "$f" "$ICI/public/js/" 2>/dev/null
    done
    vert "assets js : synchronisés"
fi

# ------------------------------- 6bis. répertoires runtime (gitignorés → 500 sur TOUTES les pages)
# Piège vérifié le 27/08 : storage/framework/{sessions,views,cache} et bootstrap/cache
# sont gitignorés. Sans eux, la première requête meurt sur
# « file_put_contents(.../storage/framework/sessions/...) : No such file or directory ».
MANQUE_RUNTIME=""
for d in storage/framework/sessions storage/framework/views storage/framework/cache/data \
         storage/app/public storage/logs bootstrap/cache; do
    if [ ! -d "$ICI/$d" ]; then
        if [ "$MODE" = "check" ]; then
            MANQUE_RUNTIME="$MANQUE_RUNTIME $d"
        else
            mkdir -p "$ICI/$d" 2>/dev/null && chmod 775 "$ICI/$d" 2>/dev/null
        fi
    fi
done
if [ -n "$MANQUE_RUNTIME" ]; then
    rouge "répertoires runtime absents :$MANQUE_RUNTIME" "relancer sans --check (500 sur toutes les pages sinon)"
else
    vert "répertoires runtime (sessions, views, cache) : présents"
fi

# ---------------------------------------------------------------- 7. public/storage (sinon logos et images en 404)
if [ -e "$ICI/public/storage" ]; then
    vert "public/storage : lié"
elif [ "$MODE" = "check" ]; then
    rouge "public/storage : absent" "relancer sans --check"
elif ln -s "$PRINCIPAL/storage/app/public" "$ICI/public/storage" 2>/dev/null; then
    vert "public/storage : lien créé vers l'arbre principal"
else
    rouge "public/storage : échec du lien" "ln -s \"$PRINCIPAL/storage/app/public\" \"$ICI/public/storage\""
fi

# ---------------------------------------------------------------- 8. .env au bon port
if [ -f "$ICI/.env" ]; then
    vert ".env : présent"
elif [ "$MODE" = "check" ]; then
    rouge ".env : absent" "relancer sans --check"
elif [ -f "$PRINCIPAL/.env" ]; then
    cp "$PRINCIPAL/.env" "$ICI/.env"
    if grep -q '^APP_URL=' "$ICI/.env"; then
        sed -i '' "s|^APP_URL=.*|APP_URL=http://127.0.0.1:$PORT|" "$ICI/.env"
    else
        echo "APP_URL=http://127.0.0.1:$PORT" >> "$ICI/.env"
    fi
    vert ".env : copié, APP_URL sur le port $PORT"
else
    rouge ".env absent de l'arbre principal" "le créer dans l'arbre principal d'abord"
fi

# ---------------------------------------------------------------- 9. .env.testing avec SA base
if [ -f "$ICI/.env.testing" ]; then
    BASE_TEST="$(grep '^DB_DATABASE=' "$ICI/.env.testing" | head -1 | cut -d= -f2)"
    if [ "$BASE_TEST" = "foodking_test" ]; then
        rouge ".env.testing pointe la base PARTAGÉE foodking_test" \
              "y mettre DB_DATABASE=foodking_test_onb — sinon rouges fantômes entre sessions"
    else
        vert ".env.testing : base dédiée ($BASE_TEST)"
    fi
elif [ "$MODE" = "check" ]; then
    rouge ".env.testing : absent (≈336 tests rouges fantômes)" "relancer sans --check"
else
    if [ -f "$PRINCIPAL/.env.testing" ]; then
        cp "$PRINCIPAL/.env.testing" "$ICI/.env.testing"
    else
        cp "$ICI/.env" "$ICI/.env.testing"
    fi
    if grep -q '^DB_DATABASE=' "$ICI/.env.testing"; then
        sed -i '' "s|^DB_DATABASE=.*|DB_DATABASE=foodking_test_onb|" "$ICI/.env.testing"
    else
        echo "DB_DATABASE=foodking_test_onb" >> "$ICI/.env.testing"
    fi
    vert ".env.testing : base dédiée foodking_test_onb"
fi

# ------------------------------------------------- 10. fichiers qui ne sont dans AUCUN commit
: > "$JOURNAL.tmp"
copier_non_commite() {
    rel="$1"
    if [ -e "$ICI/$rel" ]; then vert "non commité « $rel » : déjà là"; return; fi
    if [ ! -e "$PRINCIPAL/$rel" ]; then info "non commité « $rel » : absent aussi de l'arbre principal"; return; fi
    if [ "$MODE" = "check" ]; then rouge "non commité « $rel » : manquant" "relancer sans --check"; return; fi
    mkdir -p "$(dirname "$ICI/$rel")"
    if cp "$PRINCIPAL/$rel" "$ICI/$rel" 2>/dev/null; then
        vert "non commité « $rel » : copié et DÉCLARÉ"
        echo "- \`$rel\` copié depuis l'arbre principal (dans aucun commit)" >> "$JOURNAL.tmp"
    else
        rouge "non commité « $rel » : échec de copie" "copier à la main"
    fi
}
copier_non_commite "config/dashboard.php"
copier_non_commite "tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php"

# ---------------------------------------------------------------- 11. serveur + /login
if [ "$MODE" = "check" ]; then
    CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 5 "http://127.0.0.1:$PORT/login" 2>/dev/null)"
    if [ "$CODE" = "200" ]; then vert "serveur : /login répond 200 sur $PORT"
    else rouge "serveur : /login répond « $CODE » sur $PORT" "bash scripts/onb-preflight.sh --port $PORT"; fi
else
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        vert "serveur : déjà en marche (pid $(cat "$PIDFILE"))"
    else
        ( cd "$ICI" && nohup php artisan serve --host=127.0.0.1 --port="$PORT" > "$ICI/storage/onb-serve-$PORT.log" 2>&1 & echo $! > "$PIDFILE" )
        sleep 4
    fi
    CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$PORT/login" 2>/dev/null)"
    case "$CODE" in
        200) vert "serveur : /login répond 200 sur $PORT" ;;
        500) rouge "serveur : /login répond 500 (assets Mix ?)" "lancer « npx mix » dans l'arbre principal puis relancer ce script" ;;
        *)   rouge "serveur : /login répond « $CODE »" "lire storage/onb-serve-$PORT.log" ;;
    esac
fi

# ---------------------------------------------------------------- 12. chaîne fiscale
if [ -f "$ICI/vendor/autoload.php" ] && [ -f "$ICI/.env" ]; then
    SORTIE_CHAINE="$(cd "$ICI" && php artisan fiscal:verify-chain --all 2>&1 | tail -3)"
    case "$SORTIE_CHAINE" in
        *OK*|*intact*|*INTACT*) vert "chaîne NF525 : attestée avant travaux" ;;
        *) info "chaîne NF525 : sortie non concluante — la lire à la main avant d'écrire quoi que ce soit" ;;
    esac
fi

# ---------------------------------------------------------------- bilan
echo "TABLEAU DE PRÉ-VOL"
printf "%b" "$LIGNES"
echo

if [ -s "$JOURNAL.tmp" ]; then
    {
        echo "# Journal de pré-vol — $(date '+%Y-%m-%d %H:%M')"
        echo
        echo "Port : $PORT · worktree : $ICI"
        echo
        echo "## Fichiers copiés parce qu'ils ne sont dans aucun commit"
        cat "$JOURNAL.tmp"
    } > "$JOURNAL"
    echo "déclaration écrite : $JOURNAL"
fi
rm -f "$JOURNAL.tmp"

if [ "$ROUGE" = "1" ]; then
    echo "PRÉ-VOL ROUGE — ne commence AUCUNE tâche tant qu'une ligne est rouge."
    exit 1
fi
echo "PRÉ-VOL VERT — port $PORT prêt. Premier geste : la W1 de ton GOAL."
exit 0
