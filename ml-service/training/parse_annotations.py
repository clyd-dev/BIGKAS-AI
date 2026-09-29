"""
BIGKAS-AI: Annotation Parser → 12 RF-Ready Features
=====================================================
Parses annotated_transcript tags from metadata.csv into the 12 features
that the live Flask /api/classify endpoint and trained RF model expect.

Tag formats handled (from your Scripted Annotation Framework):
  [om_word]                      → omission
  [sub: target > spoken]         → substitution
  [mis: target > spoken]         → mispronunciation (counted as substitution)
  [ins: word]                    → insertion
  [pause]                        → long hesitation (>3s)
  [unintelligible]               → excluded from training entirely
  ma-mabilis (hyphenated stutter) → flagged for teacher review, not auto-counted

Output: extracted_features.csv (12 feature columns + metadata)

IMPORTANT: The 12 FEATURE_COLUMNS here MUST match EXACTLY:
  - ml-service/app.py FEATURE_COLUMNS list
  - ReadingAnalyzerService.php $mlFeatures array
  - train_model_real_data.py FEATURE_COLUMNS list
If you rename any column, you must rename it in ALL 4 files simultaneously.

Prerequisites:
  pip install pandas librosa soundfile

Usage:
  cd F:\\MyApp\\BIGKAS-AI\\ml-service\\training
  python parse_annotations.py
"""

import re
import os
import pandas as pd

# ── Try importing librosa; fall back to file-size estimation if missing ──
try:
    import librosa
    HAS_LIBROSA = True
except ImportError:
    HAS_LIBROSA = False
    print("WARNING: librosa not installed. WPM will be estimated from file size.")
    print("For accurate WPM, run: pip install librosa soundfile")

# ============================================================
# TAG PATTERNS — single backslash, correct escaping
# ============================================================
# Handles both [om_word] and [om: word] variants
OM_PATTERN = re.compile(r'\[om[_:]\s*([^\]]+)\]', re.IGNORECASE)
SUB_PATTERN = re.compile(r'\[sub:\s*([^>\]]+?)\s*>\s*([^\]]+?)\]', re.IGNORECASE)
MIS_PATTERN = re.compile(r'\[mis:\s*([^>\]]+?)\s*>\s*([^\]]+?)\]', re.IGNORECASE)
INS_PATTERN = re.compile(r'\[ins:\s*([^\]]+?)\]', re.IGNORECASE)
PAUSE_PATTERN = re.compile(r'\[pause\]', re.IGNORECASE)
UNINTELLIGIBLE_PATTERN = re.compile(r'\[unintelligible\]', re.IGNORECASE)

VOWELS = set('aeiouAEIOU')
BLENDS = [
    'bl', 'br', 'cl', 'cr', 'dr', 'fl', 'fr', 'gl', 'gr',
    'pl', 'pr', 'sc', 'sk', 'sl', 'sm', 'sn', 'sp', 'st', 'sw', 'tr', 'tw',
    'kl', 'kr', 'ts', 'bw', 'dy', 'sy', 'mw', 'nw'
]


# ============================================================
# HELPER FUNCTIONS
# ============================================================

def clean_token(word: str) -> str:
    """
    Strip apostrophes and non-alphanumeric chars, mirroring PHP tokenizeText().
    Ensures "Siya'y" from prompt matches "siyay" from annotations.
    """
    word = word.lower().strip()
    word = re.sub(r"[^a-z0-9\s]", "", word)
    return word.strip()


def soundex_lite(word: str) -> str:
    """Simplified soundex for phonetic similarity comparison."""
    word = word.lower().strip()
    if not word:
        return ''
    codes = {
        'b': '1', 'f': '1', 'p': '1', 'v': '1',
        'c': '2', 'g': '2', 'j': '2', 'k': '2', 'q': '2', 's': '2', 'x': '2', 'z': '2',
        'd': '3', 't': '3',
        'l': '4',
        'm': '5', 'n': '5',
        'r': '6',
    }
    result = word[0]
    for ch in word[1:]:
        result += codes.get(ch, '')
    return result[:4]


def classify_error_pattern(target: str, spoken: str) -> str:
    """
    Classify the type of substitution/mispronunciation error.
    Mirrors ReadingAnalyzerService::identifySubstitutionPattern() in PHP.
    """
    target, spoken = clean_token(target), clean_token(spoken)
    if not target or not spoken:
        return 'other'

    # 1. Check for consonant blend errors first
    for blend in BLENDS:
        if blend in target and blend not in spoken:
            return 'consonant_blend_error'

    # 2. Syllable-Drop Detection (Crucial for Tagalog/Hiligaynon polysyllabic words)
    # If the spoken word is missing 3 or more characters compared to the target word
    if len(target) - len(spoken) >= 3:
        return 'phonetic_confusion'

    # 3. Check vowel confusion (allow slight length variation)
    t_vowels = ''.join(c for c in target if c in VOWELS)
    s_vowels = ''.join(c for c in spoken if c in VOWELS)
    if t_vowels != s_vowels:
        return 'vowel_confusion'

    # 4. Check general phonetic confusion (fallback)
    if soundex_lite(target) == soundex_lite(spoken):
        return 'phonetic_confusion'

    return 'other'


