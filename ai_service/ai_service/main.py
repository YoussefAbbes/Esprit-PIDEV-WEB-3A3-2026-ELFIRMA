from fastapi import FastAPI
from pydantic import BaseModel
from typing import List
from fastapi.middleware.cors import CORSMiddleware

app = FastAPI()

# Allow CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

class AnalysisData(BaseModel):
    etat: str
    age: int
    cout: float
    nb_maintenances: int
    total_cout: float

@app.post("/full-analysis")
def full_analysis(data: AnalysisData):
    """Analyse complète basée sur l'état, l'âge et les maintenances"""
    
    # 🔥 MAPPING DE L'ÉTAT (accepte états réels ET mappés)
    etat_mapping = {
        "bon": 1,
        "moyen": 2,
        "critique": 3,
        "disponible": 1,
        "maintenance": 2,
        "panne": 3
    }
    etat_value = etat_mapping.get(data.etat.lower(), 1)
    etat_display = {1: "Bon", 2: "Moyen", 3: "Critique"}[etat_value]
    
    # 🔥 CALCUL DU RISQUE (priorité à l'état)
    # critique/panne=3 → risque ÉLEVÉ (2)
    # moyen/maintenance=2 → risque MOYEN (1) 
    # bon/disponible=1 → risque FAIBLE (0)
    risk = etat_value - 1  # 0, 1, ou 2
    
    # 🔥 CALCUL DU SCORE DE SANTÉ (100 = excellent, 0 = critique)
    # L'état a l'impact MAXIMAL sur le score
    base_score = 100 - (etat_value * 25)  # critique → 25, moyen → 50, bon → 75
    
    # Ajuster selon l'âge (pénalité)
    age_penalty = min(data.age * 2, 15)  # Max 15 points de pénalité
    
    # Ajuster selon les maintenances fréquentes
    maintenance_penalty = min(data.nb_maintenances * 2, 15)  # Max 15 points
    
    score = max(0, base_score - age_penalty - maintenance_penalty)
    
    # 🔥 ANALYSE ET RECOMMANDATIONS
    analysis = []
    recommendations = []
    
    # Analyse basée sur l'ÉTAT
    if etat_value == 3:  # critique/panne
        analysis.append("⚠️ CRITIQUE : Équipement en panne → intervention urgente requise")
        analysis.append("Arrêt de production ou danger potentiel détecté")
        recommendations.append("🚨 Arrêter l'équipement immédiatement")
        recommendations.append("Diagnostiquer la panne rapidement")
        recommendations.append("Engager un technicien d'urgence")
        
    elif etat_value == 2:  # moyen/maintenance
        analysis.append("🔧 En révision : Équipement sous maintenance")
        analysis.append("Disponibilité réduite pour la production")
        recommendations.append("Monitorer la progression de la maintenance")
        recommendations.append("Prévoir un calendrier de fin d'intervention")
        
    else:  # bon/disponible
        analysis.append("✅ Disponible : État de fonctionnement normal")
        if data.age > 5:
            analysis.append(f"⚠️ Âge avancé ({int(data.age)} ans) : Accroît les risques de défaillance")
            recommendations.append("Augmenter la fréquence de maintenance préventive")
        if data.nb_maintenances > 3:
            analysis.append(f"🔧 Maintenances fréquentes ({data.nb_maintenances}) : Usure accélérée")
            recommendations.append("Revoir les conditions d'utilisation")
    
    # Analyse additionnelle basée sur les données
    if data.age > 5 and etat_value < 3:
        analysis.append(f"📅 Équipement ancien ({int(data.age)} ans) → risque accru")
    
    if data.nb_maintenances > 5 and etat_value < 3:
        analysis.append(f"🔧 Nombreuses maintenances ({data.nb_maintenances}) → problèmes récurrents")
        recommendations.append("Envisager le remplacement de l'équipement")
    
    if not recommendations:
        recommendations.append("Maintenance préventive régulière recommandée")
    
    return {
        "risk": risk,  # 0 (faible), 1 (moyen), 2 (élevé/critique)
        "score": int(score),  # 0-100
        "analysis": analysis,
        "recommendations": recommendations,
        "etat": etat_display
    }

@app.get("/health")
def health():
    return {"status": "API running"}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=True)