# Firebase Cloud Messaging (FCM) Setup — Phase 36 (P1)

Ce document explique comment configurer les notifications push pour FoodKing (KDS, POS, clients).

---

## Architecture FCM

```
FoodKing Backend ──► FCM API ──► Mobile Apps / Web / KDS
     │                               │
     └──► SendFcmNotificationJob    └──► Topics (kitchen_branch_123)
          (queued)                        (customer_order_456)
```

**Topics utilisés :**
- `kitchen_branch_{id}` : Notifications cuisine (nouvelle commande, en préparation, prête)
- `pos_branch_{id}` : Notifications caisse (commande web/kiosque)
- `customer_order_{id}` : Notifications client (commande prête, livrée, annulée)
- `oss_branch_{id}` : Notifications écran de statut

---

## 1. Créer un projet Firebase

1. Allez sur [Firebase Console](https://console.firebase.google.com/)
2. Créez un nouveau projet (ex: `foodking-prod`)
3. Ajoutez une app Android et/ou Web
4. Téléchargez le fichier de configuration :
   - Android : `google-services.json`
   - Web : configuration JS (API key, etc.)

---

## 2. Récupérer la Server Key

1. Firebase Console → Project Settings → Cloud Messaging
2. Copiez la **Server Key** (legacy key pour HTTP API)
   ```
   AAAAxxxxx... (long string ~150 chars)
   ```

3. Alternative : OAuth2 pour FCM v1 (plus sécurisé, mais legacy key suffisant pour démarrer)

---

## 3. Configuration `.env`

```env
# FCM Configuration
FCM_SERVER_KEY=AAAAxxxxx...
FCM_SENDER_ID=123456789
FCM_TOPIC_PREFIX=foodking
```

**Note :** Le code actuel utilise l'API Legacy HTTP (plus simple). Pour FCM HTTP v1, il faudra :
- Télécharger le fichier de compte de service JSON
- Le convertir en Bearer token OAuth2
- Modifier `FcmNotificationService.php`

---

## 4. Souscription aux Topics (Côté Client)

### Android (Java/Kotlin)

```java
// Subscribe kitchen staff to their branch topic
FirebaseMessaging.getInstance().subscribeToTopic("kitchen_branch_123")
    .addOnCompleteListener(task -> {
        if (task.isSuccessful()) {
            Log.d("FCM", "Subscribed to kitchen topic");
        }
    });
```

### Web (JavaScript)

```javascript
// Service Worker + Firebase SDK
import { getMessaging, subscribeToTopic } from 'firebase/messaging';

// Note: Web can't subscribe directly to topics via SDK
// Must use backend API or device token targeting
```

**Pour Web**, il faut utiliser le device token et le stocker côté backend :

```javascript
// Get FCM token
const token = await getToken(messaging, { vapidKey: 'YOUR_VAPID_KEY' });

// Send token to backend
await fetch('/api/fcm-token', {
    method: 'POST',
    body: JSON.stringify({ token, order_id: 456 })
});
```

---

## 5. Test des Notifications

### Test via cURL

```bash
curl -X POST https://fcm.googleapis.com/fcm/send \
  -H "Authorization: key=YOUR_SERVER_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "/topics/kitchen_branch_123",
    "notification": {
      "title": "Test notification",
      "body": "Hello from FoodKing!"
    },
    "data": {
      "type": "test",
      "order_id": 123
    }
  }'
```

### Test via Laravel Tinker

```bash
php artisan tinker
```

```php
use App\Services\FcmNotificationService;

// Test topic
FcmNotificationService::sendToTopic(
    'kitchen_branch_123',
    'Test Kitchen',
    'Nouvelle commande #001',
    ['order_id' => 1]
);

// Test device token (replace with real token)
FcmNotificationService::sendToToken(
    'fcm_token_here...',
    'Test Direct',
    'Notification directe',
    ['test' => true]
);
```

---

## 6. Intégration dans le Code

Les notifications sont automatiquement envoyées via les listeners :

**OrderCreated** → `SendFcmOnOrderCreated` :
- Notifie la cuisine (nouvelle commande)
- Notifie le POS (commande externe)
- Notifie le client (confirmation)

**OrderStatusChanged** → `SendFcmOnOrderStatusChange` :
- PREPARING : Cuisine en cours
- PREPARED : Commande prête + OSS update
- DELIVERED : Commande livrée
- CANCELLED : Commande annulée

---

## 7. Monitoring et Logs

### Logs Laravel

```bash
tail -f storage/logs/laravel.log | grep FCM
```

### Vérifier les jobs FCM en attente

```bash
php artisan queue:monitor
# ou
mysql -e "SELECT COUNT(*) FROM jobs WHERE queue = 'default';"
```

### Failed Jobs

```bash
php artisan queue:failed
```

---

## 8. Sécurité

**Server Key Protection :**
- Jamais commit dans le repo
- Utilisez `.env` uniquement
- Restreignez la Server Key dans Firebase Console aux IPs de production

**Topic Naming :**
- Toujours prefixer avec `foodking_` (configurable via `FCM_TOPIC_PREFIX`)
- Évitez les topics génériques comme `all` ou `kitchen`

---

## 9. Troubleshooting

### "Unauthorized" 401
- Vérifiez la Server Key dans `.env`
- Vérifiez que le projet Firebase est actif

### "NotRegistered" 400
- Le device token est invalide ou expiré
- Le client doit ré-enregistrer son token

### Notifications non reçues
- Vérifiez la souscription au topic (logs client)
- Vérifiez que le job a été dispatched (`storage/logs/laravel.log`)
- Vérifiez le worker queue est actif (`supervisorctl status`)

### iOS uniquement : pas de notification en background
- Activez "Remote Notifications" dans Xcode → Capabilities
- Envoyez `content-available: 1` dans la payload

---

## Références

- [FCM HTTP Legacy API](https://firebase.google.com/docs/cloud-messaging/http-server-ref)
- [FCM HTTP v1 API](https://firebase.google.com/docs/cloud-messaging/migrate-v1)
- [Laravel Queues](https://laravel.com/docs/9.x/queues)
