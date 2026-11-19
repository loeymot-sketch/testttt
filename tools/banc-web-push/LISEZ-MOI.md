# Banc de la chaîne Web Push (local uniquement)

`chaine-push.cjs` rejoue la chaîne RÉELLE : vraie commande (API) → vrais changements de statut par la caisse → vrai écouteur → vraie file Redis →
vraie requête Web Push CHIFFRÉE (RFC 8291) vers un faux service de notification local, que le script DÉCHIFFRE pour lire le texte. 20 contrôles.

Pièges déjà rencontrés (2026-11-20) :
- `php artisan serve` ne transmet PAS les variables d'environnement : lancer `php -S 127.0.0.1:8766 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
  DEPUIS `public/`, avec `WEBPUSH_ENABLED=true WEBPUSH_PUBLIC_KEY=… WEBPUSH_PRIVATE_KEY=… WEBPUSH_ALLOW_INSECURE_ENDPOINTS=true WEBPUSH_ALLOWED_HOSTS=127.0.0.1 REDIS_QUEUE=e2epush`
  (clés jetables : `php artisan webpush:keys`).
- Un worker sur la même file : `REDIS_QUEUE=e2epush php artisan queue:work redis --queue=e2epush` (file isolée : la file `default` locale peut contenir des centaines de tâches périmées).
- Les clés Redis de Laravel sont PRÉFIXÉES : `redis-cli llen queues:default` répond 0 à tort ; mesurer par `Redis::connection()->llen(...)` dans tinker.
- Le restaurant local est fermé hors 18:00-00:30 (réglage `horaires`) : l'élargir le temps du banc et le RESTAURER.
- Une commande « payer sur place » ne crédite aucun point avant l'encaissement : le banc la passe en payée comme le ferait le paiement en ligne.
Lancement : `LC_BACKEND_ROOT=$PWD node tools/banc-web-push/chaine-push.cjs` (Node 22 ; serveur sur :8766).
