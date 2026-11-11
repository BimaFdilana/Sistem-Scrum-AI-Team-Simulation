{{-- Aksi sprint: rumuskan goal, setujui scope, mulai sprint. --}}
<div class="sprint-goal-actions">
                    <a class="button button-ghost" href="{{ route('chat.index', ['sprint' => $sprint->id]) }}">Chat dengan Scrum Master</a>
                    @if ($sprintSchemaReady)
                    @if ($sprint->stories->isNotEmpty())
                        <form method="POST" action="{{ route('sprints.goal.generate', $sprint) }}">@csrf<button class="button button-ghost" type="submit">{{ $sprint->goal ? 'Buat ulang draf Sprint Goal dengan Scrum Master AI' : 'Buat Sprint Goal dengan Scrum Master AI' }}</button></form>
                        @if ($sprint->goal)<form method="POST" action="{{ route('sprints.scope.approve', $sprint) }}">@csrf<button class="button button-ghost" type="submit">PO · Setujui Sprint Backlog</button></form>@endif
                        @if ($sprint->scope_approved_revision === $sprint->revision)<form method="POST" action="{{ route('sprints.start', $sprint) }}">@csrf<button class="button button-primary" type="submit">Mulai Sprint →</button></form>@endif
                    @endif
                    @endif
                </div>
