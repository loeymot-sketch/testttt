#!/usr/bin/env bash
# =============================================================================
# Runbook staging — migration SOURCE-FK (item_wizard_steps.source_item_attribute_id)
# Ne pas exécuter en CI sans validation humaine explicite.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

MIGRATION_REL="database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php"
VERIFICATION_DOC="reports/execution/RUN_SOURCE_FK_BACKFILL_VERIFICATION_2026-05-03.md"
BACKUP_DIR="${REPO_ROOT}/storage/backups/staging"

usage() {
    cat <<'EOF'
Usage: scripts/migrate-source-fk-staging.sh [options]

  (sans option)   Pré-flight, backup, dry-run artisan --pretend, pause, migrate --force,
                  puis confirmation post-flight (voir RUN_SOURCE_FK_BACKFILL_VERIFICATION_*.md).

  --dry-run       Arrêt après l’étape 3 (migrate --pretend), sans pause ni application réelle.

  --dry-run-help  Aide courte + sortie 0 (ne vérifie pas les variables d’environnement).

Variables d’environnement (obligatoires sauf avec --dry-run-help) :

  STAGING_DB_HOST       Hôte base staging
  STAGING_DB_NAME       Nom de la base
  STAGING_DB_USER       Utilisateur SQL
  STAGING_DB_PASSWORD   Mot de passe SQL

Optionnel :

  STAGING_DB_PORT       Port (défaut : 3306 pour mysql, 5432 pour pgsql)
  STAGING_DB_DRIVER     mysql | pgsql — pour pg_dump / mysqldump.
                        Si absent : lecture de DB_CONNECTION dans .env à la racine du dépôt.

Codes de sortie :

  0  Succès
  1  Variable d’environnement manquante ou driver inconnu
  2  Échec du backup
  3  Échec migration artisan
  4  Vérification post-flight non confirmée

Rollback (documenté dans le runbook) :

  php artisan migrate:rollback --path=database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php --force
EOF
}

if [[ "${1:-}" == "--dry-run-help" ]]; then
    usage
    exit 0
fi

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
fi

# -----------------------------------------------------------------------------
# 1. Pré-flight : variables requises
# -----------------------------------------------------------------------------
require_env() {
    local n="$1"
    if [[ -z "${!n:-}" ]]; then
        echo "ERREUR: variable requise absente : ${n}" >&2
        exit 1
    fi
}

require_env STAGING_DB_HOST
require_env STAGING_DB_NAME
require_env STAGING_DB_USER
require_env STAGING_DB_PASSWORD

STAGING_DB_DRIVER="${STAGING_DB_DRIVER:-}"
if [[ -z "${STAGING_DB_DRIVER}" ]] && [[ -f "${REPO_ROOT}/.env" ]]; then
    # shellcheck disable=SC2002
    line="$(grep -E '^[[:space:]]*DB_CONNECTION=' "${REPO_ROOT}/.env" | head -1 || true)"
    if [[ -n "${line}" ]]; then
        STAGING_DB_DRIVER="${line#*=}"
        STAGING_DB_DRIVER="${STAGING_DB_DRIVER//\"/}"
        STAGING_DB_DRIVER="${STAGING_DB_DRIVER//\'/}"
        STAGING_DB_DRIVER="$(echo "${STAGING_DB_DRIVER}" | tr -d '\r' | xargs)"
    fi
fi

case "${STAGING_DB_DRIVER}" in
    mysql|mariadb)
        STAGING_DB_DRIVER="mysql"
        ;;
    pgsql|postgres|postgresql)
        STAGING_DB_DRIVER="pgsql"
        ;;
    *)
        echo "ERREUR: STAGING_DB_DRIVER doit être mysql ou pgsql (ou DB_CONNECTION dans .env). Actuel: '${STAGING_DB_DRIVER:-vide}'" >&2
        exit 1
        ;;
esac

if [[ -z "${STAGING_DB_PORT:-}" ]]; then
    if [[ "${STAGING_DB_DRIVER}" == "mysql" ]]; then
        STAGING_DB_PORT="3306"
    else
        STAGING_DB_PORT="5432"
    fi
fi

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "${BACKUP_DIR}"
BACKUP_SQL="${BACKUP_DIR}/source-fk-pre-${TIMESTAMP}.sql"

# -----------------------------------------------------------------------------
# 2. Backup full DB staging
# -----------------------------------------------------------------------------
echo "Étape 2/6 : backup complet vers ${BACKUP_SQL}"
if [[ "${STAGING_DB_DRIVER}" == "mysql" ]]; then
    if ! MYSQL_PWD="${STAGING_DB_PASSWORD}" mysqldump \
        -h "${STAGING_DB_HOST}" \
        -P "${STAGING_DB_PORT}" \
        -u "${STAGING_DB_USER}" \
        --single-transaction \
        --routines \
        --triggers \
        "${STAGING_DB_NAME}" > "${BACKUP_SQL}"; then
        echo "ERREUR: mysqldump a échoué (code 2)" >&2
        exit 2
    fi
else
    if ! PGPASSWORD="${STAGING_DB_PASSWORD}" pg_dump \
        -h "${STAGING_DB_HOST}" \
        -p "${STAGING_DB_PORT}" \
        -U "${STAGING_DB_USER}" \
        -d "${STAGING_DB_NAME}" \
        -f "${BACKUP_SQL}"; then
        echo "ERREUR: pg_dump a échoué (code 2)" >&2
        exit 2
    fi
fi

# -----------------------------------------------------------------------------
# 3. Dry-run migration (artisan --pretend)
# -----------------------------------------------------------------------------
echo "Étape 3/6 : php artisan migrate --pretend --path=${MIGRATION_REL}"
if ! php artisan migrate --pretend --path="${MIGRATION_REL}"; then
    echo "ERREUR: migrate --pretend a échoué (code 3)" >&2
    exit 3
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "Mode --dry-run : arrêt après étape 3. Aucune migration appliquée."
    exit 0
fi

# -----------------------------------------------------------------------------
# 4. Pause manuelle
# -----------------------------------------------------------------------------
read -r -p "Appuyer sur ENTER pour appliquer la migration sur cette base staging " _

# -----------------------------------------------------------------------------
# 5. Application migration
# -----------------------------------------------------------------------------
echo "Étape 5/6 : php artisan migrate --force --path=${MIGRATION_REL}"
if ! php artisan migrate --path="${MIGRATION_REL}" --force; then
    echo "ERREUR: migrate --force a échoué (code 3)" >&2
    exit 3
fi

# -----------------------------------------------------------------------------
# 6. Post-flight — vérification documentée (α2 / rapport SQL manuel)
# -----------------------------------------------------------------------------
echo "Étape 6/6 : post-flight — exécuter les requêtes SQL décrites dans :"
echo "  ${VERIFICATION_DOC}"
echo "Critère : la requête #3 doit retourner 0 ligne (count / résultat vide), sauf justification."
read -r -p "Confirmer que les vérifications SQL post-migration sont OK (requis pour code 0) [y/N] " _ok
if [[ ! "${_ok}" =~ ^[yY]([eE][sS])?$ ]]; then
    echo "ERREUR: vérification post-flight non confirmée (code 4)" >&2
    exit 4
fi

echo "Terminé (code 0)."
exit 0
