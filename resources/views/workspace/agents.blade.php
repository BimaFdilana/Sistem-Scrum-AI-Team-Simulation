@extends('layouts.workspace', ['title' => 'Agent', 'breadcrumb' => 'Agent'])

@section('content')
    <section class="page-heading">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span>AI AGENT DIRECTORY</p>
            <h1>Agent team</h1>
            <p class="page-subtitle">Pilih profil untuk konsultasi sesuai perannya. Chat tidak mengubah source code atau menjalankan task; eksekusi tetap terkunci sampai isolasi workspace diverifikasi.</p>
        </div>
        <span class="count-pill">{{ count($agents) }} agents</span>
    </section>

    <section class="agent-directory-list">
        @foreach ($agents as $agent)
            @php($chatAvailable = $agentConnections[$agent['slug']] ?? false)
            @php($chatUrl = $agent['slug'] === 'scrum-master' ? route('chat.index', [], false) : null)
            @php($activityUrl = route('agents.activity', ['slug' => $agent['slug']], false))
            <article class="directory-row">
                <span class="agent-avatar avatar-{{ $agent['tone'] }}">{{ $agent['initials'] }}</span>
                <div class="directory-main"><h2>{{ $agent['name'] }}</h2><p>{{ $agent['description'] }}</p></div>
                <div class="directory-role"><span>{{ $agent['role'] }}</span></div>
                <span class="agent-status"><i></i>{{ $agent['slug'] === 'scrum-master' ? ($chatAvailable ? 'Chat aktif' : 'Belum terhubung') : ($chatAvailable ? 'Siap dipanggil Scrum Master' : 'Belum terhubung') }}</span>
                <div class="directory-actions">
                    @if ($chatUrl)
                        <a class="button button-primary" href="{{ $chatUrl }}">Chat dengan Scrum Master <span aria-hidden="true">→</span></a>
                    @else
                        <span class="button button-ghost" aria-label="Agent spesialis dikoordinasikan oleh Scrum Master">Dipanggil melalui Scrum Master</span>
                    @endif
                    <a class="button button-secondary" href="{{ route('agents.config.edit', ['slug' => $agent['slug']], false) }}">Konfigurasi Agent</a>
                    <a class="button button-secondary" href="{{ $activityUrl }}">Log activity</a>
                </div>
            </article>
        @endforeach
    </section>
@endsection
