# Graphiti Context — CV1-M11-KIOSK-RUNTIME

- FoodKing covers customer order terminal / Kiosk as a business surface implemented under `resources/js/components/frontend/kiosk/`.
- Existing memory says Kiosk has offline mode and queues orders during outages; M11 narrows that behavior under signed Caisse V1 gates.
- Existing memory says kiosk tokens are restricted to the branch of the `KioskMachine`; do not weaken branch resolution.
- Signed gates for this mission: offline Option A (read-only/offline payment refusal) and fiscal kiosk Option B (POS finalizes).
