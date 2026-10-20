<?php

namespace Database\Seeders;

use App\Models\ComprehensionQuestion;
use App\Models\ReadingMaterial;
use Illuminate\Database\Seeder;

/**
 * Short Grade 3-6 passages, English and Filipino, whose text carries the
 * notation that used to be scored as miscues even when the child read it
 * perfectly:
 *
 *   NUMERALS      the passage writes "21st" or "50" where a reader says
 *                 "twenty-first" or "fifty" - and the speech engine may write
 *                 either form, whichever the passage uses
 *   HYPHENS       "sari-sari", "mag-aaral", "well-known": read as two words,
 *                 written as one
 *   APOSTROPHES   a curly U+2019 out of Word, where the engine writes a
 *                 straight one: "Lito's", "dalawampu't"
 *   INVISIBLES    a non-breaking space (U+00A0) instead of a space, which is
 *                 what a Word or PDF paste leaves behind
 *   LOOK-ALIKES   a Cyrillic "A" (U+0410) inside an otherwise ordinary word.
 *                 This is the one that reads as a capital-letter bug on screen
 *                 ("Ang" against "ang"), because the two words look identical
 *                 and are not the same characters.
 *
 * Every hazard is written as a \u{...} escape, never as an invisible byte, so
 * the next person to open this file can see what is in the passage. Each
 * passage records its hazards in `source`.
 *
 * Every passage is 50 words or fewer. Safe to run more than once: a passage is
 * matched by title, language and grade and is never duplicated.
 *
 *   php artisan db:seed --class=NotationMaterialSeeder
 */
class NotationMaterialSeeder extends Seeder
{
    public const MAX_WORDS = 50;

    public function run(): void
    {
        foreach ($this->passages() as $p) {
            $material = ReadingMaterial::firstOrCreate(
                ['title' => $p['title'], 'language' => $p['language'], 'grade_level' => $p['grade']],
                [
                    'content' => $p['content'],
                    'difficulty' => $p['grade'] <= 3 ? 'easy' : ($p['grade'] >= 6 ? 'hard' : 'medium'),
                    'word_count' => self::countWords($p['content']),
                    'category' => $p['category'] ?? 'narrative',
                    'type' => empty($p['questions']) ? ReadingMaterial::TYPE_ORAL_READING : ReadingMaterial::TYPE_COMPREHENSION,
                    'source' => 'Original - notation test (' . $p['hazards'] . ')',
                    'is_active' => true,
                ]
            );

            if (! $material->wasRecentlyCreated || empty($p['questions'])) {
                continue;
            }

            foreach ($p['questions'] as $i => [$question, $type, $correct, $wrong]) {
                // Spread the right answer over A-D instead of always putting it first.
                $options = $wrong;
                array_splice($options, $i % 4, 0, [$correct]);

                ComprehensionQuestion::create([
                    'material_id' => $material->id,
                    'question' => $question,
                    'question_type' => $type,
                    'correct_answer' => $correct,
                    'option_a' => $options[0],
                    'option_b' => $options[1],
                    'option_c' => $options[2],
                    'option_d' => $options[3],
                    'sort_order' => $i + 1,
                ]);
            }
        }
    }

