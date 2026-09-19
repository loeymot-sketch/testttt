<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class GatewayOptionsResource extends JsonResource
{

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    /**
     * [ONB-13 F-12 2026-08-27] Le masque renvoyé à la place d'un secret de passerelle.
     *
     * Lu par PaymentGatewayService::update() ET SmsGatewayService::update() : c'est lui
     * qui permet de reconnaître « le formulaire me renvoie le masque, donc l'utilisateur
     * n'a pas touché à ce champ ». Les trois fichiers partagent la constante — masquer
     * d'un côté sans reconnaître le masque de l'autre écrirait « ******** » dans la clé
     * secrète de Stripe, et les paiements tomberaient sans que l'écran change d'un pixel.
     */
    public const MASQUE = '********';

    /**
     * Ce nom d'option désigne-t-il un secret ?
     *
     * On décide par le NOM, pas par le `type` : `type=5` regroupe indifféremment
     * `stripe_secret` (une clé secrète) et `twilio_from` (un numéro de téléphone).
     * Masquer sur le type cacherait des champs anodins et laisserait passer des secrets.
     *
     * `stripe_key` est la clé PUBLIABLE et se retrouve masquée au passage : c'est
     * volontaire. Masquer une clé publique ne coûte rien ; laisser fuir une clé secrète
     * coûte le compte de paiement du commerçant.
     */
    public static function estSecret(?string $option): bool
    {
        $o = strtolower((string) $option);

        foreach (['secret', 'token', 'password', 'apikey', 'api_key', 'key'] as $motif) {
            if (str_contains($o, $motif)) {
                return true;
            }
        }

        return false;
    }

    public function toArray($request) : array
    {
        // Les secrets de passerelle ne sortent plus du serveur. Ils partaient en clair
        // vers le navigateur — clés Stripe et PayPal, jeton d'authentification Twilio —
        // dans la mémoire de l'onglet et dans l'onglet Réseau des outils de développement.
        // Une chaîne vide reste vide : l'écran doit distinguer « configuré » de
        // « jamais renseigné ».
        $valeur = (string) ($this->value ?? '');
        $masquee = self::estSecret($this->option) && $valeur !== ''
            ? self::MASQUE
            : $this->value;

        return [
            'id' => $this->id,
            'option' => $this->option,
            'value' => $masquee,
            'type' => $this->type,
            'activities' => $this->safeJsonDecode($this->activities)
        ];
    }

    /**
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(?string $json): mixed
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

}
