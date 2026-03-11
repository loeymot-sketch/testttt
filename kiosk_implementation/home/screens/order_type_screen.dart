import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:foodking_kiosk/app/features/menu/screens/menu_screen.dart';
import 'package:foodking_kiosk/theme/colors.dart';
import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';

import '../../../../app/core/idle_service.dart';
import '../../../../theme/text_styles.dart';
import '../../../../util/images.dart';

// GetStorage key constants
const String kOrderType = 'order_type';
const String kOrderTypeDineIn = 'dine_in';
const String kOrderTypeTakeaway = 'takeaway';

class OrderTypeScreen extends StatefulWidget {
  const OrderTypeScreen({super.key});

  @override
  State<OrderTypeScreen> createState() => _OrderTypeScreenState();
}

class _OrderTypeScreenState extends State<OrderTypeScreen>
    with SingleTickerProviderStateMixin {
  final box = GetStorage();
  String? selectedType;
  late AnimationController _scaleController;

  @override
  void initState() {
    super.initState();
    _scaleController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 200),
      lowerBound: 0.95,
      upperBound: 1.0,
    );
    _scaleController.value = 1.0;
  }

  @override
  void dispose() {
    _scaleController.dispose();
    super.dispose();
  }

  void _selectOrderType(String type) {
    setState(() {
      selectedType = type;
    });
    _scaleController.forward(from: 0.95).then((_) {
      // Store selection
      box.write(kOrderType, type);
      // Navigate to menu after short delay for animation
      Future.delayed(const Duration(milliseconds: 300), () {
        Get.off(() => MenuScreen());
      });
    });
  }

  @override
  Widget build(BuildContext context) {
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
          body: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  AppColors.primaryColor.withOpacity(0.1),
                  Colors.white,
                  Colors.white,
                ],
              ),
            ),
            child: SafeArea(
              child: Column(
                children: [
                  // Logo section
                  SizedBox(height: 60.h),
                  Image.asset(
                    Images.splashLogo,
                    height: 120.h,
                    width: 120.w,
                  ),
                  SizedBox(height: 40.h),

                  // Title
                  Text(
                    'Bienvenue au Grill House',
                    style: MediumStyles.s42s.copyWith(
                      color: Colors.black87,
                      fontWeight: FontWeight.w600,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: 20.h),

                  // Subtitle
                  Text(
                    'Comment souhaitez-vous commander ?',
                    style: RegularStyles.s38s.copyWith(
                      color: Colors.grey.shade600,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: 80.h),

                  // Order type cards
                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: 60.w),
                    child: Row(
                      children: [
                        // Sur place
                        Expanded(
                          child: _buildOrderTypeCard(
                            type: kOrderTypeDineIn,
                            icon: Icons.restaurant,
                            title: 'Sur place',
                            subtitle: 'Pour manger ici',
                          ),
                        ),
                        SizedBox(width: 40.w),
                        // À emporter
                        Expanded(
                          child: _buildOrderTypeCard(
                            type: kOrderTypeTakeaway,
                            icon: Icons.shopping_bag,
                            title: 'À emporter',
                            subtitle: 'Pour emporter',
                          ),
                        ),
                      ],
                    ),
                  ),

                  const Spacer(),

                  // Admin link (discrete)
                  Padding(
                    padding: EdgeInsets.only(bottom: 40.h),
                    child: GestureDetector(
                      onTap: () {
                        // Navigate to admin login
                        // Get.to(() => LoginScreen());
                      },
                      child: Text(
                        'Accès Admin',
                        style: RegularStyles.s28s.copyWith(
                          color: Colors.grey.shade400,
                          decoration: TextDecoration.underline,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildOrderTypeCard({
    required String type,
    required IconData icon,
    required String title,
    required String subtitle,
  }) {
    final isSelected = selectedType == type;
    final isDineIn = type == kOrderTypeDineIn;

    return GestureDetector(
      onTap: () => _selectOrderType(type),
      child: AnimatedBuilder(
        animation: _scaleController,
        builder: (context, child) {
          return Transform.scale(
            scale: isSelected ? _scaleController.value : 1.0,
            child: Container(
              height: 280.h,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(24.r),
                border: Border.all(
                  color: isSelected
                      ? AppColors.primaryColor
                      : Colors.grey.shade300,
                  width: isSelected ? 4.w : 2.w,
                ),
                boxShadow: [
                  BoxShadow(
                    color: isSelected
                        ? AppColors.primaryColor.withOpacity(0.3)
                        : Colors.black.withOpacity(0.1),
                    blurRadius: isSelected ? 20.r : 10.r,
                    offset: Offset(0, 8.h),
                  ),
                ],
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  // Icon
                  Container(
                    width: 100.w,
                    height: 100.w,
                    decoration: BoxDecoration(
                      color: isDineIn
                          ? AppColors.primaryColor.withOpacity(0.1)
                          : Colors.green.withOpacity(0.1),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(
                      icon,
                      size: 50.w,
                      color: isDineIn ? AppColors.primaryColor : Colors.green,
                    ),
                  ),
                  SizedBox(height: 30.h),

                  // Title
                  Text(
                    title,
                    style: MediumStyles.s42s.copyWith(
                      color: Colors.black87,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  SizedBox(height: 12.h),

                  // Subtitle
                  Text(
                    subtitle,
                    style: RegularStyles.s32s.copyWith(
                      color: Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
