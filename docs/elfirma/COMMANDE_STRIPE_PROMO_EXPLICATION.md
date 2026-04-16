# Revue Technique - Module Commande (Promo + Stripe)

## 1) Objectif fonctionnel
Ce qui a ete implemente dans le front office commande:
- CRUD commande deja existant conserve.
- Validation metier centralisee dans l'entite Commande et dans le controller pour le flux checkout.
- Formulaire front aligne avec la table commande (nom client, mode de paiement, promo).
- Systeme de code promo:
  - generation possible si total panier >= 50 DT,
  - validite 3 jours,
  - application avant confirmation de la commande.
- Paiement carte bancaire avec Stripe (mode test) si l'utilisateur choisit "Carte bancaire".

## 2) Fichiers modifies
### Controller principal
- src/Controller/CommandeController.php

### Entite
- src/Entity/Commande.php

### Vue front office
- templates/commande_create.html.twig

### Configuration environnement
- .env
- .env.local

## 3) Respect MVC
- Model:
  - Entite Commande + contraintes de validation Symfony (Assert).
- View:
  - Twig commande_create avec champs, messages d'erreur sous chaque champ, bloc promo, bloc carte Stripe anime.
- Controller:
  - Toute la logique metier est dans CommandeController:
    - calcul panier,
    - promo,
    - creation/verification paiement Stripe,
    - transaction DB,
    - persistance commande + mise a jour stock.

## 4) Routes ajoutees/utilisees
### Checkout front
- GET/POST /commander
- name: app_commande_create
- Methode controller: create(...)

### API interne Stripe
- POST /commande/stripe/create-intent
- name: app_commande_stripe_create_intent
- Methode controller: createStripePaymentIntent(...)
- Role: creer un PaymentIntent Stripe (retourne clientSecret + paymentIntentId)

## 4.1) Ou j'ai mis l'API (emplacement exact)
### API Stripe backend (Symfony)
- Fichier: src/Controller/CommandeController.php
- Route: /commande/stripe/create-intent
- Methode PHP: createStripePaymentIntent(SessionInterface $session, EntityManagerInterface $em, HttpClientInterface $httpClient): JsonResponse
- Appel Stripe externe: POST https://api.stripe.com/v1/payment_intents
- Verification paiement: verifyStripePaymentIntent(string $paymentIntentId, int $expectedAmount, HttpClientInterface $httpClient): array

### Appel API cote front (Twig + JS)
- Fichier: templates/commande_create.html.twig
- Script JS: bloc javascripts
- Endpoint appele par fetch: path('app_commande_stripe_create_intent')
- Etape front: avant submit final, le JS cree le PaymentIntent puis confirme la carte via stripe.confirmCardPayment(...)
- Donnee renvoyee au serveur: stripe_payment_intent_id (champ hidden du formulaire)

### Configuration des cles API
- Placeholders commits: .env
- Cles locales (dev): .env.local
- Variables: STRIPE_PUBLIC_KEY, STRIPE_SECRET_KEY, STRIPE_CURRENCY

### Routes existantes conservees
- /commandes (liste client)
- /commande/{id} (detail)
- /admin/commandes (liste admin)
- /admin/commande/create|edit|delete

## 5) Fonctions importantes ajoutees/modifiees dans CommandeController
### A) Flux principal
- create(...):
  - recupere panier,
  - calcule total,
  - dispatch selon checkout_action:
    - generate_promo
    - apply_promo
    - confirm_order

- processOrder(...):
  - valide nom_client + mode_paiement,
  - verifie promo applique si code envoye,
  - si mode "Carte bancaire":
    - verifie stripe_payment_intent_id,
    - appelle verifyStripePaymentIntent(...),
  - cree les commandes dans une transaction DB,
  - met a jour le stock produit,
  - nettoie panier + etat promo.

### B) Promo
- handlePromoGeneration(...): genere code promo si total >= 50 DT.
- handlePromoApplication(...): valide/applique code promo en session.
- resolvePromoSummary(...): calcule discount + final_total + etat promo.
- getGeneratedPromo(...): lit le promo genere depuis session.
- isPromoExpired(...): controle expiration (3 jours).
- clearPromoState(...): supprime etat promo session.
- buildDiscountedLineTotals(...): repartit la reduction sur les lignes commande.

