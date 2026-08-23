@extends('layouts.app')
@section('title', 'Marketing')

@push('styles')
<style>
    .mk-brand-card {
        display: block; text-decoration: none; height: 100%;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: var(--space-4);
        transition: transform .15s, border-color .15s, box-shadow .15s;
    }
    .mk-brand-card:hover { transform: translateY(-2px); border-color: var(--primary); box-shadow: var(--shadow-md); }
    .mk-brand-name { font-size: .95rem; font-weight: 700; color: var(--text); }
    .mk-brand-client { font-size: .74rem; color: var(--text3); }
    .mk-platforms { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .7rem; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-megaphone me-2"></i>Marketing</h4>
        <small style="color:var(--text3)">Pick a brand to see its advertising performance.</small>
    </div>
</div>

<div class="row g-3">
    @forelse($brands as $brand)
        @php
            $meta = $brand->integrations->firstWhere('platform', 'meta');
        @endphp
        <div class="col-md-6 col-xl-4">
            <a href="{{ route('marketing.brand', $brand) }}" class="mk-brand-card">
                <div class="mk-brand-name">{{ $brand->name }}</div>
                <div class="mk-brand-client">{{ $brand->client->client_name ?? '—' }}</div>

                <div class="mk-platforms">
                    @if($meta && $meta->status === 'connected')
                        <span class="spill spill-completed"><i class="bi bi-meta me-1"></i>Meta connected</span>
                    @elseif($meta && $meta->status === 'token_expired')
                        <span class="spill spill-warning"><i class="bi bi-exclamation-triangle me-1"></i>Meta needs reconnecting</span>
                    @else
                        <span class="spill spill-hold"><i class="bi bi-plug me-1"></i>No platform connected</span>
                    @endif
                </div>

                @if($meta?->last_synced_at)
                    <div style="font-size:.7rem;color:var(--text3);margin-top:.5rem">
                        Last sync {{ $meta->last_synced_at->diffForHumans() }}
                    </div>
                @endif
            </a>
        </div>
    @empty
        <div class="col-12">
            <div class="card section-card">
                <div class="card-body text-center py-5" style="color:var(--text3)">
                    <i class="bi bi-megaphone" style="font-size:2.4rem"></i>
                    <div class="mt-2" style="font-size:.9rem">
                        No brands yet. Brands are created on a client's profile, under the Brands tab.
                    </div>
                </div>
            </div>
        </div>
    @endforelse
</div>
@endsection
