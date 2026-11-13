@component('mail::message')
    # {{ $title }}

    Bonjour,

    {{ $message }}

    Merci,
    {{ config('app.name') }}

@if (!empty($lienDesinscription))
<small>Vous recevez cet e-mail car vous avez accepté les offres du Cayenne. [Se désinscrire]({{ $lienDesinscription }})</small>
@endif
@endcomponent