    public static function countWords(string $text): int
    {
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /** @return array<int, array<string, mixed>> */
    public function passages(): array
    {
        return array_merge($this->oralReading(), $this->comprehension());
    }

    /** Five per language, spread over Grades 3-6. No comprehension questions. */
    public function oralReading(): array
    {
        return [
            // ── English ──
            ['grade' => 3, 'language' => 'en', 'title' => 'Coins for Pan de Sal',
                'hazards' => 'numerals, hyphenated compound',
                'content' => "Our sari-sari store opens at 6 in the morning. Aling Nena sells pan de sal for 3 pesos each. My brother and I bring 20 pesos every Monday. We buy six pieces and share them on the way to school."],

            ['grade' => 4, 'language' => 'en', 'title' => 'The 21st of June',
                'hazards' => 'ordinal numerals, numerals',
                'content' => "Our class planted trees on the 21st of June. We were the 3rd group to finish. Each pupil carried 2 small seedlings to the open field. Our teacher counted 48 trees in all. She said they will give shade by the time we reach Grade 6."],

            ['grade' => 5, 'language' => 'en', 'title' => "The Town's Old Marker",
                'hazards' => 'curly apostrophe, hyphenated compounds, non-breaking space',
                'content' => "Our town\u{2019}s plaza has a well-known stone marker. It honors a brave farmer who warned the people before a flood. Long-ago travellers rested in its shade. Today, children read the\u{00A0}marker after school and learn his story."],

            ['grade' => 6, 'language' => 'en', 'title' => 'The Twenty-First Century',
                'hazards' => 'hyphenated compound read as two words',
                'content' => "We live in the twenty-first century. Machines help farmers plant and harvest. Mobile phones carry news across the country in seconds. Still, our teacher reminds us that reading remains the most useful skill. A pupil who reads well can learn almost anything."],

            ['grade' => 6, 'language' => 'en', 'title' => 'A Ten-Minute Habit',
                'hazards' => 'Cyrillic look-alike letter, hyphenated compound, numerals',
                'content' => "\u{0410} ten-minute habit can change a whole school year. Read one page after supper, then another the next night. In 30 days you will have finished a short book. Reading is not a race, so keep a slow and steady pace."],

            // ── Filipino ──
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang Tindahan ni Lola',
                'hazards' => 'Cyrillic look-alike letter at the start, numerals, hyphenated compound',
                'content' => "\u{0410}ng tindahan ni Lola ay bukas tuwing umaga. May tinapay siyang 3 piso at kendi na 1 piso. Tuwing Sabado, binibigyan niya kami ng 10 piso. Masaya kaming bumibili ng sari-sari niyang paninda."],

            ['grade' => 4, 'language' => 'fil', 'title' => 'Masipag na Mag-aaral',
                'hazards' => 'hyphenated compounds, numerals',
                'content' => "Si Ella ay mag-aaral sa Baitang 4. Tuwing hapon, nagbabasa siya ng 2 pahina ng kuwento. Tuwang-tuwa ang kaniyang ina sa kaniyang sipag. Sa loob ng 30 araw, natapos niya ang isang maliit na aklat."],

            ['grade' => 5, 'language' => 'fil', 'title' => "Dalawampu't Isang Puno",
                'hazards' => 'curly apostrophe, numerals, non-breaking space',
                'content' => "May 21 punong mangga sa likod ng aming paaralan. Ang dalawampu\u{2019}t isang puno ay itinanim ng mga magulang noong nakaraang taon. Dinidiligan namin ang\u{00A0}mga ito bawat umaga. Malilim na ngayon ang buong bakuran."],

            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Ika-21 na Siglo',
                'hazards' => 'ordinal with hyphen and numerals',
                'content' => "Nasa ika-21 na siglo na tayo ngayon. Ang mga makina ay tumutulong sa pagsasaka. Ang telepono ay nagdadala ng balita sa loob ng ilang segundo. Ngunit ayon sa aming guro, ang pagbasa pa rin ang pinakamahalagang kasanayan."],

            ['grade' => 3, 'language' => 'fil', 'title' => 'Tuwing Ika-5 ng Hapon',
                'hazards' => 'ordinal with hyphen, numerals, hyphenated compound',
                'content' => "Tuwing ika-5 ng hapon, naglalaro kami sa bakuran. May 8 kaming magkakaibigan sa aming purok. Nagtatakbuhan kami hanggang sa dumilim. Pagkatapos, naghihilamos kami at naghahanda sa pag-aaral kinabukasan."],
        ];
    }

    /** Five per language, spread over Grades 3-6, each with four questions. */
    public function comprehension(): array
    {
        return [
            // ── English ──
            ['grade' => 3, 'language' => 'en', 'title' => "Nita's 50 Pesos",
                'hazards' => 'numerals, curly apostrophe',
                'content' => "Nita saved 50 pesos from her allowance. On Saturday she walked to the market with her mother. She wanted a toy, but she saw her mother\u{2019}s empty basket. Nita bought rice instead. Her mother hugged her and said she had a kind heart.",
                'questions' => [
                    ['How much did Nita save?', 'literal', '50 pesos', ['15 pesos', '5 pesos', '500 pesos']],
                    ['Where did Nita go on Saturday?', 'literal', 'To the market', ['To school', 'To the park', 'To her aunt']],
                    ['What did Nita buy?', 'literal', 'Rice', ['A toy', 'A basket', 'Candy']],
                    ['Why did Nita change her mind?', 'inferential', 'She saw her family needed food', ['The toy was too costly', 'The store was closed', 'She lost her money']],
                ]],

            ['grade' => 4, 'language' => 'en', 'title' => 'The 3rd Bell',
                'hazards' => 'ordinal numerals, hyphenated compounds',
                'content' => "At our school, the 3rd bell means the gate will close. Jun-Jun ran the last 100 meters and reached the gate just in time. His well-worn shoes were covered in dust. He sat down, caught his breath, and promised to leave home earlier.",
                'questions' => [
                    ['What does the 3rd bell mean?', 'literal', 'The gate will close', ['Classes are over', 'Recess has started', 'A visitor has arrived']],
                    ['How far did Jun-Jun run?', 'literal', '100 meters', ['10 meters', '1 kilometer', '50 meters']],
                    ['What did Jun-Jun promise?', 'literal', 'To leave home earlier', ['To buy new shoes', 'To run every day', 'To ask for a ride']],
                    ['How did Jun-Jun most likely feel at the gate?', 'inferential', 'Relieved', ['Bored', 'Angry', 'Sleepy']],
                ]],

            ['grade' => 5, 'language' => 'en', 'title' => 'The Well-Kept Notebook',
                'hazards' => 'hyphenated compounds, non-breaking space, numerals',
                'content' => "Mila keeps a well-kept notebook of plant names. She has written 64 of them since Grade 3. Each page has a drawing and the date she found\u{00A0}the plant. Her hand-drawn maps show where each one grows. Her teacher calls it real science.",
                'questions' => [
                    ['What does Mila collect in her notebook?', 'literal', 'Plant names', ['Stamps', 'Bird songs', 'Old coins']],
                    ['How many has she written?', 'literal', '64', ['46', '640', '6']],
                    ['What is on each page besides the name?', 'literal', 'A drawing and the date', ['A photograph', 'A poem', 'A price']],
                    ['Why does her teacher call it real science?', 'evaluative', 'She records careful observations', ['She copied a textbook', 'She drew pretty pictures', 'She finished it quickly']],
                ]],

            ['grade' => 6, 'language' => 'en', 'title' => 'Twenty-First Century Farmers',
                'hazards' => 'hyphenated compound, year and other numerals, Cyrillic look-alike letter',
                'content' => "Twenty-first century farmers read the weather on a phone. \u{0410}fter a warning, they harvest early and save the crop. In 2019, a storm ruined the rice of our village. Today the same warning arrives 3 days ahead, and the harvest is saved.",
                'questions' => [
                    ['How do these farmers read the weather?', 'literal', 'On a phone', ['In a newspaper', 'From the radio only', 'From a calendar']],
                    ['What happened in 2019?', 'literal', 'A storm ruined the rice', ['The village moved', 'The harvest doubled', 'A new school opened']],
                    ['How early does the warning arrive today?', 'literal', '3 days ahead', ['3 hours ahead', '30 days ahead', 'On the same day']],
                    ['What is the main idea of the passage?', 'inferential', 'Early information protects the harvest', ['Farming is easy now', 'Phones are expensive', 'Storms have stopped']],
                ]],

            ['grade' => 5, 'language' => 'en', 'title' => "The Driver's 2 Rules",
                'hazards' => 'curly apostrophe, numerals, hyphenated compound',
                'content' => "Mang Ben, a tricycle driver, follows 2 rules. First, never rush a passenger who is old. Second, always return the exact change. His hard-earned savings are small, but the whole barangay trusts him. Last week, a teacher\u{2019}s lost bag was returned the same hour.",
                'questions' => [
                    ['How many rules does Mang Ben follow?', 'literal', '2', ['4', '10', '1']],
                    ['What is his second rule?', 'literal', 'Always return the exact change', ['Drive slowly at night', 'Never work on Sunday', 'Carry only two passengers']],
                    ['What was returned last week?', 'literal', "A teacher's lost bag", ['A wallet of coins', 'A tricycle key', 'A school bell']],
                    ['Why does the barangay trust him?', 'inferential', 'He is honest and considerate', ['He drives the fastest', 'He is the oldest driver', 'He charges nothing']],
                ]],

            // ── Filipino ──
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang 10 Piso ni Ben',
                'hazards' => 'numerals, hyphenated compound',
                'content' => "May 10 piso si Ben na ibinigay ng kaniyang lolo. Pumunta siya sa sari-sari store upang bumili ng tinapay. Nakita niyang umiiyak ang kaniyang kaibigan dahil nawala ang baon nito. Ibinahagi ni Ben ang tinapay. Ngumiti silang dalawa.",
                'questions' => [
                    ['Magkano ang ibinigay ng lolo ni Ben?', 'literal', '10 piso', ['1 piso', '100 piso', '50 piso']],
                    ['Saan pumunta si Ben?', 'literal', 'Sa sari-sari store', ['Sa palengke', 'Sa paaralan', 'Sa simbahan']],
                    ['Bakit umiiyak ang kaibigan ni Ben?', 'literal', 'Nawala ang baon nito', ['Nasugatan ito', 'Nahuli ito sa klase', 'Nawalan ito ng lapis']],
                    ['Anong ugali ang ipinakita ni Ben?', 'inferential', 'Mapagbigay', ['Madamot', 'Mayabang', 'Tamad']],
                ]],

            ['grade' => 4, 'language' => 'fil', 'title' => 'Ika-4 na Baitang',
                'hazards' => 'ordinal with hyphen, numerals, hyphenated compound',
                'content' => "Nasa ika-4 na baitang si Lito. Tuwing umaga, naglalakad siya ng 2 kilometro papuntang paaralan. Hindi siya lumiliban kahit umuulan. Tuwang-tuwa ang kaniyang guro dahil hindi siya nahuhuli. Sa pagtatapos, siya ang pinakamasipag sa klase.",
                'questions' => [
                    ['Anong baitang si Lito?', 'literal', 'Ika-4 na baitang', ['Ika-2 na baitang', 'Ika-6 na baitang', 'Ika-1 na baitang']],
                    ['Gaano kalayo ang nilalakad niya?', 'literal', '2 kilometro', ['20 kilometro', 'Kalahating kilometro', '5 kilometro']],
                    ['Bakit tuwang-tuwa ang guro niya?', 'literal', 'Hindi siya nahuhuli', ['Magaling siyang kumanta', 'Mayaman ang pamilya niya', 'Malakas siya sa isports']],
                    ['Anong katangian ang mahihinuha kay Lito?', 'inferential', 'Matiyaga', ['Pabaya', 'Mahiyain', 'Maingay']],
                ]],

            ['grade' => 5, 'language' => 'fil', 'title' => "Ang Dalawampu't Limang Itlog",
                'hazards' => 'curly apostrophe, numerals',
                'content' => "Nakapulot si Aling Rosa ng 25 itlog mula sa kaniyang mga inahin. Ang dalawampu\u{2019}t limang itlog ay inilagay niya sa basket. Ipinagbili niya ang 20 at itinira ang 5 para sa almusal. Sa gabi, masarap ang hapunan ng buong pamilya.",
                'questions' => [
                    ['Ilang itlog ang napulot ni Aling Rosa?', 'literal', '25', ['15', '52', '5']],
                    ['Ilan ang ipinagbili niya?', 'literal', '20', ['25', '5', '10']],
                    ['Para saan ang 5 itlog?', 'literal', 'Para sa almusal', ['Para sa kapitbahay', 'Para ipamigay', 'Para sa palengke']],
                    ['Bakit masarap ang hapunan nila?', 'inferential', 'May kinita at may natirang pagkain', ['May handaan sa purok', 'Bumili sila ng karne', 'Nagluto ang kapitbahay']],
                ]],

            ['grade' => 6, 'language' => 'fil', 'title' => "Ang Ika-21 na Siglong Mag-aaral",
                'hazards' => 'ordinal with hyphen, hyphenated compounds, non-breaking space, numerals',
                'content' => "Ang mag-aaral sa ika-21 na siglo ay may telepono at aklat. Sa loob ng 5 minuto, mahahanap niya ang sagot sa\u{00A0}internet. Ngunit ayon sa aming guro, kailangan pa rin ng pag-iisip. Ang makina ay nagbibigay ng datos, hindi ng unawa.",
                'questions' => [
                    ['Ano ang mayroon ang mag-aaral sa ika-21 na siglo?', 'literal', 'Telepono at aklat', ['Makina at bisikleta', 'Papel at lapis lamang', 'Radyo at telebisyon']],
                    ['Gaano katagal bago makahanap ng sagot?', 'literal', '5 minuto', ['50 minuto', '1 oras', '5 segundo']],
                    ['Ayon sa guro, ano ang kailangan pa rin?', 'literal', 'Pag-iisip', ['Mas mabilis na internet', 'Mas maraming aklat', 'Mas mahabang klase']],
                    ['Ano ang ibig sabihin ng datos na walang unawa?', 'evaluative', 'Ang impormasyon ay hindi kaparehas ng pagkaunawa', ['Mali ang internet', 'Walang saysay ang makina', 'Dapat ipagbawal ang telepono']],
                ]],

            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Bagyo ng 2019',
                'hazards' => 'year as numerals, numerals, Cyrillic look-alike letter',
                'content' => "Noong 2019, may malakas na bagyong dumaan sa aming bayan. \u{0410}ng tubig ay umabot sa 2 talampakan sa loob ng aming bahay. Nagtulungan ang mga kapitbahay sa paglilinis. Pagkalipas ng 3 araw, muli kaming nakapasok sa paaralan.",
                'questions' => [
                    ['Kailan dumaan ang bagyo?', 'literal', 'Noong 2019', ['Noong 2009', 'Noong 2021', 'Noong nakaraang linggo']],
                    ['Gaano kataas ang tubig sa bahay?', 'literal', '2 talampakan', ['2 metro', '20 talampakan', 'Kalahating talampakan']],
                    ['Sino ang nagtulungan sa paglilinis?', 'literal', 'Ang mga kapitbahay', ['Ang mga sundalo', 'Ang mga guro', 'Ang mga bisita']],
                    ['Ano ang ipinapakita ng kuwento tungkol sa purok?', 'inferential', 'Mapagtulungan ang mga tao', ['Mayaman ang bayan', 'Walang tumulong', 'Madalas ang bagyo']],
                ]],
        ];
    }
}
