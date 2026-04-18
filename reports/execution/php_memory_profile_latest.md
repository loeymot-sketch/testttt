# PHP Memory Profile

**Date**: 2026-03-31 06:26:29

## Batch Results

### auth-security

- Status: PASS
- Duration seconds: 0
- Command: `php -d memory_limit=512M scripts/run_php_feature_batches.sh auth-security`

    set -euo pipefail
    
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"
    
    AUTH_SECURITY=(
      "tests/Unit/Http/Resources/ItemCategoryResourceTest.php"
      "tests/Unit/Rules/ValidJsonOrderTest.php"
      "tests/Unit/Services/FrontendOrderServiceTest.php"
      "tests/Unit/Services/OrderServiceSecurityTest.php"
      "tests/Feature/AuthComprehensiveTest.php"
      "tests/Feature/AddressSecurityTest.php"
      "tests/Feature/BranchIsolationTest.php"
      "tests/Feature/BranchScopeTest.php"
      "tests/Feature/CouponSecurityTest.php"
      "tests/Feature/KDSScopeRestrictionTest.php"
      "tests/Feature/KioskAuthTest.php"
      "tests/Feature/KioskLoginApiTest.php"
      "tests/Feature/KioskScopeIsolationTest.php"
      "tests/Feature/KioskSecurityTest.php"
      "tests/Feature/LoyaltyApiTest.php"
      "tests/Feature/OSSReadOnlyTest.php"
      "tests/Feature/PricingIntegrityTest.php"
      "tests/Feature/SecurityComprehensiveTest.php"
      "tests/Feature/TableOrderSecurityTest.php"
    )
    
    KIOSK_POS_SYNC=(
      "tests/Feature/AntiGravityFinalTest.php"
      "tests/Feature/AntiGravityLoginRedirectionTest.php"
      "tests/Feature/AntiGravityManualTest.php"
      "tests/Feature/AntiGravityTest.php"
      "tests/Feature/FrontendDiscountIntegrityTest.php"
      "tests/Feature/KDSFlowTest.php"
      "tests/Feature/KDSOrderItemsTest.php"
      "tests/Feature/KioskEventTest.php"
      "tests/Feature/KioskFrontendComprehensiveTest.php"
      "tests/Feature/KioskPaymentStateMachineTest.php"
      "tests/Feature/KioskUpsellCategoryTest.php"
      "tests/Feature/KitchenDisplaySystemOrderSortTest.php"
      "tests/Feature/OrderFlowTest.php"
      "tests/Feature/OrderStateTransitionTest.php"
      "tests/Feature/POSComprehensiveTest.php"
      "tests/Feature/PosDiscountTest.php"
      "tests/Feature/PosOrderTaxTest.php"
      "tests/Feature/PosPriorityApiTest.php"
      "tests/Feature/PosUITest.php"
      "tests/Feature/SyncComprehensiveTest.php"
      "tests/Feature/UpsellApiTest.php"
    )
    
    ADMIN_SEEDERS_REPORTS=(
      "tests/Feature/AdminCrudComprehensiveTest.php"
      "tests/Feature/ItemExtraManagementTest.php"
      "tests/Feature/MenuSeederTest.php"
    )
    
    run_batch() {
      local name="$1"
      shift
      echo
      echo "=== Running batch: ${name} ==="
      local test_file
      for test_file in "$@"; do
        echo "--- ${test_file} ---"
        php -d memory_limit=512M artisan test "${test_file}"
      done
    }
    
    case "${1:-all}" in
      auth-security)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        ;;
      kiosk-pos-sync)
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        ;;
      admin-seeders-reports)
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      all)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      *)
        echo "Usage: $0 [auth-security|kiosk-pos-sync|admin-seeders-reports|all]" >&2
        exit 1
        ;;
    esac

### kiosk-pos-sync

- Status: PASS
- Duration seconds: 0
- Command: `php -d memory_limit=512M scripts/run_php_feature_batches.sh kiosk-pos-sync`

    set -euo pipefail
    
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"
    
    AUTH_SECURITY=(
      "tests/Unit/Http/Resources/ItemCategoryResourceTest.php"
      "tests/Unit/Rules/ValidJsonOrderTest.php"
      "tests/Unit/Services/FrontendOrderServiceTest.php"
      "tests/Unit/Services/OrderServiceSecurityTest.php"
      "tests/Feature/AuthComprehensiveTest.php"
      "tests/Feature/AddressSecurityTest.php"
      "tests/Feature/BranchIsolationTest.php"
      "tests/Feature/BranchScopeTest.php"
      "tests/Feature/CouponSecurityTest.php"
      "tests/Feature/KDSScopeRestrictionTest.php"
      "tests/Feature/KioskAuthTest.php"
      "tests/Feature/KioskLoginApiTest.php"
      "tests/Feature/KioskScopeIsolationTest.php"
      "tests/Feature/KioskSecurityTest.php"
      "tests/Feature/LoyaltyApiTest.php"
      "tests/Feature/OSSReadOnlyTest.php"
      "tests/Feature/PricingIntegrityTest.php"
      "tests/Feature/SecurityComprehensiveTest.php"
      "tests/Feature/TableOrderSecurityTest.php"
    )
    
    KIOSK_POS_SYNC=(
      "tests/Feature/AntiGravityFinalTest.php"
      "tests/Feature/AntiGravityLoginRedirectionTest.php"
      "tests/Feature/AntiGravityManualTest.php"
      "tests/Feature/AntiGravityTest.php"
      "tests/Feature/FrontendDiscountIntegrityTest.php"
      "tests/Feature/KDSFlowTest.php"
      "tests/Feature/KDSOrderItemsTest.php"
      "tests/Feature/KioskEventTest.php"
      "tests/Feature/KioskFrontendComprehensiveTest.php"
      "tests/Feature/KioskPaymentStateMachineTest.php"
      "tests/Feature/KioskUpsellCategoryTest.php"
      "tests/Feature/KitchenDisplaySystemOrderSortTest.php"
      "tests/Feature/OrderFlowTest.php"
      "tests/Feature/OrderStateTransitionTest.php"
      "tests/Feature/POSComprehensiveTest.php"
      "tests/Feature/PosDiscountTest.php"
      "tests/Feature/PosOrderTaxTest.php"
      "tests/Feature/PosPriorityApiTest.php"
      "tests/Feature/PosUITest.php"
      "tests/Feature/SyncComprehensiveTest.php"
      "tests/Feature/UpsellApiTest.php"
    )
    
    ADMIN_SEEDERS_REPORTS=(
      "tests/Feature/AdminCrudComprehensiveTest.php"
      "tests/Feature/ItemExtraManagementTest.php"
      "tests/Feature/MenuSeederTest.php"
    )
    
    run_batch() {
      local name="$1"
      shift
      echo
      echo "=== Running batch: ${name} ==="
      local test_file
      for test_file in "$@"; do
        echo "--- ${test_file} ---"
        php -d memory_limit=512M artisan test "${test_file}"
      done
    }
    
    case "${1:-all}" in
      auth-security)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        ;;
      kiosk-pos-sync)
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        ;;
      admin-seeders-reports)
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      all)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      *)
        echo "Usage: $0 [auth-security|kiosk-pos-sync|admin-seeders-reports|all]" >&2
        exit 1
        ;;
    esac

