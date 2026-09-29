"""
BIGKAS-AI: Train Random Forest on Real Teacher-Validated Data (12 Features)
============================================================================
Trains the weakness classifier on REAL annotated + teacher-validated data.

Run this AFTER the teacher has reviewed labeled_features_for_review.csv
and saved their completed version as labeled_features_reviewed.csv.

Output files (placed in parent directory ml-service/):
  weakness_classifier.joblib  ← the trained RF model (Flask loads this)
  feature_scaler.joblib       ← StandardScaler (Flask loads this)
  model_metadata.json         ← training metadata for documentation

IMPORTANT: FEATURE_COLUMNS here MUST match EXACTLY:
  - ml-service/app.py FEATURE_COLUMNS
  - ReadingAnalyzerService.php $mlFeatures array keys
  - parse_annotations.py output columns
If you rename any column, you must rename it in ALL 4 files.

Prerequisites:
  pip install pandas scikit-learn joblib

Usage:
  cd F:\\MyApp\\BIGKAS-AI\\ml-service\\training

  # Step 1: Generate provisional labels (if not done yet)
  python generate_provisional_labels.py

  # Step 2: Teacher reviews and saves as labeled_features_reviewed.csv

  # Step 3: Train the model
  python train_model_real_data.py

  # Step 4: Restart Flask
  cd .. && python app.py
"""

import os
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

# These 12 columns MUST match Flask app.py FEATURE_COLUMNS EXACTLY
FEATURE_COLUMNS = [
    'accuracy_rate', 'words_per_minute', 'fluency_score',
    'substitution_rate', 'omission_rate', 'insertion_rate',
    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
    'self_correction_rate', 'pause_frequency', 'prosody_score'
]

WEAKNESS_LABELS = {
    0: 'Independent Reader',
    1: 'Phonemic Awareness',
    2: 'Decoding Accuracy',
    3: 'Oral Reading Fluency',
    4: 'Reading Comprehension',
}

INPUT_CSV = 'labeled_features_reviewed.csv'
# Output to PARENT directory (ml-service/) where Flask loads from
SAVE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
MODEL_FILENAME = 'weakness_classifier.joblib'
SCALER_FILENAME = 'feature_scaler.joblib'
METADATA_FILENAME = 'model_metadata.json'


# ============================================================
# TRAINING
# ============================================================

