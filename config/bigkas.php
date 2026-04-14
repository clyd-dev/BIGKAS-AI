<?php

/**
 * BIGKAS-AI Application Configuration
 * AI/ML-Assisted Reading Assessment System for Philippine Public Schools
 */

return [
    // Supported locales
    'locales' => ['en', 'fil', 'hil'],

    // Upload settings
    'upload' => [
        'max_size' => env('UPLOAD_MAX_SIZE', 10485760), // 10MB
        'audio_types' => explode(',', env('AUDIO_ALLOWED_TYPES', 'mp3,wav,webm,ogg')),
    ],

    // Phil-IRI Reading Levels
    'reading_levels' => [
        'frustration' => [
            'name' => 'Frustration Level',
            'min_accuracy' => 0,
            'max_accuracy' => 89,
            'color' => '#dc3545',
            'description' => 'Reading material is too difficult',
        ],
        'instructional' => [
            'name' => 'Instructional Level',
            'min_accuracy' => 90,
            'max_accuracy' => 96,
            'color' => '#ffc107',
            'description' => 'Appropriate for guided reading',
        ],
        'independent' => [
            'name' => 'Independent Level',
            'min_accuracy' => 97,
            'max_accuracy' => 100,
            'color' => '#28a745',
            'description' => 'Can read independently',
        ],
    ],

    // Reading weakness categories
    'weakness_categories' => [
        1 => [
            'name' => 'Phonemic Awareness',
            'code' => 'PHONEMIC',
            'description' => 'Difficulty with sound-letter relationships',
        ],
        2 => [
            'name' => 'Decoding Accuracy',
            'code' => 'DECODING',
            'description' => 'Struggles to decode words correctly',
        ],
        3 => [
            'name' => 'Oral Reading Fluency',
            'code' => 'FLUENCY',
            'description' => 'Reads slowly or without expression',
        ],
        4 => [
            'name' => 'Reading Comprehension',
            'code' => 'COMPREHENSION',
            'description' => 'Difficulty understanding text meaning',
        ],
    ],

    // Grade levels
    'grade_levels' => [
        1 => 'Grade 1',
        2 => 'Grade 2',
        3 => 'Grade 3',
        4 => 'Grade 4',
        5 => 'Grade 5',
        6 => 'Grade 6',
        7 => 'Grade 7',
        8 => 'Grade 8',
        9 => 'Grade 9',
        10 => 'Grade 10',
        11 => 'Grade 11',
        12 => 'Grade 12',
    ],

    // User roles (now includes student)
    'roles' => [
        'admin' => 'Administrator',
        'teacher' => 'Teacher',
        'parent' => 'Parent/Guardian',
        'student' => 'Student/Learner',
    ],

    // Words per minute benchmarks by grade
    'wpm_benchmarks' => [
        1 => ['min' => 30, 'target' => 60, 'advanced' => 80],
        2 => ['min' => 50, 'target' => 90, 'advanced' => 110],
        3 => ['min' => 70, 'target' => 110, 'advanced' => 130],
        4 => ['min' => 90, 'target' => 120, 'advanced' => 145],
        5 => ['min' => 100, 'target' => 130, 'advanced' => 155],
        6 => ['min' => 110, 'target' => 140, 'advanced' => 165],
        7 => ['min' => 115, 'target' => 150, 'advanced' => 175],
        8 => ['min' => 120, 'target' => 155, 'advanced' => 180],
        9 => ['min' => 125, 'target' => 160, 'advanced' => 185],
        10 => ['min' => 130, 'target' => 165, 'advanced' => 190],
        11 => ['min' => 130, 'target' => 170, 'advanced' => 195],
        12 => ['min' => 130, 'target' => 175, 'advanced' => 200],
    ],
];
