#!/usr/bin/env python3
"""
BIGKAS-AI Dataset Tool - Add Transcript Context & Auto Word-Wrap
Merges 'prompt_text' and 'annotated_transcript' from metadata_clean.csv into
labeled_features_for_review_fil.csv and labeled_features_for_review_en.csv based on file_name.
Applies word wrapping (max width = 60 chars) to prevent wide columns in Google Sheets.
Also moves 'flagged_hyphenated_tokens' directly after 'duration_sec'.
"""

import os
import textwrap
import pandas as pd

def wrap_long_text(text, max_width=60):
    """
    Inserts newline characters (\\n) into long text strings at word boundaries
    so Google Sheets wraps the text vertically inside the cell instead of stretching horizontally.
    """
    if pd.isna(text) or not str(text).strip():
        return text
    # Clean up inconsistent spaces/newlines
    clean_str = ' '.join(str(text).split())
    return textwrap.fill(clean_str, width=max_width)

def process_review_csv(review_csv_path, metadata_df, output_csv_path=None, max_text_width=60):
    if not os.path.exists(review_csv_path):
        print(f"⚠️ Warning: File '{review_csv_path}' not found in current directory. Skipping.")
        return

    if output_csv_path is None:
        output_csv_path = review_csv_path

    review_df = pd.read_csv(review_csv_path)
    print(f"\n📄 Processing '{review_csv_path}' ({len(review_df)} rows)...")

    # 1. Prepare columns to merge from metadata_clean.csv
    cols_to_merge = ['file_name']
    context_cols = ['prompt_text', 'annotated_transcript']
    
    for col in context_cols:
        if col in metadata_df.columns:
            cols_to_merge.append(col)
            # Remove from review_df if it already exists to prevent duplicate columns (_x, _y)
            if col in review_df.columns:
                review_df = review_df.drop(columns=[col])

    # Left merge to keep all existing teacher entries and audio links intact
    merged_df = review_df.merge(
        metadata_df[cols_to_merge],
        on='file_name',
        how='left'
    )

    # 2. Word-wrap long text columns so Google Sheets cells display compactly
    for col in context_cols:
        if col in merged_df.columns:
            merged_df[col] = merged_df[col].apply(lambda x: wrap_long_text(x, max_width=max_text_width))

    # 3. Reorder Columns logically
    existing_cols = list(merged_df.columns)

    # Remove context_cols from current position list to re-insert them cleanly
    for col in context_cols:
        if col in existing_cols:
            existing_cols.remove(col)

    # Determine anchor position for prompt_text & annotated_transcript (after click_to_play or file_name)
    anchor = 'click_to_play' if 'click_to_play' in existing_cols else 'file_name'
    if anchor in existing_cols:
        anchor_idx = existing_cols.index(anchor) + 1
    else:
        anchor_idx = 1

    # Insert prompt_text and annotated_transcript next to the anchor
    for col in reversed([c for c in context_cols if c in merged_df.columns]):
        existing_cols.insert(anchor_idx, col)

    # 4. Move 'flagged_hyphenated_tokens' directly after 'duration_sec'
    if 'flagged_hyphenated_tokens' in existing_cols and 'duration_sec' in existing_cols:
        existing_cols.remove('flagged_hyphenated_tokens')
        dur_idx = existing_cols.index('duration_sec') + 1
        existing_cols.insert(dur_idx, 'flagged_hyphenated_tokens')

    # Apply new column ordering
    final_df = merged_df[existing_cols]

    # Save output
    final_df.to_csv(output_csv_path, index=False)
    print(f"✅ Successfully updated '{output_csv_path}' ({len(final_df)} rows, {len(final_df.columns)} columns)")

def main():
    metadata_file = "metadata_clean.csv"
    if not os.path.exists(metadata_file):
        if os.path.exists("metadata.csv"):
            metadata_file = "metadata.csv"
            print("ℹ️ 'metadata_clean.csv' not found, falling back to 'metadata.csv'.")
        else:
            print(f"❌ Error: Could not find '{metadata_file}' or 'metadata.csv' in current directory.")
            return

    print(f"📖 Loading reference metadata from '{metadata_file}'...")
    meta_df = pd.read_csv(metadata_file)

    # Deduplicate metadata by file_name if needed
    meta_df = meta_df.drop_duplicates(subset=['file_name'])

    # Process both language review sheets with text wrapping at 60 characters
    process_review_csv("labeled_features_for_review_fil.csv", meta_df, max_text_width=60)
    process_review_csv("labeled_features_for_review_en.csv", meta_df, max_text_width=60)

if __name__ == "__main__":
    main()
