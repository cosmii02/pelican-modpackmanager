<x-filament-panels::page>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Modpack Manager — Individual Mods / Plugins browser
     ────────────────────────────────────────────────────────────────────────
     Sibling of the Modpacks page. Auto-detects (from the egg) whether the server
     runs a mod loader or a plugin platform and installs single jars straight into
     /mods or /plugins via Wings — no egg switch, no reinstall. Shares the same
     self-contained ".mpm" visual language as the Modpacks page so the two read as
     one product. The install drawer + scrim are teleported to <body> to escape the
     panel's clipped content wrapper.
     ═══════════════════════════════════════════════════════════════════════════ --}}

<style>
    .mpm {
        --mpm-accent:    #7c5cff;
        --mpm-accent-2:  #22d3ee;
        --mpm-accent-lo: color-mix(in srgb, var(--mpm-accent) 14%, transparent);
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
        --mpm-sp:  #e8a13a;
        --mpm-hg:  #3b82f6;
        --mpm-success: #22c55e;
        --mpm-warn:    #f59e0b;
        --mpm-danger:  #ef4444;
    }
    .dark .mpm {
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
    .dark .fi-layout, .dark .fi-main-ctn, .dark .fi-main, .dark .fi-page,
    .dark .fi-sidebar, .dark .fi-sidebar-nav {
        background: #050506 !important; background-color: #050506 !important;
    }
    .dark .mpm, .dark .mpm-shell { background:#050506; }
    .dark .mpm .mpm-hero__grid { display:none; }
    .dark .mpm .mpm-row__art, .dark .mpm .mpm-pill, .dark .mpm .mpm-pill .dot,
    .dark .mpm .mpm-pill.is-active, .dark .mpm .mpm-chip--src, .dark .mpm .mpm-cta--install,
    .dark .mpm .mpm-omni__go, .dark .mpm .mpm-btn--primary, .dark .mpm .mpm-seg__btn.is-active {
        box-shadow:none !important;
    }
    .dark .mpm .mpm-omni__go:hover, .dark .mpm .mpm-row:hover .mpm-cta--install,
    .dark .mpm .mpm-btn--primary:hover:not(:disabled) { filter:none; }
    .dark .mpm .mpm-row:hover, .dark .mpm .mpm-omni__field:focus-within { box-shadow:none; }

    @keyframes mpm-rise   { from { opacity:0; transform:translateY(14px);} to { opacity:1; transform:none; } }
    @keyframes mpm-spin   { to { transform: rotate(360deg); } }
    @keyframes mpm-shimmer{ 0%{background-position:-500px 0;} 100%{background-position:500px 0;} }
    .mpm-rise { animation: mpm-rise .45s cubic-bezier(.16,1,.3,1) both; }
    .mpm-spin { animation: mpm-spin 1s linear infinite; }

    .mpm-shell { display:flex; flex-direction:column; gap:20px; }

    /* ── Hero search ── */
    .mpm-hero { position:relative; overflow:hidden; border-radius:22px; padding:26px 26px 22px;
        background:var(--mpm-surface); border:1px solid var(--mpm-border); box-shadow:var(--mpm-shadow); }
    .mpm-hero__grid { position:absolute; inset:0; opacity:.5; pointer-events:none;
        background-image:linear-gradient(color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px),
                         linear-gradient(90deg, color-mix(in srgb, var(--mpm-accent) 8%, transparent) 1px, transparent 1px);
        background-size:34px 34px; -webkit-mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%);
        mask-image:radial-gradient(80% 80% at 50% 0%, #000, transparent 75%); }
    .mpm-hero__topline { position:relative; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
    .mpm-hero__controls { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

    /* Compact Minecraft-version selector (auto-seeded from the server) */
    .mpm-verselect { position:relative; display:inline-flex; align-items:center; }
    .mpm-verselect select { appearance:none; cursor:pointer; height:39px; padding:0 32px 0 34px; border-radius:11px;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); color:var(--mpm-text);
        font-size:.82rem; font-weight:700; outline:none; transition:border-color .15s, box-shadow .15s; }
    .mpm-verselect select:hover { border-color:var(--mpm-border-2); }
    .mpm-verselect select:focus { border-color:var(--mpm-accent); box-shadow:0 0 0 3px var(--mpm-accent-lo); }
    .mpm-verselect .lead { position:absolute; left:11px; width:15px; height:15px; color:var(--mpm-accent); pointer-events:none; }
    .mpm-verselect .caret { position:absolute; right:11px; width:14px; height:14px; color:var(--mpm-muted); pointer-events:none; }

    /* Supported-MC-version row under the drawer version picker */
    .mpm-verhint { display:flex; align-items:center; flex-wrap:wrap; gap:6px 8px; margin-top:10px; font-size:.76rem; }
    .mpm-verhint__lbl { font-weight:700; color:var(--mpm-text-2); }
    .mpm-verhint__chips { display:inline-flex; flex-wrap:wrap; gap:5px; }
    .mpm-verchip { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2);
        font-size:.72rem; font-weight:700; font-family:ui-monospace,monospace; line-height:1.5; }
    .mpm-verchip.is-match { background:color-mix(in srgb, var(--mpm-accent) 16%, transparent);
        border-color:color-mix(in srgb, var(--mpm-accent) 45%, transparent); color:var(--mpm-accent); }
    .mpm-verhint__warn { flex-basis:100%; color:var(--mpm-danger); font-weight:600; }
    .mpm-hero__eyebrow { display:inline-flex; align-items:center; gap:8px; font-size:.66rem; font-weight:800;
        letter-spacing:.16em; text-transform:uppercase; color:var(--mpm-accent); margin:0; }
    .mpm-hero__eyebrow svg { width:15px; height:15px; }
    .mpm-hero__title { position:relative; font-size:1.5rem; font-weight:800; letter-spacing:-.02em; margin:0 0 2px; }
    .mpm-hero__sub { position:relative; font-size:.84rem; color:var(--mpm-text-2); margin:0 0 18px; }
    .mpm-hero__sub b { color:var(--mpm-text); font-weight:700; }
    .mpm-hero__sub code { font-family:ui-monospace,monospace; font-size:.78rem; padding:2px 7px; border-radius:6px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2); }

    /* ── Mode segmented toggle ── */
    .mpm-seg { display:inline-flex; padding:4px; gap:4px; border-radius:13px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-seg__btn { display:inline-flex; align-items:center; gap:7px; padding:7px 14px; border:0; cursor:pointer; border-radius:9px;
        font-size:.8rem; font-weight:700; color:var(--mpm-text-2); background:transparent; transition:all .15s; }
    .mpm-seg__btn svg { width:15px; height:15px; }
    .mpm-seg__btn:hover { color:var(--mpm-text); }
    .mpm-seg__btn.is-active { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 4px 12px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }

    .mpm-omni { position:relative; }
    .mpm-omni__field { position:relative; display:flex; align-items:center; background:var(--mpm-surface-2);
        border:1.5px solid var(--mpm-border-2); border-radius:15px; transition:border-color .18s, box-shadow .18s, background .18s; }
    .mpm-omni__field:focus-within { border-color:var(--mpm-accent); background:var(--mpm-surface);
        box-shadow:0 0 0 4px var(--mpm-accent-lo), 0 8px 26px color-mix(in srgb, var(--mpm-accent) 16%, transparent); }
    .mpm-omni__ico { display:flex; padding-left:18px; color:var(--mpm-accent); }
    .mpm-omni__ico svg { width:20px; height:20px; }
    .mpm-omni input { flex:1; width:100%; height:56px; padding:0 16px; border:0; outline:0; background:transparent;
        color:var(--mpm-text); font-size:1.02rem; font-weight:500; }
    .mpm-omni input::placeholder { color:var(--mpm-muted); font-weight:400; }
    .mpm-omni__go { flex-shrink:0; margin:6px; height:44px; padding:0 20px; border:0; cursor:pointer;
        display:inline-flex; align-items:center; gap:7px; border-radius:11px; font-weight:700; font-size:.88rem; color:#fff;
        background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 60%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 40%, transparent); transition:transform .12s, filter .15s; }
    .mpm-omni__go:hover { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-omni__go svg { width:16px; height:16px; }

    .mpm-pills { position:relative; display:flex; flex-wrap:wrap; align-items:center; gap:9px; margin-top:16px; }
    .mpm-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 15px; border-radius:12px; cursor:pointer;
        font-size:.82rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .16s; }
    .mpm-pill:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); transform:translateY(-1px); }
    .mpm-pill .dot { width:9px; height:9px; border-radius:50%; box-shadow:0 0 6px 0 currentColor; }
    .mpm-pill--all .dot { background:conic-gradient(from 210deg, var(--mpm-cf), var(--mpm-mr), var(--mpm-accent), var(--mpm-cf)); box-shadow:none; }
    .mpm-pill--cf  .dot { background:var(--mpm-cf);  color:var(--mpm-cf); }
    .mpm-pill--mr  .dot { background:var(--mpm-mr);  color:var(--mpm-mr); }
    .mpm-pill--sp  .dot { background:var(--mpm-sp);  color:var(--mpm-sp); }
    .mpm-pill--hg  .dot { background:var(--mpm-hg);  color:var(--mpm-hg); }
    .mpm-pill.is-active { color:#fff; border-color:transparent;
        background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2)));
        box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-pill--cf.is-active { background:linear-gradient(135deg, var(--mpm-cf), #c8471f); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-cf) 40%, transparent); }
    .mpm-pill--mr.is-active { background:linear-gradient(135deg, var(--mpm-mr), #0f9c48); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-mr) 40%, transparent); }
    .mpm-pill--sp.is-active { background:linear-gradient(135deg, var(--mpm-sp), #b8761c); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-sp) 40%, transparent); }
    .mpm-pill--hg.is-active { background:linear-gradient(135deg, var(--mpm-hg), #1d4ed8); box-shadow:0 6px 18px color-mix(in srgb, var(--mpm-hg) 40%, transparent); }
    .mpm-pill.is-active .dot { background:rgba(255,255,255,.9); box-shadow:none; }

    .mpm-filterbtn { position:relative; display:inline-flex; align-items:center; gap:8px; margin-left:auto; padding:8px 15px; border-radius:12px; cursor:pointer;
        font-size:.85rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .15s; }
    .mpm-filterbtn:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); transform:translateY(-1px); }
    .mpm-filterbtn svg { width:16px; height:16px; }
    .mpm-filterbtn.is-open, .mpm-filterbtn.is-on { color:var(--mpm-accent); border-color:color-mix(in srgb, var(--mpm-accent) 45%, var(--mpm-border)); background:var(--mpm-accent-lo); }
    .mpm-filterbtn__badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; border-radius:999px;
        font-size:.66rem; font-weight:800; color:#fff; background:var(--mpm-accent); }
    .mpm-filterpanel { margin-top:14px; padding:18px; border-radius:16px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-filtergrid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; }
    .mpm-filteractions { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:16px; }

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

    /* ── Installed-files manager ── */
    .mpm-installed { border-radius:20px; background:var(--mpm-surface); border:1px solid var(--mpm-border); box-shadow:var(--mpm-shadow); overflow:hidden; }
    .mpm-installed__head { display:flex; flex-wrap:wrap; align-items:center; gap:10px 12px; padding:16px 20px; border-bottom:1px solid var(--mpm-border); }
    .mpm-installed__title { font-size:.95rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-installed__count { font-size:.72rem; font-weight:700; color:var(--mpm-muted); }
    .mpm-installed__dir { font-family:ui-monospace,monospace; font-size:.72rem; padding:3px 8px; border-radius:6px;
        background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text-2); }
    .mpm-minisearch { display:inline-flex; align-items:center; gap:7px; height:32px; padding:0 11px; border-radius:9px;
        background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:border-color .15s, box-shadow .15s; }
    .mpm-minisearch:focus-within { border-color:var(--mpm-accent); box-shadow:0 0 0 3px var(--mpm-accent-lo); }
    .mpm-minisearch svg { width:14px; height:14px; color:var(--mpm-muted); flex-shrink:0; }
    .mpm-minisearch input { border:0; outline:0; background:transparent; color:var(--mpm-text); font-size:.8rem; font-weight:500; width:160px; max-width:42vw; }
    .mpm-minisearch input::placeholder { color:var(--mpm-muted); font-weight:400; }
    .mpm-refresh { margin-left:auto; display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:10px; cursor:pointer;
        font-size:.78rem; font-weight:700; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); transition:all .15s; }
    .mpm-refresh:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-refresh svg { width:14px; height:14px; }
    .mpm-jar { display:flex; align-items:center; gap:13px; padding:12px 20px; border-top:1px solid var(--mpm-border); }
    .mpm-jar:first-of-type { border-top:0; }
    .mpm-jar.is-disabled { opacity:.68; }
    .mpm-jar__ico { flex-shrink:0; width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center;
        color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 26%, transparent); }
    .mpm-jar__ico svg { width:17px; height:17px; }
    .mpm-jar__body { min-width:0; flex:1; }
    .mpm-jar__name { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.82rem; font-weight:600; word-break:break-all; line-height:1.35; }
    .mpm-jar__size { display:flex; align-items:center; gap:7px; flex-wrap:wrap; font-size:.72rem; color:var(--mpm-muted); margin-top:2px; }
    .mpm-jar__badge { display:inline-flex; align-items:center; padding:2px 6px; border-radius:999px; font-size:.62rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .mpm-jar__badge--off { color:#fca5a5; background:color-mix(in srgb,var(--mpm-danger) 12%,transparent); border:1px solid color-mix(in srgb,var(--mpm-danger) 28%,transparent); }
    .mpm-jar__actions { margin-left:auto; flex-shrink:0; display:flex; align-items:center; gap:8px; }
    .mpm-toggle { display:inline-flex; align-items:center; gap:7px; padding:5px 8px; border-radius:10px; cursor:pointer; color:var(--mpm-text-2); background:transparent; border:1px solid transparent; font-size:.7rem; font-weight:750; }
    .mpm-toggle:hover { background:var(--mpm-surface-2); border-color:var(--mpm-border); }
    .mpm-toggle__track { position:relative; width:34px; height:19px; border-radius:999px; background:#3f3f46; transition:background .15s; }
    .mpm-toggle__knob { position:absolute; left:3px; top:3px; width:13px; height:13px; border-radius:50%; background:#fff; transition:transform .15s; box-shadow:0 1px 4px rgba(0,0,0,.35); }
    .mpm-toggle.is-on .mpm-toggle__track { background:#22c55e; }
    .mpm-toggle.is-on .mpm-toggle__knob { transform:translateX(15px); }
    .mpm-jar__rm { flex-shrink:0; display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border-radius:10px; cursor:pointer;
        font-size:.76rem; font-weight:700; color:var(--mpm-danger); background:color-mix(in srgb, var(--mpm-danger) 10%, transparent);
        border:1px solid color-mix(in srgb, var(--mpm-danger) 30%, transparent); transition:all .15s; }
    .mpm-jar__rm:hover { background:color-mix(in srgb, var(--mpm-danger) 18%, transparent); }
    .mpm-jar__rm svg { width:13px; height:13px; }
    @media (max-width:900px){ .mpm-jar { flex-wrap:wrap; } .mpm-jar__actions { width:100%; margin-left:47px; justify-content:flex-end; } }
    .mpm-installed__empty { padding:22px 20px; font-size:.83rem; color:var(--mpm-muted); text-align:center; }
    /* Cap the list height and scroll inside it so a server with hundreds of jars
       doesn't stretch the whole page. ~6 rows tall before it scrolls. */
    .mpm-installed__list { max-height:336px; overflow-y:auto; overscroll-behavior:contain; }
    .mpm-installed__list::-webkit-scrollbar { width:9px; }
    .mpm-installed__list::-webkit-scrollbar-track { background:transparent; }
    .mpm-installed__list::-webkit-scrollbar-thumb { background:var(--mpm-border-2); border-radius:5px; border:2px solid var(--mpm-surface); }
    .mpm-installed__list::-webkit-scrollbar-thumb:hover { background:var(--mpm-muted); }

    .mpm-feed-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:0 4px; }
    .mpm-feed-head h3 { font-size:.95rem; font-weight:800; margin:0; letter-spacing:-.01em; }
    .mpm-feed-head .count { font-size:.75rem; color:var(--mpm-muted); font-weight:600; }

    .mpm-feed { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; align-items:stretch; }
    .mpm-row { --row-c: var(--mpm-accent); position:relative; display:flex; flex-wrap:wrap; align-items:flex-start; align-content:flex-start; gap:14px 16px; width:100%; text-align:left;
        padding:16px; border-radius:16px; cursor:pointer; background:var(--mpm-surface); border:1px solid var(--mpm-border); box-shadow:var(--mpm-shadow);
        transition:transform .16s cubic-bezier(.16,1,.3,1), box-shadow .16s, border-color .16s, background .16s; }
    .mpm-row::before { content:""; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:3px; background:var(--row-c); opacity:0; transition:opacity .16s; }
    .mpm-row:hover { transform:translateY(-3px); border-color:color-mix(in srgb, var(--row-c) 45%, var(--mpm-border)); box-shadow:0 10px 28px color-mix(in srgb, var(--row-c) 16%, transparent); }
    .mpm-row:hover::before { opacity:1; }
    .mpm-row:focus-visible { outline:2px solid var(--mpm-accent); outline-offset:2px; }
    .mpm-row__art { position:relative; width:56px; height:56px; border-radius:13px; object-fit:cover; flex-shrink:0;
        border:1px solid var(--mpm-border-2); background:var(--mpm-surface-3); box-shadow:0 4px 12px rgba(0,0,0,.18); }
    .mpm-row__art--ph { display:flex; align-items:center; justify-content:center; color:var(--mpm-muted); }
    .mpm-row__body { flex:1 1 200px; min-width:0; }
    .mpm-row__title { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .mpm-row__name { font-size:.98rem; font-weight:800; margin:0; letter-spacing:-.01em; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
    .mpm-row__desc { font-size:.79rem; color:var(--mpm-text-2); line-height:1.45; margin:6px 0 0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .mpm-row__meta { display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-top:8px; font-size:.72rem; color:var(--mpm-muted); }
    .mpm-row__meta span { display:inline-flex; align-items:center; gap:5px; }
    .mpm-row__meta svg { width:13px; height:13px; }
    .mpm-row__meta .author { color:var(--mpm-text-2); font-weight:600; }
    .mpm-chip { display:inline-flex; align-items:center; gap:5px; font-size:.63rem; font-weight:800; letter-spacing:.03em; text-transform:uppercase; padding:3px 9px; border-radius:7px; --c:#8b8aa3;
        color:var(--c); background:color-mix(in srgb, var(--c) 14%, transparent); border:1px solid color-mix(in srgb, var(--c) 38%, transparent); }
    .mpm-chip::before { content:""; width:6px; height:6px; border-radius:50%; background:var(--c); }
    .mpm-chip--src { color:#fff; background:var(--c); border-color:var(--c); box-shadow:0 2px 8px color-mix(in srgb, var(--c) 45%, transparent); }
    .mpm-chip--src::before { background:rgba(255,255,255,.9); }
    .mpm-row__cta { flex:1 1 100%; display:flex; align-items:center; gap:12px; margin-top:2px; }
    .mpm-cta-state { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:10px 16px; border-radius:11px; font-size:.82rem; font-weight:700; white-space:nowrap; transition:all .15s; }
    .mpm-cta-state svg { width:15px; height:15px; }
    .mpm-cta--install { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2))); box-shadow:0 6px 16px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-row:hover .mpm-cta--install { filter:brightness(1.08); }
    .mpm-cta--installed { color:var(--mpm-success); background:color-mix(in srgb, var(--mpm-success) 14%, transparent); border:1px solid color-mix(in srgb, var(--mpm-success) 36%, transparent); }
    .mpm-row__chev { color:var(--mpm-muted); transition:transform .16s, color .16s; display:flex; }
    .mpm-row__chev svg { width:18px; height:18px; }
    .mpm-row:hover .mpm-row__chev { transform:translateX(3px); color:var(--mpm-accent); }

    .mpm-skel-row { display:flex; align-items:center; gap:16px; padding:14px 16px; border-radius:16px; background:var(--mpm-surface); border:1px solid var(--mpm-border); }
    .mpm-shimmer { background:linear-gradient(90deg, var(--mpm-surface-2) 25%, var(--mpm-surface-3) 50%, var(--mpm-surface-2) 75%); background-size:900px 100%; animation:mpm-shimmer 1.5s infinite linear; border-radius:7px; }

    .mpm-empty { display:flex; flex-direction:column; align-items:center; text-align:center; gap:12px; padding:64px 20px; border-radius:20px; background:var(--mpm-surface); border:1px dashed var(--mpm-border-2); color:var(--mpm-muted); }
    .mpm-empty__orb { width:72px; height:72px; border-radius:20px; display:flex; align-items:center; justify-content:center; color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 30%, transparent); }
    .mpm-alert { display:flex; gap:13px; padding:16px 18px; border-radius:16px; align-items:flex-start;
        background:color-mix(in srgb, var(--mpm-danger) 10%, var(--mpm-surface)); border:1px solid color-mix(in srgb, var(--mpm-danger) 34%, transparent); color:var(--mpm-danger); font-size:.85rem; }
    .mpm-alert svg { width:20px; height:20px; flex-shrink:0; margin-top:1px; }

    .mpm-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 20px; border-radius:12px; border:1px solid transparent; font-size:.86rem; font-weight:700; cursor:pointer; transition:all .15s; text-decoration:none; }
    .mpm-btn svg { width:16px; height:16px; }
    .mpm-btn:disabled { opacity:.5; cursor:not-allowed; }
    .mpm-btn--primary { color:#fff; background:linear-gradient(135deg, var(--mpm-accent), color-mix(in srgb, var(--mpm-accent) 55%, var(--mpm-accent-2))); box-shadow:0 8px 22px color-mix(in srgb, var(--mpm-accent) 32%, transparent); }
    .mpm-btn--primary:hover:not(:disabled) { filter:brightness(1.08); transform:translateY(-1px); }
    .mpm-btn--ghost { background:var(--mpm-surface-2); border-color:var(--mpm-border-2); color:var(--mpm-text); }
    .mpm-btn--ghost:hover { background:var(--mpm-surface-3); }

    /* ── Install drawer ── */
    .mpm-scrim { position:fixed; inset:0; z-index:9990; background:rgba(8,6,22,.92); backdrop-filter:blur(8px); }
    .mpm-ilbl { display:inline-flex; align-items:center; gap:8px; }
    .mpm-drawer { position:fixed; top:0; right:0; bottom:0; z-index:9991; width:100%; max-width:480px; display:flex; flex-direction:column;
        background:var(--mpm-surface); border-left:1px solid var(--mpm-border-2); box-shadow:var(--mpm-shadow-lg);
        transform:translateX(100%); visibility:hidden; transition:transform .32s cubic-bezier(.16,1,.3,1), visibility 0s linear .32s; }
    .mpm-drawer.is-open { transform:translateX(0); visibility:visible; transition:transform .32s cubic-bezier(.16,1,.3,1); }
    .mpm-drawer__starting { position:absolute; inset:0; z-index:5; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; text-align:center;
        background:color-mix(in srgb, var(--mpm-surface) 88%, transparent); backdrop-filter:blur(4px); animation:mpm-rise .22s ease both; }
    .mpm-drawer__starting .mpm-spin { width:40px; height:40px; color:var(--mpm-accent); }
    .mpm-drawer__starting p { margin:0; font-weight:800; font-size:1rem; }
    .mpm-drawer__starting small { color:var(--mpm-text-2); }
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
    .mpm-dbadge { position:relative; display:inline-flex; align-items:center; gap:7px; margin-top:14px; padding:6px 14px; border-radius:999px; font-size:.72rem; font-weight:800; letter-spacing:.02em;
        color:var(--mpm-accent); background:var(--mpm-accent-lo); border:1px solid color-mix(in srgb, var(--mpm-accent) 34%, transparent); }
    .mpm-dbadge .dot { width:8px; height:8px; border-radius:50%; background:var(--mpm-accent); }
    .mpm-drawer__body { flex:1; overflow-y:auto; padding:22px; display:flex; flex-direction:column; gap:20px; }
    .mpm-dest { display:flex; align-items:flex-start; gap:11px; padding:14px; border-radius:13px; background:var(--mpm-surface-2); border:1px solid var(--mpm-border); font-size:.8rem; color:var(--mpm-text-2); line-height:1.5; }
    .mpm-dest svg { width:18px; height:18px; flex-shrink:0; margin-top:1px; color:var(--mpm-accent); }
    .mpm-dest code { font-family:ui-monospace,monospace; font-size:.78rem; padding:1px 6px; border-radius:5px; background:var(--mpm-surface-3); border:1px solid var(--mpm-border); color:var(--mpm-text); }
    .mpm-drawer__foot { flex-shrink:0; padding:18px 22px; border-top:1px solid var(--mpm-border); background:var(--mpm-surface); display:flex; gap:11px; }
    .mpm-drawer__foot .mpm-btn--primary { flex:1; }

    @media (max-width:860px){
        .mpm-row { flex-wrap:wrap; }
        .mpm-row__cta { width:100%; justify-content:space-between; margin-top:4px; }
        .mpm-cta-state { flex:1; justify-content:center; }
    }
    @media (max-width:560px){
        .mpm-hero__title { font-size:1.25rem; }
        .mpm-drawer { max-width:100%; }
    }

    .mpm-loader-picks { display:flex; flex-wrap:wrap; gap:7px; margin-top:2px; }
    .mpm-filterchip { display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:9px; cursor:pointer;
        font-size:.75rem; font-weight:750; color:var(--mpm-text-2); background:var(--mpm-surface); border:1px solid var(--mpm-border); transition:all .14s; }
    .mpm-filterchip:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); }
    .mpm-filterchip.is-active { color:#fff; border-color:var(--mpm-accent); background:var(--mpm-accent); box-shadow:0 4px 12px var(--mpm-accent-lo); }
    .mpm-filterchip .dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
    .mpm-filter-note { margin:7px 0 0; font-size:.7rem; color:var(--mpm-muted); line-height:1.4; }
    .mpm-active-filters { display:flex; flex-wrap:wrap; align-items:center; gap:7px; margin-top:12px; }
    .mpm-active-filter { display:inline-flex; align-items:center; gap:7px; padding:6px 9px; border-radius:999px; cursor:pointer;
        font-size:.72rem; font-weight:750; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); }
    .mpm-active-filter:hover { color:var(--mpm-text); border-color:var(--mpm-accent); }
    .mpm-active-filter__x { font-size:.9rem; line-height:1; color:var(--mpm-muted); }
    .mpm-provider-warning { display:flex; align-items:flex-start; gap:10px; padding:11px 13px; border-radius:12px;
        color:#fcd34d; background:color-mix(in srgb,var(--mpm-warn) 10%,var(--mpm-surface)); border:1px solid color-mix(in srgb,var(--mpm-warn) 35%,var(--mpm-border)); font-size:.76rem; }
    .mpm-provider-warning svg { width:16px; height:16px; flex-shrink:0; margin-top:1px; }
    .mpm-provider-warning button { margin-left:auto; flex-shrink:0; border:0; background:none; color:inherit; cursor:pointer; font-weight:800; text-decoration:underline; text-underline-offset:3px; }
    .mpm-row__quick { flex:1 1 100%; display:flex; gap:7px; margin-top:4px; }
    .mpm-quickbtn { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:31px; padding:6px 10px; border-radius:9px;
        cursor:pointer; font-size:.7rem; font-weight:750; color:var(--mpm-text-2); background:var(--mpm-surface-2); border:1px solid var(--mpm-border); text-decoration:none; transition:all .14s; }
    .mpm-quickbtn:hover { color:var(--mpm-text); border-color:var(--mpm-border-2); background:var(--mpm-surface-3); }
    .mpm-quickbtn svg { width:13px; height:13px; }
    .mpm-quickbtn--grow { flex:1; }
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

</style>

<div
    class="mpm"
    x-data="{ filtersOpen: false, drawerOpen: @entangle('showModal'), infoOpen: @entangle('showInfoModal'), installing: false }"
    x-init="
        document.documentElement.style.setProperty('background', '#050506', 'important');
        document.documentElement.style.setProperty('background-color', '#050506', 'important');
        document.body.style.setProperty('background', '#050506', 'important');
        document.body.style.setProperty('background-color', '#050506', 'important');
    "
>
@php
    $isPlugins = $mode === 'plugins';
    $noun      = $isPlugins ? 'plugin' : 'mod';
    $nounPl    = $isPlugins ? 'plugins' : 'mods';
    $Noun      = ucfirst($noun);
    $NounPl    = ucfirst($nounPl);
    $sourceMeta = [
        'curseforge' => ['label' => 'CurseForge', 'pill' => 'cf', 'color' => 'var(--mpm-cf)'],
        'modrinth'   => ['label' => 'Modrinth',   'pill' => 'mr', 'color' => 'var(--mpm-mr)'],
        'spigot'     => ['label' => 'SpigotMC',   'pill' => 'sp', 'color' => 'var(--mpm-sp)'],
        'hangar'     => ['label' => 'Hangar',     'pill' => 'hg', 'color' => 'var(--mpm-hg)'],
    ];
    $sources = $this->providersForMode();
    $sourceNames = array_map(fn ($s) => $sourceMeta[$s]['label'], $sources);
    $sourceList = count($sourceNames) > 1
        ? implode(', ', array_slice($sourceNames, 0, -1)) . ' & ' . end($sourceNames)
        : implode('', $sourceNames);
@endphp
<div class="mpm-shell">

    {{-- ══════════ Hero ══════════ --}}
    <div class="mpm-hero mpm-rise">
        <div class="mpm-hero__grid"></div>

        <div class="mpm-hero__topline">
            <p class="mpm-hero__eyebrow">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                Individual {{ $nounPl }}
            </p>
            <div class="mpm-hero__controls">
                {{-- MC-version selector — seeded from this server's version variable, still overridable.
                     Bound to the same filterVersion the filter panel uses; changing it reloads. --}}
                <div class="mpm-verselect" title="Minecraft version — auto-detected from this server. Only compatible {{ $nounPl }} are shown.">
                    <svg class="lead" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <select wire:model="filterVersion" wire:change="applyFilters" aria-label="Minecraft version">
                        <option value="">All versions</option>
                        @foreach($this->getFilterVersionOptions() as $v)
                            <option value="{{ $v }}">MC {{ $v }}</option>
                        @endforeach
                    </select>
                    <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>

                {{-- Mode override: detection isn't always right, so let the user flip it. --}}
                <div class="mpm-seg">
                    <button type="button" wire:click="setMode('mods')" class="mpm-seg__btn {{ !$isPlugins ? 'is-active' : '' }}">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        Mods
                    </button>
                    <button type="button" wire:click="setMode('plugins')" class="mpm-seg__btn {{ $isPlugins ? 'is-active' : '' }}">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                        Plugins
                    </button>
                </div>
            </div>
        </div>

        <h1 class="mpm-hero__title">Add {{ $nounPl }} to your server</h1>
        <p class="mpm-hero__sub">
            This server looks like a <b>{{ $isPlugins ? 'plugin platform' : 'modded' }}</b> server, so we install into <code>{{ $this->getTargetDirLabel() }}</code>.
            Search {{ $sourceList }}, pick a version, and we drop the jar straight in. Wrong guess? Switch above.
        </p>

        <div class="mpm-omni">
            <div class="mpm-omni__field">
                <span class="mpm-omni__ico">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                </span>
                <input type="text" wire:model.lazy="search" wire:keydown.enter="searchItems"
                       placeholder="Search {{ $nounPl }} — {{ $isPlugins ? 'try “EssentialsX”, “LuckPerms”, “Vault”…' : 'try “JEI”, “Sodium”, “Create”…' }}">
                <button type="button" class="mpm-omni__go" wire:click="searchItems">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    Search
                </button>
            </div>
        </div>

        <div class="mpm-pills">
            <button type="button" wire:click="setProvider('all')" class="mpm-pill mpm-pill--all {{ $provider === 'all' ? 'is-active' : '' }}"><span class="dot"></span> All sources</button>
            @foreach($sources as $src)
                <button type="button" wire:click="setProvider('{{ $src }}')" class="mpm-pill mpm-pill--{{ $sourceMeta[$src]['pill'] }} {{ $provider === $src ? 'is-active' : '' }}"><span class="dot"></span> {{ $sourceMeta[$src]['label'] }}</button>
            @endforeach

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
                            wire:click="removeFilterChip('{{ $chip['type'] }}', '{{ $chip['value'] }}')"
                            title="Remove filter">
                        <span>{{ $chip['label'] }}</span><span class="mpm-active-filter__x">×</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="mpm-filterpanel" x-show="filtersOpen" x-cloak x-transition>
            <div class="mpm-filtergrid">
                <div>
                    <label class="mpm-label">{{ $isPlugins ? 'Platforms' : 'Loaders' }} <span style="font-weight:500;color:var(--mpm-muted);">(pick more than one)</span></label>
                    <div class="mpm-loader-picks">
                        @foreach($this->getFilterLoaderOptions() as $slug => $label)
                            <button type="button" wire:click="toggleLoader('{{ $slug }}')"
                                    class="mpm-filterchip {{ in_array($slug, $filterLoaders, true) ? 'is-active' : '' }}">
                                <span class="dot"></span>{{ $label }}
                            </button>
                        @endforeach
                    </div>
                    @if($isPlugins && $provider !== 'modrinth')
                        <p class="mpm-filter-note">Only Modrinth filters by exact platform. CurseForge ignores the platform and filters by Minecraft version only. SpigotMC is shown when Bukkit, Spigot, Paper or Purpur is picked, and is not filtered by Minecraft version (its tested versions are shown on each card). Hangar is shown for Paper, Purpur or Folia (Folia alone limits it to Folia-ready projects).</p>
                    @endif
                </div>
                @if(!$isPlugins)
                    <div>
                        <label class="mpm-label">Categories <span style="font-weight:500;color:var(--mpm-muted);">(pick any)</span></label>
                        <div class="mpm-loader-picks">
                            @foreach($this->getCategoryFilterOptions() as $slug => $label)
                                <button type="button" wire:click="toggleCategory('{{ $slug }}')"
                                        class="mpm-filterchip {{ in_array($slug, $filterCategories, true) ? 'is-active' : '' }}">
                                    <span class="dot"></span>{{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <p class="mpm-filter-note">These use the closest matching category on CurseForge and Modrinth, so the same pills work in All Sources.</p>
                    </div>
                @endif
            </div>
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
    </div>

    {{-- ══════════ Installed jars manager ══════════ --}}
    <div class="mpm-installed mpm-rise" wire:key="installed-{{ $mode }}"
         x-data="{
            q: '',
            visible(name) { const t = this.q.trim().toLowerCase(); return t === '' || name.includes(t); },
            noMatches() {
                const t = this.q.trim().toLowerCase();
                if (t === '') return false;
                const rows = this.$refs.jarlist ? this.$refs.jarlist.querySelectorAll('[data-name]') : [];
                for (const r of rows) { if (r.dataset.name.includes(t)) return false; }
                return rows.length > 0;
            }
         }">
        <div class="mpm-installed__head">
            <h3 class="mpm-installed__title">Installed {{ $nounPl }}</h3>
            <span class="mpm-installed__count">{{ count($installedFiles) }} {{ \Illuminate\Support\Str::plural('file', count($installedFiles)) }}</span>
            <span class="mpm-installed__dir">{{ $this->getTargetDirLabel() }}</span>
            @if(count($installedFiles) > 0)
                <div class="mpm-minisearch">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                    <input type="text" x-model="q" spellcheck="false" placeholder="Filter installed {{ $nounPl }}…">
                </div>
            @endif
            <button type="button" class="mpm-refresh" wire:click="refreshInstalled" wire:loading.attr="disabled" wire:target="refreshInstalled">
                <svg wire:loading.class="mpm-spin" wire:target="refreshInstalled" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Refresh
            </button>
        </div>

        <div class="mpm-installed__list" x-ref="jarlist">
            @forelse($installedFiles as $file)
                <div class="mpm-jar {{ $file['enabled'] ? '' : 'is-disabled' }}" wire:key="jar-{{ md5($file['name']) }}"
                     data-name="{{ strtolower($file['name']) }}" x-show="visible($el.dataset.name)">
                    <span class="mpm-jar__ico">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </span>
                    <div class="mpm-jar__body">
                        <div class="mpm-jar__name">{{ $file['name'] }}</div>
                        <div class="mpm-jar__size">
                            <span>{{ $file['sizeLabel'] }}</span>
                            @if(!$file['enabled'])
                                <span class="mpm-jar__badge mpm-jar__badge--off">Disabled</span>
                            @endif
                        </div>
                    </div>
                    @if($canManage)
                        <div class="mpm-jar__actions">
                            <button type="button" class="mpm-toggle {{ $file['enabled'] ? 'is-on' : '' }}"
                                    wire:click="toggleInstalledFile(@js($file['name']))"
                                    aria-pressed="{{ $file['enabled'] ? 'true' : 'false' }}"
                                    title="{{ $file['enabled'] ? 'Disable' : 'Enable' }} {{ $file['name'] }}">
                                <span class="mpm-toggle__track"><span class="mpm-toggle__knob"></span></span>
                                <span>{{ $file['enabled'] ? 'On' : 'Off' }}</span>
                            </button>
                            <button type="button" class="mpm-jar__rm"
                                    wire:click="removeInstalledFile(@js($file['name']))"
                                    wire:confirm="Remove {{ $file['name'] }} from {{ $this->getTargetDirLabel() }}? Restart the server afterwards.">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Remove
                            </button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="mpm-installed__empty">
                    Nothing in {{ $this->getTargetDirLabel() }} yet — install {{ $nounPl }} below and they'll show up here.
                </div>
            @endforelse
            @if(count($installedFiles) > 0)
                <div class="mpm-installed__empty" x-show="noMatches()" x-cloak>
                    No installed {{ $nounPl }} match “<span x-text="q"></span>”.
                </div>
            @endif
        </div>
    </div>

    {{-- ══════════ Error ══════════ --}}
    @if($errorMsg)
        <div class="mpm-alert mpm-rise">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div><b>Failed to load {{ $nounPl }}</b><div style="margin-top:4px;opacity:.85;">{{ $errorMsg }}</div></div>
        </div>
    @endif

    @if(!empty($providerErrors) && $provider === 'all')
        <div class="mpm-provider-warning mpm-rise">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <b>Some sources could not be reached.</b> Results from the other sources are still shown.
                <div style="margin-top:3px;opacity:.9;">{{ implode(' ', array_values($providerErrors)) }}</div>
            </div>
            <button type="button" wire:click="retryProviderSearches">Retry</button>
        </div>
    @endif

    {{-- ══════════ Results ══════════ --}}
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

    @elseif(!empty($items))
        <div class="mpm-feed-head">
            <h3>{{ $search !== '' ? 'Results' : ('Popular ' . $nounPl) }}</h3>
            <span class="count">{{ count($items) }} {{ \Illuminate\Support\Str::plural($noun, count($items)) }}</span>
        </div>
        <div class="mpm-feed">
            @foreach($items as $i => $item)
                @php
                    $dl = (int) ($item['downloadCount'] ?? 0);
                    $dlFmt = $dl >= 1000000 ? round($dl/1000000,1).'M' : ($dl >= 1000 ? round($dl/1000).'K' : $dl);
                    $pv = $item['provider'] ?? '';
                    $pvLabel = $sourceMeta[$pv]['label'] ?? ucfirst($pv);
                    $pvColor = $sourceMeta[$pv]['color'] ?? '#8b8aa3';
                    $siteUrl = $this->itemWebsiteUrl($item);
                    $galleryCount = count($item['gallery'] ?? []);
                @endphp
                <div class="mpm-row mpm-rise" style="--row-c:{{ $pvColor }}; animation-delay: {{ min($i * 40, 320) }}ms"
                     role="button" tabindex="0"
                     wire:click="openModal('{{ $item['id'] }}', '{{ $pv }}')"
                     wire:key="row-{{ $pv }}-{{ $item['id'] }}"
                     @keydown.enter="$wire.openModal('{{ $item['id'] }}', '{{ $pv }}')">
                    @if($item['iconUrl'])
                        <img src="{{ $item['iconUrl'] }}" alt="" class="mpm-row__art">
                    @else
                        <div class="mpm-row__art mpm-row__art--ph">
                            <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                    @endif

                    <div class="mpm-row__body">
                        <div class="mpm-row__title">
                            <h3 class="mpm-row__name">{{ $item['name'] }}</h3>
                            <span class="mpm-chip mpm-chip--src" style="--c:{{ $pvColor }};">{{ $pvLabel }}</span>
                            @foreach(array_slice($item['loaders'] ?? [], 0, 3) as $loader)
                                @php $lc = match(strtolower($loader)) {
                                    'forge'=>'#d97706','neoforge'=>'#f97316','fabric'=>'#c9a16b','quilt'=>'#a855f7','liteloader'=>'#38bdf8', default=>'#8b8aa3',
                                }; @endphp
                                <span class="mpm-chip" style="--c:{{ $lc }};">{{ $loader }}</span>
                            @endforeach
                            @if(($item['installable'] ?? true) === false)
                                <span class="mpm-chip" style="--c:var(--mpm-warn);" title="SpigotMC only offers this file through a browser — open its page to download it.">Browser download</span>
                            @endif
                        </div>
                        @if(!empty($item['summary']))
                            <p class="mpm-row__desc">{{ $item['summary'] }}</p>
                        @endif
                        <div class="mpm-row__meta">
                            @if(!empty($item['author']))<span class="author">{{ $item['author'] }}</span>@endif
                            <span title="Downloads">
                                <svg fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm-1 11H7v-6h2v6zm4 0h-2v-6h2v6z"/></svg>
                                {{ $dlFmt }}
                            </span>
                            @if(!empty($item['gameVersions']))<span>{{ $item['gameVersions'] }}</span>@endif
                        </div>
                    </div>

                    <div class="mpm-row__quick">
                        <button type="button" class="mpm-quickbtn mpm-quickbtn--grow"
                                wire:click.stop="openItemInfo('{{ $item['id'] }}', '{{ $pv }}', 'description')">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m-7 7l-4-4V5a2 2 0 012-2h16a2 2 0 012 2v12a2 2 0 01-2 2H8l-2 2z"/></svg>
                            Description
                        </button>
                        <button type="button" class="mpm-quickbtn mpm-quickbtn--grow"
                                wire:click.stop="openItemInfo('{{ $item['id'] }}', '{{ $pv }}', 'gallery')">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Gallery{{ $galleryCount ? ' · '.$galleryCount : '' }}
                        </button>
                        @if($siteUrl)
                            <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="mpm-quickbtn" @click.stop title="Open {{ $noun }} page">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 3h7m0 0v7m0-7L10 14M5 5h5M5 5a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2v-5"/></svg>
                                Open
                            </a>
                        @endif
                    </div>

                    <div class="mpm-row__cta">
                        <span class="mpm-cta-state mpm-cta--install">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Install
                        </span>
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
            <p style="font-weight:800;color:var(--mpm-text);margin:0;font-size:1rem;">No {{ $nounPl }} found</p>
            <p style="font-size:.86rem;margin:0;">Try a different search term or switch source above.</p>
        </div>
    @endif

    {{-- ══════════ Install drawer (teleported) ══════════ --}}
    <template x-teleport="body">
    <div class="mpm">

        <div class="mpm-scrim" x-show="drawerOpen" x-cloak x-transition.opacity
             @keydown.escape.window="$wire.closeModal()" @click="$wire.closeModal()"></div>

        <div class="mpm-drawer" :class="{ 'is-open': drawerOpen }" x-cloak>

            <div class="mpm-drawer__starting" x-show="installing" x-cloak x-transition.opacity>
                <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <div>
                    <p>Installing…</p>
                    <small>Wings is downloading the file to {{ $this->getTargetDirLabel() }}</small>
                </div>
            </div>

            @if($selectedItem)
                {{-- Drawer hero --}}
                <div class="mpm-dhero">
                    @if($selectedItem['iconUrl'])
                        <div class="mpm-dhero__bg" style="background-image:url('{{ $selectedItem['iconUrl'] }}')"></div>
                    @endif
                    <div class="mpm-dhero__scrim"></div>
                    <div class="mpm-dhero__row">
                        @if($selectedItem['iconUrl'])
                            <img src="{{ $selectedItem['iconUrl'] }}" alt="" class="mpm-dhero__art">
                        @endif
                        <div style="min-width:0;">
                            <h3 class="mpm-dhero__name">{{ $selectedItem['name'] }}</h3>
                            @if(!empty($selectedItem['author']))<p class="mpm-dhero__author">by {{ $selectedItem['author'] }}</p>@endif
                        </div>
                        <button type="button" class="mpm-dhero__close" @click="$wire.closeModal()">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <span class="mpm-dbadge"><span class="dot"></span>Install {{ $noun }}</span>
                </div>

                {{-- Drawer body --}}
                <div class="mpm-drawer__body"
                     wire:key="drawer-{{ $selectedItem['provider'] ?? '' }}-{{ $selectedItem['id'] ?? '' }}"
                     x-init="$wire.loadVersions()">

                    <div>
                        <label class="mpm-label">Version</label>
                        @if($versionsLoading)
                            <div class="mpm-loadbox">
                                <svg class="mpm-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Loading versions…
                            </div>
                        @elseif(empty($versions))
                            <p style="color:var(--mpm-danger);font-size:.85rem;margin:0;">{{ $versionsNote !== '' ? $versionsNote : 'No compatible versions available.' }}</p>
                            @if($versionsNote !== '' && ($noteUrl = $this->itemWebsiteUrl($selectedItem)))
                                <a href="{{ $noteUrl }}" target="_blank" rel="noopener noreferrer" class="mpm-btn mpm-btn--ghost" style="margin-top:12px;">Open {{ $noun }} page</a>
                            @endif
                        @else
                            @php $mcMap = $this->versionMcMap(); @endphp
                            <div class="mpm-select-wrap">
                                <select class="mpm-select" x-model="$wire.selectedVersion">
                                    @foreach($versions as $ver)
                                        @php
                                            $label = $ver['displayName'] ?? $ver['versionNumber'] ?? $ver['name'] ?? $ver['fileName'] ?? $ver['id'];
                                            $date = isset($ver['datePublished']) ? \Carbon\Carbon::parse($ver['datePublished'])->format('M j, Y')
                                                  : (isset($ver['fileDate']) ? \Carbon\Carbon::parse($ver['fileDate'])->format('M j, Y') : '');
                                            $mcList = $this->supportedMcVersions($ver);
                                            $mcLabel = '';
                                            if (!empty($mcList)) {
                                                $shown   = array_slice($mcList, 0, 5);
                                                $mcLabel = ' — MC ' . implode(', ', $shown) . (count($mcList) > 5 ? ' +' . (count($mcList) - 5) : '');
                                            }
                                        @endphp
                                        <option value="{{ $ver['id'] }}">{{ $label }}{{ $date ? ' • '.$date : '' }}{{ $mcLabel }}</option>
                                    @endforeach
                                </select>
                                <svg class="caret" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </div>

                            {{-- Reactive compatibility row: which MC versions the picked file supports,
                                 highlighting the one this server runs so there's no guessing. --}}
                            <div class="mpm-verhint"
                                 x-data="{ map: @js($mcMap), server: @js($filterVersion) }"
                                 x-show="$wire.selectedVersion && (map[$wire.selectedVersion] || []).length"
                                 x-cloak>
                                <span class="mpm-verhint__lbl">Supports MC:</span>
                                <span class="mpm-verhint__chips">
                                    <template x-for="v in (map[$wire.selectedVersion] || [])" :key="v">
                                        <span class="mpm-verchip" :class="{ 'is-match': server !== '' && v === server }" x-text="v"></span>
                                    </template>
                                </span>
                                <span class="mpm-verhint__warn"
                                      x-show="server !== '' && !(map[$wire.selectedVersion] || []).includes(server)">
                                    Not listed for your {{ $filterVersion ?: 'server' }} — install only if you know it's compatible.
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="mpm-dest">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        <span>The selected jar is downloaded straight into <code>{{ $this->getTargetDirLabel() }}</code>. Existing files aren't touched — restart the server afterwards to load it.</span>
                    </div>
                </div>

                {{-- Drawer footer --}}
                <div class="mpm-drawer__foot">
                    <button type="button"
                            @click="installing = true; $wire.installItem().finally(() => installing = false)"
                            class="mpm-btn mpm-btn--primary"
                            :disabled="installing"
                            @if($versionsLoading || empty($versions)) disabled @endif>
                        <span class="mpm-ilbl" x-show="!installing">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Install {{ $noun }}
                        </span>
                        <span class="mpm-ilbl" x-show="installing" x-cloak>
                            <svg class="mpm-spin" style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Installing…
                        </span>
                    </button>
                    <button type="button" class="mpm-btn mpm-btn--ghost" @click="$wire.closeModal()">Cancel</button>
                </div>
            @else
                <div style="flex:1; display:flex; align-items:center; justify-content:center; padding:40px;">
                    <svg class="mpm-spin" style="width:28px;height:28px;color:var(--mpm-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </div>
            @endif
        </div>

        <div class="mpm-info-scrim" x-show="infoOpen" x-cloak x-transition.opacity
             @click.self="$wire.closeItemInfo()"></div>

        <div class="mpm-info" x-show="infoOpen" x-cloak x-transition>
            @if($infoItem)
                @php
                    $infoSiteUrl = $this->itemWebsiteUrl($infoItem);
                    $infoDescription = trim((string) ($infoItem['description'] ?? $infoItem['summary'] ?? ''));
                    $infoGallery = array_values(array_filter(
                        $infoItem['gallery'] ?? [],
                        fn ($image) => is_array($image) && $this->externalUrl($image['url'] ?? null)
                    ));
                @endphp
                <div class="mpm-info__head">
                    @if(!empty($infoItem['iconUrl']))
                        <img src="{{ $infoItem['iconUrl'] }}" alt="" class="mpm-info__art">
                    @endif
                    <div class="mpm-info__title">
                        <p class="mpm-info__eyebrow">{{ $infoMode === 'gallery' ? 'Gallery' : 'Description' }}</p>
                        <h3 class="mpm-info__name">{{ $infoItem['name'] ?? $Noun }}</h3>
                    </div>
                    <button type="button" class="mpm-dhero__close" @click="$wire.closeItemInfo()" aria-label="Close">
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
                                                onclick="const d=document.getElementById('mpm-item-gallery-preview'),i=document.getElementById('mpm-item-gallery-preview-image'),c=document.getElementById('mpm-item-gallery-preview-caption'); if(!d||!i)return; i.src=this.dataset.url; if(c){c.textContent=this.dataset.caption||''; c.hidden=!this.dataset.caption;} if(!d.open)d.showModal();">
                                            <img src="{{ $thumbUrl }}" alt="{{ $caption }}" loading="lazy">
                                            @if($caption !== '')
                                                <div class="mpm-gallery__cap">{{ $caption }}</div>
                                            @endif
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <div class="mpm-gallery__empty">No gallery images are available for this {{ $noun }}.</div>
                        @endif
                    @else
                        @if($infoDescription !== '')
                            <p class="mpm-description">{{ $infoDescription }}</p>
                        @else
                            <div class="mpm-gallery__empty">No full description is available for this {{ $noun }}.</div>
                        @endif
                    @endif
                </div>

                <div class="mpm-info__foot">
                    @if($infoSiteUrl)
                        <a href="{{ $infoSiteUrl }}" target="_blank" rel="noopener noreferrer" class="mpm-btn mpm-btn--ghost">Open {{ $noun }} page</a>
                    @endif
                    <button type="button" class="mpm-btn mpm-btn--primary" @click="$wire.closeItemInfo()">Close</button>
                </div>
            @endif
        </div>

        <dialog id="mpm-item-gallery-preview" class="mpm-gallery-dialog" onclick="if (event.target === this) this.close()">
            <div class="mpm-gallery-dialog__panel">
                <button type="button" class="mpm-gallery-dialog__close" onclick="this.closest('dialog').close()" aria-label="Close image">×</button>
                <div class="mpm-gallery-dialog__stage">
                    <img id="mpm-item-gallery-preview-image" class="mpm-gallery-dialog__img" src="" alt="">
                </div>
                <div id="mpm-item-gallery-preview-caption" class="mpm-gallery-dialog__cap" hidden></div>
            </div>
        </dialog>

    </div>
    </template>

</div>
</div>

</x-filament-panels::page>
