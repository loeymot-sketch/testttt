<?php

namespace App\Services;

use Exception;
use App\Http\Requests\MailRequest;
use App\Http\Resources\MailResource;
use Illuminate\Support\Facades\Log;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Support\Facades\Artisan;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class MailService
{
    public $envService;

    public function __construct(EnvEditor $envEditor)
    {
        $this->envService = $envEditor;
    }

    /**
     * @throws Exception
     */
    public function list()
    {
        try {
            return Settings::group('mail')->all();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(MailRequest $request)
    {
        try {
            // [ONB-13 F-12 2026-08-27] Le second geste du masquage — sans lui, le
            // premier casse tout.
            //
            // `MailResource` ne renvoie plus le mot de passe SMTP mais un masque. Le
            // formulaire renvoie donc ce masque quand l'utilisateur n'a pas touché au
            // champ. Sans ce traitement, on écrirait « ******** » dans le vrai mot de
            // passe à la première sauvegarde d'un autre réglage — l'expéditeur de
            // courriel du restaurant cesserait de fonctionner, et personne ne
            // comprendrait pourquoi puisque l'écran afficherait la même chose qu'avant.
            $valide = $request->validated();
            $inchange = ($valide['mail_password'] ?? null) === MailResource::MASQUE_MOT_DE_PASSE;

            if ($inchange) {
                // On reprend la valeur stockée : l'utilisateur a modifié autre chose.
                $valide['mail_password'] = (string) (Settings::group('mail')->get('mail_password') ?? '');
            }

            Settings::group('mail')->set($valide);
            $this->envService->addData([
                'MAIL_MAILER'       => 'smtp',
                'MAIL_HOST'         => $request->mail_host,
                'MAIL_PORT'         => $request->mail_port,
                'MAIL_USERNAME'     => $request->mail_username,
                'MAIL_PASSWORD'     => $valide['mail_password'],
                'MAIL_ENCRYPTION'   => $request->mail_encryption,
                'MAIL_FROM_ADDRESS' => $request->mail_from_email,
                'MAIL_FROM_NAME'    => $request->mail_from_name
            ]);
            Artisan::call('optimize:clear');
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
