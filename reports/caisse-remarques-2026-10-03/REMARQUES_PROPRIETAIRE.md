# Remarques du propriétaire — caisse, personnalisation, encaissement, tickets, annulation, cuisine, afficheur

Établi le 2026-10-03 à partir des messages tapés par le propriétaire (dictée vocale, français imparfait conservé tel quel).
Périmètre : du 2026-08-15 au 2026-10-03, plus deux remarques antérieures sur la lisibilité du ticket cuisine (§4, signalées).
Ce document **ne juge pas** si une remarque est traitée : il recense seulement.

Conventions :
- **Dates** en heure de Paris (celle de `~/.claude/history.jsonl`). Les journaux de session sont en UTC : une remarque tapée après 22 h peut y figurer à la veille.
- **Session** : préfixe de 8 caractères ; le nom complet du fichier est dans la table « Sessions » en fin de document.
- **Propos** : extraits verbatim, `[…]` marque une coupe. Rien n'est traduit ni corrigé.
- « la banque » / « la bande » est, dans la dictée, presque toujours « la borne ». Les remarques purement borne ou purement site web sont exclues, sauf quand elles touchent aussi la caisse ou la cuisine.

Total : **79 remarques** (R-001 à R-079), plus les annexes A à C (listes externes transmises par le propriétaire, citées séparément).

| Domaine | Remarques |
|---|---|
| 1. Caisse — prise de commande et suivi | 18 (R-001 → R-018) |
| 2. Personnalisation et suppléments | 24 (R-019 → R-042) |
| 3. Encaissement et paiement | 5 (R-043 → R-047) |
| 4. Facture et tickets | 10 (R-048 → R-057, dont 2 antérieures au 15/08) |
| 5. Annulation et commandes téléphone | 5 (R-058 → R-062) |
| 6. Écran de cuisine (KDS) | 11 (R-063 → R-073) |
| 7. Afficheur client | 1 (R-074) |
| 8. Autres (catalogue, prix, Uber) | 5 (R-075 → R-079) |

---

## 1. Caisse — prise de commande et suivi des commandes

#### R-001 — Bouton « Modifier » sur un article déjà au panier
- 2026-08-16 · `a3394641` — « chaque produit ajoute au panier, j'arrive pas à le modifier […] j'arrive pas parce que y a pas de bouton modifié pour réouvrir le Wizard »
- Demande : pouvoir rouvrir le wizard d'un article du panier pour le modifier.

#### R-002 — La modification d'un article n'est pas enregistrée et le prix ne change pas
- 2026-09-03 · `d5af53ab` — « sur la caisse en fait lorsque je fais un produit, je le modifie ça s'enregistre jamais ça reste toujours à l'ancienne version même si je lui modifie […] même le prix ne changerait pas si je rajouterai une sauce »
- 2026-09-03 · `e16893d9` — « la modification d'un produit sur dans la dans le panier, ça modifie pas le prix ne changeait pas »
- 2026-09-23 · `f99fe4fa` — « test-e2e la caisse et lors de modifier ou dupliquer un produit en panier et le modier assure »
- Demande : une modification d'article doit être réellement enregistrée et le prix recalculé (avec preuve de test).

#### R-003 — Dupliquer un article du panier puis modifier la copie
- 2026-08-28 · `672aa22e` (recollé le 2026-09-02 · `f1ed7a7a`) — « je veux l'option de dupliquer vraiment mettre ça et ouvrir un pareil comme comme lui […] Enregistre le premier, je clique dupliquer, je travaille sur la seconde copie »
- 2026-09-23 · `f99fe4fa` — voir R-002 (« modifier ou dupliquer un produit en panier »)
- Demande : bouton « Dupliquer » qui crée une copie identique, éditable séparément (ex. changer seulement la sauce).

#### R-004 — Commande programmée : choisir directement l'heure, la date en option
- 2026-08-21 · `0be029be` — « par défaut on doit choisir juste l'heure, il va venir après 15 minutes 30 minutes mettre l'heure exact […] Personne ne va commander pour demain »
- 2026-08-28 · `672aa22e` — « l'horaire programmé une commande pour telle heure je veux que ça vraiment ça je peux personnaliser directement l'heure exacte et pour la date c'est c'est un choix »
- Demande : la programmation d'une commande propose d'abord l'heure du jour ; la date n'est qu'une option.

#### R-005 — Bande blanche inutilisée en bas du panneau de la caisse
- 2026-08-21 · `0be029be` — « y a presque si je peux calculer 2 cm de ton bas qui sont blanc. Normalement ça sert être une partie de la de la caisse et d'affichage de panier et Total »
- Demande : utiliser les ~2 cm vides en bas du panneau latéral pour le panier et le total.

#### R-006 — Boutons du haut de la caisse trop encombrants
- 2026-08-21 · `0be029be` — « la partie tout en haut c'est rapide et après il y ira toutes les pourquoi, on prend beaucoup d'espace pour mettre juste ces boutons là »
- Demande : réduire les boutons du haut et les aligner pour agrandir la zone de caisse.

#### R-007 — Rapidité et fluidité de l'interface caisse
- 2026-08-19 · `ac6c0ad3` — « elle est pas si dynamique, elle est pas si elle est pas si rapide, faudra vraiment beaucoup de travail côté technique, chargement et rapidité surtout et fluidité »
- 2026-08-24 · `1341dbb2` — « surtout la rapidité et l'interface pour la caisse »
- Demande : accélérer le chargement et la fluidité de la caisse.

#### R-008 — Panier lisible d'un coup d'œil, en mots techniques
- 2026-08-19 · `ac6c0ad3` — « visualiser tout ce qu'il y a dans le panier. Pas besoin de scroller […] c'est mieux d'écrire l'mot technique directement STO » ; « frites : maillot par exemple, ensuite Coca-Cola directement »
- Demande : panier visible sans défilement, composition résumée en abréviations métier (STO, frites : mayo, boisson).

#### R-009 — Voir le contenu des commandes en cours et téléphone, pas seulement le total
- 2026-08-24 · `1341dbb2` — « les commandes qui sont par téléphone, faudrait pas juste voir le total vaut mieux voir si je clique sur voir tout […] mettre même les noms de produits que il y a avec les mots techniques »
- 2026-09-02 · `62a52582` — « on va toujours ce qu'il y a dedans en mode technique avec le nom de produits ainsi que l'heure de commande »
- Demande : afficher pour chaque commande les produits et toutes leurs personnalisations, en mots techniques, pour reconnaître un client sans son nom.

