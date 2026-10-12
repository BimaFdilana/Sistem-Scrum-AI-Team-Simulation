<?php

namespace App\Support\Agents;

/** Kepribadian profesional tiap peran AI (disetujui Bima 2026-10-13). */
class AgentSoul
{
    public static function for(string $slug): ?string
    {
        $profile = collect(config('agents.profiles'))->firstWhere('slug', $slug);

        return is_string($profile['soul'] ?? null) && trim($profile['soul']) !== ''
            ? trim($profile['soul'])
            : null;
    }

    /** Baris soul untuk ditempel ke prompt (SM via HermesClient tidak punya system prompt). */
    public static function promptLine(string $slug): string
    {
        $soul = self::for($slug);

        return $soul === null ? '' : 'Kepribadian profesionalmu: '.$soul;
    }

    /** Skill diskusi yang terdaftar untuk peran ini. */
    public static function skills(string $slug): array
    {
        $profile = collect(config('agents.profiles'))->firstWhere('slug', $slug);

        return array_values(array_filter((array) ($profile['skills'] ?? []), 'is_string'));
    }
}
