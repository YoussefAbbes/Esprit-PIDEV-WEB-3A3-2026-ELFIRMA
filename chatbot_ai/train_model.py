import json
import numpy as np
import pickle
import os
from sklearn.ensemble import RandomForestClassifier
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import train_test_split
from sklearn.metrics import classification_report

# ── Load intents ──────────────────────────────────────────────────────────────
with open("intents.json", "r", encoding="utf-8") as f:
    data = json.load(f)

# ── Build training dataset ────────────────────────────────────────────────────
sentences = []
labels    = []

for intent in data["intents"]:
    for pattern in intent["patterns"]:
        sentences.append(pattern.lower())
        labels.append(intent["tag"])

print(f"Total training examples: {len(sentences)}")
print(f"Classes: {set(labels)}")

# ── Vectorize text using TF-IDF ───────────────────────────────────────────────
# TF-IDF converts text into numerical feature vectors
# Term Frequency × Inverse Document Frequency
vectorizer = TfidfVectorizer(
    ngram_range=(1, 2),   # use single words AND pairs (bigrams)
    max_features=1000,     # keep top 1000 features
    analyzer="word",
    strip_accents="unicode",
    lowercase=True
)

X = vectorizer.fit_transform(sentences).toarray()

# ── Encode labels ─────────────────────────────────────────────────────────────
label_encoder = LabelEncoder()
y = label_encoder.fit_transform(labels)

# ── Split for evaluation ──────────────────────────────────────────────────────
X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, random_state=42
)

# ── Train Random Forest ───────────────────────────────────────────────────────
# Random Forest = ensemble of decision trees, each trained on a
# random subset of features and data (bagging)
model = RandomForestClassifier(
    n_estimators=200,      # 200 decision trees in the forest
    max_depth=None,        # let trees grow fully
    min_samples_split=2,
    random_state=42,
    n_jobs=-1              # use all CPU cores
)

model.fit(X_train, y_train)

# ── Evaluate ──────────────────────────────────────────────────────────────────
y_pred = model.predict(X_test)
print("\n── Model Evaluation ──────────────────────────────────")
print(classification_report(
    y_test, y_pred,
    target_names=label_encoder.classes_
))
print(f"Training accuracy: {model.score(X_train, y_train):.4f}")
print(f"Test accuracy:     {model.score(X_test, y_test):.4f}")

# ── Save model artifacts ──────────────────────────────────────────────────────
os.makedirs("model", exist_ok=True)

with open("model/rf_model.pkl", "wb") as f:
    pickle.dump(model, f)

with open("model/vectorizer.pkl", "wb") as f:
    pickle.dump(vectorizer, f)

with open("model/label_encoder.pkl", "wb") as f:
    pickle.dump(label_encoder, f)

# Save responses map for the API
responses_map = {
    intent["tag"]: intent["responses"]
    for intent in data["intents"]
}
with open("model/responses.pkl", "wb") as f:
    pickle.dump(responses_map, f)

print("\n✅ Model trained and saved to model/ folder")
print(f"   - model/rf_model.pkl")
print(f"   - model/vectorizer.pkl")
print(f"   - model/label_encoder.pkl")
print(f"   - model/responses.pkl")
