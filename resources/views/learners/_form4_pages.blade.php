{{-- Phil-IRI Form 4 pages. Expects $form (Form4Builder). Page 1 is filled from assessments; page 2 is a blank grid. --}}
@php
    $behaviors = [
        'Does word-by-word reading (Nagbabasa nang pa-isa isang salita)',
        'Lacks expression; reads in a monotonous tone (Walang damdamin; walang pagbabago ang tono)',
        'Voice is hardly audible (Hindi madaling marinig ang boses)',
        'Disregards punctuation (Hindi pinapansin ang mga bantas)',
        'Points to each word with his/her finger (Itinuturo ang bawat salita)',
        'Employs little or no method of analysis (Bahagya o walang paraan ng pagsusuri)',
    ];
    $mark = fn ($v, $want) => $v === $want ? '&#10003;' : '';
@endphp

@foreach($form['languages'] as $code => $lang)
    {{-- ───── Page 1 ───── --}}
    <div class="f4-page">
        <div class="f4-no">Phil-IRI Form 4, Page 1 of 2 &middot; {{ $lang['label'] }}</div>
        <h1>Individual Summary Record (ISR)</h1>
        <h2>Talaan ng Indibidwal na Pagbabasa (TIP)</h2>

        <table class="f4-head" style="margin-bottom: 6px;">
            <tr>
                <td style="width: 45%;"><strong>Name:</strong> {{ $form['name'] }}</td>
                <td style="width: 15%;"><strong>Age:</strong> {{ $form['age'] ?? '' }}</td>
                <td><strong>Grade / Section:</strong> {{ $form['grade'] }}{{ $form['section'] ? ' / ' . $form['section'] : '' }}</td>
            </tr>
            <tr>
                <td><strong>School:</strong> {{ $form['school'] }}</td>
                <td></td>
                <td><strong>Teacher:</strong> {{ $form['teacher'] }}</td>
            </tr>
            <tr>
                <td colspan="3"><strong>Language of assessment:</strong> {{ $lang['label'] }}</td>
            </tr>
        </table>

        <table class="f4-grid">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 8%;">Level<br>Started<br><span style="font-weight: normal;">(mark with *)</span></th>
                    <th rowspan="2" style="width: 6%;">Level</th>
                    <th rowspan="2" style="width: 9%;">Set<br><span style="font-weight: normal;">A, B, C or D</span></th>
                    <th colspan="3">Word Reading</th>
                    <th colspan="3">Comprehension</th>
                    <th rowspan="2" style="width: 14%;">Date Taken</th>
                </tr>
                <tr>
                    <th>Ind</th><th>Ins</th><th>Frus</th>
                    <th>Ind</th><th>Ins</th><th>Frus</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lang['levels'] as $row)
                    <tr>
                        <td>{{ $row['started'] ? '*' : '' }}</td>
                        <td><strong>{{ $row['roman'] }}</strong></td>
                        <td></td>
                        <td>{!! $mark($row['word'], 'independent') !!}</td>
                        <td>{!! $mark($row['word'], 'instructional') !!}</td>
                        <td>{!! $mark($row['word'], 'frustration') !!}</td>
                        <td></td><td></td><td></td>
                        <td>{{ $row['date'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="f4-note">
            Legend: Ind &ndash; Independent; Ins &ndash; Instructional; Frus &ndash; Frustration.
            Word reading is filled from the recorded oral reading (Independent 97% and above, Instructional 90&ndash;96%, Frustration below 90%).
            Set and Comprehension are completed by the teacher.
        </div>

        <table class="f4-grid" style="margin-top: 8px;">
            <thead>
                <tr>
                    <th class="f4-left">Oral Reading Observation Checklist<br><span style="font-weight: normal;">Talaan ng mga Puna Habang Nagbabasa</span></th>
                    <th style="width: 10%;">&#10003; or X</th>
                </tr>
            </thead>
            <tbody>
                <tr><td class="f4-left"><strong>Behaviors while Reading (Paraan ng Pagbabasa)</strong></td><td></td></tr>
                @foreach($behaviors as $b)
                    <tr><td class="f4-left">{{ $b }}</td><td></td></tr>
                @endforeach
                <tr><td class="f4-left">Other observations: (Ibang Puna)</td><td></td></tr>
                <tr><td class="f4-left f4-blank" colspan="2"></td></tr>
            </tbody>
        </table>
    </div>

    {{-- ───── Page 2 (blank grid, completed by hand) ───── --}}
    <div class="f4-page">
        <div class="f4-no">Phil-IRI Form 4, Page 2 of 2 &middot; {{ $lang['label'] }}</div>
        <h1>Individual Summary Record (ISR)</h1>
        <h2>Summary of Comprehension Responses (Talaan ng Pag-Unawa) &middot; {{ $lang['label'] }}</h2>

        <table class="f4-grid">
            <thead>
                <tr>
                    <th rowspan="3">Passage<br>Level</th>
                    <th colspan="13">Pre-Test <span style="font-weight: normal;">(Panimulang Pagtatasa)</span></th>
                    <th colspan="13">Post-Test <span style="font-weight: normal;">(Panapos na Pagtatasa)</span></th>
                </tr>
                <tr>
                    @foreach(['pre', 'post'] as $p)
                        <th colspan="8">Responses to Questions</th>
                        <th colspan="3">Score per Type</th>
                        <th rowspan="2">Score</th>
                        <th rowspan="2">Level</th>
                    @endforeach
                </tr>
                <tr>
                    @foreach(['pre', 'post'] as $p)
                        @foreach(range(1, 8) as $q) <th>Q{{ $q }}</th> @endforeach
                        <th>L</th><th>I</th><th>C</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice(\App\Services\PhilIriLevels::LEVELS, 1, null, true) as $roman)
                    <tr class="f4-blank">
                        <td><strong>{{ $roman }}</strong></td>
                        @for($i = 0; $i < 26; $i++) <td>&nbsp;</td> @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="f4-note">
            L = Literal, I = Inferential, C = Critical. Legend: Ind &ndash; Independent; Ins &ndash; Instructional; Frus &ndash; Frustration.
            This page is completed by the teacher.
        </div>
    </div>
@endforeach
