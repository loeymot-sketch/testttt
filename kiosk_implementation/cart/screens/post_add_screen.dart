import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:foodking_kiosk/app/features/cart/controllers/cart_controller.dart';
import 'package:foodking_kiosk/app/features/cart/screens/cart_screen.dart';
import 'package:foodking_kiosk/app/features/menu/screens/menu_screen.dart';
import 'package:foodking_kiosk/theme/colors.dart';
import 'package:get/get.dart';

import '../../../../app/core/idle_service.dart';
import '../../../../theme/text_styles.dart';

class PostAddScreen extends StatefulWidget {
  final String itemName;
  final double itemPrice;

  const PostAddScreen({
    super.key,
    required this.itemName,
    required this.itemPrice,
  });

  @override
  State<PostAddScreen> createState() => _PostAddScreenState();
}

class _PostAddScreenState extends State<PostAddScreen>
    with SingleTickerProviderStateMixin {
  final cartController = Get.isRegistered<CartController>()
      ? Get.find<CartController>()
      : Get.put(CartController());

  late AnimationController _checkController;
  late Animation<double> _checkScale;
  Timer? _autoNavigateTimer;
  int _countdownSeconds = 8;
  bool _showButtons = false;

  @override
  void initState() {
    super.initState();
    _checkController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600),
    );
    _checkScale = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _checkController, curve: Curves.bounceOut),
    );

    // Play check animation
    _checkController.forward();

    // Show buttons after 1 second
    Future.delayed(const Duration(seconds: 1), () {
      if (mounted) {
        setState(() {
          _showButtons = true;
        });
      }
    });

    // Start auto-navigate countdown
    _startAutoNavigateTimer();
  }

  @override
  void dispose() {
    _checkController.dispose();
    _autoNavigateTimer?.cancel();
    super.dispose();
  }

  void _startAutoNavigateTimer() {
    _autoNavigateTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) {
        setState(() {
          _countdownSeconds--;
        });
        if (_countdownSeconds <= 0) {
          timer.cancel();
          _goToCart();
        }
      }
    });
  }

  void _goToCart() {
    _autoNavigateTimer?.cancel();
    Get.off(() => CartScreen());
  }

  void _continueShopping() {
    _autoNavigateTimer?.cancel();
    Get.off(() => MenuScreen());
  }

  @override
  Widget build(BuildContext context) {
    final cartCount = cartController.cartList.length;
    final cartTotal = cartController.cartTotalPrice;

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
        child: Scaffold(
          backgroundColor: Colors.white,
          body: SafeArea(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Spacer(),

                // Success check animation
                AnimatedBuilder(
                  animation: _checkScale,
                  builder: (context, child) {
                    return Transform.scale(
                      scale: _checkScale.value,
                      child: Container(
                        width: 150.w,
                        height: 150.w,
                        decoration: BoxDecoration(
                          color: AppColors.success.withOpacity(0.1),
                          shape: BoxShape.circle,
                        ),
                        child: Icon(
                          Icons.check_circle,
                          size: 80.w,
                          color: AppColors.success,
                        ),
                      ),
                    );
                  },
                ),

                SizedBox(height: 40.h),

                // Success text
                Text(
                  'Ajouté !',
                  style: MediumStyles.s56s.copyWith(
                    color: AppColors.success,
                    fontWeight: FontWeight.bold,
                  ),
                ),

                SizedBox(height: 20.h),

                // Item name
                Text(
                  widget.itemName,
                  style: MediumStyles.s38s.copyWith(
                    color: Colors.black87,
                  ),
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),

                SizedBox(height: 12.h),

                // Item price
                Text(
                  '+${cartController.formatPrice(widget.itemPrice)}',
                  style: MediumStyles.s42s.copyWith(
                    color: AppColors.primaryColor,
                    fontWeight: FontWeight.w600,
                  ),
                ),

                const Spacer(),

                // Auto-navigate countdown
                AnimatedOpacity(
                  opacity: _showButtons ? 1.0 : 0.0,
                  duration: const Duration(milliseconds: 300),
                  child: Column(
                    children: [
                      // Progress bar
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: 100.w),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(8.r),
                          child: LinearProgressIndicator(
                            value: _countdownSeconds / 8,
                            backgroundColor: Colors.grey.shade200,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              AppColors.primaryColor.withOpacity(0.5),
                            ),
                            minHeight: 8.h,
                          ),
                        ),
                      ),

                      SizedBox(height: 12.h),

                      // Countdown text
                      Text(
                        'Redirection automatique dans $_countdownSeconds s',
                        style: RegularStyles.s32s.copyWith(
                          color: Colors.grey.shade500,
                        ),
                      ),

                      SizedBox(height: 60.h),

                      // Buttons
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: 60.w),
                        child: Row(
                          children: [
                            // Continue shopping button
                            Expanded(
                              child: _buildActionButton(
                                icon: Icons.add,
                                label: 'Continuer',
                                sublabel: 'mes achats',
                                onTap: _continueShopping,
                                isSecondary: true,
                              ),
                            ),
                            SizedBox(width: 30.w),
                            // View cart button
                            Expanded(
                              child: _buildActionButton(
                                icon: Icons.shopping_cart,
                                label: 'Mon panier',
                                sublabel: '$cartCount articles • ${cartController.formatPrice(cartTotal)}',
                                onTap: _goToCart,
                                isSecondary: false,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),

                SizedBox(height: 80.h),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildActionButton({
    required IconData icon,
    required String label,
    required String sublabel,
    required VoidCallback onTap,
    required bool isSecondary,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 160.h,
        padding: EdgeInsets.all(20.w),
        decoration: BoxDecoration(
          color: isSecondary ? Colors.white : AppColors.primaryColor,
          borderRadius: BorderRadius.circular(20.r),
          border: Border.all(
            color: isSecondary ? Colors.grey.shade300 : AppColors.primaryColor,
            width: 2.w,
          ),
          boxShadow: isSecondary
              ? null
              : [
                  BoxShadow(
                    color: AppColors.primaryColor.withOpacity(0.3),
                    blurRadius: 15.r,
                    offset: Offset(0, 8.h),
                  ),
                ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 40.w,
              color: isSecondary ? AppColors.primaryColor : Colors.white,
            ),
            SizedBox(height: 12.h),
            Text(
              label,
              style: MediumStyles.s36s.copyWith(
                color: isSecondary ? Colors.black87 : Colors.white,
                fontWeight: FontWeight.w600,
              ),
            ),
            SizedBox(height: 8.h),
            Text(
              sublabel,
              style: RegularStyles.s28s.copyWith(
                color: isSecondary ? Colors.grey.shade600 : Colors.white.withOpacity(0.9),
              ),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}
