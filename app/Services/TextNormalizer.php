<?php

namespace App\Services;

/**
 * Turns a passage, or what the speech engine wrote, into the word tokens the
 * two are compared as.
 *
 * Both sides of every comparison come through here, so the only thing that can
 * make them differ is the reading itself - not how the text happens to be
 * written. That matters because a passage is typed or pasted by a teacher while
 * the transcript is written by a machine, and the two choose different forms
 * for the same word:
 *
 *   "twenty-first"   / "21st"           the same word, words or digits
 *   "sari-sari"      / "sari sari"      one written word, two spoken ones
 *   "town\u{2019}s"  / "town's"         a curly apostrophe out of Word
 *   "the\u{00A0}marker"                 a non-breaking space out of a paste
 *   "\u{0410}ng"     / "Ang"            a Cyrillic look-alike letter
 *   "10 piso"        / "sampung piso"   a Tagalog number with its linker
 *
 * The steps run in this order, and the order is load-bearing:
 *
 *   1. fold     invisible, look-alike and accented characters to plain ones,
 *               and word-breaking punctuation (dashes, NBSP) to a space
 *   2. lower    case has never been part of a reading score
 *   3. strip    drop what is left that is not a letter, digit or space. By now
 *               nothing that separates two words is left, so dropping is safe
 *   4. numbers  spell digits out as words, in the passage's language, so a
 *               number matches however it was written
 *   5. canon    reduce a Tagalog number word to its bare form, so the linker
 *               ("sampu" against "sampung") is not a miscue
 *
 * Deliberate limits: this is for the short Grade 3-6 passages the system
 * assesses, so only numbers below 10,000 are spelled out - anything longer is
 * an id or a phone number, which nobody reads as a quantity. The Filipino
 * number words are the common Tagalog forms; a child who reads a number in
 * Spanish ("beinte uno") is reading something else, and that is a real miscue.
 */
class TextNormalizer
{
    /** Above this a digit string is an id, not a quantity. */
    private const MAX_SPELLED_NUMBER = 9999;

    /**
     * @return array<int, string> lowercase word tokens
     */
    public static function tokens(string $text, ?string $language = null): array
    {
        $text = mb_strtolower(self::fold($text));

        // A reader says "percent", so the sign is a word, not punctuation.
        $text = str_replace('%', $language === 'fil' ? ' porsyento ' : ' percent ', $text);

        // Everything that separates words is already a space, so what is left
        // (apostrophes, commas, full stops) belongs inside or beside a word and
        // is dropped without one.
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        $tokens = [];

        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            foreach (self::spellOut($token, $language) as $word) {
                $tokens[] = self::canonical($word, $language);
            }
        }

