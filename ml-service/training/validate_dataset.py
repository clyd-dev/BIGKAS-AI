"""
Step 0 — Run this FIRST, before parse_annotations.py.
Filters out incomplete rows (missing file_name, prompt_text, or annotated_transcript)
left by team members who haven't finished their entries yet.
Produces metadata_clean.csv for the rest of the pipeline to use.
"""

import pandas as pd

REQUIRED_COLUMNS = ['file_name', 'prompt_text', 'annotated_transcript', 'grade_level', 'language']

def is_blank(value) -> bool:
    """True if a cell is empty, whitespace-only, or pandas NaN."""
    if pd.isna(value):
        return True
    return str(value).strip() == ''

if __name__ == '__main__':
    df = pd.read_csv('metadata.csv')
    total_rows = len(df)

    missing_cols = [c for c in REQUIRED_COLUMNS if c not in df.columns]
    if missing_cols:
        raise ValueError(f"metadata.csv is missing expected columns: {missing_cols}")

    # Flag each row as complete/incomplete, with a reason
    def check_row(row):
        missing = [col for col in REQUIRED_COLUMNS if is_blank(row[col])]
        return ', '.join(missing) if missing else ''

    df['_missing_fields'] = df.apply(check_row, axis=1)
    df['_is_complete'] = df['_missing_fields'] == ''

    incomplete_df = df[~df['_is_complete']].copy()
    clean_df = df[df['_is_complete']].drop(columns=['_missing_fields', '_is_complete'])

    print(f"Total rows in metadata.csv: {total_rows}")
    print(f"Complete rows: {len(clean_df)}")
    print(f"Incomplete rows (skipped): {len(incomplete_df)}")

    if len(incomplete_df) > 0:
        print("\nSkipped rows and why:")
        for _, row in incomplete_df.iterrows():
            fname = row['file_name'] if not is_blank(row['file_name']) else '(no file_name)'
            print(f"  - {fname}: missing [{row['_missing_fields']}]")

    clean_df.to_csv('metadata_clean.csv', index=False)
    incomplete_df.to_csv('skipped_rows_report.csv', index=False)

    print(f"\n-> Saved: metadata_clean.csv ({len(clean_df)} usable rows)")
    print(f"-> Saved: skipped_rows_report.csv ({len(incomplete_df)} rows — send to your team so they know what's missing)")