### admin-seeders-reports

- Status: PASS
- Duration seconds: 0
- Command: `php -d memory_limit=512M scripts/run_php_feature_batches.sh admin-seeders-reports`

    set -euo pipefail
    
    ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$ROOT_DIR"
    
    AUTH_SECURITY=(
      "tests/Unit/Http/Resources/ItemCategoryResourceTest.php"
      "tests/Unit/Rules/ValidJsonOrderTest.php"
      "tests/Unit/Services/FrontendOrderServiceTest.php"
      "tests/Unit/Services/OrderServiceSecurityTest.php"
      "tests/Feature/AuthComprehensiveTest.php"
      "tests/Feature/AddressSecurityTest.php"
      "tests/Feature/BranchIsolationTest.php"
      "tests/Feature/BranchScopeTest.php"
      "tests/Feature/CouponSecurityTest.php"
      "tests/Feature/KDSScopeRestrictionTest.php"
      "tests/Feature/KioskAuthTest.php"
      "tests/Feature/KioskLoginApiTest.php"
      "tests/Feature/KioskScopeIsolationTest.php"
      "tests/Feature/KioskSecurityTest.php"
      "tests/Feature/LoyaltyApiTest.php"
      "tests/Feature/OSSReadOnlyTest.php"
      "tests/Feature/PricingIntegrityTest.php"
      "tests/Feature/SecurityComprehensiveTest.php"
      "tests/Feature/TableOrderSecurityTest.php"
    )
    
    KIOSK_POS_SYNC=(
      "tests/Feature/AntiGravityFinalTest.php"
      "tests/Feature/AntiGravityLoginRedirectionTest.php"
      "tests/Feature/AntiGravityManualTest.php"
      "tests/Feature/AntiGravityTest.php"
      "tests/Feature/FrontendDiscountIntegrityTest.php"
      "tests/Feature/KDSFlowTest.php"
      "tests/Feature/KDSOrderItemsTest.php"
      "tests/Feature/KioskEventTest.php"
      "tests/Feature/KioskFrontendComprehensiveTest.php"
      "tests/Feature/KioskPaymentStateMachineTest.php"
      "tests/Feature/KioskUpsellCategoryTest.php"
      "tests/Feature/KitchenDisplaySystemOrderSortTest.php"
      "tests/Feature/OrderFlowTest.php"
      "tests/Feature/OrderStateTransitionTest.php"
      "tests/Feature/POSComprehensiveTest.php"
      "tests/Feature/PosDiscountTest.php"
      "tests/Feature/PosOrderTaxTest.php"
      "tests/Feature/PosPriorityApiTest.php"
      "tests/Feature/PosUITest.php"
      "tests/Feature/SyncComprehensiveTest.php"
      "tests/Feature/UpsellApiTest.php"
    )
    
    ADMIN_SEEDERS_REPORTS=(
      "tests/Feature/AdminCrudComprehensiveTest.php"
      "tests/Feature/ItemExtraManagementTest.php"
      "tests/Feature/MenuSeederTest.php"
    )
    
    run_batch() {
      local name="$1"
      shift
      echo
      echo "=== Running batch: ${name} ==="
      local test_file
      for test_file in "$@"; do
        echo "--- ${test_file} ---"
        php -d memory_limit=512M artisan test "${test_file}"
      done
    }
    
    case "${1:-all}" in
      auth-security)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        ;;
      kiosk-pos-sync)
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        ;;
      admin-seeders-reports)
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      all)
        run_batch "auth-security" "${AUTH_SECURITY[@]}"
        run_batch "kiosk-pos-sync" "${KIOSK_POS_SYNC[@]}"
        run_batch "admin-seeders-reports" "${ADMIN_SEEDERS_REPORTS[@]}"
        ;;
      *)
        echo "Usage: $0 [auth-security|kiosk-pos-sync|admin-seeders-reports|all]" >&2
        exit 1
        ;;
    esac

