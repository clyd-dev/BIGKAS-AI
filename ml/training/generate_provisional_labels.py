"""
Applies existing production rule-based logic to generate provisional weakness labels.
Merges in click_to_play links so the teacher can review clips directly.
"""

import pandas as pd

def rule_based_label(row) -> int:
    scores = {1: 0, 2: 0, 3: 0, 4: 0}

    scores[1] = (row['phonetic_error_rate'] + row['vowel_error_rate'] + row['blend_error_rate']) * 30

    if row['accuracy_rate'] < 90:
        scores[2] += (90 - row['accuracy_rate']) * 0.8
    scores[2] += row['substitution_rate'] * 40

    wpm = row['words_per_minute'] if pd.notna(row['words_per_minute']) else 60
    if wpm < 80:
        scores[3] += (80 - wpm) * 0.5
    if pd.notna(row['fluency_score']) and row['fluency_score'] < 6:
        scores[3] += (6 - row['fluency_score']) * 5
    scores[3] += row['pause_frequency'] * 15

    if row['omission_rate'] > 0.1:
        scores[4] += row['omission_rate'] * 30

    return max(scores, key=scores.get)


if __name__ == '__main__':
    features_df = pd.read_csv('extracted_features.csv')
    original_df = pd.read_csv('metadata.csv')

    features_df['provisional_weakness_label'] = features_df.apply(rule_based_label, axis=1)
    features_df['teacher_confirmed_label'] = ''
    features_df['prosody_score_teacher'] = ''
    features_df['reduplication_review'] = ''  # teacher marks: "valid word" or "real stutter" per flagged token

    features_df = features_df.merge(
        original_df[['file_name', 'click_to_play']],
        on='file_name', how='left'
    )

    features_df.to_csv('labeled_features_for_review.csv', index=False)
    print("-> Saved: labeled_features_for_review.csv — send this to the reading teacher.")