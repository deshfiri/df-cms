@extends('layouts.app')
@section('title', 'Sound Settings')

@push('styles')
<style>
    .snd-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .snd-help { font-size: var(--fs-2xs); color: var(--text3); margin-top: 4px; display: block; line-height: 1.5; }
    .snd-error { font-size: var(--fs-2xs); color: var(--c-red); margin-top: 4px; display: block; }

    .snd-list { display: flex; flex-direction: column; }
    .snd-row {
        display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--space-3) var(--space-4);
        align-items: center; padding: var(--space-4) 0; border-top: 1px solid var(--border);
        transition: opacity .15s;
    }
    .snd-row:first-child { border-top: 0; padding-top: 0; }
    .snd-row.snd-off .snd-controls { opacity: .45; }

    .snd-what { display: flex; align-items: center; gap: var(--space-3); min-width: 0; }
    .snd-icon {
        width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(var(--primary-rgb), .12); color: var(--primary); font-size: 1rem;
    }
    .snd-label { font-size: var(--fs-sm); font-weight: 600; color: var(--text); }
    .snd-hint { font-size: var(--fs-2xs); color: var(--text3); line-height: 1.4; }

    .snd-controls { display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; justify-content: flex-end; }
    .snd-controls .form-select { width: 190px; }
    .snd-vol { display: flex; align-items: center; gap: var(--space-2); }
    .snd-vol input[type=range] { width: 110px; accent-color: var(--primary); }
    .snd-vol output { font-size: var(--fs-2xs); color: var(--text2); width: 34px; text-align: right; font-variant-numeric: tabular-nums; }

    .snd-upload, .snd-error { grid-column: 1 / -1; }
    .snd-upload .form-control { max-width: 360px; }

    .snd-disabled-note { display: none; }
    .snd-all-off .snd-disabled-note { display: block; }
    .snd-all-off .snd-list { opacity: .5; }

    @media (max-width: 767.98px) {
        .snd-row { grid-template-columns: 1fr; }
        .snd-controls { justify-content: flex-start; }
        .snd-controls .form-select { width: 100%; flex: 1 1 100%; }
    }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-volume-up me-2"></i>Sounds</h4>
        <small style="color:var(--text3)">Which sound plays for what, and how loud — for everyone.</small>
    </div>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'sounds'])

