<x-filament-panels::page>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Modpack Manager — Server Panel Page  ·  "Command Deck" experience
     ────────────────────────────────────────────────────────────────────────
     A search-first, command-palette layout: one hero search, inline source
     filter pills, a dense results FEED (rows, not a card grid), and a
     right-hand slide-in DRAWER for configuring + launching an install. Install
     progress renders as a vertical timeline console. Bold "game launcher"
     visual language, fully self-contained under `.mpm` so it renders correctly
     regardless of the host panel's compiled Tailwind. Adapts to light/dark via
     the `.dark` class on <html>. Alpine drives interactivity; Livewire the data.

     Overlays (scrim + drawer + log modal) are teleported to <body> so they escape
     the host panel's transformed/clipped content wrapper and can dim the whole
     viewport (incl. the panel header/footer). They keep this component's Alpine +
     Livewire scope via <template x-teleport>; loading states use Alpine flags since
     wire:loading is re-queried against the component subtree and won't cross the
     teleport. The drawer slide is a pure-CSS class toggle so it never depends on
     the host build shipping any particular utility class.
     ═══════════════════════════════════════════════════════════════════════════ --}}

<style>
    .mpm {
        --mpm-accent:    #7c5cff;
        --mpm-accent-2:  #22d3ee;
        --mpm-accent-lo: color-mix(in srgb, var(--mpm-accent) 14%, transparent);
        --mpm-glow:      color-mix(in srgb, var(--mpm-accent) 55%, transparent);

        --mpm-surface:   #ffffff;
        --mpm-surface-2: #f5f6fc;
        --mpm-surface-3: #ecedf6;
        --mpm-border:    #e6e7f2;
        --mpm-border-2:  #d9dbec;
        --mpm-text:      #16151f;
        --mpm-text-2:    #55536b;
        --mpm-muted:     #8b8aa3;

        --mpm-shadow:    0 1px 2px rgba(24,22,45,.06), 0 4px 14px rgba(24,22,45,.05);
        --mpm-shadow-lg: 0 24px 60px rgba(24,22,45,.22);

        --mpm-cf:  #f16436;
        --mpm-mr:  #18bf5d;
        --mpm-ftb: #e0403a;
        --mpm-atl: #1d9bf0;
        --mpm-success: #22c55e;
        --mpm-warn:    #f59e0b;
        --mpm-danger:  #ef4444;
        --mpm-blue:    #3b82f6;
    }
    .dark .mpm {
        /* Card fill matches the Modpacks page body background so there is no
           lightness step between a card and the surrounding gutters/sidebar — the whole UI
           reads as one tone. Cards are defined by borders; dark shadows are avoided because
           they spill into the empty page background and create horizontal tone bands. */
        --mpm-page-bg:   #050506;
        --mpm-surface:   var(--mpm-page-bg);
        --mpm-surface-2: #18181b;
        --mpm-surface-3: #23232a;
        --mpm-border:    rgba(255,255,255,.10);
        --mpm-border-2:  rgba(255,255,255,.16);
        --mpm-text:      #fafafa;
        --mpm-text-2:    #a1a1aa;
        --mpm-muted:     #71717a;

        --mpm-shadow:    none;
        --mpm-shadow-lg: 0 30px 70px rgba(0,0,0,.7);
    }

    .mpm { color: var(--mpm-text); }
    .mpm *, .mpm *::before, .mpm *::after { box-sizing: border-box; }
    .mpm [x-cloak] { display: none !important; }
    html.fi.dark,
    .dark body.fi-panel-server.fi-body,
    .dark .fi-layout,
    .dark .fi-main-ctn,
    .dark .fi-main,
    .dark .fi-page,
    .dark .fi-sidebar,
    .dark .fi-sidebar-nav {
        background: #050506 !important;
        background-color: #050506 !important;
    }
    .dark .mpm,
    .dark .mpm-shell {
        background:#050506;
    }
    .dark .mpm .mpm-hero__grid { display:none; }
    .dark .mpm .mpm-loadout__art,
    .dark .mpm .mpm-row__art,
    .dark .mpm .mpm-pill,
    .dark .mpm .mpm-pill .dot,
    .dark .mpm .mpm-pill.is-active,
    .dark .mpm .mpm-chip--src,
    .dark .mpm .mpm-cta--install,
    .dark .mpm .mpm-cta--update,
    .dark .mpm .mpm-omni__go,
    .dark .mpm .mpm-btn--primary,
    .dark .mpm .mpm-btn--update,
    .dark .mpm .mpm-btn--success {
        box-shadow:none !important;
    }
    .dark .mpm .mpm-loadout__label .live {
        animation:none;
        box-shadow:none;
    }
    .dark .mpm .mpm-row:hover,
    .dark .mpm .mpm-omni__field:focus-within {
        box-shadow:none;
    }
    .dark .mpm .mpm-omni__go:hover,
    .dark .mpm .mpm-row:hover .mpm-cta--install,
    .dark .mpm .mpm-btn--primary:hover:not(:disabled),
    .dark .mpm .mpm-btn--update:hover:not(:disabled) {
        filter:none;
    }

    /* ── Animations ── */
    @keyframes mpm-rise   { from { opacity:0; transform:translateY(14px);} to { opacity:1; transform:none; } }
    @keyframes mpm-spin   { to { transform: rotate(360deg); } }
    @keyframes mpm-shimmer{ 0%{background-position:-500px 0;} 100%{background-position:500px 0;} }
    @keyframes mpm-livepulse { 0%,100%{ filter:brightness(1); } 50%{ filter:brightness(1.22); } }
    @keyframes mpm-glowpulse { 0%,100%{ box-shadow:0 0 0 0 var(--mpm-glow);} 50%{ box-shadow:0 0 0 6px transparent;} }
    .mpm-rise { animation: mpm-rise .45s cubic-bezier(.16,1,.3,1) both; }
    .mpm-spin { animation: mpm-spin 1s linear infinite; }

    /* ── Shell ── */
    .mpm-shell { display:flex; flex-direction:column; gap:20px; }

    /* ══════════ LOADOUT (installed pack) hero strip ══════════ */
    .mpm-loadout {
        position:relative; overflow:hidden;
        display:flex; align-items:center; gap:18px;
        padding:18px 20px; border-radius:20px;
        background: var(--mpm-surface);
        border:1px solid color-mix(in srgb, var(--mpm-success) 34%, var(--mpm-border));
        box-shadow: var(--mpm-shadow);
    }
    .mpm-loadout__art { width:60px; height:60px; border-radius:15px; object-fit:cover; flex-shrink:0;
        border:1px solid var(--mpm-border-2); box-shadow:0 6px 18px rgba(0,0,0,.25); }
    .mpm-loadout__label { display:inline-flex; align-items:center; gap:7px; font-size:.64rem; font-weight:800;
        letter-spacing:.14em; text-transform:uppercase; color:var(--mpm-success); }
    .mpm-loadout__label .live { width:7px; height:7px; border-radius:50%; background:var(--mpm-success);
        box-shadow:0 0 0 0 var(--mpm-success); animation:mpm-glowpulse 2s infinite; }
    .mpm-loadout__name { font-size:1.05rem; font-weight:800; margin:4px 0 1px; letter-spacing:-.01em; }
    .mpm-loadout__ver  { font-size:.8rem; color:var(--mpm-text-2); }

    /* ══════════ Hero search (command palette) ══════════ */
    .mpm-hero {
        position:relative; overflow:hidden; border-radius:22px; padding:26px 26px 22px;
        background: var(--mpm-surface);
        border:1px solid var(--mpm-border);
        box-shadow:var(--mpm-shadow);
    }
    .mpm-hero__grid { position:absolute; inset:0; opacity:.5; pointer-events:none;
        background-image:linear-gradient(color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px),
                         linear-gradient(90deg, color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px);
        background-size:34px 34px; -webkit-mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%);
        mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%); }
    .mpm-hero__eyebrow { position:relative; display:inline-flex; align-items:center; gap:8px;
        font-size:.66rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase; color:var(--mpm-accent); margin:0 0 6px; }
    .mpm-hero__eyebrow svg { width:15px; height:15px; }
    .mpm-hero__title { position:relative; font-size:1.5rem; font-weight:800; letter-spacing:-.02em; margin:0 0 2px; }
    .mpm-hero__sub { position:relative; font-size:.84rem; color:var(--mpm-text-2); margin:0 0 18px; }

    .mpm-omni { position:relative; }
    .mpm-omni__field { position:relative; display:flex; align-items:center;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); border-radius:15px;
        transition:border-color .18s, box-shadow .18s, background .18s; }
    .mpm-omni__field:focus-within { border-color:var(--mpm-accent); background:var(--mpm-surface);
        box-shadow:0 0 0 4px var(--mpm-accent-lo), 0 8px 26px color-mix(in srgb, var(--mpm-accent) 16%, transparent); }
    .mpm-omni__ico { display:flex; padding-left:18px; color:var(--mpm-accent); }
    .mpm-omni__ico svg { width:20px; height:20px; }
    .mpm-omni input { flex:1; width:100%; height:56px; padding:0 16px; border:0; outline:0; background:transparent;
        color:var(--mpm-text); font-size:1.02rem; font-weight:500; }
    .mpm-omni input::placeholder { color:var(--mpm-muted); font-weight:400; }
    .mpm-omni__kbd { display:flex; align-items:center; gap:5px; padding-right:14px; }
    .mpm-omni__kbd kbd { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.66rem; font-weight:700;
        color:var(--mpm-text-2); background:var(--mpm-surface-3); border:1px solid var(--mpm-border-2);
        border-bottom-width:2px; border-radius:6px; padding:3px 7px; }
    .mpm-omni__go { flex-shrink:0; margin:6px; height:44px; padding:0 20px; border:0; cursor:pointer;
        display:inline-flex; align-items:center; gap:7px; border-radius:11px; font-weight:700; font-size:.88rem;
        color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 60%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 40%, transparent); transition:transform .12s, filter .15s; }
    .mpm-omni__go:hover { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-omni__go svg { width:16px; height:16px; }

    /* ── Source pills ── */
    .mpm-pills { position:relative; display:flex; flex-wrap:wrap; gap:9px; margin-top:16px; }
    .mpm-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 15px; border-radius:12px; cursor:pointer;
        font-size:.82rem; font-weight:700; letter-spacing:.01em; color:var(--mpm-text-2);
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .16s; }
    .mpm-pill:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); transform:translateY(-1px); }
    .mpm-pill .dot { width:9px; height:9px; border-radius:50%; box-shadow:0 0 6px 0 currentColor; }
    .mpm-pill--all .dot { background:conic-gradient(from 210deg, var(--mpm-cf), var(--mpm-mr), var(--mpm-atl), var(--mpm-ftb), var(--mpm-cf)); box-shadow:none; }
    .mpm-pill--cf  .dot { background:var(--mpm-cf);  color:var(--mpm-cf); }
    .mpm-pill--mr  .dot { background:var(--mpm-mr);  color:var(--mpm-mr); }
    .mpm-pill--ftb .dot { background:var(--mpm-ftb); color:var(--mpm-ftb); }
    .mpm-pill--atl .dot { background:var(--mpm-atl); color:var(--mpm-atl); }
    .mpm-pill.is-active { color:#fff; border-color:transparent;
        background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-pill--cf.is-active  { background:linear-gradient(135deg, var(--mpm-cf),  #c8471f); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-cf) 40%, transparent); }
    .mpm-pill--mr.is-active  { background:linear-gradient(135deg, var(--mpm-mr),  #0f9c48); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-mr) 40%, transparent); }
    .mpm-pill--ftb.is-active { background:linear-gradient(135deg, var(--mpm-ftb), #b52a25); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-ftb) 40%, transparent); }
    .mpm-pill--atl.is-active { background:linear-gradient(135deg, var(--mpm-atl), #1273c4); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-atl) 40%, transparent); }
    .mpm-pill.is-active .dot { background:rgba(255,255,255,.9); box-shadow:none; }

    /* ══════════ Filter button + panel ══════════ */
    .mpm-filterbtn { position:relative; display:inline-flex; align-items:center; gap:8px; margin-left:auto; padding:8px 15px; border-radius:12px; cursor:pointer;
        font-size:.85rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .15s; }
    .mpm-filterbtn:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); transform:translateY(-1px); }
    .mpm-filterbtn svg { width:16px; height:16px; }
    .mpm-filterbtn.is-open, .mpm-filterbtn.is-on { color:var(--mpm-accent); border-color:color-mix(in srgb, var(--mpm-accent) 45%, var(--mpm-border)); background:var(--mpm-accent-lo); }
    .mpm-filterbtn__badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; border-radius:999px;
        font-size:.66rem; font-weight:800; color:#fff; background:var(--mpm-accent); }

    .mpm-filterpanel { margin-top:14px; padding:18px; border-radius:16px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-filtergrid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; }
    .mpm-loader-picks { display:flex; flex-wrap:wrap; gap:7px; margin-top:2px; }
    .mpm-filterchip { display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:9px; cursor:pointer;
        font-size:.75rem; font-weight:750; color:var(--mpm-text-2); background:var(--mpm-surface); border:1px solid var(--mpm-border); transition:all .14s; }
    .mpm-filterchip:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-filterchip.is-active { color:#fff; border-color:var(--mpm-accent); background:var(--mpm-accent); box-shadow:0 4px 12px var(--mpm-accent-lo); }
    .mpm-filterchip .dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
    .mpm-filter-note { margin:7px 0 0; font-size:.7rem; color:var(--mpm-muted); line-height:1.4; }
    .mpm-filteractions { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:16px; }
    .mpm-active-filters { display:flex; flex-wrap:wrap; align-items:center; gap:7px; margin-top:12px; }
    .mpm-active-filter { display:inline-flex; align-items:center; gap:7px; padding:6px 9px; border-radius:999px; cursor:pointer;
        font-size:.72rem; font-weight:750; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-active-filter:hover { color:var(--mpm-text); border-color:var(--mpm-accent); }
    .mpm-active-filter__x { font-size:.9rem; line-height:1; color:var(--mpm-muted); }
    .mpm-provider-warning { display:flex; align-items:flex-start; gap:10px; padding:11px 13px; border-radius:12px;
        color:#fcd34d; background:color-mix(in srgb,var(--mpm-warn) 10%,var(--mpm-surface)); border:1px solid color-mix(in srgb,var(--mpm-warn) 35%,var(--mpm-border)); font-size:.76rem; }
    .mpm-provider-warning svg { width:16px; height:16px; flex-shrink:0; margin-top:1px; }
    .mpm-provider-warning button { margin-left:auto; flex-shrink:0; border:0; background:none; color:inherit; cursor:pointer; font-weight:800; text-decoration:underline; text-underline-offset:3px; }

    /* subtle secondary action (last log) */
    .mpm-loglink { display:inline-flex; align-items:center; gap:8px; margin-top:16px; padding:8px 14px; border-radius:11px; cursor:pointer;
        font-size:.8rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .15s; }
    .mpm-loglink:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-loglink svg { width:15px; height:15px; }

    /* ── Results header ── */
    .mpm-feed-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:0 4px; }
    .mpm-feed-head h3 { font-size:.95rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-feed-head .count { font-size:.75rem; color:var(--mpm-muted); font-weight:600; }

    /* ══════════ Results feed (card grid) ══════════ */
    .mpm-feed { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; align-items:stretch; }
    .mpm-row {
        --row-c: var(--mpm-accent);
        position:relative; display:flex; flex-wrap:wrap; align-items:flex-start; align-content:flex-start; gap:14px 16px; width:100%; text-align:left;
        padding:16px; border-radius:16px; cursor:pointer;
        background:var(--mpm-surface); border:1px solid var(--mpm-border); box-shadow:var(--mpm-shadow);
        transition:transform .16s cubic-bezier(.16,1,.3,1), box-shadow .16s, border-color .16s, background .16s;
    }
    .mpm-row::before { content:""; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:3px;
        background:var(--row-c); opacity:0; transition:opacity .16s; }
    .mpm-row:hover { transform:translateY(-3px); border-color:color-mix(in srgb, var(--row-c) 45%, var(--mpm-border));
        box-shadow:0 10px 28px color-mix(in srgb, var(--row-c) 16%, transparent); }
    .mpm-row:hover::before { opacity:1; }
    .mpm-row:focus-visible { outline:2px solid var(--mpm-accent); outline-offset:2px; }
    .mpm-row.is-installed { border-color:color-mix(in srgb, var(--mpm-success) 42%, var(--mpm-border)); }

    .mpm-row__art { position:relative; width:56px; height:56px; border-radius:13px; object-fit:cover; flex-shrink:0;
        border:1px solid var(--mpm-border-2); background:var(--mpm-surface-3); box-shadow:0 4px 12px rgba(0,0,0,.18); }
    .mpm-row__art--ph { display:flex; align-items:center; justify-content:center; color:var(--mpm-muted); }
    .mpm-row__body { flex:1 1 200px; min-width:0; }
    .mpm-row__title { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .mpm-row__name { font-size:.98rem; font-weight:800; margin:0; letter-spacing:-.01em; line-height:1.2;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
    .mpm-row__desc { font-size:.79rem; color:var(--mpm-text-2); line-height:1.45; margin:6px 0 0;
        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .mpm-row__meta { display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-top:8px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-row__meta span { display:inline-flex; align-items:center; gap:5px; }
    .mpm-row__meta svg { width:13px; height:13px; }
    .mpm-row__meta .author { color:var(--mpm-text-2); font-weight:600; }

    /* provider + loader chips */
    .mpm-chip { display:inline-flex; align-items:center; gap:5px; font-size:.63rem; font-weight:800; letter-spacing:.03em;
        text-transform:uppercase; padding:3px 9px; border-radius:7px; --c:#8b8aa3;
        color:var(--c); background:color-mix(in srgb, var(--c) 14%, transparent);
        border:1px solid color-mix(in srgb, var(--c) 38%, transparent); }
    .mpm-chip::before { content:""; width:6px; height:6px; border-radius:50%; background:var(--c); }
    .mpm-chip--src { color:#fff; background:var(--c); border-color:var(--c); box-shadow:0 2px 8px color-mix(in srgb, var(--c) 45%, transparent); }
    .mpm-chip--src::before { background:rgba(255,255,255,.9); }

    .mpm-row__quick { flex:1 1 100%; display:flex; gap:7px; margin-top:4px; }
    .mpm-quickbtn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:31px; padding:6px 10px; border-radius:9px;
        font-size:.7rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); cursor:pointer; transition:all .14s; text-decoration:none; }
    .mpm-quickbtn:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); background:var(--mpm-surface-3); }
    .mpm-quickbtn svg { width:13px; height:13px; }
    .mpm-quickbtn--grow { flex:1; }

    /* row CTA — full-width bar pinned to the bottom of the card */
    .mpm-row__cta { flex:1 1 100%; display:flex; align-items:center; gap:12px; margin-top:2px; }
    .mpm-cta-state { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:10px 16px; border-radius:11px;
        font-size:.82rem; font-weight:700; white-space:nowrap; transition:all .15s; }
    .mpm-cta-state svg { width:15px; height:15px; }
    .mpm-cta--install { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 6px 16px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-row:hover .mpm-cta--install { filter:brightness(1.08); }
    .mpm-cta--update { color:#3a2400; background:linear-gradient(135deg, var(--mpm-warn), #f7b64c); box-shadow:0 6px 16px color-mix(in srgb, var(--mpm-warn) 34%, transparent); }
    .mpm-cta--installed { color:var(--mpm-success); background:color-mix(in srgb, var(--mpm-success) 14%, transparent);
        border:1px solid color-mix(in srgb, var(--mpm-success) 36%, transparent); }
    .mpm-row__chev { color:var(--mpm-muted); transition:transform .16s, color .16s; display:flex; }
    .mpm-row__chev svg { width:18px; height:18px; }
    .mpm-row:hover .mpm-row__chev { transform:translateX(3px); color:var(--mpm-accent); }

    /* ── Skeleton rows ── */
    .mpm-skel-row { display:flex; align-items:center; gap:16px; padding:14px 16px; border-radius:16px;
        background:var(--mpm-surface); border:1px solid var(--mpm-border); }
    .mpm-shimmer { background:linear-gradient(90deg, var(--mpm-surface-2) 25%, var(--mpm-surface-3) 50%, var(--mpm-surface-2) 75%);
        background-size:900px 100%; animation:mpm-shimmer 1.5s infinite linear; border-radius:7px; }

    /* ── Empty / error ── */
    .mpm-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:12px; padding:64px 20px;
        border-radius:20px; background:var(--mpm-surface); border:1px dashed var(--mpm-border-2); color:var(--mpm-muted); }
    .mpm-empty__orb { width:72px; height:72px; border-radius:20px; display:flex; align-items:center; justify-content:center;
        color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 30%, transparent); }
    .mpm-alert { display:flex; gap:13px; padding:16px 18px; border-radius:16px; align-items:flex-start;
        background:color-mix(in srgb, var(--mpm-danger) 10%, var(--mpm-surface));
        border:1px solid color-mix(in srgb, var(--mpm-danger) 34%, transparent); color:var(--mpm-danger); font-size:.85rem; }
    .mpm-alert svg { width:20px; height:20px; flex-shrink:0; margin-top:1px; }

    /* ══════════ INSTALL CONSOLE (vertical timeline) ══════════ */
    .mpm-console { position:relative; overflow:hidden; border-radius:22px; border:1px solid var(--mpm-border);
        background:var(--mpm-surface);
        box-shadow:var(--mpm-shadow); }
    .mpm-console__head { display:flex; align-items:center; justify-content:space-between; gap:18px; flex-wrap:wrap;
        padding:22px 24px; border-bottom:1px solid var(--mpm-border); }
    .mpm-console__eyebrow { font-size:.64rem; text-transform:uppercase; letter-spacing:.16em; font-weight:800; color:var(--mpm-accent); margin:0 0 5px; }
    .mpm-console__title { font-size:1.25rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-console__title.ok  { color:var(--mpm-success); }
    .mpm-console__title.err { color:var(--mpm-danger); }
    .mpm-clock { text-align:right; }
    .mpm-clock__k { font-size:.62rem; text-transform:uppercase; letter-spacing:.14em; color:var(--mpm-muted); font-weight:700; margin:0 0 2px; }
    .mpm-clock__n { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.6rem; font-weight:800; line-height:1;
        background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }

    .mpm-console__body { padding:22px 24px; display:flex; flex-direction:column; gap:22px; }

    /* progress bar */
    .mpm-bar__top { display:flex; justify-content:space-between; align-items:center; font-size:.74rem; color:var(--mpm-text-2); margin-bottom:8px; }
    .mpm-bar__top b { font-family:ui-monospace,monospace; font-size:1rem; color:var(--mpm-text); }
    .mpm-bar__track { height:14px; border-radius:999px; background:var(--mpm-surface-3); overflow:hidden; border:1px solid var(--mpm-border); }
    .mpm-bar__fill { height:100%; border-radius:999px; transition:width .7s cubic-bezier(.16,1,.3,1);
        background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); }
    .mpm-bar__fill.is-running { animation:mpm-livepulse 1.8s ease-in-out infinite; }
    .mpm-bar__fill.is-done   { background:linear-gradient(135deg, var(--mpm-success), #4ade80); }
    .mpm-bar__fill.is-failed { background:linear-gradient(135deg, var(--mpm-danger), #f87171); }

    /* horizontal timeline (stepper) — steps flow left→right so the console stays compact */
    .mpm-timeline { display:flex; flex-direction:row; align-items:flex-start; gap:0; }
    .mpm-tl { position:relative; flex:1; min-width:0; display:flex; flex-direction:column; align-items:center; gap:9px; padding:6px 4px 0; text-align:center; }
    .mpm-tl__rail { position:relative; width:100%; display:flex; justify-content:center; }
    .mpm-tl__node { position:relative; z-index:1; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
        background:var(--mpm-surface-3); color:var(--mpm-muted); border:2px solid var(--mpm-border-2); transition:all .3s; }
    .mpm-tl__node svg { width:15px; height:15px; }
    /* connector runs from this node's centre to the next node's centre */
    .mpm-tl:not(:last-child) .mpm-tl__rail::after { content:""; position:absolute; top:14px; left:50%; right:-50%;
        height:2px; background:var(--mpm-border-2); transition:background .3s; }
    .mpm-tl__label { font-size:.72rem; line-height:1.25; font-weight:600; color:var(--mpm-text-2); transition:color .3s; }
    .mpm-tl.is-done  .mpm-tl__node { background:var(--mpm-success); border-color:var(--mpm-success); color:#fff; }
    .mpm-tl.is-done  .mpm-tl__rail::after { background:var(--mpm-success); }
    .mpm-tl.is-done  .mpm-tl__label { color:var(--mpm-text); }
    .mpm-tl.is-running .mpm-tl__node { background:var(--mpm-accent); border-color:var(--mpm-accent); color:#fff;
        box-shadow:0 0 0 5px var(--mpm-accent-lo); }
    .mpm-tl.is-running .mpm-tl__label { color:var(--mpm-text); font-weight:800; }
    .mpm-tl.is-failed .mpm-tl__node { background:var(--mpm-danger); border-color:var(--mpm-danger); color:#fff; }
    .mpm-tl.is-failed .mpm-tl__label { color:var(--mpm-danger); }

    /* terminal / debug log */
    .mpm-term { border-radius:14px; overflow:hidden; border:1px solid var(--mpm-border-2); background:#0a0b10; }
    .mpm-term__bar { display:flex; align-items:center; gap:9px; padding:9px 14px; background:#11131b; border-bottom:1px solid rgba(255,255,255,.06); }
    .mpm-term__dots { display:flex; gap:6px; }
    .mpm-term__dots i { width:11px; height:11px; border-radius:50%; display:block; }
    .mpm-term__dots i:nth-child(1){ background:#ff5f57; } .mpm-term__dots i:nth-child(2){ background:#febc2e; } .mpm-term__dots i:nth-child(3){ background:#28c840; }
    .mpm-term__title { font-family:ui-monospace,monospace; font-size:.72rem; color:#8b95a5; }
    .mpm-term__count { margin-left:auto; font-family:ui-monospace,monospace; font-size:.68rem; color:#5b6472;
        background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); border-radius:6px; padding:2px 8px; }
    .mpm-term__body { height:210px; overflow-y:auto; padding:14px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:.73rem; line-height:1.65; color:#4ade80; }
    .mpm-term__body::-webkit-scrollbar { width:8px; } .mpm-term__body::-webkit-scrollbar-thumb { background:rgba(255,255,255,.14); border-radius:4px; }
    .mpm-term__body .muted { color:#475569; }
    .mpm-term__foot { display:flex; align-items:center; gap:8px; padding:9px 14px; border-top:1px solid rgba(255,255,255,.06);
        font-size:.72rem; color:#8b95a5; }
    .mpm-term-toggle { display:inline-flex; align-items:center; gap:8px; background:none; border:0; cursor:pointer; font-size:.82rem; font-weight:600; color:var(--mpm-text-2); padding:0; }
    .mpm-term-toggle:hover { color:var(--mpm-text); }
    .mpm-term-toggle .chev { width:15px; height:15px; transition:transform .2s; } .mpm-term-toggle .chev.open { transform:rotate(90deg); }

    .mpm-now { display:inline-flex; align-items:center; gap:9px; font-size:.88rem; font-weight:700; color:var(--mpm-accent); }
    .mpm-now svg { width:17px; height:17px; }
    .mpm-console__foot { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:18px 24px; border-top:1px solid var(--mpm-border); }

    /* ── Buttons ── */
    .mpm-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 20px; border-radius:12px;
        border:1px solid transparent; font-size:.86rem; font-weight:700; cursor:pointer; transition:all .15s; text-decoration:none; }
    .mpm-btn svg { width:16px; height:16px; }
    .mpm-btn:disabled { opacity:.5; cursor:not-allowed; }
    .mpm-btn--primary { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-btn--primary:hover:not(:disabled) { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-btn--update  { color:#3a2400; background:linear-gradient(135deg, var(--mpm-warn), #f7b64c); box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-warn) 32%, transparent); }
    .mpm-btn--update:hover:not(:disabled) { filter:brightness(1.05); transform:translateY(-1px); }
    .mpm-btn--success { color:#fff; background:linear-gradient(135deg, var(--mpm-success), #4ade80); box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-success) 30%, transparent); }
    .mpm-btn--ghost { background:var(--mpm-surface-2); border-color:var(--mpm-border-2); color:var(--mpm-text); }
    .mpm-btn--ghost:hover { background:var(--mpm-surface-3); }
    .mpm-btn--block { width:100%; }

    /* ══════════ Install DRAWER (slide-in from right) ══════════ */
    /* These overlays are teleported to <body> (see markup), escaping Filament's transformed/
       clipped content wrapper, so inset:0 is truly viewport-relative here. High z-index keeps
       them above the panel's own header/footer chrome. */
    .mpm-scrim { position:fixed; inset:0; z-index:9990; background:rgba(8,6,22,.92); backdrop-filter:blur(8px); }
    .mpm-ilbl { display:inline-flex; align-items:center; gap:8px; }
    .mpm-drawer { position:fixed; top:0; right:0; bottom:0; z-index:9991; width:100%; max-width:480px; display:flex; flex-direction:column;
        background:var(--mpm-surface); border-left:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg);
        transform:translateX(100%); visibility:hidden;
        transition:transform .32s cubic-bezier(.16,1,.3,1), visibility 0s linear .32s; }
    .mpm-drawer.is-open { transform:translateX(0); visibility:visible; transition:transform .32s cubic-bezier(.16,1,.3,1); }
    .mpm-drawer__starting { position:absolute; inset:0; z-index:5; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; text-align:center;
        background:color-mix(in srgb, var(--mpm-surface) 88%, transparent); backdrop-filter:blur(4px); animation:mpm-rise .22s ease both; }
    .mpm-drawer__starting .mpm-spin { width:40px; height:40px; color:var(--mpm-accent); }
    .mpm-drawer__starting p { margin:0; font-weight:800; font-size:1rem; }
    .mpm-drawer__starting small { color:var(--mpm-text-2); }

    /* drawer hero (artwork banner) */
    .mpm-dhero { position:relative; overflow:hidden; padding:22px 22px 20px; flex-shrink:0; border-bottom:1px solid var(--mpm-border); }
    .mpm-dhero__bg { position:absolute; inset:0; background-size:cover; background-position:center; filter:blur(26px) saturate(1.4); opacity:.5; transform:scale(1.3); }
    .mpm-dhero__scrim { position:absolute; inset:0; background:linear-gradient(180deg, color-mix(in srgb, var(--mpm-surface) 40%, transparent), var(--mpm-surface)); }
    .mpm-dhero__row { position:relative; display:flex; align-items:flex-start; gap:15px; }
    .mpm-dhero__art { width:64px; height:64px; border-radius:15px; object-fit:cover; flex-shrink:0; border:1px solid var(--mpm-border-2); box-shadow:0 8px 22px rgba(0,0,0,.35); }
    .mpm-dhero__name { font-size:1.12rem; font-weight:800; margin:0; letter-spacing:-.01em; line-height:1.25; }
    .mpm-dhero__author { font-size:.8rem; color:var(--mpm-text-2); margin:4px 0 0; }
    .mpm-dhero__close { position:relative; margin-left:auto; flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center;
        border-radius:10px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-muted); cursor:pointer; transition:all .15s; }
    .mpm-dhero__close:hover { color:var(--mpm-text); background:var(--mpm-surface-3); }
    .mpm-dhero__close svg { width:18px; height:18px; }
    .mpm-dbadge { position:relative; display:inline-flex; align-items:center; gap:7px; margin-top:14px; padding:6px 14px; border-radius:999px; font-size:.72rem; font-weight:800; letter-spacing:.02em; }
    .mpm-dbadge .dot { width:8px; height:8px; border-radius:50%; }
    .mpm-dbadge--install { color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-dbadge--install .dot { background:var(--mpm-accent); }
    .mpm-dbadge--update { color:#b45309; background:color-mix(in srgb, var(--mpm-warn) 16%, transparent); border:1px solid color-mix(in srgb, var(--mpm-warn) 38%, transparent); }
    .dark .mpm-dbadge--update { color:var(--mpm-warn); }
    .mpm-dbadge--update .dot { background:var(--mpm-warn); }

    .mpm-drawer__body { flex:1; overflow-y:auto; padding:22px; display:flex; flex-direction:column; gap:20px; }
    .mpm-label { display:block; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:var(--mpm-text-2); margin-bottom:9px; }

    .mpm-select-wrap { position:relative; }
    .mpm-select { width:100%; height:48px; padding:0 40px 0 15px; appearance:none; cursor:pointer;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); border-radius:12px;
        color:var(--mpm-text); font-size:.9rem; font-weight:600; outline:none; transition:border-color .15s, box-shadow .15s; }
    .mpm-select:focus { border-color:var(--mpm-accent); box-shadow:0 0 0 4px var(--mpm-accent-lo); }
    .mpm-select-wrap .caret { position:absolute; right:14px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--mpm-muted); pointer-events:none; }
    .mpm-loadbox { display:flex; align-items:center; gap:10px; height:48px; padding:0 15px; border-radius:12px;
        background:var(--mpm-surface-2); border:1.5px solid var(--mpm-border-2); color:var(--mpm-muted); font-size:.88rem; }
    .mpm-loadbox svg { width:17px; height:17px; }

    /* preserve panel */
    .mpm-preserve { border:1px solid var(--mpm-border); background:var(--mpm-surface-2); border-radius:14px; padding:15px; }
    .mpm-preserve__head { display:flex; align-items:center; gap:9px; margin-bottom:11px; font-size:.8rem; font-weight:700; color:var(--mpm-text-2); }
    .mpm-preserve__head svg { width:16px; height:16px; color:var(--mpm-success); }
    .mpm-preserve__tags { display:flex; flex-wrap:wrap; gap:7px; }
    .mpm-tag { font-family:ui-monospace,monospace; font-size:.68rem; padding:4px 9px; border-radius:7px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2); }
    .mpm-preserve__note { font-size:.72rem; color:var(--mpm-muted); margin:11px 0 0; }

    /* option toggles */
    .mpm-opt { display:flex; align-items:flex-start; gap:13px; cursor:pointer; padding:13px 14px; border-radius:13px;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:border-color .15s, background .15s; }
    .mpm-opt:hover { border-color:var(--mpm-border-2); }
    .mpm-opt input { position:absolute; opacity:0; width:0; height:0; }
    .mpm-opt__track { position:relative; flex-shrink:0; margin-top:1px; width:44px; height:25px; border-radius:999px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border-2); transition:all .18s; }
    .mpm-opt__track::after { content:""; position:absolute; top:2px; left:2px; width:19px; height:19px; border-radius:50%; background:#fff;
        box-shadow:0 1px 3px rgba(0,0,0,.35); transition:transform .18s; }
    .mpm-opt input:checked + .mpm-opt__track { background:linear-gradient(135deg, var(--mpm-accent), var(--mpm-accent-2)); border-color:transparent; }
    .mpm-opt input:checked + .mpm-opt__track::after { transform:translateX(19px); }
    .mpm-opt__title { font-size:.87rem; font-weight:700; }
    .mpm-opt__desc { font-size:.75rem; color:var(--mpm-muted); margin-top:2px; line-height:1.4; }
    .mpm-opt--danger input:checked + .mpm-opt__track { background:var(--mpm-danger); border-color:transparent; }
    .mpm-danger-panel { padding:14px; border-radius:13px; border:1px solid color-mix(in srgb, var(--mpm-danger) 42%, var(--mpm-border));
        background:color-mix(in srgb, var(--mpm-danger) 8%, var(--mpm-surface-2)); }
    .mpm-danger-panel__title { display:flex; align-items:center; gap:8px; color:var(--mpm-danger); font-size:.8rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
    .mpm-danger-panel__title svg { width:16px; height:16px; flex-shrink:0; }
    .mpm-danger-panel__text { margin:7px 0 0; color:var(--mpm-text-2); font-size:.75rem; line-height:1.45; }
    .mpm-targets { display:flex; flex-wrap:wrap; gap:7px; margin-top:11px; }
    .mpm-target { font-family:ui-monospace,monospace; font-size:.69rem; padding:5px 9px; border-radius:7px;
        color:var(--mpm-text-2); background:var(--mpm-surface-3); border:1px solid var(--mpm-border-2); }
    .mpm-backup-list { display:flex; flex-direction:column; gap:8px; margin-top:12px; max-height:240px; overflow-y:auto; }
    .mpm-backup-row { display:grid; grid-template-columns:18px minmax(0,1fr) auto; gap:10px; align-items:center; padding:10px 11px;
        border-radius:10px; background:var(--mpm-surface); border:1px solid var(--mpm-border); cursor:pointer; }
    .mpm-backup-row:hover { border-color:color-mix(in srgb, var(--mpm-danger) 45%, var(--mpm-border-2)); }
    .mpm-backup-row.is-disabled { opacity:.5; cursor:not-allowed; }
    .mpm-backup-row input { width:16px; height:16px; margin:0; accent-color:var(--mpm-danger); }
    .mpm-backup-row__name { display:block; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.78rem; font-weight:700; color:var(--mpm-text); }
    .mpm-backup-row__meta { display:block; margin-top:2px; font-size:.68rem; color:var(--mpm-muted); }
    .mpm-backup-row__state { font-size:.67rem; font-weight:800; color:var(--mpm-muted); white-space:nowrap; }
    .mpm-critical { margin-top:11px; padding:9px 10px; border-radius:9px; color:#fff; background:var(--mpm-danger); font-size:.72rem; font-weight:700; line-height:1.4; }

    .mpm-drawer__foot { flex-shrink:0; padding:18px 22px; border-top:1px solid var(--mpm-border); background:var(--mpm-surface); display:flex; flex-direction:column; gap:12px; }
    .mpm-drawer__actions { display:flex; gap:11px; }
    .mpm-drawer__actions .mpm-btn--primary, .mpm-drawer__actions .mpm-btn--update { flex:1; }

    .mpm-link-existing { background:none; border:0; padding:0; cursor:pointer; font-size:.83rem; font-weight:700; color:var(--mpm-accent);
        text-decoration:underline; text-underline-offset:3px; }
    .mpm-link-existing:hover:not(:disabled) { color:var(--mpm-text); }
    .mpm-link-existing:disabled { opacity:.5; cursor:not-allowed; text-decoration:none; }
    .mpm-link-existing__hint { display:block; margin-top:5px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-link-wrap { text-align:center; padding-top:2px; }

    /* ══════════ Last-install-log modal (centered) ══════════ */
    .mpm-logscrim { position:fixed; inset:0; z-index:9992; display:flex; align-items:center; justify-content:center; padding:18px;
        background:rgba(6,4,20,.6); backdrop-filter:blur(5px); }
    .mpm-logmodal { width:100%; max-width:660px; max-height:86vh; display:flex; flex-direction:column; overflow:hidden;
        border-radius:20px; background:var(--mpm-surface); border:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg); }
    .mpm-logmodal__head { display:flex; align-items:flex-start; gap:12px; padding:20px 22px; border-bottom:1px solid var(--mpm-border); }
    .mpm-logmodal__title { font-size:1.05rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-logmodal__meta { font-size:.8rem; color:var(--mpm-text-2); margin:4px 0 0; }
    .mpm-logmodal__close { margin-left:auto; flex-shrink:0; width:34px; height:34px; display:flex; align-items:center; justify-content:center;
        border-radius:10px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-muted); cursor:pointer; transition:all .15s; }
    .mpm-logmodal__close:hover { color:var(--mpm-text); background:var(--mpm-surface-3); }
    .mpm-logmodal__close svg { width:18px; height:18px; }
    .mpm-logmodal__body { padding:20px 22px; display:flex; }

    .mpm-more { display:flex; justify-content:center; padding:4px 0 2px; }
    .mpm-more__btn { min-width:190px; }

    .mpm-info-scrim { position:fixed; inset:0; z-index:10040; background:rgba(5,5,7,.82); backdrop-filter:blur(7px); }
    .mpm-info { position:fixed; z-index:10050; left:50%; top:50%; transform:translate(-50%,-50%); width:min(860px,calc(100vw - 28px));
        max-height:min(82vh,760px); display:flex; flex-direction:column; overflow:hidden; border-radius:20px; border:1px solid var(--mpm-border-2);
        background:var(--mpm-surface); box-shadow:0 28px 80px rgba(0,0,0,.6); }
    .mpm-info__head { display:flex; align-items:center; gap:13px; padding:16px 18px; border-bottom:1px solid var(--mpm-border); }
    .mpm-info__art { width:44px; height:44px; object-fit:cover; border-radius:11px; border:1px solid var(--mpm-border-2); }
    .mpm-info__title { min-width:0; flex:1; }
    .mpm-info__eyebrow { margin:0 0 2px; font-size:.64rem; text-transform:uppercase; letter-spacing:.12em; font-weight:800; color:var(--mpm-accent); }
    .mpm-info__name { margin:0; font-size:1.05rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .mpm-info__body { padding:20px; overflow:auto; }
    .mpm-info__error { margin-bottom:14px; padding:10px 12px; border-radius:10px; color:#fecaca; background:color-mix(in srgb,var(--mpm-danger) 14%,transparent); border:1px solid color-mix(in srgb,var(--mpm-danger) 38%,transparent); font-size:.78rem; }
    .mpm-description { margin:0; color:var(--mpm-text-2); line-height:1.65; font-size:.88rem; white-space:pre-wrap; overflow-wrap:anywhere; }
    .mpm-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:12px; }
    .mpm-gallery__item { display:block; width:100%; padding:0; overflow:hidden; border-radius:13px; border:1px solid var(--mpm-border); background:var(--mpm-surface-2); text-decoration:none; cursor:pointer; text-align:left; }
    .mpm-gallery__item img { width:100%; aspect-ratio:16/9; object-fit:cover; display:block; background:var(--mpm-surface-3); }
    .mpm-gallery__cap { padding:9px 10px; font-size:.72rem; color:var(--mpm-text-2); }
    .mpm-gallery__empty { padding:42px 20px; text-align:center; color:var(--mpm-muted); border:1px dashed var(--mpm-border-2); border-radius:14px; }
    .mpm-info__foot { display:flex; justify-content:flex-end; gap:9px; padding:14px 18px; border-top:1px solid var(--mpm-border); }

    .mpm-gallery-dialog { width:min(94vw,1320px); height:min(88vh,920px); max-width:none; max-height:none; padding:0; overflow:hidden;
        border-radius:18px; border:1px solid rgba(255,255,255,.16); background:#111114; color:#fff; box-shadow:0 28px 100px rgba(0,0,0,.72); }
    .mpm-gallery-dialog::backdrop { background:rgba(0,0,0,.86); backdrop-filter:blur(8px); }
    .mpm-gallery-dialog__panel { position:relative; width:100%; height:100%; display:flex; flex-direction:column; }
    .mpm-gallery-dialog__stage { flex:1; min-height:0; display:flex; align-items:center; justify-content:center; padding:20px; background:#08080a; }
    .mpm-gallery-dialog__img { width:100%; height:100%; object-fit:contain; display:block; }
    .mpm-gallery-dialog__cap { flex-shrink:0; padding:12px 62px 13px 16px; color:#e4e4e7; font-size:.8rem; text-align:center; border-top:1px solid rgba(255,255,255,.08); background:#111114; }
    .mpm-gallery-dialog__close { position:absolute; z-index:2; top:12px; right:12px; width:42px; height:42px; display:flex; align-items:center; justify-content:center;
        border-radius:999px; border:2px solid rgba(255,255,255,.92); background:#dc2626; color:#fff; cursor:pointer; font-size:1.55rem; font-weight:800; line-height:1; box-shadow:0 6px 20px rgba(0,0,0,.45); }
    .mpm-gallery-dialog__close:hover { background:#ef4444; transform:scale(1.04); }
    @media (max-width:560px){
        .mpm-gallery-dialog { width:96vw; height:84vh; border-radius:14px; }
        .mpm-gallery-dialog__stage { padding:10px; }
    }

    .mpm-confirm-scrim { position:fixed; inset:0; z-index:10060; background:rgba(5,5,7,.86); backdrop-filter:blur(8px); }
    .mpm-confirm { position:fixed; z-index:10070; left:50%; top:50%; transform:translate(-50%,-50%); width:min(560px,calc(100vw - 28px));
        overflow:hidden; border-radius:20px; background:var(--mpm-surface); border:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg); }
    .mpm-confirm__head { padding:18px 20px; border-bottom:1px solid var(--mpm-border); }
    .mpm-confirm__eyebrow { margin:0 0 4px; color:var(--mpm-accent); font-size:.65rem; font-weight:850; text-transform:uppercase; letter-spacing:.12em; }
    .mpm-confirm__title { margin:0; font-size:1.08rem; font-weight:850; }
    .mpm-confirm__body { padding:18px 20px; display:flex; flex-direction:column; gap:12px; }
    .mpm-confirm__pack { display:flex; gap:12px; align-items:center; }
    .mpm-confirm__pack img { width:48px; height:48px; border-radius:12px; object-fit:cover; border:1px solid var(--mpm-border-2); }
    .mpm-confirm__grid { display:grid; grid-template-columns:1fr 1fr; gap:9px; }
    .mpm-confirm__item { padding:10px 11px; border-radius:11px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-confirm__k { display:block; font-size:.64rem; color:var(--mpm-muted); text-transform:uppercase; letter-spacing:.08em; font-weight:800; }
    .mpm-confirm__v { display:block; margin-top:2px; font-size:.8rem; color:var(--mpm-text); font-weight:750; }
    .mpm-confirm__warn { padding:10px 11px; border-radius:11px; color:#fcd34d; background:color-mix(in srgb,var(--mpm-warn) 10%,var(--mpm-surface)); border:1px solid color-mix(in srgb,var(--mpm-warn) 35%,var(--mpm-border)); font-size:.75rem; line-height:1.45; }
    .mpm-confirm__foot { display:flex; gap:10px; justify-content:flex-end; padding:14px 20px; border-top:1px solid var(--mpm-border); }

    @media (max-width:860px){
        .mpm-row { flex-wrap:wrap; }
        .mpm-row__cta { width:100%; justify-content:space-between; margin-top:4px; }
        .mpm-cta-state { flex:1; justify-content:center; }
        /* labels get too cramped side-by-side on narrow viewports — fall back to the vertical stepper */
        .mpm-timeline { flex-direction:column; gap:2px; }
        .mpm-tl { flex:none; flex-direction:row; align-items:center; gap:14px; padding:11px 0 11px 4px; text-align:left; }
        .mpm-tl__rail { width:34px; flex-shrink:0; }
        .mpm-tl:not(:last-child) .mpm-tl__rail::after { top:26px; bottom:-16px; left:50%; right:auto;
            width:2px; height:auto; transform:translateX(-50%); }
        .mpm-tl__label { font-size:.9rem; }
    }
    @media (max-width:560px){
        .mpm-hero__title { font-size:1.25rem; }
        .mpm-omni__kbd { display:none; }
        .mpm-drawer { max-width:100%; }
    }
</style>

<div
    class="mpm"
    x-data="{
        showDebug: false,
        filtersOpen: false,
        drawerOpen: @entangle('showModal'),
        infoOpen: @entangle('showInfoModal'),
        confirmOpen: @entangle('showInstallConfirmation'),
        lastLogOpen: @entangle('showLastLog'),
        starting: false,
        linking: false,
        autoScroll: true,
        scrollDebugToBottom() {
            if (!this.autoScroll) return;
            this.$nextTick(() => { const el = this.$refs.termBody; if (el) el.scrollTop = el.scrollHeight; });
        }
    }"
    x-init="
        document.documentElement.style.setProperty('background', '#050506', 'important');
        document.documentElement.style.setProperty('background-color', '#050506', 'important');
        document.body.style.setProperty('background', '#050506', 'important');
        document.body.style.setProperty('background-color', '#050506', 'important');
        $watch('$wire.debugLog', () => scrollDebugToBottom());
    "
>
<div class="mpm-shell">

    {{-- ══════════════ INSTALL CONSOLE (progress) ══════════════ --}}
    @if ($isInstalling || in_array($installStatus, ['installing', 'cancelling', 'failed']))

        <div wire:poll.2000ms="pollProgress"></div>

        <div class="mpm-console mpm-rise">
            <div class="mpm-console__head">
                <div>
                    <p class="mpm-console__eyebrow">
                        @if($installStatus === 'failed') Deployment halted
                        @elseif($installStatus === 'installed') Deployment complete
                        @elseif($installStatus === 'cancelling') Stopping installer
                        @else Deploying to server @endif
                    </p>
                    <h2 class="mpm-console__title {{ $installStatus === 'failed' ? 'err' : ($installStatus === 'installed' ? 'ok' : '') }}">
                        @if($installStatus === 'failed') Installation Failed
                        @elseif($installStatus === 'installed') Installation Complete
                        @elseif($installStatus === 'cancelling') Cancelling installation…
                        @else Building your modpack… @endif
                    </h2>
                </div>
                <div class="mpm-clock">
                    <p class="mpm-clock__k">Elapsed</p>
                    @if($isInstalling)
                        {{-- Elapsed derives from a fixed start timestamp so repeated Livewire
                             polls (which re-run x-init) can't speed the counter up. --}}
                        <p class="mpm-clock__n"
                           x-data="{ start: {{ $installStartedAt ?: 'Date.now()' }}, now: Date.now() }"
                           x-init="setInterval(() => { now = Date.now() }, 1000)"
                           x-text="Math.max(0, Math.floor((now - start) / 1000)) + 's'">0s</p>
                    @else
                        <p class="mpm-clock__n">{{ $installElapsed }}s</p>
                    @endif
                </div>
            </div>

            <div class="mpm-console__body">

                {{-- Progress bar --}}
                <div>
                    <div class="mpm-bar__top"><span>Overall progress</span><b>{{ $progress }}%</b></div>
                    <div class="mpm-bar__track">
                        <div class="mpm-bar__fill {{ $installStatus === 'failed' ? 'is-failed' : ($installStatus === 'installed' ? 'is-done' : 'is-running') }}" style="width: {{ $progress }}%"></div>
                    </div>
                </div>

                @if($installStatus === 'failed' && $installError)
                    <div class="mpm-alert">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div><b>Error:</b> {{ $installError }}</div>
                    </div>
                @endif

                {{-- Vertical timeline --}}
                @php
                    $stepLabels   = $this->getInstallStepLabels();
                    $stepStatuses = $steps ?: array_fill_keys(array_keys($stepLabels), 'pending');
                    $runningStep  = collect($steps ?? [])->filter(fn($s) => $s === 'running')->keys()->first();
                    $runningLabel = $runningStep ? ($stepLabels[$runningStep] ?? $runningStep) : null;
                @endphp
                <div class="mpm-timeline">
                    @foreach ($stepLabels as $key => $label)
                        @php $st = $stepStatuses[$key] ?? 'pending'; @endphp
                        <div class="mpm-tl {{ $st === 'done' ? 'is-done' : ($st === 'running' ? 'is-running' : ($st === 'failed' ? 'is-failed' : '')) }}">
                            <div class="mpm-tl__rail">
                                <div class="mpm-tl__node">
                                    @if($st === 'done')
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    @elseif($st === 'running')
                                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    @elseif($st === 'failed')
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                    @endif
                                </div>
                            </div>
                            <span class="mpm-tl__label">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                @if($runningLabel)
                    <div class="mpm-now">
                        <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>{{ $runningLabel }}…</span>
                    </div>
                @endif

                {{-- Terminal / debug log --}}
                <div>
                    <button type="button" class="mpm-term-toggle" @click="showDebug = !showDebug">
                        <svg class="chev" :class="showDebug ? 'open' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span x-text="showDebug ? 'Hide install log' : 'Show install log'">Show install log</span>
                    </button>
                    <div x-show="showDebug" x-transition x-cloak style="margin-top:12px;">
                        <div class="mpm-term">
                            <div class="mpm-term__bar">
                                <span class="mpm-term__dots"><i></i><i></i><i></i></span>
                                <span class="mpm-term__title">modpack-manager · install.log</span>
                                <span class="mpm-term__count">{{ count($debugLog) }} lines</span>
                            </div>
                            <div class="mpm-term__body" x-ref="termBody">
                                @forelse($debugLog as $line)
                                    <div>{{ $line }}</div>
                                @empty
                                    <div class="muted">Waiting for log output…</div>
                                @endforelse
                            </div>
                            <label class="mpm-term__foot">
                                <input type="checkbox" x-model="autoScroll"> Auto-scroll
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="mpm-console__foot">
                @if($installStatus === 'installed')
                    <button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--success">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg> Done
                    </button>
                @elseif($installStatus === 'failed')
                    <button type="button" wire:click="cancelInstallView" class="mpm-btn mpm-btn--ghost">Back to library</button>
                @elseif($installStatus === 'cancelling')
                    <button type="button" class="mpm-btn mpm-btn--ghost" disabled style="opacity:.65; cursor:not-allowed;">Cancelling…</button>
                    <span style="font-size:.78rem; color:var(--mpm-muted);">Waiting for the running queue job to stop. The library will not unlock early and trap the next install behind it.</span>
                @else
                    <button type="button" wire:click="cancelInstallView"
                            wire:confirm="Cancel this installation? If it is already running, this screen will stay here until the queue worker has actually stopped it."
                            class="mpm-btn mpm-btn--ghost">
                        Cancel install
                    </button>
                    <span style="font-size:.78rem; color:var(--mpm-muted);">Queued installs cancel immediately; running installs remain here until the worker has really stopped.</span>
                @endif
            </div>
        </div>

    {{-- ══════════════ MODPACK LIBRARY (browse) ══════════════ --}}
    @else

        @if($installedModpack)
            <div class="mpm-loadout mpm-rise">
                @if($installedModpack['iconUrl'])
                    <img src="{{ $installedModpack['iconUrl'] }}" alt="" class="mpm-loadout__art">
                @endif
                <div style="flex:1; min-width:0;">
                    <div class="mpm-loadout__label"><span class="live"></span> Active modpack</div>
                    <div class="mpm-loadout__name">{{ $installedModpack['name'] }}</div>
                    @if($installedModpack['version'])<div class="mpm-loadout__ver">{{ $installedModpack['version'] }}</div>@endif
                    @if($updateAvailable && $latestVersionLabel)
                        <div class="mpm-loadout__ver" style="color:var(--mpm-warn); font-weight:600;">New version available: {{ $latestVersionLabel }}</div>
                    @endif
                </div>
                @if($updateAvailable)
                    <button type="button" wire:click="openModal(@js($installedModpack['id']), @js($installedModpack['provider'] ?? ''))" class="mpm-btn mpm-btn--update">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Update available
                    </button>
                @else
                    <span class="mpm-dbadge" style="color:var(--mpm-success); background:color-mix(in srgb, var(--mpm-success) 14%, transparent); border:1px solid color-mix(in srgb, var(--mpm-success) 36%, transparent);">
                        <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        Up to date
                    </span>
                @endif
            </div>
        @endif

        {{-- Hero search --}}
        <div class="mpm-hero mpm-rise">
            <div class="mpm-hero__grid"></div>
            <p class="mpm-hero__eyebrow">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Modpacks
            </p>
            <h1 class="mpm-hero__title">Deploy a modpack to your server</h1>
            <p class="mpm-hero__sub">Search CurseForge, Modrinth, FTB &amp; ATLauncher — pick a pack and we build &amp; launch it for you.</p>

            <div class="mpm-omni">
                <div class="mpm-omni__field">
                    <span class="mpm-omni__ico">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                    </span>
                    <input type="text" wire:model.lazy="search" wire:keydown.enter="searchModpacks"
                           placeholder="Search modpacks — try “All the Mods”, “Create”, “RLCraft”…">
                    <span class="mpm-omni__kbd"><kbd>Enter</kbd></span>
                    <button type="button" class="mpm-omni__go" wire:click="searchModpacks">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        Search
                    </button>
                </div>
            </div>

            <div class="mpm-pills">
                <button type="button" wire:click="setProvider('all')" class="mpm-pill mpm-pill--all {{ $provider === 'all' ? 'is-active' : '' }}"><span class="dot"></span> All sources</button>
                <button type="button" wire:click="setProvider('curseforge')" class="mpm-pill mpm-pill--cf {{ $provider === 'curseforge' ? 'is-active' : '' }}"><span class="dot"></span> CurseForge</button>
                <button type="button" wire:click="setProvider('modrinth')" class="mpm-pill mpm-pill--mr {{ $provider === 'modrinth' ? 'is-active' : '' }}"><span class="dot"></span> Modrinth</button>
                <button type="button" wire:click="setProvider('ftb')" class="mpm-pill mpm-pill--ftb {{ $provider === 'ftb' ? 'is-active' : '' }}"><span class="dot"></span> FTB</button>
                <button type="button" wire:click="setProvider('atlauncher')" class="mpm-pill mpm-pill--atl {{ $provider === 'atlauncher' ? 'is-active' : '' }}"><span class="dot"></span> ATLauncher</button>

                @php $activeFilters = $this->getActiveFilterCount(); @endphp
                <button type="button" class="mpm-filterbtn {{ $activeFilters ? 'is-on' : '' }}" @click="filtersOpen = !filtersOpen" :class="{ 'is-open': filtersOpen }">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 018 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                    Filters
                    @if($activeFilters)<span class="mpm-filterbtn__badge">{{ $activeFilters }}</span>@endif
                </button>
            </div>

            @php $activeFilterChips = $this->getActiveFilterChips(); @endphp
            @if(!empty($activeFilterChips))
                <div class="mpm-active-filters">
                    @foreach($activeFilterChips as $chip)
                        <button type="button" class="mpm-active-filter"
                                wire:click="removeFilterChip(@js($chip['type']), @js($chip['value']))"
                                title="Remove filter">
                            <span>{{ $chip['label'] }}</span><span class="mpm-active-filter__x">×</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Filter panel: Minecraft version · loader · category --}}
            <div class="mpm-filterpanel" x-show="filtersOpen" x-cloak x-transition>
                <div class="mpm-filtergrid">
                    <div>
                        <label class="mpm-label">Minecraft version</label>
                        <div class="mpm-select-wrap">
                            <select class="mpm-select" wire:model="filterVersion">
                                <option value="">Any version</option>
                                @foreach($this->getFilterVersionOptions() as $v)
                                    <option value="{{ $v }}">{{ $v }}</option>
                                @endforeach
                            </select>
                            <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                    <div>
                        <label class="mpm-label">Loaders <span style="font-weight:500;color:var(--mpm-muted);">(pick more than one)</span></label>
                        @if($this->loaderFilterAvailable())
                            <div class="mpm-loader-picks">
                                @foreach($this->getFilterLoaderOptions() as $slug => $label)
                                    <button type="button"
                                            wire:click="toggleLoader(@js($slug))"
                                            class="mpm-filterchip {{ in_array($slug, $filterLoaders, true) ? 'is-active' : '' }}">
                                        <span class="dot"></span>{{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="mpm-filter-note">ATLauncher does not expose loader data in its public pack list, so loader filtering is unavailable for this source.</p>
                        @endif
                    </div>
                    @if($this->categoryFilterAvailable())
                    <div>
                        <label class="mpm-label">Category</label>
                        <div class="mpm-select-wrap">
                            <select class="mpm-select" wire:model="filterCategory">
                                <option value="">Any category</option>
                                @foreach($this->getFilterCategoryOptions() as $slug => $label)
                                    <option value="{{ $slug }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                    @endif
                    <div>
                        <label class="mpm-label">Sort</label>
                        <div class="mpm-select-wrap">
                            <select class="mpm-select" wire:model="filterSort">
                                @foreach($this->getSortOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                </div>
                @if($provider === 'all')
                    <p class="mpm-filter-note" style="margin-top:12px;">All sources includes CurseForge, Modrinth, FTB and ATLauncher. If a selected filter is not exposed by a provider, that provider is left out instead of showing incorrect matches.</p>
                @endif
                <div class="mpm-filteractions">
                    @if($activeFilters)
                        <button type="button" class="mpm-btn mpm-btn--ghost" wire:click="clearFilters">Clear filters</button>
                    @endif
                    <button type="button" class="mpm-btn mpm-btn--primary" wire:click="applyFilters">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Apply filters
                    </button>
                </div>
            </div>

            @if($hasLastLog)
                <div>
                    <button type="button" wire:click="showLastInstallLog" class="mpm-loglink">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Show last install log
                    </button>
                </div>
            @endif
        </div>

        @if(!empty($providerErrors) && $provider === 'all')
            <div class="mpm-provider-warning mpm-rise">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <b>Some sources could not be reached.</b> Results from the other providers are still shown.
                    <div style="margin-top:3px;opacity:.9;">{{ implode(' ', array_values($providerErrors)) }}</div>
                </div>
                <button type="button" wire:click="retryProviderSearches">Retry</button>
            </div>
        @endif

        @if($errorMsg)
            <div class="mpm-alert mpm-rise">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div><b>Failed to load modpacks</b><div style="margin-top:4px;opacity:.85;">{{ $errorMsg }}</div></div>
            </div>
        @endif

        {{-- Results feed --}}
        @if($isLoading)
            <div class="mpm-feed">
                @foreach(range(1,6) as $_)
                    <div class="mpm-skel-row">
                        <div class="mpm-shimmer" style="width:56px;height:56px;border-radius:13px;flex-shrink:0;"></div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:9px;">
                            <div class="mpm-shimmer" style="height:15px;width:38%;"></div>
                            <div class="mpm-shimmer" style="height:11px;width:65%;"></div>
                            <div class="mpm-shimmer" style="height:10px;width:45%;"></div>
                        </div>
                        <div class="mpm-shimmer" style="width:96px;height:38px;border-radius:11px;flex-shrink:0;"></div>
                    </div>
                @endforeach
            </div>

        @elseif(!empty($modpacks))
            <div class="mpm-feed-head">
                <h3>{{ $search !== '' ? 'Results' : 'Popular modpacks' }}</h3>
                <span class="count">{{ count($modpacks) }} {{ \Illuminate\Support\Str::plural('pack', count($modpacks)) }}</span>
            </div>
            <div class="mpm-feed">
                @foreach($modpacks as $i => $pack)
                    @php
                        $isInstalled = $installedModpack
                            && (string) $installedModpack['id'] === (string) $pack['id']
                            && ($installedModpack['provider'] ?? null) === ($pack['provider'] ?? null);
                        $dl = (int) ($pack['downloadCount'] ?? 0);
                        $dlFmt = $dl >= 1000000 ? round($dl/1000000,1).'M' : ($dl >= 1000 ? round($dl/1000).'K' : $dl);
                        $pv = $pack['provider'] ?? '';
                        $pvLabel = ['curseforge'=>'CurseForge','modrinth'=>'Modrinth','ftb'=>'FTB','atlauncher'=>'ATLauncher'][$pv] ?? ucfirst($pv);
                        $pvColor = ['curseforge'=>'var(--mpm-cf)','modrinth'=>'var(--mpm-mr)','ftb'=>'var(--mpm-ftb)','atlauncher'=>'var(--mpm-atl)'][$pv] ?? '#8b8aa3';
                        $siteUrl = $this->externalUrl($pack['websiteUrl'] ?? null);
                        $galleryCount = count($pack['gallery'] ?? []);
                    @endphp
                    <div class="mpm-row mpm-rise {{ $isInstalled ? 'is-installed' : '' }}" style="--row-c:{{ $pvColor }}; animation-delay: {{ min($i * 40, 320) }}ms"
                         role="button" tabindex="0"
                         wire:click="openModal(@js($pack['id']), @js($pack['provider'] ?? ''))"
                         wire:key="row-{{ $pv }}-{{ $pack['id'] }}"
                         @keydown.enter="$wire.openModal(@js($pack['id']), @js($pack['provider'] ?? ''))">
                        @if($pack['iconUrl'])
                            <img src="{{ $pack['iconUrl'] }}" alt="" class="mpm-row__art">
                        @else
                            <div class="mpm-row__art mpm-row__art--ph">
                                <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </div>
                        @endif

                        <div class="mpm-row__body">
                            <div class="mpm-row__title">
                                <h3 class="mpm-row__name">{{ $pack['name'] }}</h3>
                                <span class="mpm-chip mpm-chip--src" style="--c:{{ $pvColor }};">{{ $pvLabel }}</span>
                                @foreach(array_slice($pack['loaders'] ?? [], 0, 3) as $loader)
                                    @php $lc = match(strtolower($loader)) {
                                        'forge'=>'#d97706','neoforge'=>'#f97316','fabric'=>'#c9a16b','quilt'=>'#a855f7','liteloader'=>'#38bdf8', default=>'#8b8aa3',
                                    }; @endphp
                                    <span class="mpm-chip" style="--c:{{ $lc }};">{{ $loader }}</span>
                                @endforeach
                            </div>
                            @if(!empty($pack['summary']))
                                <p class="mpm-row__desc">{{ $pack['summary'] }}</p>
                            @endif
                            <div class="mpm-row__meta">
                                @if(!empty($pack['author']))<span class="author">{{ $pack['author'] }}</span>@endif
                                <span title="Downloads">
                                    <svg fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm-1 11H7v-6h2v6zm4 0h-2v-6h2v6z"/></svg>
                                    {{ $dlFmt }}
                                </span>
                                @if(!empty($pack['gameVersions']))<span>{{ $pack['gameVersions'] }}</span>@endif
                                @if(!empty($pack['dateModified']))
                                    <span>
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0"/></svg>
                                        {{ \Carbon\Carbon::parse($pack['dateModified'])->diffForHumans(null, true) }} ago
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="mpm-row__quick">
                            <button type="button" class="mpm-quickbtn mpm-quickbtn--grow"
                                    wire:click.stop="openPackInfo(@js($pack['id']), @js($pack['provider'] ?? ''), 'description')">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m-7 7l-4-4V5a2 2 0 012-2h16a2 2 0 012 2v12a2 2 0 01-2 2H8l-2 2z"/></svg>
                                Description
                            </button>
                            <button type="button" class="mpm-quickbtn mpm-quickbtn--grow"
                                    wire:click.stop="openPackInfo(@js($pack['id']), @js($pack['provider'] ?? ''), 'gallery')">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Gallery{{ $galleryCount ? ' · '.$galleryCount : '' }}
                            </button>
                            @if($siteUrl)
                                <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="mpm-quickbtn" @click.stop title="Open modpack page">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 3h7m0 0v7m0-7L10 14M5 5h5M5 5a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2v-5"/></svg>
                                    Open
                                </a>
                            @endif
                        </div>

                        <div class="mpm-row__cta">
                            @if($isInstalled && $updateAvailable)
                                <span class="mpm-cta-state mpm-cta--update">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Update
                                </span>
                            @elseif($isInstalled)
                                <span class="mpm-cta-state mpm-cta--installed">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    Installed
                                </span>
                            @else
                                <span class="mpm-cta-state mpm-cta--install">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Install
                                </span>
                            @endif
                            <span class="mpm-row__chev">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($hasMore)
                <div class="mpm-more">
                    <button type="button" class="mpm-btn mpm-btn--ghost mpm-more__btn" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                        <span wire:loading.remove wire:target="loadMore">Load 20 more</span>
                        <span wire:loading wire:target="loadMore">Loading more…</span>
                    </button>
                </div>
            @endif

        @else
            <div class="mpm-empty mpm-rise">
                <div class="mpm-empty__orb">
                    <svg width="34" height="34" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p style="font-weight:800;color:var(--mpm-text);margin:0;font-size:1rem;">No modpacks found</p>
                <p style="font-size:.86rem;margin:0;">Try a different search term or switch source above.</p>
            </div>
        @endif

    @endif

    {{-- ══════════════ OVERLAYS ══════════════
         Teleported to <body> so they escape Filament's transformed/clipped content wrapper and
         can dim the ENTIRE viewport (including the panel's own header/footer), instead of being
         trapped inside the page's box. The <template x-teleport> keeps them in this component's
         Alpine + Livewire scope (wire:model / wire:click / wire:init travel with the nodes). The
         wrapper carries `.mpm` so the scoped CSS variables resolve at the new DOM location.
         wire:loading is NOT reliable across teleport (Livewire re-queries the component subtree),
         so the loading states are driven by Alpine `starting` / `linking` flags instead. --}}
    <template x-teleport="body">
    <div class="mpm">

    <div class="mpm-scrim" x-show="drawerOpen" x-cloak x-transition.opacity
         @keydown.escape.window="if (!confirmOpen) $wire.closeModal()" @click="if (!confirmOpen) $wire.closeModal()"></div>

    <div class="mpm-drawer" :class="{ 'is-open': drawerOpen }" x-cloak>

        {{-- Fades in the instant Install is pressed, masking the startInstall round-trip. --}}
        <div class="mpm-drawer__starting" x-show="starting" x-cloak x-transition.opacity>
            <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <div>
                <p>Preparing deployment…</p>
                <small>Setting things up on your server</small>
            </div>
        </div>

        @if($selectedModpack)
            @php
                $isInstalledPack = $installedModpack
                    && (string) $installedModpack['id'] === (string) $selectedModpack['id']
                    && ($installedModpack['provider'] ?? null) === ($selectedModpack['provider'] ?? null);
                $isUpd       = $isInstalledPack && $updateAvailable;
            @endphp

            {{-- Drawer hero --}}
            <div class="mpm-dhero">
                @if($selectedModpack['iconUrl'])
                    <div class="mpm-dhero__bg" style="background-image:url('{{ $selectedModpack['iconUrl'] }}')"></div>
                @endif
                <div class="mpm-dhero__scrim"></div>
                <div class="mpm-dhero__row">
                    @if($selectedModpack['iconUrl'])
                        <img src="{{ $selectedModpack['iconUrl'] }}" alt="" class="mpm-dhero__art">
                    @endif
                    <div style="min-width:0;">
                        <h3 class="mpm-dhero__name">{{ $selectedModpack['name'] }}</h3>
                        @if(!empty($selectedModpack['author']))<p class="mpm-dhero__author">by {{ $selectedModpack['author'] }}</p>@endif
                    </div>
                    <button type="button" class="mpm-dhero__close" @click="$wire.closeModal()">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <span class="mpm-dbadge {{ $isUpd ? 'mpm-dbadge--update' : 'mpm-dbadge--install' }}">
                    <span class="dot"></span>{{ $isUpd ? 'Update deployment' : ($isInstalledPack ? 'Reinstall pack' : 'New install') }}
                </span>
            </div>

            {{-- Drawer body --}}
            <div class="mpm-drawer__body"
                 wire:key="drawer-{{ $selectedModpack['provider'] ?? '' }}-{{ $selectedModpack['id'] ?? '' }}"
                 x-init="$wire.loadVersions(); $wire.loadInstallTargets()">

                <div>
                    <label class="mpm-label">Version</label>
                    @if($versionsLoading)
                        <div class="mpm-loadbox">
                            <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Loading versions…
                        </div>
                    @elseif(empty($versions))
                        <p style="color:var(--mpm-danger);font-size:.85rem;margin:0;">No versions available.</p>
                    @else
                        <div class="mpm-select-wrap">
                            <select class="mpm-select" x-model="$wire.selectedVersion">
                                @foreach($versions as $ver)
                                    @php
                                        $label = $ver['displayName'] ?? $ver['versionNumber'] ?? $ver['name'] ?? $ver['fileName'] ?? $ver['id'];
                                        $date = isset($ver['datePublished']) ? \Carbon\Carbon::parse($ver['datePublished'])->format('M j, Y')
                                              : (isset($ver['fileDate']) ? \Carbon\Carbon::parse($ver['fileDate'])->format('M j, Y') : '');
                                        $loaderTags = array_values(array_filter(array_map(
                                            fn ($loader) => strtolower((string) $loader),
                                            $ver['loaders'] ?? []
                                        ), fn ($loader) => in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true)));
                                        if (empty($loaderTags)) {
                                            $loaderTags = array_values(array_filter(array_map(
                                                fn ($loader) => strtolower((string) $loader),
                                                $ver['gameVersions'] ?? []
                                            ), fn ($loader) => in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true)));
                                        }
                                        $loaderText = implode('/', array_map(
                                            fn ($loader) => $loader === 'neoforge' ? 'NeoForge' : ucfirst($loader),
                                            array_unique($loaderTags)
                                        ));
                                    @endphp
                                    <option value="{{ $ver['id'] }}">{{ $label }}{{ $loaderText ? ' • '.$loaderText : '' }}{{ $date ? ' • '.$date : '' }}</option>
                                @endforeach
                            </select>
                            <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    @endif
                </div>

                <div class="mpm-preserve">
                    <div class="mpm-preserve__head">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        These files are always kept
                    </div>
                    <div class="mpm-preserve__tags">
                        @foreach($this->getPreservedFilesProperty() as $file)
                            <span class="mpm-tag">{{ $file }}</span>
                        @endforeach
                    </div>
                    <p class="mpm-preserve__note">Player/admin files are restored after the new pack is assembled. server.properties is merged safely so the new pack keeps its own world-generation settings.</p>
                </div>

                <label class="mpm-opt">
                    <input type="checkbox" x-model="$wire.createBackup" @disabled(!$canCreateBackups)>
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Create a backup first</span>
                        <span class="mpm-opt__desc">Makes a Pelican backup (shown in the Backups tab) before installing — recommended.</span>
                    </span>
                </label>

                <label class="mpm-opt">
                    <input type="checkbox" x-model="$wire.deleteExisting">
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Wipe existing server files</span>
                        <span class="mpm-opt__desc">Removes old mods, configs and loader runtime before install. Defaults ON when changing modpacks and is strongly recommended, but you can turn it off. Worlds are handled separately below.</span>
                    </span>
                </label>

                @if($installedModpack && !$isInstalledPack)
                    <div class="mpm-danger-panel" x-show="!$wire.deleteExisting" x-cloak>
                        <div class="mpm-danger-panel__title">Keeping old server files</div>
                        <p class="mpm-danger-panel__text">You are switching to a different modpack. Leaving this off can mix old mods, configs, datapacks and loader files into the new pack, causing crashes or incorrect world generation. Keeping Wipe existing server files ON is strongly recommended.</p>
                    </div>
                @endif

                <label class="mpm-opt mpm-opt--danger">
                    <input type="checkbox" x-model="$wire.deleteWorld">
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Delete existing world</span>
                        <span class="mpm-opt__desc">Permanently deletes only the active world folders detected from this server's server.properties.</span>
                    </span>
                </label>

                <div class="mpm-danger-panel" x-show="$wire.deleteWorld" x-cloak>
                    <div class="mpm-danger-panel__title">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        World deletion
                    </div>
                    @if(empty($detectedWorlds))
                        <p class="mpm-danger-panel__text">No active world folder is currently detected. The installer will never guess a world name or search unrelated folders.</p>
                    @else
                        <p class="mpm-danger-panel__text">Only these exact folders are eligible for deletion:</p>
                        <div class="mpm-targets">
                            @foreach($detectedWorlds as $world)
                                <span class="mpm-target">/{{ $world }}/</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="mpm-critical" x-show="!$wire.createBackup" x-cloak>No new pre-install backup will be created. Deleted world data cannot be recovered through Pelican.</div>
                </div>

                <label class="mpm-opt mpm-opt--danger">
                    <input type="checkbox" x-model="$wire.deleteBackups" @disabled(!$canReadBackups || !$canDeleteBackups)>
                    <span class="mpm-opt__track"></span>
                    <span>
                        <span class="mpm-opt__title">Delete existing backups</span>
                        <span class="mpm-opt__desc">Permanently removes only the specific backups you select below from this server.</span>
                    </span>
                </label>

                <div class="mpm-danger-panel" x-show="$wire.deleteBackups" x-cloak>
                    <div class="mpm-danger-panel__title">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Permanent backup deletion
                    </div>
                    <p class="mpm-danger-panel__text">Only backups belonging to this server are shown. Locked or currently running backups cannot be selected.</p>
                    <div class="mpm-backup-list">
                        @forelse($availableBackups as $backup)
                            @php
                                $bytes = (int) ($backup['bytes'] ?? 0);
                                $size = $bytes >= 1073741824
                                    ? number_format($bytes / 1073741824, 2) . ' GB'
                                    : number_format($bytes / 1048576, 1) . ' MB';
                                $disabled = $backup['locked'] || $backup['inProgress'];
                            @endphp
                            <label class="mpm-backup-row {{ $disabled ? 'is-disabled' : '' }}">
                                <input type="checkbox" value="{{ $backup['id'] }}" wire:model.live="selectedBackupIds" @disabled($disabled)>
                                <span style="min-width:0;">
                                    <span class="mpm-backup-row__name">{{ $backup['name'] }}</span>
                                    <span class="mpm-backup-row__meta">{{ $backup['createdAt'] }} · {{ $size }}</span>
                                </span>
                                <span class="mpm-backup-row__state">{{ $backup['locked'] ? 'Locked' : ($backup['inProgress'] ? 'Running' : 'Select') }}</span>
                            </label>
                        @empty
                            <p class="mpm-danger-panel__text" style="margin-top:0;">No backups exist for this server.</p>
                        @endforelse
                    </div>
                    <div class="mpm-critical" x-show="!$wire.createBackup" x-cloak>No replacement backup will be created before installation. Any selected backups will be permanently removed.</div>
                </div>
            </div>

            {{-- Drawer footer (sticky) --}}
            <div class="mpm-drawer__foot">
                <div class="mpm-drawer__actions">
                    <button type="button"
                            @click="$wire.reviewInstall()"
                            class="mpm-btn {{ $isUpd ? 'mpm-btn--update' : 'mpm-btn--primary' }}"
                            :disabled="starting || linking"
                            @if($versionsLoading || empty($versions)) disabled @endif>
                        <span class="mpm-ilbl">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Review {{ $isUpd ? 'update' : ($isInstalledPack ? 'reinstall' : 'install') }}
                        </span>
                    </button>
                    <button type="button" class="mpm-btn mpm-btn--ghost" @click="$wire.closeModal()">Cancel</button>
                </div>

                {{-- Non-destructive recovery: register a pack you already have without wiping/re-downloading. --}}
                <div class="mpm-link-wrap">
                    <button type="button"
                            @click="linking = true; $wire.linkInstalled().finally(() => linking = false)"
                            class="mpm-link-existing"
                            :disabled="starting || linking"
                            @if($versionsLoading || empty($versions)) disabled @endif>
                        <span x-show="!linking">Already installed? Link this version without reinstalling</span>
                        <span class="mpm-ilbl" style="justify-content:center;" x-show="linking" x-cloak>
                            <svg class="mpm-spin" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Linking…
                        </span>
                    </button>
                    <span class="mpm-link-existing__hint">Marks this pack as installed for the active-modpack banner &amp; update checks. No files are changed.</span>
                </div>
            </div>
        @else
            <div style="flex:1; display:flex; align-items:center; justify-content:center; padding:40px;">
                <svg class="mpm-spin" style="width:28px;height:28px;color:var(--mpm-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            </div>
        @endif
    </div>

    {{-- ══════════════ MODPACK DESCRIPTION / GALLERY ══════════════ --}}
    <div class="mpm-info-scrim" x-show="infoOpen" x-cloak x-transition.opacity
         @click.self="$wire.closePackInfo()"></div>

    <div class="mpm-info" x-show="infoOpen" x-cloak x-transition>
        @if($infoModpack)
            @php
                $infoSiteUrl = $this->externalUrl($infoModpack['websiteUrl'] ?? null);
                $infoDescription = trim((string) ($infoModpack['description'] ?? $infoModpack['summary'] ?? ''));
                $infoGallery = array_values(array_filter(
                    $infoModpack['gallery'] ?? [],
                    fn ($image) => is_array($image) && $this->externalUrl($image['url'] ?? null)
                ));
            @endphp
            <div class="mpm-info__head">
                @if(!empty($infoModpack['iconUrl']))
                    <img src="{{ $infoModpack['iconUrl'] }}" alt="" class="mpm-info__art">
                @endif
                <div class="mpm-info__title">
                    <p class="mpm-info__eyebrow">{{ $infoMode === 'gallery' ? 'Gallery' : 'Description' }}</p>
                    <h3 class="mpm-info__name">{{ $infoModpack['name'] ?? 'Modpack' }}</h3>
                </div>
                <button type="button" class="mpm-logmodal__close" @click="$wire.closePackInfo()" aria-label="Close">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="mpm-info__body">
                @if($infoError !== '')
                    <div class="mpm-info__error">{{ $infoError }}</div>
                @endif

                @if($infoMode === 'gallery')
                    @if(!empty($infoGallery))
                        <div class="mpm-gallery">
                            @foreach($infoGallery as $image)
                                @php
                                    $imageUrl = $this->externalUrl($image['url'] ?? null);
                                    $thumbUrl = $this->externalUrl($image['thumbnailUrl'] ?? null) ?? $imageUrl;
                                    $caption = trim((string) ($image['title'] ?? $image['description'] ?? ''));
                                @endphp
                                @if($imageUrl)
                                    <button type="button" class="mpm-gallery__item"
                                            data-url="{{ $imageUrl }}" data-caption="{{ $caption }}"
                                            onclick="const d=document.getElementById('mpm-modpack-gallery-preview'),i=document.getElementById('mpm-modpack-gallery-preview-image'),c=document.getElementById('mpm-modpack-gallery-preview-caption'); if(!d||!i)return; i.src=this.dataset.url; if(c){c.textContent=this.dataset.caption||''; c.hidden=!this.dataset.caption;} if(!d.open)d.showModal();">
                                        <img src="{{ $thumbUrl }}" alt="{{ $caption }}" loading="lazy">
                                        @if($caption !== '')
                                            <div class="mpm-gallery__cap">{{ $caption }}</div>
                                        @endif
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="mpm-gallery__empty">No gallery images are available for this modpack.</div>
                    @endif
                @else
                    @if($infoDescription !== '')
                        <p class="mpm-description">{{ $infoDescription }}</p>
                    @else
                        <div class="mpm-gallery__empty">No full description is available for this modpack.</div>
                    @endif
                @endif
            </div>

            <div class="mpm-info__foot">
                @if($infoSiteUrl)
                    <a href="{{ $infoSiteUrl }}" target="_blank" rel="noopener noreferrer" class="mpm-btn mpm-btn--ghost">
                        Open modpack page
                    </a>
                @endif
                <button type="button" class="mpm-btn mpm-btn--primary" @click="$wire.closePackInfo()">Close</button>
            </div>
        @endif
    </div>

    {{-- ══════════════ INSTALL CONFIRMATION ══════════════ --}}
    <div class="mpm-confirm-scrim" x-show="confirmOpen" x-cloak x-transition.opacity
         @click.self="$wire.cancelInstallConfirmation()"></div>
    <div class="mpm-confirm" x-show="confirmOpen" x-cloak x-transition
         @keydown.escape.window="$wire.cancelInstallConfirmation()">
        @if($selectedModpack)
            @php $review = $this->getInstallReview(); @endphp
            <div class="mpm-confirm__head">
                <p class="mpm-confirm__eyebrow">Confirm deployment</p>
                <h3 class="mpm-confirm__title">Review exactly what will happen</h3>
            </div>
            <div class="mpm-confirm__body">
                <div class="mpm-confirm__pack">
                    @if(!empty($selectedModpack['iconUrl']))
                        <img src="{{ $selectedModpack['iconUrl'] }}" alt="">
                    @endif
                    <div>
                        <div style="font-weight:850;">{{ $review['name'] ?? $selectedModpack['name'] }}</div>
                        <div style="font-size:.75rem;color:var(--mpm-muted);margin-top:2px;">{{ $review['provider'] ?? '' }} · {{ $review['version'] ?? '' }}@if(!empty($review['loaders'])) · {{ implode(' / ', $review['loaders']) }}@endif</div>
                    </div>
                </div>
                <div class="mpm-confirm__grid">
                    <div class="mpm-confirm__item"><span class="mpm-confirm__k">Create backup</span><span class="mpm-confirm__v">{{ !empty($review['backup']) ? 'Yes' : 'No' }}</span></div>
                    <div class="mpm-confirm__item"><span class="mpm-confirm__k">Wipe old server files</span><span class="mpm-confirm__v">{{ !empty($review['wipeFiles']) ? 'Yes' : 'No' }}</span></div>
                    <div class="mpm-confirm__item"><span class="mpm-confirm__k">Delete world</span><span class="mpm-confirm__v">{{ !empty($review['deleteWorld']) ? 'Yes' : 'No' }}</span></div>
                    <div class="mpm-confirm__item"><span class="mpm-confirm__k">Delete selected backups</span><span class="mpm-confirm__v">{{ !empty($review['deleteBackups']) ? (($review['backupCount'] ?? 0) . ' selected') : 'No' }}</span></div>
                </div>
                @if(!empty($review['wipeFiles']) || !empty($review['deleteWorld']) || !empty($review['deleteBackups']))
                    <div class="mpm-confirm__warn">This install includes destructive cleanup options. Check the summary above before continuing.</div>
                @endif
            </div>
            <div class="mpm-confirm__foot">
                <button type="button" class="mpm-btn mpm-btn--ghost" @click="$wire.cancelInstallConfirmation()">Back</button>
                <button type="button" class="mpm-btn mpm-btn--primary"
                        :disabled="starting"
                        @click="starting = true; $wire.startInstall().finally(() => starting = false)">
                    <span x-show="!starting">Confirm &amp; install</span>
                    <span x-show="starting" x-cloak>Starting…</span>
                </button>
            </div>
        @endif
    </div>

    {{-- ══════════════ GALLERY IMAGE PREVIEW ══════════════ --}}
    <dialog id="mpm-modpack-gallery-preview" class="mpm-gallery-dialog" onclick="if (event.target === this) this.close()">
        <div class="mpm-gallery-dialog__panel">
            <button type="button" class="mpm-gallery-dialog__close" onclick="this.closest('dialog').close()" aria-label="Close image">×</button>
            <div class="mpm-gallery-dialog__stage">
                <img id="mpm-modpack-gallery-preview-image" class="mpm-gallery-dialog__img" src="" alt="">
            </div>
            <div id="mpm-modpack-gallery-preview-caption" class="mpm-gallery-dialog__cap" hidden></div>
        </div>
    </dialog>

    {{-- ══════════════ LAST INSTALL LOG MODAL ══════════════ --}}
    <div class="mpm-logscrim" x-show="lastLogOpen" x-cloak x-transition.opacity
         @keydown.escape.window="$wire.hideLastInstallLog()" @click.self="$wire.hideLastInstallLog()">
        <div class="mpm-logmodal" x-show="lastLogOpen" x-transition>
            <div class="mpm-logmodal__head">
                <div style="min-width:0;">
                    <h3 class="mpm-logmodal__title">Last install log</h3>
                    @if(!empty($lastLogMeta))
                        @php
                            $llStatus = $lastLogMeta['status'] ?? '';
                            $llColor  = $llStatus === 'failed' ? 'var(--mpm-danger)' : 'var(--mpm-success)';
                        @endphp
                        <p class="mpm-logmodal__meta">
                            {{ $lastLogMeta['name'] ?? 'Modpack' }}@if(!empty($lastLogMeta['version'])) · {{ $lastLogMeta['version'] }}@endif
                            — <span style="color:{{ $llColor }};font-weight:700;">{{ ucfirst($llStatus) }}</span>@if(!empty($lastLogMeta['time'])) · {{ $lastLogMeta['time'] }}@endif
                        </p>
                    @endif
                </div>
                <button type="button" class="mpm-logmodal__close" @click="$wire.hideLastInstallLog()">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="mpm-logmodal__body">
                <div class="mpm-term" style="width:100%;">
                    <div class="mpm-term__bar">
                        <span class="mpm-term__dots"><i></i><i></i><i></i></span>
                        <span class="mpm-term__title">modpack-manager · install.log</span>
                        <span class="mpm-term__count">{{ count($lastLog) }} lines</span>
                    </div>
                    <div class="mpm-term__body" style="height:46vh;">
                        @forelse($lastLog as $line)
                            <div>{{ $line }}</div>
                        @empty
                            <div class="muted">No log output was recorded for the last install.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>{{-- /.mpm teleport wrapper --}}
    </template>

</div>
</div>

</x-filament-panels::page>
