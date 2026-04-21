import pickle
import random
import numpy as np
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

# ── Load trained model artifacts ──────────────────────────────────────────────
with open("model/rf_model.pkl", "rb") as f:
    model = pickle.load(f)

with open("model/vectorizer.pkl", "rb") as f:
    vectorizer = pickle.load(f)

with open("model/label_encoder.pkl", "rb") as f:
    label_encoder = pickle.load(f)

with open("model/responses.pkl", "rb") as f:
    responses_map = pickle.load(f)

# ── FastAPI app ───────────────────────────────────────────────────────────────
app = FastAPI(title="Supplier Chatbot AI", version="1.0.0")

# Allow Symfony (localhost) to call the API
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Request / Response models ─────────────────────────────────────────────────
class ChatRequest(BaseModel):
    message: str

class ChatResponse(BaseModel):
    intent:     str
    response:   str
    confidence: float


FALLBACK_RESPONSES = {
    "create_supplier": [
        "Sure! Let's create a new supplier. I'll open the form for you."
    ],
    "edit_supplier": [
        "Sure! Tell me which supplier you want to edit."
    ],
    "delete_supplier": [
        "Sure. Tell me which supplier you want to delete."
    ],
}


def detect_priority_intent(message: str) -> str | None:
    text = message.lower().strip()

    has_supplier_word = any(word in text for word in [
        "supplier", "suppliers", "fournisseur", "fournisseurs", "vendor", "vendors"
    ])

    if has_supplier_word and any(word in text for word in ["delete", "remove", "supprimer", "erase"]):
        return "delete_supplier"

    if has_supplier_word and any(word in text for word in ["edit", "update", "modify", "change", "modifier", "mettre a jour"]):
        return "edit_supplier"

    if has_supplier_word and any(word in text for word in ["create", "add", "new", "ajouter", "creer", "register"]):
        return "create_supplier"

    return None

# ── Prediction endpoint ───────────────────────────────────────────────────────
@app.post("/chat", response_model=ChatResponse)
def chat(request: ChatRequest):
    message = request.message.lower().strip()

    # Prioritize explicit command-like intents so critical actions are not
    # misclassified as informational intents.
    forced_intent = detect_priority_intent(message)
    if forced_intent is not None:
        possible_responses = responses_map.get(
            forced_intent,
            FALLBACK_RESPONSES.get(forced_intent, responses_map["unknown"])
        )
        response = random.choice(possible_responses)
        return ChatResponse(
            intent=forced_intent,
            response=response,
            confidence=1.0
        )

    # Vectorize input using the same TF-IDF vectorizer used in training
    X = vectorizer.transform([message]).toarray()

    # Get predicted class index
    predicted_index = model.predict(X)[0]

    # Get probabilities for ALL classes from every tree in the forest
    # shape: (1, n_classes)
    probabilities = model.predict_proba(X)[0]

    # Confidence = probability of the predicted class
    confidence = float(np.max(probabilities))

    # Decode the label back to intent name
    intent = label_encoder.inverse_transform([predicted_index])[0]

    # If confidence is too low, fall back to unknown
    if confidence < 0.30:
        intent = "unknown"

    # Pick a random response for this intent
    possible_responses = responses_map.get(intent, responses_map["unknown"])
    response = random.choice(possible_responses)

    return ChatResponse(
        intent=intent,
        response=response,
        confidence=round(confidence, 4)
    )

# ── Health check ──────────────────────────────────────────────────────────────
@app.get("/health")
def health():
    return {
        "status": "ok",
        "model":  "Random Forest",
        "classes": list(label_encoder.classes_)
    }

# ── Run directly ──────────────────────────────────────────────────────────────
if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=True)