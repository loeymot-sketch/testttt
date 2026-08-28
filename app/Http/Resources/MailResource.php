<?php

namespace App\Http\Resources;


use Illuminate\Http\Resources\Json\JsonResource;

class MailResource extends JsonResource
{

    public $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    /**
     * [ONB-13 F-12 2026-08-27] Le masque renvoyé à la place du mot de passe SMTP.
     *
     * Cette constante est LUE PAR `MailService::update()` : c'est elle qui permet
     * de reconnaître « le formulaire me renvoie le masque, donc l'utilisateur n'a
     * pas touché au mot de passe ». Les deux côtés doivent donc partager la même
     * valeur — d'où la constante plutôt qu'une chaîne écrite deux fois.
     *
     * Ne jamais la changer sans changer le service : masquer d'un côté sans
     * reconnaître le masque de l'autre écrirait « ******** » dans le vrai mot de
     * passe à la première sauvegarde, et le restaurant cesserait d'envoyer ses
     * courriels sans que personne comprenne pourquoi.
     */
    public const MASQUE_MOT_DE_PASSE = '********';

    public function toArray($request) : array
    {
        // Le mot de passe SMTP ne sort plus du serveur. Il partait en clair vers le
        // navigateur : mémoire de l'onglet, onglet Réseau des outils de développement,
        // et tout journal de requêtes intermédiaire. La route est réservée aux comptes
        // ayant le droit `settings`, donc ce n'était pas une fuite publique — mais un
        // secret qui sortait sans nécessité. On renvoie un masque quand un mot de passe
        // existe, une chaîne vide sinon : l'écran doit pouvoir distinguer « configuré »
        // de « jamais renseigné ».
        $motDePasseExistant = (string) ($this->info['mail_password'] ?? '');

        return [
            "mail_host"       => $this->info['mail_host'],
            "mail_port"       => $this->info['mail_port'],
            "mail_username"   => $this->info['mail_username'],
            "mail_password"   => $motDePasseExistant === '' ? '' : self::MASQUE_MOT_DE_PASSE,
            "mail_encryption" => $this->info['mail_encryption'],
            "mail_from_name"  => $this->info['mail_from_name'],
            "mail_from_email" => $this->info['mail_from_email']
        ];
    }

}
