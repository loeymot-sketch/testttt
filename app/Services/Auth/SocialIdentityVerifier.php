<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * [APPS 2026-08-19] Vérification des jetons d'identité Apple et Google.
 *
 * CE QUE CETTE CLASSE PROTÈGE
 * ---------------------------
 * Le téléphone du client reçoit un jeton d'identité du fournisseur et nous l'envoie.
 * Ce jeton est la SEULE preuve que la personne est bien qui elle prétend être. Si on se
 * contentait de le décoder sans vérifier sa signature — ce qui « marche » parfaitement en
 * test, puisque le contenu est lisible en clair — n'importe qui pourrait fabriquer un
 * jeton portant le `sub` d'un autre client et prendre son compte, son historique et sa
 * fidélité, avec une simple requête HTTP. La vérification de signature n'est donc pas un
 * durcissement optionnel : c'est tout ce qui sépare cette connexion d'une porte ouverte.
 *
 * TROIS PIÈGES CLASSIQUES, TRAITÉS EXPLICITEMENT
 * ----------------------------------------------
 * 1. « Confusion d'algorithme » : un attaquant renvoie un jeton dont l'en-tête annonce
 *    `alg: none` (aucune signature) ou `alg: HS256` (signature symétrique, calculable
 *    avec une clé PUBLIQUE qu'il connaît). On impose RS256 et on refuse tout le reste
 *    AVANT de regarder la signature.
 * 2. Destinataire non vérifié : un jeton Google est parfaitement authentique… mais émis
 *    pour l'application de quelqu'un d'autre. Sans contrôle du `aud`, un site tiers peut
 *    rejouer chez nous le jeton de ses propres utilisateurs. On exige donc que `aud`
 *    figure dans NOS identifiants déclarés.
 * 3. Rotation des clés : Apple et Google renouvellent régulièrement leurs clés. Un cache
 *    figé ferait échouer TOUTES les connexions du jour au lendemain. Si le `kid` du jeton
 *    est inconnu, on rafraîchit le trousseau une fois avant de conclure à un échec.
 *
 * Aucune bibliothèque JWT n'est ajoutée : le projet n'en a pas, et l'extension `openssl`
 * de PHP suffit. Ajouter une dépendance à un système en production se justifie quand elle
 * évite du code délicat — ici la partie délicate est la validation des revendications, qui
 * resterait à notre charge de toute façon.
 */
class SocialIdentityVerifier
{
    /** Marge d'horloge tolérée entre le téléphone du client et notre serveur. */
    private const DERIVE_HORLOGE = 120;

    /** Durée de mise en cache du trousseau public d'un fournisseur. */
    private const CACHE_TROUSSEAU = 21600; // 6 h

    /** Intervalle minimal entre deux rafraîchissements forcés du trousseau (anti-abus). */
    private const FREIN_RAFRAICHISSEMENT = 60; // 1 min

    private const FOURNISSEURS = [
        'apple' => [
            'jwks' => 'https://appleid.apple.com/auth/keys',
            'iss'  => ['https://appleid.apple.com'],
        ],
        'google' => [
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            // Google émet historiquement les deux formes ; les deux sont légitimes.
            'iss'  => ['https://accounts.google.com', 'accounts.google.com'],
        ],
    ];

    /**
     * Vérifie un jeton d'identité et renvoie les revendications sûres.
     *
     * @return array{sub:string, email:?string, email_verified:bool, prenom:?string, nom:?string}
     *
     * @throws RuntimeException si le jeton n'est pas digne de confiance (message court,
     *         jamais destiné à être affiché tel quel au client — voir le contrôleur).
     */
    public function verifier(string $fournisseur, string $jeton): array
    {
        if (! isset(self::FOURNISSEURS[$fournisseur])) {
            throw new RuntimeException('fournisseur inconnu');
        }

        $morceaux = explode('.', $jeton);
        if (count($morceaux) !== 3) {
            throw new RuntimeException('jeton malformé');
        }

        [$b64Entete, $b64Charge, $b64Signature] = $morceaux;

        $entete = $this->jsonDepuisBase64Url($b64Entete, 'en-tête');
        $charge = $this->jsonDepuisBase64Url($b64Charge, 'charge utile');

        // (1) Algorithme imposé AVANT toute autre chose.
        if (($entete['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('algorithme de signature refusé');
        }
        $kid = $entete['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw new RuntimeException('identifiant de clé absent');
        }

        // (2) Signature.
        $signature = $this->base64UrlDecode($b64Signature);
        $signe = $b64Entete . '.' . $b64Charge;

        $pem = $this->clePublique($fournisseur, $kid, false);
        if ($pem === null) {
            // (3) Clé inconnue → le fournisseur a probablement tourné ses clés.
            $pem = $this->clePublique($fournisseur, $kid, true);
        }
        if ($pem === null) {
            throw new RuntimeException('clé de signature introuvable');
        }

        $ok = openssl_verify($signe, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('signature invalide');
        }

        // (4) Émetteur.
        $iss = (string) ($charge['iss'] ?? '');
        if (! in_array($iss, self::FOURNISSEURS[$fournisseur]['iss'], true)) {
            throw new RuntimeException('émetteur inattendu');
        }

        // (5) Destinataire : le jeton doit avoir été émis POUR NOUS.
        $auds = $charge['aud'] ?? null;
        $auds = is_array($auds) ? $auds : [$auds];
        $attendus = $this->audiencesAutorisees($fournisseur);
        if ($attendus === []) {
            throw new RuntimeException('aucune audience configurée');
        }
        if (count(array_intersect(array_map('strval', $auds), $attendus)) === 0) {
            throw new RuntimeException('destinataire inattendu');
        }

        // (6) Fenêtre temporelle.
        $maintenant = time();
        $exp = (int) ($charge['exp'] ?? 0);
        if ($exp <= 0 || $exp < $maintenant - self::DERIVE_HORLOGE) {
            throw new RuntimeException('jeton expiré');
        }
        $iat = (int) ($charge['iat'] ?? 0);
        if ($iat > 0 && $iat > $maintenant + self::DERIVE_HORLOGE) {
            throw new RuntimeException('jeton daté du futur');
        }

        $sub = (string) ($charge['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException('identifiant utilisateur absent');
        }

        // `email_verified` arrive tantôt en booléen, tantôt en chaîne "true" selon le
        // fournisseur et la plateforme. On normalise plutôt que de comparer à `true`,
        // sinon un e-mail réellement vérifié serait traité comme douteux.
        $emailVerifie = $charge['email_verified'] ?? false;
        $emailVerifie = ($emailVerifie === true || $emailVerifie === 'true' || $emailVerifie === 1 || $emailVerifie === '1');

        $email = $charge['email'] ?? null;
        $email = (is_string($email) && $email !== '') ? strtolower(trim($email)) : null;

        return [
            'sub'            => $sub,
            'email'          => $email,
            'email_verified' => $emailVerifie,
            'prenom'         => $this->texteOuNull($charge['given_name'] ?? null),
            'nom'            => $this->texteOuNull($charge['family_name'] ?? null),
        ];
    }

    /**
     * Identifiants d'application acceptés pour ce fournisseur.
     *
     * Il y en a PLUSIEURS par fournisseur, et c'est normal : le même compte Google émet
     * un `aud` différent selon que la connexion vient d'iOS, d'Android ou du web ; Apple
     * émet l'identifiant du paquet sur iOS et l'identifiant de service ailleurs. Une
     * liste unique aurait fait échouer une plateforme sur deux, avec un message
     * indistinguable d'une vraie tentative de fraude.
     */
    private function audiencesAutorisees(string $fournisseur): array
    {
        $brut = (array) config('services.' . $fournisseur . '.audiences', []);
        $liste = [];
        foreach ($brut as $v) {
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') {
                $liste[] = $v;
            }
        }

        return array_values(array_unique($liste));
    }

    /** Renvoie la clé publique PEM correspondant au `kid`, ou null si absente. */
    private function clePublique(string $fournisseur, string $kid, bool $forcerRafraichissement): ?string
    {
        $cle = 'social_jwks:' . $fournisseur;

        if ($forcerRafraichissement) {
            // Un `kid` inconnu est le signal d'une rotation de clés — mais c'est AUSSI ce
            // qu'obtient quiconque envoie un identifiant de clé au hasard. Sans frein, chaque
            // jeton bidon nous ferait rappeler le trousseau d'Apple ou de Google : au mieux
            // on se fait limiter par eux, au pire on leur sert de levier d'amplification.
            // Un rafraîchissement par minute suffit largement — une rotation de clés se
            // propage en heures, jamais en secondes.
            $verrou = 'social_jwks_refresh:' . $fournisseur;
            if (Cache::get($verrou)) {
                return null;
            }
            Cache::put($verrou, true, self::FREIN_RAFRAICHISSEMENT);
            Cache::forget($cle);
        }

        $trousseau = Cache::remember($cle, self::CACHE_TROUSSEAU, function () use ($fournisseur) {
            $url = self::FOURNISSEURS[$fournisseur]['jwks'];
            $reponse = Http::timeout(8)->retry(2, 200)->get($url);
            if (! $reponse->successful()) {
                throw new RuntimeException('trousseau indisponible');
            }
            $json = $reponse->json();

            return is_array($json['keys'] ?? null) ? $json['keys'] : [];
        });

        foreach ($trousseau as $jwk) {
            if (($jwk['kid'] ?? null) !== $kid) {
                continue;
            }
            if (($jwk['kty'] ?? null) !== 'RSA') {
                continue;
            }
            if (! isset($jwk['n'], $jwk['e'])) {
                continue;
            }

            return $this->pemDepuisJwkRsa((string) $jwk['n'], (string) $jwk['e']);
        }

        return null;
    }

    /**
     * Construit une clé publique PEM à partir du modulo et de l'exposant d'un JWK.
     *
     * PHP ne sait pas lire un JWK directement : il faut fabriquer la structure DER que
     * `openssl` attend (SubjectPublicKeyInfo), puis l'encoder en PEM.
     */
    private function pemDepuisJwkRsa(string $n, string $e): string
    {
        $modulo   = $this->derEntier($this->base64UrlDecode($n));
        $exposant = $this->derEntier($this->base64UrlDecode($e));

        $rsaPublicKey = $this->derSequence($modulo . $exposant);

        // AlgorithmIdentifier : OID rsaEncryption (1.2.840.113549.1.1.1) + paramètre NULL.
        $oidRsa = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $null   = "\x05\x00";
        $algo   = $this->derSequence($oidRsa . $null);

        // La clé RSA est portée par un BIT STRING, précédé de son nombre de bits inutilisés (0).
        $bitString = "\x03" . $this->derLongueur(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $spki = $this->derSequence($algo . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derEntier(string $octets): string
    {
        // Un INTEGER DER est signé : si le premier bit est à 1, la valeur serait lue comme
        // négative. On préfixe alors un octet nul — omission classique qui produit une clé
        // silencieusement fausse pour environ un modulo sur deux.
        if ($octets !== '' && (ord($octets[0]) & 0x80)) {
            $octets = "\x00" . $octets;
        }

        return "\x02" . $this->derLongueur(strlen($octets)) . $octets;
    }

    private function derSequence(string $contenu): string
    {
        return "\x30" . $this->derLongueur(strlen($contenu)) . $contenu;
    }

    private function derLongueur(int $longueur): string
    {
        if ($longueur < 0x80) {
            return chr($longueur);
        }
        $octets = '';
        while ($longueur > 0) {
            $octets = chr($longueur & 0xFF) . $octets;
            $longueur >>= 8;
        }

        return chr(0x80 | strlen($octets)) . $octets;
    }

    private function base64UrlDecode(string $valeur): string
    {
        $valeur = strtr($valeur, '-_', '+/');
        $reste = strlen($valeur) % 4;
        if ($reste !== 0) {
            $valeur .= str_repeat('=', 4 - $reste);
        }
        $decode = base64_decode($valeur, true);
        if ($decode === false) {
            throw new RuntimeException('encodage invalide');
        }

        return $decode;
    }

    private function jsonDepuisBase64Url(string $valeur, string $quoi): array
    {
        $json = json_decode($this->base64UrlDecode($valeur), true);
        if (! is_array($json)) {
            throw new RuntimeException($quoi . ' illisible');
        }

        return $json;
    }

    private function texteOuNull($valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : mb_substr($valeur, 0, 60);
    }
}
