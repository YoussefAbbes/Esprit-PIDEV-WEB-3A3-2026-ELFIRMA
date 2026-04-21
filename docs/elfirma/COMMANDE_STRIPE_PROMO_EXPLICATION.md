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

### templates/partials/_header.html.twig
- Ajout d'un bouton front office "My Orders" a cote du bouton panier.
- Route cible: app_commandes_index.
- Libelle en anglais pour coherence du site.

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

### Conversion TND -> EUR (nouveau)
- API externe: https://v6.exchangerate-api.com/v6/{API_KEY}/latest/TND
- Utilisation: convertir le total commande en TND vers EUR avant creation du PaymentIntent Stripe.
- Raison: les montants produit/commande sont en dinar tunisien, Stripe est configure en EUR.

- Montant attendu: base sur un quote EUR stocke en session lors de create-intent.
- POST /api/commande/chatbot
- Variable conversion devise: EXCHANGE_RATE_API_KEY

- GET /commande/{id}/receipt
- name: app_commande_receipt
- Methode controller: receipt(...)
- Ajout conversion devise:
  - convertTndToEur(...),
  - utilisation API ExchangeRate,
  - stockage quote (TND/EUR/taux) en session pour verification paiement fiable.
- Role: ouvrir le recu de paiement dans une page moderne avec bouton Imprimer.
- EXCHANGE_RATE_API_KEY.
- Methode controller: downloadReceipt(...)
- Ajout cle locale ExchangeRate API (dev).
- Etape 10: integration conversion TND->EUR via ExchangeRate API pour paiement Stripe (MVC + session quote).
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
- Etape 9: ajout bouton "My Orders" dans le header front office a cote de "Panier".
- Etape 10: integration ExchangeRate API pour convertir le total checkout TND->EUR dans le flux Stripe.
- Etape 11: affichage front office en double devise (DT + EUR) sur produits, panier, checkout, commandes client et recu paiement.
- Etape 12: ajout du Weather Assistant admin produits (OpenWeather, regions Tunisie, recommendation, refresh).
- Etape 13: ajout du Stock Alert Center admin (icone rouge, stats stock/expiration, restock rapide).

## 12) Double devise front office (DT + EUR)
- Objectif:
  - permettre aux clients etrangers de lire les montants en EUR tout en gardant le DT comme devise principale.
- Source du taux:
  - API ExchangeRate: https://v6.exchangerate-api.com/v6/{API_KEY}/latest/TND
  - variable env: EXCHANGE_RATE_API_KEY

- Controllers concernes:
  - src/Controller/AuthController.php: passe tnd_to_eur_rate a la page produits (home).
  - src/Controller/PanierController.php: passe tnd_to_eur_rate au panier.
  - src/Controller/CommandeController.php:
    - passe tnd_to_eur_rate aux pages commandes front (liste/detail/recu),
    - conserve la conversion Stripe,
    - fallback sur le taux stocke en session stripe_payment_quote si l'API est indisponible.

- Views front mises a jour:
  - templates/pages/index.html.twig:
    - prix produit en DT + equivalent EUR,
    - modal detail produit en DT + EUR,
    - message quick order avec indication EUR.
  - templates/panier_index.html.twig:
    - prix unitaire, sous-totaux et total en DT + EUR,
    - recalcul JS synchro DT/EUR lors des modifications quantite.
  - templates/commande_create.html.twig:
    - recap commande en DT + EUR (lignes, reduction promo, total).
  - templates/commande_index.html.twig:
    - total commande en DT + EUR.
  - templates/commande_show.html.twig:
    - prix unitaire et total en DT + EUR.
  - templates/commande_receipt.html.twig:
    - montant regle en DT + EUR.

## 13) Admin Products Weather Assistant (OpenWeather)
- Objective:
  - provide admin with live temperature and humidity for Tunisian regions,
  - generate a smart product-selling recommendation based on weather conditions.

- MVC implementation:
  - Controller: src/Controller/ProductController.php
    - weather API call is handled in the controller (not in Twig),
    - region selection validation,
    - recommendation logic by temperature/humidity,
    - robust fallback handling when API is unavailable.
  - View: templates/elfirma/produits.html.twig
    - modern weather panel in admin products page,
    - region selector,
    - weather cards (temperature, humidity, status),
    - recommendation block,
    - refresh button with icon.

