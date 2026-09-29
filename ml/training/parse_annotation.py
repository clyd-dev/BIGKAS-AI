"""
BIGKAS-AI: Parses real annotated transcripts (om/sub/mis/ins/repetition/pause tags)
into RF-ready features. Filters out [unintelligible] clips. Run this FIRST.
"""

import re
import os
import librosa
import pandas as pd

# ── Tag patterns — single backslash, correct escaping ──
OM_PATTERN = re.compile(r'\[om[_:]\s*([^\]]+)\]', re.IGNORECASE)
SUB_PATTERN = re.compile(r'\[sub:\s*([^>\]]+?)\s*>\s*([^\]]+?)\]', re.IGNORECASE)
MIS_PATTERN = re.compile(r'\[mis:\s*([^>\]]+?)\s*>\s*([^\]]+?)\]', re.IGNORECASE)
INS_PATTERN = re.compile(r'\[ins:\s*([^\]]+?)\]', re.IGNORECASE)
PAUSE_PATTERN = re.compile(r'\[pause\]', re.IGNORECASE)
UNINTELLIGIBLE_PATTERN = re.compile(r'\[unintelligible\]', re.IGNORECASE)

VOWELS = set('aeiouAEIOU')
BLENDS = ['bl','br','cl','cr','dr','fl','fr','gl','gr','pl','pr',
          'sc','sk','sl','sm','sn','sp','st','sw','tr','tw']


def soundex_lite(word: str) -> str:
    word = word.lower().strip()
    if not word:
        return ''
    codes = {'b':'1','f':'1','p':'1','v':'1','c':'2','g':'2','j':'2','k':'2',
             'q':'2','s':'2','x':'2','z':'2','d':'3','t':'3','l':'4','m':'5','n':'5','r':'6'}
    result = word[0]
    for ch in word[1:]:
        result += codes.get(ch, '')
    return result[:4]


def classify_error_pattern(target: str, spoken: str) -> str:
    target, spoken = target.strip().lower(), spoken.strip().lower()
    if not target or not spoken:
        return 'other'
    if soundex_lite(target) == soundex_lite(spoken):
        return 'phonetic_confusion'
    t_vowels = ''.join(c for c in target if c in VOWELS)
    s_vowels = ''.join(c for c in spoken if c in VOWELS)
    if t_vowels != s_vowels and len(t_vowels) == len(s_vowels):
        return 'vowel_confusion'
    for blend in BLENDS:
        if blend in target and blend not in spoken:
            return 'consonant_blend_error'
    return 'other'


def flag_hyphenated_tokens(annotated_text: str, prompt_text: str) -> list:
    """
    Flags hyphenated tokens for MANUAL review instead of auto-classifying as stutters.
    Filipino has many legitimate reduplicated words (araw-araw, sari-sari, unti-unti, etc.)
    that a hardcoded whitelist cannot reliably cover. A human reviewer makes the final call
    during Step 3 (teacher review).
    """
    clean_text = OM_PATTERN.sub('', annotated_text)
    clean_text = SUB_PATTERN.sub('', clean_text)
    clean_text = MIS_PATTERN.sub('', clean_text)
    clean_text = INS_PATTERN.sub('', clean_text)
    clean_text = PAUSE_PATTERN.sub('', clean_text)

    prompt_words_lower = set(w.lower() for w in prompt_text.split())
    flagged = []

    for token in clean_text.split():
        token_clean = token.strip('.,!?').lower()
        if '-' in token_clean:
            suffix = token_clean.split('-')[-1]
            if suffix in prompt_words_lower:
                flagged.append(token_clean)

    return flagged


