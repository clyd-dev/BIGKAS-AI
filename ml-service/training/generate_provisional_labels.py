"""
BIGKAS-AI: Generate Provisional Weakness Labels
=================================================
Applies rule-based logic to generate provisional weakness labels (1-4)
for teacher review. Merges click_to_play links so the teacher can
listen to clips while reviewing.

Output: labeled_features_for_review.csv (send to teacher)

Usage:
  cd F:\\MyApp\\BIGKAS-AI\\ml-service\\training
  python generate_provisional_labels.py
"""

import pandas as pd


def rule_based_label(row) -> int:
    """
    Score each weakness category and return the highest-scoring one.
    Mirrors ReadingAnalyzerService::ruleBasedClassification() in PHP.

    Labels: 0=Independent, 1=Phonemic, 2=Decoding, 3=Fluency, 4=Comprehension
    """
    scores = {0: 0, 1: 0, 2: 0, 3: 0, 4: 0}

    # Independent Reader (No Weakness)
    accuracy = row.get('accuracy_rate', 0) or 0
    wpm = row.get('words_per_minute', 60)
    if pd.isna(wpm): wpm = 60
    fluency = row.get('fluency_score', 5)
    
    if accuracy >= 95 and wpm >= 60 and fluency >= 8:
        # High base score for excellent reading
        scores[0] = (accuracy - 90) * 2 + (fluency - 5) * 5
        
    # Phonemic Awareness: phonetic/vowel/blend error patterns
    scores[1] = (
        row.get('phonetic_error_rate', 0) +
        row.get('vowel_error_rate', 0) +
        row.get('blend_error_rate', 0)
    ) * 30

    # Decoding Accuracy: low accuracy + high substitution rate
    accuracy = row.get('accuracy_rate', 0) or 0
    if accuracy < 90:
        scores[2] += (90 - accuracy) * 0.8
    scores[2] += row.get('substitution_rate', 0) * 40

    # Fluency: slow reading, many pauses
    wpm = row.get('words_per_minute', 60)
    if pd.isna(wpm):
        wpm = 60
    if wpm < 80:
        scores[3] += (80 - wpm) * 0.5

    fluency = row.get('fluency_score', 5)
    if pd.notna(fluency) and fluency < 6:
        scores[3] += (6 - fluency) * 5

    scores[3] += row.get('pause_frequency', 0) * 15

    # Comprehension: many omissions (skipping words)
    omission_rate = row.get('omission_rate', 0)
    if omission_rate > 0.1:
        scores[4] += omission_rate * 30

    # The "Speed-Reading without Comprehension" Trap
    # Fast reading but high skipping means they aren't monitoring meaning
    if wpm > 80 and omission_rate > 0.1:
        scores[4] += 25

    return max(scores, key=scores.get)


if __name__ == '__main__':
    FEATURES_CSV = 'extracted_features.csv'
    METADATA_CSV = 'metadata_clean.csv'

    features_df = pd.read_csv(FEATURES_CSV)
    original_df = pd.read_csv(METADATA_CSV)

    # Generate provisional labels
    features_df['provisional_weakness_label'] = features_df.apply(rule_based_label, axis=1)

    # Add blank columns for teacher to fill during review
    features_df['teacher_confirmed_label'] = ''
    features_df['prosody_score_teacher'] = ''
    features_df['reduplication_review'] = ''  # "valid word" or "real stutter" per flagged token

    # Merge the Google Drive link so the teacher can click and listen
    if 'click_to_play' in original_df.columns:
        features_df = features_df.merge(
            original_df[['file_name', 'click_to_play']],
            on='file_name', how='left'
        )
        print("Merged click_to_play links from metadata.csv.")
    else:
        print("WARNING: 'click_to_play' column not found in metadata.csv.")
        print("Teacher will not have audio links in the review file.")

    features_df.to_csv('labeled_features_for_review.csv', index=False)

    # Report label distribution
    dist = features_df['provisional_weakness_label'].value_counts().sort_index()
    label_names = {0: 'Independent', 1: 'Phonemic', 2: 'Decoding', 3: 'Fluency', 4: 'Comprehension'}
    print(f"\nProvisional label distribution:")
    for label_id, count in dist.items():
        print(f"  {label_id} ({label_names.get(label_id, '?')}): {count} samples")

    print(f"\n-> Saved: labeled_features_for_review.csv")
    print(f"   Send this file to the reading teacher for validation.")
    print(f"   Teacher fills: teacher_confirmed_label, prosody_score_teacher, reduplication_review")
    print(f"   Save completed file as: labeled_features_reviewed.csv")
