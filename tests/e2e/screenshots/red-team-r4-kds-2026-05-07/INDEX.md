# RED TEAM R4 — KDS RÉCEPTION (2026-05-07)

Total findings: 17
Par sévérité: {"OK":11,"P2":5,"P1":1}

## Findings
- **R4-00** [OK] seed-reality → probed | seed reality probed: {"chef":{"id":17,"branch_id":1},"pos":{"id":16,"branch_id":1},"branches_count":1,"non_branch1_users":1}
- **R4-01** [OK] kd10-rollback-static → verified | KD10 réfuté par construction — 3 verrous statiques convergents
- **R4-02** [OK] kd4-double-click-static → verified | KD4 verrouillé : double-clic = 1×202 + 1×409 par construction. Test runtime confirme R4-09.
- **R4-03** [OK] kd11-audit-log-static → verified | KD11 satisfait : audit row écrite pour TOUTE transition forward. Pas de runtime drilldown nécessaire.
- **R4-04** [OK] kd12-branch-iso-static → verified | KD12 verrouillé statiquement. Runtime non drivable : seed = {"non_branch1_users":1,"branches":1} (1 branche, pas de cross-branch user). Limitation honnête déclarée.
- **R4-05** [OK] kds-surface → loaded | Surface KDS : fatal=false, console critical=0, page errors=0
- **R4-06** [OK] a11y-axe → analyzed | axe : 0 violations dont critical+serious=0
- **R4-07** [P2] kd1-kd2-aria → probed | KD1: 8 cartes potentielles sans role="article". KD2: 0 live regions (banners only — pas de live region dédiée aux transitions de statut).
- **R4-08** [P1] kd5-sound-watcher-bug → static-confirmed | KD5 BUG CONFIRMÉ STATIC : watcher resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:921-929 ne déclenche son que si LENGTH grandit. Si +1 ACCEPT entrant et -1 PREPAR
- **R4-09** [OK] kd4-race-409 → runtime-confirmed | Concurrent change-status race : codes=[202,409] (expect 202+409). Final DB status=7 (7=PREPARING expected). Audit transitions=[{"from_status":4,"to_status":7,"actor_id":17}]
- **R4-10** [OK] kd6-cancel-removal → removed | Ticket #R4CANCEL-636d8a cancel → still visible after 7s polling: false. Cancel result: {"ok":true,"final_status":16}.
- **R4-11** [P2] kd9-86-distinction → probed | KD9 : KDS écoute ItemAvailabilityChanged (KitchenDisplaySystemComponent.vue:1268) MAIS le handler appelle uniquement _debouncedRefresh. Pas de badge 86 visible côté KDS si l'item devient OOS pendant l
- **R4-12** [OK] ws-heartbeat → probed | Banner mode secours : present=true. WS state=false. Si pas de serveur Pusher démarré (vérifié via ps aux : aucun) ET banner absent ⇒ KD8 heartbeat manquant. cf. KitchenDisplaySystemComponent.vue:4 v-i
- **R4-13** [P2] kd3-keyboard-focus → probed | KD3 focus inter-tickets : 0 action buttons trouvés, mais aucun trap clavier dédié inter-cartes. (Modal allergens a un focus trap dédié:1064-1095, mais le board principal n'a PAS de skip-link / shortcu
- **R4-14** [P2] kd7-pos-edit-sync → static-cite | KD7 : Pas d'endpoint dédié pos-order/edit-items trouvé (grep=0 lignes). Le KDS écoute OrderStatusChanged + OrderCreated + OrderTableChanged + ItemAvailabilityChanged (KitchenDisplaySystemComponent.vue
- **R4-15** [OK] kd12-isolation-runtime → isolated | Chef (branch=1) tente change-status sur order branch=99 → HTTP 404. 404 = isolation par BranchScope global (app/Models/Scopes/BranchScope.php:33-39) AVANT route binding find. 403 = isolation par abort
- **R4-16** [P2] multi-items-render → probed | 8 items seedés (8/8 réussis) : ticket visible=true, qty badges dans DOM=13. IMPORTANT : le détail items est rendu dans <div style="height: 0px"> (Vue:245) — collapsed par défaut. Le chef doit cliquer 