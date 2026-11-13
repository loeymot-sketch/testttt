@component('mail::message')
{{-- [test-e2e stores S14-04 · 2026-11-13] Plus d'indentation : en Markdown, 4 espaces = bloc de CODE (l'e-mail s'affichait
     en police machine dans un cadre gris). --}}
# {{ $title }}

Bonjour,

{{ $message }}

Merci,<br>
{{ config('app.name') }}

@if (!empty($lienDesinscription))
<small>Vous recevez cet e-mail car vous avez accepté les offres du Cayenne. [Se désinscrire]({{ $lienDesinscription }})</small>
@endif
@endcomponent
