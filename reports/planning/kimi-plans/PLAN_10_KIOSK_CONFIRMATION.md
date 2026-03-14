# PLAN_10 — D-011 + KUX-01 à KUX-05 : Kiosk — Confirmation Forte + Idle Warning
**Phase :** P2 — Moyenne
**Test-Type :** Anti-Gravity (test navigateur/device)
**Impact :** 🟡 UX — Confirmation commande faible + reset idle sans avertissement = frustration client
**Fichiers :** Projet Flutter Kiosk
- `lib/views/screens/checkout/payment_screen.dart` (ou `success_screen.dart`)
- `lib/services/idle_service.dart` (ou `IdleService`)
- `lib/views/screens/home/home_screen.dart`

---

## 1. Contexte & Problème

Le rapport identifie 5 problèmes Kiosk UX (KUX-01 à KUX-05) :

| ID | Problème | Impact |
|----|---------|--------|
| KUX-01 | Confirmation paiement faible (pas de bip/animation forte) | Client incertain que la commande est passée |
| KUX-02 | Idle reset sans avertissement (timer 3 min) | Commande perdue si client s'arrête brusquement |
| KUX-03 | Temps d'attente estimé non affiché | Client ne sait pas combien de temps attendre |
| KUX-04 | MON COMPTE / ALLERGÈNES absent du header | Non-conformité réglementaire (allergènes) |
| KUX-05 | Barre de progression absente dans le wizard | Différent de GUR KEBAB standard |

Ce plan traite **KUX-01** (confirmation) et **KUX-02** (idle warning) en priorité.
**KUX-04** (allergènes) nécessite une décision métier/légale → escalader à Claude.
**KUX-03** et **KUX-05** → phases ultérieures.

---

## 2. Implémentation Flutter

### 2.1 KUX-01 — Confirmation Forte (OrderSuccessScreen)

**Objectif :** Animation plein écran + son (si device le permet) quand la commande est confirmée.

```dart
// Dans lib/views/screens/checkout/order_success_screen.dart (ou équivalent)

class OrderSuccessScreen extends StatefulWidget {
  final String orderNumber;
  const OrderSuccessScreen({Key? key, required this.orderNumber}) : super(key: key);

  @override
  State<OrderSuccessScreen> createState() => _OrderSuccessScreenState();
}

class _OrderSuccessScreenState extends State<OrderSuccessScreen>
    with TickerProviderStateMixin {
  late AnimationController _checkController;
  late AnimationController _pulseController;

  @override
  void initState() {
    super.initState();
    // Animation checkmark
    _checkController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600),
    )..forward();

    // Animation pulse sur le numéro
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    )..repeat(reverse: true);

    // Son de confirmation (si AudioPlayers disponible)
    _playSuccessSound();

    // Auto-retour à l'accueil après 8s
    Future.delayed(const Duration(seconds: 8), () {
      if (mounted) Get.offAllNamed('/home');
    });
  }

  void _playSuccessSound() {
    // Si le projet utilise audioplayers ou just_audio :
    // AudioPlayer().play(AssetSource('sounds/success.mp3'));
    // Sinon ignorer — l'animation suffit
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF1B1B3A),
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Checkmark animé
            ScaleTransition(
              scale: CurvedAnimation(parent: _checkController, curve: Curves.elasticOut),
              child: Container(
                width: 120, height: 120,
                decoration: BoxDecoration(
                  color: const Color(0xFF43C6AC),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.check_rounded, size: 70, color: Colors.white),
              ),
            ),
            const SizedBox(height: 32),
            const Text(
              'Commande confirmée !',
              style: TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 16),
            // Numéro de commande avec pulse
            AnimatedBuilder(
              animation: _pulseController,
              builder: (_, __) => Transform.scale(
                scale: 1.0 + (_pulseController.value * 0.05),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 12),
                  decoration: BoxDecoration(
                    border: Border.all(color: const Color(0xFF43C6AC), width: 2),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    '#${widget.orderNumber}',
                    style: const TextStyle(
                      color: Color(0xFF43C6AC), fontSize: 42, fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 24),
            const Text(
              'Présentez ce numéro au comptoir',
              style: TextStyle(color: Colors.white70, fontSize: 16),
            ),
          ],
        ),
      ),
    );
  }
}
```

### 2.2 KUX-02 — Idle Warning (60s avant reset)

**Objectif :** Afficher un overlay de compte à rebours 30s avant le reset automatique.

```dart
// Dans lib/services/idle_service.dart

class IdleService extends GetxService {
  static const int idleTimeoutSeconds = 180; // 3 minutes
  static const int warningThresholdSeconds = 150; // Alerte à 2min30 (30s avant reset)

  Timer? _idleTimer;
  Timer? _warningTimer;
  final RxBool showWarning = false.obs;
  final RxInt warningCountdown = 30.obs;

  void resetIdle() {
    showWarning.value = false;
    _idleTimer?.cancel();
    _warningTimer?.cancel();

    // Timer principal
    _idleTimer = Timer(const Duration(seconds: idleTimeoutSeconds), _onIdleTimeout);

    // Timer d'avertissement
    _warningTimer = Timer(const Duration(seconds: warningThresholdSeconds), _onWarning);
  }

  void _onWarning() {
    showWarning.value = true;
    warningCountdown.value = 30;
    // Compte à rebours
    Timer.periodic(const Duration(seconds: 1), (t) {
      warningCountdown.value--;
      if (warningCountdown.value <= 0) {
        t.cancel();
      }
    });
  }

  void _onIdleTimeout() {
    showWarning.value = false;
    Get.offAllNamed('/idle'); // Retour à l'écran d'attente
  }

  void cancelReset() {
    // Appelé si le client clique "Continuer"
    resetIdle();
  }
}
```

**Overlay dans le HomeScreen ou widget global :**
```dart
// Wrappe l'écran principal avec un Stack
Obx(() => idleService.showWarning.value
    ? _IdleWarningOverlay(
        countdown: idleService.warningCountdown.value,
        onContinue: idleService.cancelReset,
      )
    : const SizedBox.shrink()
),

// Widget overlay
class _IdleWarningOverlay extends StatelessWidget {
  final int countdown;
  final VoidCallback onContinue;

  Widget build(BuildContext context) {
    return Container(
      color: Colors.black54,
      child: Center(
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('Toujours là ?', style: TextStyle(fontSize: 24)),
                Text('Réinitialisation dans $countdown s', style: TextStyle(color: Colors.red)),
                ElevatedButton(
                  onPressed: onContinue,
                  child: const Text('Continuer ma commande'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

---

## 3. Tests Anti-Gravity

**Tests navigateur/device :**
1. Passer une commande → vérifier l'écran de succès avec animation checkmark verte
2. Rester inactif 2min30 → vérifier que l'overlay "Toujours là ?" apparaît
3. Cliquer "Continuer" → overlay disparaît, commande préservée
4. Ne pas cliquer → après 30s supplémentaires, retour à l'écran idle

---

## 4. Critères de Succès

- [ ] Écran de confirmation : checkmark animé + numéro de commande visible en grand
- [ ] Auto-retour accueil après 8s sur l'écran de succès
- [ ] Overlay d'avertissement à 2min30 d'inactivité
- [ ] Bouton "Continuer ma commande" fonctionnel
- [ ] Reset automatique à 3min d'inactivité
- [ ] Anti-Gravity valide les deux scénarios

---

## 5. NE PAS Toucher

- La logique de paiement (PaymentScreen)
- Le flux de commande (CartScreen, OrderTypeScreen)
- L'API POST `/api/frontend/order` — ne pas modifier le backend
