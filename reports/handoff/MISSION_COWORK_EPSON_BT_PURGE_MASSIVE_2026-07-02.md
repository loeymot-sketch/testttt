# 🖨️ MISSION COWORK — Réhabiliter l'imprimante EPSON THERMIQUE Bluetooth (impression massive)

> Contexte : imprimante **Epson (thermique, Bluetooth)** de la CAISSE, restée **>1 an sans service**.
> Symptôme : elle imprime **incomplètement** — des lignes / parties manquantes, il faut réimprimer
> plusieurs fois. Objectif owner : lancer une **impression MASSIVE (≥ 2×25 m = 50 m, jusqu'à tout le
> rouleau)**, en boucle, pour **dégommer/burniser la tête + le rouleau** et faire repartir l'imprimante,
> puis vérifier qu'elle sort enfin une bande PROPRE.

> ⚠️ **Important (thermique, pas d'encre)** : « lignes manquantes » = 3 causes possibles →
> (1) **papier thermique vieilli/dégradé** (un rouleau d'1 an chauffé/humide imprime pâle/par plaques),
> (2) **tête ou rouleau sale/oxydé** (résidu après 1 an d'arrêt), (3) **points de tête endommagés**
> (physique — l'impression massive NE les répare PAS). On teste (1) et (2) EN PREMIER (rapides et
> décisifs), PUIS on lance la purge massive pour (2), et on sait interpréter le résultat.

---

## §0 — MÉTHODE (rappel playbook AnyDesk si pilotage à distance)
- **AZERTY** : ne jamais taper, **coller** (AnyDesk → Actions → « Insérer à partir du presse-papiers », ou
  clic-DROIT dans une console PowerShell). Ouvrir PS admin via **Ctrl+Alt+Suppr → Gestionnaire des tâches
  → Fichier → Exécuter une nouvelle tâche → coller `powershell` → cocher admin → OK**.
- 💡 **Le script de purge tourne SUR LA MACHINE** (impression locale) : même si la session AnyDesk se coupe
  (licence 4 min), **le job continue**. Reconnecter pour surveiller la progression dans la console.

## §1 — SÉCURITÉ / PRÉ-REQUIS (à lire avant de lancer)
- **Beaucoup de papier** : 50 m consomment un gros rouleau. Prévoir des rouleaux de rechange thermiques du
  BON format (largeur 58 ou 80 mm selon l'imprimante).
- **Protection de la tête** : l'impression continue de noir chauffe la tête. Le script fait des **pauses de
  refroidissement** (l'Epson auto-limite aussi, mais on aide). NE PAS retirer les pauses.
- Surveiller : pas de **bourrage**, le capot bien fermé, l'imprimante bien alimentée (secteur, pas batterie faible).

---

## §2 — ÉTAPE 1 (2 min) : AUTO-TEST MATÉRIEL par bouton (indépendant du Bluetooth/logiciel)
C'est le test le plus honnête de la tête + mécanique, sans passer par le PC :
1. **Éteindre** l'imprimante.
2. **Maintenir le bouton FEED (avance papier) enfoncé** et **rallumer** ; garder FEED enfoncé ~1 s puis relâcher.
3. → elle imprime une **page d'auto-test** (réglages + motif). Sur certains modèles, re-appuyer sur FEED
   continue le test / imprime plus.
- **Lire le résultat** : le motif d'auto-test est-il PLEIN et net, ou y a-t-il des **rayures blanches
  verticales** (= colonnes de points morts/sales) ou des **bandes horizontales pâles** (= rouleau/pression) ?
- Répéter l'auto-test **5–10 fois** de suite : parfois ça se dégomme un peu à chaud. Noter si ça s'améliore.

## §3 — ÉTAPE 2 (5 min) : ÉLIMINER LA CAUSE « PAPIER » (test décisif, souvent LA cause)
1. Prendre un **rouleau thermique NEUF** (pas celui en place depuis 1 an).
2. Vérifier le **sens** : le côté thermique (celui qui noircit) doit faire face à la tête. Test : gratter le
   papier avec un ongle → le côté qui laisse une marque grise = côté thermique = vers la tête.
3. Refaire l'auto-test (§2) avec le rouleau neuf.
- ✅ **Si l'impression devient propre avec le rouleau neuf → c'était le PAPIER.** Terminé : remettre du
  papier neuf en service, jeter le vieux rouleau. (Ne pas faire la purge massive.)
- ❌ Si toujours des manques avec du papier neuf → continuer (tête/rouleau) : §4.

## §4 — ÉTAPE 3 (10 min) : NETTOYAGE PHYSIQUE tête + rouleau (à refaire À FOND)
> Même si déjà tenté : le faire méthodiquement avec le bon produit change tout.
1. **Éteindre** + débrancher. Ouvrir le capot.
2. **Tête thermique** (barrette fine sous laquelle passe le papier) : coton-tige imbibé d'**alcool
   isopropylique 99 %** (pas de l'eau, pas d'alcool ménager dilué) → essuyer **délicatement** la ligne de
   la tête, plusieurs passages, dans le sens de la barrette. Laisser **sécher 2–3 min**.
3. **Rouleau presseur (platen, le cylindre en caoutchouc)** : IPA 99 % sur un chiffon non pelucheux, **faire
   tourner le rouleau à la main** pour nettoyer toute la circonférence (un dépôt/anneau collant crée une
   bande pâle récurrente au même endroit).
4. Dépoussiérer (air sec) le chemin papier. Refermer, rallumer, refaire l'auto-test (§2).

---

## §5 — ÉTAPE 4 : IDENTIFIER LA CONNEXION (pour piloter l'impression massive)
Sur la caisse (Windows), ouvrir PowerShell (§0) et coller :
```powershell
Write-Host "=== Imprimantes Windows ==="; Get-Printer | Select Name,PortName,DriverName | Format-Table -Auto
Write-Host "=== Ports COM (le Bluetooth SPP sortant apparait ici, ex COM5) ==="; [System.IO.Ports.SerialPort]::GetPortNames()
Write-Host "=== Peripheriques Bluetooth ==="; Get-PnpDevice -Class Ports -EA 0 | Select FriendlyName,Status | Format-Table -Auto
```
- **Cas A (le + probable en Bluetooth)** : l'imprimante a un **port COM sortant** (ex. `COM5`, « Standard
  Serial over Bluetooth »). → on envoie l'ESC/POS **directement au COM** (§6, script principal).
- **Cas B** : elle est installée comme **imprimante Windows** (ex. « EPSON TM-… »). → on partage l'imprimante
  et on envoie le RAW par `copy /b` (§6 bis, fallback).
> Noter le **COM exact** (ou le **nom d'imprimante exact**) pour l'étape suivante.

## §6 — ÉTAPE 5 : ⭐ IMPRESSION MASSIVE (le job de purge — CAS A, port COM)
> Remplacer `COM5` par le vrai port (§5). `write_clipboard` ce bloc → coller dans PS → Entrée. Le job
> imprime des **bandes noires épaisses** (nettoient + révèlent les points manquants) + des lignes de texte
> (« 10 lettres ») + un **marqueur de progression**, en boucle jusqu'à **50 m** (modifiable), avec
> **pauses de refroidissement**. Il tourne même si AnyDesk se coupe.
```powershell
$PORT       = "COM5"     # <-- METTRE le vrai port COM (§5)
$BAUD       = 9600       # si ça bloque/rien ne sort, réessayer avec 115200
$TARGET_M   = 50         # metres a imprimer (50 = 2x25 ; mettre 60-80 pour tout le rouleau)
$WIDTH      = 48         # 48 = 80mm ; mettre 32 si imprimante 58mm
$BANDS      = 20         # bandes noires par passe (heat) — baisser a 12 si la tete chauffe trop
$COOL_EVERY = 5          # pause refroidissement toutes les N passes
$COOL_SEC   = 20

$port = New-Object System.IO.Ports.SerialPort($PORT,$BAUD,'None',8,'One')
$port.Handshake='None'; $port.WriteTimeout=8000; $port.Open()
function Build([int]$no,[double]$m){
  $b = New-Object System.Collections.Generic.List[byte]
  $b.AddRange([byte[]]@(0x1B,0x40))                       # ESC @ init
  for($i=0;$i -lt $BANDS;$i++){
    $b.AddRange([byte[]]@(0x1D,0x21,0x01))               # GS ! hauteur x2 (bande epaisse)
    $b.AddRange([byte[]]@(0x1D,0x42,0x01))               # reverse ON => fond noir
    for($s=0;$s -lt $WIDTH;$s++){ $b.Add(0x20) }         # ligne pleine (espaces sur fond noir)
    $b.Add(0x0A)
    $b.AddRange([byte[]]@(0x1D,0x42,0x00))               # reverse OFF
  }
  $b.AddRange([byte[]]@(0x1D,0x21,0x00))                 # taille normale
  foreach($k in 1..4){ $b.AddRange([Text.Encoding]::ASCII.GetBytes("ABCDEFGHIJ 0123456789")); $b.Add(0x0A) }
  $b.AddRange([Text.Encoding]::ASCII.GetBytes(("== PASSE {0}  ~{1:N1} m ==" -f $no,$m))); $b.Add(0x0A)
  $b.AddRange([byte[]]@(0x1B,0x64,0x02))                 # avance 2 lignes
  return ,$b.ToArray()
}
$passM = 0.15            # ~metres imprimes par passe (estimation)
$pass = 0
Write-Host "=== DEBUT PURGE MASSIVE (cible $TARGET_M m) ==="
while(($pass*$passM) -lt $TARGET_M){
  $pass++
  $bytes = Build $pass ($pass*$passM)
  try { $port.Write($bytes,0,$bytes.Length) }
  catch { Write-Host "!! coupure Bluetooth a la passe $pass — reconnecter, relancer le script (il reprendra du debut)"; break }
  Write-Host ("passe {0}  ~{1:N1} m" -f $pass, ($pass*$passM))
  if($pass % $COOL_EVERY -eq 0){ Write-Host "  ...refroidissement $COOL_SEC s"; Start-Sleep -Seconds $COOL_SEC }
  else { Start-Sleep -Milliseconds 400 }                 # laisse le buffer BT se vider
}
$port.Write([byte[]]@(0x1B,0x64,0x05),0,3)               # avance finale
$port.Write([byte[]]@(0x1D,0x56,0x00),0,3)               # coupe (GS V 0) — retirer ces 2 lignes pour NE PAS couper
$port.Close()
Write-Host "=== FIN — $pass passes, ~$([math]::Round($pass*$passM,1)) m imprimes ==="
```
> Pour **tout le rouleau** : mettre `$TARGET_M = 80` (ou plus) et **relancer** le script autant de fois que
> voulu. Pour ne PAS couper entre les runs, supprimer les 2 lignes `GS V 0`/avance finale (impression continue).

## §6 bis — CAS B (imprimante Windows partagée) — fallback RAW
Si l'Epson est une imprimante Windows (pas de COM) : la **partager**, puis envoyer le RAW en boucle.
```powershell
$PRN = "EPSON TM-XXXX"        # <-- nom EXACT (§5) ; partager l'imprimante : nom de partage "EPSONBT"
# construire un fichier de bande noire + texte, puis copier /b vers le partage, en boucle
$sb = New-Object System.Collections.Generic.List[byte]
$sb.AddRange([byte[]]@(0x1B,0x40))
foreach($i in 1..20){ $sb.AddRange([byte[]]@(0x1D,0x21,0x01,0x1D,0x42,0x01)); (1..48)|%{$sb.Add(0x20)}; $sb.Add(0x0A); $sb.AddRange([byte[]]@(0x1D,0x42,0x00)) }
$sb.AddRange([byte[]]@(0x1D,0x21,0x00)); foreach($k in 1..4){ $sb.AddRange([Text.Encoding]::ASCII.GetBytes("ABCDEFGHIJ 0123456789")); $sb.Add(0x0A) }
$sb.AddRange([byte[]]@(0x1B,0x64,0x03))
[IO.File]::WriteAllBytes("$env:TEMP\band.bin",$sb.ToArray())
foreach($n in 1..170){                                    # ~170 passes ~ 50 m
  cmd /c copy /b "$env:TEMP\band.bin" "\\localhost\EPSONBT" | Out-Null
  Write-Host "passe $n"
  if($n % 5 -eq 0){ Start-Sleep 20 } else { Start-Sleep -Milliseconds 500 }
}
```

## §7 — ÉTAPE 6 : VÉRIFIER + BOUCLER
Après une purge :
1. Regarder la bande imprimée : les **rayures blanches** ont-elles **réduit / disparu** ? Le noir est-il plein ?
2. **Si ça s'améliore mais reste imparfait** → relancer la purge (§6) **plusieurs fois** (c'est ce que veut
   l'owner : beaucoup de passes pour dégommer). Refaire un nettoyage IPA (§4) entre deux grosses purges.
3. **Test final réel** : imprimer un **vrai ticket** (via la caisse) → doit sortir **complet, net, du 1er coup**.
4. ✅ **Validé** quand un ticket sort propre du premier coup, de façon répétée (5 tickets d'affilée nets).

## §8 — INTERPRÉTATION (savoir quand s'arrêter)
| Observation | Cause probable | Action |
|---|---|---|
| Propre avec rouleau NEUF | papier vieilli | ✅ papier neuf, fini (pas de purge) |
| **Rayures blanches VERTICALES** qui **disparaissent** après purge/IPA | tête sale/oxydée (1 an d'arrêt) | ✅ la purge a marché — refaire jusqu'à propre |
| Rayures verticales aux **MÊMES colonnes**, **persistantes** après IPA + grosse purge | **points de tête endommagés** (physique) | ❌ la purge ne répare pas — tête à remplacer / SAV Epson |
| **Bande PÂLE horizontale récurrente** au même endroit | rouleau presseur (dépôt/pression) | nettoyer/vérifier le rouleau (§4.3) ; si persiste → rouleau à changer |
| Rien ne sort du tout | pas de connexion BT / mauvais COM / baud | vérifier §5, essayer BAUD 115200, re-pairer le Bluetooth |

## §9 — À RAPPORTER (photos)
- Auto-test bouton (avant), bande de purge (après N passes), ticket final.
- Est-ce que le rouleau NEUF seul a réglé le problème ? (oui/non)
- Nombre de passes / mètres imprimés au total pour arriver à propre.
- Si rayures persistantes aux mêmes colonnes → le préciser (= tête HS, décision SAV/remplacement).

> Résumé de la stratégie : **auto-test → rouleau neuf (souvent LA cause) → IPA tête+rouleau → purge massive
> ≥50 m en boucle (dégomme) → re-test → répéter.** Si des colonnes restent blanches après tout ça, la tête
> est physiquement abîmée et il faut la remplacer — l'impression massive ne ressuscite pas un point mort.
