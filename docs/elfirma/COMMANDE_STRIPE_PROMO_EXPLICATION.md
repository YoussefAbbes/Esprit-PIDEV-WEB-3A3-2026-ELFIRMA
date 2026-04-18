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

---
Si on ajoute de nouvelles fonctionnalites, continuer a mettre a jour ce fichier dans cette section journal + sections techniques concernees.
