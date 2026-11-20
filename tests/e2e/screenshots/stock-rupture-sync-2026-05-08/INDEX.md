# F-017 Wave 4.1 / Suite 6 — Stock Rupture Multi-Surface Sync

Total findings: 8

| Step | Slug | State | Sev | Note |
| --- | --- | --- | --- | --- |
| SETUP | env | spa-up | OK | spaUp=true token=true branches=[1,2] itemId=22 extraId=1 variationId=5 |
| S6-01 | pos-86-badge | visible | OK | POS branch A rupture badge after 2000ms wait |
| S6-01 | item-rupture-A | backend-propagated | OK | Toggle 382ms; DB persisted; event row id=15737 |
| S6-02 | extra-rupture-A | backend-propagated | OK | toggleExtra 382ms; isolation verified at AvailabilityService level |
| S6-03 | variation-rupture-A | backend-propagated | OK | toggleVariation 360ms; isolation verified at AvailabilityService level |
| S6-04 | re-enable | backend-propagated | OK | Re-enable toggled in 359ms; row.is_available=true confirmed. |
| S6-05 | auto-86-quota | backend-propagated | OK | Auto-86 via max_daily_qty=0 confirmed; null restored availability. |
| S6-06 | order-rejected-mid-flight | backend-defensive-OK | OK | assertItemsOrderableForBranch threw as expected (defensive AC7). |