<div>
    @if(session('success'))
        <div class="snd-note mb-4" style="border-left:3px solid var(--c-green)">
            <i class="bi bi-check-circle me-1" style="color:var(--c-green)"></i>{{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="snd-note mb-4" style="border-left:3px solid var(--c-red)">
            <i class="bi bi-exclamation-circle me-1" style="color:var(--c-red)"></i>Nothing was saved — see the notes below.
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- After a failed save, show what was submitted rather than what is stored. --}}
            @php $masterOn = $errors->any() ? (bool) old('enabled') : $config['enabled']; @endphp
            <form method="POST" action="{{ route('settings.sounds.update') }}" enctype="multipart/form-data" id="sndForm"
                  class="{{ $masterOn ? '' : 'snd-all-off' }}">
                @csrf

                <div class="card section-card mb-4">
                    <div class="card-header py-3">
                        <h6 class="fw-bold mb-0">Alert sounds</h6>
                        <small style="color:var(--text3)">The switch for the whole app.</small>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="sndEnabled" name="enabled" value="1"
                                   {{ $masterOn ? 'checked' : '' }}>
                            <label class="form-check-label" for="sndEnabled" style="font-size:.86rem">Play alert sounds</label>
                        </div>
                        <span class="snd-help">
                            Off silences the app for everyone: chat, notifications and the call ringtone.
                            With it on, each person can still mute sounds for themselves from their profile menu.
                        </span>
                    </div>
                </div>

                <div class="card section-card mb-4">
                    <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <h6 class="fw-bold mb-0">What plays for what</h6>
                            <small style="color:var(--text3)">Press <i class="bi bi-play-fill"></i> to hear a choice before saving it.</small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="sndDefaults">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore defaults
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="snd-note snd-disabled-note mb-3" style="border-left:3px solid var(--c-yellow)">
                            <i class="bi bi-volume-mute me-1" style="color:var(--c-yellow)"></i>
                            Alert sounds are off, so none of these play. You can still set them up for later.
                        </div>

                        <div class="snd-list">
                            @foreach($events as $key => $meta)
                                @php
                                    $row    = $config['events'][$key];
                                    $on     = $errors->any() ? (bool) old("events.{$key}.on") : $row['on'];
                                    $sound  = old("events.{$key}.sound", $row['sound']);
                                    $volume = (int) old("events.{$key}.volume", $row['volume']);
                                @endphp
                                <div class="snd-row {{ $on ? '' : 'snd-off' }}" data-event="{{ $key }}"
                                     data-default-sound="{{ $meta['default'] }}" data-default-volume="{{ $meta['volume'] }}">
                                    <div class="snd-what">
                                        <span class="snd-icon"><i class="bi {{ $meta['icon'] }}"></i></span>
                                        <div style="min-width:0">
                                            <div class="snd-label">{{ $meta['label'] }}</div>
                                            <div class="snd-hint">{{ $meta['hint'] }}</div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-3 flex-wrap">
                                        <div class="form-check form-switch m-0" title="Play a sound for this">
                                            <input class="form-check-input snd-on" type="checkbox" role="switch"
                                                   id="sndOn-{{ $key }}" name="events[{{ $key }}][on]" value="1" {{ $on ? 'checked' : '' }}
                                                   aria-label="Play a sound for {{ $meta['label'] }}">
                                        </div>
                                        <div class="snd-controls">
                                            <select class="form-select form-select-sm snd-sound" name="events[{{ $key }}][sound]"
                                                    aria-label="{{ $meta['label'] }} sound">
                                                @foreach(collect($library)->groupBy('kind', true) as $kind => $items)
                                                    <optgroup label="{{ $kind }}">
                                                        @foreach($items as $value => $item)
                                                            <option value="{{ $value }}" {{ $sound === $value ? 'selected' : '' }}>
                                                                {{ $item['label'] }}{{ $value === $meta['default'] ? ' (default)' : '' }}
                                                            </option>
                                                        @endforeach
                                                    </optgroup>
                                                @endforeach
                                                <optgroup label="Your own">
                                                    @if($row['custom'])
                                                        <option value="{{ App\Services\SoundSettings::CUSTOM }}" {{ $sound === App\Services\SoundSettings::CUSTOM ? 'selected' : '' }}>
                                                            {{ \Illuminate\Support\Str::limit($row['custom']['name'], 28) }}
                                                        </option>
                                                    @endif
                                                    <option value="{{ App\Services\SoundSettings::UPLOAD }}" {{ $sound === App\Services\SoundSettings::UPLOAD ? 'selected' : '' }}>
                                                        {{ $row['custom'] ? 'Upload a different sound…' : 'Upload a sound…' }}
                                                    </option>
                                                </optgroup>
                                            </select>

                                            <div class="snd-vol">
                                                <i class="bi bi-volume-down" style="color:var(--text3)"></i>
                                                <input type="range" class="snd-volume" name="events[{{ $key }}][volume]"
                                                       min="0" max="100" step="5" value="{{ $volume }}"
                                                       aria-label="{{ $meta['label'] }} volume">
                                                <output>{{ $volume }}%</output>
                                            </div>

                                            <button type="button" class="btn btn-sm btn-outline-secondary snd-test" title="Play it">
                                                <i class="bi bi-play-fill"></i><span class="visually-hidden">Play {{ $meta['label'] }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="snd-upload {{ $sound === App\Services\SoundSettings::UPLOAD ? '' : 'd-none' }}">
                                        <input type="file" class="form-control form-control-sm snd-file" name="uploads[{{ $key }}]"
                                               accept=".mp3,.wav,.ogg,audio/mpeg,audio/wav,audio/ogg"
                                               aria-label="{{ $meta['label'] }} file">
                                        <span class="snd-help">MP3, WAV or OGG, up to {{ $maxKb }} KB. A second or two is plenty{{ !empty($meta['loop']) ? '; it repeats until the call is answered' : '' }}.</span>
                                    </div>

                                    @foreach(["events.{$key}.sound", "events.{$key}.volume", "uploads.{$key}"] as $field)
                                        @error($field)<span class="snd-error">{{ $message }}</span>@enderror
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card section-card" style="position:sticky;top:72px">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">How it works</h6>
                </div>
                <div class="card-body">
                    <ul style="font-size:.78rem;color:var(--text2);padding-left:1.1rem;line-height:1.75">
                        <li>This applies to <strong>everyone</strong>. Each page picks up the new sounds when it next loads.</li>
                        <li>Anyone can still mute sounds for themselves from their profile menu → <em>Alert sounds</em>.</li>
                        <li><strong>Tones</strong> are generated by the browser, so they need no download.
                            <strong>Recordings</strong> and your own uploads are audio files.</li>
                        <li>A task, workflow or meeting notification plays its own sound. Everything else
                            in the bell plays <em>Notification</em>.</li>
                        <li>The incoming call sound repeats until the call is answered or declined.</li>
                        <li>Browsers stay silent until someone has clicked on the page once. That is a
                            browser rule, not a setting.</li>
                        <li>Every change is recorded in the activity log.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const LIBRARY = @json($library);
    // What an event's saved upload is, so it can be played without re-uploading.
    const SAVED = @json($saved);
    const MAX_BYTES = {{ $maxKb }} * 1024;
    const CUSTOM = @json(App\Services\SoundSettings::CUSTOM);
    const UPLOAD = @json(App\Services\SoundSettings::UPLOAD);
    const picked = {};   // event => object URL of a file chosen but not yet saved

    function rowOf(el) { return $(el).closest('.snd-row'); }

    function spec($row) {
        const event = $row.data('event');
        const value = $row.find('.snd-sound').val();
        const volume = Number($row.find('.snd-volume').val()) / 100;

        if (LIBRARY[value]) return { src: LIBRARY[value].src, tone: LIBRARY[value].tone, volume: volume };
        if (value === CUSTOM && SAVED[event]) return { src: SAVED[event].src, tone: SAVED[event].tone, volume: volume };
        if (value === UPLOAD && picked[event]) return { src: picked[event], volume: volume };
        return null;
    }

    $('#sndEnabled').on('change', function () {
        $('#sndForm').toggleClass('snd-all-off', !this.checked);
    });

    $('.snd-on').on('change', function () {
        rowOf(this).toggleClass('snd-off', !this.checked);
    });

    $('.snd-volume').on('input', function () {
        $(this).siblings('output').text(this.value + '%');
    });

    $('.snd-sound').on('change', function () {
        rowOf(this).find('.snd-upload').toggleClass('d-none', this.value !== UPLOAD);
    });

    $('.snd-file').on('change', function () {
        const $row = rowOf(this), event = $row.data('event'), file = this.files && this.files[0];

        if (picked[event]) { URL.revokeObjectURL(picked[event]); delete picked[event]; }
        if (!file) return;

        if (file.size > MAX_BYTES) {
            this.value = '';
            Swal.fire('Too large', 'Keep a sound under {{ $maxKb }} KB. An alert only needs a second or two.', 'warning');
            return;
        }
        picked[event] = URL.createObjectURL(file);
    });

    $('.snd-test').on('click', function () {
        const $row = rowOf(this), s = spec($row);

        if (!s) {
            Swal.fire({ toast: true, position: 'top-end', timer: 2500, showConfirmButton: false, icon: 'info', title: 'Choose a file to upload first.' });
            return;
        }
        if (!s.volume) {
            Swal.fire({ toast: true, position: 'top-end', timer: 2500, showConfirmButton: false, icon: 'info', title: 'The volume is at 0%.' });
            return;
        }
        if (window.AppSound) window.AppSound.preview(s);
    });

    $('#sndDefaults').on('click', function () {
        Swal.fire({
            title: 'Restore the default sounds?',
            text: 'Every sound goes back to what the app shipped with. Nothing changes until you save.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Restore',
        }).then(function (res) {
            if (!res.isConfirmed) return;

            $('#sndEnabled').prop('checked', true).trigger('change');
            $('.snd-row').each(function () {
                const $row = $(this);
                $row.find('.snd-on').prop('checked', true).trigger('change');
                $row.find('.snd-sound').val($row.data('default-sound')).trigger('change');
                $row.find('.snd-volume').val($row.data('default-volume')).trigger('input');
                $row.find('.snd-file').val('').trigger('change');
            });
        });
    });
})();
</script>
@endpush
