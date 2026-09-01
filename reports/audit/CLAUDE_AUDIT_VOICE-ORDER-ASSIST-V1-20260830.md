# Audit Claude terminal — VOICE-ORDER-ASSIST-V1-20260830

## Canal

- AUDIT_CHANNEL: claude-terminal
- MODEL: claude-opus-4-7
- EFFORT: high
- TERMINAL_AUDIT_OK: 1
- Première passe code exhaustive interrompue après silence prolongé ; reprise bornée aux fichiers allowlist réussie.

## Invariants

- PASS — gateway `branch_id` dérivé de la configuration après HMAC ; body `branch_id` rejeté.
- PASS — branche admin concrète requise ; cache, transcript et lien explicitement branch-scoped.
- PASS — HMAC SHA-256 constant-time, tolérance temporelle et réservation atomique anti-rejeu.
- PASS — aucun socket RTP/Deepgram avant consentement ; aucun stockage audio.
- PASS — aucun prix assistant, aucune soumission autonome ; lien seulement après la création téléphone existante.
- PASS — lien same-branch, phone/deferred-only, transactionnel, idempotent et non réassignable.
- PASS — fermeture Flux ordonnée `ForceEndTurn` puis `CloseStream`.
- PASS — allowlist respectée, hunk propriétaire routes préservé, frozen zones intactes.

## Findings

- P3 efficiency — polling consentement à 750 ms peut approcher le throttle avec quatre appels simultanés ; non bloquant pour la ligne V1 et choix cohérent avec la priorité latence.
- P3 PII — la redaction facultative OpenAI couvre téléphone/e-mail mais ne garantit pas le retrait des noms/adresses dictés ; risque déjà documenté et fonctionnalité désactivée par défaut.
- P3 robustness — timestamp Unix limité à 10 chiffres, horizon théorique 2286.

## Verdict

AUDIT_VERDICT: PASS