def flag_hyphenated_tokens(annotated_text: str, prompt_text: str) -> list:
    """
    Flags hyphenated tokens for MANUAL review instead of auto-classifying
    them as stutters. Words like tuwang-tuwa, nag-aaral, nag-aalaga that
    already exist in the prompt text are automatically skipped as valid
    vocabulary — only genuine stutter-like patterns reach the teacher.
    """
    clean_text = OM_PATTERN.sub('', annotated_text)
    clean_text = SUB_PATTERN.sub('', clean_text)
    clean_text = MIS_PATTERN.sub('', clean_text)
    clean_text = INS_PATTERN.sub('', clean_text)
    clean_text = PAUSE_PATTERN.sub('', clean_text)

    prompt_words_raw = set(w.strip('.,!?').lower() for w in prompt_text.split())
    prompt_words_clean = set(clean_token(w) for w in prompt_text.split())
    flagged = []

    for token in clean_text.split():
        raw_token = token.strip('.,!?').lower()

        # Check for hyphen on the RAW token — clean_token() would strip it
        if '-' not in raw_token:
            continue

        # Case 1: exact match to a known prompt word (e.g. "tuwang-tuwa" IS in the passage)
        # -> normal vocabulary, not a stutter, skip entirely
        if raw_token in prompt_words_raw:
            continue

        # Case 2: not an exact prompt word, but the tail after the last hyphen
        # matches a real target word -> likely a genuine stutter attempt
        # (e.g. child said "ma-mabilis" reaching for "mabilis")
        suffix_raw = raw_token.split('-')[-1]
        suffix_clean = clean_token(suffix_raw)
        if suffix_clean in prompt_words_clean:
            flagged.append(raw_token)

    return flagged


def parse_annotated_transcript(annotated_text: str) -> dict:
    """Extract error counts and patterns from annotated transcript tags."""
    omissions = len(OM_PATTERN.findall(annotated_text))
    insertions = len(INS_PATTERN.findall(annotated_text))
    long_pauses = len(PAUSE_PATTERN.findall(annotated_text))

    substitutions = 0
    patterns = {
        'phonetic_confusion': 0,
        'vowel_confusion': 0,
        'consonant_blend_error': 0,
        'other': 0,
    }

    # Count [sub: target > spoken] tags
    for target, spoken in SUB_PATTERN.findall(annotated_text):
        substitutions += 1
        patterns[classify_error_pattern(target, spoken)] += 1

    # Count [mis: target > spoken] tags (mispronunciation = decoding-adjacent error)
    for target, spoken in MIS_PATTERN.findall(annotated_text):
        substitutions += 1
        patterns[classify_error_pattern(target, spoken)] += 1

    return {
        'omissions': omissions,
        'substitutions': substitutions,
        'insertions': insertions,
        'long_pauses': long_pauses,
        'patterns': patterns,
    }


def get_audio_duration(audio_path: str):
    """Get audio duration in seconds. Returns None if file not found."""
    if not os.path.exists(audio_path):
        return None

    if HAS_LIBROSA:
        try:
            y, sr = librosa.load(audio_path, sr=None)
            return librosa.get_duration(y=y, sr=sr)
        except Exception as e:
            print(f"  WARNING: could not read audio '{audio_path}': {e}")
            return None
    else:
        # Rough estimate: 16kHz mono 16-bit = 32000 bytes/sec
        return os.path.getsize(audio_path) / 32000


def compute_fluency_score(wpm, long_pauses, repetitions, total_words, total_errors, grade_level):
    """Refined fluency score taking Phil-IRI WPM standards and error pauses into account."""
    score = 10.0
    
    # Pause penalty
    score -= min(4.0, long_pauses * 1.5)
    # Stutter penalty
    score -= min(2.0, repetitions * 0.5)
    
    # WPM Penalty (scaled according to Phil-IRI speed expectations)
    grade_str = str(grade_level).strip()
    
    if wpm is not None:
        if '6' in grade_str or '5' in grade_str:
            if wpm < 60: score -= 3.5
            elif wpm < 80: score -= 2.0
            elif wpm < 100: score -= 1.0
        elif '4' in grade_str:
            if wpm < 40: score -= 3.5
            elif wpm < 60: score -= 2.0
            elif wpm < 80: score -= 1.0
        else:
            # Default to Grade 3 baseline
            if wpm < 30: score -= 3.5
            elif wpm < 50: score -= 2.0
            elif wpm < 70: score -= 1.0
            
    # High error density penalty (lack of accuracy harms fluency)
    error_rate = total_errors / max(total_words, 1)
    if error_rate > 0.3:
        score -= 1.5
        
    return max(0.0, min(10.0, round(score, 1)))


