{{-- Papan Sprint Backlog: sprint sebagai akordeon, story sebagai baris ringkas. --}}
@forelse ($sprints as $sprint)
    @include('projects.partials.sprint', ['sprint' => $sprint])
@empty
    <section class="project-empty">
        <span class="empty-icon">◷</span>
        <p class="section-kicker">BELUM ADA SPRINT</p>
        <h2>Mulai dari satu Sprint</h2>
        <p>Buat Sprint, lalu tambahkan User Story ke dalamnya. Estimasi dan eksekusi dilakukan per story di halaman ini.</p>
        @if ($sprintSchemaReady)
            <button class="button button-primary" type="button" data-sprint-dialog-open>＋ Buat Sprint pertama</button>
        @endif
    </section>
@endforelse
