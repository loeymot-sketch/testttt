# Impression automatique du ticket cuisine — mise en service

**Imprimante** : Epson **TM-m30** (modèle M335B) · IP **statique 192.168.192.168** · port **9100**
**Objectif owner** : toute commande qui entre en cuisine s'imprime SEULE — borne, caisse ou site —
sans clic, sans boîte de dialogue, sans « impression en cours ».

---

## Pourquoi ça n'imprimait pas

Deux causes, toutes deux de configuration :

1. **`PRINTING_BYPASS_MODE=true`** dans `.env` — le mode bypass court-circuite *tout* envoi vers
   l'imprimante. Rien ne pouvait sortir, quelle que soit la configuration.
2. **L'imprimante déclarée pointait sur `127.0.0.1:9101`**, pas sur la vraie Epson.

Et une cause de logique : la caisse était **exclue** de l'impression automatique (elle attendait
un clic sur « Imprimer »). C'est corrigé — le déclencheur ne regarde plus d'où vient la commande
mais **quand elle entre en cuisine**, ce qui vaut pour toutes les surfaces.

---

## Mise en service (sur la machine du restaurant)

```bash
# 0. COMPILER LES ASSETS — sinon l'écran de cuisine sert l'ANCIEN bundle et aucun
#    changement d'affichage n'apparaît. Les fichiers compilés sont hors git
#    (public/.gitignore) : ils DOIVENT être rebâtis sur la machine.
npm ci && npm run production

# 1. Désactiver le bypass
#    .env :  PRINTING_BYPASS_MODE=false
php artisan config:clear

# 2. Déclarer la vraie imprimante
php artisan kitchen:printer --host=192.168.192.168 --port=9100 --width=48

# 3. Diagnostic complet
php artisan kitchen:printer --check

# 4. Ticket de test RÉEL (sort du rouleau)
php artisan kitchen:printer --test
```

Le diagnostic vérifie, dans l'ordre des pannes les plus fréquentes : bypass actif, pilote,
imprimante déclarée, statut actif, port TCP joignable.

> **80 mm = 48 colonnes.** C'est la largeur de la TM-m30. Ne pas reprendre le 42 de la SAGA :
> au-delà de la largeur physique, l'imprimante ré-enroule les derniers caractères et coupe les
> lignes.

---

## Comment ça marche

```
   commande (borne · caisse · site)
            │
            ▼
   statut ACCEPTÉ / EN PRÉPARATION   ← « elle entre sur l'écran de cuisine »
            │
            ▼
   KitchenTicketAutoPrinter::printOnce()
            │  garde ATOMIQUE en base (orders.kitchen_ticket_printed_at)
            ▼
   SERVEUR ──TCP 9100──▶ Epson TM-m30
```

**Aucun affichage.** L'impression part du serveur, pas du navigateur : il n'y a ni boîte de
dialogue, ni fenêtre « impression en cours ». L'écran de cuisine n'est même pas au courant.

**Aucun doublon.** Plusieurs chemins peuvent mener à la même commande (création, changement de
statut, rejeu d'un job). Tous passent par la même garde, qui réclame la commande en base de façon
atomique : c'est la base qui arbitre, pas PHP.

**Le sens de la défaillance est délibéré.** En cas d'échec d'impression, la commande est
*libérée* : une imprimante momentanément hors tension ne doit pas condamner le ticket à ne jamais
sortir. Et si la commande ne peut pas être dédupliquée, on imprime quand même — un ticket qui
manque fait oublier un plat, un ticket en double ne coûte qu'un bout de papier.

---

## Si ça n'imprime toujours pas

| Symptôme du diagnostic | Cause | Geste |
|---|---|---|
| `mode bypass ACTIF` | `.env` non modifié ou cache de config | `PRINTING_BYPASS_MODE=false` + `php artisan config:clear` |
| `port TCP injoignable` | imprimante éteinte, câble réseau débranché, ou machine hors du réseau | vérifier le câble Ethernet et l'alimentation |
| `imprimante cuisine AUCUNE` | rien en base | rejouer l'étape 2 |
| `statut INACTIVE` | imprimante désactivée en admin | la réactiver |
| tout est vert mais rien ne sort | file d'attente arrêtée | vérifier le worker (`php artisan queue:work`) |

L'IP est **statique** (le ticket d'auto-test indique `DHCP : No Server -> Static`) : elle ne
changera pas toute seule. Si elle change un jour, refaire l'étape 2 avec la nouvelle adresse.

---

## Ce qui n'a PAS pu être vérifié d'ici

L'imprimante vit sur le réseau du restaurant : elle est **injoignable depuis le poste de
développement** (`192.168.192.168:9100` ne répond pas). La chaîne logicielle est prouvée par 13
tests qui comptent les octets réellement envoyés au transport, mais **le passage sur le papier
doit être confirmé par `php artisan kitchen:printer --test` sur place**.