#### R-010 — Vue de pilotage de toutes les commandes par état, en panneau latéral
- 2026-09-02 · `62a52582` (recollé 2026-09-02 · `472bf530`) — « contrôler toutes les commandes livrés et mettre en voir ce qui est en cours, les commandes qui étaient prêtes les visualiser les commandes qui étaient pas encore encaissés » ; « pour les commandes qui sont en cours je veux pas que ça ouvre une nouvelle page vraiment directement en petite barre à droite »
- Demande : voir depuis la caisse les commandes livrées, en cours, prêtes, à encaisser et en cuisine, avec heure, numéro et rang en cuisine, dans un panneau à droite plutôt qu'une nouvelle page.

#### R-011 — Sonnerie à l'arrivée d'une commande (site web)
- 2026-08-16 · `a3394641` — « toutes les commandes qui viennent de site Web que la caisse n'arrête pas de sonner pendant au minimum 30 secondes. Elle fait par exemple comme la sonnerie de Hubert […] elle sonn trois fois chaque les 10 secondes façon de bip »
- 2026-08-19 · `a1cec93d` — « ajouyte une sonnerie lors de commande qui arrive »
- Demande : alerte sonore répétée (3 bips toutes les 10 s, au moins 30 s) à l'arrivée d'une commande web.

#### R-012 — Commandes web mises en évidence en rouge
- 2026-08-16 · `a3394641` — « dans les commandes de site Web, ça doit afficher en rouge parce que le le bleu comme dernièrement c'est la détecte même pas »
- Demande : afficher les commandes du site en rouge à la caisse.

#### R-013 — Sandwich classique absent de la caisse
- 2026-08-19 · `ac6c0ad3` — « pourquoi le sandwich classique sur la caisse ça s'affiche pas alors que sur l'accès admin ou sur la tablette je sais pas comment je le vois toujours le sandwich classique »
- Demande : afficher le sandwich classique à la caisse, avec les mêmes réglages que le Cayenne.

#### R-014 — Commandes refusées (« choix #450 ») : impossible de passer un Cayenne personnalisé
- 2026-09-03 · `01ed2c73` — « urgent ! corrige la cause que les commande ne pase pas !! » (photo IMG_2292, lue par l'assistant : « Composition : le choix #450 n'appartient pas au profil publié »)
- 2026-09-03 · `d5af53ab` — « tu dois revenir sur ta dernière version de caisse parce que actuellement j'arrive pas à choisir choisir des choses ça donnerait erreur, composition de choix 450 l'apparaît pas au profil publié corrige ça »
- 2026-09-03 · `01ed2c73` — « toujour memem problème j'arrive pas à mettre un cayenne et j'ai choisis tout personalisatio !!!!! »
- Demande : supprimer l'erreur de composition qui bloque l'encaissement (ou revenir à la version de caisse qui fonctionnait).

#### R-015 — Fidélité à la caisse : pouvoir retirer le client d'une commande
- 2026-08-21 · `0be029be` — « parfois je me connecte sur un sur c'est-à-dire le le système de fidélité de Client et après je veux annuler ou bien autre. […] j'arrive pas ça reste pour toute la command c'est un problème »
- Demande : pouvoir détacher le client fidélité d'une commande pour passer à la suivante.

#### R-016 — Valider à la caisse le retrait des commandes du site (attribution des points)
- 2026-09-03 · `e16893d9` — « sur la caisse, je demanderai lors de retrait de commande par par site Web […] mettre vraiment Maitre séparés et puis et je pourrais les les valider comme ça ils ser comme validé il y aura ces points »
- Demande : liste séparée des commandes du site à valider au retrait (QR plus tard), la validation créditant les points.

#### R-017 — Régler le temps de préparation annoncé depuis la caisse
- 2026-09-22 · `f99fe4fa` — « c'est moi qui met ça depuis la caisse 15 minutes pour être prêt l'autre il commande une autre commande, je mets par exemple 17 minutes »
- Demande : le caissier fixe l'estimation de préparation de chaque commande, reprise par le suivi client.

#### R-018 — La caisse du PC ne se met pas à jour (cache)
- 2026-09-24 · `f99fe4fa` — « la caisse sur le PC de la caisse ça se met pas à jour tout le temps […] faudrait vraiment mise à jour mettre à jour le le cache »
- Demande : le poste caisse doit charger la nouvelle version après chaque mise à jour.

---

## 2. Personnalisation et suppléments

#### R-019 — Même ordre des étapes d'un sandwich à l'autre
- 2026-08-28 · `672aa22e` — « déjà pas les mêmes ça dire pas les mêmes ordre dans des sandwiches sont différents à l'autre »
- Demande : ordre identique des choix dans tous les wizards de sandwich.

#### R-020 — Choix plus grands, occupant tout leur cadre
- 2026-08-28 · `672aa22e` — « je voudrais même les faire grandir au maximum de son cadre »
- 2026-08-28 · `1341dbb2` — « les choix de choses on a dit de lui mettre plus grand pour occuper tout l'espace quand y en a pour ainsi que Maitre avec des couleurs comme ça c'est plus facile à choisir pour le caissier »
- Demande : agrandir les boutons de choix du wizard caisse pour remplir l'espace disponible.

#### R-021 — Bloc des sauces de même taille que le bloc crudités en face
- 2026-08-28 · `672aa22e` — « je veux une sauce apprenne la même largeur, c'est-à-dire ça y estça prend la même l'espace qui est qui fait la même taille de l'espace du cru »
- 2026-08-28 · `1341dbb2` — « je veux la même taille que les crus passe pour les crus l'autre, c'est en face, la même taille »
- 2026-08-30 · `d5af53ab` — « les mis chacune dans une ligne, il s'apprécie vraiment trois fois plus d'espace alors que je voudrais que ça dépasse pas, mais ça soit à la même taille de la partie en face, qui est de crudités »
- Demande : sauces et crudités côte à côte, à la même taille, sans une sauce par ligne.

