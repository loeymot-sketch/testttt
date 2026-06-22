W7 live visual 2026-06-20 (admin :8766)
- Dashboard CLEAN: money FR (37 715,92 € / 49,45 € / 4,50 €), all labels resolved, no raw i18n keys, branding intact.
- soketi was DOWN mid-session → dashboard STILL loaded all data via REST = graceful degradation (WS-down→polling) PROVEN, no data loss. WS console errors = expected Echo retry, NOT a defect. Restarted soketi.
