import os
import tempfile
from flask import Flask, request, jsonify
import joblib
import pandas as pd

app = Flask(__name__)

# ============================================================
# FEATURE COLUMNS — must match training script AND Laravel EXACTLY
# ============================================================
FEATURE_COLUMNS = [
    'accuracy_rate', 'words_per_minute', 'fluency_score',
    'substitution_rate', 'omission_rate', 'insertion_rate',
    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
    'self_correction_rate', 'pause_frequency', 'prosody_score'
]

# ============================================================
# MODEL LOADING (supports both old and new filenames)
# ============================================================
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

model = None
scaler = None

# Try new 12-feature model first, then fall back to old 5-feature model
NEW_MODEL_PATH = os.path.join(BASE_DIR, 'weakness_classifier.joblib')
OLD_MODEL_PATH = os.path.join(BASE_DIR, 'bigkas_rf_model.pkl')
SCALER_PATH = os.path.join(BASE_DIR, 'feature_scaler.joblib')

if os.path.exists(NEW_MODEL_PATH):
    try:
        model = joblib.load(NEW_MODEL_PATH)
        print("BIGKAS RF Model (12-feature) loaded from:", NEW_MODEL_PATH)
    except Exception as e:
        print(f"Error loading new model: {e}")
elif os.path.exists(OLD_MODEL_PATH):
    try:
        model = joblib.load(OLD_MODEL_PATH)
        print("WARNING: Loaded OLD 5-feature prototype model from:", OLD_MODEL_PATH)
        print("This model will NOT work with the new 12-feature pipeline.")
        print("Rule-based fallback will be used until you train a new model.")
        model = None  # Intentionally disable — 5-feature model cannot accept 12 features
    except Exception as e:
        print(f"Error loading old model: {e}")
else:
    print("No RF model found. Place 'weakness_classifier.joblib' in ml-service/.")
    print("Classification will use rule-based fallback via Laravel.")

if os.path.exists(SCALER_PATH):
    try:
        scaler = joblib.load(SCALER_PATH)
        print("Feature scaler loaded from:", SCALER_PATH)
    except Exception as e:
        print(f"Error loading scaler: {e}")
        scaler = None
else:
    print("No feature_scaler.joblib found. Skipping scaling (OK if model was trained without it).")

# ============================================================
# LOCAL WHISPER LOADING (faster-whisper)
# ============================================================
whisper_model = None

try:
    from faster_whisper import WhisperModel
    # Check if a fine-tuned CTranslate2 model exists locally first
    FINETUNED_MODEL_PATH = os.path.join(BASE_DIR, 'bigkas-whisper-ct2')
    if os.path.isdir(FINETUNED_MODEL_PATH):
        whisper_model = WhisperModel(FINETUNED_MODEL_PATH, device="cpu", compute_type="int8")
        print("BIGKAS fine-tuned Whisper model loaded from:", FINETUNED_MODEL_PATH)
    else:
        # Fall back to the pre-trained 'small' model
        whisper_model = WhisperModel("small", device="cpu", compute_type="int8")
        print("Whisper 'small' model loaded (pre-trained, not fine-tuned).")
except ImportError:
    print("faster-whisper not installed. Run: pip install faster-whisper")
    print("Transcription endpoint /api/transcribe will return 503.")
except Exception as e:
    print(f"Error loading Whisper model: {e}")

# ============================================================
# ENDPOINT: /api/classify (Random Forest — 12 features)
# ============================================================
@app.route('/api/classify', methods=['POST'])
def classify_reading():
    if model is None:
        return jsonify({'status': 'error', 'message': 'RF model not loaded'}), 503

    data = request.json

    # Build DataFrame with ALL 12 feature columns in the EXACT order
    # the model was trained on. Column names must match FEATURE_COLUMNS.
    features = pd.DataFrame([{
        col: data.get(col, 0) for col in FEATURE_COLUMNS
    }])

    # Apply scaler if one was saved during training (StandardScaler)
    if scaler is not None:
        features_scaled = scaler.transform(features)
    else:
        features_scaled = features.values

    prediction = model.predict(features_scaled)[0]
    probabilities = model.predict_proba(features_scaled)[0]
    confidence = float(max(probabilities))

    return jsonify({
        'status': 'success',
        'primary_weakness': str(prediction),
        'secondary_weakness': None,
        'confidence': confidence,
        'scores': probabilities.tolist()
    })

# ============================================================
# ENDPOINT: /api/transcribe (Local Whisper STT)
# ============================================================
@app.route('/api/transcribe', methods=['POST'])
def transcribe():
    if whisper_model is None:
        return jsonify({'error': 'Whisper model not loaded. Install faster-whisper.'}), 503

    if 'audio' not in request.files:
        return jsonify({'error': 'No audio file provided'}), 400

    audio_file = request.files['audio']

    # FIX: Use tempfile for Windows compatibility (not /tmp/)
    temp_fd, temp_path = tempfile.mkstemp(suffix=os.path.splitext(audio_file.filename)[1] or '.webm')
    os.close(temp_fd)

    try:
        audio_file.save(temp_path)

        language_map = {'en': 'en', 'fil': 'tl', 'hil': 'tl'}
        lang_code = language_map.get(request.form.get('language', 'en'), 'en')

        segments, info = whisper_model.transcribe(temp_path, language=lang_code, word_timestamps=True)

        words = []
        full_text = []
        all_segments = []

        for segment in segments:
            full_text.append(segment.text)
            all_segments.append({
                'start': segment.start,
                'end': segment.end,
                'text': segment.text,
            })
            if segment.words:
                for w in segment.words:
                    words.append({
                        'word': w.word,
                        'start': w.start,
                        'end': w.end,
                        'confidence': w.probability,
                    })

        # FIX: Return ALL keys that SpeechToTextService::formatWhisperResponse() expects
        return jsonify({
            'text': ' '.join(full_text).strip(),
            'words': words,
            'language': lang_code,
            'duration': info.duration,
            'segments': all_segments,
        })
    finally:
        # Always clean up the temp file
        if os.path.exists(temp_path):
            os.remove(temp_path)

# ============================================================
# ENDPOINT: /api/health
# ============================================================
@app.route('/api/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'model_version': '2.0-12feature' if model is not None else '(no model loaded — using rule-based fallback)',
        'feature_columns': FEATURE_COLUMNS,
        'scaler_loaded': scaler is not None,
        'whisper_loaded': whisper_model is not None,
        'classifier_loaded': model is not None,
    })

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, debug=True)