#### R-022 — Une couleur par sauce, fidèle à la sauce
- 2026-08-28 · `672aa22e` — « Ça reste avec ma grande blanche ça rien plan blanche la sauce ça dit rien orange ça orange et barbecue oran fromage c'est ketchup c'est un peu rouge »
- 2026-08-28 · `1341dbb2` — « les couleurs j'aime pas trop comment t'as fait […] curry c'est ses jaunes pourquoi t'as mis sa curry en rouge »
- Demande : colorer chaque bouton de sauce selon la sauce réelle (blanche blanche, ketchup rouge, curry jaune…).

#### R-023 — Liste de sauces complète partout (américaine, sans sauce, barbecue, frites)
- 2026-08-28 · `672aa22e` — « il manque la sauce américaine, il manque le choix de mettre pas de sauce sauce, il manquait parfois la sauce barbecue » ; « pour les frites assurer que toute la liste des choses c'est bien rattaché »
- 2026-08-28 · `1341dbb2` — « c'est un choix de sauce sans sauce »
- 2026-08-30 · `d5af53ab` — « je trouve toujours pas toute la liste comme l'américaine qui manque »
- Demande : chaque produit et les frites proposent toute la liste des sauces, avec « sans sauce ».

#### R-024 — Bols : ajouter d'autres sauces que la fromagère
- 2026-09-02 · `73e4aaa9` — « pour les bols, j'arrive pas à choisir d'autres sauces parfois des gens, ils veulent à part sauce fromagère, ils veulent leur rajouter une autre sauce barbecue »
- Demande : sur les bols, permettre d'autres sauces (logique : d'abord gratuite, ensuite payante).

#### R-025 — La modification demande « pain ou galette » sur un cheeseburger ou un tacos
- 2026-09-02 · `73e4aaa9` — « lorsque je vais le valider ça demande de choisir pain en galette alors que c'est un Cheeseburger c'est un tacos »
- Demande : ne jamais demander le choix pain/galette pour les produits qui n'en ont pas, y compris en modification.

#### R-026 — Erreur en ajoutant une viande en plus (3e viande)
- 2026-09-03 · `d5af53ab` — « lorsque je passerai un sandwich comme de viande et je rajouterai le troisième sur la banque toujours ça donnerait un erreur »
- Demande : accepter une viande supplémentaire sans erreur (cité sur la borne, demandé partout).

#### R-027 — Supprimer la page de choix de viande en double
- 2026-09-03 · `d5af53ab` — « supprime-moi la page supplémentaire de choix de viande parce que ça toujours ça me fait deux pages »
- 2026-09-04 · `e16893d9` — « ce problème de choix de viande une page qui servent à rien parce que la page principale existe déjà »
- Demande : une seule page de choix de viande.

#### R-028 — Cornichon affiché « offert » et inclus partout
- 2026-09-03 · `d5af53ab` — « c'est le cornichon qui l'a mis offert partout et inclus. Supprime-le annule-le »
- 2026-09-03 · `e16893d9` — « le cornichon qui a été mis en gratuit, c'était pas la peine »
- Demande : retirer le cornichon offert/inclus ajouté par erreur.

#### R-029 — Suprême : viandes déjà fixées, pas de choix
- 2026-09-03 · `d5af53ab` — « le suprême y a pas de choix de viande, viande viandes sont déjà choisis, c'est-à-dire un cordon-bleu et une steak c'est déjà pris même sur la caisse »
- Demande : le Suprême ne propose pas de choix de viande (cordon-bleu + steak imposés), caisse et borne.

#### R-030 — Viande « mixte » réservée à la caisse (Cayenne, sandwich classique)
- 2026-09-03 · `d5af53ab` — « viande mixte ça existait jamais que sur la caisse on a fait ça exclusive pour les anciens Client »
- 2026-09-07 · `e16893d9` — « comme actuellement il est spécial, soit poulet soit viande hachée, soit mixte et on affiche que sur la caisse »
- Demande : choix poulet / viande hachée / mixte pour Cayenne et classique ; « mixte » visible seulement à la caisse.

#### R-031 — Le Cayenne exige 2 ou 3 viandes au lieu d'une
- 2026-09-04 · `e16893d9` — « ça me demande toujours de prendre deux viandes pour que c'est par une viande » ; « ainsi que de la même logique par rapport Cayenne et sandwich classic »
- 2026-09-04 · `e16893d9` — « le cayenne demande toujours 3  viandes »
- Demande : Cayenne et sandwich classique n'exigent qu'une viande.

#### R-032 — « Choisir au minimum une viande » alors que des viandes sont choisies ; burgers qui demandent un choix
- 2026-09-03 · `e16893d9` — « même les hamburgers actuellement ça demande ça dire de choisir l'avion alors que c'était pas avant » ; « même si je choisirais deux viandes, j'arrive pas à passer la commande ça dit que vous devrez choisir au minimum une viande »
- 2026-09-04 · `e16893d9` — « comme le choix de viande dans les hamburgers y a pas or que ça me propose »
- Demande : supprimer le faux blocage « au minimum une viande » et le choix de viande/pain proposé à tort sur les burgers.

#### R-033 — Tacos M : cordon-bleu et tenders mis par défaut
- 2026-09-04 · `e16893d9` — « ça met toujours de tacos M une viande ça met toujours un cordon-bleu tenders pour que j'ai même pas choisi une viande »
- Demande : aucune viande présélectionnée sur le Tacos M à la caisse.

#### R-034 — Supplément affiché « 90 » ; la sauce américaine traitée comme supplément
- 2026-09-04 · `e16893d9` — « j'ai ajouté des supplément ça affiche un chiffre 90 hors que c'est rien à voir deuxième chose personne américaine qui qui s'on se dirait comme un supplément »
- Demande : corriger l'affichage des suppléments (chiffre parasite) et ne pas classer l'américaine en supplément.

#### R-035 — Tacos : page « extra sauce fromagère » (+1 € ou non)
- 2026-09-07 · `e16893d9` — « je veux sur le tacos mettre une page X extra sauce fromagère une page vraiment. En plus ça va Maitr plus un euro »
- Demande : ajouter au wizard du tacos une étape « plus de sauce fromagère (+1 €) / non ».