### C) Stripe
- createStripePaymentIntent(...):
  - recalcule montant serveur (avec promo eventuelle),
  - cree PaymentIntent via API Stripe,
  - retourne clientSecret + paymentIntentId.

- verifyStripePaymentIntent(...):
  - recupere PaymentIntent Stripe,
  - verifie status == succeeded,
  - verifie montant == montant attendu.

- getStripePublicKey(), getStripeSecretKey(), getStripeCurrency():
  - lisent la config depuis les variables d'environnement.

## 6) Parametres env utilises
Dans .env (placeholders) et .env.local (valeurs locales):
- STRIPE_PUBLIC_KEY
- STRIPE_SECRET_KEY
- STRIPE_CURRENCY

Important:
- Ne jamais commiter des cles secretes de production.
- Utiliser des cles test en dev.

## 6.1) Modification base de donnees (nouveau champ adresse)
Champ ajoute dans la table commande:
- adresse_livraison VARCHAR(255)

SQL a executer dans phpMyAdmin:
```sql
ALTER TABLE commande
ADD COLUMN adresse_livraison VARCHAR(255) NULL AFTER nom_client;
```

Si tu veux forcer au niveau DB (optionnel apres nettoyage des anciennes donnees):
```sql
UPDATE commande
SET adresse_livraison = 'Adresse non renseignee'
WHERE adresse_livraison IS NULL OR TRIM(adresse_livraison) = '';

ALTER TABLE commande
MODIFY adresse_livraison VARCHAR(255) NOT NULL;
```

Note:
- Cote application, la validation est deja obligatoire dans l'entite Commande.
- Le mode NULL en DB evite de casser les anciennes lignes existantes.

## 7) Front (commande_create.html.twig)
### Ce qui a ete ajoute
- Bloc promo:
  - bouton Generer code,
  - bouton Appliquer code,
  - affichage code genere + expiration,
  - affichage reduction + total final.

- Bloc Stripe conditionnel:
  - visible seulement si mode paiement = Carte bancaire,
  - design carte anime (preview visuelle),
  - saisie nom porteur,
  - element carte Stripe,
  - erreurs client affichees proprement.

- Champ cache:
  - stripe_payment_intent_id
  - rempli apres confirmation Stripe cote JS.

### JS Stripe
- Charge Stripe.js.
- Cree element carte.
- Sur submit "confirm_order" et mode carte:
  - appelle /commande/stripe/create-intent,
  - appelle stripe.confirmCardPayment(clientSecret, ...),
  - injecte paymentIntentId dans le formulaire,
  - relance submit serveur.

## 8) Regles metier actuelles
- Promo disponible uniquement a partir de 50 DT.
- Validite promo: 3 jours.
- Paiement carte:
  - commande acceptee seulement si paiement Stripe confirme.
- Statut paiement en DB:
  - Carte bancaire => "Paye"
  - Sinon => "Non paye"

## 9) Pourquoi cette architecture est correcte
- Pas de confiance au front:
  - montant recalcule serveur,
  - paiement verifie serveur avant persist.
- Transaction DB:
  - commande et stock restent coherents.
- MVC respecte:
  - logique dans controller,
  - rendu dans Twig,
  - donnees et contraintes dans entite.

## 10) Questions probables du prof + reponses courtes
### Q1: Pourquoi verifier PaymentIntent cote serveur?
R: Pour eviter qu'un client falsifie la reponse front. Le serveur valide status et montant.

### Q2: Pourquoi recalculer le total serveur?
R: Le total front n'est pas fiable. Le serveur recalcule depuis le panier et applique promo.

### Q3: Pourquoi session pour promo?
R: Pas de table promo disponible dans le schema actuel. La session permet un flux simple et rapide.

### Q4: Ou est la separation MVC?
R: Entite Commande (Model), Twig commande_create (View), CommandeController (Controller).

### Q5: Comment gerer l'expiration promo?
R: Date expires_at stockee en session et verifiee a chaque action.

## 11) Journal des changements
- Etape 1: alignement formulaire front avec table commande.
- Etape 2: controle de saisie sous chaque champ.
- Etape 3: ajout systeme promo (generation + application + expiration).
- Etape 4: ajout Stripe (intent + verification + UI animee).
- Etape 5: ajout adresse_livraison (entite + formulaire front + controller + admin + affichage detail).

---
Si on ajoute de nouvelles fonctionnalites, continuer a mettre a jour ce fichier dans cette section journal + sections techniques concernees.
