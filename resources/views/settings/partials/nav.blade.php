{{--
    Shared settings sidebar.

    Every settings page renders this, so the section list is defined once here
    rather than as ad-hoc buttons in each page header. Pass $active as the key
    of the current section.

    Items carry an optional status chip: a section whose state matters from the
    outside (is a CDN connected? is Meet linked?) should say so without making
    an admin open it.
--}}
@php
    // Resolved here rather than passed in, so every settings page shows where
    // files are going without each controller having to remember to send it.
    $storageProvider = app(App\Services\Storage\StorageSettings::class)->activeDisk();
    $storageBadge = match ($storageProvider) {
        'cloudflare' => 'R2',
        'cloudinary' => 'Cloudinary',
        default      => 'Local',
    };

    $groups = [
        'Workspace' => [
            'general'    => ['label' => 'General',        'icon' => 'bi-sliders',      'route' => 'settings.index',   'hint' => 'Name, logo, theme'],
            'storage'    => ['label' => 'Storage & CDN',  'icon' => 'bi-hdd-network',  'route' => 'settings.storage', 'hint' => 'Where files are kept'],
        ],
        'Integrations' => [
            'whatsapp'   => ['label' => 'WhatsApp',       'icon' => 'bi-whatsapp',     'route' => 'settings.whatsapp', 'hint' => 'Customer messaging'],
            'google'     => ['label' => 'Google Meet',    'icon' => 'bi-camera-video', 'route' => 'settings.google',  'hint' => 'Meeting links'],
            'meta'       => ['label' => 'Meta Marketing', 'icon' => 'bi-meta',         'route' => 'settings.meta',    'hint' => 'Ad account sync'],
        ],
        'Access' => [
            'users'      => ['label' => 'Users',          'icon' => 'bi-people',       'route' => 'users.index',      'hint' => 'Staff accounts'],
            'roles'      => ['label' => 'Roles',          'icon' => 'bi-shield-lock',  'route' => 'roles.index',      'hint' => 'Permissions'],
            'categories' => ['label' => 'Categories',     'icon' => 'bi-tags',         'route' => 'categories.index', 'hint' => 'Client categories'],
        ],
    ];
@endphp

<nav class="set-nav">
    @foreach($groups as $groupLabel => $items)
        <div class="set-nav-group">{{ $groupLabel }}</div>
        @foreach($items as $key => $item)
            @continue(!Route::has($item['route']))
            <a href="{{ route($item['route']) }}" class="set-nav-item {{ ($active ?? '') === $key ? 'active' : '' }}">
                <i class="bi {{ $item['icon'] }}"></i>
                <span class="set-nav-text">
                    <span class="set-nav-label">{{ $item['label'] }}</span>
                    <span class="set-nav-hint">{{ $item['hint'] }}</span>
                </span>
                @if($key === 'storage' && !empty($storageBadge))
                    <span class="set-nav-badge">{{ $storageBadge }}</span>
                @endif
            </a>
        @endforeach
    @endforeach
</nav>

@once
    @push('styles')
    <style>
        .set-layout { display: grid; grid-template-columns: 232px minmax(0, 1fr); gap: var(--space-5); align-items: start; }
        @media (max-width: 991.98px) { .set-layout { grid-template-columns: 1fr; } }

        .set-nav {
            position: sticky; top: 72px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: var(--space-2);
        }
        @media (max-width: 991.98px) { .set-nav { position: static; } }

        .set-nav-group {
            font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .06em;
            color: var(--text3); font-weight: 700;
            padding: var(--space-3) var(--space-3) var(--space-2);
        }
        .set-nav-group:first-child { padding-top: var(--space-2); }

        .set-nav-item {
            display: flex; align-items: center; gap: var(--space-3);
            padding: 8px var(--space-3); border-radius: var(--radius-sm);
            color: var(--text2); text-decoration: none;
            transition: background .12s, color .12s;
        }
        .set-nav-item:hover { background: var(--surface2); color: var(--text); }
        .set-nav-item.active { background: rgba(var(--primary-rgb), .12); color: var(--primary); }
        .set-nav-item.active .set-nav-label { font-weight: 600; }
        .set-nav-item > i { font-size: .95rem; flex-shrink: 0; }

        .set-nav-text { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
        .set-nav-label { font-size: var(--fs-sm); }
        .set-nav-hint { font-size: var(--fs-2xs); color: var(--text3); }
        .set-nav-item.active .set-nav-hint { color: rgba(var(--primary-rgb), .75); }

        .set-nav-badge {
            margin-left: auto; flex-shrink: 0;
            font-size: var(--fs-2xs); font-weight: 600;
            padding: 1px 7px; border-radius: 20px;
            background: var(--surface2); color: var(--text3);
            border: 1px solid var(--border);
        }
    </style>
    @endpush
@endonce
