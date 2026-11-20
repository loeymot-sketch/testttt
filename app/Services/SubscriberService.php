<?php

namespace App\Services;


use Exception;
use App\Models\Subscriber;
use App\Mail\SubscriberMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\SubscriberRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\SubscriberEmailRequest;


class SubscriberService
{
    /** [SUPERVISION 2026-11-13] Nombre d'adresses refusées par le serveur d'envoi lors du dernier sendEmail(). */
    private int $dernierEnvoiRefuses = 0;

    public function dernierEnvoiRefuses(): int
    {
        return $this->dernierEnvoiRefuses;
    }


    protected array $subscriberCateFilter = [
        'email',
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
            $orderType   = $request->get('order_type') ?? 'desc';

            return Subscriber::where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = Date('Y-m-d', strtotime($requests['from_date']));
                    $last_date  = Date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('created_at', '>=', $first_date)->whereDate(
                        'created_at',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->subscriberCateFilter)) {
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
    public function destroy(Subscriber $subscriber): void
    {
        try {
            $subscriber->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * @param SubscriberRequest $request
     * @return Subscriber
     * @throws Exception
     */
    public function store(SubscriberRequest $request): Subscriber
    {
        try {
            $subscriber        = new Subscriber;
            $subscriber->email = $request->email;
            $subscriber->save();
            return $subscriber;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @param SubscriberRequest $request
     * @return Subscriber
     * @throws Exception
     */
    public function sendEmail(SubscriberEmailRequest $request): int
    {
        try {
            $subscribers = Subscriber::pluck('email');

            // [ONB-09 2026-08-28] La méthode sortait ici SANS RIEN DIRE quand la liste
            // était vide, et le contrôleur répondait « Email envoyé avec succès » sans
            // condition. Mesuré sur la base de travail : 0 abonné — donc 100 % des
            // envois depuis cet écran étaient des faux succès. Le commerçant rédige son
            // message, l'envoie, voit une confirmation verte, et personne ne l'a reçu.
            //
            // On rend désormais le NOMBRE de destinataires, pour que l'appelant puisse
            // dire la vérité. Zéro n'est pas une erreur — c'est une information.
            if ($subscribers->isEmpty()) {
                return 0;
            }

            // [S13-04 · 2026-11-13] Un e-mail PAR destinataire (au lieu d'une copie cachée commune) : chacun porte SON lien
            // de désinscription signé — obligatoire pour un e-mail d'offre.
            // [SUPERVISION 2026-11-13] Chaque destinataire est ISOLÉ : une adresse refusée par le serveur d'envoi (550…)
            // interrompait la boucle — les abonnés suivants ne recevaient rien, l'admin voyait une erreur, et une relance
            // renvoyait l'offre à ceux d'avant. Les refus sont comptés (dernierEnvoiRefuses()) et journalisés.
            $this->dernierEnvoiRefuses = 0;
            $envoyes = 0;
            $derniereErreur = null;
            foreach ($subscribers as $email) {
                try {
                    $lien = \Illuminate\Support\Facades\URL::signedRoute('newsletter.desinscription', ['email' => $email]);
                    Mail::to($email)->send(new SubscriberMail($request->subject, $request->message, $lien));
                    $envoyes++;
                } catch (\Throwable $refus) {
                    $this->dernierEnvoiRefuses++;
                    $derniereErreur = $refus;
                    Log::warning('Offre par e-mail : destinataire refusé', ['subscriber' => $email, 'erreur' => $refus->getMessage()]);
                }
            }

            // Personne n'a reçu le message : c'est une panne d'envoi, pas une information — l'admin doit le savoir.
            if ($envoyes === 0 && $derniereErreur !== null) {
                throw $derniereErreur instanceof Exception ? $derniereErreur : new Exception($derniereErreur->getMessage());
            }

            return $envoyes;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
