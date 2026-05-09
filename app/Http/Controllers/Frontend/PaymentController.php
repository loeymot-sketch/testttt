<?php

namespace App\Http\Controllers\Frontend;


use App\Enums\Activity;
use App\Enums\PaymentStatus;
use App\Http\Requests\PaymentRequest;
use App\Libraries\AppLibrary;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\ThemeSetting;
use App\Services\PaymentManagerService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Smartisan\Settings\Facades\Settings;

class PaymentController extends Controller
{
    private const STRIPE_GATEWAY_SLUG = 'stripe';

    private PaymentManagerService $paymentManagerService;
    private PaymentService $paymentService;

    public function __construct(PaymentManagerService $paymentManagerService, PaymentService $paymentService)
    {
        $this->paymentManagerService = $paymentManagerService;
        $this->paymentService = $paymentService;
    }

    public function index(
        Order $order
    ): \Illuminate\Contracts\View\Factory | \Illuminate\Contracts\View\View | \Illuminate\Contracts\Foundation\Application | \Illuminate\Http\RedirectResponse {
        $this->guardWebPaymentV1();

        $credit          = false;
        $paymentGateways = PaymentGateway::with('gatewayOptions')
            ->whereNotIn('id', [1])
            ->where(['status' => Activity::ENABLE])
            ->get()
            ->filter(fn (PaymentGateway $gateway) => $this->isPublicWebGatewayAllowed((string) $gateway->slug))
            ->values();
        $company         = Settings::group('company')->all();
        $logo            = ThemeSetting::where(['key' => 'theme_logo'])->first();
        $faviconLogo     = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();
        $currency        = Currency::findOrFail(Settings::group('site')->get('site_default_currency'));
        if ($order?->user?->balance >= $order->total) {
            $credit = true;
        }

        if (blank($order->transaction) && $order->payment_status === PaymentStatus::UNPAID) {
            return view('payment', [
                'company'         => $company,
                'logo'            => (object)['logo' => $logo?->logo ?? asset('images/theme/theme-logo.png')],
                'currency'        => $currency,
                'faviconLogo'     => (object)['faviconLogo' => $faviconLogo?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png')],
                'paymentGateways' => $paymentGateways,
                'order'           => $order,
                'creditAmount'    => AppLibrary::currencyAmountFormat($order?->user?->balance),
                'credit'          => $credit
            ]);
        }
        return redirect()->route('home')->with('error', trans('all.message.payment_canceled'));
    }

    public function payment(Order $order, PaymentRequest $request)
    {
        $this->guardWebPaymentV1();
        $this->assertGatewayActivationAllowed((string) $request->paymentMethod);
        $this->paymentService->assertPilotPaymentMethodAllowed($order, (string) $request->paymentMethod, 'payment.route');

        if ($this->paymentManagerService->gateway($request->paymentMethod)->status()) {
            $className = 'App\\Http\\PaymentGateways\\PaymentRequests\\' . ucfirst($request->paymentMethod);
            $gateway   = new $className;
            $request->validate($gateway->rules());
            return $this->paymentManagerService->gateway($request->paymentMethod)->payment($order, $request);
        } else {
            return redirect()->route('payment.index', ['order' => $order])->with(
                'error',
                trans('all.message.payment_gateway_disable')
            );
        }
    }

    public function success(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        $this->guardWebPaymentV1();
        $this->assertGatewayActivationAllowed((string) $paymentGateway->slug);

        return $this->paymentManagerService->gateway($paymentGateway->slug)->success($order, $request);
    }

    public function fail(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        $this->guardWebPaymentV1();
        $this->assertGatewayActivationAllowed((string) $paymentGateway->slug);

        return $this->paymentManagerService->gateway($paymentGateway->slug)->fail($order, $request);
    }

    public function cancel(PaymentGateway $paymentGateway, Order $order, Request $request)
    {
        $this->guardWebPaymentV1();
        $this->assertGatewayActivationAllowed((string) $paymentGateway->slug);

        return $this->paymentManagerService->gateway($paymentGateway->slug)->cancel($order, $request);
    }

    public function successful(
        Order $order
    ): \Illuminate\Contracts\View\Factory | \Illuminate\Contracts\View\View | \Illuminate\Contracts\Foundation\Application | \Illuminate\Http\RedirectResponse {
        $this->guardWebPaymentV1();

        $company     = Settings::group('company')->all();
        $logo        = ThemeSetting::where(['key' => 'theme_logo'])->first();
        $faviconLogo = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();

        if (!blank($order->transaction)) {
            return view('paymentSuccess', [
                'company'     => $company,
                'logo'        => (object)['logo' => $logo?->logo ?? asset('images/theme/theme-logo.png')],
                'faviconLogo' => (object)['faviconLogo' => $faviconLogo?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png')],
                'order'       => $order,
            ]);
        }
        return redirect()->route('home')->with('error', trans('all.message.payment_canceled'));
    }

    private function guardWebPaymentV1(): void
    {
        if (! (bool) config('payment.web_payment_v1.enabled', false)) {
            abort(404);
        }
    }

    private function isPublicWebGatewayAllowed(string $gatewaySlug): bool
    {
        return $this->isGatewayActivationAllowed($gatewaySlug)
            && $this->paymentService->isPilotPaymentMethodAllowed($gatewaySlug);
    }

    private function assertGatewayActivationAllowed(string $gatewaySlug): void
    {
        if (! $this->isGatewayActivationAllowed($gatewaySlug)) {
            abort(404);
        }
    }

    private function isGatewayActivationAllowed(string $gatewaySlug): bool
    {
        $method = strtolower(trim($gatewaySlug));

        if ($method !== self::STRIPE_GATEWAY_SLUG) {
            return true;
        }

        if (! (bool) config('payment.stripe.activation_guard.enabled', true)) {
            return true;
        }

        return (bool) config('payment.stripe.activation_guard.activation_gate_cleared', false);
    }
}
