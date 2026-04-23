import pandas as pd
import mysql.connector

def load_data():
    conn = mysql.connector.connect(
        host="localhost",
        user="root",
        password="",
        database="personne"
    )

    query = """
    SELECT 
        e.id_eq,
        e.etat,
        e.cout_achat,
        e.date_achat,
        COUNT(m.id_m) as nb_maintenances,
        COALESCE(SUM(m.cout),0) as total_cout
    FROM equipement e
    LEFT JOIN maintenance m ON e.id_eq = m.id_equipement
    GROUP BY e.id_eq
    """

    df = pd.read_sql(query, conn)
    conn.close()

    return df