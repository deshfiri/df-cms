@extends('layouts.public')
@section('title', $brand['name'])

@section('content')
<div class="mb-3">
    <a href="{{ route('landing') }}" class="small text-decoration-none pub-muted"><i class="bi bi-arrow-left me-1"></i>All brands</a>
</div>

<div class="pub-card p-4 mb-4 d-flex align-items-center gap-3">
    @if($brand['logo'])
        <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="pub-logo" style="width:72px;height:72px">
    @else
        <div class="pub-logo d-flex align-items-center justify-content-center" style="width:72px;height:72px">
            <i class="bi bi-shop" style="color:var(--text3);font-size:1.5rem"></i>
        </div>
    @endif
    <div>
        <h1 class="h4 mb-1">{{ $brand['name'] }}</h1>
        @if($brand['website'])
            <a href="{{ $brand['website'] }}" target="_blank" rel="noopener" class="small">{{ $brand['website'] }}</a>
        @endif
        @if($brand['description'])
            <div class="mt-2" style="color:var(--text2)">{{ $brand['description'] }}</div>
        @endif
    </div>
</div>

<h2 class="h5 mb-3">Products</h2>

@if($products->isEmpty())
    <div class="pub-card p-4 text-center pub-muted">No products listed yet.</div>
@else
    <div class="row g-3">
        @foreach($products as $product)
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pub-card p-3 h-100">
                    @if($product->image)
                        <img src="{{ $product->image }}" alt="{{ $product->name }}" class="w-100 mb-2" style="height:140px;object-fit:cover;border-radius:var(--radius)">
                    @endif
                    <div class="fw-semibold" style="color:var(--text)">{{ $product->name }}</div>
                    @if($product->description)
                        <div class="small pub-muted mt-1">{{ $product->description }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
