<?php

namespace App\Services;

use Exception;
use App\Enums\GatewayMode;
use App\Models\GatewayOption;
use App\Http\Resources\GatewayOptionsResource;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Dipokhalder\EnvEditor\EnvEditor;
use App\Http\Requests\PaginateRequest;
use Illuminate\Support\Facades\Artisan;
use App\Libraries\QueryExceptionLibrary;


class PaymentGatewayService
{
    public EnvEditor $envService;

    public function __construct(EnvEditor $envEditor)
    {
        $this->envService = $envEditor;
    }


    public object $gateway;
    protected array $paymentGatewayFilter = [
        'name',
        'slug',
        'status'
    ];

    protected array $exceptFilter = [
        'excepts'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'asc';

            return PaymentGateway::with('gatewayOptions')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->paymentGatewayFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update($validationRequests): object
    {
        try {
            if (!blank($validationRequests)) {
                foreach ($validationRequests as $key => $value) {
                    $option = GatewayOption::where('option', $key)->first();
                    if (!blank($option)) {
                        // [ONB-13 F-12 2026-08-27] Le second geste du masquage — sans lui,
                        // le premier casse les paiements.
                        //
                        // GatewayOptionsResource ne renvoie plus les secrets mais un masque.
                        // Le formulaire le renvoie donc tel quel quand l'utilisateur n'a pas
                        // touche au champ. Sans ce test, on ecrirait « ******** » dans la cle
                        // secrete de Stripe ou le jeton Twilio : les paiements et les SMS
                        // tomberaient, et l'ecran afficherait exactement la meme chose qu'avant.
                        $inchange = GatewayOptionsResource::estSecret($key)
                            && (string) $value === GatewayOptionsResource::MASQUE;

                        if (! $inchange) {
                            $option->value = $value;
                            $option->save();
                        }
                    }

                    if (str_contains($key, 'status')) {
                        $this->gateway = PaymentGateway::find($option->model_id);
                        if (!blank($this->gateway)) {
                            $this->gateway->status = $value;
                            $this->gateway->save();
                        }
                    }
                }
            }
            return $this->gateway;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