def parse_annotated_transcript(annotated_text: str) -> dict:
    omissions = len(OM_PATTERN.findall(annotated_text))
    insertions = len(INS_PATTERN.findall(annotated_text))
    long_pauses = len(PAUSE_PATTERN.findall(annotated_text))

    substitutions = 0
    patterns = {'phonetic_confusion': 0, 'vowel_confusion': 0, 'consonant_blend_error': 0, 'other': 0}

    for target, spoken in SUB_PATTERN.findall(annotated_text):
        substitutions += 1
        patterns[classify_error_pattern(target, spoken)] += 1

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
    try:
        y, sr = librosa.load(audio_path, sr=None)
        return librosa.get_duration(y=y, sr=sr)
    except Exception as e:
        print(f"  WARNING: could not read audio '{audio_path}': {e}")
        return None


def build_feature_row(row: pd.Series, audio_dir: str) -> dict:
    prompt_text = str(row['prompt_text']).strip()
    annotated_text = str(row['annotated_transcript']).strip()
    reference_words = prompt_text.split()
    total_words = len(reference_words)

    errors = parse_annotated_transcript(annotated_text)
    flagged_tokens = flag_hyphenated_tokens(annotated_text, prompt_text)

    correct_words = max(0, total_words - errors['substitutions'] - errors['omissions'])
    accuracy_rate = round((correct_words / total_words) * 100, 2) if total_words else 0

    audio_path = os.path.join(audio_dir, row['file_name'])
    duration = get_audio_duration(audio_path)
    wpm = round((correct_words / duration) * 60, 1) if duration and duration > 0 else None

    total_pattern_errors = max(sum(errors['patterns'].values()), 1)
    pause_frequency = round(errors['long_pauses'] / max(total_words, 1), 3)

    # self_correction_rate left as a placeholder pending manual review of flagged_tokens
    # (see review_notes_hyphenated_tokens column — teacher confirms real stutters vs valid words)
    provisional_repetition_count = len(flagged_tokens)
    repetition_rate = round(provisional_repetition_count / max(total_words, 1), 3)

    fluency_score = 10.0
    fluency_score -= min(4, errors['long_pauses'] * 1.5)
    fluency_score -= min(2, provisional_repetition_count * 0.5)
    if wpm is not None and wpm < 60:
        fluency_score -= (60 - wpm) / 20
    fluency_score = max(0, min(10, round(fluency_score, 1)))

    return {
        'file_name': row['file_name'],
        'grade_level': row['grade_level'],
        'language': row['language'],
        'accuracy_rate': accuracy_rate,
        'words_per_minute': wpm,
        'substitution_rate': round(errors['substitutions'] / total_words, 3) if total_words else 0,
        'omission_rate': round(errors['omissions'] / total_words, 3) if total_words else 0,
        'insertion_rate': round(errors['insertions'] / total_words, 3) if total_words else 0,
        'phonetic_error_rate': round(errors['patterns']['phonetic_confusion'] / total_pattern_errors, 3),
        'vowel_error_rate': round(errors['patterns']['vowel_confusion'] / total_pattern_errors, 3),
        'blend_error_rate': round(errors['patterns']['consonant_blend_error'] / total_pattern_errors, 3),
        'self_correction_rate': repetition_rate,  # PROVISIONAL — see flagged_hyphenated_tokens
        'flagged_hyphenated_tokens': '; '.join(flagged_tokens) if flagged_tokens else '',
        'pause_frequency': pause_frequency,
        'fluency_score': fluency_score,
        'prosody_score': None,  # filled by teacher in Step 3, no acoustic source available
    }


if __name__ == '__main__':
    AUDIO_DIR = './audio_files'
    df = pd.read_csv('metadata.csv')

    total_rows = len(df)
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

    missing_audio = features_df['words_per_minute'].isna().sum()
    flagged_rows = (features_df['flagged_hyphenated_tokens'] != '').sum()
    print(f"Rows missing WPM (audio not found/unreadable): {missing_audio}")
    print(f"Rows with flagged hyphenated tokens needing manual review: {flagged_rows}")

    features_df.to_csv('extracted_features.csv', index=False)
    print("-> Saved: extracted_features.csv")