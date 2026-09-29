"""
Trains the weakness classifier on REAL teacher-validated data.
Run this AFTER Step 3 review is complete.
"""

import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
from sklearn.metrics import classification_report
import joblib
import json
import os

FEATURE_COLUMNS = [
    'accuracy_rate', 'words_per_minute', 'fluency_score',
    'substitution_rate', 'omission_rate', 'insertion_rate',
    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
    'self_correction_rate', 'pause_frequency', 'prosody_score'
]

def train_on_real_data(csv_path='labeled_features_reviewed.csv', save_path='../models'):
    df = pd.read_csv(csv_path)

    # Merge teacher-filled prosody into the main column
    if 'prosody_score_teacher' in df.columns:
        df['prosody_score'] = df['prosody_score'].fillna(
            pd.to_numeric(df['prosody_score_teacher'], errors='coerce')
        )

    label_col = 'teacher_confirmed_label'
    if label_col not in df.columns or df[label_col].isna().all() or (df[label_col] == '').all():
        label_col = 'provisional_weakness_label'
        print("WARNING: no teacher_confirmed_label found — training on provisional labels only. "
              "Disclose this explicitly in your methodology.")

    df = df.dropna(subset=FEATURE_COLUMNS + [label_col])
    print(f"Training on {len(df)} fully-labeled real samples out of original dataset")

    if len(df) < 50:
        print("WARNING: small dataset. Document this limitation in your paper explicitly.")

    label_counts = df[label_col].value_counts()
    print(f"Label distribution:\n{label_counts}")

    X = df[FEATURE_COLUMNS].values
    y = df[label_col].astype(int).values

    stratify_arg = y if min(label_counts) >= 2 else None
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=stratify_arg
    )

    scaler = StandardScaler()
    X_train_scaled = scaler.fit_transform(X_train)
    X_test_scaled = scaler.transform(X_test)

    model = RandomForestClassifier(
        n_estimators=100, max_depth=8, min_samples_split=5,
        min_samples_leaf=3, random_state=42, n_jobs=-1
    )
    model.fit(X_train_scaled, y_train)

    accuracy = model.score(X_test_scaled, y_test)
    print(f"\nTest accuracy on REAL data: {accuracy:.4f}")
    print(classification_report(y_test, model.predict(X_test_scaled), zero_division=0))

    os.makedirs(save_path, exist_ok=True)
    joblib.dump(model, os.path.join(save_path, 'weakness_classifier.joblib'))
    joblib.dump(scaler, os.path.join(save_path, 'feature_scaler.joblib'))

    metadata = {
        'model_type': 'Random Forest',
        'accuracy': round(accuracy, 4),
        'feature_columns': FEATURE_COLUMNS,
        'training_samples': len(X_train),
        'test_samples': len(X_test),
        'version': '2.0.0',
        'trained_with': 'real_annotated_data',
        'data_source': 'BIGKAS-AI Grade 3-6 collected audio (EN/FIL), teacher-validated labels',
        'label_source': label_col,
    }
    with open(os.path.join(save_path, 'model_metadata.json'), 'w') as f:
        json.dump(metadata, f, indent=2)

    print(f"\nModel saved to {save_path}/ — restart Flask (ml/api/app.py) to load it.")

if __name__ == '__main__':
    train_on_real_data()