{{--
    Reading comparison.

    Left: the passage exactly as written, no marking at all.
    Right: the same passage as the child read it — every word shown, coloured by
    what happened to it. Skipped words stay in place (in yellow) so the two
    sides line up and nothing is hidden.

    Each word carries a `miscue` (see MiscueClassifierService); results analysed
    before that existed only have the four-way `status`, used as the fallback.
--}}
@php
    $types = [
        'mispronunciation' => ['label' => 'Mispronounced',   'class' => 'w-mis',  'hint' => 'Said a word that sounds like the right one'],
        'substitution'     => ['label' => 'Different word',  'class' => 'w-sub',  'hint' => 'Said a different word instead'],
        'omission'         => ['label' => 'Skipped',         'class' => 'w-omit', 'hint' => 'Left the word out'],
        'not_reached'      => ['label' => 'Not reached',     'class' => 'w-nr',   'hint' => 'The reading stopped before these words'],
        'insertion'        => ['label' => 'Added',           'class' => 'w-ins',  'hint' => 'Said a word that is not in the passage'],
        'repetition'       => ['label' => 'Repeated',        'class' => 'w-rep',  'hint' => 'Said the word again, or started it twice'],
        'self_correction'  => ['label' => 'Self-corrected',  'class' => 'w-sc',   'hint' => 'Made a mistake and fixed it — not counted as an error'],
        'off_passage'      => ['label' => 'Not reading',     'class' => 'w-off',  'hint' => 'Talking, a title, or another voice — not counted as an error'],
    ];

    $tally = array_fill_keys(array_merge(array_keys($types), ['correct', 'hesitation', 'long_pause', 'unclear']), 0);

    foreach ($comparison as $w) {
        $kind = $w['miscue'] ?? $w['status'] ?? 'correct';
        $tally[$kind] = ($tally[$kind] ?? 0) + 1;

        if (isset($w['pause_kind'])) {
            $tally[$w['pause_kind']]++;
        }
        if (!empty($w['unclear'])) {
            $tally['unclear']++;
        }
    }

    $pauses = $tally['hesitation'] + $tally['long_pause'];
    $anyIssue = collect($types)->keys()->contains(fn ($k) => $tally[$k] > 0) || $pauses > 0 || $tally['unclear'] > 0;

    // The passage as written, with its capitals and punctuation. Falls back to
    // the scored words if the material has since been deleted.
    $passage = $assessment->material?->content
        ?? implode(' ', array_filter(array_column($comparison, 'reference'), fn ($w) => $w !== null));
@endphp

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-2"><i class="bi bi-file-diff me-1"></i> Reading Comparison</h6>

        {{-- Key: only what actually happened in this reading --}}
        <div class="d-flex flex-wrap gap-x-3 gap-y-1 comparison-key">
            @if(!$anyIssue)
                <span class="text-success small"><i class="bi bi-check-circle me-1"></i>Every word was read as written.</span>
            @endif

            @foreach($types as $key => $type)
                @if($tally[$key] > 0)
                    <span class="key-chip" title="{{ $type['hint'] }}">
                        <span class="key-swatch {{ $type['class'] }}"></span>{{ $type['label'] }} <b>{{ $tally[$key] }}</b>
                    </span>
                @endif
            @endforeach

            @if($pauses > 0)
                <span class="key-chip" title="A silence of one second or more before a word">
                    <span class="w-pause">&#10073;&#10073;</span>Pause <b>{{ $pauses }}</b>
                </span>
            @endif

            @if($tally['unclear'] > 0)
                <span class="key-chip" title="The speech engine wasn't sure it heard this word correctly. Play the recording to check.">
                    <span class="w-unclear key-sample">abc</span>Unsure how it sounded <b>{{ $tally['unclear'] }}</b>
                </span>
            @endif
        </div>
    </div>

    @if(empty($comparison))
        <div class="card-body">
            <p class="text-muted mb-0">No word-by-word comparison is available for this assessment.</p>
        </div>
    @else
        <div class="row g-0">
            {{-- Left: the passage, untouched --}}
            <div class="col-md-6 border-end">
                <div class="px-3 py-2 bg-light border-bottom">
                    <span class="small fw-semibold text-muted text-uppercase">Original Passage</span>
                </div>
                <div class="p-3 reading-pane">{{ $passage }}</div>
            </div>

            {{-- Right: what the child read, every word shown --}}
            <div class="col-md-6">
                <div class="px-3 py-2 bg-light border-bottom">
                    <span class="small fw-semibold text-muted text-uppercase">What the Child Read</span>
                </div>
                <div class="p-3 reading-pane">
                    @foreach($comparison as $word)
                        @php
                            $kind = $word['miscue'] ?? $word['status'] ?? 'correct';
                            $unclear = !empty($word['unclear']) ? ' w-unclear' : '';
                            // The word to print: what was said, or — for words the child
                            // didn't say — the passage word that went unsaid.
                            $heard = $word['spoken'] ?? $word['reference'] ?? '?';
                            $unsureTip = !empty($word['unclear']) ? ' · The speech engine was unsure it heard this correctly' : '';
                        @endphp

                        @isset($word['pause_kind'])
                            <span class="w-pause {{ $word['pause_kind'] === 'long_pause' ? 'w-pause-long' : '' }}"
                                  title="{{ $word['pause_kind'] === 'long_pause' ? 'Long pause' : 'Hesitation' }}: {{ $word['pause_before'] }} seconds of silence before the next word">&#10073;&#10073; {{ $word['pause_before'] }}s</span>
                        @endisset

                        @if($kind === 'not_reached' && ($comparison[$loop->index - 1]['miscue'] ?? null) !== 'not_reached')
                            {{-- Once, where the reading ended --}}
                            <span class="w-stop" title="Nothing more was said after this point">&#9632; reading stopped here</span>
                        @endif

                        @switch($kind)
                            @case('omission')
                                <span class="w w-omit" title="Skipped — the child left this word out">{{ $word['reference'] }}</span>
                                @break
                            @case('not_reached')
                                <span class="w w-nr" title="Not reached — the reading stopped before this word">{{ $word['reference'] }}</span>
                                @break
                            @case('mispronunciation')
                                <span class="w w-mis{{ $unclear }}" title="Mispronounced — the passage says “{{ $word['reference'] }}”{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @case('substitution')
                                <span class="w w-sub{{ $unclear }}" title="Different word — the passage says “{{ $word['reference'] }}”{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @case('repetition')
                                <span class="w w-rep{{ $unclear }}" title="Repeated{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @case('self_correction')
                                <span class="w w-sc{{ $unclear }}" title="Self-corrected to “{{ $word['corrected_to'] ?? '' }}” — not counted as an error{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @case('off_passage')
                                <span class="w w-off{{ $unclear }}" title="Not part of the passage — not counted as an error{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @case('insertion')
                                <span class="w w-ins{{ $unclear }}" title="Added — not in the passage{{ $unsureTip }}">{{ $heard }}</span>
                                @break
                            @default
                                <span class="w{{ $unclear }}" @if($unclear) title="The speech engine was unsure it heard this correctly" @endif>{{ $heard }}</span>
                        @endswitch
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
