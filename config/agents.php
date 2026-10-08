<?php

return [
    'profiles' => [
        [
            'slug' => 'scrum-master',
            'name' => 'Scrum Master',
            'initials' => 'SM',
            'role' => 'Orchestrator',
            'description' => 'Memfasilitasi refinement, planning, diskusi agent, dan alur sprint.',
            'profile' => 'scrumagent',
            'tone' => 'mint',
        ],
        [
            'slug' => 'backend-developer',
            'name' => 'Backend Developer',
            'initials' => 'BE',
            'role' => 'Implementation',
            'description' => 'Mengerjakan API, business logic, persistence, dan integrasi server.',
            'profile' => 'backend-developer',
            'tone' => 'blue',
        ],
        [
            'slug' => 'frontend-developer',
            'name' => 'Frontend Developer',
            'initials' => 'FE',
            'role' => 'Implementation',
            'description' => 'Mengerjakan UI workspace dan integrasi ke endpoint Laravel.',
            'profile' => 'frontend-developer',
            'tone' => 'violet',
        ],
        [
            'slug' => 'qa-engineer',
            'name' => 'QA Engineer',
            'initials' => 'QA',
            'role' => 'Independent verification',
            'description' => 'Memverifikasi acceptance criteria dengan test dan evidence nyata, termasuk risiko keamanan.',
            'profile' => 'qa-engineer',
            'tone' => 'amber',
        ],
    ],
];
