#!/usr/bin/env python3
"""[W-REM T-R2.1 2026-06-12] Miroir mécanique en→fr des clés label.* manquantes.

- Lit en.json / fr.json, calcule les clés label.* manquantes en fr.
- Traduction FR mécanique : clés gateway/SMS `<provider>_<field>` → "<Champ FR> <Provider>" ;
  clés génériques → dictionnaire manuel ; fallback = valeur en.json telle quelle.
- Insertion TEXTE additive en fin de section "label" de fr.json (diff minimal,
  pas de re-dump JSON global).
- Imprime la liste des clés fr orphelines (présentes fr / absentes en) — à
  documenter, PAS de suppression (arbitrage séparé).
"""
import json
import re
import sys

FR = 'resources/js/languages/fr.json'
EN = 'resources/js/languages/en.json'

PROVIDERS = {
    'paypal': 'PayPal', 'stripe': 'Stripe', 'twilio': 'Twilio',
    'flutterwave': 'Flutterwave', 'paystack': 'Paystack',
    'sslcommerz': 'SSLCommerz', 'mollie': 'Mollie', 'senangpay': 'SenangPay',
    'bkash': 'bKash', 'paytm': 'Paytm', 'razorpay': 'Razorpay',
    'mercadopago': 'MercadoPago', 'cashfree': 'Cashfree',
    'clickatell': 'Clickatell', 'nexmo': 'Nexmo', 'msg91': 'MSG91',
    'payfast': 'PayFast', 'skrill': 'Skrill', 'twofactor': '2Factor',
    'bulksms': 'BulkSMS', 'bulksmsbd': 'BulkSMSBD', 'telesign': 'Telesign',
    'phonepe': 'PhonePe', 'telr': 'Telr', 'iyzico': 'Iyzico',
    'pesapal': 'Pesapal', 'midtrans': 'Midtrans', 'twocheckout': '2Checkout',
    'myfatoorah': 'MyFatoorah', 'easypaisa': 'Easypaisa',
}

FIELDS = {
    'app_id': "ID d'application", 'app_key': "Clé d'application",
    'app_secret': "Secret d'application", 'client_id': 'ID client',
    'client_secret': 'Secret client', 'consumer_key': 'Clé consommateur',
    'consumer_secret': 'Secret consommateur', 'api_key': 'Clé API',
    'apikey': 'Clé API', 'api_password': 'Mot de passe API',
    'secret_key': 'Clé secrète', 'secret': 'Secret', 'key': 'Clé',
    'key_index': 'Index de clé', 'public_key': 'Clé publique',
    'server_key': 'Clé serveur', 'hash_key': 'Clé de hachage',
    'auth_key': "Clé d'authentification",
    'store_auth_key': "Clé d'authentification boutique",
    'merchant_id': 'ID marchand', 'merchant_key': 'Clé marchande',
    'merchant_user_id': 'ID utilisateur marchand',
    'merchant_website': 'Site web marchand', 'merchant_email': 'Email marchand',
    'merchant_api_password': 'Mot de passe API marchand',
    'store_id': 'ID boutique', 'store_name': 'Nom de la boutique',
    'store_password': 'Mot de passe boutique', 'seller_id': 'ID vendeur',
    'sender_id': 'ID expéditeur', 'template_id': 'ID de modèle',
    'template_variable': 'Variable de modèle', 'service_id': 'ID de service',
    'account_sid': 'SID de compte', 'auth_token': "Jeton d'authentification",
    'customer_id': 'ID client', 'ipn_id': 'ID IPN',
    'payment_url': 'URL de paiement',
    'buy_link_secret_word': "Mot secret du lien d'achat",
    'passphrase': 'Phrase secrète', 'from': 'Expéditeur', 'channel': 'Canal',
    'industry_type': "Type d'industrie", 'module': 'Module', 'mode': 'Mode',
    'status': 'Statut', 'username': "Nom d'utilisateur",
    'password': 'Mot de passe',
}

MANUAL = {
    'security': 'Sécurité', 'feature': 'Fonctionnalité',
    'auto_update': 'Mise à jour automatique', 'company': 'Entreprise',
    'offer': 'Offre', 'license_key': 'Clé de licence',
    'otp_type_checking': "Type d'OTP",
    'splash_screen_logo': "Logo de l'écran de démarrage",
    'deliveryBoy': 'Livreur',
    'notification_fcm_secret_key': 'Clé secrète Firebase',
    'menu_section_id': 'Section de menu', 'day': 'Jour',
    'customer_id': 'Client', 'single': 'Simple',
    'administrator': 'Administrateur', 'employee': 'Employé',
    'date_range': 'Plage de dates', 'search_branch': 'Rechercher une filiale',
    'open': 'Ouvert', 'resend_code': 'Renvoyer le code',
    'live': 'Live', 'sandbox': 'Sandbox',
    'sign': 'Signe', 'stripe': 'Stripe', 'map': 'Carte',
    'pos_payment_method': 'Moyen de paiement caisse',
    'enter_payment_note': 'Saisir une note de paiement',
    'enter_transaction_id': "Saisir l'ID de transaction",
    'select_address': 'Sélectionner une adresse',
    'search_address': 'Rechercher une adresse',
}


def translate(key, en_value):
    if key in MANUAL:
        return MANUAL[key]
    # Provider-prefixed config keys: <provider>_<field> → "<Champ FR> <Provider>"
    for prov, prov_fr in PROVIDERS.items():
        prefix = prov + '_'
        if key.startswith(prefix):
            field = key[len(prefix):]
            if field in FIELDS:
                return f'{FIELDS[field]} {prov_fr}'
            return f'{field.replace("_", " ").capitalize()} {prov_fr}'
    # Already-French or untranslatable: mirror the en value as-is.
    return en_value


def main():
    en = json.load(open(EN, encoding='utf-8'))['label']
    fr_full = json.load(open(FR, encoding='utf-8'))
    fr = fr_full['label']
    missing = [k for k in en if k not in fr]
    orphans = [k for k in fr if k not in en]
    print(f'missing in fr: {len(missing)} | fr orphans: {len(orphans)}')
    if not missing:
        print('nothing to do')
        return

    raw = open(FR, encoding='utf-8').read()
    # Locate the label section block.
    start = raw.index('"label": {')
    depth = 0
    end = None
    for i in range(start + len('"label": '), len(raw)):
        c = raw[i]
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                end = i
                break
    assert end is not None
    # Insert before the closing brace line, after the last entry.
    head, tail = raw[:end], raw[end:]
    # head currently ends with whitespace/newline before '}'. Trim trailing ws.
    m = re.search(r'\n([ \t]*)$', head)
    closing_indent = m.group(1) if m else '    '
    body = head.rstrip()
    if not body.endswith(','):
        body += ','
    lines = [body, '']
    for k in missing:
        lines.append(f'        {json.dumps(k, ensure_ascii=False)}: {json.dumps(translate(k, en[k]), ensure_ascii=False)},')
    # Remove the trailing comma on the last entry.
    lines[-1] = lines[-1].rstrip(',')
    new_raw = '\n'.join(lines) + '\n' + closing_indent + tail
    json.loads(new_raw)  # validate before writing
    open(FR, 'w', encoding='utf-8').write(new_raw)

    # Re-verify.
    fr2 = json.load(open(FR, encoding='utf-8'))['label']
    still = [k for k in en if k not in fr2]
    print(f'post-fill missing: {len(still)}')
    print('--- fr orphan keys (document, do NOT delete) ---')
    for k in orphans:
        print(k)
    sys.exit(0 if not still else 1)


if __name__ == '__main__':
    main()
