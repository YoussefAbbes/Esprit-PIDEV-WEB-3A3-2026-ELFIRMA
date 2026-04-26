# Guide complet: Password strong avec API externe (A a Z)

## 1) Objectif
Ce travail ajoute une suggestion de mot de passe fort dans le signup, basee sur une API externe, avec un fallback local si l'API externe ne repond pas.

Fonctionnalites ajoutees:
- Endpoint backend: GET /api/password/suggest
- Appel API externe: https://passwordwolf.com/api/
- Validation du mot de passe selon les regles du projet
- Bouton de suggestion dans la page signup
- Icone oeil pour afficher/masquer le mot de passe
- Message d'etat (generation, succes, erreur)

## 2) Fichiers modifies et emplacement exact

### Backend API
- Controleur ajoute: [src/Controller/ApiPasswordController.php](../../src/Controller/ApiPasswordController.php)
- Route API suggestion: [src/Controller/ApiPasswordController.php#L14](../../src/Controller/ApiPasswordController.php#L14)
- Methode principale suggest(): [src/Controller/ApiPasswordController.php#L15](../../src/Controller/ApiPasswordController.php#L15)
- Appel vers API externe PasswordWolf: [src/Controller/ApiPasswordController.php#L20](../../src/Controller/ApiPasswordController.php#L20)
- Validation des regles locales: [src/Controller/ApiPasswordController.php#L55](../../src/Controller/ApiPasswordController.php#L55)
- Generation locale fallback: [src/Controller/ApiPasswordController.php#L74](../../src/Controller/ApiPasswordController.php#L74)

### Frontend Signup
- Fichier page signup: [templates/auth/signup.html.twig](../../templates/auth/signup.html.twig)
- Bouton oeil (show/hide): [templates/auth/signup.html.twig#L86](../../templates/auth/signup.html.twig#L86)
- Nouveau bloc UI suggestion: [templates/auth/signup.html.twig#L90](../../templates/auth/signup.html.twig#L90)
- Bouton suggestion (API): [templates/auth/signup.html.twig#L95](../../templates/auth/signup.html.twig#L95)
- Zone message statut: [templates/auth/signup.html.twig#L100](../../templates/auth/signup.html.twig#L100)
- Styles du bloc suggestion: [templates/auth/signup.html.twig#L306](../../templates/auth/signup.html.twig#L306)
- Styles bouton suggestion: [templates/auth/signup.html.twig#L355](../../templates/auth/signup.html.twig#L355)
- JS show/hide password: [templates/auth/signup.html.twig#L666](../../templates/auth/signup.html.twig#L666)
- JS appel API suggestion: [templates/auth/signup.html.twig#L675](../../templates/auth/signup.html.twig#L675)
- Fetch vers endpoint local API: [templates/auth/signup.html.twig#L683](../../templates/auth/signup.html.twig#L683)

### Dependance utilisee
- Http client deja present dans le projet: [composer.json#L30](../../composer.json#L30)

## 3) Installation et prerequisites (A a Z)

### A. Prerequis
- PHP >= 8.1
- Symfony 6.4
- Serveur Symfony lance

### B. Dependances
Aucune installation supplementaire n'a ete necessaire pour cette fonctionnalite, car symfony/http-client est deja installe.

Verification:
- Voir [composer.json#L30](../../composer.json#L30)

### C. Variables d'environnement
Aucune variable .env supplementaire n'est necessaire pour la version actuelle.

### D. Lancer le projet
Commande standard:
- symfony server:start
ou
- php -S 127.0.0.1:8000 -t public

## 4) Comment ca fonctionne (architecture)

1. Le client clique sur Generate secure suggestion dans signup.
2. Le frontend appelle GET /api/password/suggest.
3. Le backend appelle l'API externe PasswordWolf.
4. Le backend verifie que le mot de passe respecte les regles du projet:
   - longueur 6 a 7
   - au moins 1 majuscule
   - au moins 1 minuscule
   - au moins 1 chiffre
5. Si ok: renvoie success + password + source=external_api.
6. Si API externe indisponible/non valide: generation locale fallback + source=local_fallback.
7. Le frontend remplit password et confirm_password automatiquement.

## 5) Contrat API

### Endpoint
- Methode: GET
- URL: /api/password/suggest

### Reponse succes (API externe)
```json
{
  "success": true,
  "password": "Ab3x$Q7",
  "source": "external_api"
}
```

### Reponse succes (fallback local)
```json
{
  "success": true,
  "password": "Rk4!mP2",
  "source": "local_fallback"
}
```

## 6) Verification et recette (comment prouver que ca utilise une API)

### Test 1: endpoint direct
Ouvrir dans navigateur:
- http://127.0.0.1:8000/api/password/suggest

Resultat attendu:
- JSON avec success=true
- Champ source present

Interpretation:
- source=external_api => appel API externe reussi
- source=local_fallback => API externe indisponible (fallback actif)

### Test 2: via interface signup
1. Ouvrir la page signup.
2. Cliquer Generate secure suggestion.
3. Verifier que password + confirm_password sont remplis.
4. Verifier le message statut sous le bouton.

### Test 3: preuve reseau (DevTools)
1. Ouvrir DevTools > Network.
2. Cliquer bouton suggestion.
3. Verifier requete GET vers /api/password/suggest.
4. Verifier la reponse JSON contient source.

## 7) Limites actuelles et ameliorations conseillees

### Limite actuelle
Le projet impose actuellement une longueur courte (max 7). Ce n'est pas ideal pour un vrai password fort moderne.

### Recommandation
Faire evoluer progressivement la politique vers 12+ caracteres (backend + frontend + base de validation).

## 8) Checklist finale
- [x] Endpoint API de suggestion ajoute
- [x] Appel API externe implemente
- [x] Fallback local implemente
- [x] Bouton suggestion dans signup
- [x] Bouton oeil show/hide mot de passe
- [x] Design bouton suggestion ameliore (pro, clair, structure)
- [x] Guide technique complet cree
