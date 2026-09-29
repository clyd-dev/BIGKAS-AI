# ml/training/check_audio_files.py
import pandas as pd
import os

df = pd.read_csv('metadata_clean.csv')
audio_dir = './audio_files'

if not os.path.isdir(audio_dir):
    print(f"FOLDER DOES NOT EXIST: {audio_dir}")
    print("Create it and place your downloaded .wav files inside.")
else:
    found, missing = 0, []
    for fname in df['file_name']:
        path = os.path.join(audio_dir, str(fname))
        if os.path.isfile(path):
            found += 1
        else:
            missing.append(fname)

    print(f"Found: {found} / {len(df)}")
    if missing:
        print(f"Missing files (first 10 shown): {missing[:10]}")