# ============================================================
# MAIN FEATURE EXTRACTION
# ============================================================

def build_feature_row(row: pd.Series, audio_dir: str) -> dict:
    """
    Turn one metadata.csv row into a 12-feature dict.
    Column names MUST match FEATURE_COLUMNS in app.py, Laravel, and training.
    """
    prompt_text = str(row['prompt_text']).strip()
    annotated_text = str(row['annotated_transcript']).strip()
    reference_words = prompt_text.split()
    total_words = len(reference_words)

    errors = parse_annotated_transcript(annotated_text)
    flagged_tokens = flag_hyphenated_tokens(annotated_text, prompt_text)

    correct_words = max(0, total_words - errors['substitutions'] - errors['omissions'])
    accuracy_rate = round((correct_words / total_words) * 100, 2) if total_words else 0

    # WPM from audio duration
    audio_path = os.path.join(audio_dir, str(row['file_name']).strip())
    duration = get_audio_duration(audio_path)
    wpm = round((correct_words / duration) * 60, 1) if duration and duration > 0 else None

    # Compute rates
    total_pattern_errors = max(sum(errors['patterns'].values()), 1)
    pause_frequency = round(errors['long_pauses'] / max(total_words, 1), 3)

    # Provisional repetition count (pending teacher review)
    provisional_repetition_count = len(flagged_tokens)
    self_correction_rate = round(provisional_repetition_count / max(total_words, 1), 3)

    # Heuristic fluency score
    total_errors = errors['substitutions'] + errors['omissions'] + errors['insertions']
    fluency_score = compute_fluency_score(
        wpm=wpm, 
        long_pauses=errors['long_pauses'], 
        repetitions=provisional_repetition_count, 
        total_words=total_words, 
        total_errors=total_errors,
        grade_level=row.get('grade_level', '3')
    )

    # Prosody has NO source data in this tag scheme — placeholder for teacher
    prosody_score = None

    return {
        'file_name': row['file_name'],
        'grade_level': row.get('grade_level', ''),
        'language': row.get('language', ''),
        # ── The 12 ML features (names match Flask FEATURE_COLUMNS exactly) ──
        'accuracy_rate': accuracy_rate,
        'words_per_minute': wpm,
        'fluency_score': fluency_score,
        'substitution_rate': round(errors['substitutions'] / total_words, 3) if total_words else 0,
        'omission_rate': round(errors['omissions'] / total_words, 3) if total_words else 0,
        'insertion_rate': round(errors['insertions'] / total_words, 3) if total_words else 0,
        'phonetic_error_rate': round(errors['patterns']['phonetic_confusion'] / total_pattern_errors, 3),
        'vowel_error_rate': round(errors['patterns']['vowel_confusion'] / total_pattern_errors, 3),
        'blend_error_rate': round(errors['patterns']['consonant_blend_error'] / total_pattern_errors, 3),
        'self_correction_rate': self_correction_rate,
        'pause_frequency': pause_frequency,
        'prosody_score': prosody_score,
        # ── Metadata (not sent to model, used for review/debugging) ──
        'flagged_hyphenated_tokens': '; '.join(flagged_tokens) if flagged_tokens else '',
        'total_words': total_words,
        'correct_words': correct_words,
        'total_errors': errors['substitutions'] + errors['omissions'] + errors['insertions'],
        'duration_sec': round(duration, 2) if duration else None,
    }


if __name__ == '__main__':
    AUDIO_DIR = './audio_files'
    CSV_PATH = 'metadata_clean.csv'

    if not os.path.exists(CSV_PATH):
        print(f"ERROR: {CSV_PATH} not found.")
        print(f"Export your Google Sheet as CSV and place it here: {os.path.abspath(CSV_PATH)}")
        exit(1)

    df = pd.read_csv(CSV_PATH)
    total_rows = len(df)

    # Filter out [unintelligible] clips
    unintelligible_mask = df['annotated_transcript'].astype(str).str.strip().apply(
        lambda x: bool(UNINTELLIGIBLE_PATTERN.fullmatch(x.strip()))
    )
    unintelligible_count = unintelligible_mask.sum()
    df_usable = df[~unintelligible_mask].copy()

    print(f"Total rows in metadata.csv: {total_rows}")
    print(f"Excluded as [unintelligible]: {unintelligible_count}")
    print(f"Usable rows for feature extraction: {len(df_usable)}")

    features = [build_feature_row(row, AUDIO_DIR) for _, row in df_usable.iterrows()]
    features_df = pd.DataFrame(features)

    missing_wpm = features_df['words_per_minute'].isna().sum()
    flagged_rows = (features_df['flagged_hyphenated_tokens'] != '').sum()
    print(f"Rows missing WPM (audio not found): {missing_wpm}")
    print(f"Rows with flagged hyphenated tokens needing manual review: {flagged_rows}")

    features_df.to_csv('extracted_features.csv', index=False)
    print(f"-> Saved: extracted_features.csv ({len(features_df)} rows)")