- API details:
  - Provider: OpenWeather
  - Endpoint used: https://api.openweathermap.org/data/2.5/weather
  - Env key: OPENWEATHER_API_KEY
  - Units: metric
  - Language: English (`lang=en`)

- Tunisian regions:
  - multiple governorates are supported (Tunis, Sfax, Sousse, Nabeul, Bizerte, etc.).
  - admin can choose which Tunisian region to inspect.

- Refresh feature:
  - a dedicated `Refresh` button (with icon) lets admin reload temperature/humidity later,
  - weather panel also displays the last update timestamp.

- UX and design:
  - colorful modern gradient cards,
  - cleaner friendly fallback messages,
  - all weather labels/messages translated to English to match app language.

  ## 14) Admin Products Stock Alert Center
  - Objective:
    - alert admin when products are low stock, out of stock, or expired,
    - provide a quick action to add quantities directly from a dedicated alert modal.

  - MVC implementation:
    - Controller: src/Controller/ProductController.php
      - computes stock alert list server-side (low/out-of-stock/expired),
      - computes global stock stats (expired/non-expired/low/out),
      - exposes alert payload to Twig,
      - handles POST restock action in route `produit_restock`.
    - View: templates/elfirma/produits.html.twig
      - floating red alert icon with badge counter,
      - modern modal "Stock Alert Center" with KPI cards,
      - alert list rows with contextual severity,
      - per-product mini form to add quantity (+stock) instantly.

  - Business rules implemented:
    - low stock threshold: <= 20 units,
    - out of stock: quantity <= 0 or status rupture,
    - expired: expiration date passed or status expired,
    - after restock:
      - keep status expired if product is already expired,
      - otherwise switch to available when stock > 0.

  - UX:
    - red pulse floating button is shown only when at least one alert exists,
    - modal displays actionable priority cards + clear stock state,
    - restock preserves current filters and selected weather region on redirect.

  ## 15) Fiche Revision Rapide - Ce qui a ete fait (Stock Alert)
  ### A) Besoin fonctionnel
  - L'admin doit voir immediatement les produits en risque:
    - stock faible,
    - rupture,
    - expiration.
  - L'admin doit pouvoir ajouter une quantite sans quitter la page produits.

  ### B) Ou est la logique (MVC)
  - Controller (metier):
    - calcul de la liste des alertes produit,
    - calcul des statistiques globales (expired/non-expired/low/out),
    - route POST de restock avec validation de la quantite.
  - View (Twig):
    - icone rouge flottante + badge du nombre d'alertes,
    - modal moderne avec KPI + liste detaillee,
    - mini formulaire "Add Qty" pour chaque produit en alerte.

  ### C) Regles metier memoriser
  - Seuil low stock: <= 20.
  - Out of stock: quantite <= 0 (ou statut rupture).
  - Expired: date expiration depassee (ou statut expired).
  - Restock:
    - quantite ajoutee doit etre un entier strictement positif,
    - si produit expire => statut reste expire,
    - sinon statut devient disponible si stock > 0, sinon rupture.

  ### D) Ce que l'utilisateur admin voit
  - Si alertes > 0:
    - bouton rouge pulse en bas a droite,
    - badge numerique du total d'alertes.
  - Dans la modal:
    - cartes KPI: total alertes, low stock, out of stock, expired,
    - compteur non-expired,
    - lignes produits avec niveau de severite,
    - champ quantity_add + bouton Add Qty.

  ### E) Validation technique deja faite
  - Lint PHP du controller: OK.
  - Lint Twig de la page produits admin: OK.
  - Aucune erreur editor sur les fichiers modifies.

  ### F) Mini demo (a dire a l'oral)
  - "Je clique sur l'icone rouge d'alerte, j'ouvre le Stock Alert Center."
  - "Je vois les KPIs et la liste des produits critiques."
  - "Je saisis une quantite dans Add Qty et je valide."
  - "Le stock est mis a jour cote serveur, le statut est recalcule selon les regles metier."
  - "Je reviens sur la meme vue avec mes filtres conserves." 

  ## 16) Accessibilite IA - Phase 1 MVP aveugles (assistant vocal)
  ### A) Objectif
  - Permettre a un utilisateur malvoyant/non-voyant de:
    - rechercher des produits,
    - ajouter au panier,
    - gerer son panier,
    - finaliser la commande,
    uniquement via commandes vocales.

  ### B) Perimetre implemente
  - Catalogue (templates/pages/index.html.twig):
    - bouton micro flottant,
    - reconnaissance vocale + retour vocal,
    - commandes pour chercher/lire/details/ajouter/panier.
  - Panier (templates/panier_index.html.twig):
    - bouton micro flottant,
    - commandes pour lire panier, augmenter/diminuer/supprimer, vider panier, passer commande.
  - Checkout (templates/commande_create.html.twig):
    - bouton micro flottant,
    - commandes pour remplir nom/adresse,
    - choisir mode paiement,
    - generer/appliquer promo,
    - lire recap,
    - confirmer commande.

  ### C) Tech IA/Web utilisee
  - Speech Recognition navigateur:
    - `window.SpeechRecognition || window.webkitSpeechRecognition`.
  - Speech Synthesis (lecture vocale):
    - `window.speechSynthesis` + `SpeechSynthesisUtterance`.
  - Strategie MVP:
    - aucun service externe obligatoire,
    - fallback message si navigateur non compatible.

  ### D) Exemples commandes vocales
  - Catalogue:
    - "aide"
    - "chercher huile"
    - "lire produits"
    - "details huile d'olive"
    - "ajouter huile d'olive"
    - "ouvrir panier"
  - Panier:
    - "lire panier"
    - "augmenter huile"
    - "diminuer tomate"
    - "supprimer pomme"
    - "vider panier"
    - "passer commande"
  - Checkout:
    - "nom Ali Ben Salah"
    - "adresse Rue de Tunis..."
    - "paiement cash" / "paiement carte" / "paiement virement"
    - "generer promo"
    - "appliquer promo ABC123"
    - "lire recap"
    - "confirmer commande"

  ### E) Points techniques importants
  - Normalisation texte vocal:
    - conversion en minuscules,
    - suppression accents (NFD) pour detection robuste.
  - UX:
    - bouton micro devient rouge pendant l'ecoute,
    - retour vocal apres chaque action,
    - message d'erreur clair si commande inconnue.

  ### F) Verification
  - Lint Twig catalogue: OK.
  - Lint Twig panier: OK.
  - Lint Twig checkout: OK.

  ### G) Limites connues (MVP)
  - Precision vocale depend du navigateur et du bruit ambiant.
  - Les commandes doivent rester relativement simples.
  - Ce MVP est base navigateur (pas encore modele IA cloud personnalise).

  ### H) Evolution prevue (Phase 2)
  - Ajouter NLP plus avance (synonymes/contextes metier) avec API IA.
  - Support multilingue FR/AR/EN en temps reel.
  - Historique des commandes vocales + profils accessibilite.

  ### I) Correction MVC appliquee (important)
  - Suite a la contrainte "fonctions metier dans controller", le MVP vocal a ete refactorise:
    - avant: interpretation des commandes vocales directement dans Twig,
    - maintenant: interpretation centralisee dans un controller dedie API.

  - Nouveau controller:
    - `src/Controller/AiAccessibilityController.php`
    - route: `POST /api/ai/voice-command`
    - role:
      - parser la phrase vocale,
      - decider la commande metier selon le contexte (`catalog`, `cart`, `checkout`),
      - retourner une reponse JSON standard (`speak` + `actions`).

  - Cote Twig (vue):
    - la vue capte le micro,
    - envoie `context + transcript` au controller,
    - execute uniquement les actions UI renvoyees (navigation, clic, update champ, etc.).

  - Benefices:
    - respect MVC,
    - logique metier testable et centralisee,
    - evolution plus simple vers un vrai moteur IA/NLP.

  ## 17) Vrai modele IA entraine localement (sans API IA externe)
  ### A) Ce qui a ete demande
  - Contrairement a une simple API tierce, le projet doit contenir un modele IA reelement entraine.

  ### B) Ce qui est implemente
  - Modele IA local de classification d'intentions vocales:
    - algorithme: Multinomial Naive Bayes (texte),
    - entrainement local dans le projet,
    - inference locale dans le backend Symfony.

  - Fichiers IA ajoutes:
    - `src/AI/IntentModel.php`:
      - moteur mathematique du modele (train + predict),
      - tokenisation + probabilites + score de confiance.
    - `src/AI/VoiceIntentAi.php`:
      - dataset d'entrainement,
      - chargement/sauvegarde modele,
      - prediction d'intention utilisee par le controller.
    - `src/Command/TrainVoiceIntentModelCommand.php`:
      - commande console pour entrainer explicitement le modele.

  - Integration MVC:
    - `src/Controller/AiAccessibilityController.php` consomme `VoiceIntentAi`.
    - Le controller utilise la prediction IA (`intent`, `confidence`) pour decider les actions metier.
    - Les vues restent des executeurs d'actions UI (pas de logique IA metier lourde).

  ### C) Entrainement effectif realise
  - Commande executee:
    - `php bin/console app:ai:train-voice-intent`
  - Resultat d'entrainement (session actuelle):
    - Examples: 110
    - Classes: 22
    - Modele genere: `var/ai/voice_intent_model.json`

  ### D) Pourquoi c'est une IA "codee et entrainee"
  - Pas d'appel a OpenAI/Gemini/Azure AI pour classifier les intentions.
  - Le modele est appris a partir d'un dataset local de phrases.
  - Les poids/probabilites appris sont sauvegardes dans un artefact de modele local.
  - La prediction en production est faite localement dans l'application.

  ### D.1) Langage et modele utilises
  - Langage principal: PHP (Symfony).
  - Modele IA: Multinomial Naive Bayes (classification d'intentions texte).
  - Capture/restitution vocale: Web Speech API navigateur (STT/TTS).
  - Base metier: MySQL via Doctrine ORM.

  ### D.2) Produits dynamiques depuis la base (pas un exemple fixe)
  - Le mot "pomme" etait un exemple utilisateur, pas une valeur codee.
  - Le systeme lit les produits `Disponible` depuis la BD et cherche la meilleure correspondance.
  - Matching dynamique implemente:
    - inclusion nom<->requete,
    - overlap de tokens,
    - similarite Levenshtein (tolerance aux variations de phrase).
  - Si aucun match fiable:
    - reponse vocale: "Produit non trouve dans la base de donnees."
  - Si match trouve:
    - ajout panier automatique,
    - enchainement vers etapes de commande.

  ### D.3) Persistance commande BD
  - Le flux final de commande reste celui de `CommandeController`.
  - Apres confirmation utilisateur, la commande est persistee en BD (table commande) et le stock produit est mis a jour.

  ### D.4) Flow vocal complet checkout (100% a la voix)
  - Depuis la commande, le client peut maintenant tout faire en vocal:
    - remplir `nom_client`,
    - remplir `adresse_livraison`,
    - choisir le mode de paiement,
    - generer et appliquer un code promo,
    - augmenter/diminuer une quantite produit,
    - confirmer la commande.

  - Gestion quantite en vocal pendant checkout:
    - la commande vocale (ex: "augmenter huile") met a jour le panier en session cote backend,
    - la page est rechargee automatiquement pour rafraichir le recapitulatif,
    - controles stock appliques (pas de depassement du stock disponible).

  - Validation metier:
    - produit non trouve -> message vocal explicite,
    - stock insuffisant -> message vocal explicite,
    - panier mis a jour -> recap recalculé avant confirmation finale.

  ### E) Limites actuelles (honnetes pour soutenance)
  - Dataset de taille MVP (110 exemples) : precision correcte mais perfectible.
  - Classifieur textuel simple (Naive Bayes), pas reseau profond.
  - Le composant STT/TTS navigateur reste base Web Speech API pour capter/lire la voix.

  ### F) Pistes d'amelioration
  - Augmenter le dataset (plus de formulations reelles utilisateurs).
  - Ajouter evaluation offline (accuracy/F1 + matrice confusion).
  - Version multilingue FR/AR/EN avec datasets separes.


### C) Integration front catalogue
- Fichier: `templates/pages/index.html.twig`
- Ajout bouton `Generate Video` dans la modal detail produit.
- Action JS: ouverture de `/product/{id}/generate-video` dans un nouvel onglet.

### D) Script Python de generation
- Fichier: `scripts/generate_product_video.py`
- Librairies utilisees:
  - MoviePy
  - gTTS
  - Pillow
- Pipeline:
  - fond video depuis image produit
  - overlays animes (titre + details)
  - zoom progressif + fade-in
  - audio TTS## 18) Generation video produit automatique (Symfony + Python)
### A) Objectif
- Ajouter un bouton "Generate Video" dans le detail produit du catalogue.
- Generer automatiquement une video `.mp4` depuis les donnees BD du produit.
- Afficher le resultat dans une vue Twig dediee.

### B) Architecture MVC appliquee
- Controller:
  - `src/Controller/ProductController.php`
  - route: `GET /product/{id}/generate-video`
  - recupere le produit via Doctrine
  - prepare les champs multimedia (nom, description, prix, qualite, dates)
  - appelle le service de generation Python
- Service:
  - `src/Service/ProductVideoGeneratorService.php`
  - construit le payload
  - execute le script Python
  - parse la reponse JSON
  - retourne statut + URL video
- View:
  - `templates/product/video_result.html.twig`
  - affiche la video si succes
  - affiche la sortie technique si erreur

  - export `.mp4` dans `public/generated_videos`

### E) Narration vocale (ordre demande)
Le texte audio est construit cote controller dans cet ordre:
1. nom du produit
2. qualite
3. date de production
4. date d'expiration
5. prix

### F) Payload transmis Python
Champs passes:
- `id`
- `name`
- `description`
- `price`
- `quality`
- `production_date`
- `expiration_date`
- `tts_text`
- `image_path`
- `project_dir`

### G) Corrections majeures realisees (debug reel)
1. **Erreur DI Symfony env fallback**
- erreur: `Invalid env fallback in default:python:PYTHON_BIN`
- correction: config service explicite dans `config/services.yaml` + cache clear.

2. **MoviePy import incompatibilite v2**
- erreur: `No module named moviepy.editor`
- correction: import compatible v1/v2 (`moviepy` puis fallback `moviepy.editor`).

3. **Payload JSON casse sous Windows (exec)**
- erreur: `Invalid JSON payload`
- correction: passage du payload via fichier temporaire (`--payload-file`) au lieu d'argument CLI brut.

4. **File lock Windows sur voice.mp3 (WinError 32)**
- correction: fermeture explicite de tous les clips + cleanup robuste avec retry.

5. **API MoviePy v2 differente (set_duration absent)**
- correction: wrappers compatibilite v1/v2 (`with_duration/set_duration`, `with_position/set_position`, `with_audio/set_audio`, `FadeIn`, `Resize`).

6. **Timeout PHP 120s pendant generation**
- correction:
  - `set_time_limit(600)` autour de `exec`
  - video rendue plus rapide (960x540, fps 20, preset ultrafast, duree borne).

### H) Fichiers modifies pour ce module
- `src/Controller/ProductController.php`
- `src/Service/ProductVideoGeneratorService.php`
- `scripts/generate_product_video.py`
- `templates/pages/index.html.twig`
- `templates/product/video_result.html.twig`
- `config/services.yaml`

### I) Commandes utiles de verification
- PHP:
  - `php -l src/Controller/ProductController.php`
  - `php -l src/Service/ProductVideoGeneratorService.php`
- Twig:
  - `php bin/console lint:twig templates/product/video_result.html.twig templates/pages/index.html.twig`
- Python:
  - `python -m py_compile scripts/generate_product_video.py`

### J) Resultat final
- Depuis le catalogue, le bouton "Generate Video" declenche une video produit auto.
- L'affichage est anime et plus moderne.
- La narration suit l'ordre metier attendu pour la soutenance.

## 19) Recommandation produits dans le panier (Collaborative Filtering)
### A) Objectif fonctionnel
- Quand le client ouvre son panier, afficher jusqu'a 5 produits recommandes.
- Les recommandations sont basees sur l'historique de commandes reel en base.
- Les produits deja presents dans le panier sont exclus.
- Les produits non disponibles ou en rupture sont exclus.

### B) Principe IA utilise (explication simple)
- Type d'IA utilise pour cette fonctionnalite: collaborative filtering base sur co-achats.
- Idee: "des clients qui ont achete A ont aussi achete B".
- Ce n'est pas un modele deep learning ici, mais une intelligence statistique basee donnees historiques.
- Le score de pertinence d'un produit recommande est base sur la frequence de co-achat avec les produits du panier courant.

### C) Langages et technologies utilises
- Backend principal: PHP 8 + Symfony 6.4.
- Acces donnees: Doctrine ORM + Doctrine DBAL (requete SQL optimisee).
- Base de donnees: MySQL.
- Front: Twig + JavaScript (affichage bloc "Recommandes pour vous" + bouton Ajouter).
- Architecture: MVC propre.

### D) Fichiers concernes
- Service metier IA:
  - `src/Service/RecommendationService.php`
- Controller panier:
  - `src/Controller/PanierController.php`
- Vue panier:
  - `templates/panier_index.html.twig`

### E) Pipeline technique exact
1. Le client ouvre la page panier.
2. Le controller lit la session panier (`product_id => quantite`).
3. Le controller appelle `RecommendationService::getRecommendationsFromCart(...)`.
4. Le service normalise les IDs produits du panier.
5. Le service execute une requete SQL de co-achats sur la table `commande`:
   - cherche les lignes de commande partageant le meme "contexte panier" (client/utilisateur + date + adresse + mode paiement),
   - calcule combien de fois chaque produit externe au panier apparait avec les produits du panier.
6. Le service trie par score de co-achat decroissant.
7. Le service recharge les produits via Doctrine et applique les filtres metier:
   - statut = `Disponible`,
   - quantite_stock > 0.
8. Le service retourne au maximum 5 produits.
9. Twig affiche les cartes recommandations avec un bouton "Ajouter".

### F) Formule de scoring (version soutenance)
- Pour un produit candidat C:
  - score(C) = nombre total de co-occurrences de C avec les produits du panier courant.
- Tri final:
  - score desc,
  - puis id produit desc (stabilisation du tri).

### G) Pourquoi cette approche est "IA" dans ce contexte
- Le systeme apprend implicitement des comportements historiques d'achat (patterns collectifs).
- Il adapte la suggestion au panier en cours (personnalisation contextuelle).
- Il n'utilise pas de regle fixe du type "si produit X alors Y" codee en dur.
- C'est une IA de recommandation statistique, sans API externe.

### H) Contraintes respectees
- Pas d'API externe pour recommander.
- Logique metier isolee dans un Service Symfony.
- Controller leger (orchestration MVC).
- Requete basee base de donnees (Doctrine/SQL).

### I) Correctifs techniques realises pendant integration
- Injection de dependance du service recommendation corrigee (injection constructeur controller).
- Correction DBAL sur le parametre `LIMIT` (gestion type compatible).
- Correction SQL MySQL collation (suppression comparaison `CONCAT = CONCAT`, passage a comparaisons null-safe colonne par colonne).

### J) Limites actuelles (honnetes pour soutenance)
- La table `commande` est structuree en lignes produit, pas en en-tete + lignes avec id_commande_metier global.
- Le regroupement de "meme panier historique" est reconstruit via des champs contexte (utilisateur, date, adresse, mode paiement).
- Les recommandations sont robustes, mais peuvent etre encore ameliorees avec un schema commande plus normalise.

### K) Evolutions proposees
- Ajouter un vrai identifiant de panier/commande parent pour un regroupement parfait des co-achats.
- Ajouter un score hybride:
  - co-achat,
  - popularite recente,
  - affinite categorie.
- Ajouter une table de cache des top co-achats pour accelerer les requetes sur grand volume.

### L) Reponse courte type prof
- "Pour les recommandations panier, j'ai implemente un collaborative filtering en PHP/Symfony avec SQL MySQL via Doctrine. Le moteur observe les produits souvent achetes ensemble dans l'historique des commandes, exclut les produits deja dans le panier, filtre disponibilite/stock, puis renvoie les 5 plus pertinents."

## 20) Revision rapide - Dernieres evolutions (admin + IA gestes + video)
### A) Bundle externe ajoute (demande prof)
- Bundle installe via Composer:
  - `knplabs/knp-paginator-bundle`
- Activation Symfony:
  - `config/bundles.php`
- Usage reel dans le module Orders admin:
  - pagination des commandes backoffice via KNP Paginator.

### B) Orders admin: suppression multiple
- Objectif:
  - permettre a l'admin de selectionner plusieurs commandes puis supprimer en une action.
- MVC applique:
  - Controller: nouvelle route `POST /admin/commandes/bulk-delete`
    - methode: `adminBulkDelete(...)`
    - validation CSRF,
    - validation IDs,
    - suppression en lot + flash message.
  - View: `templates/elfirma/commandes.html.twig`
    - checkbox par ligne,
    - checkbox "select all",
    - bouton "Supprimer la selection",
    - compteur dynamique des lignes cochees.

### C) Orders admin: pagination avec bundle
- Controller:
  - injection `PaginatorInterface` dans `adminIndex(...)`.
  - pagination sur 10 lignes/page.
- View:
  - rendu navigation avec `knp_pagination_render(commandes)`.
- Benefice:
  - page admin plus fluide quand le volume commandes augmente.

### D) Checkout gestes: confirmation commande securisee
- Changement metier:
  - avant: confirmation par `pouce haut`.
  - maintenant: confirmation uniquement par `2 mains ouvertes`.
- Fichiers touches:
  - `public/assets/js/gesture_assistant.js`:
    - detection 2 mains activee (`maxNumHands: 2`),
    - nouveau geste compose `double_open_palm`.
  - `src/AI/GestureIntentAi.php`:
    - mapping checkout_confirm => `double_open_palm`.
  - `src/Controller/AiAccessibilityController.php`:
    - validation serveur du geste checkout confirm.

### E) Generation video produit: correction affichage overlays
- Probleme corrige:
  - le prix etait trop grand et recouvrait les dates.
- Correctifs:
  - taille de police du prix adaptative,
  - positionnement vertical dynamique,
  - limitation intelligente du texte description,
  - marge garantie entre dates et prix.
- Fichier:
  - `scripts/generate_product_video.py`.

### F) Mini pitch oral (30 secondes)
- "J'ai ajoute un bundle Symfony externe (KnpPaginatorBundle) et je l'ai integre concretement dans la gestion admin des commandes avec pagination. J'ai aussi implemente la suppression multiple en MVC propre (route controller + checkboxes vue + CSRF). Cote accessibilite, la confirmation checkout par geste est maintenant plus sure: deux mains ouvertes au lieu du pouce haut. Enfin, j'ai corrige le rendu video produit pour eviter le chevauchement du prix avec les dates." 

### G) Journal des changements (suite)
- Etape 14: ajout suppression multiple commandes backoffice (select + bulk delete).
- Etape 15: integration bundle externe `KnpPaginatorBundle` avec pagination Orders admin.
- Etape 16: checkout gestes: confirmation commande par `double_open_palm`.
- Etape 17: correction overlay video (prix/dates) pour un rendu lisible et moderne.

## 21) Revision rapide - Statut commande client + affichage admin colore
### A) Besoin fonctionnel
- En front office client, apres creation d'une commande (`En attente`), permettre au client de choisir:
  - `Confirmée`
  - `Annulée`
- En backoffice admin, afficher visuellement ces statuts avec couleur:
  - `Confirmée` en vert
  - `Annulée` en rouge

### B) MVC applique
- Controller:
  - `src/Controller/CommandeController.php`
  - nouvelle route: `POST /commande/{id}/status`
  - methode: `updateStatus(...)`
  - regles metier:
    - CSRF obligatoire,
    - verification que la commande appartient au client courant,
    - changement autorise seulement si statut actuel = `En attente`,
    - valeurs autorisees: `Confirmée`, `Annulée`.
- View front:
  - `templates/commande_show.html.twig`
  - ajout boutons `Confirmer` et `Annuler` (POST securise).
- View backoffice:
  - `templates/elfirma/commandes.html.twig`
  - statut commande affiche en badge colore.

### C) Securite et controle d'acces
- Protection CSRF sur le formulaire de changement statut.
- Controle d'appartenance commande:
  - priorite a `user_id` si la commande est liee a `Utilisateur`,
  - fallback sur `user_name` vs `nom_client` si besoin.
- Evite la modification d'une commande deja traitee (non `En attente`).

### D) Effet visible pour l'admin
- Dans la liste Orders admin:
  - `Confirmée` => badge vert,
  - `Annulée` => badge rouge,
  - `En attente` => badge orange,
  - autres statuts => gris.

### E) Mini pitch oral (20 secondes)
- "J'ai ajoute une action front client pour valider ou annuler une commande tant qu'elle est en attente, avec verification CSRF et controle d'appartenance commande. Ensuite j'ai adapte la vue admin Orders pour afficher les statuts en couleurs, ce qui permet d'identifier rapidement les commandes confirmees et annulees." 

### F) Journal des changements (suite)
- Etape 18: front client - ajout action de changement statut commande (`Confirmée` / `Annulée`).
- Etape 19: backoffice orders - badges statut colores (vert/rouge/orange).

## 22) Score client intelligent (OpenAI + fallback local) - Front + Admin
### A) Objectif fonctionnel
- Afficher un score client sur 100 avec categorie metier:
  - `VIP`, `fidèle`, `normal`, `à risque`.
- Front office:
  - chaque client voit son propre score sur la page `Mes Commandes`.
- Backoffice admin:
  - l'admin voit un score pour chaque client dans le tableau des commandes.

### B) MVC applique
- Controller:
  - `src/Controller/CommandeController.php`
  - endpoint JSON ajoute:
    - `GET /api/commande/client-score`
  - methode front `index(...)` enrichie avec `client_score`.
  - methode admin `adminIndex(...)` enrichie avec `order_client_scores`.
- Service metier:
  - `src/Service/ClientScoringService.php`
  - calcule score via API OpenAI quand disponible.
  - fallback local intelligent si quota/billing/reponse invalide.
- Views:
  - `templates/commande_index.html.twig`:
    - carte score client (score, categorie, commandes, total depense, annulations).
  - `templates/elfirma/commandes.html.twig`:
    - colonne `Score client` pour chaque ligne commande.

### C) Donnees utilisees pour scorer
- `total_orders` (nombre de commandes)
- `total_spent` (montant depense hors commandes annulees)
- `cancellations` (nombre de commandes annulees)

### D) API OpenAI integree
- Endpoint appele:
  - `POST https://api.openai.com/v1/responses`
- Client HTTP:
  - `HttpClientInterface` Symfony
- Cle secrete:
  - variable env `OPENAI_API_KEY`
  - placeholder ajoute dans `.env`
  - valeur locale dans `.env.local`

### E) Gestion robuste des erreurs IA
- Cas gere:
  - payload IA invalide,
  - erreur API OpenAI,
  - quota/billing depasse,
  - cle manquante.
- Comportement:
  - si OpenAI indisponible, score calcule localement (sans bloquer l'UI).
  - la page continue a afficher un score exploitable pour la demo.

### F) Regle fallback local (sans API)
- Score local borne entre 0 et 100.
- Formule metier:
  - bonus sur nombre de commandes,
  - bonus sur montant depense,
  - malus sur annulations.
- Categorie derivee du score:
  - >= 85: `VIP`
  - >= 65: `fidèle`
  - >= 40: `normal`
  - sinon: `à risque`

### G) Affichage front ajuste (UX)
- Le message technique fallback/quota est masque pour l'utilisateur final.
- Le client voit uniquement une carte claire avec:
  - score,
  - categorie,
  - metriques metier.

### H) Affichage admin de tous les clients
- Nouvelle colonne dans le tableau Orders:
  - `Score client` avec badge couleur + valeur `/100` + categorie.
- Calcul optimisé:
  - agrégation par client sur l'ensemble des commandes,
  - reutilisation d'un calcul local pour eviter un appel OpenAI par ligne.

### I) Securite / bonnes pratiques
- Cle API jamais hardcodee dans le code PHP.
- Utilisation des variables d'environnement uniquement.
- Recommandation operationnelle:
  - revoquer toute cle partagee publiquement,
  - regenerer une nouvelle cle privee.

### J) Verifications techniques effectuees
- `php -l src/Service/ClientScoringService.php` => OK
- `php -l src/Controller/CommandeController.php` => OK
- `php bin/console lint:twig templates/commande_index.html.twig` => OK
- `php bin/console lint:twig templates/elfirma/commandes.html.twig` => OK
- `php bin/console lint:container` => OK

### K) Journal des changements (suite)
- Etape 20: ajout service `ClientScoringService` (OpenAI responses API + parsing JSON).
- Etape 21: ajout endpoint `GET /api/commande/client-score`.
- Etape 22: affichage score client dans `Mes Commandes` (front office).
- Etape 23: correction parsing reponse OpenAI + gestion erreurs API explicites.
- Etape 24: ajout fallback local automatique si quota/billing depasse.
- Etape 25: masquage message technique fallback cote front.
- Etape 26: affichage score client pour admin sur toutes les commandes.

---
Si on ajoute de nouvelles fonctionnalites, continuer a mettre a jour ce fichier dans cette section journal + sections techniques concernees.