#### R-036 — Option « portion de viande plus grande » (+1,90 €) — annoncée pour plus tard
- 2026-09-07 · `e16893d9` — « une option de mettre une portion plus grande de viande pour toute les sandwiches qui contiennent une viande […] un supplément de 1,90 € »
- Demande : supplément « plus de viande » à 1,90 € sur les sandwichs (le propriétaire précise « prochainement c'est pas maintenant »).

#### R-037 — Frites : grande ou petite et sauce frites non prises en compte
- 2026-09-07 · `e16893d9` — « quand je prendrai une frite […] ça met même pas une grande frite, petite frite la sauce pour la frite, on va toujours pas ça et on la calcule même pas »
- Demande : la taille de frite et la sauce frites doivent être saisies, affichées et facturées, quel que soit le canal.

#### R-038 — Supplément libre : saisir un libellé et un montant à la main
- 2026-08-21 · `0be029be` — « je veux entrer un prix ajouter une tarif toujours supplément […] je peux écrire manuellement c'était quoi le truc chargé et je tape le montant »
- Demande : ajouter à la caisse un supplément libre (libellé facultatif + montant).

#### R-039 — Le supplément libre provoque une erreur et n'arrive ni au ticket ni en cuisine
- 2026-09-29 · `48fdb176` — « j'arrive pas rajouter un supplément libre ça met toujours erreur […] ça s'ajoute au panier, mais j'arrive pas à le passer en en commande soit sur le ticket soit sur l'écran de cuisine »
- Demande : une commande avec supplément libre doit passer et apparaître sur le ticket et le KDS.

#### R-040 — Catégorie « Sauces supplémentaires » vendable seule
- 2026-10-02 · `0ef68016` (reformulé en GOAL point 1, `d6d4ef03` / `2d4e60aa`) — « rajouter une section qui s'appelle sauce supplémentaire d'catégories. Par exemple des clients ils veulent prendre cinq sauce quoi en supplément c'est pas dans un menu »
- Demande : une catégorie de sauces vendues à part (hors menu), à côté du supplément libre.

#### R-041 — Bouton « Offert » sur une sauce ou un supplément
- 2026-10-02 · `0ef68016` (GOAL point 5) — « je voudrais bien avoir une petite beau temps de Maitre sauce pliante bien ça offert on va mettre comme ça il y aurait des clients que je vais pas leur calculer les choses »
- Demande : un petit bouton « Offert » pour ne pas facturer une sauce ou un supplément à un client habitué.

#### R-042 — Suppléments des frites affichés payants mais jamais facturés
- 2026-10-02 · `0ef68016` (GOAL point 6) — « pour les frites ils sont toujours gratuites alors que c'est payant, ils s'affichent que c'est payant, mais c'est jamais pris en compte »
- Demande : facturer réellement les suppléments des frites.

---

## 3. Encaissement et paiement

#### R-043 — Carte bleue : ne plus demander de code à 4 chiffres
- 2026-08-19 · `ac6c0ad3` — « quand je clique sur encaisser par carte bleue, ça directement stimule que c'était vraiment un fait […] là ça demande vraiment chaque fois le code à quatre chiffres »
- Demande : « Encaisser par CB » enregistre directement le paiement, sans saisie de code.

#### R-044 — Quatre boutons de mode de paiement visibles
- 2026-10-02 · `0ef68016` (GOAL point 2) — « je veux 23 Boutons même les quatre boutons de choix, de mode de payement, je après espèce, carte bleue ou bien titre resto ou bien multi payement »
- Demande : boutons Espèces, Carte bleue, Titre-resto et Multi-paiement sur l'écran d'encaissement.

#### R-045 — Paiement CB partiel : régler le reste autrement
- 2026-10-02 · `0ef68016` (GOAL point 2) — « si je cliquerai par exemple sur carte bleue et je et je t'appelle à mon tour inférieur du total ça va mettre le reste comment sur un autre carte ou bien sur dire en espèces ou bien titre resto »
- Demande : un montant CB inférieur au total laisse un reste à régler par un autre moyen.

#### R-046 — Commande téléphone : prix différent entre le ticket et l'encaissement
- 2026-09-04 · `e16893d9` — « si je rajouterai une commande par téléphone là des tickets sur téléphone s'affiche un prix lors d'encaisser, le client s'affiche un autre prix complètement, ça prend pas les suppléments »
- Demande : même prix du ticket à l'encaissement, suppléments compris.

#### R-047 — Tiroir-caisse : ouverture « sans vente » à corriger et tester sur place
- 2026-09-17 · `f99fe4fa` — « IMG_2384.HEIC  , Je te demande de bien corriger cela » (photo lue par l'assistant : bouton d'ouverture du tiroir sans vente) ; « va sur place tester le tiroir-caisse et la borne »
- Demande : corriger l'ouverture du tiroir sans vente et la vérifier sur le matériel réel.

---

## 4. Facture et tickets

#### R-048 — Demander « imprimer ou non » après paiement, y compris pour les commandes téléphone
- 2026-09-17 · `f99fe4fa` — « je veux pas que ça imprime le ticket par défaut je veux vraiment garder ça dire le choix d'imprimer ou ne pas imprimer »
- 2026-09-21 · `f99fe4fa` — « reprends la caisse : demande imprimer ou non après paiement »
- 2026-09-24 · `f99fe4fa` — « quand je passerai une commande par téléphone ou quand j'arrive à l'encaisser, je l'encaisse si ça imprime automatiquement le ticket hors que je veux que ça soit optionnel »
- 2026-10-02 · `0ef68016` (GOAL point 2) — « on reste toujours sur la façon d'encaissement et l'option d'imprimer le ticket »
- Demande : aucune impression automatique ; après chaque encaissement (direct, téléphone, web), proposer d'imprimer ou non.

#### R-049 — Après modification, ticket et écran cuisine en double
- 2026-08-19 · `ac6c0ad3` — « lorsque je modifie ça ouvre bien le Wizzard du tout, j'arrive bien à modifier, mais lors d'impression du ticket et dans l'écran de cuisine etc. ça écrit en double »
- Demande : un article modifié n'apparaît qu'une fois sur le ticket et le KDS.

#### R-050 — Menus et boissons disparus du ticket cuisine et du KDS
- 2026-08-19 · `a1cec93d` (même message dans `bac639d7`) — « L'AFFICHAGE DE MENU C'EST-À-DIRE AVEC FREE ET BOIS BIÈRE AVEC FRITES BIEN AVEC BOISSON SEUL SUR TICKET DE CUISINE ET SUR L'ÉCRAN DE CUISINE […] LES MENUS ON VA PLUS ET LES BOISSONS ON LES VOIT PLUS »
- Demande : réafficher la formule (menu frites + boisson, boisson seule) sur le ticket cuisine et le KDS.

#### R-051 — Suppléments absents du ticket
- 2026-09-04 · `e16893d9` — « les supplément là je les rajoute au ticket ça s'affiche même pas »
- Demande : chaque supplément ajouté figure sur le ticket.

#### R-052 — Ticket cuisine : numéro de commande très grand
- 2026-09-07 · `e16893d9` — « le numéro de commande soit un peu plus grand vraiment qui va prendre une grande partie, disons 4 cm ou 3 cm de la page »
- Demande : numéro de commande sur 3 à 4 cm en haut du ticket cuisine.

#### R-053 — Ticket cuisine : frites et menu encadrés en noir
- 2026-09-07 · `e16893d9` — « lorsqu'il y a une frite, je veux que ça soit encadré soit menu soit frites ça doit être encadré en noir »
- Demande : encadrer en noir les lignes contenant des frites ou un menu.

#### R-054 — Ticket cuisine : quantités (×2) en gras ou sur fond noir
- 2026-09-07 · `e16893d9` — « si il y a quelque chose Froid deux on va le mettre un peu en gras fois deux […] ou bien les mettre avec une arrière-plan Khomsi en noir »
- Demande : mettre en évidence les quantités multiples.

#### R-055 — Auditer l'ajout au panier et l'impression
- 2026-09-24 · `f99fe4fa` — « audit system de caisse ajout panier imprimle »
- Demande : contrôler le parcours ajout au panier → impression de la caisse.

#### R-056 — *(antérieur, 2026-07-03)* Ticket : nom du produit en gras, personnalisations décalées dessous
- 2026-07-03 · `b773186e` — « on mettra le nom de produits et on revient à la lignee Avec un petit espace pour dire que c'est ces choses là ça appartient à ce moment à cette ce produit-là. Et lorsque un nouveau produit rentre à notre produit s'écrit en gras »
- Demande : chaque produit en gras, ses personnalisations en retrait dessous. (Hors fenêtre, retenu car c'est l'origine du thème « # en gras ».)

#### R-057 — *(antérieur, 2026-07-05)* Ticket cuisine trop petit : agrandir police et largeur
- 2026-07-05 · `b773186e` — « pour le ticket de cuisine quand j'imprime depuis la caisse […] elle sort trop petite façon et on peut pas vraiment la voir si on peut faire agrandir un petit peu la taille de ça de Police »
- Demande : police plus grande et ticket environ 30 % plus large. (Hors fenêtre, même thème « agrandir ».)

---

## 5. Annulation et commandes téléphone

#### R-058 — Pouvoir annuler une commande passée depuis un moment
- 2026-08-19 · `ac6c0ad3` — « j'arrive pas à annuler les commandes qu'ils ont passé de certains heures ça veut dire y avait un peu de temps qu'ils étaient passés, j'arrive pas à les annuler pour que je veux pouvoir les annuler si je veux »
- Demande : annulation possible quelle que soit l'ancienneté de la commande.

#### R-059 — Croix « X » : annulation directe, conservée 24 h, sans trace fiscale
- 2026-09-24 · `f99fe4fa` — « les commandes que je veux directement les annuler, je clique sur X ça s'annule directement » ; « ça va dans commande rater ça reste 24 heures » ; « ça doit pas être enregistré fiscalement »
- Demande : un clic sur X retire une commande téléphone non venue ; elle reste récupérable 24 h dans « commandes ratées », puis disparaît, sans écriture fiscale.

#### R-060 — Supprimer sans justificatif les commandes téléphone en attente, une par une ou toutes
- 2026-09-29 · `48fdb176` — « les commandes en attente ceux qui sont pris par téléphone, il y aurait plein d'entre eux qui sont annulés les Client ils viennent pas » ; « je veux pas cliquer sur chacune et je mettre la justificatif pour pouvoir annuler directement X et ça s'annule » ; « Dans l'attente je veux tout supprimer, je supprime tout »
- 2026-10-03 · `48fdb176` — « l'annulation des commandes annuler par téléphone »
- Demande : X supprime immédiatement une commande en attente jamais encaissée, sans motif ; plus une action « tout supprimer ».

#### R-061 — Liste « en attente d'encaissement » : jour courant par défaut, purge des anciens jours
- 2026-10-02 · `0ef68016` (GOAL point 3) — « je voudrais vraiment pouvoir supprimer jour au jour ça veut dire la liste de commande » ; « là ça m'affiche des des commandes qui étaient jamais venu d'hier et avant-hier »
- 2026-10-03 · `48fdb176` — voir R-060
- Demande : un nouveau jour démarre avec une liste vide ; pouvoir purger ou consulter les commandes des jours précédents.

#### R-062 — Commandes téléphone : ne pas changer leur passage en « en attente d'encaissement »
- 2026-10-02 · `0ef68016` (GOAL point 2) — « pour les comme téléphone toujours si la même chose ne le modifie pas ça, ça veut dire ça passe et ça restera dans la section deux on attend encaissement »
- Demande (contrainte) : conserver le parcours actuel des commandes téléphone.

---

## 6. Écran de cuisine (KDS)

#### R-063 — Aucune commande n'arrive sur le KDS alors que l'imprimante cuisine imprime
- 2026-08-16 · `a3394641` — « problème majeur ! occune commande ne passe en kds , on vois 0 commande et l'imprimante de cuisine imprime malgré ça ! »
- 2026-08-17 · `a3394641` puis `f3d3382a` — « oui je passe commande aucine ne pase sur ecran de cuisine ! et l'imprimante imprime oui direct »
- Demande : chaque commande doit apparaître sur l'écran cuisine.

#### R-064 — Écran de préparation : obligé de défiler à gauche et à droite
- 2026-08-19 · `ac6c0ad3` — « je dois scroller à gauche et à droite pour pouvoir visualiser une commande qui est en cours de préparation ou bien commande qui est encore en attente de livraison »
- Demande : écran de suivi de préparation adapté à la taille d'écran, sans défilement horizontal.

#### R-065 — Barre du haut du KDS sur une seule ligne, à côté du logo
- 2026-08-21 · `0be029be` — « la même chose pour l'écran de cuisine, il y aura une ça veut dire la barre de navigation tout en haut et les notes ils sont en haut ça prend beaucoup d'espace […] une seule ligne il y aura toutes les beaux temps qu'il y aura besoin à côté de logo »
- Demande : regrouper boutons et logo sur une ligne pour laisser plus de place aux commandes.

#### R-066 — Cuisson : portion de poulet comptée pour moitié
- 2026-08-19 · `a1cec93d` (même message dans `bac639d7`) — « UNE PENSION DE POULET pour les produits EST AFFICHE EN CUISSON LA MOITIER ALORS FAUT  double »
- Demande : corriger le décompte cuisson des portions de poulet (et cordons-bleus).

#### R-067 — Cuisson des frites : petite = F, grande = 2F
- 2026-09-07 · `e16893d9` — « pour la cuisson une petite frite c'est une F et une grande frite c'est 2F »
- Demande : afficher les frites en unités de cuisson (F / 2F).

#### R-068 — Accès KDS : mot de passe chef oublié, à redéfinir
- 2026-08-25 · `a149bfb8` — « donne le mot de passe accés kds cuisine avec chef@lecayenne.fr j'ai oublié » ; « je veux le modifier alors car sur vps ça fonctionne pas »
- Demande : rétablir l'accès du compte cuisine au KDS (mot de passe choisi par le propriétaire, non recopié ici).

#### R-069 — Suppléments en ligne séparée : à rattacher au produit concerné
- 2026-09-27 · `f99fe4fa` — « sur le ticket ça s'affiche bien ça supplémentaire mais après sur l'écran de cuisine on affiche une ligne de supplémentaire » ; « chaque sauce si c'est pour le sandwich nous on doit s'afficher ça pour la ligne de sandwich. Si c'est pour les frites on doit s'afficher ça devant les frites ou bien menu »
- Demande : chaque supplément ou sauce s'affiche sous le produit auquel il appartient, pas sur une ligne « supplémentaire » isolée.

#### R-070 — « Sauce supplémentaire » sans le nom de la sauce — défaut qui revient
- 2026-10-02 · `0ef68016` (GOAL point 4) — « avec deux sauces ça affiche toujours en ticket ticket cuisine sauce supplémentaire ça affiche pas le nom de la sauce » ; « je t'ai demandé ça t'as corrigé ça s'affiche correctement et chaque fois ça revient à la même chose »
- 2026-09-27 · `f99fe4fa` — « chaque fois je te demande de corriger, tu le corriges et la mise à jour suivante tu, tu supprimes ça »
- Demande : afficher le nom réel de chaque sauce sur le ticket cuisine et le KDS, toutes catégories, sans régression.

#### R-071 — Suppléments en grand : gras, blanc sur cadre noir
- 2026-10-02 · `0ef68016` (GOAL point 7) — « les supplément dans le ticket de cuisine toujours il oublie ça parce que c'est pas écrit en grand je veux que ça soit en gras, même que ça soit écrit en blanc et entouré de cas cadre noir »
- 2026-10-03 · `48fdb176` — « l'écran de cuisine d'agrandir les suppléments »
- Demande : sur le ticket et le KDS, suppléments plus grands, en gras, texte blanc dans un cadre noir.

#### R-072 — « # » en gras au début de la ligne d'un produit avec suppléments
- 2026-10-02 · `0ef68016` (GOAL point 7) — « je veux la ligne qui commence le produit ça commence avec un #en gras. Comme ça ils savent bien que ce produit il contient un supplément »
- 2026-10-03 · `48fdb176` — « mettre un dièse si il y a un produit avec des supplément encore, ils savent bien que ce produit là il y a des supplémen »
- Demande : préfixer d'un « # » gras la ligne de tout produit avec supplément(s), sur le ticket et le KDS.

#### R-073 — Suppléments jaunes sur fond de commande jaune : illisibles
- 2026-10-02 · `0ef68016` (GOAL point 7) — « Les suppléments sont écrites en jaune et la page se dire la commande sur l'écran de cuisine déjà avec un fond jaune, il y arrive jamais à lire »
- Demande : supprimer l'écriture jaune des suppléments et le fond jaune qui la rend illisible.

---

## 7. Afficheur client

#### R-074 — L'afficheur client doit montrer le total à chaque ajout
- 2026-09-29 · `af06bcd1` — « il y a cet écran qui fasse Client qui affiche juste le prix derrière Client, j'ai vu que chaque fois je tape sur le panier ça aurait un prix ça rajoute un produit, il tape le total »
- Demande : l'afficheur côté client affiche le total du panier, mis à jour à chaque article ajouté.

---

## 8. Autres (catalogue, prix, Uber côté caisse)

#### R-075 — Tickets Uber scannés : texte complet plutôt qu'« article non mappé », en mots techniques
- 2026-08-20 · `1ada985a` — « corrige le probleme pour les ticket que uber scan , chaque foix ça donne art ! article non mappé !! alors vaut mieux l'afficher entierment ! » ; « mais faut pas afficher sauce crudité , boisson , ces therme !! faut juste les mots technique comme la caisse !! »
- Demande : afficher l'article Uber en entier quand il n'est pas reconnu, avec les abréviations de la caisse.

#### R-076 — Prix des tacos et Tacos XL 3 viandes, y compris à la caisse
- 2026-08-24 · `797be1f7` — « changer le prix de tacos 2 viande À 8,90 € et le tacos trois viandes à dix euros 0,90 € […] Tu dois le rajouter sur le site Web sur la borne sur la caisse »
- Demande : Tacos 2 viandes à 8,90 €, Tacos 3 viandes (XL) à 10,90 €, avec sa logique de choix, sur tous les canaux.

#### R-077 — Prix des galettes à 7,40 € partout
- 2026-08-19 · `ac6c0ad3` — « là corrige le prix de galette cayenne à 7,4euro er la classique aussi , sur tout les sytem »
- Demande : galette Cayenne et galette classique à 7,40 € sur tous les systèmes.

#### R-078 — Galettes : nouvelles images, supprimer « galette normale » (caisse comprise)
- 2026-09-25 · `f99fe4fa` — « Mise à jour, l'image de galette Cayenne et galette classique […] même dans la caisse galette normal galette Cayenne y a pas de normal »
- 2026-09-26 · `f99fe4fa` — « t'as pas mis à jour les image de galette classique et cayenne !! »
- Demande : seules « galette Cayenne » et « galette classique » existent, avec les nouvelles photos, partout.

#### R-079 — Retirer des viandes et produits ajoutés par erreur
- 2026-09-03 · `d5af53ab` — « supprime les viande tondoré crispy et cury qui sont ajouté par une autee session apr erreur ! alors on les fais pas !!! sur site caisse et borne ! » ; « oui supprime CCV Varia Poulet et les bolws ne touche pas ! »
- 2026-09-03 · `01ed2c73` — « dépublie 64 et 68 et assure ça fonctionne la caisse !! »
- Demande : supprimer les viandes tandoori, crispy et curry et les éléments « CCV / Varia Poulet » sur site, caisse et borne ; dépublier les articles 64 et 68.

---

## Annexe A — GOAL « Caisse/Cuisine : 7 correctifs » (2026-10-02)

Reformulation structurée, collée par le propriétaire en `/goal` (sessions `d6d4ef03`, puis `2d4e60aa` « continue ce goal »), de sa dictée du même jour (`0ef68016`). Correspondance :

| Point du GOAL | Remarques |
|---|---|
| 1. Sauces supplémentaires (catalogue) | R-040 |
| 2. Encaissement : 4 boutons, CB partielle, impression, téléphone inchangé | R-044, R-045, R-048, R-062 |
| 3. Liste « en attente d'encaissement » : jour courant, purge des anciens jours | R-061 (et R-060) |
| 4. Ticket cuisine + KDS : nom des sauces | R-070 |
| 5. Bouton « Offert » | R-041 |
| 6. Prix des frites | R-042 |
| 7. Lisibilité cuisine : « # » gras, blanc sur noir, pas de jaune | R-071, R-072, R-073 |

## Annexe B — Rapport externe Codex `RAPPORT_DEV_CAISSE_2026-09-24.md`

Rapport **écrit par Codex, pas par le propriétaire**. Il l'a collé le 2026-09-26 (`f99fe4fa`, « /goal corrige max precision »), puis redonné en `/goal` le 2026-09-28 et le 2026-09-30 (`48fdb176`). Le journal ne garde qu'un repère (« [Pasted text #9 …] ») : les éléments ci-dessous viennent du fichier sur disque (`/Users/1millnonstop/Documents/Codex/2026-09-20/x20-teste-moi/plans/`, lignes 1-122). Seules les références dans le périmètre caisse / cuisine / encaissement / tickets / annulation sont retenues (tableau de bord, RGPD, rôles, stock et site exclus).

| Réf. | Constat (résumé) | Lien |
|---|---|---|
| P0-01 | « Modifier » recharge 1 viande / 13,90 € au lieu de 3 viandes / 18,90 € (Tacos XL) | R-002 |
| P0-02, P0-17, P1-28, P1-31, P1-58 | Compteurs « à encaisser » et états différents selon POS, Encaissement, Tracker et Historique | R-010 |
| P0-03, P1-38 | KDS « Mode secours — SYNC · LOCAL » ; pastilles « Prêt » gardées dans le navigateur | R-063 |
| P0-05, P0-08 | TPE en simulation, aucune imprimante, healthcheck vert | R-047 |
| P0-09, P1-13, P1-57 | « Supprimer » / « Rembourser » sur des commandes en préparation, sans motif ni rôle | R-059, R-060 |
| P0-10 | Rapport X refusé : « compte non rattaché à un établissement » | — |
| P0-11, P1-33 | Écran client et KDS en désaccord sur les commandes prêtes | R-063 |
| P0-18, P1-25 | Même numéro court (A0041…) sur deux commandes de jours différents | R-010 |
| P1-01, P1-12, P1-14, P1-34 | Cartes téléphone sans « Voir le détail » ; commande tronquée « +3… » à l'encaissement | R-009 |
| P1-02, P1-03, P1-27, P1-35 | Ticket client imprimé automatiquement à l'encaissement téléphone malgré le réglage | R-048 |
| P1-04, P1-18 | Commandes en attente : « 0 », « Aucune » et « Impossible de charger » à la fois ; mise en attente d'un panier vide | R-061 |
| P1-05 | Assistant téléphone « Reconnexion… » sans état ni mode manuel | — |
| P1-06, P1-07, P1-26, P1-32, P1-60 | Import Uber : abréviations, ticket illisible, boissons sans alerte, doublons | R-075 |
| P1-08, P1-36 | « Livraison » proposée au POS alors que désactivée | — |
| P1-09, P1-20, P1-21, P1-61, P1-67 | Session de caisse ouverte depuis des mois sans comptage ; mouvements sans référence de commande | — |
| P1-10 | Tacos M : message « 1 viande supplémentaire » au lieu de « viande incluse » | R-033 |
| P1-11, P1-65 | « Sans sauce » non exclusif et facturé 0,50 € | R-023 |
| P1-15, P1-24 | Boissons et choix recopiés dans le texte « Instruction: » (doublons ticket/écran) | R-049, R-050 |
| P1-16 | Duplicata imprimable même sur commandes annulées | — |
| P1-17 | « Ajouter un client » exige e-mail + mot de passe au poste caisse | R-015 |
| P1-19 | Supplément libre possible avec un panier vide | R-038 |
| P1-22 | Libellés de paiement incohérents (COUNTER_CARD / Carte (Caisse) / Carte) | — |
| P1-43 | Heure affichée au format 12 h | — |
| P1-47 | Bol Frites : plafond de 6 suppléments et gratiné | — |
| P1-66, P1-76 | Message « Maximum 4 sélections » qui reste affiché dans le wizard ou le récap | — |

## Annexe C — Autres rapports externes collés par le propriétaire

- **« Plan de correction complet — Audit Le Cayenne »** (ChatGPT/Codex), collé le 2026-09-20 (« avec test-e2e corrige… »), le 2026-09-23 à 01:50 (« go deeeper audit and fix ») et le 2026-09-23 à 17:45 (« corrige : »), session `f99fe4fa`. Constats dans le périmètre : perte de configuration Tacos XL (= P0-01), commande POS erronée propagée au KDS (A0057), états KDS non synchronisés (= P1-38), caisse ambiguë (période contre session de tiroir, = P1-21), environnement de production en staging/simulation (= P0-05), compteurs POS contre Encaissement en désaccord (= P0-17).
- **« Rapport complet de test — Le Cayenne »**, collé le 2026-09-20 (« problème : », `f99fe4fa`). Il porte sur la commande **site web** (disponibilité des sauces, état du configurateur, libellé « 1 viande ») : hors périmètre caisse, sauf une demande de nettoyage — annuler côté caisse les commandes de test `#2009261329` à `#2009261350`.
- `QA_LOOP_NEXT_ACTION_2026-09-27/29.md` et `QA_CROSS_SURFACE_FOLLOWUP_2026-09-25.md` (donnés en `/goal` les 27, 28 et 30/09) : comptes rendus d'état Codex, **sans liste de remarques du propriétaire**. Non repris.

## Sessions

| Préfixe | Fichier | Dossier de transcripts |
|---|---|---|
| `a3394641` | `a3394641-fbcf-4592-ac94-b1b60ce2e0bd.jsonl` | absent — texte lu dans `~/.claude/history.jsonl` |
| `f3d3382a` | `f3d3382a-cd21-4dbf-aee4-018f6b0fa5ac.jsonl` | absent — history.jsonl |
| `ac6c0ad3` | `ac6c0ad3-54ef-4bb7-83a4-18fd1e087826.jsonl` | absent — history.jsonl |
| `a1cec93d` | `a1cec93d-f458-4d37-b159-96e1c268f7b0.jsonl` | absent — history.jsonl |
| `bac639d7` | `bac639d7-c5f2-49c1-afc1-4666f9baf657.jsonl` | absent — history.jsonl |
| `1ada985a` | `1ada985a-f9d8-4a87-9665-d951c8cbc2d3.jsonl` | absent — history.jsonl |
| `0be029be` | `0be029be-c05a-4c1a-b718-b37a775e5d09.jsonl` | absent — history.jsonl |
| `a149bfb8` | session `a149bfb8…` | absent — history.jsonl |
| `672aa22e` | `672aa22e-cacb-42b5-981f-3a4ab8e0a275.jsonl` | absent — history.jsonl (texte recollé dans `f1ed7a7a`) |
| `b773186e` | session `b773186e…` | absent — history.jsonl |
| `1341dbb2` | `1341dbb2-9006-43aa-8577-0cd34fddfa80.jsonl` | `…testttt--claude-worktrees-goal-caisse-vision-2026-08-24/` |
| `797be1f7` | `797be1f7-ffd7-47d4-9de5-1908589525b0.jsonl` | `…testttt--claude-worktrees-tacos-xl-3-viandes-2026-08-24/` |
| `d5af53ab` | `d5af53ab-20ee-487f-ad63-cc1d4fe51b9e.jsonl` | `…testttt/` |
| `73e4aaa9` | `73e4aaa9-7bb9-4124-8c87-799ffd37c55d.jsonl` | `…testttt/` |
| `62a52582` | `62a52582-5579-46fe-b95d-5c17cc347bcf.jsonl` | `…testttt/` |
| `472bf530` | `472bf530-54d9-4573-98fe-ed62e8823c82.jsonl` | `…testttt/` |
| `f1ed7a7a` | `f1ed7a7a-2f88-42fd-85f7-c4826d35a9bc.jsonl` | `…testttt/` |
| `01ed2c73` | `01ed2c73-aa58-40b9-b284-4a09e9c80ccd.jsonl` | `…testttt/` |
| `e16893d9` | `e16893d9-e7e3-4f81-bd1d-022f7acfbae1.jsonl` | `…testttt/` |
| `f99fe4fa` | `f99fe4fa-c562-404d-a371-daf538858601.jsonl` | `…testttt/` |
| `af06bcd1` | `af06bcd1-9531-4fd0-a11e-9de2797ae36c.jsonl` | `…testttt/` |
| `0ef68016` | `0ef68016-4276-4a9c-8e92-cdc3b114c7c1.jsonl` | `…testttt/` |
| `d6d4ef03` | `d6d4ef03-a7b5-4f43-9863-329efa6d8322.jsonl` | `…testttt/` |
| `2d4e60aa` | `2d4e60aa-c762-482e-a56d-74131ce0f252.jsonl` | `…testttt/` |
| `48fdb176` | `48fdb176-8e84-4194-8666-b3977f97c6d0.jsonl` | `…testttt--claude-worktrees-qa-corrige-2026-09-28/` |

`…testttt` = `/Users/1millnonstop/.claude/projects/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt`.

## Sources et limites

- **`~/.claude/history.jsonl`** : 497 messages tapés pour ce projet depuis le 2026-08-15, contre environ 150 retrouvés dans les transcripts. Les transcripts des sessions du 15 au 25 août (et de `672aa22e`, `b773186e`) n'existent plus ; leur texte vient de cet historique.
- **Transcripts** : 46 fichiers de premier niveau dans les 6 dossiers `…testttt*` (≈ 1,7 Go en comptant les sous-agents), lus ligne par ligne. Les sous-agents ont été ignorés : leurs messages « user » sont des consignes d'agent, pas du propriétaire.
- **Photos non lisibles** (`.HEIC`, captures collées) : IMG_2292, IMG_2384, IMG_2451, IMG_2457 et les « [Image #n] ». Leur contenu n'est cité que d'après le texte du propriétaire ou la description qu'en a faite l'assistant (R-014, R-047).
- **Contenus collés introuvables** : plusieurs blocs « [Pasted text #n] » d'août (missions ONB, Voice Order) ont disparu de `~/.claude/paste-cache`. Aucun ne portait de remarque caisse d'après le message qui les entoure.
- **Exclus volontairement** : remarques purement borne (animation, logo, pages viande de la borne, tailles M/L/XL, fond blanc), purement site web (temps d'attente, compte client, Mollie, fidélité web), consignes de méthode (« deploy », « continue », « test-e2e », missions ONB).
