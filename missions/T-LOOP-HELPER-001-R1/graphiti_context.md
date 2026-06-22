# Graphiti facts (REMEDIATION round 1)

- Previous round produced a helper that rejects 0.30000004 incorrectly.
- bug_signature: tests/js/posCentsArith.spec.js::"converts floating-point imprecision like 0.30000004 to 30 cents"
- root_cause: tolerance EPSILON=1e-6 compared against (amount*100 - Math.round((amount+EPSILON)*100)) which is ~4e-3 for 0.30000004 — comparison scale mismatch.
- Constraint preserved: 0.345 must still throw (genuine 3-decimal).
