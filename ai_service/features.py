from datetime import datetime
import pandas as pd

def prepare_features(df):

    # 🔥 Conversion de la date d'achat
    df["date_achat"] = pd.to_datetime(df["date_achat"])

    # 🔥 Calcul de l'âge en années
    df["age"] = (pd.Timestamp.now() - df["date_achat"]).dt.days / 365

    # 🔥 Mapping des états (avec les variantes attendues du contrôleur)
    # bon=1 (bon état/disponible) → maintien score santé
    # moyen=2 (état moyen/maintenance) → diminue score
    # critique=3 (état critique/panne) → score très faible + risque élevé
    df["etat"] = df["etat"].str.lower().map({
        "bon": 1,
        "moyen": 2,
        "critique": 3,
        "disponible": 1,
        "maintenance": 2,
        "panne": 3
    }).fillna(1)

    df.fillna(0, inplace=True)

    return df

def create_target(df):

    df["risk"] = 0
    df.loc[df["nb_maintenances"] > 3, "risk"] = 1
    df.loc[df["nb_maintenances"] > 5, "risk"] = 2

    return df