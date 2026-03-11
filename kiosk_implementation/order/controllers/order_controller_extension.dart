// EXTENSION pour OrderController — À AJOUTER à la fin du fichier existant
// Fichier: lib/app/features/order/controllers/order_controller.dart
//
// Ajoutez ces méthodes publiques à la classe OrderController existante
// Ces méthodes permettent la soumission de commande depuis PaymentScreen

// ══════════════════════════════════════════════════════════════════════════════
// PUBLIC API — pour PaymentScreen et autres appelants externes
// ══════════════════════════════════════════════════════════════════════════════

/// Réponse de la dernière commande créée (pour récupération de l'ID)
OrderDetailsData? orderResponse;

/// Place une commande POS avec paiement (utilisé par PaymentScreen)
/// 
/// [body] doit être un PlaceOrderBody avec tous les champs requis
/// Retourne l'ID de la commande créée ou 0 en cas d'erreur
Future<int> placeOrder(dynamic placeOrderBody) async {
  // [GUARD] Prevent duplicate submissions
  if (_isSubmitting) {
    debugPrint('[OrderController] placeOrder: already submitting, ignoring');
    return 0;
  }
  _isSubmitting = true;
  loader = true;
  update();

  try {
    final response = await server.postRequestWithToken(
      endPoint: APIList.order,  // ou APIList.dineInOrder selon le type
      body: json.encode({
        "branch_id": placeOrderBody.branchId,
        "order_type": placeOrderBody.orderType,
        "is_advance_order": placeOrderBody.isAdvanceOrder,
        "delivery_charge": placeOrderBody.deliveryCharge ?? 0,
        "address_id": placeOrderBody.addressId,
        "delivery_time": placeOrderBody.deliveryTime,
        "subtotal": placeOrderBody.subtotal,
        "total": placeOrderBody.total,
        "coupon_id": placeOrderBody.couponId ?? 0,
        "discount": placeOrderBody.discount ?? 0,
        "payment_method": placeOrderBody.posPaymentMethod ?? 1, // CASH default
        "pos_payment_method": placeOrderBody.posPaymentMethod,
        "pos_payment_note": placeOrderBody.posPaymentNote ?? '',
        "pos_received_amount": placeOrderBody.posReceivedAmount,
        "source": placeOrderBody.source ?? 10, // POS source
        "token": _generateOrderToken(),
        "loyalty_code": _getLoyaltyCode(),
        "items": json.encode(placeOrderBody.cartItems ?? []).toString(),
        "dining_table_id": placeOrderBody.diningTableId,
      }),
    );

    if (response != null && response.statusCode == 201) {
      final jsonResponse = json.decode(response.body);
      orderDetailsModel = OrderDetailsModel.fromJson(jsonResponse);
      
      if (orderDetailsModel.data == null) {
        customSnackbar("ERROR".tr, 'Erreur lors de la création de la commande', AppColors.error);
        return 0;
      }
      
      orderResponse = orderDetailsModel.data;
      orderDetailsData = orderDetailsModel.data!;

      // Credit loyalty points
      _creditLoyaltyPoints(placeOrderBody.total, orderDetailsData.token.toString());

      // Clear loyalty session
      _clearLoyaltySession();

      return orderDetailsData.id ?? 0;
    } else {
      final msg = response != null
          ? (json.decode(response.body)["message"] ?? 'Erreur lors de la commande')
          : 'Erreur de connexion';
      customSnackbar("ERROR".tr, msg, AppColors.error);
      return 0;
    }
  } catch (e) {
    debugPrint('[OrderController] placeOrder error: $e');
    customSnackbar("ERROR".tr, 'Erreur: $e', AppColors.error);
    return 0;
  } finally {
    loader = false;
    _isSubmitting = false;
    update();
  }
}

/// Alternative: Place order with full navigation (legacy style)
/// 
/// Cette méthode reproduit le comportement legacy pour compatibilité
Future<void> submitOrderWithNavigation({
  required dynamic placeOrderBody,
  String? tableNo,
}) async {
  final orderId = await placeOrder(placeOrderBody);
  
  if (orderId > 0 && orderResponse != null) {
    // Reset cart
    _resetCartState();
    
    // Navigate to confirmation
    Get.offAll(
      () => OrderConfirmScreen(
        orderID: orderResponse!.token.toString(),
        tableNo: tableNo ?? '',
        order: orderResponse!,
      ),
    );
  }
}

/// Reset cart state publique (pour appel externe)
void resetCartForNewCustomer() {
  _resetCartState();
}