        return $tokens;
    }

    /**
     * Characters that look like other characters, or like nothing at all.
     *
     * A word break becomes a space; a mark that lives inside a word is dropped
     * (so "town's" stays one word); a look-alike becomes the plain letter it is
     * being used as.
     */
    private static function fold(string $text): string
    {
        static $map = null;

        $map ??= self::foldMap();

        return strtr($text, $map);
    }

    /** @return array<string, string> */
    private static function foldMap(): array
    {
        $map = [];

        // ── Breaks a word: becomes a space ──
        // Non-breaking and other fixed-width spaces (a Word or PDF paste), and
        // every dash: a hyphenated compound is read as two words.
        foreach (["\u{00A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}",
            "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{202F}",
            "\u{205F}", "\u{3000}", "\u{2028}", "\u{2029}",
            '-', "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}",
            "\u{FE58}", "\u{FE63}", "\u{FF0D}"] as $char) {
            $map[$char] = ' ';
        }

        // ── Invisible: dropped ──
        // A soft hyphen or zero-width space sits inside a word and must not
        // break it: "ma<shy>chine" is "machine".
        foreach (["\u{00AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}"] as $char) {
            $map[$char] = '';
        }

        // ── Quotes and apostrophes: dropped, like any other punctuation ──
        foreach (["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{02BC}", "\u{02B9}",
            "\u{00B4}", "\u{0060}", "\u{2032}", "'"] as $char) {
            $map[$char] = '';
        }

        // ── Look-alike letters ──
        // Cyrillic and Greek letters drawn like Latin ones. They reach a passage
        // through a copy-paste and are invisible on screen, so without this the
        // word they sit in can never be read correctly.
        $lookAlikes = [
            'а' => 'a', 'А' => 'A', 'е' => 'e', 'Е' => 'E', 'о' => 'o', 'О' => 'O',
            'р' => 'p', 'Р' => 'P', 'с' => 'c', 'С' => 'C', 'х' => 'x', 'Х' => 'X',
            'у' => 'y', 'У' => 'Y', 'і' => 'i', 'І' => 'I', 'ј' => 'j', 'Ј' => 'J',
            'В' => 'B', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'Т' => 'T', 'ѕ' => 's', 'Ѕ' => 'S',
            'α' => 'a', 'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H',
            'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'ο' => 'o',
            'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X', 'ν' => 'v', 'ρ' => 'p',
        ];

        // ── Accents ──
        // A teacher types "nino" where the passage says "niño", and the engine
        // may write either. It is the same word being read.
        $accented = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E',
            'Ê' => 'E', 'Í' => 'I', 'Ì' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O',
            'Ö' => 'O', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
        ];

        // ── Full-width letters and digits, as some phone keyboards produce ──
        $fullWidth = [];

        for ($i = 0x21; $i <= 0x7E; $i++) {
            $fullWidth[mb_chr($i + 0xFEE0)] = chr($i);
        }

        return $map + $lookAlikes + $accented + $fullWidth;
    }

    /**
     * A digit token as the words a reader says for it.
     *
     * "21st" is two words ("twenty first"), so this returns a list. Anything
     * that is not a number comes back unchanged.
     *
     * @return array<int, string>
     */
    private static function spellOut(string $token, ?string $language): array
    {
        if (! preg_match('/^(\d+)(st|nd|rd|th)?$/u', $token, $m)) {
            return [$token];
        }

        $number = (int) $m[1];

        // Long digit strings (a phone number, an id) and leading zeros are not
        // quantities. Left as written: both sides are written the same way.
        if ($number > self::MAX_SPELLED_NUMBER || ($m[1] !== '0' && str_starts_with($m[1], '0'))) {
            return [$token];
        }

        $words = $language === 'fil'
            ? self::filipino($number)
            : self::english($number, isset($m[2]));

        // The words carry their own punctuation ("dalawampu't") and spaces, so
        // they go through the same strip-and-split as everything else.
        return preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', '', $words), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** English number words for 0-9999, cardinal or ordinal. */
    private static function english(int $n, bool $ordinal = false): string
    {
        static $units = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
            'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
            'seventeen', 'eighteen', 'nineteen'];
        static $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
            6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

        if ($ordinal) {
            // Only the last word changes: "twenty-first", "one hundredth".
            return preg_replace_callback(
                '/(\w+)$/',
                fn ($m) => self::ordinalWord($m[1]),
                self::english($n)
            );
        }

        if ($n < 20) {
            return $units[$n];
        }

        if ($n < 100) {
            return $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $units[$n % 10] : '');
        }

        if ($n < 1000) {
            return $units[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . self::english($n % 100) : '');
        }

        return self::english(intdiv($n, 1000)) . ' thousand' . ($n % 1000 ? ' ' . self::english($n % 1000) : '');
    }

    /** "two" -> "second", "twenty" -> "twentieth", "four" -> "fourth". */
    private static function ordinalWord(string $word): string
    {
        static $irregular = ['one' => 'first', 'two' => 'second', 'three' => 'third',
            'five' => 'fifth', 'eight' => 'eighth', 'nine' => 'ninth', 'twelve' => 'twelfth'];

        if (isset($irregular[$word])) {
            return $irregular[$word];
        }

        return str_ends_with($word, 'y')
            ? substr($word, 0, -1) . 'ieth'   // twenty -> twentieth
            : $word . 'th';                   // four -> fourth, hundred -> hundredth
    }

    /** Tagalog number words for 0-9999. */
    private static function filipino(int $n): string
    {
        static $units = ['sero', 'isa', 'dalawa', 'tatlo', 'apat', 'lima', 'anim', 'pito', 'walo', 'siyam'];
        static $teens = [10 => 'sampu', 'labing isa', 'labindalawa', 'labintatlo', 'labing apat',
            'labinlima', 'labing anim', 'labimpito', 'labingwalo', 'labinsiyam'];
        static $tens = [2 => 'dalawampu', 3 => 'tatlumpu', 4 => 'apatnapu', 5 => 'limampu',
            6 => 'animnapu', 7 => 'pitumpu', 8 => 'walumpu', 9 => 'siyamnapu'];

        if ($n < 10) {
            return $units[$n];
        }

        if ($n < 20) {
            return $teens[$n];
        }

        if ($n < 100) {
            // 21 is "dalawampu't isa"; the apostrophe is dropped downstream.
            return $tens[intdiv($n, 10)] . ($n % 10 ? "'t " . $units[$n % 10] : '');
        }

        if ($n < 1000) {
            return self::filipino(intdiv($n, 100)) . ' daan' . ($n % 100 ? ' ' . self::filipino($n % 100) : '');
        }

        return self::filipino(intdiv($n, 1000)) . ' libo' . ($n % 1000 ? ' ' . self::filipino($n % 1000) : '');
    }

    /**
     * A Tagalog number word reduced to its bare form.
     *
     * Before a noun a number takes a linker - "sampung piso", "dalawang
     * pahina", "isang puno" - and on its own it does not: "ipinagbili niya ang
     * dalawampu". The passage and the child can differ on that without the
     * reading being wrong, so the linker is removed from both.
     *
     * Only the number words are touched. An ordinary word that happens to end
     * in "ng" ("tindahang", "bakuran") is left exactly as it is.
     */
    private static function canonical(string $word, ?string $language): string
    {
        static $numbers = null;

        if ($language !== 'fil' || ! str_ends_with($word, 'ng')) {
            return $word;
        }

        $numbers ??= array_flip(array_merge(
            ['sero', 'isa', 'dalawa', 'tatlo', 'apat', 'lima', 'anim', 'pito', 'walo', 'siyam',
                'sampu', 'labindalawa', 'labintatlo', 'labinlima', 'labimpito', 'labingwalo',
                'labinsiyam', 'dalawampu', 'tatlumpu', 'apatnapu', 'limampu', 'animnapu',
                'pitumpu', 'walumpu', 'siyamnapu', 'daan', 'libo'],
        ));

        $bare = substr($word, 0, -2);

        return isset($numbers[$bare]) ? $bare : $word;
    }
}
