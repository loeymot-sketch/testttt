# F-017 Wave 4.1 / Suite 6 — Stock Rupture Multi-Surface Sync

Total findings: 3

| Step | Slug | State | Sev | Note |
| --- | --- | --- | --- | --- |
| SETUP | env | spa-up | OK | spaUp=true token=true branches=[1,7] itemId=null extraId=1 variationId=5 |
| S6-02 | extra-rupture-A | backend-propagated | OK | toggleExtra 413ms; isolation verified at AvailabilityService level |
| S6-03 | variation-rupture-A | backend-propagated | OK | toggleVariation 407ms; isolation verified at AvailabilityService level |