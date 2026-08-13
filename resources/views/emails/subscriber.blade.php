@component('mail::message')
    # {{ $title }}

    Bonjour,

    {{ $message }}

    Merci,
    {{ config('app.name') }}
@endcomponent
