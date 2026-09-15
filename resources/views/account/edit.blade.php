@extends('layouts.app')
@section('title', 'My Account')

@push('styles')
<style>
    .acc-grid { display: grid; grid-template-columns: minmax(0, 320px) minmax(0, 1fr); gap: 1rem; align-items: start; }
    @media (max-width: 991.98px) { .acc-grid { grid-template-columns: 1fr; } }

    .acc-avatar {
        width: 64px; height: 64px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: rgba(var(--primary-rgb), .12); color: var(--primary);
        font-size: 1.6rem; font-weight: 700;
    }
    .acc-k { font-size: .66rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 600; }
    .acc-v { font-size: .86rem; color: var(--text); margin-bottom: .75rem; word-break: break-word; }
    .acc-role { display: inline-block; padding: 1px 8px; border-radius: 20px; font-size: .66rem; font-weight: 600; background: rgba(var(--primary-rgb), .12); color: var(--primary); margin: 0 .25rem .25rem 0; }

    .acc-help { font-size: .74rem; color: var(--text3); }
    .acc-rules { list-style: none; padding: 0; margin: .45rem 0 0; font-size: .74rem; }
    .acc-rules li { color: var(--text3); margin-bottom: 2px; }
    .acc-rules li i { width: 1rem; display: inline-block; }
    .acc-rules li.ok { color: var(--c-green); }

    .acc-eye {
        border: 1px solid var(--border); border-left: 0; background: var(--surface2); color: var(--text3);
    }
    .acc-eye:hover { color: var(--text); }
    .acc-match { font-size: .74rem; margin-top: 4px; min-height: 1.1em; }

    .acc-note {
        font-size: .76rem; color: var(--text2); background: var(--surface2);
        border: 1px solid var(--border); border-radius: var(--radius); padding: .6rem .8rem;
    }
</style>
@endpush

@section('content')
<div class="mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-person-gear me-2"></i>My Account</h4>
    <small style="color:var(--text3)">Your sign-in details.</small>
</div>

<div class="acc-grid">
    {{-- Who you are --}}
    <div class="card section-card">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="acc-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
                <div class="min-w-0">
                    <div style="font-weight:700;color:var(--text);font-size:1rem" class="text-truncate">{{ $user->name }}</div>
                    <div style="font-size:.78rem;color:var(--text3)" class="text-truncate">{{ $user->email }}</div>
                </div>
            </div>

            <div class="acc-k">Roles</div>
            <div class="acc-v">
                @forelse($user->roles as $role)
                    <span class="acc-role">{{ $role->name }}</span>
                @empty
                    <span style="color:var(--text3)">None</span>
                @endforelse
            </div>

            <div class="acc-k">Member since</div>
            <div class="acc-v">{{ $user->created_at?->format('d M Y') ?? '—' }}</div>

            <div class="acc-help mb-0">
                <i class="bi bi-info-circle me-1"></i>To change your name, email or roles, ask an administrator.
            </div>
        </div>
    </div>

    {{-- Change password --}}
    <div class="card section-card">
        <div class="card-header py-3">
            <h6 class="fw-bold mb-0">Change password</h6>
            <small style="color:var(--text3)">You'll stay signed in on this browser.</small>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('account.password') }}" id="accPasswordForm" autocomplete="off" novalidate>
                @csrf
                @method('PUT')

                <div class="mb-3" style="max-width:420px">
                    <label class="form-label fw-semibold small" for="current_password">Current password</label>
                    <div class="input-group has-validation">
                        <input type="password" name="current_password" id="current_password" autocomplete="current-password"
                               class="form-control @error('current_password', 'password') is-invalid @enderror" required>
                        <button type="button" class="btn acc-eye" data-toggle-pass="current_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        @error('current_password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3" style="max-width:420px">
                    <label class="form-label fw-semibold small" for="password">New password</label>
                    <div class="input-group has-validation">
                        <input type="password" name="password" id="password" autocomplete="new-password"
                               class="form-control @error('password', 'password') is-invalid @enderror" required>
                        <button type="button" class="btn acc-eye" data-toggle-pass="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        @error('password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <ul class="acc-rules" id="accRules">
                        <li data-rule="length"><i class="bi bi-circle"></i>At least 8 characters</li>
                        <li data-rule="letter"><i class="bi bi-circle"></i>Contains a letter</li>
                        <li data-rule="number"><i class="bi bi-circle"></i>Contains a number</li>
                    </ul>
                </div>

                <div class="mb-3" style="max-width:420px">
                    <label class="form-label fw-semibold small" for="password_confirmation">Confirm new password</label>
                    <div class="input-group">
                        <input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" class="form-control" required>
                        <button type="button" class="btn acc-eye" data-toggle-pass="password_confirmation" aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="acc-match" id="accMatch"></div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="logout_others" value="1" id="logout_others" @checked(old('logout_others', true))>
                    <label class="form-check-label small" for="logout_others">
                        Sign me out everywhere else
                        @if($otherSessions)
                            <span style="color:var(--text3)">— you're signed in on {{ $otherSessions }} other {{ Str::plural('device', $otherSessions) }}</span>
                        @endif
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-sm" id="accSubmit">
                    <i class="bi bi-shield-lock me-1"></i>Update password
                </button>
            </form>

            <div class="acc-note mt-4">
                <i class="bi bi-question-circle me-1"></i>
                Forgot your current password? Sign out and use <strong>Forgot password</strong> on the login page,
                or ask an administrator to set a new one for you.
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Show / hide a password field.
    $('[data-toggle-pass]').on('click', function () {
        const input = $('#' + $(this).data('toggle-pass'));
        const show = input.attr('type') === 'password';
        input.attr('type', show ? 'text' : 'password');
        $(this).find('i').toggleClass('bi-eye', !show).toggleClass('bi-eye-slash', show);
    });

    // Live hints — the server enforces the same rules.
    function check() {
        const pass = $('#password').val();
        const rules = { length: pass.length >= 8, letter: /[A-Za-z]/.test(pass), number: /\d/.test(pass) };
        Object.keys(rules).forEach(function (key) {
            $('#accRules [data-rule="' + key + '"]').toggleClass('ok', rules[key])
                .find('i').toggleClass('bi-check-circle-fill', rules[key]).toggleClass('bi-circle', !rules[key]);
        });

        const confirm = $('#password_confirmation').val();
        $('#accMatch').html(!confirm ? ''
            : confirm === pass
                ? '<span style="color:var(--c-green)"><i class="bi bi-check-circle-fill me-1"></i>Passwords match</span>'
                : '<span style="color:var(--c-red)"><i class="bi bi-x-circle-fill me-1"></i>Passwords don\'t match yet</span>');
    }
    $('#password, #password_confirmation').on('input', check);

    $('#accPasswordForm').on('submit', function () {
        $('#accSubmit').prop('disabled', true);
    });

    @if($errors->password->any())
        $('#{{ $errors->password->has('current_password') ? 'current_password' : 'password' }}').trigger('focus');
    @endif
})();
</script>
@endpush
