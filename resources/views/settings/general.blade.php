@extends('layouts.app')
@section('title', 'General Settings')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-gear me-2"></i>General Settings</h4>
</div>

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
@csrf
<div class="row g-4">

    {{-- Left column: controls --}}
    <div class="col-lg-7">

        {{-- App Name --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Application Name</h6>
                <small class="text-muted">Shown in the browser tab and sidebar when no logo is set.</small>
            </div>
            <div class="card-body">
                <input type="text" name="app_name" id="inputAppName"
                       class="form-control @error('app_name') is-invalid @enderror"
                       value="{{ old('app_name', $appName) }}" placeholder="e.g. DFCP COMS" maxlength="80">
                @error('app_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Theme Color --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Theme Color</h6>
                <small class="text-muted">Controls the sidebar, buttons, links, and accent colors across the app.</small>
            </div>
            <div class="card-body">
                {{-- Presets --}}
                <label class="form-label small fw-semibold mb-2">Presets</label>
                <div class="d-flex flex-wrap gap-2 mb-3" id="colorPresets">
                    @php
                    $presets = [
                        '#1F3C88' => 'Navy (Default)',
                        '#2563EB' => 'Blue',
                        '#7C3AED' => 'Purple',
                        '#059669' => 'Green',
                        '#0891B2' => 'Teal',
                        '#DC2626' => 'Red',
                        '#EA580C' => 'Orange',
                        '#D97706' => 'Amber',
                        '#0F172A' => 'Dark',
                        '#475569' => 'Slate',
                    ];
                    @endphp
                    @foreach($presets as $hex => $label)
                    <button type="button" class="color-preset border-0 rounded-2 p-0"
                            data-color="{{ $hex }}" title="{{ $label }}"
                            style="width:36px;height:36px;background:{{ $hex }};cursor:pointer;transition:transform .15s,box-shadow .15s;{{ $themeColor === $hex ? 'box-shadow:0 0 0 3px #fff,0 0 0 5px '.$hex.';transform:scale(1.15)' : '' }}">
                    </button>
                    @endforeach
                </div>

                {{-- Custom picker --}}
                <label class="form-label small fw-semibold">Custom Color</label>
                <div class="d-flex align-items-center gap-3">
                    <input type="color" id="colorPicker" name="theme_color"
                           value="{{ old('theme_color', $themeColor) }}"
                           class="form-control form-control-color @error('theme_color') is-invalid @enderror"
                           style="width:56px;height:40px;padding:2px;cursor:pointer">
                    <input type="text" id="colorHex"
                           value="{{ old('theme_color', $themeColor) }}"
                           class="form-control form-control-sm font-monospace"
                           style="width:100px" maxlength="7" placeholder="#1F3C88">
                    <span class="text-muted small">hex code</span>
                </div>
                @error('theme_color')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        {{-- Logo --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Logo</h6>
                <small class="text-muted">PNG / JPG / SVG / WebP · Max 512 KB · When set, only the logo is shown (no text).</small>
            </div>
            <div class="card-body">
                @if($appLogo)
                <div class="mb-3 p-3 border rounded d-flex align-items-center gap-3">
                    <img src="{{ asset($appLogo) }}" alt="Current Logo" style="max-height:52px;max-width:160px;object-fit:contain">
                    <div>
                        <div class="small text-muted mb-1">Current logo</div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="removeLogo">
                            <label class="form-check-label small text-danger" for="removeLogo">Remove logo</label>
                        </div>
                    </div>
                </div>
                @endif
                <label class="form-label small fw-semibold">{{ $appLogo ? 'Replace logo' : 'Upload logo' }}</label>
                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp"
                       class="form-control @error('logo') is-invalid @enderror" id="logoInput">
                @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div id="logoPreviewWrap" class="mt-2 d-none">
                    <img id="logoPreview" src="" alt="Preview"
                         style="max-height:52px;max-width:180px;object-fit:contain;border:1px solid #dee2e6;padding:6px;border-radius:6px">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-save me-1"></i>Save Settings
        </button>
    </div>

    {{-- Right column: live preview --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm" style="position:sticky;top:72px">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Live Preview</h6>
                <small class="text-muted">Changes apply to the whole page instantly.</small>
            </div>
            <div class="card-body">
                {{-- Mini sidebar --}}
                <div id="previewSidebar" class="rounded-3 p-3 mb-3" style="background:linear-gradient(180deg,{{ $themeColor }},{{ $themeColorDark }});width:180px">
                    <div id="previewBrand" class="d-flex align-items-center gap-2 pb-2 mb-2" style="border-bottom:1px solid rgba(255,255,255,.15)">
                        @if($appLogo)
                        <img id="previewLogoImg" src="{{ asset($appLogo) }}" alt="Logo" style="max-height:32px;max-width:120px;object-fit:contain">
                        @else
                        <i class="bi bi-shop text-white" style="font-size:1.2rem"></i>
                        <div class="text-white fw-bold text-truncate" id="previewName" style="font-size:.85rem">{{ $appName }}</div>
                        @endif
                    </div>
                    <div style="color:rgba(255,255,255,.6);font-size:.72rem;padding:.2rem .4rem;border-radius:4px;background:rgba(255,255,255,.1)" class="mb-1">
                        <i class="bi bi-speedometer2 me-1"></i>Dashboard
                    </div>
                    <div style="color:rgba(255,255,255,.45);font-size:.72rem;padding:.2rem .4rem">
                        <i class="bi bi-people me-1"></i>Clients
                    </div>
                </div>

                {{-- Button & badge samples --}}
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary btn-sm preview-btn">Primary Button</button>
                    <button type="button" class="btn btn-outline-primary btn-sm preview-btn-outline">Outline</button>
                    <span class="badge bg-primary preview-badge">Badge</span>
                </div>
                <div style="font-size:.85rem">
                    <a href="#" class="text-primary preview-link" onclick="return false">Link color</a>
                    &nbsp;·&nbsp;
                    <span class="text-primary preview-text-primary fw-semibold">Primary text</span>
                </div>
            </div>
        </div>
    </div>

</div>
</form>
@endsection

@push('styles')
<style>
.color-preset:hover { transform: scale(1.15); box-shadow: 0 0 0 3px #fff, 0 0 0 5px currentColor; }
.color-preset.active { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--color); transform: scale(1.15); }
input[type="color"] { border-radius: 6px; }
</style>
@endpush

@push('scripts')
<script>
// ── Helpers ──────────────────────────────────────────────────────────────────
function darken(hex, f = .18) {
    const r = parseInt(hex.slice(1,3), 16),
          g = parseInt(hex.slice(3,5), 16),
          b = parseInt(hex.slice(5,7), 16);
    const d = v => Math.max(0, Math.round(v * (1 - f)));
    return `#${d(r).toString(16).padStart(2,'0')}${d(g).toString(16).padStart(2,'0')}${d(b).toString(16).padStart(2,'0')}`;
}

function hexToRgb(hex) {
    return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)];
}

function isValidHex(v) { return /^#[0-9A-Fa-f]{6}$/.test(v); }

// ── Apply theme color live ────────────────────────────────────────────────────
function applyTheme(color) {
    if (!isValidHex(color)) return;
    const dark = darken(color);
    const [r, g, b] = hexToRgb(color);
    const root = document.documentElement;

    // Our own vars
    root.style.setProperty('--primary',      color);
    root.style.setProperty('--primary-dark', dark);
    root.style.setProperty('--primary-rgb',  `${r}, ${g}, ${b}`);

    // Bootstrap root vars
    root.style.setProperty('--bs-primary',          color);
    root.style.setProperty('--bs-primary-rgb',      `${r}, ${g}, ${b}`);
    root.style.setProperty('--bs-link-color',       color);
    root.style.setProperty('--bs-link-hover-color', dark);

    // Button component vars (need explicit update as they don't inherit from root)
    document.querySelectorAll('.btn-primary').forEach(el => {
        el.style.setProperty('--bs-btn-bg',                  color);
        el.style.setProperty('--bs-btn-border-color',        color);
        el.style.setProperty('--bs-btn-hover-bg',            dark);
        el.style.setProperty('--bs-btn-hover-border-color',  dark);
        el.style.setProperty('--bs-btn-active-bg',           dark);
        el.style.setProperty('--bs-btn-active-border-color', dark);
        el.style.setProperty('--bs-btn-disabled-bg',         color);
    });
    document.querySelectorAll('.btn-outline-primary').forEach(el => {
        el.style.setProperty('--bs-btn-color',               color);
        el.style.setProperty('--bs-btn-border-color',        color);
        el.style.setProperty('--bs-btn-hover-bg',            color);
        el.style.setProperty('--bs-btn-hover-border-color',  color);
        el.style.setProperty('--bs-btn-active-bg',           color);
        el.style.setProperty('--bs-btn-active-border-color', color);
    });

    // Mini sidebar preview
    $('#previewSidebar').css('background', `linear-gradient(180deg, ${color}, ${dark})`);

    // Highlight active preset
    $('.color-preset').css('box-shadow', '').css('transform', '');
    $(`.color-preset[data-color="${color.toLowerCase()}"]`).css({
        'box-shadow': `0 0 0 3px #fff, 0 0 0 5px ${color}`,
        'transform':  'scale(1.15)',
    });
}

// ── Color picker ─────────────────────────────────────────────────────────────
$('#colorPicker').on('input', function () {
    const color = $(this).val();
    $('#colorHex').val(color);
    applyTheme(color);
});

$('#colorHex').on('input', function () {
    let v = $(this).val().trim();
    if (!v.startsWith('#')) v = '#' + v;
    if (isValidHex(v)) {
        $('#colorPicker').val(v);
        applyTheme(v);
    }
});

// Sync hex → picker on blur
$('#colorHex').on('blur', function () {
    let v = $(this).val().trim();
    if (!v.startsWith('#')) v = '#' + v;
    if (isValidHex(v)) $(this).val(v.toLowerCase());
    else $(this).val($('#colorPicker').val());
});

// ── Preset swatches ───────────────────────────────────────────────────────────
$(document).on('click', '.color-preset', function () {
    const color = $(this).data('color');
    $('#colorPicker').val(color);
    $('#colorHex').val(color);
    applyTheme(color);
});

// ── App name preview ──────────────────────────────────────────────────────────
$('#inputAppName').on('input', function () {
    $('#previewName').text($(this).val().trim() || 'App Name');
});

// ── Logo preview ──────────────────────────────────────────────────────────────
$('#logoInput').on('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        $('#logoPreview').attr('src', e.target.result);
        $('#logoPreviewWrap').removeClass('d-none');
        $('#previewBrand').html(`<img src="${e.target.result}" style="max-height:32px;max-width:120px;object-fit:contain">`);
    };
    reader.readAsDataURL(file);
});

$('#removeLogo').on('change', function () {
    if (this.checked) {
        $('#previewBrand').html(`
            <i class="bi bi-shop text-white" style="font-size:1.2rem"></i>
            <div class="text-white fw-bold text-truncate" style="font-size:.85rem">${$('#inputAppName').val() || '{{ $appName }}'}</div>`);
    }
});

// Apply saved color on load so preset highlight is correct
applyTheme('{{ $themeColor }}');
</script>
@endpush
