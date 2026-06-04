#!/usr/bin/env bash
# [Pre-commit secret check 2026-05-29] FoodKing security hygiene
#
# Source: /insights 2026-05-29 — Claude accidentally committed .env with
# live AWS keys during autonomous V1 work session. This hook prevents
# recurrence by blocking commits that include .env files or files matching
# secret regex patterns.
#
# Wire via: ln -s "$(pwd)/.cursor/hooks/pre-commit-secret-check.sh" \
#          .git/hooks/pre-commit
# OR add to .claude/settings.json PreToolUse hook for Bash matching git commit.

set -e

EXIT_OK=0
EXIT_BLOCKED=1

# Get list of staged files
staged_files=$(git diff --cached --name-only --diff-filter=ACM)

if [ -z "$staged_files" ]; then
  exit $EXIT_OK
fi

violations=()

# Block 1 — .env files (except .env.example template)
while IFS= read -r f; do
  case "$f" in
    .env|.env.local|.env.production|.env.staging|*.env)
      violations+=("BLOCKED: $f (contains likely secrets)")
      ;;
    .env.example|.env.template)
      # whitelist templates
      ;;
  esac
done <<< "$staged_files"

# Block 2 — credential files
while IFS= read -r f; do
  case "$f" in
    *.key|*.pem|*.p12|*.pfx|credentials.json|secrets.json|*-credentials.json|service-account*.json)
      violations+=("BLOCKED: $f (credential file)")
      ;;
  esac
done <<< "$staged_files"

# Block 3 — backup files
while IFS= read -r f; do
  case "$f" in
    storage/backups/db-daily/*.sql.gz|storage/backups/db-daily/*.sql)
      violations+=("BLOCKED: $f (local backup, gitignore violation)")
      ;;
  esac
done <<< "$staged_files"

# Block 4 — grep contents for secret patterns
secret_patterns=(
  'AWS_SECRET_ACCESS_KEY\s*=\s*["A-Za-z0-9/+]{40,}'
  'aws_secret_access_key\s*=\s*["A-Za-z0-9/+]{40,}'
  'AKIA[0-9A-Z]{16}'
  'STRIPE_SECRET_KEY\s*=\s*"?sk_(live|test)_[A-Za-z0-9]{20,}'
  'sk_live_[A-Za-z0-9]{20,}'
  'sk_test_[A-Za-z0-9]{20,}'
  '-----BEGIN (RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----'
  'GITHUB_TOKEN\s*=\s*"?ghp_[A-Za-z0-9]{36}'
  'ghp_[A-Za-z0-9]{36}'
  'xox[baprs]-[A-Za-z0-9-]{10,}'
)

while IFS= read -r f; do
  # Skip non-text files
  [ -f "$f" ] || continue
  case "$f" in
    *.png|*.jpg|*.jpeg|*.gif|*.pdf|*.zip|*.tar|*.gz|*.bin|node_modules/*|vendor/*)
      continue
      ;;
  esac
  for pattern in "${secret_patterns[@]}"; do
    if git diff --cached "$f" 2>/dev/null | grep -E "$pattern" > /dev/null 2>&1; then
      violations+=("BLOCKED: $f contains secret pattern '$pattern'")
      break
    fi
  done
done <<< "$staged_files"

# Block 5 — frozen-zone touch detection
frozen_files=(
  "public/js/pos-wizard.js"
  "public/css/pos-wizard.css"
  "resources/views/admin-pos-v4.blade.php"
  "resources/js/components/frontend/kiosk/KioskWizardComponent.vue"
  "resources/js/components/frontend/kiosk/KioskAppComponent.vue"
  "resources/js/components/frontend/kiosk/KioskUpsellComponent.vue"
  "app/Services/Fiscal/FiscalSequenceService.php"
  "app/Services/Fiscal/ZReportService.php"
  "app/Services/Fiscal/AuditLogService.php"
  "app/Models/Scopes/BranchScope.php"
  "app/Http/Middleware/IdempotencyKeyMiddleware.php"
  "app/Services/Pricing/PricingService.php"
  "app/Domain/Order/OrderStateMachine.php"
)

for frozen in "${frozen_files[@]}"; do
  if echo "$staged_files" | grep -qF "$frozen"; then
    # Allow only if commit message has LOCK_*.md citation
    last_msg=$(git log -1 --format=%B 2>/dev/null || echo "")
    if ! echo "$last_msg" | grep -qE "LOCK_[A-Z0-9_]+\.md|frozen-override"; then
      violations+=("BLOCKED: $frozen is frozen-zone §7 (CLAUDE.md). Use lock-plan skill + owner countersign")
    fi
  fi
done

# Report violations
if [ ${#violations[@]} -gt 0 ]; then
  echo ""
  echo "════════════════════════════════════════════════════════════════"
  echo "  PRE-COMMIT BLOCKED — Security/Frozen-zone violations:"
  echo "════════════════════════════════════════════════════════════════"
  for v in "${violations[@]}"; do
    echo "  ✗ $v"
  done
  echo ""
  echo "Fix:"
  echo "  - Unstage offending files: git restore --staged <file>"
  echo "  - For .env: ensure .gitignore covers it"
  echo "  - For frozen-zone: invoke /lock-plan skill + get owner approval"
  echo "  - To bypass (DANGER): git commit --no-verify (owner explicit OK only)"
  echo ""
  exit $EXIT_BLOCKED
fi

# All clear
exit $EXIT_OK
