"""
BIGKAS: Reading Weakness Classification Model

This script trains a Decision Tree / Random Forest classifier
to identify reading weaknesses from assessment features.

Weakness Categories:
    1 = Phonemic Awareness Issues
    2 = Decoding Accuracy Issues
    3 = Oral Reading Fluency Issues
    4 = Reading Comprehension Issues

Features used:
    - accuracy_rate (0-100)
    - words_per_minute (0-200)
    - fluency_score (0-10)
    - substitution_rate (0-1)
    - omission_rate (0-1)
    - insertion_rate (0-1)
    - phonetic_error_rate (0-1)
    - vowel_error_rate (0-1)
    - blend_error_rate (0-1)
    - self_correction_rate (0-1)
    - pause_frequency (0-1)
    - prosody_score (0-10)
"""

import numpy as np
import pandas as pd
from sklearn.tree import DecisionTreeClassifier
from sklearn.ensemble import RandomForestClassifier, GradientBoostingClassifier
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score
from sklearn.preprocessing import StandardScaler
import joblib
import os
import json

# ============================================================
# SYNTHETIC TRAINING DATA GENERATOR
# ============================================================
# Since we don't have real assessment data yet, we generate
# synthetic data that models realistic reading difficulty patterns.
# This is replaced with real data as the system collects assessments.
# ============================================================

def generate_training_data(n_samples=2000, random_state=42):
    """
    Generate synthetic training data that represents realistic
    reading assessment patterns for each weakness category.
    """
    np.random.seed(random_state)

    data = []

    samples_per_class = n_samples // 4

    # ---- CLASS 1: PHONEMIC AWARENESS ISSUES ----
    # Characteristics: High phonetic/vowel/blend errors, moderate accuracy,
    # may have okay fluency if memorized
    for _ in range(samples_per_class):
        data.append({
            'accuracy_rate': np.random.normal(65, 12),
            'words_per_minute': np.random.normal(55, 18),
            'fluency_score': np.random.normal(4.5, 1.5),
            'substitution_rate': np.random.normal(0.25, 0.08),
            'omission_rate': np.random.normal(0.10, 0.05),
            'insertion_rate': np.random.normal(0.05, 0.03),
            'phonetic_error_rate': np.random.normal(0.40, 0.12),  # HIGH
            'vowel_error_rate': np.random.normal(0.35, 0.10),     # HIGH
            'blend_error_rate': np.random.normal(0.30, 0.10),     # HIGH
            'self_correction_rate': np.random.normal(0.05, 0.03),
            'pause_frequency': np.random.normal(0.30, 0.10),
            'prosody_score': np.random.normal(4.0, 1.5),
            'label': 1
        })

    # ---- CLASS 2: DECODING ACCURACY ISSUES ----
    # Characteristics: Low accuracy, many substitutions, moderate fluency
    for _ in range(samples_per_class):
        data.append({
            'accuracy_rate': np.random.normal(55, 15),            # LOW
            'words_per_minute': np.random.normal(45, 15),
            'fluency_score': np.random.normal(3.5, 1.5),
            'substitution_rate': np.random.normal(0.35, 0.10),    # HIGH
            'omission_rate': np.random.normal(0.15, 0.06),
            'insertion_rate': np.random.normal(0.08, 0.04),
            'phonetic_error_rate': np.random.normal(0.15, 0.08),
            'vowel_error_rate': np.random.normal(0.15, 0.08),
            'blend_error_rate': np.random.normal(0.12, 0.06),
            'self_correction_rate': np.random.normal(0.10, 0.05),
            'pause_frequency': np.random.normal(0.35, 0.10),
            'prosody_score': np.random.normal(3.5, 1.5),
            'label': 2
        })

    # ---- CLASS 3: ORAL READING FLUENCY ISSUES ----
    # Characteristics: Decent accuracy but very slow, many pauses,
    # low prosody, word-by-word reading
    for _ in range(samples_per_class):
        data.append({
            'accuracy_rate': np.random.normal(85, 8),             # OKAY
            'words_per_minute': np.random.normal(35, 12),         # VERY LOW
            'fluency_score': np.random.normal(2.5, 1.0),          # LOW
            'substitution_rate': np.random.normal(0.08, 0.04),
            'omission_rate': np.random.normal(0.05, 0.03),
            'insertion_rate': np.random.normal(0.03, 0.02),
            'phonetic_error_rate': np.random.normal(0.08, 0.05),
            'vowel_error_rate': np.random.normal(0.06, 0.04),
            'blend_error_rate': np.random.normal(0.05, 0.03),
            'self_correction_rate': np.random.normal(0.12, 0.05),
            'pause_frequency': np.random.normal(0.55, 0.12),      # HIGH
            'prosody_score': np.random.normal(2.5, 1.0),          # LOW
            'label': 3
        })

    # ---- CLASS 4: READING COMPREHENSION ISSUES ----
    # Characteristics: Can decode (okay accuracy), reads at okay speed,
    # but low prosody/expression indicating lack of understanding,
    # high omission rate (skips words without noticing meaning loss)
    for _ in range(samples_per_class):
        data.append({
            'accuracy_rate': np.random.normal(82, 10),
            'words_per_minute': np.random.normal(70, 20),         # OKAY
            'fluency_score': np.random.normal(4.0, 1.5),
            'substitution_rate': np.random.normal(0.10, 0.05),
            'omission_rate': np.random.normal(0.18, 0.07),        # HIGH
            'insertion_rate': np.random.normal(0.06, 0.03),
            'phonetic_error_rate': np.random.normal(0.08, 0.05),
            'vowel_error_rate': np.random.normal(0.06, 0.04),
            'blend_error_rate': np.random.normal(0.05, 0.03),
            'self_correction_rate': np.random.normal(0.03, 0.02), # LOW
            'pause_frequency': np.random.normal(0.20, 0.08),
            'prosody_score': np.random.normal(3.0, 1.2),          # LOW
            'label': 4
        })

    df = pd.DataFrame(data)

    # Clip values to valid ranges
    df['accuracy_rate'] = df['accuracy_rate'].clip(0, 100)
    df['words_per_minute'] = df['words_per_minute'].clip(0, 200)
    df['fluency_score'] = df['fluency_score'].clip(0, 10)
    df['prosody_score'] = df['prosody_score'].clip(0, 10)

    rate_columns = ['substitution_rate', 'omission_rate', 'insertion_rate',
                    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
                    'self_correction_rate', 'pause_frequency']
    for col in rate_columns:
        df[col] = df[col].clip(0, 1)

    return df


