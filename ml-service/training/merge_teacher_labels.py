#!/usr/bin/env python3
"""
BIGKAS-AI Dataset Tool - Merge Teacher Labels
Merges the reviewed Filipino and English CSV files back into a master training dataset.
"""

import os
import pandas as pd

def merge_teacher_labels(
    fil_csv="labeled_features_reviewed_fil.csv",
    en_csv="labeled_features_reviewed_en.csv",
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

    # Column consistency check
    if len(dfs) == 2 and set(dfs[0].columns) != set(dfs[1].columns):
        mismatch = set(dfs[0].columns) ^ set(dfs[1].columns)
        print(f"⚠️ Warning: column mismatch between files: {mismatch}")

    merged_df = pd.concat(dfs, ignore_index=True)

    # Duplicate file_name check
    dupes = merged_df[merged_df['file_name'].duplicated(keep=False)]
    if not dupes.empty:
        print(f"⚠️ Warning: {len(dupes)} duplicate file_name rows found:")
        print(dupes['file_name'].tolist())
        merged_df = merged_df.drop_duplicates(subset='file_name', keep='first')
        print(f"   Kept first occurrence, dropped duplicates.")

    # Review completeness report
    if 'teacher_confirmed_label' in merged_df.columns:
        blank = merged_df['teacher_confirmed_label'].isna() | \
                (merged_df['teacher_confirmed_label'].astype(str).str.strip() == '')
        print(f"\nReview completeness: {len(merged_df) - blank.sum()} / {len(merged_df)} rows labeled")
        if 'language' in merged_df.columns:
            print(merged_df.groupby('language')['teacher_confirmed_label']
                  .apply(lambda s: (s.notna() & (s.astype(str).str.strip() != '')).sum()))

    merged_df.to_csv(output_csv, index=False)
    print(f"\n✅ Success! Merged dataset saved to '{output_csv}' with {len(merged_df)} total rows.")

if __name__ == "__main__":
    merge_teacher_labels()