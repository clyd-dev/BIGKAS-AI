"""
BIGKAS-AI: Train Random Forest on Real Teacher-Validated Data (12 Features, 5 Classes)
=========================================================================================
Trains the weakness classifier on REAL annotated + teacher-validated data.
Handles combined labels (e.g. "1,3" or "1,4,2") by extracting a primary class
for training and preserving the full combo for reporting.

Run this AFTER merge_teacher_labels.py produces labeled_features_master_confirmed.csv.

Usage:
  cd ml-service/training
  python train_model_real_data.py
"""

import os
import re
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.preprocessing import StandardScaler
from sklearn.metrics import classification_report, accuracy_score
import joblib
import json

# ============================================================
# CONFIGURATION
# ============================================================

FEATURE_COLUMNS = [
    'accuracy_rate', 'words_per_minute', 'fluency_score',
    'substitution_rate', 'omission_rate', 'insertion_rate',
    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
    'self_correction_rate', 'pause_frequency', 'prosody_score'
]

WEAKNESS_LABELS = {
    0: 'Independent Reader / No Weakness',
    1: 'Phonemic Awareness',
    2: 'Decoding Accuracy',
    3: 'Oral Reading Fluency',
    4: 'Reading Comprehension',
}
VALID_CLASSES = set(WEAKNESS_LABELS.keys())

INPUT_CSV = 'labeled_features_master_confirmed.csv'
SAVE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
MODEL_FILENAME = 'weakness_classifier.joblib'
SCALER_FILENAME = 'feature_scaler.joblib'
METADATA_FILENAME = 'model_metadata.json'


# ============================================================
# LABEL PARSING — handles "1", "1,3", "1,4,2", "0"
# ============================================================

def parse_combo_label(value):
    """
    Parses teacher_confirmed_label into (primary, secondary, tertiary, raw_combo).
    '1'       -> (1, None, None, '1')
    '1,3'     -> (1, 3, None, '1,3')
    '1,4,2'   -> (1, 4, 2, '1,4,2')
    '0'       -> (0, None, None, '0')
    blank/NaN -> (None, None, None, None)
    """
    if pd.isna(value) or str(value).strip() == '':
        return (None, None, None, None)

    nums = re.findall(r'\d+', str(value))
    if not nums:
        return (None, None, None, None)

    nums = [int(n) for n in nums]
    primary = nums[0]
    secondary = nums[1] if len(nums) > 1 else None
    tertiary = nums[2] if len(nums) > 2 else None
    raw_combo = ','.join(str(n) for n in nums)

    return (primary, secondary, tertiary, raw_combo)


# ============================================================
# TRAINING
# ============================================================

