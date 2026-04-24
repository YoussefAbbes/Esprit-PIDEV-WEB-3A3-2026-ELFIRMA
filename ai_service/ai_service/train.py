from sklearn.ensemble import RandomForestClassifier
from db_loader import load_data
from features import prepare_features, create_target
import joblib

df = load_data()
df = prepare_features(df)
df = create_target(df)

X = df[["etat","age","cout_achat","nb_maintenances","total_cout"]]
y = df["risk"]

model = RandomForestClassifier(n_estimators=200)
model.fit(X, y)

joblib.dump(model, "model.pkl")

print("✅ Modèle entraîné")