import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:foodking_kiosk/app/features/cart/controllers/cart_controller.dart';
import 'package:foodking_kiosk/app/features/order/controllers/order_controller.dart';
import 'package:foodking_kiosk/theme/colors.dart';
import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';

import '../../../../app/core/idle_service.dart';
import '../../../../theme/text_styles.dart';
import '../../../../util/icons.dart';
import '../../../../widgets/custom_toast_widget.dart';
import '../../../../widgets/loader.dart';
import '../../../data/body/place_order_body.dart';
import '../../../enums/modules/isAdvanceOrderEnum.dart';
import '../../../enums/modules/orderTypeEnum.dart';
import '../../../enums/modules/posPaymentMethodEnum.dart';
import '../../../enums/modules/sourceEnum.dart';
import 'order_confirm_screen.dart';

class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key});

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  final box = GetStorage();
  final cartController = Get.isRegistered<CartController>()
      ? Get.find<CartController>()
      : Get.put(CartController());
  final orderController = Get.isRegistered<OrderController>()
      ? Get.find<OrderController>()
      : Get.put(OrderController());

  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    cartController.getCartList();
  }

  Future<void> _submitOrder(String paymentMethod) async {
    if (_isLoading) return;

    setState(() {
      _isLoading = true;
    });

    try {
      // Get order type from storage (set in OrderTypeScreen)
      final orderType = box.read('order_type') ?? kOrderTypeTakeaway;
      final isDineIn = orderType == kOrderTypeDineIn;

      // Build items list
      List<CartItem> cartItems = [];
      for (var cartItem in cartController.cartList) {
        cartItems.add(CartItem(
          itemId: cartItem.itemId ?? 0,
          price: cartItem.price ?? 0,
          quantity: cartItem.quantity ?? 1,
          itemVariations: cartItem.itemVariations ?? {},
          itemExtras: cartItem.itemExtras ?? [],
          instruction: cartItem.instruction ?? '',
        ));
      }

      // Calculate total
      double total = cartController.cartTotalPrice;

      // Create order body
      PlaceOrderBody placeOrderBody = PlaceOrderBody(
        branchId: cartController.branchId ?? 1,
        cartItems: cartItems,
        couponId: 0,
        orderType: isDineIn ? orderTypeEnum.DINING_TABLE : orderTypeEnum.TAKEAWAY,
        isAdvanceOrder: isAdvanceOrderEnum.NO,
        deliveryCharge: 0,
        source: sourceEnum.POS,
        total: total,
        posPaymentMethod: paymentMethod == 'cash'
            ? posPaymentMethodEnum.CASH
            : posPaymentMethodEnum.CARD,
        posPaymentNote: paymentMethod == 'cash' ? '' : 'CB',
        posReceivedAmount: paymentMethod == 'cash' ? total : null,
      );

      // Submit order
      await orderController.placeOrder(placeOrderBody);

      // Get order ID from response
      final orderId = orderController.orderResponse?.data?.id ?? 0;

      if (orderId > 0) {
        // Clear cart
        await cartController.clearCart();

        // Navigate to confirmation
        Get.off(() => OrderConfirmScreen(orderId: orderId));
      } else {
        alertService.error('Erreur lors de la création de la commande');
      }
    } catch (e) {
      alertService.error('Erreur: $e');
    } finally {
      setState(() {
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final cartItems = cartController.cartList;
    final subtotal = cartController.cartTotalPrice;
    final tax = subtotal * 0.10; // 10% TVA
    final total = subtotal + tax;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: const SystemUiOverlayStyle(
        systemNavigationBarColor: Colors.white,
        systemNavigationBarIconBrightness: Brightness.dark,
        statusBarIconBrightness: Brightness.dark,
        statusBarColor: Colors.transparent,
        statusBarBrightness: Brightness.dark,
      ),
      child: Listener(
        onPointerDown: (_) {
          if (Get.isRegistered<IdleService>()) {
            IdleService.to.reset();
          }
        },
        child: Stack(
          children: [
            Scaffold(
              backgroundColor: Colors.white,
              appBar: AppBar(
                backgroundColor: Colors.white,
                elevation: 0,
                leading: GestureDetector(
                  onTap: () => Get.back(),
                  child: Container(
                    margin: EdgeInsets.all(16.w),
                    decoration: BoxDecoration(
                      color: AppColors.whiteColor,
                      borderRadius: BorderRadius.circular(40.r),
                      border: Border.all(
                        color: AppColors.primaryColor,
                        width: 1.w,
                      ),
                    ),
                    child: Icon(
                      Icons.arrow_back,
                      color: AppColors.primaryColor,
                      size: 28.w,
                    ),
                  ),
                ),
                title: Text(
                  'Récapitulatif',
                  style: MediumStyles.s42s.copyWith(
                    color: Colors.black87,
                  ),
                ),
                centerTitle: true,
              ),
              body: SafeArea(
                child: Column(
                  children: [
                    // Order summary section (40%)
                    Expanded(
                      flex: 4,
                      child: Container(
                        margin: EdgeInsets.all(24.w),
                        padding: EdgeInsets.all(24.w),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade50,
                          borderRadius: BorderRadius.circular(20.r),
                          border: Border.all(
                            color: Colors.grey.shade200,
                            width: 1.w,
                          ),
                        ),
                        child: Column(
                          children: [
                            // Items list
                            Expanded(
                              child: ListView.builder(
                                itemCount: cartItems.length,
                                itemBuilder: (context, index) {
                                  final item = cartItems[index];
                                  return Padding(
                                    padding: EdgeInsets.symmetric(vertical: 8.h),
                                    child: Row(
                                      children: [
                                        // Quantity
                                        Container(
                                          padding: EdgeInsets.symmetric(
                                            horizontal: 12.w,
                                            vertical: 4.h,
                                          ),
                                          decoration: BoxDecoration(
                                            color: AppColors.primaryColor.withOpacity(0.1),
                                            borderRadius: BorderRadius.circular(8.r),
                                          ),
                                          child: Text(
                                            '${item.quantity}x',
                                            style: MediumStyles.s32s.copyWith(
                                              color: AppColors.primaryColor,
                                            ),
                                          ),
                                        ),
                                        SizedBox(width: 16.w),

                                        // Name
                                        Expanded(
                                          child: Text(
                                            item.name ?? '',
                                            style: RegularStyles.s32s.copyWith(
                                              color: Colors.black87,
                                            ),
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),

                                        // Price
                                        Text(
                                          cartController.formatPrice(
                                            (item.price ?? 0) * (item.quantity ?? 1),
                                          ),
                                          style: MediumStyles.s32s.copyWith(
                                            color: Colors.black87,
                                          ),
                                        ),
                                      ],
                                    ),
                                  );
                                },
                              ),
                            ),

                            Divider(height: 32.h, thickness: 1.w),

                            // Totals
                            _buildTotalRow('Sous-total', subtotal, false),
                            SizedBox(height: 8.h),
                            _buildTotalRow('TVA (10%)', tax, false),
                            SizedBox(height: 12.h),
                            _buildTotalRow('TOTAL', total, true),
                          ],
                        ),
                      ),
                    ),

                    // Payment methods section (60%)
                    Expanded(
                      flex: 6,
                      child: Container(
                        padding: EdgeInsets.all(40.w),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.vertical(
                            top: Radius.circular(40.r),
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.05),
                              blurRadius: 20.r,
                              offset: Offset(0, -10.h),
                            ),
                          ],
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Mode de paiement',
                              style: MediumStyles.s42s.copyWith(
                                color: Colors.black87,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            SizedBox(height: 40.h),

                            // Card payment button
                            _buildPaymentButton(
                              icon: Icons.credit_card,
                              title: 'Carte Bancaire',
                              subtitle: 'Paiement par CB ou sans contact',
                              color: Colors.blue,
                              onTap: () => _submitOrder('card'),
                            ),

                            SizedBox(height: 24.h),

                            // Cash payment button
                            _buildPaymentButton(
                              icon: Icons.payments,
                              title: 'Espèces au comptoir',
                              subtitle: 'Payez au comptoir avant de manger',
                              color: Colors.green,
                              onTap: () => _submitOrder('cash'),
                            ),

                            const Spacer(),

                            // Loyalty hint (if enabled)
                            Container(
                              padding: EdgeInsets.all(20.w),
                              decoration: BoxDecoration(
                                color: Colors.amber.withOpacity(0.1),
                                borderRadius: BorderRadius.circular(16.r),
                                border: Border.all(
                                  color: Colors.amber.withOpacity(0.3),
                                  width: 1.w,
                                ),
                              ),
                              child: Row(
                                children: [
                                  Icon(
                                    Icons.stars,
                                    color: Colors.amber,
                                    size: 32.w,
                                  ),
                                  SizedBox(width: 16.w),
                                  Expanded(
                                    child: Text(
                                      'Programme de fidélité disponible au comptoir',
                                      style: RegularStyles.s28s.copyWith(
                                        color: Colors.amber.shade800,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // Loading overlay
            if (_isLoading)
              Container(
                color: Colors.black.withOpacity(0.5),
                child: const Center(
                  child: Loader(),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildTotalRow(String label, double amount, bool isTotal) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: isTotal
              ? MediumStyles.s42s.copyWith(
                  color: Colors.black87,
                  fontWeight: FontWeight.bold,
                )
              : RegularStyles.s32s.copyWith(
                  color: Colors.grey.shade600,
                ),
        ),
        Text(
          cartController.formatPrice(amount),
          style: isTotal
              ? MediumStyles.s48s.copyWith(
                  color: AppColors.primaryColor,
                  fontWeight: FontWeight.bold,
                )
              : MediumStyles.s36s.copyWith(
                  color: Colors.black87,
                ),
        ),
      ],
    );
  }

  Widget _buildPaymentButton({
    required IconData icon,
    required String title,
    required String subtitle,
    required Color color,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 140.h,
        padding: EdgeInsets.symmetric(horizontal: 32.w),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(24.r),
          border: Border.all(
            color: color.withOpacity(0.3),
            width: 2.w,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 80.w,
              height: 80.w,
              decoration: BoxDecoration(
                color: color.withOpacity(0.2),
                shape: BoxShape.circle,
              ),
              child: Icon(
                icon,
                color: color,
                size: 40.w,
              ),
            ),
            SizedBox(width: 24.w),
            Expanded(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: MediumStyles.s40s.copyWith(
                      color: Colors.black87,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  SizedBox(height: 8.h),
                  Text(
                    subtitle,
                    style: RegularStyles.s28s.copyWith(
                      color: Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
            ),
            Icon(
              Icons.arrow_forward_ios,
              color: color,
              size: 28.w,
            ),
          ],
        ),
      ),
    );
  }
}
