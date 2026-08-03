#!/usr/bin/env bash
set -euo pipefail

if ls public/js/kiosk-admin*.js 2>/dev/null; then
    echo "ERROR: kiosk-admin bundle is forbidden in public/js/" >&2
    exit 1
fi

if [ -f resources/js/components/frontend/kiosk/KioskAdminComponent.vue ]; then
    echo "ERROR: KioskAdminComponent.vue source must remain deleted (D-KIOSK-03)" >&2
    exit 1
fi

echo "kiosk-admin lockdown OK"
