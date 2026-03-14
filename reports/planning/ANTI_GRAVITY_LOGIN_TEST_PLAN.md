# ANTI-GRAVITY E2E TEST PLAN — POST-LOGIN FIX & MENU
**Target:** FoodKing Web Application
**Date:** 12 Mars 2026
**Purpose:** Verify the implementation of LOGIN-02 (role-based post-login redirection) and the fix for the empty POS menu (Status::ACTIVE).

## Prerequisites
- Application running on `http://localhost:8000`
- Database seeded with `LeCayenneRoleLandingUrlSeeder`
- Test users exist with the correct roles:
  - `posoperator@example.com` (Role: POS Operator, landing_url: pos)
  - `chef@example.com` (Role: Chef, landing_url: kitchen-display-system)
  - `customer@example.com` (Role: Customer, landing_url: NULL)
- Menu items exist with `status = 5` (Active).

---

## Scenario 1: AG-LOGIN-A - POS Operator Redirection
**Objective:** Verify that a POS Operator is correctly redirected to `/admin/pos` after logging in.
**Steps:**
1. Navigate to `http://localhost:8000/login`.
2. Enter email: `posoperator@example.com`.
3. Enter password: `123456`.
4. Click the "Login" button.
5. Wait for the redirect.
**Expected Result:** 
- The final URL must be `http://localhost:8000/admin/pos`.
- The Point of Sale interface should be visible.

---

## Scenario 2: AG-LOGIN-B - Chef Redirection
**Objective:** Verify that a Chef is correctly redirected to `/admin/kitchen-display-system` after logging in.
**Steps:**
1. Navigate to `http://localhost:8000/login`.
2. Enter email: `chef@example.com`.
3. Enter password: `123456`.
4. Click the "Login" button.
5. Wait for the redirect.
**Expected Result:** 
- The final URL must be `http://localhost:8000/admin/kitchen-display-system`.
- The Kitchen Display System interface should be visible.

---

## Scenario 3: AG-LOGIN-C - Customer Redirection
**Objective:** Verify that a regular customer is redirected to the frontend home page (`/`) after logging in, maintaining previous behavior.
**Steps:**
1. Navigate to `http://localhost:8000/login`.
2. Enter email: `customer@example.com`.
3. Enter password: `123456`.
4. Click the "Login" button.
5. Wait for the redirect.
**Expected Result:** 
- The final URL must be `http://localhost:8000/` (or `http://localhost:8000/home`).
- The frontend homepage should be visible.

---

## Scenario 4: AG-MENU - POS Menu Visibility (Status Fix)
**Objective:** Verify that the POS menu correctly loads the active items (status = 5) after the database fix.
**Steps:**
1. Ensure you are logged in as an Admin or POS Operator.
2. Navigate to `http://localhost:8000/admin/pos`.
3. Observe the menu grid area in the center of the screen.
4. Verify the absence of the "No data available" fallback image.
**Expected Result:**
- The menu items (e.g., Tacos, Burgers) should be visibly populated in the grid.
- At least one item should be clickable to open the wizard.

---

## Execution Constraints
- Use the `browser_subagent` for full visual verification of the URLs and UI state.
- If browser execution fails due to capacity, fallback to API level verification via Tinker/PHPUnit mimicking the exact flow.
- Document assertions explicitly in the final report.
