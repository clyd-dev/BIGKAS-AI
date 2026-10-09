<?php

namespace Database\Seeders;

use App\Models\ComprehensionQuestion;
use App\Models\ReadingMaterial;
use Illuminate\Database\Seeder;

/**
 * Grade 3-6 passages in English and Filipino: two for word reading and two for comprehension
 * (with four multiple-choice questions each) per grade and language.
 *
 * Every passage is kept to 50 words or fewer while the system is being tested. Safe to run more
 * than once: a passage is matched by title, language and grade and is never duplicated.
 *
 *   php artisan db:seed --class=UpperGradeMaterialSeeder
 */
class UpperGradeMaterialSeeder extends Seeder
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
                    'source' => 'Original',
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
        return [
            // ── Grade 3 · English ──
            ['grade' => 3, 'language' => 'en', 'title' => 'The Sari-Sari Store',
                'content' => 'Near our house there is a small sari-sari store. Aling Rosa sells rice, sugar, candy, and soap there. Every afternoon, children buy cold drinks after school. Aling Rosa smiles and counts the change carefully. She knows every child by name. Everyone likes her kind store.'],
            ['grade' => 3, 'language' => 'en', 'title' => 'The Big Storm',
                'content' => 'Last night, a strong wind shook our windows. Heavy rain poured on the roof. Father tied the door tightly, and Mother lit a candle. We stayed together in the living room. By morning, the storm was gone. Branches covered the yard, but everyone was safe.'],
            ['grade' => 3, 'language' => 'en', 'title' => "Lito's Garden",
                'content' => 'Lito planted beans behind his house. He watered them every morning before school. After two weeks, tiny green leaves came out. Lito was excited and told his sister. Soon the plants grew tall, and they picked many beans for dinner. Lito learned that patience helps plants grow.',
                'questions' => [
                    ['What did Lito plant?', 'literal', 'Beans', ['Corn', 'Rice', 'Carrots']],
                    ['When did Lito water the plants?', 'literal', 'Every morning before school', ['Every night', 'Only on Sundays', 'After dinner']],
                    ['What came out after two weeks?', 'literal', 'Tiny green leaves', ['Red flowers', 'Many beans', 'Yellow fruit']],
                    ['How did Lito feel when the leaves came out?', 'inferential', 'Excited', ['Angry', 'Bored', 'Afraid']],
                ]],
            ['grade' => 3, 'language' => 'en', 'title' => 'The Lost Umbrella',
                'content' => 'Ana forgot her umbrella at school. On the way home, rain began to fall. A classmate named Ben shared his umbrella with her. They walked together and talked about their lessons. The next day, Ana brought a small gift to thank him. Ben smiled and said, "You are welcome."',
                'questions' => [
                    ['Where did Ana forget her umbrella?', 'literal', 'At school', ['At home', 'At the market', 'On the bus']],
                    ['Who shared an umbrella with Ana?', 'literal', 'Ben', ['Her mother', 'Her teacher', 'Her brother']],
                    ['What did Ana bring the next day?', 'literal', 'A small gift', ['A new umbrella', 'Some books', 'A letter']],
                    ['Why did Ana bring a gift?', 'inferential', 'To thank Ben', ['To sell it', 'To play a game', 'Because it was her birthday']],
                ]],

            // ── Grade 3 · Filipino ──
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang Aming Bakuran',
                'content' => 'May malaking puno ng mangga sa aming bakuran. Tuwing hapon, naglalaro kami sa ilalim ng lilim nito. Si Lolo ay nagdidilig ng mga halaman, at si Lola ay nagwawalis ng mga dahon. Masaya kaming magkakasama sa tahanan.'],
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang Ulan sa Umaga',
                'content' => 'Maaga akong nagising dahil sa malakas na ulan. Nakita kong basa ang daan at puno ng tubig ang mga kanal. Kinuha ko ang aking payong at sumakay sa tricycle papuntang paaralan. Dumating akong tuyo at handang mag-aral.'],
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang Alagang Manok',
                'content' => 'Si Dino ay may alagang manok na pinangalanang Kikay. Tuwing umaga, binibigyan niya ito ng mais at tubig. Isang araw, nakakita siya ng tatlong itlog sa kulungan. Tuwang-tuwa si Dino at ipinakita ito sa kaniyang ina. Nagluto sila ng masarap na almusal.',
                'questions' => [
                    ['Sino ang may alagang manok?', 'literal', 'Si Dino', ['Si Lola', 'Si Kikay', 'Si Nanay']],
                    ['Ano ang ibinibigay niya sa manok tuwing umaga?', 'literal', 'Mais at tubig', ['Gatas at tinapay', 'Isda at kanin', 'Prutas at gulay']],
                    ['Ilang itlog ang nakita ni Dino?', 'literal', 'Tatlo', ['Isa', 'Lima', 'Sampu']],
                    ['Ano ang naramdaman ni Dino sa nakita niya?', 'inferential', 'Tuwang-tuwa', ['Galit', 'Takot', 'Lungkot']],
                ]],
            ['grade' => 3, 'language' => 'fil', 'title' => 'Ang Nawalang Lapis',
                'content' => 'Nawala ang lapis ni Rosa habang nasa klase. Malungkot siyang naghanap sa ilalim ng upuan. Lumapit si Carlo at inabot ang kaniyang dagdag na lapis. Nagpasalamat si Rosa nang may ngiti. Mula noon, naging magkaibigan sila.',
                'questions' => [
                    ['Ano ang nawala kay Rosa?', 'literal', 'Lapis', ['Bag', 'Aklat', 'Payong']],
                    ['Saan naghanap si Rosa?', 'literal', 'Sa ilalim ng upuan', ['Sa bahay', 'Sa silid-aklatan', 'Sa palaruan']],
                    ['Sino ang nag-abot ng dagdag na lapis?', 'literal', 'Si Carlo', ['Ang guro', 'Si Nanay', 'Ang kaklase niyang si Lito']],
                    ['Anong ugali ang ipinakita ni Carlo?', 'inferential', 'Matulungin', ['Madamot', 'Mayabang', 'Tamad']],
                ]],

            // ── Grade 4 · English ──
            ['grade' => 4, 'language' => 'en', 'title' => "The Fisherman's Morning",
                'content' => 'Before sunrise, Mang Pedro paddles his small boat to the sea. He throws his net into the calm water and waits patiently. When the sun rises, silver fish jump inside the net. He returns to shore and sells his catch at the market. His hard work feeds his family.'],
            ['grade' => 4, 'language' => 'en', 'title' => 'Saving Coins',
                'content' => 'Mia keeps a clay jar in her room. Each day she drops a few coins inside, even when she wants to buy candy. After several months, the jar becomes heavy. She counts her savings and buys a book she has wanted for a long time. Saving taught her discipline.'],
            ['grade' => 4, 'language' => 'en', 'title' => 'The Brave Little Crab',
                'content' => 'A little crab lived on a rocky beach. One day, a big wave pushed a starfish onto dry sand. The starfish could not move. The crab pulled it slowly toward the water, even though it was tiring. At last, a wave carried the starfish home. The crab felt proud.',
                'questions' => [
                    ['Where did the little crab live?', 'literal', 'On a rocky beach', ['In a deep river', 'In a garden', 'Under a boat']],
                    ['What happened to the starfish?', 'literal', 'A wave pushed it onto dry sand', ['It got lost in the net', 'It was caught by a bird', 'It fell asleep']],
                    ['How did the crab help the starfish?', 'literal', 'It pulled the starfish toward the water', ['It carried the starfish home on its back', 'It called a fisherman', 'It dug a hole']],
                    ['Which word best describes the crab?', 'inferential', 'Helpful', ['Lazy', 'Selfish', 'Fearful']],
                ]],
            ['grade' => 4, 'language' => 'en', 'title' => 'Market Day Math',
                'content' => 'Lola bought three kilos of rice for ninety pesos each. She also bought a fish for sixty pesos. The seller added everything and said the total was three hundred thirty pesos. Lola handed him four hundred pesos. He returned seventy pesos as change.',
                'questions' => [
                    ['How many kilos of rice did Lola buy?', 'literal', 'Three', ['Two', 'Four', 'Six']],
                    ['How much did the fish cost?', 'literal', 'Sixty pesos', ['Ninety pesos', 'Thirty pesos', 'Seventy pesos']],
                    ['How much did Lola pay in all?', 'applied', 'Three hundred thirty pesos', ['Four hundred pesos', 'Two hundred seventy pesos', 'Sixty pesos']],
                    ['How much change did Lola receive?', 'applied', 'Seventy pesos', ['Thirty pesos', 'Sixty pesos', 'Ninety pesos']],
                ]],

            // ── Grade 4 · Filipino ──
            ['grade' => 4, 'language' => 'fil', 'title' => 'Ang Magsasaka',
                'content' => 'Maagang pumupunta si Mang Tonyo sa kaniyang bukid. Inaararo niya ang lupa gamit ang kalabaw at itinatanim ang binhi ng palay. Kahit mainit ang araw, hindi siya sumusuko. Pagdating ng anihan, masaya ang buong pamilya dahil sagana ang kanilang ani.'],
            ['grade' => 4, 'language' => 'fil', 'title' => 'Ang Aklatan',
                'content' => 'Tahimik ang aklatan ng aming paaralan. Maraming aklat tungkol sa hayop, halaman, at kasaysayan ang nakahanay sa mga istante. Pinahihiram ng librarian ang mga aklat sa loob ng isang linggo. Responsable naming ibinabalik ang mga ito upang magamit din ng iba.'],
            ['grade' => 4, 'language' => 'fil', 'title' => 'Ang Bayanihan',
                'content' => 'Nasira ang bubong ng bahay ni Aling Nena dahil sa bagyo. Nagtipon ang mga kapitbahay dala ang martilyo at yero. Sa loob ng isang araw, naayos nila ang bubong. Nagluto naman si Aling Nena ng pansit para sa lahat. Ipinakita nila ang diwa ng bayanihan.',
                'questions' => [
                    ['Ano ang nasira dahil sa bagyo?', 'literal', 'Ang bubong ng bahay ni Aling Nena', ['Ang tulay', 'Ang paaralan', 'Ang bangka']],
                    ['Sino ang nagtipon upang tumulong?', 'literal', 'Ang mga kapitbahay', ['Ang mga guro', 'Ang mga pulis', 'Ang mga turista']],
                    ['Gaano katagal naayos ang bubong?', 'literal', 'Sa loob ng isang araw', ['Sa loob ng isang linggo', 'Sa loob ng isang oras', 'Sa loob ng isang buwan']],
                    ['Ano ang ibig sabihin ng bayanihan sa kuwento?', 'inferential', 'Sama-samang pagtutulungan', ['Pag-aaway ng magkapitbahay', 'Pagbebenta ng pagkain', 'Paglalaro sa labas']],
                ]],
            ['grade' => 4, 'language' => 'fil', 'title' => 'Ang Tamang Pag-iimpok',
                'content' => 'Nag-iimpok si Lena ng limang piso araw-araw. Pagkalipas ng sampung araw, nakaipon siya ng limampung piso. Ginamit niya ito upang bumili ng kuwaderno at lapis na kailangan sa paaralan. Natutuhan niyang may mabuting bunga ang pagtitipid.',
                'questions' => [
                    ['Magkano ang iniimpok ni Lena araw-araw?', 'literal', 'Limang piso', ['Sampung piso', 'Dalawang piso', 'Limampung piso']],
                    ['Ilang araw siyang nag-impok?', 'literal', 'Sampung araw', ['Limang araw', 'Isang linggo', 'Dalawampung araw']],
                    ['Magkano ang kabuuang naipon ni Lena?', 'applied', 'Limampung piso', ['Limang piso', 'Sampung piso', 'Isandaang piso']],
                    ['Ano ang natutuhan ni Lena?', 'inferential', 'May mabuting bunga ang pagtitipid', ['Mahirap ang mag-aral', 'Masarap ang bumili ng laruan', 'Hindi kailangan ang pera']],
                ]],

            // ── Grade 5 · English ──
            ['grade' => 5, 'language' => 'en', 'title' => 'Why We Sleep', 'category' => 'expository',
                'content' => 'Sleep gives the body time to rest and repair itself. While we sleep, the brain stores what we learned during the day. Children who sleep well can pay attention in class and feel cheerful. Doctors advise students to sleep at least nine hours every night.'],
            ['grade' => 5, 'language' => 'en', 'title' => 'The Old Bridge',
                'content' => 'For many years, an old stone bridge has crossed the river in our town. Farmers carried their harvest over it, and children walked across it to school. Although the stones are worn, the bridge is still strong. Townspeople protect it because it holds their shared history.'],
            ['grade' => 5, 'language' => 'en', 'title' => 'A Volcano Wakes', 'category' => 'expository',
                'content' => 'Mayon Volcano stands tall in Albay. Its nearly perfect cone shape attracts visitors from around the world. However, it is also an active volcano. When scientists detect danger signs, officials ask nearby families to move to safe shelters. Preparing early protects lives.',
                'questions' => [
                    ['Where does Mayon Volcano stand?', 'literal', 'In Albay', ['In Batangas', 'In Cebu', 'In Negros']],
                    ['Why do visitors come to see Mayon?', 'literal', 'Its cone shape is nearly perfect', ['It is the tallest mountain in the world', 'It has a large lake', 'It is covered with snow']],
                    ['Why are families asked to move to shelters?', 'literal', 'Scientists detect danger signs', ['The volcano is being repaired', 'A festival is about to begin', 'The shelters need guests']],
                    ['What is the main idea of the passage?', 'inferential', 'Mayon is beautiful but dangerous, so people must prepare', ['Mayon is the only volcano in the country', 'Visitors should not go to Albay', 'Scientists dislike volcanoes']],
                ]],
            ['grade' => 5, 'language' => 'en', 'title' => 'The Honest Vendor',
                'content' => 'Mr. Cruz sells newspapers near the plaza. One morning, a customer paid him a five hundred peso bill by mistake instead of fifty. Mr. Cruz noticed it, ran after the man, and returned the extra money. The customer thanked him warmly. Mr. Cruz said honesty is worth more than money.',
                'questions' => [
                    ['Where does Mr. Cruz sell newspapers?', 'literal', 'Near the plaza', ['Inside a school', 'At the port', 'Beside the church']],
                    ['What mistake did the customer make?', 'literal', 'He paid five hundred pesos instead of fifty', ['He forgot to pay', 'He took two newspapers', 'He lost his wallet']],
                    ['What did Mr. Cruz do after he noticed the mistake?', 'literal', 'He returned the extra money', ['He kept the money', 'He closed his stall', 'He called the police']],
                    ['Which trait did Mr. Cruz show?', 'evaluative', 'Honesty', ['Greed', 'Laziness', 'Shyness']],
                ]],

            // ── Grade 5 · Filipino ──
            ['grade' => 5, 'language' => 'fil', 'title' => 'Ang Kalinisan', 'category' => 'expository',
                'content' => 'Mahalaga ang kalinisan sa ating katawan at kapaligiran. Naghuhugas tayo ng kamay bago kumain upang maiwasan ang sakit. Itinatapon natin ang basura sa tamang lalagyan. Kapag malinis ang paligid, ligtas at masigla ang buong komunidad.'],
            ['grade' => 5, 'language' => 'fil', 'title' => 'Ang mga Bayani', 'category' => 'expository',
                'content' => 'Maraming bayani ang nag-alay ng buhay para sa kalayaan ng ating bansa. Ipinaglaban nila ang karapatan ng bawat Pilipino. Hindi lamang sa digmaan makikita ang kabayanihan. Maaari rin tayong maging bayani araw-araw sa pamamagitan ng katapatan, sipag, at malasakit sa kapwa.'],
            ['grade' => 5, 'language' => 'fil', 'title' => 'Ang Bulkang Taal', 'category' => 'expository',
                'content' => 'Matatagpuan sa Batangas ang Bulkang Taal, isa sa pinakamaliit na aktibong bulkan sa mundo. May lawa ito sa loob ng bunganga. Kapag nagbabala ang mga eksperto, inililikas ang mga residente. Dahil dito, naiiwasan ang malaking pinsala sa buhay.',
                'questions' => [
                    ['Saan matatagpuan ang Bulkang Taal?', 'literal', 'Sa Batangas', ['Sa Albay', 'Sa Negros', 'Sa Davao']],
                    ['Ano ang nasa loob ng bunganga nito?', 'literal', 'Lawa', ['Ilog', 'Kagubatan', 'Bukid']],
                    ['Bakit inililikas ang mga residente?', 'literal', 'Dahil nagbabala ang mga eksperto', ['Dahil may pista', 'Dahil may pasukan', 'Dahil may sale sa pamilihan']],
                    ['Ano ang mahihinuha sa talata?', 'inferential', 'Mahalagang making sa mga babala upang maligtas ang buhay', ['Walang panganib ang mga bulkan', 'Dapat umalis ang lahat sa Batangas', 'Hindi dapat maniwala sa mga eksperto']],
                ]],
            ['grade' => 5, 'language' => 'fil', 'title' => 'Ang Tapat na Mag-aaral',
                'content' => 'Nakakita si Paolo ng pitaka sa daan. May laman itong pera at identification card. Kahit kailangan niya ng baon, dinala niya ito sa guro. Nahanap ang may-ari, at pinasalamatan siya nang lubos. Ipinagmalaki ng paaralan ang katapatan ni Paolo.',
                'questions' => [
                    ['Ano ang nakita ni Paolo sa daan?', 'literal', 'Pitaka', ['Cellphone', 'Bag', 'Relo']],
                    ['Kanino niya dinala ang pitaka?', 'literal', 'Sa guro', ['Sa pulis', 'Sa kaklase', 'Sa kapitbahay']],
                    ['Ano ang ugaling ipinakita ni Paolo?', 'evaluative', 'Katapatan', ['Kasakiman', 'Katamaran', 'Kayabangan']],
                    ['Bakit kahanga-hanga ang ginawa ni Paolo?', 'inferential', 'Ibinalik niya ito kahit kailangan niya ng pera', ['Nakakuha siya ng gantimpala', 'Walang nakakita sa kaniya', 'Pinilit siya ng guro']],
                ]],

            // ── Grade 6 · English ──
            ['grade' => 6, 'language' => 'en', 'title' => 'Renewable Energy', 'category' => 'expository',
                'content' => 'Renewable energy comes from sources that nature refills, such as sunlight, wind, and flowing water. Unlike coal, these sources do not release harmful smoke. Many countries now build solar farms and wind turbines to protect the environment and lower electricity costs.'],
            ['grade' => 6, 'language' => 'en', 'title' => 'Rizal as a Student',
                'content' => 'Jose Rizal showed great talent even as a young boy. He wrote poems, drew pictures, and studied several languages. His teachers admired his discipline and curiosity. Rizal believed that education could free his countrymen from ignorance, and he used his knowledge to serve the nation.'],
            ['grade' => 6, 'language' => 'en', 'title' => 'The Coral Reef', 'category' => 'expository',
                'content' => 'Coral reefs cover less than one percent of the ocean floor, yet they shelter about a quarter of all marine species. Fish find food and protection among the corals. However, rising water temperatures and pollution damage reefs. Protecting them requires clean seas and responsible fishing.',
                'questions' => [
                    ['How much of the ocean floor do coral reefs cover?', 'literal', 'Less than one percent', ['About a quarter', 'Half', 'Almost all of it']],
                    ['What do coral reefs shelter?', 'literal', 'About a quarter of marine species', ['Only large whales', 'Most land animals', 'Every kind of fish']],
                    ['What damages coral reefs?', 'literal', 'Rising temperatures and pollution', ['Cold winds', 'Too many fish', 'Strong sunlight only']],
                    ['What can we infer from the passage?', 'inferential', 'Losing reefs would endanger many sea animals', ['Reefs can survive without care', 'Fishing always helps reefs', 'Fish do not need corals']],
                ]],
            ['grade' => 6, 'language' => 'en', 'title' => 'The Recycling Challenge',
                'content' => 'Our class held a recycling challenge for one month. Students collected bottles, paper, and cans instead of throwing them away. We gathered over two hundred kilograms and sold them to a junk shop. The money bought new books for our library. Small habits create big change.',
                'questions' => [
                    ['How long did the recycling challenge last?', 'literal', 'One month', ['One week', 'One year', 'Two days']],
                    ['What did the students collect?', 'literal', 'Bottles, paper, and cans', ['Old shoes and toys', 'Plants and seeds', 'Clothes and bags']],
                    ['What was the money used for?', 'literal', 'New books for the library', ['A class party', 'Repairing the roof', 'New uniforms']],
                    ['What is the main message of the passage?', 'inferential', 'Small habits can create big change', ['Junk shops pay a lot of money', 'Libraries need more space', 'Recycling is too difficult']],
                ]],

            // ── Grade 6 · Filipino ──
            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Pagbabago ng Klima', 'category' => 'expository',
                'content' => 'Unti-unting umiinit ang daigdig dahil sa labis na usok mula sa pabrika at sasakyan. Natutunaw ang yelo sa mga poste, at tumataas ang lebel ng dagat. Makakatulong tayo sa pamamagitan ng pagtatanim ng puno, pagtitipid ng kuryente, at pag-recycle.'],
            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Wikang Filipino', 'category' => 'expository',
                'content' => 'Ang wika ay nag-uugnay sa mga mamamayan ng isang bansa. Sa pamamagitan ng wikang Filipino, nagkakaunawaan ang mga taga-Luzon, Visayas, at Mindanao. Dapat nating pahalagahan ito sa wastong paggamit sa pagsasalita, pagbabasa, at pagsusulat araw-araw.'],
            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Bakawan', 'category' => 'expository',
                'content' => 'Pinoprotektahan ng mga bakawan ang baybayin laban sa malalakas na alon at bagyo. Tirahan din ito ng mga isda, alimango, at ibon. Gayunman, maraming bakawan ang pinuputol para gawing palaisdaan. Kapag nawala ang mga ito, malalagay sa panganib ang mga komunidad sa tabing-dagat.',
                'questions' => [
                    ['Ano ang pinoprotektahan ng mga bakawan?', 'literal', 'Ang baybayin laban sa alon at bagyo', ['Ang mga bundok', 'Ang mga bukid', 'Ang mga lungsod']],
                    ['Anong mga hayop ang naninirahan sa bakawan?', 'literal', 'Isda, alimango, at ibon', ['Kabayo at baka', 'Tigre at leon', 'Aso at pusa']],
                    ['Bakit pinuputol ang maraming bakawan?', 'literal', 'Upang gawing palaisdaan', ['Upang gawing daan', 'Upang magtayo ng paaralan', 'Upang gawing taniman ng palay']],
                    ['Ano ang mangyayari kapag nawala ang mga bakawan?', 'inferential', 'Malalagay sa panganib ang mga komunidad sa tabing-dagat', ['Dadami ang mga isda', 'Titigil ang mga bagyo', 'Lalawak ang mga bukid']],
                ]],
            ['grade' => 6, 'language' => 'fil', 'title' => 'Ang Proyektong Pangkalinisan',
                'content' => 'Nagsagawa ang aming klase ng proyektong pangkalinisan sa tabing-ilog. Nangolekta kami ng mga plastik at lumang lambat. Tumulong ang barangay sa pagbubuhat ng mga sako. Pagkatapos, malinis na ang ilog at nakita naming lumalangoy ang mga isda. Napatunayan naming mahalaga ang sama-samang pagkilos.',
                'questions' => [
                    ['Saan ginanap ang proyektong pangkalinisan?', 'literal', 'Sa tabing-ilog', ['Sa paaralan', 'Sa palengke', 'Sa simbahan']],
                    ['Ano ang nakolekta ng klase?', 'literal', 'Plastik at lumang lambat', ['Bote at lata lamang', 'Dahon at sanga', 'Papel at karton']],
                    ['Sino ang tumulong sa pagbubuhat ng mga sako?', 'literal', 'Ang barangay', ['Ang mga bumbero', 'Ang mga mangingisda', 'Ang mga turista']],
                    ['Ano ang napatunayan ng klase?', 'inferential', 'Mahalaga ang sama-samang pagkilos', ['Hindi na kailangang maglinis', 'Mas mabuting magtrabaho nang mag-isa', 'Walang silbi ang proyekto']],
                ]],
        ];
    }
}