def train_on_real_data(csv_path=INPUT_CSV):
    if not os.path.exists(csv_path):
        print(f"ERROR: {csv_path} not found.")
        print(f"Complete these steps first:")
        print(f"  1. Run: python parse_annotations.py")
        print(f"  2. Run: python generate_provisional_labels.py")
        print(f"  3. Send labeled_features_for_review.csv to teacher")
        print(f"  4. Save teacher's completed file as: {csv_path}")
        return

    df = pd.read_csv(csv_path)

    # Merge teacher-filled prosody into the main column
    if 'prosody_score_teacher' in df.columns:
        df['prosody_score'] = df['prosody_score'].fillna(
            pd.to_numeric(df['prosody_score_teacher'], errors='coerce')
        )

    # Fill remaining null prosody with a neutral default (5.0)
    # and document this in metadata
    prosody_filled = df['prosody_score'].isna().sum()
    df['prosody_score'] = df['prosody_score'].fillna(5.0)

    # Determine label column
    label_col = 'teacher_confirmed_label'
    if label_col not in df.columns or df[label_col].isna().all() or (df[label_col].astype(str).str.strip() == '').all():
        label_col = 'provisional_weakness_label'
        print("WARNING: No teacher_confirmed_label found.")
        print("Training on provisional (rule-based) labels only.")
        print("Disclose this explicitly in your methodology section.")

    # Clean labels
    df[label_col] = pd.to_numeric(df[label_col], errors='coerce')
    df = df.dropna(subset=FEATURE_COLUMNS + [label_col])

    if len(df) < 20:
        print(f"ERROR: Only {len(df)} usable samples. Need at least 20.")
        print("Collect more audio data before training.")
        return

    if len(df) < 50:
        print(f"WARNING: Only {len(df)} samples. Model will likely overfit.")
        print("Document this limitation in your capstone paper.")

    y = df[label_col].astype(int).values
    X = df[FEATURE_COLUMNS].values

    print(f"\n{'=' * 60}")
    print(f"Training on {len(df)} real annotated samples")
    print(f"Label source: {label_col}")
    print(f"Feature columns ({len(FEATURE_COLUMNS)}): {FEATURE_COLUMNS}")
    print(f"Prosody scores filled with default (5.0): {prosody_filled}")
    print(f"{'=' * 60}")

    # Report class distribution
    unique_classes = np.unique(y)
    label_counts = pd.Series(y).value_counts().sort_index()
    print(f"\nLabel distribution:")
    for label_id, count in label_counts.items():
        name = WEAKNESS_LABELS.get(int(label_id), f'Unknown ({label_id})')
        print(f"  {label_id} ({name}): {count} samples")

    # Train/test split
    min_class_count = min([np.sum(y == c) for c in unique_classes])
    can_stratify = min_class_count >= 2

    if not can_stratify:
        print("\nWARNING: Some classes have only 1 sample. Cannot stratify split.")

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42,
        stratify=y if can_stratify else None
    )

    # Scale features (StandardScaler)
    scaler = StandardScaler()
    X_train_scaled = scaler.fit_transform(X_train)
    X_test_scaled = scaler.transform(X_test)

    # Train Random Forest
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

    # Evaluate
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

    # Cross-validation (if enough data)
    cv_mean = None
    cv_std = None
    if len(df) >= 30:
        n_splits = min(5, len(df) // 5)
        cv_scores = cross_val_score(rf_model, scaler.transform(X), y, cv=n_splits, scoring='accuracy')
        cv_mean = float(cv_scores.mean())
        cv_std = float(cv_scores.std())
        print(f"Cross-validation accuracy: {cv_mean:.4f} (+/- {cv_std:.4f})")
    else:
        print("Too few samples for cross-validation.")

    # Feature importance
    print(f"\nFeature Importance:")
    for name, importance in sorted(
        zip(FEATURE_COLUMNS, rf_model.feature_importances_),
        key=lambda x: x[1], reverse=True
    ):
        bar = '#' * int(importance * 50)
        print(f"  {name:25s} {importance:.4f} {bar}")

    # Save model + scaler to ml-service/ (parent directory)
    os.makedirs(SAVE_DIR, exist_ok=True)

    model_path = os.path.join(SAVE_DIR, MODEL_FILENAME)
    scaler_path = os.path.join(SAVE_DIR, SCALER_FILENAME)
    metadata_path = os.path.join(SAVE_DIR, METADATA_FILENAME)

    joblib.dump(rf_model, model_path)
    joblib.dump(scaler, scaler_path)
    print(f"\nModel saved:  {model_path}")
    print(f"Scaler saved: {scaler_path}")

    # Save metadata
    metadata = {
        'model_type': 'Random Forest',
        'version': '2.0.0-12feature-real-data',
        'test_accuracy': round(test_accuracy, 4),
        'cv_accuracy': round(cv_mean, 4) if cv_mean else None,
        'cv_std': round(cv_std, 4) if cv_std else None,
        'feature_columns': FEATURE_COLUMNS,
        'weakness_labels': {str(k): v for k, v in WEAKNESS_LABELS.items()},
        'training_samples': len(X_train),
        'test_samples': len(X_test),
        'total_samples': len(df),
        'label_source': label_col,
        'trained_with': 'real_annotated_data',
        'data_source': 'BIGKAS-AI Grade 3-6 collected audio (EN/FIL), teacher-validated labels',
        'scaler': 'StandardScaler',
        'prosody_defaults_filled': prosody_filled,
        'class_distribution': label_counts.to_dict(),
        'notes': [
            f'Model uses {len(FEATURE_COLUMNS)} features (rates/scores, not raw counts).',
            'Feature scaler (StandardScaler) MUST be loaded alongside the model.',
            'Flask app.py loads weakness_classifier.joblib + feature_scaler.joblib from ml-service/.',
            f'Prosody score was NULL for {prosody_filled} rows and filled with default 5.0.',
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
