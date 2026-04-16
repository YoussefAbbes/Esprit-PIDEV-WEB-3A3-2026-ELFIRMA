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

## 2.1) Detail exact: fichier par fichier (ou j'ai mis quoi)
### src/Controller/CommandeController.php
- Ajout du flux checkout securise:
  - creation seulement sur checkout_action = confirm_order,
  - actions separées pour generate_promo / apply_promo.
- Ajout logique promo:
  - generation code (>= 50 DT),
  - validite 3 jours,
  - application et calcul final_total,
  - nettoyage session promo apres commande.
- Ajout Stripe:
  - endpoint create-intent,
  - verification serveur PaymentIntent (status + montant),
  - statut paiement passe a Paye pour carte validee.
- Ajout adresse_livraison dans le flux creation (front + admin + quick API).
- Ajout chatbot API:
  - endpoint /api/commande/chatbot,
  - generation de reponse selon contexte panier/promo/paiement.
- Ajout recu paiement:
  - route voir recu,
  - route telecharger recu HTML.

### src/Entity/Commande.php
- Ajout/normalisation des contraintes Assert pour controle de saisie.
- Ajout champ adresse_livraison + validation:
  - NotBlank,
  - Length min/max.

### templates/commande_create.html.twig
- Form checkout front aligne table commande.
- Erreurs de validation affichees sous chaque champ.
- Bloc promo (generer/appliquer + affichage reduction).
- Bloc Stripe anime (carte visuelle + Stripe Elements).
- JS Stripe:
  - create PaymentIntent,
  - confirmCardPayment,
  - envoi stripe_payment_intent_id.
- Chatbot UI + JS:
  - bouton flottant,
  - panneau chat,
  - appels API chatbot,
  - quick replies.

### templates/commande_show.html.twig
- Affichage adresse_livraison.
- Ajout toast anime apres paiement carte confirme.
- Ajout boutons:
  - Voir le recu,
  - Telecharger le recu.

### templates/commande_receipt.html.twig
- Nouveau template dedie recu paiement.
- Design moderne responsive.
- Bouton Imprimer (window.print).
- Compatible affichage navigateur + telechargement HTML.
- Theme vert agriculture renforce (gradient + elements visuels modernes).
- Logo EL FIRMA ajoute dans l'en-tete du recu.

### templates/elfirma/commandes.html.twig
- Back office add/edit commande:
  - suppression validation HTML native (required/min/maxlength),
  - affichage messages d'erreur sous chaque champ,
  - ajout champ adresse_livraison create/edit.

### .env
- Ajout placeholders config Stripe:
  - STRIPE_PUBLIC_KEY,
  - STRIPE_SECRET_KEY,
  - STRIPE_CURRENCY.

### .env.local
- Ajout valeurs locales de test Stripe (dev uniquement).

### docs/elfirma/COMMANDE_STRIPE_PROMO_EXPLICATION.md
- Documentation complete des routes, fonctions, MVC, API et journal des changements.

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

### API interne Chatbot commande
- POST /api/commande/chatbot
- name: app_api_commande_chatbot
- Methode controller: chatbot(...)
- Role: assistant intelligent pour aider le client pendant le checkout (promo, paiement, adresse, total)

### Recu paiement carte (nouveau)
- GET /commande/{id}/receipt
- name: app_commande_receipt
- Methode controller: receipt(...)
- Role: ouvrir le recu de paiement dans une page moderne avec bouton Imprimer.

- GET /commande/{id}/receipt/download
- name: app_commande_receipt_download
- Methode controller: downloadReceipt(...)
- Role: telecharger le recu en fichier HTML.

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

### API Chatbot backend (Symfony)
- Fichier: src/Controller/CommandeController.php
- Route: /api/commande/chatbot
- Methode PHP: chatbot(Request $request, SessionInterface $session, EntityManagerInterface $em): JsonResponse
- Methode metier associee: buildCheckoutChatbotReply(...)
- Donnees utilisees pour repondre: panier session, total, etat promo

### Appel Chatbot cote front
- Fichier: templates/commande_create.html.twig
- Script JS: bloc javascripts (2eme script)
- Endpoint appele par fetch: path('app_api_commande_chatbot')
- UI: bouton flottant + panneau chat + quick replies

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

### Chatbot client (nouveau)
- Bouton flottant "assistant commande" en bas a droite.
- Fenetre chat moderne avec:
  - messages bot/client,
  - reponses rapides (quick replies),
  - envoi via Enter ou bouton.
- Le bot repond sur:
  - code promo,
  - paiement carte Stripe,
  - adresse de livraison,
  - montant total.

## 7.1) MVC du chatbot
- Model:
  - donnees panier + promo recuperees depuis session/produits.
- View:
  - interface chat dans templates/commande_create.html.twig.
- Controller:
  - endpoint API dans CommandeController (chatbot + buildCheckoutChatbotReply).

## 7.2) Recu de paiement apres confirmation
- Apres confirmation d'une commande carte payee:
  - message anime affiche dans la page detail commande,
  - bouton "Telecharger le recu" disponible.
- Le recu est un template HTML dedie:
  - fichier: templates/commande_receipt.html.twig
  - contient un design moderne + bouton "Imprimer" (window.print).

Pourquoi un fichier HTML?
- Oui, c'est une bonne pratique ici:
  - View MVC propre (template dedie recu),
  - telechargement simple sans dependance PDF,
  - impression native navigateur.

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
- Etape 6: ajout chatbot client checkout (API Symfony + UI chat + quick replies + doc).
- Etape 7: ajout recu paiement carte (message anime, telechargement HTML, bouton imprimer, routes dediees).
- Etape 8: redesign moderne vert agriculture du recu + integration du logo EL FIRMA.

---
Si on ajoute de nouvelles fonctionnalites, continuer a mettre a jour ce fichier dans cette section journal + sections techniques concernees.
