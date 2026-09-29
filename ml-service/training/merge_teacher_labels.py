#!/usr/bin/env python3
"""
BIGKAS-AI Dataset Tool - Merge Teacher Labels
Merges the reviewed Filipino and English CSV files back into a master training dataset:
  1. labeled_features_for_review_fil.csv
  2. labeled_features_for_review_en.csv
  Outputs: labeled_features_master_confirmed.csv
"""

import os
import pandas as pd

def merge_teacher_labels(
    fil_csv="labeled_features_for_review_fil.csv",
    en_csv="labeled_features_for_review_en.csv",
    output_csv="labeled_features_master_confirmed.csv"
):
    dfs = []
    if os.path.exists(fil_csv):
        fil_df = pd.read_csv(fil_csv)
        dfs.append(fil_df)
        print(f"Loaded {len(fil_df)} rows from {fil_csv}")
    else:
        print(f"⚠️ Warning: '{fil_csv}' not found.")

    if os.path.exists(en_csv):
        en_df = pd.read_csv(en_csv)
        dfs.append(en_df)
        print(f"Loaded {len(en_df)} rows from {en_csv}")
    else:
        print(f"⚠️ Warning: '{en_csv}' not found.")

    if not dfs:
        print("❌ Error: No input CSV files found to merge.")
        return

    merged_df = pd.concat(dfs, ignore_index=True)
    merged_df.to_csv(output_csv, index=False)
    print(f"✅ Success! Merged dataset saved to '{output_csv}' with {len(merged_df)} total rows.")

if __name__ == "__main__":
    merge_teacher_labels()
