#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# KDS poll-fallback tune — 2026-07-14 (owner-autorisé)
# Prod tourne en BROADCAST_DRIVER=log (pas de temps-réel WS) → le KDS détecte les
# nouvelles commandes par POLLING. Défaut disconnected = 10 s → ticket cuisine ~10 s
# après la commande. On baisse à ~4 s (haute activité ~2 s) pour rapprocher de
# l'« impression directe » SANS daemon temps-réel. RÉVERSIBLE (retirer les 2 lignes .env).
# Pour du VRAIMENT instant → activer reverb/soketi (BROADCAST_DRIVER + daemon).
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
echo "==> Tune du poll KDS sur le VPS…"
ssh lecayenne "cd /var/www/lecayenne && \
  echo '== avant : '\$(grep -E '^FK_CATALOG_KDS_DISCONNECTED_BASE_MS=' .env || echo 'non défini (=10000 par défaut)') && \
  ( grep -q '^FK_CATALOG_KDS_DISCONNECTED_BASE_MS=' .env && sed -i 's/^FK_CATALOG_KDS_DISCONNECTED_BASE_MS=.*/FK_CATALOG_KDS_DISCONNECTED_BASE_MS=4000/' .env || echo 'FK_CATALOG_KDS_DISCONNECTED_BASE_MS=4000' >> .env ) && \
  ( grep -q '^FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS=' .env && sed -i 's/^FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS=.*/FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS=2000/' .env || echo 'FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS=2000' >> .env ) && \
  echo '== apres : '\$(grep -E '^FK_CATALOG_KDS_DISCONNECTED_BASE_MS=|^FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS=' .env | tr '\n' ' ') && \
  php artisan config:clear >/dev/null && php artisan config:cache >/dev/null && echo 'config:cache OK' && \
  echo '== valeur exposee au front (doit etre 4000) ==' && curl -s --max-time 6 -k https://vps-418872ac.vps.ovh.net/kds 2>/dev/null | grep -oE 'disconnectedBaseMs[\":= ]+[0-9]+|highActivityBaseMs[\":= ]+[0-9]+' | head -3"
rc=$?
[ $rc -ne 0 ] && { echo "Échec (code $rc)."; exit $rc; }
echo "==> Terminé. Ticket cuisine désormais ~2-4 s après la commande (poll). Réversible via .env."
