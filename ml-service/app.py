import os
from flask import Flask, request, jsonify
import joblib
import pandas as pd

app = Flask(__name__)

# FIX: Get the absolute path so it always finds the model, no matter where you run it from
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, 'bigkas_rf_model.pkl')

# Load the trained Random Forest model
try:
    model = joblib.load(MODEL_PATH)
    print("✅ BIGKAS Model loaded successfully from:", MODEL_PATH)
except Exception as e:
    print(f"❌ Error loading model: {e}")
    print("Please make sure 'bigkas_rf_model.pkl' is inside the ml-service folder.")

@app.route('/api/classify', methods=['POST'])
def classify_reading():
    data = request.json
    
    # Structure the incoming data
    features = pd.DataFrame([{
        'wpm': data.get('wpm', 0),
        'accuracy': data.get('accuracy', 0),
        'omissions': data.get('omissions', 0),
        'insertions': data.get('insertions', 0),
        'substitutions': data.get('substitutions', 0)
    }])
    
    # Predict the Weakness
    prediction = model.predict(features)[0]
    
    # Get Confidence Score (converted to a standard float)
    probabilities = model.predict_proba(features)[0]
    confidence = float(max(probabilities))
    
    # Return JSON matching your Laravel MLClassificationService expectations
    return jsonify({
        'status': 'success',
        'primary_weakness': str(prediction),
        'secondary_weakness': None, # Placeholder for future multi-label classification
        'confidence': confidence,
        'scores': probabilities.tolist()
    })

# Add a health check endpoint because your PHP service expects it!
@app.route('/api/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'model_version': '1.0-prototype (Adult Data)'
    })

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000, debug=True)