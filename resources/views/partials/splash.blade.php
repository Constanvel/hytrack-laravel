{{--
    First-load splash: painted from this inline CSS before the app bundle arrives, then faded
    out by resources/js/lib/splash.ts right after Inertia's first render. Inertia visits never
    reload the document, so page changes inside the app never show it. Where the visitor lands
    (login, patient home, companion view, staff or manager dashboard) is decided by the server.
    Included twice: ['part' => 'head'] in <head>, then ['part' => 'body'] first in <body>.
--}}
@if ($part === 'head')
<link rel="preload" as="image" href="/assets/logo-splash.png">
<style>
    .splash {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        padding: 24px;
        background: radial-gradient(circle at 50% 44%, rgba(201, 227, 214, 0.55), rgba(201, 227, 214, 0) 58%), #f8f7f1;
        transition: opacity 0.24s ease-out, visibility 0s linear 0.24s;
    }
    .splash.is-done {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }
    .splash-mark {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        animation: splash-in 0.48s cubic-bezier(0.2, 0.7, 0.2, 1) both;
    }
    .splash-mark img {
        width: 132px;
        height: 97px;
    }
    .splash-name,
    .splash-tagline {
        margin: 0;
        font-family: 'Inter', system-ui, sans-serif;
    }
    .splash-name {
        margin-top: 16px;
        color: #086f65;
        font-size: 32px;
        font-weight: 700;
        line-height: 40px;
    }
    .splash-tagline {
        margin-top: 4px;
        color: #5c706c;
        font-size: 11px;
        font-weight: 600;
        line-height: 16px;
        letter-spacing: 0.08em;
        animation: splash-fade 0.4s ease-out 0.12s both;
    }
    @keyframes splash-in {
        from {
            opacity: 0;
            transform: scale(0.94);
        }
    }
    @keyframes splash-fade {
        from {
            opacity: 0;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .splash,
        .splash-mark,
        .splash-tagline {
            animation: none;
            transition: none;
        }
    }
</style>
@else
<div id="splash" class="splash" role="status" aria-label="Memuat HyTrack">
    <div class="splash-mark" aria-hidden="true">
        <img src="/assets/logo-splash.png" alt="" width="132" height="97">
        <p class="splash-name">HyTrack</p>
        <p class="splash-tagline">TEMAN PERJALANAN BEROBAT</p>
    </div>
</div>
@endif
