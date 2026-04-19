from datetime import datetime
import pandas as pd

def prepare_features(df):

    # 🔥 correction ici
    df["date_achat"] = pd.to_datetime(df["date_achat"])

    df["age"] = (pd.Timestamp.now() - df["date_achat"]).dt.days / 365

    df["etat"] = df["etat"].str.lower().map({
        "bon": 1,
        "moyen": 2,
        "critique": 3
    }).fillna(1)

    df.fillna(0, inplace=True)

    return df

def create_target(df):

    df["risk"] = 0
    df.loc[df["nb_maintenances"] > 3, "risk"] = 1
    df.loc[df["nb_maintenances"] > 5, "risk"] = 2

    return df