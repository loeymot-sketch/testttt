import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_screenutil/flutter_screenutil.dart';
import 'package:foodking_kiosk/app/features/cart/controllers/cart_controller.dart';
import 'package:foodking_kiosk/app/features/menu/controllers/menu_controller.dart';
import 'package:foodking_kiosk/app/features/menu/data/model/menu_model.dart';
import 'package:foodking_kiosk/theme/colors.dart';
import 'package:get/get.dart';

import '../../../../app/core/idle_service.dart';
import '../../../../theme/text_styles.dart';
import '../../../data/body/add_to_cart_body.dart';
import '../../../features/order/screens/payment_screen.dart';

class DessertUpsellScreen extends StatefulWidget {
  const DessertUpsellScreen({super.key});

  @override
  State<DessertUpsellScreen> createState() => _DessertUpsellScreenState();
}

class _DessertUpsellScreenState extends State<DessertUpsellScreen> {
  final cartController = Get.isRegistered<CartController>()
      ? Get.find<CartController>()
      : Get.put(CartController());
  final menuController = Get.isRegistered<MenuuController>()
      ? Get.find<MenuuController>()
      : Get.put(MenuuController());

  List<Item> _dessertItems = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadDessertItems();
  }

  void _loadDessertItems() {
    // Filter dessert and drink items from menu
    final allItems = <Item>[];

    // Get items from dessert and drink categories
    for (var category in menuController.categoryList) {
      final catName = category.name?.toLowerCase() ?? '';
      if (catName.contains('dessert') ||
          catName.contains('boisson') ||
          catName.contains('desserts') ||
          catName.contains('drink') ||
          catName.contains('glace') ||
          catName.contains('ice')) {
        if (category.items != null) {
          allItems.addAll(category.items!);
        }
      }
    }

    // Sort by sort_order and take max 6
    allItems.sort((a, b) => (a.sortOrder ?? 0).compareTo(b.sortOrder ?? 0));
    _dessertItems = allItems.take(6).toList();

    setState(() {
      _isLoading = false;
    });

    // Auto-skip if no dessert items
    if (_dessertItems.isEmpty) {
      Future.delayed(const Duration(milliseconds: 300), () {
        Get.off(() => PaymentScreen());
      });
    }
  }

  void _addToCart(Item item) async {
    // Create add to cart body
    AddToCartBody addToCartBody = AddToCartBody(
      itemId: item.id ?? 0,
      name: item.name ?? '',
      price: double.tryParse(item.price ?? '0') ?? 0.0,
      image: item.cover ?? '',
      quantity: 1,
    );

    await cartController.addToCart(addToCartBody);

    // Show feedback and go to payment
    Get.snackbar(
      'Ajouté !',
      '${item.name} ajouté au panier',
      snackPosition: SnackPosition.TOP,
      backgroundColor: AppColors.success,
      colorText: Colors.white,
      duration: const Duration(seconds: 1),
    );

    Future.delayed(const Duration(milliseconds: 800), () {
      Get.off(() => PaymentScreen());
    });
  }

  void _skipToPayment() {
    Get.off(() => PaymentScreen());
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
          backgroundColor: Colors.white,
          appBar: AppBar(
            backgroundColor: Colors.white,
            elevation: 0,
            automaticallyImplyLeading: false,
            actions: [
              // Skip button
              Padding(
                padding: EdgeInsets.only(right: 30.w, top: 20.h),
                child: GestureDetector(
                  onTap: _skipToPayment,
                  child: Row(
                    children: [
                      Text(
                        'Non merci',
                        style: RegularStyles.s32s.copyWith(
                          color: Colors.grey.shade600,
                        ),
                      ),
                      SizedBox(width: 8.w),
                      Icon(
                        Icons.arrow_forward,
                        color: Colors.grey.shade600,
                        size: 28.w,
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
          body: _isLoading
              ? const Center(child: CircularProgressIndicator())
              : SafeArea(
                  child: Column(
                    children: [
                      // Header
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: 40.w),
                        child: Column(
                          children: [
                            // Title with cake icon
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(
                                  Icons.cake,
                                  size: 48.sp,
                                  color: AppColors.primaryColor,
                                ),
                                SizedBox(width: 16.w),
                                Text(
                                  'Et pour finir ?',
                                  style: MediumStyles.s48s.copyWith(
                                    color: Colors.black87,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                            SizedBox(height: 16.h),

                            // Subtitle
                            Text(
                              'Craquez pour une petite douceur',
                              style: RegularStyles.s36s.copyWith(
                                color: Colors.grey.shade600,
                              ),
                            ),
                          ],
                        ),
                      ),

                      SizedBox(height: 40.h),

                      // Dessert grid
                      Expanded(
                        child: Padding(
                          padding: EdgeInsets.symmetric(horizontal: 40.w),
                          child: GridView.builder(
                            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 2,
                              childAspectRatio: 0.75,
                              crossAxisSpacing: 24.w,
                              mainAxisSpacing: 24.h,
                            ),
                            itemCount: _dessertItems.length,
                            itemBuilder: (context, index) {
                              final item = _dessertItems[index];
                              return _buildDessertCard(item);
                            },
                          ),
                        ),
                      ),

                      SizedBox(height: 20.h),

                      // Skip button at bottom
                      Padding(
                        padding: EdgeInsets.only(bottom: 40.h),
                        child: GestureDetector(
                          onTap: _skipToPayment,
                          child: Container(
                            padding: EdgeInsets.symmetric(
                              horizontal: 40.w,
                              vertical: 16.h,
                            ),
                            decoration: BoxDecoration(
                              border: Border.all(
                                color: Colors.grey.shade400,
                                width: 2.w,
                              ),
                              borderRadius: BorderRadius.circular(40.r),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  'Non merci, continuer',
                                  style: MediumStyles.s36s.copyWith(
                                    color: Colors.grey.shade600,
                                  ),
                                ),
                                SizedBox(width: 12.w),
                                Icon(
                                  Icons.arrow_forward,
                                  color: Colors.grey.shade600,
                                  size: 32.w,
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  Widget _buildDessertCard(Item item) {
    final price = double.tryParse(item.price ?? '0') ?? 0.0;
    final imageUrl = item.cover ?? '';

    return GestureDetector(
      onTap: () => _addToCart(item),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20.r),
          border: Border.all(
            color: Colors.grey.shade200,
            width: 2.w,
          ),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 10.r,
              offset: Offset(0, 4.h),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Image
            Expanded(
              flex: 3,
              child: ClipRRect(
                borderRadius: BorderRadius.vertical(
                  top: Radius.circular(18.r),
                ),
                child: imageUrl.isNotEmpty
                    ? Image.network(
                        imageUrl,
                        fit: BoxFit.cover,
                        errorBuilder: (context, error, stackTrace) {
                          return Container(
                            color: Colors.grey.shade100,
                            child: Icon(
                              Icons.image_not_supported,
                              color: Colors.grey.shade400,
                              size: 50.w,
                            ),
                          );
                        },
                      )
                    : Container(
                        color: Colors.grey.shade100,
                        child: Icon(
                          Icons.cake,
                          color: Colors.grey.shade400,
                          size: 50.w,
                        ),
                      ),
              ),
            ),

            // Info
            Expanded(
              flex: 2,
              child: Padding(
                padding: EdgeInsets.all(16.w),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Name
                    Text(
                      item.name ?? '',
                      style: MediumStyles.s32s.copyWith(
                        color: Colors.black87,
                      ),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),

                    const Spacer(),

                    // Price and Add button row
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        // Price
                        Text(
                          cartController.formatPrice(price),
                          style: MediumStyles.s36s.copyWith(
                            color: AppColors.primaryColor,
                            fontWeight: FontWeight.w600,
                          ),
                        ),

                        // Add button
                        Container(
                          width: 50.w,
                          height: 50.w,
                          decoration: BoxDecoration(
                            color: AppColors.primaryColor,
                            shape: BoxShape.circle,
                          ),
                          child: Icon(
                            Icons.add,
                            color: Colors.white,
                            size: 28.w,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
