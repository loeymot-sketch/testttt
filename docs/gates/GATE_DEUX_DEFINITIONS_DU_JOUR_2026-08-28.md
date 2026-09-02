# GATE G-JOUR — « Aujourd'hui » a deux définitions, affichées l'une sous l'autre

- **Ouvert le** : 2026-08-28 · **Mission** : ONB-07 (tableau de bord et rapports vrais)
- **Statut** : **EN ATTENTE DE DÉCISION DU PROPRIÉTAIRE**
- **Qui peut trancher** : le propriétaire seul. Ce n'est pas un défaut à corriger,
  c'est une définition à choisir — et elle engage un document fiscal archivé six ans.

---

## 1. Le constat, mesuré

Deux tuiles portent le mot « jour », sur la même page, l'une sous l'autre
(`DashboardComponent.vue:48,50` — `<OverviewComponent/>` puis
`<RealtimeReportComponent/>`). Elles ne comptent pas la même chose.

| Surface | Libellé | Fenêtre | Code |
|---|---|---|---|
| Tuile « Ventes du jour » | `OverviewComponent.vue:12` | **jour fiscal** — `where('business_date', now()->toDateString())` | `DashboardService::totalSales('today')` → `scopePeriod` `:383-386` |
| Widget « Chiffre d'Affaires du Jour » | `RealtimeReportComponent.vue:7` | **minuit à minuit** — `order_datetime` entre `Carbon::today` et `Carbon::tomorrow` | `DashboardService::realtimeReport()` `:443-452` |
| PDF « Clôture du jour » | — | **minuit à minuit** — `order_datetime` | `DashboardService::eodSynthesis()` `:700-716` |

**Le Cayenne sert jusqu'à 00h30** (`config/kds.php`, cité par le commentaire de
`scopePeriod` lui-même). Les commandes passées entre minuit et 00h30 appartiennent
donc au jour fiscal de la veille — et à la journée civile du lendemain.

L'écart n'est pas théorique : c'est exactement le service du soir, tous les soirs.

## 2. Pourquoi ce n'est pas une simple correction

Le commentaire de `scopePeriod` (`DashboardService.php:375-381`) énonce déjà la
doctrine, et il argumente bien :

> « `$period='today'` scope sur `business_date` (le jour FISCAL, pas minuit UTC —
> Le Cayenne sert jusqu'à 00h30, un même service du soir ne doit pas être coupé en
> deux jours). »

On pourrait donc croire qu'il suffit d'aligner les deux autres surfaces. Trois
raisons de ne pas le faire sans signature :

1. **`realtimeReport()` ne rend pas que le chiffre d'affaires.** Il rend aussi le
   nombre de commandes et le ticket moyen. Changer sa fenêtre change ces trois
   nombres d'un coup, sur le widget que le commerçant regarde en service.

2. **`eodSynthesis()` produit le PDF remis au comptable**, archivé six ans. Modifier
   la journée qu'il couvre change un document fiscal déjà émis pour les jours
   passés. Ce n'est pas une décision d'agent.

3. **« Temps réel » et « jour fiscal » ne veulent peut-être pas dire la même chose,
   et c'est défendable.** Un widget de service qui affiche ce qui est entré depuis
   minuit répond à une question différente de la tuile qui réconcilie avec le Z.
   Si c'est le choix retenu, alors le défaut n'est pas le calcul : ce sont les
   LIBELLÉS, qui disent « du jour » des deux côtés sans dire lequel.

## 3. Les options

| # | Option | Ce qu'elle coûte | Ce qu'elle règle |
|---|---|---|---|
| **1** | Tout au **jour fiscal** : `realtimeReport()` et `eodSynthesis()` passent à `business_date` | Le PDF de clôture change de périmètre — à ne faire qu'à partir d'une date, jamais rétroactivement | Un seul « jour » dans tout le produit ; le tableau de bord réconcilie avec le Z |
| **2** | Garder deux fenêtres, **changer les libellés** : « Ventes du jour (journée fiscale) » et « Encaissé depuis minuit » | Presque rien — deux clés de traduction | Le commerçant cesse de voir deux nombres contradictoires sous le même mot |
| **3** | Ne rien faire | — | Rien. Deux nombres différents nommés pareil, tous les soirs |

**Recommandation : option 2 d'abord, option 1 ensuite si le propriétaire le veut.**

L'option 2 est immédiate, sans risque fiscal, et supprime la contradiction *visible*
— qui est le vrai coût aujourd'hui : un commerçant qui voit deux chiffres du jour
différents ne fait plus confiance à aucun des deux. L'option 1 est le bon état final,
mais elle touche un document archivé et mérite une date d'effet décidée.

**Si rien n'est tranché**, l'option 3 s'applique par défaut et ce dossier reste
ouvert. C'est le pire des trois, et c'est pour ça qu'il est écrit.

## 4. Ce que ce dossier ne demande PAS

Aucune modification de `PricingService`, aucune écriture fiscale, aucune migration.
Les trois options sont des changements de lecture ou de libellé.

---

*Ouvert par la session ONB du 2026-08-28, à la suite d'un audit adverse en lecture
seule qui a comparé les trois surfaces ligne à ligne. Le constat est reproductible :
`DashboardService.php:383-386` contre `:449-452`.*
