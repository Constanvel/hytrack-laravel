{{--
    First-load splash, after the Figma storyboard 43:2. Painted from this inline CSS before the
    app bundle arrives; resources/js/lib/splash.ts moves its loader on real milestones and plays
    the exit (the teal sweep that uncovers the first page) right after Inertia's first render.
    Inertia visits never reload the document, so page changes inside the app never show it. Where
    the visitor lands (login, patient home, companion view, staff or manager dashboard) is decided
    by the server.
    Included twice: ['part' => 'head'] in <head>, then ['part' => 'body'] first in <body>.
--}}
@if ($part === 'head')
<link rel="preload" as="image" href="/assets/splash-mark.svg">
<style>
    .splash {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        overflow: hidden;
        background: #f1f9f5;
        font-family: 'Inter', system-ui, sans-serif;
    }
    .splash :where(p) {
        margin: 0;
    }
    /* The storyboard's slanted band: light blue on the way in, teal on the way out. */
    .splash-sweep {
        position: absolute;
        top: 0;
        left: 0;
        width: 104.8vh;
        height: 100%;
        clip-path: polygon(0 0, 68.75% 0, 100% 100%, 31.25% 100%);
        transform: translateX(100vw);
        pointer-events: none;
    }
    .splash-sweep-in {
        background: #a8e5ed;
        animation: splash-sweep 0.62s cubic-bezier(0.45, 0, 0.25, 1) backwards;
    }
    .splash-sweep-out {
        background: linear-gradient(#086b62, #168875);
    }
    .splash-stage {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    /* Mark + wordmark. The wordmark box has a fixed width, so the lockup never shifts when Inter
       replaces the fallback font, and --shift (half of gap + wordmark) centres the mark alone. */
    .splash-lockup {
        --shift: 1.96em;
        display: flex;
        align-items: center;
        gap: 0.12em;
        font-size: min(45px, 10.9vw);
    }
    /* Above the wordmark, which comes out from behind it. */
    .splash-mark {
        position: relative;
        z-index: 1;
        flex: none;
        width: 2.152em;
        height: 1.568em;
        animation: splash-mark 0.76s 0.12s backwards;
    }
    .splash-mark img,
    .splash-hands {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
    }
    .splash-hands line {
        stroke: #124558;
        stroke-width: 2.289;
        stroke-linecap: round;
        transform-box: view-box;
        transform-origin: 83.9px 12.4px;
    }
    /* The clock keeps time while the app loads: the minute hand turns, the hour hand creeps. */
    .splash-minute {
        transform: rotate(70deg);
        animation: splash-minute 2.4s linear 0.3s infinite;
    }
    .splash-hour {
        transform: rotate(157deg);
        animation: splash-hour 1.6s cubic-bezier(0.2, 0.7, 0.2, 1) 0.3s backwards;
    }
    .splash-name {
        width: 3.8em;
        color: #086f66;
        font-size: 1em;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: -0.04em;
        white-space: nowrap;
        animation: splash-name 0.46s cubic-bezier(0.65, 0, 0.35, 1) 0.42s backwards;
    }
    .splash-tagline {
        margin-top: 34px;
        /* Letter spacing trails the last letter; the same on the left keeps it centred. */
        padding-left: 0.23em;
        color: #47736d;
        font-size: 10px;
        font-weight: 500;
        line-height: 18px;
        letter-spacing: 0.23em;
        animation: splash-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) 0.6s backwards;
    }
    .splash-load {
        position: absolute;
        right: 0;
        bottom: calc(env(safe-area-inset-bottom, 0px) + clamp(40px, 9.2vh, 96px));
        left: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 18px;
        animation: splash-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) 0.66s backwards;
    }
    .splash-bar {
        width: 66px;
        height: 3px;
        overflow: hidden;
        border-radius: 1.5px;
        background: #d2e7e1;
    }
    /* --p is the real progress, set by splash.ts: 0.1 with this HTML, 0.6 once the bundle
       runs, 1 when the first page has rendered. */
    .splash-bar i {
        display: block;
        height: 100%;
        background: #25a494;
        transform: scaleX(var(--p, 0.1));
        transform-origin: left;
        transition: transform 0.45s cubic-bezier(0.2, 0.7, 0.2, 1);
    }
    .splash-load p {
        color: #446f6d;
        font-size: 11px;
        line-height: 18px;
    }
    @keyframes splash-sweep {
        from {
            transform: translateX(-104.8vh);
        }
    }
    /* Appears centred and a little larger (Figma 02), then moves aside for the wordmark (03). */
    @keyframes splash-mark {
        0% {
            opacity: 0;
            transform: translateX(var(--shift)) scale(1.06);
            animation-timing-function: cubic-bezier(0.2, 0.7, 0.2, 1);
        }
        40% {
            opacity: 1;
            transform: translateX(var(--shift)) scale(1.21);
            animation-timing-function: cubic-bezier(0.65, 0, 0.35, 1);
        }
    }
    @keyframes splash-name {
        from {
            opacity: 0;
            clip-path: inset(0 100% 0 0);
            transform: translateX(-0.4em);
        }
    }
    @keyframes splash-rise {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
    }
    @keyframes splash-minute {
        to {
            transform: rotate(430deg);
        }
    }
    @keyframes splash-hour {
        from {
            transform: rotate(121deg);
        }
    }
    /* The finished frame, still; splash.ts removes it without the sweep. */
    @media (prefers-reduced-motion: reduce) {
        .splash *,
        .splash-hands line {
            animation: none !important;
            transition: none !important;
        }
    }
</style>
@else
<div id="splash" class="splash" role="status" aria-label="Memuat HyTrack">
    <i class="splash-sweep splash-sweep-in"></i>
    <div class="splash-stage" aria-hidden="true">
        <div class="splash-lockup">
            <div class="splash-mark">
                <img src="/assets/splash-mark.svg" alt="" width="97" height="71">
                <svg class="splash-hands" viewBox="0 0 96.8502 70.5552">
                    <line class="splash-hour" x1="83.9" y1="12.342" x2="83.9" y2="8.75" />
                    <line class="splash-minute" x1="83.9" y1="12.342" x2="83.9" y2="7.98" />
                </svg>
            </div>
            <p class="splash-name">HyTrack</p>
        </div>
        <p class="splash-tagline">TEMAN PERJALANAN BEROBAT</p>
    </div>
    <div class="splash-load" aria-hidden="true">
        <span class="splash-bar"><i></i></span>
        <p>Menyiapkan perjalanan Anda</p>
    </div>
    <i class="splash-sweep splash-sweep-out"></i>
</div>
@endif
