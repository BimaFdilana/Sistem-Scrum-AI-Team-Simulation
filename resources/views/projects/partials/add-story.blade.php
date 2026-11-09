{{-- Form tambah story ke sprint PLANNING. --}}
<details class="inline-story-form">
                    <summary class="button button-primary">＋ Tambah User Story ke {{ $sprint->name }}</summary>
                    <form class="project-form-main" method="POST" action="{{ route('sprints.stories.store', $sprint) }}">
                        @csrf
                        <p class="field-help">Sprint ID: <strong>{{ $sprint->name }}</strong> · Story ID akan dibuat otomatis.</p>
                        <div class="field-group"><label for="title-{{ $sprint->id }}">Saya Ingin [Fitur] <span>*</span></label><textarea id="title-{{ $sprint->id }}" name="title" rows="3" maxlength="180" required>{{ old('title') }}</textarea></div>
                        @error('title')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="actor-{{ $sprint->id }}">Sebagai [Aktor] <span>*</span></label><select id="actor-{{ $sprint->id }}" name="actor_id" required><option value="">Pilih aktor</option>@foreach ($actors as $actor)<option value="{{ $actor->id }}" @selected((string) old('actor_id') === (string) $actor->id)>{{ $actor->name }}</option>@endforeach</select></div>
                        @error('actor_id')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="purpose-{{ $sprint->id }}">Agar [Tujuan] <span>*</span></label><textarea id="purpose-{{ $sprint->id }}" name="sprint_purpose" rows="2" maxlength="3000" required>{{ old('sprint_purpose') }}</textarea></div>
                        @error('sprint_purpose')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="field-group"><label for="criteria-{{ $sprint->id }}">Acceptance Criteria <span>*</span></label><textarea id="criteria-{{ $sprint->id }}" name="acceptance_criteria" rows="4" maxlength="10000" required>{{ old('acceptance_criteria') }}</textarea></div>
                        @error('acceptance_criteria')<small class="field-error">{{ $message }}</small>@enderror
                        <button class="button button-primary" type="submit">Simpan User Story</button>
                    </form>
                </details>