def train_model(save_path='../models'):
    """
    Train the reading weakness classification model.
    """
    print("=" * 60)
    print("BASA-PLUS-AI: Training Reading Weakness Classifier")
    print("=" * 60)

    # Generate training data
    print("\n[1/5] Generating training data...")
    df = generate_training_data(n_samples=2000)
    print(f"  Generated {len(df)} samples ({len(df)//4} per class)")

    # Prepare features and labels
    feature_columns = [
        'accuracy_rate', 'words_per_minute', 'fluency_score',
        'substitution_rate', 'omission_rate', 'insertion_rate',
        'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
        'self_correction_rate', 'pause_frequency', 'prosody_score'
    ]

    X = df[feature_columns].values
    y = df['label'].values

    # Split data
    print("\n[2/5] Splitting data (80% train, 20% test)...")
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=y
    )
    print(f"  Training set: {len(X_train)} samples")
    print(f"  Test set:     {len(X_test)} samples")

    # Scale features
    scaler = StandardScaler()
    X_train_scaled = scaler.fit_transform(X_train)
    X_test_scaled = scaler.transform(X_test)

    # Train multiple models and select the best
    print("\n[3/5] Training models...")

    models = {
        'Decision Tree': DecisionTreeClassifier(
            max_depth=8,
            min_samples_split=10,
            min_samples_leaf=5,
            random_state=42
        ),
        'Random Forest': RandomForestClassifier(
            n_estimators=100,
            max_depth=10,
            min_samples_split=5,
            min_samples_leaf=3,
            random_state=42,
            n_jobs=-1
        ),
        'Gradient Boosting': GradientBoostingClassifier(
            n_estimators=100,
            max_depth=5,
            learning_rate=0.1,
            random_state=42
        )
    }

    best_model_name = None
    best_model = None
    best_accuracy = 0

    for name, model in models.items():
        model.fit(X_train_scaled, y_train)
        accuracy = model.score(X_test_scaled, y_test)

        # Cross-validation
        cv_scores = cross_val_score(model, X_train_scaled, y_train, cv=5)
        cv_mean = cv_scores.mean()

        print(f"\n  {name}:")
        print(f"    Test Accuracy:  {accuracy:.4f}")
        print(f"    CV Mean:        {cv_mean:.4f} (+/- {cv_scores.std():.4f})")

        if accuracy > best_accuracy:
            best_accuracy = accuracy
            best_model = model
            best_model_name = name

    print(f"\n  Best Model: {best_model_name} ({best_accuracy:.4f})")

    # Detailed evaluation
    print(f"\n[4/5] Evaluating {best_model_name}...")
    y_pred = best_model.predict(X_test_scaled)

    weakness_names = {
        1: 'Phonemic Awareness',
        2: 'Decoding Accuracy',
        3: 'Oral Reading Fluency',
        4: 'Reading Comprehension'
    }

    print("\n  Classification Report:")
    print(classification_report(
        y_test, y_pred,
        target_names=[weakness_names[i] for i in sorted(weakness_names.keys())]
    ))

    # Feature importance
    if hasattr(best_model, 'feature_importances_'):
        importances = best_model.feature_importances_
        feature_importance = sorted(
            zip(feature_columns, importances),
            key=lambda x: x[1],
            reverse=True
        )
        print("  Feature Importance:")
        for feat, imp in feature_importance:
            bar = '#' * int(imp * 50)
            print(f"    {feat:25s} {imp:.4f} {bar}")

    # Save model
    print(f"\n[5/5] Saving model to {save_path}...")
    os.makedirs(save_path, exist_ok=True)

    model_path = os.path.join(save_path, 'weakness_classifier.joblib')
    scaler_path = os.path.join(save_path, 'feature_scaler.joblib')
    metadata_path = os.path.join(save_path, 'model_metadata.json')

    joblib.dump(best_model, model_path)
    joblib.dump(scaler, scaler_path)

    metadata = {
        'model_type': best_model_name,
        'accuracy': round(best_accuracy, 4),
        'feature_columns': feature_columns,
        'weakness_labels': weakness_names,
        'training_samples': len(X_train),
        'test_samples': len(X_test),
        'version': '1.0.0',
        'trained_with': 'synthetic_data'
    }

    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)

    print(f"\n  Model saved: {model_path}")
    print(f"  Scaler saved: {scaler_path}")
    print(f"  Metadata saved: {metadata_path}")
    print("\n" + "=" * 60)
    print("Training complete!")
    print("=" * 60)

    return best_model, scaler, metadata


if __name__ == '__main__':
    train_model()
