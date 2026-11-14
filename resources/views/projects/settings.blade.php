@extends('layouts.workspace', ['title' => 'Pengaturan · '.$project->name, 'breadcrumb' => $project->name.' / Pengaturan Project'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>PENGATURAN PROJECT</p>
            <h1>Pengaturan {{ $project->name }}</h1>
            <p class="page-subtitle">Sumber kode, nama backlog, dan gerbang tes QA project ini.</p>
        </div>
    </section>

    @if (session('status'))<div class="success-banner" role="status">{{ session('status') }}</div>@endif

    @include('projects.partials.settings')
@endsection
