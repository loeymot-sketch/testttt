<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Carnet — Le Cayenne</title>
    <link rel="stylesheet" href="{{ asset(mix('css/daily-book.css')) }}">
</head>
<body>
    {{-- [GOAL RUPTURE-CARNET 2026-07-15 / W5] Carnet — app Vue légère mobile-first. --}}
    <div id="daily-book-app"></div>
    {{-- Extract global Mix : vendor partagé (cf. webpack.mix.js note D.6). --}}
    <script src="{{ asset(mix('js/manifest.js')) }}"></script>
    <script src="{{ asset(mix('js/vendor.js')) }}"></script>
    <script src="{{ asset(mix('js/daily-book.js')) }}"></script>
</body>
</html>
