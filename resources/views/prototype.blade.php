<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f8f7f1">
        <meta name="robots" content="noindex, nofollow">
        <title>HyTrack — Mode prototype</title>
        @include('partials.splash', ['part' => 'head'])
        @viteReactRefresh
        @vite('resources/js/prototype.tsx')
    </head>
    <body>
        @include('partials.splash', ['part' => 'body'])
        {{-- Served without the web middleware: no session, no database. --}}
        <div class="prototype-bar" role="note">
            <strong>Mode prototype</strong> · data contoh, tidak ada yang disimpan
            <a href="/prototipe/masuk?reset=1">Ulang dari awal</a>
            <a href="/masuk">Keluar dari prototype</a>
        </div>
        <div id="app"></div>
    </body>
</html>
