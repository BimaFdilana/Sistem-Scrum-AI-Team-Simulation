@extends('layouts.workspace', ['title' => 'Demo & Rilis · '.$project->name, 'breadcrumb' => $project->name.' / Demo & Rilis'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>DEMO &amp; RILIS</p>
            <h1>Demo &amp; Rilis {{ $project->name }}</h1>
            <p class="page-subtitle">Coba hasil kerja tim di demo, lalu satukan dan kirim ke GitHub sebagai satu project utuh.</p>
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif
    @foreach (['demo', 'release'] as $errorKey)
        @error($errorKey)<div class="form-alert" role="alert">{{ $message }}</div>@enderror
    @endforeach

    @include('projects.partials.demo-panel')
    @includeIf('projects.partials.release-panel')
@endsection