def train_on_real_data(csv_path=INPUT_CSV):
    if not os.path.exists(csv_path):
        print(f"ERROR: {csv_path} not found.")
        print("Run merge_teacher_labels.py first.")
        return

    df = pd.read_csv(csv_path)
    rows_start = len(df)

    # ── Parse prosody (now plain numeric 0-10 from teacher dropdown) ──
    if 'prosody_score_teacher' in df.columns:
        df['prosody_score'] = pd.to_numeric(df['prosody_score_teacher'], errors='coerce')

    # ── Parse combined labels ──
    label_col = 'teacher_confirmed_label'
    if label_col not in df.columns or df[label_col].isna().all():
        label_col = 'provisional_weakness_label'
        print("WARNING: No teacher_confirmed_label found.")
        print("Training on provisional (rule-based) labels only.")
        print("Disclose this explicitly in your methodology section.\n")
        df['primary_label'] = pd.to_numeric(df[label_col], errors='coerce')
        df['secondary_label'] = None
        df['tertiary_label'] = None
        df['raw_combo_label'] = df['primary_label'].astype(str)
    else:
        parsed = df[label_col].apply(lambda v: pd.Series(
            parse_combo_label(v),
            index=['primary_label', 'secondary_label', 'tertiary_label', 'raw_combo_label']
        ))
        df = pd.concat([df, parsed], axis=1)

    # ── Validate: fail loudly on unexpected classes, not silently drop ──
    unexpected = df[df['primary_label'].notna() & ~df['primary_label'].isin(VALID_CLASSES)]
    if not unexpected.empty:
        print(f"ERROR: {len(unexpected)} rows have an out-of-range primary label:")
        print(unexpected[['file_name', label_col]].to_string(index=False))
        print(f"Valid classes are {sorted(VALID_CLASSES)}. Fix these rows and re-run.")
        return

    # ── Report combo usage before dropping anything ──
    combo_rows = df[df['secondary_label'].notna()]
    print(f"\n{'=' * 60}")
    print(f"Rows loaded from {csv_path}: {rows_start}")
    print(f"Rows with combined labels (2+ weaknesses): {len(combo_rows)}")
    if not combo_rows.empty:
        print("Combo label distribution:")
        print(combo_rows['raw_combo_label'].value_counts().to_string())
    print(f"{'=' * 60}\n")

    # ── Report what will be dropped, BEFORE dropping ──
    missing_label = df['primary_label'].isna().sum()
    missing_prosody = df['prosody_score'].isna().sum()
    missing_other = df[[c for c in FEATURE_COLUMNS if c != 'prosody_score']].isna().any(axis=1).sum()

    print(f"Rows with blank/unparsed primary label: {missing_label}")
    print(f"Rows with missing prosody_score:        {missing_prosody}")
    print(f"Rows with missing other features:       {missing_other}")

    df_clean = df.dropna(subset=FEATURE_COLUMNS + ['primary_label'])

    if len(df_clean) < 20:
        print(f"\nERROR: Only {len(df_clean)} usable samples. Need at least 20.")
        print("Collect more audio data or complete more teacher reviews before training.")
        return

    if len(df_clean) < 50:
        print(f"\nWARNING: Only {len(df_clean)} samples. Model will likely overfit.")
        print("Document this limitation in your capstone paper.")

    y = df_clean['primary_label'].astype(int).values
    X = df_clean[FEATURE_COLUMNS].values

    print(f"\n{'=' * 60}")
    print(f"Training on {len(df_clean)} real annotated samples")
    print(f"Label source: {label_col} (primary class extracted from combos)")
    print(f"Feature columns ({len(FEATURE_COLUMNS)}): {FEATURE_COLUMNS}")
    print(f"{'=' * 60}")

    # Class distribution
    unique_classes = np.unique(y)
    label_counts = pd.Series(y).value_counts().sort_index()
    print(f"\nLabel distribution (by primary class):")
    for label_id, count in label_counts.items():
        name = WEAKNESS_LABELS.get(int(label_id), f'Unknown ({label_id})')
        print(f"  {label_id} ({name}): {count} samples")

    missing_classes = VALID_CLASSES - set(unique_classes)
    if missing_classes:
        print(f"\nNOTE: No samples at all for class(es): {sorted(missing_classes)}")
        print("Model cannot predict a class it never saw. Document this gap.")

    # Train/test split
    min_class_count = min([np.sum(y == c) for c in unique_classes])
    can_stratify = min_class_count >= 2
    if not can_stratify:
        print("\nWARNING: Some classes have only 1 sample. Cannot stratify split.")

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42,
        stratify=y if can_stratify else None
    )

    scaler = StandardScaler()
    X_train_scaled = scaler.fit_transform(X_train)
    X_test_scaled = scaler.transform(X_test)

    rf_model = RandomForestClassifier(
        n_estimators=100,
        max_depth=8,
        min_samples_split=5,
        min_samples_leaf=3,
        random_state=42,
        n_jobs=-1,
        class_weight='balanced',
    )
    rf_model.fit(X_train_scaled, y_train)

    y_pred = rf_model.predict(X_test_scaled)
    test_accuracy = accuracy_score(y_test, y_pred)

    print(f"\n{'=' * 60}")
    print(f"TEST ACCURACY: {test_accuracy * 100:.2f}%")
    print(f"{'=' * 60}")

    target_names = [WEAKNESS_LABELS.get(int(c), str(c)) for c in sorted(unique_classes)]
    print(f"\nClassification Report:")
    print(classification_report(
        y_test, y_pred,
        target_names=target_names,
        labels=sorted(unique_classes),
        zero_division=0
    ))

    cv_mean = None
    cv_std = None
    if len(df_clean) >= 30:
        n_splits = min(5, len(df_clean) // 5)
        cv_scores = cross_val_score(rf_model, scaler.transform(X), y, cv=n_splits, scoring='accuracy')
        cv_mean = float(cv_scores.mean())
        cv_std = float(cv_scores.std())
        print(f"Cross-validation accuracy: {cv_mean:.4f} (+/- {cv_std:.4f})")
    else:
        print("Too few samples for cross-validation.")

    print(f"\nFeature Importance:")
    for name, importance in sorted(
        zip(FEATURE_COLUMNS, rf_model.feature_importances_),
        key=lambda x: x[1], reverse=True
    ):
        bar = '#' * int(importance * 50)
        print(f"  {name:25s} {importance:.4f} {bar}")

    os.makedirs(SAVE_DIR, exist_ok=True)
    model_path = os.path.join(SAVE_DIR, MODEL_FILENAME)
    scaler_path = os.path.join(SAVE_DIR, SCALER_FILENAME)
    metadata_path = os.path.join(SAVE_DIR, METADATA_FILENAME)

    joblib.dump(rf_model, model_path)
    joblib.dump(scaler, scaler_path)
    print(f"\nModel saved:  {model_path}")
    print(f"Scaler saved: {scaler_path}")

    metadata = {
        'model_type': 'Random Forest',
        'version': '3.0.0-12feature-5class-combo-aware',
        'test_accuracy': round(test_accuracy, 4),
        'cv_accuracy': round(cv_mean, 4) if cv_mean else None,
        'cv_std': round(cv_std, 4) if cv_std else None,
        'feature_columns': FEATURE_COLUMNS,
        'weakness_labels': {str(k): v for k, v in WEAKNESS_LABELS.items()},
        'classes_present_in_training': sorted(int(c) for c in unique_classes),
        'classes_missing_from_training': sorted(int(c) for c in missing_classes),
        'training_samples': len(X_train),
        'test_samples': len(X_test),
        'total_samples': len(df_clean),
        'rows_with_combo_labels': len(combo_rows),
        'label_source': label_col,
        'trained_with': 'real_annotated_data',
        'data_source': 'BIGKAS-AI Grade 3-6 collected audio (EN/FIL), teacher-validated labels',
        'scaler': 'StandardScaler',
        'class_distribution': {str(k): int(v) for k, v in label_counts.to_dict().items()},
        'notes': [
            f'Model uses {len(FEATURE_COLUMNS)} features (rates/scores, not raw counts).',
            'Combined teacher labels (e.g. "1,3", "1,4,2") were reduced to a PRIMARY '
            'class for training. Secondary/tertiary weaknesses preserved in source CSV '
            '(secondary_label, tertiary_label columns) but not used for this classifier.',
            'Feature scaler (StandardScaler) MUST be loaded alongside the model.',
            'Flask app.py loads weakness_classifier.joblib + feature_scaler.joblib.',
        ],
    }

    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2, default=str)
    print(f"Metadata saved: {metadata_path}")

    print(f"\nDONE. Restart Flask to load the new model:")
    print(f"  cd {SAVE_DIR}")
    print(f"  python app.py")


if __name__ == '__main__':
    train_on_real_data()