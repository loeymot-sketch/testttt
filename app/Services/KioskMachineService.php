<?php

namespace App\Services;

use Exception;
use App\Enums\Ask;
use App\Enums\Status;
use App\Models\KioskMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\KioskMachineRequest;
use Laravel\Sanctum\PersonalAccessToken;

class KioskMachineService
{
    public object $machine;
    protected array $kioskMachineFilter = [
        'user_id',
        'branch_id',
        'machine_id',
        'username',
        'status'
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
            $orderType   = $request->get('order_type') ?? 'desc';

            return KioskMachine::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->kioskMachineFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
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
    public function store(KioskMachineRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->machine = KioskMachine::create([
                    'machine_id' => $request->machine_id,
                    'user_id'    => $request->user_id,
                    'username'   => $request->username,
                    'password'   => bcrypt($request->password),
                    'branch_id'  => $request->branch_id,
                    'status'     => $request->status,
                ]);
            });
            return $this->machine;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(KioskMachineRequest $request, KioskMachine $kioskMachine)
    {
        try {
            DB::transaction(function () use ($kioskMachine, $request) {
                $this->machine             = $kioskMachine;
                $this->machine->machine_id = $request->machine_id;
                $this->machine->user_id    = $request->user_id;
                $this->machine->username   = $request->username;
                $this->machine->branch_id  = $request->branch_id;
                $this->machine->status     = $request->status;
                if ($request->password) {
                    $this->machine->password = Hash::make($request->password);
                }
                $this->machine->save();
            });
            return $this->machine;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    /**
     * [ONB-10 T-1.1.1 2026-08-27] Révoquer le jeton d'accès de CETTE borne, et
     * d'elle seule.
     *
     * Le défaut trouvé : aucun des trois gestes du Dashboard — supprimer,
     * désactiver, déconnecter — ne révoquait le jeton Sanctum. Ils envoyaient une
     * notification Firebase « vous êtes déconnecté » et espéraient que la borne
     * obéisse. Une borne volée, hors ligne, ou dont l'application a été modifiée
     * continuait donc de commander pendant 8 heures (sanctum.expiration = 480).
     * Le seul chemin qui révoquait vraiment est la déconnexion demandée PAR LA
     * BORNE elle-même — c'est-à-dire précisément le cas où on n'en a pas besoin.
     *
     * La portée est l'appareil, pas le compte : les jetons de borne portent
     * `device_id = 'kiosk-<id>'` (posé à la connexion). On ne supprime que ceux-là.
     * Révoquer par `user_id` déconnecterait toutes les bornes partageant le compte —
     * c'est exactement le défaut corrigé le 2026-08-07 pour les écrans multiples, et
     * il ne faut pas le réintroduire ici.
     */
    private function revoquerJetonsDeLaBorne(KioskMachine $kioskMachine): int
    {
        return PersonalAccessToken::query()
            ->where('device_id', 'kiosk-' . $kioskMachine->id)
            ->delete();
    }

    public function destroy(KioskMachine $kioskMachine): void
    {
        try {
            DB::transaction(function () use ($kioskMachine) {
                // Le jeton part AVANT la suppression de la ligne : si la transaction
                // échoue ensuite, on aura révoqué une borne encore listée — gênant
                // mais sans danger. L'inverse laisserait un jeton vivant sans borne.
                $this->revoquerJetonsDeLaBorne($kioskMachine);
                $pushNotification = (object)[
                    'title'       => 'Kiosk Notification',
                    'description' => "Logged Out Successfully.",
                ];
                $fcmTokenArray = [];
                if (!blank($kioskMachine->device_token)) {
                    $fcmTokenArray[] = $kioskMachine->device_token;
                }
                $firebase         = new FirebaseService();
                $firebase->sendNotification($pushNotification, $fcmTokenArray, "kiosk-logout-notification");
                $kioskMachine->delete();
            });
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(KioskMachine $kioskMachine): KioskMachine
    {
        try {
            return $kioskMachine;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeStatus(KioskMachine $kioskMachine, Request $request): KioskMachine
    {
        try {
            DB::transaction(function () use ($kioskMachine, $request) {
                if ($request->filled('status')) {
                    $kioskMachine->update(['status' => $request->input('status')]);

                    // [ONB-10 T-1.1.1 2026-08-27] Désactiver une borne doit la couper
                    // pour de bon. On ne révoque QUE sur passage à inactif : réactiver
                    // ne doit rien détruire, la borne se reconnectera d'elle-même.
                    if ((int) $kioskMachine->status !== Status::ACTIVE) {
                        $this->revoquerJetonsDeLaBorne($kioskMachine);
                    }
                }
                $pushNotification = (object)[
                    'title'       => 'Kiosk Notification',
                    'description' => "Status Updated Successfully.",
                ];
                $fcmTokenArray = [];
                if (!blank($kioskMachine->device_token)) {
                    $fcmTokenArray[] = $kioskMachine->device_token;
                }
                $firebase         = new FirebaseService();
                $firebase->sendNotification($pushNotification, $fcmTokenArray, $kioskMachine->status === Status::ACTIVE ? "kiosk-status-on" : "kiosk-status-off");
            });
            return $kioskMachine;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function logout(KioskMachine $kioskMachine)
    {
        try {
            DB::transaction(function () use ($kioskMachine) {
                $pushNotification = (object)[
                    'title'       => 'Kiosk Notification',
                    'description' => "Logged Out Successfully.",
                ];
                $fcmTokenArray = [];
                if (!blank($kioskMachine->device_token)) {
                    $fcmTokenArray[] = $kioskMachine->device_token;
                }
                $firebase         = new FirebaseService();
                $firebase->sendNotification($pushNotification, $fcmTokenArray, "kiosk-logout-notification");
                // [ONB-10 T-1.1.1 2026-08-27] « Déconnecter » ne faisait que poser un
                // drapeau `is_login = NO` : la borne restait parfaitement capable de
                // commander. Le bouton disait une chose et faisait l'autre.
                $this->revoquerJetonsDeLaBorne($kioskMachine);
                $kioskMachine->update(['is_login' => Ask::NO]);
            });
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}