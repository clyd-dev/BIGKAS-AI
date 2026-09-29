#!/usr/bin/env python3
"""
BIGKAS-AI Dataset Tool - Split Features by Language
Splits labeled_features_for_review.csv into two distinct CSV files:
  1. labeled_features_for_review_fil.csv (Filipino)
  2. labeled_features_for_review_en.csv (English)
"""

import os
import pandas as pd

def split_features_by_language(input_csv="labeled_features_for_review.csv"):
    if not os.path.exists(input_csv):
        print(f"❌ Error: Could not find '{input_csv}' in the current directory.")
        return

    df = pd.read_csv(input_csv)
    
    if 'language' not in df.columns:
        print("❌ Error: 'language' column missing from input CSV.")
        return

    # Filter by language (case-insensitive)
    fil_df = df[df['language'].astype(str).str.strip().str.lower() == 'fil'].copy()
    en_df = df[df['language'].astype(str).str.strip().str.lower() == 'en'].copy()

    # Add new review columns for the teachers
    for subset_df in [fil_df, en_df]:
        if 'annotation_issue' not in subset_df.columns:
            subset_df['annotation_issue'] = ''
        if 'teacher_notes' not in subset_df.columns:
            subset_df['teacher_notes'] = ''

    fil_output = "labeled_features_for_review_fil.csv"
    en_output = "labeled_features_for_review_en.csv"

    fil_df.to_csv(fil_output, index=False)
    en_df.to_csv(en_output, index=False)

    print(f"✅ Success! Dataset split complete:")
    print(f"   • {fil_output}: {len(fil_df)} rows")
    print(f"   • {en_output}: {len(en_df)} rows")

if __name__ == "__main__":
    split_features_by_language()
