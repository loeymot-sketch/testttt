# Le Cayenne Étiquettes — application Android native

Pourquoi : Chrome (Web Serial) ne trouvait pas la Brother QL‑820NWB (« Aucun appareil compatible détecté »). Comme Komia et
Octopus HACCP, l'impression passe par une **application native**. Ici : le back‑office (écran *Étiquettes*) dans une WebView, plus un
pont Java qui ouvre une vraie prise **Bluetooth RFCOMM (profil série SPP)** ou **Wi‑Fi (TCP, port 9100)** vers l'imprimante.

Le protocole Brother (octets raster, état, confirmation de page) reste celui de la page : `resources/js/helpers/brotherQl.js` et
`labelPrinter.js`, déjà prouvés contre le manuel. Seul le transport change : `labelPrinterNative.js` fait ressembler le pont à Web
Serial. Le Java ne fait que transporter des octets (`PrinterBridge.java`).

## Installer sur la tablette

1. Copier `LeCayenne-Etiquettes.apk` sur la tablette (câble, AirDrop, Drive…) et l'ouvrir ; autoriser « Installer des applis
   inconnues » pour l'application utilisée si Android le demande.
2. Ouvrir **Le Cayenne Étiquettes**, se connecter (même compte que le back‑office), accepter « Appareils à proximité ».
3. Écran *Étiquettes* › **Connecter l'imprimante** : une fenêtre native liste les imprimantes Bluetooth appairées (les QL en
   premier) et propose **Adresse IP (Wi‑Fi)**. Le dernier choix est mémorisé : l'imprimante se reconnecte seule.
   - Bluetooth : appairer d'abord la QL‑820NWB dans Réglages Android › Bluetooth.
   - Wi‑Fi : imprimante et tablette sur le même réseau ; l'adresse IP est dans le menu de l'imprimante (WLAN).
4. L'écran reste allumé tant que l'application est ouverte (impression automatique des commandes).

## Compiler

```
export JAVA_HOME=~/.local/android-build/jdk-21.0.12.1+1/Contents/Home ANDROID_HOME=~/Library/Android/sdk
printf "sdk.dir=%s\n" "$ANDROID_HOME" > local.properties
./gradlew assembleRelease                 # app/build/outputs/apk/release/app-release-unsigned.apk
# adresse du site : -PstartUrl=https://… (défaut : https://vps-418872ac.vps.ovh.net/admin/labels/dlc)
zipalign -p 4 app-release-unsigned.apk aligned.apk && apksigner sign --ks <keystore.jks> --out LeCayenne-Etiquettes.apk aligned.apk
```
La clé de signature (`lecayenne-etiquettes.jks`) n'est **jamais** dans le dépôt : en cas de perte, il faut désinstaller puis
réinstaller l'application. Aucune dépendance (WebView, Bluetooth et sockets sont dans Android) ; minSdk 24.

## Tester sans imprimante

`node native/labels-android/tools/fake-brother-tcp.js 9100` lance une fausse Brother Wi‑Fi (répond à l'état, décode chaque travail
avec le décodeur indépendant, confirme les pages). Dans l'émulateur, l'hôte est `10.0.2.2` (la version *debug* autorise le http).
Les tests JavaScript du pont : `tests/js/labelPrinterNative.spec.js`.

## État de vérification

Compilé, signé, manifeste vérifié ; adaptateur JavaScript testé avec un pont simulé. **Pas encore testé sur une tablette réelle**
(l'émulateur Android 36 exige 7 Go de disque libres).
