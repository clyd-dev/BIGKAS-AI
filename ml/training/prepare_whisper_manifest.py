"""
Converts tagged annotations into clean literal-speech transcripts for Whisper fine-tuning.
Excludes [unintelligible] clips. Run separately from the RF pipeline.
"""

import re
import pandas as pd

OM_PATTERN = re.compile(r'\[om[_:]\s*[^\]]+\]', re.IGNORECASE)
SUB_PATTERN = re.compile(r'\[sub:\s*[^>\]]+?\s*>\s*([^\]]+?)\]', re.IGNORECASE)
MIS_PATTERN = re.compile(r'\[mis:\s*[^>\]]+?\s*>\s*([^\]]+?)\]', re.IGNORECASE)
INS_PATTERN = re.compile(r'\[ins:\s*([^\]]+?)\]', re.IGNORECASE)
PAUSE_PATTERN = re.compile(r'\[pause\]', re.IGNORECASE)
UNINTELLIGIBLE_PATTERN = re.compile(r'\[unintelligible\]', re.IGNORECASE)


def resolve_literal_speech(annotated_text: str) -> str:
    """
    Converts tagged transcripts into clean, literal spoken text.
    'ang [om_matabang] aso [sub: tumakbo > tumalon]' -> 'ang aso tumalon'
    """
    text = str(annotated_text)
    text = OM_PATTERN.sub('', text)
    text = SUB_PATTERN.sub(r'\1', text)
    text = MIS_PATTERN.sub(r'\1', text)
    text = INS_PATTERN.sub(r'\1', text)
    text = PAUSE_PATTERN.sub('', text)
    return ' '.join(text.split()).lower()


if __name__ == '__main__':
    df = pd.read_csv('metadata.csv')

    unintelligible_mask = df['annotated_transcript'].astype(str).str.strip().apply(
        lambda x: bool(UNINTELLIGIBLE_PATTERN.fullmatch(x.strip()))
    )
    excluded = unintelligible_mask.sum()
    df = df[~unintelligible_mask].copy()

    print(f"Excluded {excluded} [unintelligible] clips from Whisper manifest.")

    df['clean_whisper_transcript'] = df['annotated_transcript'].apply(resolve_literal_speech)

    empty_transcripts = (df['clean_whisper_transcript'].str.strip() == '').sum()
    if empty_transcripts:
        print(f"WARNING: {empty_transcripts} rows produced empty transcripts after cleaning — check these manually.")

    manifest_df = df[['file_name', 'clean_whisper_transcript', 'prompt_text', 'language', 'grade_level']]
    manifest_df.to_csv('whisper_training_manifest.csv', index=False)
    print("-> Saved: whisper_training_manifest.csv")