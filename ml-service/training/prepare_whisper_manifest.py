"""
BIGKAS-AI: Prepare Whisper Fine-Tuning Manifest
=================================================
Converts tagged annotations into clean literal-speech transcripts
for Whisper LoRA fine-tuning. Excludes [unintelligible] clips.

This is INDEPENDENT from the RF pipeline — run it separately.

Tag resolution logic:
  [om_word]              → removed (word was not spoken)
  [sub: target > spoken] → keeps "spoken" (what the child actually said)
  [mis: target > spoken] → keeps "spoken" (the mispronunciation)
  [ins: word]            → keeps "word" (extra word was spoken)
  [pause]                → removed (not spoken text)
  ma-mabilis             → left as-is (literal vocalization per annotation spec)

Output: whisper_training_manifest.csv
  Columns: file_name, clean_whisper_transcript, prompt_text, language, grade_level

Usage:
  cd F:\\MyApp\\BIGKAS-AI\\ml-service\\training
  python prepare_whisper_manifest.py
"""

import re
import pandas as pd

# ── Tag patterns (same as parse_annotations.py) ──
OM_PATTERN = re.compile(r'\[om[_:]\s*[^\]]+\]', re.IGNORECASE)
SUB_PATTERN = re.compile(r'\[sub:\s*[^>\]]+?\s*>\s*([^\]]+?)\]', re.IGNORECASE)
MIS_PATTERN = re.compile(r'\[mis:\s*[^>\]]+?\s*>\s*([^\]]+?)\]', re.IGNORECASE)
INS_PATTERN = re.compile(r'\[ins:\s*([^\]]+?)\]', re.IGNORECASE)
PAUSE_PATTERN = re.compile(r'\[pause\]', re.IGNORECASE)
UNINTELLIGIBLE_PATTERN = re.compile(r'\[unintelligible\]', re.IGNORECASE)


def resolve_literal_speech(annotated_text: str) -> str:
    """
    Convert tagged transcript into clean, literal spoken text.

    Example:
      "ang [om_matabang] aso [sub: tumakbo > tumalon]"
      → "ang aso tumalon"
    """
    text = str(annotated_text)

    # Remove omissions (word was not spoken)
    text = OM_PATTERN.sub('', text)

    # Replace substitutions with what was actually spoken
    text = SUB_PATTERN.sub(r'\1', text)

    # Replace mispronunciations with what was actually spoken
    text = MIS_PATTERN.sub(r'\1', text)

    # Keep insertions (extra words that were spoken)
    text = INS_PATTERN.sub(r'\1', text)

    # Remove pause markers
    text = PAUSE_PATTERN.sub('', text)

    # Clean up whitespace and lowercase
    return ' '.join(text.split()).lower()


if __name__ == '__main__':
    CSV_PATH = 'metadata_clean.csv'

    df = pd.read_csv(CSV_PATH)
    total = len(df)

    # Exclude [unintelligible] clips
    unintelligible_mask = df['annotated_transcript'].astype(str).str.strip().apply(
        lambda x: bool(UNINTELLIGIBLE_PATTERN.fullmatch(x.strip()))
    )
    excluded = unintelligible_mask.sum()
    df = df[~unintelligible_mask].copy()

    print(f"Total rows: {total}")
    print(f"Excluded [unintelligible]: {excluded}")
    print(f"Usable for Whisper: {len(df)}")

    # Resolve tags into literal speech
    df['clean_whisper_transcript'] = df['annotated_transcript'].apply(resolve_literal_speech)

    # Check for empty transcripts (would be bad training data)
    empty_count = (df['clean_whisper_transcript'].str.strip() == '').sum()
    if empty_count:
        print(f"WARNING: {empty_count} rows produced empty transcripts after cleaning.")
        print("Check these rows manually — they may have only tags and no spoken text.")

    # Save manifest
    manifest_df = df[['file_name', 'clean_whisper_transcript', 'prompt_text', 'language', 'grade_level']]
    manifest_df.to_csv('whisper_training_manifest.csv', index=False)
    print(f"\n-> Saved: whisper_training_manifest.csv ({len(manifest_df)} rows)")
    print(f"   Use this as input for the Whisper LoRA fine-tuning notebook on Colab.")
