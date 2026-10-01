@extends('layouts.public')
@section('title', 'Brands')

@section('content')
<div class="mb-4">
    <h1 class="h3 mb-1">Our Brands</h1>
    <div class="pub-muted">A selection of the brands we work with.</div>
</div>

@if($brands->isEmpty())
    <div class="pub-card p-5 text-center pub-muted">
        <i class="bi bi-shop" style="font-size:2rem"></i>
        <div class="mt-2">No brands are listed yet.</div>
    </div>
@else
    <div class="row g-3">
        @foreach($brands as $brand)
            <div class="col-12 col-sm-6 col-lg-4">
                <a href="{{ route('landing.brand', $brand) }}" class="text-decoration-none">
                    <div class="pub-card p-3 h-100 d-flex align-items-center gap-3">
                        @if($brand->logo)
                            <img src="{{ $brand->logo }}" alt="{{ $brand->name }}" class="pub-logo">
                        @else
                            <div class="pub-logo d-flex align-items-center justify-content-center">
                                <i class="bi bi-shop" style="color:var(--text3)"></i>
                            </div>
                        @endif
                        <div>
                            <div class="fw-semibold" style="color:var(--text)">{{ $brand->name }}</div>
                            @if($brand->description)
                                <div class="small pub-muted text-truncate" style="max-width:220px">{{ $brand->description }}</div>
                            @endif
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endif
@endsection
