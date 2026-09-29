<?php

namespace Database\Seeders;

use App\Models\PracticeItem;
use Illuminate\Database\Seeder;

class PracticeItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [];

        // Sight Words - English
        $sightWordsEn = [
            1 => ['the', 'and', 'a', 'to', 'said', 'in', 'he', 'it', 'of', 'was', 'she', 'for', 'that', 'is', 'his', 'but', 'they', 'my', 'are', 'with'],
            2 => ['would', 'make', 'like', 'him', 'has', 'her', 'some', 'then', 'could', 'them', 'very', 'when', 'what', 'your', 'been', 'their', 'its', 'over', 'just', 'also'],
            3 => ['about', 'never', 'going', 'before', 'always', 'because', 'around', 'should', 'better', 'between', 'different', 'important', 'another', 'together', 'through'],
            4 => ['children', 'animals', 'country', 'something', 'thought', 'question', 'example', 'family', 'begin', 'answer', 'school', 'letter', 'number', 'people', 'water'],
            5 => ['again', 'change', 'different', 'important', 'children', 'animals', 'country', 'something', 'thought', 'question', 'example', 'family', 'begin', 'answer'],
            6 => ['beautiful', 'careful', 'dangerous', 'enormous', 'famous', 'generous', 'handsome', 'innocent', 'jealous', 'kind', 'lively', 'magnificent', 'numerous', 'obedient'],
        ];

        foreach ($sightWordsEn as $grade => $words) {
            foreach ($words as $word) {
                $items[] = [
                    'type' => 'sight_word',
                    'content' => $word,
                    'grade_level' => $grade,
                    'language' => 'en',
                    'metadata' => null,
                    'is_active' => true,
                ];
            }
        }

        // Sight Words - Filipino
        $sightWordsFil = [
            1 => ['ang', 'ng', 'sa', 'ay', 'mga', 'ko', 'mo', 'siya', 'kami', 'tayo', 'nila', 'amin', 'inyo', 'dito', 'doon', 'ano', 'sino', 'saan', 'kailano', 'bakit'],
            2 => ['maganda', 'masaya', 'malaki', 'maliit', 'mataas', 'mababa', 'mahaba', 'maikli', 'bago', 'luma', 'mabuti', 'masama', 'mabilis', 'mabagal', 'malakas', 'mahina'],
            3 => ['araw', 'buwan', 'taon', 'oras', 'minuto', 'segundo', 'kahapon', 'ngayon', 'bukas', 'umaga', 'tanghali', 'hapon', 'gabi', 'linggo', 'buwan', 'taon'],
        ];

        foreach ($sightWordsFil as $grade => $words) {
            foreach ($words as $word) {
                $items[] = [
                    'type' => 'sight_word',
                    'content' => $word,
                    'grade_level' => $grade,
                    'language' => 'fil',
                    'metadata' => null,
                    'is_active' => true,
                ];
            }
        }

        // Phonemic Sounds - English
        $phonemicEn = [
            1 => [
                ['content' => '/b/', 'metadata' => ['sound' => 'b', 'example' => 'ball', 'options' => ['ball', 'cat', 'dog', 'fish']]],
                ['content' => '/k/', 'metadata' => ['sound' => 'k', 'example' => 'cat', 'options' => ['cat', 'ball', 'dog', 'fish']]],
                ['content' => '/d/', 'metadata' => ['sound' => 'd', 'example' => 'dog', 'options' => ['dog', 'ball', 'cat', 'fish']]],
                ['content' => '/f/', 'metadata' => ['sound' => 'f', 'example' => 'fish', 'options' => ['fish', 'ball', 'cat', 'dog']]],
            ],
            2 => [
                ['content' => '/ch/', 'metadata' => ['sound' => 'ch', 'example' => 'chair', 'options' => ['chair', 'table', 'book', 'pen']]],
                ['content' => '/sh/', 'metadata' => ['sound' => 'sh', 'example' => 'ship', 'options' => ['ship', 'car', 'bus', 'train']]],
                ['content' => '/th/', 'metadata' => ['sound' => 'th', 'example' => 'think', 'options' => ['think', 'sink', 'wink', 'link']]],
            ],
        ];

        foreach ($phonemicEn as $grade => $sounds) {
            foreach ($sounds as $sound) {
                $items[] = [
                    'type' => 'phonemic_sound',
                    'content' => $sound['content'],
                    'grade_level' => $grade,
                    'language' => 'en',
                    'metadata' => $sound['metadata'],
                    'is_active' => true,
                ];
            }
        }

        // Rhyming Words - English
        $rhymesEn = [
            1 => [
                ['content' => 'cat', 'metadata' => ['correct' => 'hat', 'options' => ['hat', 'dog', 'sun', 'cup']]],
                ['content' => 'dog', 'metadata' => ['correct' => 'log', 'options' => ['log', 'cat', 'sun', 'cup']]],
                ['content' => 'sun', 'metadata' => ['correct' => 'fun', 'options' => ['fun', 'cat', 'dog', 'cup']]],
            ],
            2 => [
                ['content' => 'make', 'metadata' => ['correct' => 'cake', 'options' => ['cake', 'book', 'tree', 'fish']]],
                ['content' => 'tree', 'metadata' => ['correct' => 'bee', 'options' => ['bee', 'book', 'fish', 'cup']]],
            ],
        ];

        foreach ($rhymesEn as $grade => $rhymes) {
            foreach ($rhymes as $rhyme) {
                $items[] = [
                    'type' => 'rhyme',
                    'content' => $rhyme['content'],
                    'grade_level' => $grade,
                    'language' => 'en',
                    'metadata' => $rhyme['metadata'],
                    'is_active' => true,
                ];
            }
        }

        // Syllable Counting - English
        $syllablesEn = [
            1 => [
                ['content' => 'cat', 'metadata' => ['syllables' => 1]],
                ['content' => 'dog', 'metadata' => ['syllables' => 1]],
                ['content' => 'butterfly', 'metadata' => ['syllables' => 3]],
                ['content' => 'elephant', 'metadata' => ['syllables' => 3]],
            ],
            2 => [
                ['content' => 'computer', 'metadata' => ['syllables' => 3]],
                ['content' => 'banana', 'metadata' => ['syllables' => 3]],
                ['content' => 'tomorrow', 'metadata' => ['syllables' => 3]],
            ],
        ];

        foreach ($syllablesEn as $grade => $words) {
            foreach ($words as $word) {
                $items[] = [
                    'type' => 'syllable',
                    'content' => $word['content'],
                    'grade_level' => $grade,
                    'language' => 'en',
                    'metadata' => $word['metadata'],
                    'is_active' => true,
                ];
            }
        }

        // Blend Sounds - English
        $blendsEn = [
            1 => [
                ['content' => 'bl', 'metadata' => ['blend' => 'bl', 'example' => 'blue', 'options' => ['blue', 'red', 'green', 'yellow']]],
                ['content' => 'br', 'metadata' => ['blend' => 'br', 'example' => 'brown', 'options' => ['brown', 'blue', 'green', 'yellow']]],
                ['content' => 'cl', 'metadata' => ['blend' => 'cl', 'example' => 'clap', 'options' => ['clap', 'run', 'jump', 'sit']]],
            ],
            2 => [
                ['content' => 'cr', 'metadata' => ['blend' => 'cr', 'example' => 'crab', 'options' => ['crab', 'fish', 'bird', 'cat']]],
                ['content' => 'dr', 'metadata' => ['blend' => 'dr', 'example' => 'drum', 'options' => ['drum', 'bell', 'guitar', 'piano']]],
            ],
        ];

        foreach ($blendsEn as $grade => $blends) {
            foreach ($blends as $blend) {
                $items[] = [
                    'type' => 'blend',
                    'content' => $blend['content'],
                    'grade_level' => $grade,
                    'language' => 'en',
                    'metadata' => $blend['metadata'],
                    'is_active' => true,
                ];
            }
        }

        foreach ($items as $item) {
            PracticeItem::create($item);
        }
    }
}
