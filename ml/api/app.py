"""
BASA-PLUS-AI: ML Classification Flask API

This Flask server exposes the trained reading weakness classifier
as a REST API that the PHP application calls.

Endpoints:
    POST /api/classify   - Classify reading weakness from features
    POST /api/analyze    - Full analysis with skill scores
    GET  /api/health     - Health check
    GET  /api/model-info - Model metadata
    POST /api/training-data - Submit new training data
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import numpy as np
import joblib
import os
import json
import logging

# ============================================================
# APP SETUP
# ============================================================

app = Flask(__name__)
CORS(app)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Paths
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(BASE_DIR, '..', 'models')
MODEL_PATH = os.path.join(MODELS_DIR, 'weakness_classifier.joblib')
SCALER_PATH = os.path.join(MODELS_DIR, 'feature_scaler.joblib')
METADATA_PATH = os.path.join(MODELS_DIR, 'model_metadata.json')
TRAINING_DATA_PATH = os.path.join(MODELS_DIR, 'collected_training_data.json')

# Feature columns expected by the model
FEATURE_COLUMNS = [
    'accuracy_rate', 'words_per_minute', 'fluency_score',
    'substitution_rate', 'omission_rate', 'insertion_rate',
    'phonetic_error_rate', 'vowel_error_rate', 'blend_error_rate',
    'self_correction_rate', 'pause_frequency', 'prosody_score'
]

# Weakness labels
WEAKNESS_LABELS = {
    1: 'Phonemic Awareness',
    2: 'Decoding Accuracy',
    3: 'Oral Reading Fluency',
    4: 'Reading Comprehension'
}

# ============================================================
# MODEL LOADING
# ============================================================

model = None
scaler = None
metadata = None


def load_model():
    """Load the trained model, scaler, and metadata."""
    global model, scaler, metadata

    if not os.path.exists(MODEL_PATH):
        logger.warning(f"Model file not found at {MODEL_PATH}")
        logger.info("Run 'python training/train_model.py' to train the model first.")
        return False

    try:
        model = joblib.load(MODEL_PATH)
        scaler = joblib.load(SCALER_PATH)

        if os.path.exists(METADATA_PATH):
            with open(METADATA_PATH, 'r') as f:
                metadata = json.load(f)

        logger.info(f"Model loaded successfully: {metadata.get('model_type', 'Unknown')}")
        logger.info(f"Model accuracy: {metadata.get('accuracy', 'Unknown')}")
        return True

    except Exception as e:
        logger.error(f"Failed to load model: {e}")
        return False


def extract_features(data):
    """
    Extract and normalize features from the input data.
    The PHP app sends analysis data; we map it to model features.
    """
    features = {}

    # Direct mappings
    features['accuracy_rate'] = float(data.get('accuracy_rate', 50))
    features['words_per_minute'] = float(data.get('words_per_minute', 50))
    features['fluency_score'] = float(data.get('fluency_score', 5))
    features['prosody_score'] = float(data.get('prosody_score', 5))

    # Calculate rates from counts
    total_words = max(float(data.get('total_words', 100)), 1)

    substitutions = float(data.get('substitutions', 0))
    omissions = float(data.get('omissions', 0))
    insertions = float(data.get('insertions', 0))

    features['substitution_rate'] = min(substitutions / total_words, 1.0)
    features['omission_rate'] = min(omissions / total_words, 1.0)
    features['insertion_rate'] = min(insertions / total_words, 1.0)

    # Error pattern rates
    patterns = data.get('patterns', {})
    total_errors = max(substitutions + omissions + insertions, 1)

    features['phonetic_error_rate'] = min(
        float(patterns.get('phonetic_confusion', 0)) / total_errors, 1.0
    )
    features['vowel_error_rate'] = min(
        float(patterns.get('vowel_confusion', 0)) / total_errors, 1.0
    )
    features['blend_error_rate'] = min(
        float(patterns.get('consonant_blend_error', 0)) / total_errors, 1.0
    )

    # Derived features
    features['self_correction_rate'] = float(data.get('self_correction_rate', 0.05))
    features['pause_frequency'] = float(data.get('pause_frequency', 0.2))

    # Build feature vector in correct order
    feature_vector = [features.get(col, 0) for col in FEATURE_COLUMNS]

    return np.array(feature_vector).reshape(1, -1), features


# ============================================================
# API ENDPOINTS
# ============================================================

@app.route('/api/health', methods=['GET'])
def health():
    """Health check endpoint."""
    model_loaded = model is not None
    return jsonify({
        'status': 'ok' if model_loaded else 'degraded',
        'model_loaded': model_loaded,
        'model_version': metadata.get('version', '0.0.0') if metadata else '0.0.0',
        'service': 'basa-plus-ai-ml',
        'message': 'ML service is running' if model_loaded else 'Model not loaded - using rule-based fallback'
    })


@app.route('/api/model-info', methods=['GET'])
def model_info():
    """Return model metadata."""
    if metadata is None:
        return jsonify({
            'error': 'Model metadata not available',
            'model_loaded': False
        }), 404

    return jsonify({
        'model_loaded': model is not None,
        'model_type': metadata.get('model_type'),
        'accuracy': metadata.get('accuracy'),
        'version': metadata.get('version'),
        'feature_columns': metadata.get('feature_columns'),
        'weakness_labels': metadata.get('weakness_labels'),
        'training_samples': metadata.get('training_samples'),
        'trained_with': metadata.get('trained_with')
    })


@app.route('/api/classify', methods=['POST'])
def classify():
    """
    Classify reading weakness from assessment features.

    Expected JSON body:
    {
        "accuracy_rate": 75.5,
        "words_per_minute": 45,
        "fluency_score": 3.5,
        "substitutions": 8,
        "omissions": 3,
        "insertions": 1,
        "total_words": 50,
        "patterns": {
            "phonetic_confusion": 4,
            "vowel_confusion": 2,
            "consonant_blend_error": 3
        }
    }

    Returns:
    {
        "primary_weakness": 2,
        "primary_weakness_name": "Decoding Accuracy",
        "secondary_weakness": 1,
        "secondary_weakness_name": "Phonemic Awareness",
        "confidence": 0.85,
        "scores": { "1": 0.1, "2": 0.7, "3": 0.1, "4": 0.1 }
    }
    """
    if not request.is_json:
        return jsonify({'error': 'Content-Type must be application/json'}), 400

    data = request.get_json()

    try:
        feature_vector, features = extract_features(data)

        # If model is loaded, use ML classification
        if model is not None and scaler is not None:
            scaled_features = scaler.transform(feature_vector)

            # Get prediction and probabilities
            prediction = int(model.predict(scaled_features)[0])

            if hasattr(model, 'predict_proba'):
                probabilities = model.predict_proba(scaled_features)[0]
                scores = {}
                for i, prob in enumerate(probabilities):
                    class_label = model.classes_[i]
                    scores[str(class_label)] = round(float(prob), 4)
            else:
                scores = {str(prediction): 1.0}

            # Determine secondary weakness
            sorted_scores = sorted(scores.items(), key=lambda x: x[1], reverse=True)
            primary = int(sorted_scores[0][0])
            secondary = int(sorted_scores[1][0]) if len(sorted_scores) > 1 and sorted_scores[1][1] > 0.15 else None
            confidence = round(float(sorted_scores[0][1]), 2)

        else:
            # Rule-based fallback
            result = rule_based_classify(features)
            primary = result['primary']
            secondary = result['secondary']
            confidence = result['confidence']
            scores = result['scores']

        response = {
            'primary_weakness': primary,
            'primary_weakness_name': WEAKNESS_LABELS.get(primary, 'Unknown'),
            'secondary_weakness': secondary,
            'secondary_weakness_name': WEAKNESS_LABELS.get(secondary) if secondary else None,
            'confidence': confidence,
            'scores': scores,
            'method': 'ml' if model is not None else 'rule_based'
        }

        logger.info(
            f"Classification: primary={primary} ({WEAKNESS_LABELS.get(primary)}), "
            f"confidence={confidence}, method={'ml' if model else 'rules'}"
        )

        return jsonify(response)

    except Exception as e:
        logger.error(f"Classification error: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/analyze', methods=['POST'])
def analyze():
    """
    Full analysis endpoint - returns classification plus skill scores
    and detailed breakdown.
    """
    if not request.is_json:
        return jsonify({'error': 'Content-Type must be application/json'}), 400

    data = request.get_json()

    try:
        feature_vector, features = extract_features(data)

        # Classification
        if model is not None and scaler is not None:
            scaled_features = scaler.transform(feature_vector)
            prediction = int(model.predict(scaled_features)[0])

            if hasattr(model, 'predict_proba'):
                probabilities = model.predict_proba(scaled_features)[0]
                scores = {}
                for i, prob in enumerate(probabilities):
                    class_label = model.classes_[i]
                    scores[str(class_label)] = round(float(prob), 4)
            else:
                scores = {str(prediction): 1.0}
        else:
            result = rule_based_classify(features)
            prediction = result['primary']
            scores = result['scores']

        # Calculate skill scores (0-100)
        skill_scores = calculate_skill_scores(features)

        # Generate interpretation
        interpretation = generate_interpretation(features, prediction)

        # Sorted weaknesses
        sorted_scores = sorted(scores.items(), key=lambda x: x[1], reverse=True)

        response = {
            'classification': {
                'primary_weakness': int(sorted_scores[0][0]),
                'primary_weakness_name': WEAKNESS_LABELS.get(int(sorted_scores[0][0])),
                'secondary_weakness': int(sorted_scores[1][0]) if len(sorted_scores) > 1 else None,
                'confidence': round(float(sorted_scores[0][1]), 2),
                'all_scores': scores
            },
            'skill_scores': skill_scores,
            'interpretation': interpretation,
            'features_used': features,
            'method': 'ml' if model is not None else 'rule_based'
        }

        return jsonify(response)

    except Exception as e:
        logger.error(f"Analysis error: {e}")
        return jsonify({'error': str(e)}), 500


@app.route('/api/training-data', methods=['POST'])
def submit_training_data():
    """
    Accept new labeled training data from the PHP app.
    Stores it for future model retraining.
    """
    if not request.is_json:
        return jsonify({'error': 'Content-Type must be application/json'}), 400

    data = request.get_json()
    features = data.get('features', {})
    label = data.get('label')

    if label not in [1, 2, 3, 4]:
        return jsonify({'error': 'Label must be 1, 2, 3, or 4'}), 400

    # Load existing training data
    collected_data = []
    if os.path.exists(TRAINING_DATA_PATH):
        with open(TRAINING_DATA_PATH, 'r') as f:
            collected_data = json.load(f)

    # Add new entry
    collected_data.append({
        'features': features,
        'label': label,
        'timestamp': str(np.datetime64('now'))
    })

    # Save
    os.makedirs(os.path.dirname(TRAINING_DATA_PATH), exist_ok=True)
    with open(TRAINING_DATA_PATH, 'w') as f:
        json.dump(collected_data, f, indent=2)

    logger.info(f"Training data collected: {len(collected_data)} total samples")

    return jsonify({
        'success': True,
        'total_samples': len(collected_data),
        'message': f'Training data saved ({len(collected_data)} total samples)'
    })


# ============================================================
# HELPER FUNCTIONS
# ============================================================

def rule_based_classify(features):
    """
    Rule-based fallback classification when ML model is not available.
    """
    scores = {1: 0, 2: 0, 3: 0, 4: 0}

    accuracy = features.get('accuracy_rate', 50)
    wpm = features.get('words_per_minute', 50)
    fluency = features.get('fluency_score', 5)

    # Phonemic Awareness score
    phonetic_rate = features.get('phonetic_error_rate', 0)
    vowel_rate = features.get('vowel_error_rate', 0)
    blend_rate = features.get('blend_error_rate', 0)
    scores[1] = (phonetic_rate + vowel_rate + blend_rate) * 30

    # Decoding Accuracy score
    sub_rate = features.get('substitution_rate', 0)
    if accuracy < 85:
        scores[2] += (85 - accuracy) * 0.8
    scores[2] += sub_rate * 40

    # Fluency score
    if wpm < 60:
        scores[3] += (60 - wpm) * 0.5
    if fluency < 5:
        scores[3] += (5 - fluency) * 5

    pause_freq = features.get('pause_frequency', 0.2)
    scores[3] += pause_freq * 15

    # Comprehension score
    omission_rate = features.get('omission_rate', 0)
    self_corr = features.get('self_correction_rate', 0.05)
    prosody = features.get('prosody_score', 5)

    scores[4] += omission_rate * 30
    if accuracy > 80 and prosody < 4:
        scores[4] += 10
    if self_corr < 0.03:
        scores[4] += 5

    # Normalize to probabilities
    total = sum(scores.values())
    if total > 0:
        probs = {str(k): round(v / total, 4) for k, v in scores.items()}
    else:
        probs = {'1': 0.25, '2': 0.25, '3': 0.25, '4': 0.25}

    sorted_scores = sorted(scores.items(), key=lambda x: x[1], reverse=True)
    primary = sorted_scores[0][0]
    secondary = sorted_scores[1][0] if sorted_scores[1][1] > 0 else None

    confidence = 0.5
    if total > 0:
        gap = sorted_scores[0][1] - sorted_scores[1][1]
        confidence = min(1.0, 0.5 + (gap / sorted_scores[0][1]) * 0.5) if sorted_scores[0][1] > 0 else 0.5

    return {
        'primary': primary,
        'secondary': secondary,
        'confidence': round(confidence, 2),
        'scores': probs
    }


def calculate_skill_scores(features):
    """
    Calculate skill-area scores on a 0-100 scale.
    """
    accuracy = features.get('accuracy_rate', 50)
    fluency = features.get('fluency_score', 5)
    prosody = features.get('prosody_score', 5)

    # Phonemic awareness: inverse of phonetic errors
    phonemic = max(0, 100 - (
        features.get('phonetic_error_rate', 0) * 100 +
        features.get('vowel_error_rate', 0) * 100 +
        features.get('blend_error_rate', 0) * 100
    ) / 3 * 2)

    # Decoding: based on accuracy and substitution rate
    decoding = accuracy - features.get('substitution_rate', 0) * 50
    decoding = max(0, min(100, decoding))

    # Fluency: scaled from 0-10 to 0-100
    fluency_score = fluency * 10

    # Comprehension: estimated from prosody, self-correction, and accuracy
    comprehension = (
        prosody * 5 +
        accuracy * 0.3 +
        (1 - features.get('omission_rate', 0)) * 30
    )
    comprehension = max(0, min(100, comprehension))

    return {
        'phonemic_awareness': round(phonemic, 1),
        'decoding_accuracy': round(decoding, 1),
        'oral_reading_fluency': round(fluency_score, 1),
        'reading_comprehension': round(comprehension, 1)
    }


def generate_interpretation(features, primary_weakness):
    """
    Generate human-readable interpretation of the analysis.
    """
    accuracy = features.get('accuracy_rate', 50)
    wpm = features.get('words_per_minute', 50)
    fluency = features.get('fluency_score', 5)

    interpretations = []

    # Overall reading level
    if accuracy >= 97:
        interpretations.append(
            "The learner is reading at an INDEPENDENT level. "
            "They can read this material on their own."
        )
    elif accuracy >= 90:
        interpretations.append(
            "The learner is at an INSTRUCTIONAL level. "
            "This material is appropriate for guided reading with teacher support."
        )
    else:
        interpretations.append(
            "The learner is at a FRUSTRATION level. "
            "Consider using easier reading materials for assessment."
        )

    # Weakness-specific interpretation
    weakness_interpretations = {
        1: (
            "Primary area of concern: PHONEMIC AWARENESS. "
            "The learner shows difficulty with sound-letter relationships, "
            "vowel patterns, and consonant blends. Focus on sound isolation, "
            "blending, and segmenting activities."
        ),
        2: (
            "Primary area of concern: DECODING ACCURACY. "
            "The learner struggles to decode words correctly, with frequent "
            "substitutions. Focus on sight word practice, word family activities, "
            "and phonics pattern drills."
        ),
        3: (
            "Primary area of concern: ORAL READING FLUENCY. "
            "While the learner can decode words, reading is slow and choppy "
            "with frequent pauses. Focus on repeated reading, echo reading, "
            "and phrase-cued text practice."
        ),
        4: (
            "Primary area of concern: READING COMPREHENSION. "
            "The learner can decode but may not fully understand the text. "
            "Focus on before-during-after reading strategies, story mapping, "
            "and visualization exercises."
        )
    }

    interpretations.append(
        weakness_interpretations.get(primary_weakness, "Unable to determine primary weakness.")
    )

    # WPM interpretation
    if wpm < 40:
        interpretations.append(
            f"Reading speed ({wpm:.0f} WPM) is significantly below grade level. "
            "Daily fluency practice is recommended."
        )
    elif wpm < 80:
        interpretations.append(
            f"Reading speed ({wpm:.0f} WPM) is approaching grade level. "
            "Continue fluency practice to build automaticity."
        )
    else:
        interpretations.append(
            f"Reading speed ({wpm:.0f} WPM) is at or above grade level."
        )

    return ' '.join(interpretations)


# ============================================================
# MAIN
# ============================================================

if __name__ == '__main__':
    logger.info("Starting BASA-PLUS-AI ML Service...")
    model_loaded = load_model()

    if not model_loaded:
        logger.warning("Model not found. Running in rule-based mode.")
        logger.info("To train the model, run: python training/train_model.py")

    app.run(
        host='0.0.0.0',
        port=5000,
        debug=True
    )
