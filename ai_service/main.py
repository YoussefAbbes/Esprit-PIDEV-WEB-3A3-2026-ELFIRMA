from fastapi import FastAPI
import joblib

model = joblib.load("model.pkl")

app = FastAPI()

@app.post("/full-analysis")
def full_analysis(data: dict):

    features = [[
        data["etat"],
        data["age"],
        data["cout"],
        data["nb_maintenances"],
        data["total_cout"]
    ]]

    risk = int(model.predict(features)[0])

    score = 100 - (data["age"]*2 + data["nb_maintenances"]*5 + risk*15)
    score = max(score, 0)

    # 🔍 ANALYSE
    analysis = []

    if data["age"] > 5:
        analysis.append("Équipement ancien → risque accru")

    if data["nb_maintenances"] > 3:
        analysis.append("Fréquence élevée de maintenance")

    if data["total_cout"] > data["cout"] * 0.3:
        analysis.append("Coût de maintenance élevé")
    
    if not analysis:
        analysis.append("Aucun problème détecté, équipement en bon état")

    # 🔧 RECOMMANDATIONS
    recommendations = []

    if risk == 2:
        recommendations.append("Maintenance immédiate recommandée")

    if score < 50:
        recommendations.append("Remplacement conseillé")

    if data["nb_maintenances"] > 5:
        recommendations.append("Analyser les causes des pannes répétées")
        
    if not recommendations:
        recommendations.append("Aucune action nécessaire pour le moment")

    return {
        "risk_level": risk,
        "health_score": score,
        "analysis": analysis,
        "recommendations": recommendations
    }