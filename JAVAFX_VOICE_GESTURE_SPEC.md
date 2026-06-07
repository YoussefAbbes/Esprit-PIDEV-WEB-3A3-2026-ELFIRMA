# Elfirma — Voice & Gesture Assistant: How It Works & JavaFX Implementation Guide

## Briefing for Claude

This document fully explains how the **voice command assistant** and **gesture recognition assistant** were built in the Symfony Elfirma web app, and gives you everything you need to rebuild equivalent features in a JavaFX desktop app. The JavaFX app connects to the **same MySQL database** and can optionally call the same Symfony backend endpoints, or run entirely standalone.

---

## PART 1 — SYSTEM OVERVIEW

There are **two separate assistant systems** that work on the client-facing product/cart/checkout pages:

### 1. Voice Assistant (Product Catalog, Cart, Checkout)
- User speaks a command in French or English
- Browser captures audio via **Web Speech API**
- Transcript is sent to the Symfony backend
- Backend classifies intent using a **Naive Bayes classifier** (fast, local, no API) for cart/checkout, and **OpenRouter LLM** (GPT-4o-mini) for catalog commands that need product name matching
- Backend returns a JSON response with what to say and what actions to perform
- Browser reads the response aloud via **Web Speech Synthesis API** and executes actions (navigate, add to cart, fill form fields, etc.)

### 2. Gesture Assistant (Product Catalog, Cart, Checkout)
- User shows hand to webcam
- Browser runs **MediaPipe Hands** (a Google ML library) directly in the browser — no server needed for detection
- MediaPipe returns 21 3D hand landmark coordinates per hand
- Custom JavaScript classifies the gesture from those coordinates
- Gesture name is sent to Symfony backend
- Backend maps gesture → intent → action using another **Naive Bayes classifier**
- Same action execution pipeline as voice

### 3. Supplier Voice Assistant (Admin/Supplier Module — separate)
- A different, more complex assistant that runs a **stateful multi-turn conversation** to collect data (create/edit/delete a supplier)
- Uses session state on the server to track which field is being collected
- Uses Naive Bayes for intent detection and field validation

---

## PART 2 — BACKEND ENDPOINTS

All three endpoints are in `AiAccessibilityController`. In JavaFX you can either:
- **Option A**: Call these HTTP endpoints on the running Symfony app (easiest, no logic to reimplement)
- **Option B**: Reimplement the logic in Java (standalone desktop, no Symfony dependency)

This document covers both options for every component.

### Endpoint 1: Voice Command
```
POST http://localhost:8000/api/ai/voice-command
Content-Type: application/json

Request:
{
  "context": "catalog",       // one of: catalog | cart | checkout
  "transcript": "add tomato"  // what the user said
}

Response (success):
{
  "ok": true,
  "speak": "Adding Tomates Fraîches to your cart.",
  "actions": [
    { "type": "add_to_cart", "product_id": 12 }
  ]
}

Response (error / not understood):
{
  "ok": false,
  "speak": "I didn't understand that. Try: search product name, add product, open cart."
}
```

### Endpoint 2: Gesture Command
```
POST http://localhost:8000/api/ai/gesture-command
Content-Type: application/json

Request:
{
  "context": "cart",       // catalog | cart | checkout
  "gesture": "thumb_up",   // classified gesture name
  "product_id": 0          // optional, for context
}

Response:
{
  "ok": true,
  "speak": "Quantity increased.",
  "actions": [
    { "type": "update_cart_quantity_delta", "product_id": 5, "delta": 1 }
  ]
}
```

### Endpoint 3: Gesture Help
```
GET http://localhost:8000/api/ai/gesture-help?context=catalog

Response:
{
  "ok": true,
  "context": "catalog",
  "items": [
    { "gesture": "open_palm",   "title": "Main ouverte", "description": "Aller au panier" },
    { "gesture": "one_finger",  "title": "1 doigt",      "description": "Détails du premier produit" },
    { "gesture": "thumb_up",    "title": "Pouce haut",   "description": "Ajouter le produit au panier" }
  ]
}
```

---

## PART 3 — ALL ACTION TYPES

The backend response `actions` array contains objects. Each has a `type` field. Here is every possible type and its extra fields:

| `type` | Extra fields | What to do in JavaFX |
|--------|-------------|----------------------|
| `read_visible_products` | — | Read product names from current list view via TTS |
| `navigate` | `url: String` | Open URL in browser or navigate to that module |
| `open_product_details` | `product_id: int` | Open product detail dialog/panel |
| `add_to_cart` | `product_id: int` | Add product to session cart |
| `add_to_cart_then_checkout` | `product_id: int` | Add to cart then open checkout view |
| `filter_products` | `query: String` | Filter product list by query string |
| `remove_from_cart` | `product_id: int` | Remove product from cart |
| `update_cart_quantity_delta` | `product_id: int`, `delta: int` | Change qty by delta (+1 or -1) |
| `update_cart_quantity_set` | `product_id: int`, `quantity: int` | Set qty to exact value |
| `clear_cart` | — | Empty the cart |
| `set_field` | `field_id: String`, `value: String` | Set a form field value |
| `set_payment_mode` | `value: String` | Select payment method |
| `submit_checkout_action` | `value: String` (e.g. `"generate_promo"`) | Trigger checkout action |
| `apply_generated_promo` | — | Apply the last generated promo code |
| `read_cart_summary` | — | Read cart contents via TTS |
| `read_checkout_summary` | — | Read checkout total/summary via TTS |
| `focus_next_checkout_field` | — | Move focus to next form input |
| `click_confirm_order` | — | Submit the order form |
| `reload_page` | — | Refresh current view |

---

## PART 4 — HOW THE NAIVE BAYES CLASSIFIER WORKS

Both the voice intent and gesture intent classifiers use the same algorithm. You need to reimplement this in Java.

### 4.1 Training Phase

Input: a map of `intentName → [list of example sentences]`

```
"cart_increase" → ["augmenter huile", "ajouter quantite huile", "plus un", ...]
"cart_decrease" → ["diminuer huile", "reduire quantite tomate", "moins un", ...]
```

Steps:
1. **Tokenize** each example sentence (see Section 4.3)
2. **Count** token frequencies per class
3. **Compute log-prior** for each class:
   ```
   logPrior(class) = log( (docsInClass + 1) / (totalDocs + numClasses) )
   ```
4. **Compute log-likelihood** for each token in each class (Laplace smoothing):
   ```
   logLikelihood(token, class) = log( (freq(token, class) + 1) / (totalTokensInClass + vocabSize) )
   ```
5. **Save** priors, log-likelihoods, vocabulary to a JSON file

### 4.2 Prediction Phase

Input: a text string (transcript or gesture name)

Steps:
1. Tokenize the input
2. For each class, compute score:
   ```
   score(class) = logPrior(class)
   for each token in input:
     if token in vocabulary:
       score(class) += logLikelihood(token, class)
     else:
       score(class) += log(1 / vocabSize)   // unseen word penalty
   ```
3. Pick class with highest score → this is the `intent`
4. Compute confidence using sigmoid on the score gap:
   ```
   gap = topScore - secondBestScore
   confidence = 1.0 / (1.0 + exp(-gap))
   ```
5. Return: `{ intent, confidence, allScores }`

### 4.3 Tokenization

Apply these steps in order to every text before training or prediction:
1. Unicode NFD normalization
2. Remove diacritics (combining marks: `\p{Mn}`)
3. Lowercase
4. Remove non-alphanumeric characters (keep spaces)
5. Split on whitespace
6. **Drop tokens shorter than 2 characters**
7. Return token array

Examples:
- `"Chercher Huile d'Olive"` → `["chercher", "huile", "olive"]`
- `"augmenter quantité de pommes"` → `["augmenter", "quantite", "pommes"]`
- `"thumb_up"` → `["thumb", "up"]`

---

## PART 5 — VOICE INTENT TRAINING DATA (Complete)

These are the exact 24 intent classes and their training examples used in the Symfony app. Use these same examples to train your Java classifier.

```java
Map<String, List<String>> trainingData = new LinkedHashMap<>();

trainingData.put("help", List.of(
    "aide", "help", "montre moi les commandes", "que peux tu faire",
    "commandes disponibles", "liste des commandes", "what can you do"
));

// CATALOG INTENTS
trainingData.put("catalog_read_products", List.of(
    "lire produits", "liste produits", "quels produits", "montre produits",
    "afficher produits", "read products", "show products"
));
trainingData.put("catalog_search", List.of(
    "chercher huile", "cherche tomate", "rechercher pomme", "find oil",
    "search tomato", "trouver huile olive", "recherche produit"
));
trainingData.put("catalog_details", List.of(
    "details huile", "ouvrir details tomate", "voir details pomme",
    "details du produit", "open details oil", "show details tomato"
));
trainingData.put("catalog_add", List.of(
    "ajouter huile", "ajoute tomate au panier", "add oil to cart",
    "mettre huile dans panier", "ajouter au panier pomme"
));
trainingData.put("catalog_order", List.of(
    "commander pomme", "je veux commander une pomme", "order tomato",
    "commander maintenant huile", "passer commande tomate"
));
trainingData.put("catalog_open_cart", List.of(
    "ouvrir panier", "aller panier", "voir mon panier", "open cart",
    "go to cart", "afficher panier"
));

// CART INTENTS
trainingData.put("cart_read", List.of(
    "lire panier", "resume panier", "quoi dans panier", "read cart",
    "show cart", "what is in cart", "contenu panier"
));
trainingData.put("cart_increase", List.of(
    "augmenter huile", "ajouter quantite huile", "plus un", "increase oil",
    "add more tomato", "augmenter quantite pomme", "mettre plus"
));
trainingData.put("cart_decrease", List.of(
    "diminuer huile", "reduire quantite tomate", "moins un", "decrease oil",
    "remove one tomato", "diminuer quantite pomme", "mettre moins"
));
trainingData.put("cart_remove", List.of(
    "supprimer huile", "retirer tomate du panier", "remove oil from cart",
    "enlever pomme", "effacer huile du panier"
));
trainingData.put("cart_clear", List.of(
    "vider panier", "supprimer tous les produits", "clear cart",
    "effacer panier", "tout supprimer", "empty cart"
));
trainingData.put("cart_checkout", List.of(
    "passer commande", "commander maintenant", "checkout", "go to checkout",
    "proceder paiement", "valider panier", "finaliser commande"
));

// CHECKOUT INTENTS
trainingData.put("checkout_name", List.of(
    "nom ali", "mon nom est ahmed", "my name is john", "je suis ahmed",
    "nom client ali ben", "name is sarah"
));
trainingData.put("checkout_address", List.of(
    "adresse tunis", "mon adresse est sfax", "my address is paris",
    "adresse de livraison tunis", "livrer a sfax centre"
));
trainingData.put("checkout_payment_cash", List.of(
    "paiement cash", "payer en espece", "pay cash", "payer cash",
    "paiement en especes", "cash payment"
));
trainingData.put("checkout_payment_card", List.of(
    "paiement carte", "payer par carte bancaire", "pay by card",
    "carte credit", "visa mastercard", "bank card"
));
trainingData.put("checkout_payment_transfer", List.of(
    "paiement virement", "payer par virement", "bank transfer",
    "virement bancaire", "transfer payment"
));
trainingData.put("checkout_promo_generate", List.of(
    "generer promo", "creer code promo", "generate promo code",
    "obtenir reduction", "code promo", "create discount"
));
trainingData.put("checkout_promo_apply", List.of(
    "appliquer promo abc", "utiliser code promo", "apply promo code",
    "appliquer reduction", "use discount code abc123"
));
trainingData.put("checkout_read_summary", List.of(
    "lire recap", "lire total", "read summary", "total commande",
    "resume commande", "combien total", "what is total"
));
trainingData.put("checkout_confirm", List.of(
    "confirmer commande", "valider la commande", "confirm order",
    "passer la commande", "finaliser", "submit order"
));
trainingData.put("checkout_back_cart", List.of(
    "retour panier", "revenir au panier", "go back to cart",
    "retourner panier", "back to cart"
));
```

---

## PART 6 — GESTURE INTENT TRAINING DATA (Complete)

Three separate models — one per context. Train each independently.

```java
// CATALOG GESTURES
Map<String, List<String>> catalogGestures = new LinkedHashMap<>();
catalogGestures.put("catalog_open_cart",    List.of("open_palm", "main ouverte", "ouvrir panier", "aller panier"));
catalogGestures.put("catalog_details_1",    List.of("one_finger", "1 doigt", "details premier produit", "premier"));
catalogGestures.put("catalog_details_2",    List.of("two_fingers", "2 doigts", "details deuxieme produit", "deuxieme"));
catalogGestures.put("catalog_details_3",    List.of("three_fingers", "3 doigts", "details troisieme produit", "troisieme"));
catalogGestures.put("catalog_details_4",    List.of("four_fingers", "4 doigts", "details quatrieme produit", "quatrieme"));
catalogGestures.put("catalog_details_5",    List.of("five_fingers", "5 doigts", "details cinquieme produit", "cinquieme"));
catalogGestures.put("catalog_add",          List.of("thumb_up", "pouce haut", "ajouter panier", "ajouter produit"));

// CART GESTURES
Map<String, List<String>> cartGestures = new LinkedHashMap<>();
cartGestures.put("cart_clear",    List.of("fist", "poing", "vider panier"));
cartGestures.put("cart_checkout", List.of("open_palm", "main ouverte", "passer commande", "aller commande"));
cartGestures.put("cart_read",     List.of("victory", "v signe", "lire panier", "recap panier"));
cartGestures.put("cart_increase", List.of("thumb_up", "pouce haut", "augmenter quantite", "plus un"));
cartGestures.put("cart_decrease", List.of("thumb_down", "pouce bas", "diminuer quantite", "moins un"));

// CHECKOUT GESTURES
Map<String, List<String>> checkoutGestures = new LinkedHashMap<>();
checkoutGestures.put("checkout_focus_next",      List.of("wave", "vague", "champ suivant", "suivant"));
checkoutGestures.put("checkout_promo_generate",  List.of("open_palm", "main ouverte", "generer promo"));
checkoutGestures.put("checkout_promo_apply",     List.of("fist", "poing", "appliquer promo"));
checkoutGestures.put("checkout_confirm",         List.of("double_open_palm", "deux mains ouvertes", "confirmer commande"));
checkoutGestures.put("checkout_read_summary",    List.of("victory", "v signe", "lire recap", "total commande"));
```

**Gesture → Intent Quick Reference Table:**

| Gesture | Catalog | Cart | Checkout |
|---------|---------|------|----------|
| `open_palm` | Open cart | Go to checkout | Generate promo |
| `thumb_up` | Add to cart | Increase qty | — |
| `thumb_down` | — | Decrease qty | — |
| `fist` | — | Clear cart | Apply promo |
| `victory` | — | Read summary | Read summary |
| `one_finger` | Details #1 | — | — |
| `two_fingers` | Details #2 | — | — |
| `three_fingers` | Details #3 | — | — |
| `four_fingers` | Details #4 | — | — |
| `five_fingers` | Details #5 | — | — |
| `double_open_palm` | — | — | Confirm order |
| `wave` | — | — | Next field |

---

## PART 7 — HOW GESTURE RECOGNITION WORKS (MediaPipe)

In the web app, gesture recognition runs entirely in the browser using MediaPipe. In JavaFX you have two options:

### Option A: Use JavaFX WebView + Same JS
Embed a `WebView` that runs the same `gesture_assistant.js` logic. Use `JSObject` / `WebEngine` bridge to get gesture names into Java.

### Option B: Java MediaPipe / OpenCV (Standalone)
Use [MediaPipe Java](https://developers.google.com/mediapipe/framework/getting_started/java) or OpenCV's hand detection. Then classify gestures using the same landmark math described below.

### 7.1 Hand Landmark Layout (MediaPipe)
MediaPipe returns 21 landmarks per hand, each with x, y, z coordinates (normalized 0.0–1.0):

```
 0 = Wrist
 1 = Thumb MCP     2 = Thumb IP     3 = Thumb DIP     4 = Thumb TIP
 5 = Index MCP     6 = Index PIP    7 = Index DIP     8 = Index TIP
 9 = Middle MCP   10 = Middle PIP  11 = Middle DIP   12 = Middle TIP
13 = Ring MCP     14 = Ring PIP    15 = Ring DIP     16 = Ring TIP
17 = Pinky MCP    18 = Pinky PIP   19 = Pinky DIP    20 = Pinky TIP
```

**Y-axis note**: In screen coordinates, Y increases downward. A finger is considered "extended" (raised) when its TIP landmark has a **smaller Y value** than its PIP landmark.

### 7.2 Finger Extension Detection

```java
// Returns true if finger is extended (raised up)
boolean isFingerExtended(Landmark tip, Landmark pip) {
    return tip.y < pip.y;
}

boolean indexUp  = isFingerExtended(lm[8],  lm[6]);
boolean middleUp = isFingerExtended(lm[12], lm[10]);
boolean ringUp   = isFingerExtended(lm[16], lm[14]);
boolean pinkyUp  = isFingerExtended(lm[20], lm[18]);

// Thumb: compare x position (flipped for handedness)
boolean thumbUp   = lm[4].y < lm[2].y;
boolean thumbDown = lm[4].y > lm[2].y + 0.04;  // extra threshold
boolean thumbSide = (handedness == RIGHT) ? (lm[4].x < lm[3].x)
                                          : (lm[4].x > lm[3].x);
boolean thumbExtended = thumbUp || thumbSide;
```

### 7.3 Gesture Classification from Extended Fingers

```java
int count = 0;
if (thumbExtended) count++;
if (indexUp)       count++;
if (middleUp)      count++;
if (ringUp)        count++;
if (pinkyUp)       count++;

String gesture;
if (count == 0) {
    gesture = "fist";
} else if (count >= 5) {
    gesture = "open_palm";
} else if (count == 1 && thumbExtended && thumbDown) {
    gesture = "thumb_down";
} else if (count == 1 && thumbExtended) {
    gesture = "thumb_up";
} else if (count == 1 && indexUp) {
    gesture = "one_finger";
} else if (count == 2 && indexUp && middleUp) {
    gesture = "victory";         // V sign = index + middle
} else if (count == 2) {
    gesture = "two_fingers";
} else if (count == 3) {
    gesture = "three_fingers";
} else if (count == 4) {
    gesture = "four_fingers";
} else {
    gesture = null;              // unrecognized
}

// Two hands both open_palm → double_open_palm
if (hand1Gesture.equals("open_palm") && hand2Gesture.equals("open_palm")) {
    gesture = "double_open_palm";
}
```

### 7.4 Stabilization & Debounce

Never fire on every frame. Wait until the gesture is stable:

```java
private String lastGesture = "";
private int stableFrames = 0;
private long lastActionAt = 0;
private static final int MIN_STABLE_FRAMES = 5;
private static final long COOLDOWN_MS = 2600;

void onFrame(String detectedGesture) {
    if (detectedGesture == null) {
        stableFrames = 0;
        return;
    }

    if (detectedGesture.equals(lastGesture)) {
        stableFrames++;
    } else {
        lastGesture = detectedGesture;
        stableFrames = 1;
    }

    if (stableFrames < MIN_STABLE_FRAMES) return;  // not stable yet

    long now = System.currentTimeMillis();
    if (now - lastActionAt < COOLDOWN_MS) return;  // in cooldown

    lastActionAt = now;
    onGestureConfirmed(detectedGesture);  // fire!
}
```

---

## PART 8 — OPENROUTER LLM INTEGRATION (Catalog Voice)

The catalog voice handler uses OpenRouter for product name matching because simple keyword matching isn't reliable enough when the user says a partial product name.

### 8.1 API Call

```java
String systemPrompt = String.format(
    "You are a voice assistant for an agricultural product catalog. " +
    "Available intents: help, catalog_search, catalog_read_products, " +
    "catalog_details, catalog_add, catalog_order, catalog_open_cart. " +
    "Available products: %s. " +
    "User said: \"%s\". " +
    "Reply with ONLY a JSON object like {\"intent\":\"...\",\"product\":null or \"product name\",\"query\":null or \"search query\"} with no extra text.",
    gson.toJson(productNames),
    transcript
);

// HTTP request
HttpRequest request = HttpRequest.newBuilder()
    .uri(URI.create("https://openrouter.ai/api/v1/chat/completions"))
    .header("Authorization", "Bearer " + apiKey)
    .header("Content-Type", "application/json")
    .header("HTTP-Referer", "http://localhost:8000")
    .header("X-Title", "EL FIRMA")
    .POST(HttpRequest.BodyPublishers.ofString(gson.toJson(Map.of(
        "model", "openai/gpt-4o-mini",
        "messages", List.of(Map.of("role", "system", "content", systemPrompt)),
        "temperature", 0.3,
        "max_tokens", 150
    ))))
    .timeout(Duration.ofSeconds(10))
    .build();
```

### 8.2 Response Parsing

```java
// Response body:
// {"choices":[{"message":{"content":"{\"intent\":\"catalog_add\",\"product\":\"Huile d'Olive\",\"query\":null}"}}]}

String content = response.choices[0].message.content;
// Parse inner JSON string
JsonObject result = gson.fromJson(content, JsonObject.class);
String intent   = result.get("intent").getAsString();
String product  = result.has("product") && !result.get("product").isJsonNull()
                  ? result.get("product").getAsString() : null;
String query    = result.has("query") && !result.get("query").isJsonNull()
                  ? result.get("query").getAsString() : null;
```

### 8.3 Fallback (no API key or timeout)
Use keyword matching on the raw transcript:

```java
String t = transcript.toLowerCase();
if (t.contains("search") || t.contains("cherch") || t.contains("recherch")) return "catalog_search";
if (t.contains("add") || t.contains("ajouter")) return "catalog_add";
if (t.contains("details") || t.contains("detail")) return "catalog_details";
if (t.contains("cart") || t.contains("panier")) return "catalog_open_cart";
if (t.contains("order") || t.contains("commander")) return "catalog_order";
return "help";
```

---

## PART 9 — PRODUCT NAME FUZZY MATCHING

When a user says "add olive oil" but the product is named "Huile d'Olive Extra Vierge", exact string match fails. The Symfony app uses a custom scoring function. Replicate this in Java:

```java
double scoreProductMatch(String userInput, String productName) {
    String target  = normalize(userInput);
    String product = normalize(productName);   // normalize = lowercase + remove accents

    double score = 0.0;

    // Exact match
    if (target.equals(product)) return 1.0;

    // Target contained in product name
    if (product.contains(target)) score += 0.68;

    // Product name contained in target
    if (target.contains(product)) score += 0.52;

    // Token overlap
    Set<String> targetTokens  = new HashSet<>(tokenize(target));
    Set<String> productTokens = new HashSet<>(tokenize(product));
    long common = targetTokens.stream().filter(productTokens::contains).count();
    double coverage = targetTokens.isEmpty() ? 0.0 : (double) common / targetTokens.size();
    score += Math.min(0.35, coverage * 0.35);

    // Levenshtein distance similarity
    int dist   = levenshteinDistance(target, product);
    int maxLen = Math.max(target.length(), product.length());
    if (maxLen > 0) {
        double levSimilarity = 1.0 - ((double) dist / maxLen);
        score += levSimilarity * 0.25;
    }

    return score;
}

// Returns null if no product scores >= 0.38 (false positive guard)
Produit findBestMatch(String userInput, List<Produit> products) {
    Produit best = null;
    double  bestScore = 0.38;  // minimum threshold
    for (Produit p : products) {
        double s = scoreProductMatch(userInput, p.getNom());
        if (s > bestScore) { bestScore = s; best = p; }
    }
    return best;
}
```

---

## PART 10 — COMPLETE JAVA IMPLEMENTATION PLAN

### 10.1 Project Structure

```
src/main/java/com/elfirma/assistant/
  ├── ai/
  │   ├── IntentModel.java          // Naive Bayes model (train + predict)
  │   ├── VoiceIntentClassifier.java // trains on voice data, predicts voice intents
  │   ├── GestureIntentClassifier.java // trains on gesture data (per context)
  │   └── OpenRouterClient.java     // calls OpenRouter LLM API
  ├── gesture/
  │   ├── GestureDetector.java      // processes MediaPipe landmarks → gesture name
  │   ├── GestureStabilizer.java    // debounce + frame stabilization
  │   └── CameraCapture.java        // webcam feed using Java OpenCV or JavaFX WebView
  ├── voice/
  │   ├── SpeechRecognizer.java     // captures audio → transcript
  │   └── TextToSpeech.java         // speaks text aloud
  ├── handler/
  │   ├── CatalogCommandHandler.java // handles catalog intents + actions
  │   ├── CartCommandHandler.java    // handles cart intents + actions
  │   └── CheckoutCommandHandler.java // handles checkout intents + actions
  ├── model/
  │   ├── IntentResult.java         // { intent, confidence, scores }
  │   ├── CommandResponse.java      // { ok, speak, actions }
  │   └── AssistantAction.java      // { type, extra fields }
  └── AssistantManager.java         // orchestrates everything
```

### 10.2 IntentModel.java

```java
package com.elfirma.assistant.ai;

import java.util.*;

public class IntentModel {
    private Map<String, Double> logPriors = new HashMap<>();
    private Map<String, Map<String, Double>> logLikelihoods = new HashMap<>();
    private Map<String, Integer> classTotals = new HashMap<>();
    private Set<String> vocabulary = new HashSet<>();
    private List<String> classes = new ArrayList<>();

    public static IntentModel train(Map<String, List<String>> trainingSet) {
        IntentModel model = new IntentModel();
        model.classes = new ArrayList<>(trainingSet.keySet());

        // Count docs per class
        Map<String, Integer> docCounts = new HashMap<>();
        int totalDocs = 0;
        for (var entry : trainingSet.entrySet()) {
            docCounts.put(entry.getKey(), entry.getValue().size());
            totalDocs += entry.getValue().size();
        }

        // Build token frequency maps
        Map<String, Map<String, Integer>> tokenFreq = new HashMap<>();
        for (var entry : trainingSet.entrySet()) {
            String cls = entry.getKey();
            tokenFreq.put(cls, new HashMap<>());
            for (String example : entry.getValue()) {
                for (String token : tokenize(example)) {
                    model.vocabulary.add(token);
                    tokenFreq.get(cls).merge(token, 1, Integer::sum);
                    model.classTotals.merge(cls, 1, Integer::sum);
                }
            }
        }

        int vocabSize = model.vocabulary.size();
        int numClasses = model.classes.size();

        // Compute log priors
        for (String cls : model.classes) {
            model.logPriors.put(cls,
                Math.log((double)(docCounts.get(cls) + 1) / (totalDocs + numClasses)));
        }

        // Compute log likelihoods with Laplace smoothing
        for (String cls : model.classes) {
            model.logLikelihoods.put(cls, new HashMap<>());
            int classTotal = model.classTotals.getOrDefault(cls, 0);
            for (String token : model.vocabulary) {
                int freq = tokenFreq.get(cls).getOrDefault(token, 0);
                double ll = Math.log((double)(freq + 1) / (classTotal + vocabSize));
                model.logLikelihoods.get(cls).put(token, ll);
            }
        }

        return model;
    }

    public IntentResult predict(String text) {
        List<String> tokens = tokenize(text);
        double unknownPenalty = Math.log(1.0 / Math.max(1, vocabulary.size()));

        Map<String, Double> scores = new HashMap<>();
        for (String cls : classes) {
            double score = logPriors.getOrDefault(cls, 0.0);
            for (String token : tokens) {
                score += logLikelihoods.get(cls).getOrDefault(token, unknownPenalty);
            }
            scores.put(cls, score);
        }

        // Find top and second
        String top = classes.stream().max(Comparator.comparingDouble(scores::get)).orElse("help");
        double topScore = scores.get(top);
        double second = scores.values().stream()
            .filter(s -> s < topScore).mapToDouble(Double::doubleValue).max().orElse(topScore - 5);

        double gap = topScore - second;
        double confidence = 1.0 / (1.0 + Math.exp(-gap));

        return new IntentResult(top, confidence, scores);
    }

    public static List<String> tokenize(String text) {
        // 1. NFD normalize + strip diacritics
        String normalized = java.text.Normalizer.normalize(text, java.text.Normalizer.Form.NFD);
        normalized = normalized.replaceAll("\\p{Mn}", "");
        // 2. Lowercase
        normalized = normalized.toLowerCase();
        // 3. Remove non-alphanumeric
        normalized = normalized.replaceAll("[^a-z0-9 ]", " ");
        // 4. Split + filter short tokens
        return Arrays.stream(normalized.split("\\s+"))
            .filter(t -> t.length() >= 2)
            .toList();
    }
}
```

### 10.3 VoiceIntentClassifier.java

```java
package com.elfirma.assistant.ai;

public class VoiceIntentClassifier {
    private IntentModel model;

    public VoiceIntentClassifier() {
        // Train on startup (fast, <100ms)
        model = IntentModel.train(buildTrainingData());
    }

    public IntentResult predict(String transcript) {
        return model.predict(transcript);
    }

    private Map<String, List<String>> buildTrainingData() {
        // Paste the full training data from Part 5 here
        Map<String, List<String>> data = new LinkedHashMap<>();
        data.put("help", List.of("aide", "help", "montre moi les commandes", ...));
        // ... all 24 intents
        return data;
    }
}
```

### 10.4 GestureIntentClassifier.java

```java
package com.elfirma.assistant.ai;

public class GestureIntentClassifier {
    private final Map<String, IntentModel> contextModels = new HashMap<>();

    public GestureIntentClassifier() {
        contextModels.put("catalog",  IntentModel.train(catalogTrainingData()));
        contextModels.put("cart",     IntentModel.train(cartTrainingData()));
        contextModels.put("checkout", IntentModel.train(checkoutTrainingData()));
    }

    public IntentResult predict(String context, String gestureName) {
        IntentModel model = contextModels.getOrDefault(context, contextModels.get("catalog"));
        return model.predict(gestureName);
    }

    private Map<String, List<String>> catalogTrainingData() {
        // Paste catalog gesture training data from Part 6
    }
    // ... cartTrainingData(), checkoutTrainingData()
}
```

### 10.5 Speech-to-Text in JavaFX

Java doesn't have a built-in speech recognition API. Options:

**Option A — Use Vosk (offline, free, no API)**
```xml
<dependency>
    <groupId>com.alphacephei</groupId>
    <artifactId>vosk</artifactId>
    <version>0.3.45</version>
</dependency>
```
Download a Vosk model for French or English from https://alphacephei.com/vosk/models

```java
Model model = new Model("path/to/vosk-model-fr");
Recognizer rec = new Recognizer(model, 16000);

// Feed audio chunks from microphone
AudioFormat format = new AudioFormat(16000, 16, 1, true, false);
TargetDataLine mic = AudioSystem.getTargetDataLine(format);
mic.open(format);
mic.start();

byte[] buffer = new byte[4096];
while (recording) {
    int read = mic.read(buffer, 0, buffer.length);
    if (rec.acceptWaveForm(buffer, read)) {
        String result = rec.getResult();
        // Parse JSON: {"text": "add tomato"}
        String transcript = parseVoskResult(result);
        onTranscript(transcript);
    }
}
```

**Option B — Use JavaFX WebView to access Web Speech API**
Embed a small HTML page in a hidden WebView and bridge the transcript back to Java:
```java
webEngine.executeScript("startListening()");
// JS calls: window.javaCallback.onTranscript(transcript)
webEngine.getLoadWorker().stateProperty().addListener(...);
```

**Option C — Call the Symfony endpoint which calls OpenRouter**
Send text typed by user → no microphone needed. Good for testing.

### 10.6 Text-to-Speech in JavaFX

**Option A — FreeTTS (open source, robotic voice)**
```xml
<dependency>
    <groupId>net.sf.sociaal</groupId>
    <artifactId>freetts</artifactId>
    <version>1.2.2</version>
</dependency>
```

**Option B — System TTS via ProcessBuilder**
```java
// Windows (uses Windows TTS engine, good quality)
void speak(String text) {
    String script = String.format(
        "Add-Type -AssemblyName System.Speech; " +
        "$s = New-Object System.Speech.Synthesis.SpeechSynthesizer; " +
        "$s.Speak('%s')", text.replace("'", ""));
    new ProcessBuilder("powershell", "-Command", script).start();
}
```

**Option C — JavaFX WebView bridge**
Same hidden WebView as speech recognition — call `speechSynthesis.speak()` via JS.

### 10.7 Gesture Detection in JavaFX

**Recommended: JavaFX WebView with gesture_assistant.js**

1. Copy `gesture_assistant.js` from the Symfony `public/assets/js/` into your JavaFX resources
2. Create an FXML scene with a `WebView` set to `visible: false` (or visible for camera preview)
3. Load a small HTML page that initializes the gesture assistant
4. Bridge detected gestures to Java using `JSObject`:

```java
// Java side
webEngine.getLoadWorker().stateProperty().addListener((obs, old, newState) -> {
    if (newState == Worker.State.SUCCEEDED) {
        JSObject window = (JSObject) webEngine.executeScript("window");
        window.setMember("javaGestureCallback", new JavaGestureCallback());
    }
});

// Callback class
public class JavaGestureCallback {
    public void onGesture(String gestureName) {
        Platform.runLater(() -> handleGesture(gestureName));
    }
}

// HTML (in WebView)
// <script>
// var assistant = GestureAssistant.create({
//   id: 'catalog',
//   onGesture: function(gesture) {
//     window.javaGestureCallback.onGesture(gesture);
//     return null;  // don't speak in JS, Java will handle it
//   }
// });
// </script>
```

---

## PART 11 — SUPPLIER VOICE ASSISTANT (Admin Module)

This is a separate multi-turn conversation system for creating/editing/deleting suppliers. It is stateful — the server tracks which field is being collected.

### 11.1 How It Works

The conversation has states stored on the server session:

```
IDLE
  → user says "create supplier" → COLLECTING (field: type)
  → user provides type value    → COLLECTING (field: description)
  → user provides description   → COLLECTING (field: adresse)
  → user provides adresse       → COLLECTING (field: tel)
  → user provides tel           → COLLECTING (field: email)
  → user provides email         → COLLECTING (field: statut)
  → user provides statut        → CONFIRMING (read back all fields)
  → user says "yes"             → SAVE supplier to DB → IDLE
  → user says "no"              → IDLE (cancelled)

IDLE → user says "delete supplier" → DELETING (ask for name)
  → user provides name          → DELETING_CONFIRM (read back name)
  → user says "yes"             → DELETE from DB → IDLE

IDLE → user says "edit supplier" → EDITING (ask for name)
  → user provides name          → EDITING_FIELD (ask which field)
  → user says field name        → EDITING_VALUE (ask for new value)
  → user provides value         → UPDATE in DB → IDLE
```

### 11.2 Endpoints

```
POST /voice-assistant/greet
  → No body required
  → Response: { "status": "idle", "reply": "Hello! How can I help you?" }

POST /voice-assistant/process
  Body: { "text": "create supplier" }
  Response: {
    "status": "collecting",       // idle | offering | collecting | confirming | deleting | save
    "reply":  "What is the supplier type? For example: equipment, seeds, or fertilizer.",
    "field":  "type",             // current field being collected
    "payload": null               // populated on status=save with all field values
  }

On status="save":
  "payload": {
    "type": "Seeds",
    "description": "Organic seed supplier",
    "adresse": "Tunis Centre",
    "tel": "12345678",
    "email": "seeds@example.com",
    "statut": "Active"
  }
```

### 11.3 Field Validation Rules

| Field | Validation |
|-------|-----------|
| `type` | 2–50 characters, any text |
| `description` | 2–100 characters, any text |
| `adresse` | Must contain at least 1 letter |
| `tel` | Exactly 8 digits (spoken digits like "zero one two..." are converted) |
| `email` | Valid email format (contains @) |
| `statut` | Must be one of: `active`, `inactive`, `suspended` (fuzzy matched from spoken word) |

### 11.4 Java Implementation

```java
// Replicate the state machine locally in Java (no HTTP calls needed)

public class SupplierVoiceSession {
    enum State { IDLE, COLLECTING, CONFIRMING, DELETING, DELETING_CONFIRM, EDITING, EDITING_FIELD, EDITING_VALUE }

    State state = State.IDLE;
    Map<String, String> data = new LinkedHashMap<>();
    String currentField = null;
    String[] fields = {"type", "description", "adresse", "tel", "email", "statut"};
    int fieldIndex = 0;

    Map<String, String> fieldPrompts = Map.of(
        "type",        "What is the supplier type? For example: equipment, seeds, or fertilizer.",
        "description", "Give a brief description of the supplier.",
        "adresse",     "What is the supplier address? Include the city name.",
        "tel",         "What is the phone number? Exactly 8 digits.",
        "email",       "What is the email address?",
        "statut",      "What is the status? Say: active, inactive, or suspended."
    );

    public String process(String userInput, VoiceIntentClassifier intentAI) {
        String intent = intentAI.predict(userInput).intent();

        if (state == State.IDLE) {
            if (intent.equals("create_supplier")) {
                state = State.COLLECTING;
                fieldIndex = 0;
                currentField = fields[fieldIndex];
                data.clear();
                return fieldPrompts.get(currentField);
            }
            // ... handle delete/edit/greeting intents
        }

        if (state == State.COLLECTING) {
            ValidationResult v = validateField(currentField, userInput);
            if (!v.valid()) return "Invalid. " + v.errorMessage() + " " + fieldPrompts.get(currentField);
            data.put(currentField, v.value());
            fieldIndex++;
            if (fieldIndex >= fields.length) {
                state = State.CONFIRMING;
                return buildConfirmationSpeech(data);
            }
            currentField = fields[fieldIndex];
            return fieldPrompts.get(currentField);
        }

        if (state == State.CONFIRMING) {
            if (isYes(userInput)) {
                saveSupplier(data);  // INSERT into fournisseur table
                state = State.IDLE;
                return "Supplier saved successfully. How can I help you next?";
            } else {
                state = State.IDLE;
                return "Cancelled. How can I help you?";
            }
        }

        return "Sorry, I didn't understand. How can I help you?";
    }

    ValidationResult validateField(String field, String input) {
        switch (field) {
            case "tel":
                String digits = input.replaceAll("[^0-9]", "");
                // also convert spoken words: "zero one two..." → "012..."
                if (digits.length() != 8) return ValidationResult.error("Phone must be exactly 8 digits.");
                return ValidationResult.ok(digits);
            case "email":
                if (!input.contains("@")) return ValidationResult.error("Invalid email address.");
                return ValidationResult.ok(input.trim().toLowerCase());
            case "statut":
                String s = input.toLowerCase();
                if (s.contains("active") || s.contains("actif")) return ValidationResult.ok("active");
                if (s.contains("inactive") || s.contains("inactif")) return ValidationResult.ok("inactive");
                if (s.contains("suspend")) return ValidationResult.ok("suspended");
                return ValidationResult.error("Say: active, inactive, or suspended.");
            default:
                if (input.trim().length() < 2) return ValidationResult.error("Too short, please try again.");
                return ValidationResult.ok(capitalize(input.trim()));
        }
    }
}
```

---

## PART 12 — COMPLETE ACTION HANDLER (JavaFX)

When the backend (or local classifier) returns an action, execute it in JavaFX:

```java
public class ActionExecutor {
    private ProductListController productList;
    private CartController cart;
    private CheckoutController checkout;
    private TextToSpeech tts;

    public void execute(List<AssistantAction> actions) {
        for (AssistantAction action : actions) {
            Platform.runLater(() -> doExecute(action));
        }
    }

    private void doExecute(AssistantAction action) {
        switch (action.type()) {
            case "read_visible_products" -> {
                String names = productList.getVisibleProductNames().stream()
                    .limit(5).collect(Collectors.joining(", "));
                tts.speak("Visible products: " + names);
            }
            case "navigate" -> {
                // Switch main content pane to the appropriate module
                mainController.navigateTo(action.url());
            }
            case "open_product_details" -> {
                productList.openDetailPanel(action.productId());
            }
            case "add_to_cart" -> {
                cart.addProduct(action.productId(), 1);
                tts.speak("Added to cart.");
            }
            case "add_to_cart_then_checkout" -> {
                cart.addProduct(action.productId(), 1);
                mainController.navigateTo("checkout");
            }
            case "filter_products" -> {
                productList.setSearchFilter(action.query());
            }
            case "remove_from_cart" -> {
                cart.removeProduct(action.productId());
                tts.speak("Removed from cart.");
            }
            case "update_cart_quantity_delta" -> {
                cart.updateQuantityDelta(action.productId(), action.delta());
            }
            case "update_cart_quantity_set" -> {
                cart.setQuantity(action.productId(), action.quantity());
            }
            case "clear_cart" -> {
                cart.clear();
                tts.speak("Cart cleared.");
            }
            case "set_field" -> {
                checkout.setFieldValue(action.fieldId(), action.value());
            }
            case "set_payment_mode" -> {
                checkout.selectPaymentMode(action.value());
            }
            case "read_cart_summary" -> {
                String summary = cart.buildSummaryText();
                tts.speak(summary);
            }
            case "read_checkout_summary" -> {
                String summary = checkout.buildSummaryText();
                tts.speak(summary);
            }
            case "focus_next_checkout_field" -> {
                checkout.focusNextField();
            }
            case "click_confirm_order" -> {
                checkout.submitOrder();
            }
            case "reload_page" -> {
                mainController.refreshCurrentView();
            }
        }
    }
}
```

---

## PART 13 — UI COMPONENTS TO BUILD

### Voice Assistant Button
- Floating button (bottom-right, 60px circle)
- **Idle**: green (`#43682b`), microphone icon
- **Listening**: red (`#dc2626`), pulsing animation
- **Speaking**: purple (`#7c3aed`), speaker icon
- Click: toggle start/stop listening

### Voice Assistant Panel (Collapsible)
Shows above the FAB button:
- Transcript of what user said (italic)
- AI response text (bold)
- Field progress dots (for supplier assistant: 6 dots = 6 fields, colored: done/current/pending)
- Current field label

### Gesture Camera Panel
- Toggle button (FAB, bottom-left)
- Camera preview video (480×270 or full 640×480)
- Current gesture label (large text overlay)
- Gesture guide list (icon + description per gesture)
- Cooldown progress bar (visual feedback)

### Notification Toast
After any assistant action, show a brief toast message (2 seconds):
- Green for success
- Red for error
- Animate in from bottom, fade out

---

## PART 14 — QUICK INTEGRATION CHECKLIST

Use this to track which pieces are needed:

- [ ] `IntentModel.java` — Naive Bayes (train + predict + tokenize)
- [ ] `VoiceIntentClassifier.java` — trained on voice data (Part 5)
- [ ] `GestureIntentClassifier.java` — trained on gesture data (Part 6)
- [ ] `OpenRouterClient.java` — HTTP call for catalog commands
- [ ] `GestureDetector.java` — landmark → gesture name (Part 7)
- [ ] `GestureStabilizer.java` — debounce logic (Part 7.4)
- [ ] Speech recognition integration (Part 10.5)
- [ ] Text-to-speech integration (Part 10.6)
- [ ] Camera access (WebView bridge or Java OpenCV)
- [ ] `CatalogCommandHandler.java` — handles catalog intents
- [ ] `CartCommandHandler.java` — handles cart intents
- [ ] `CheckoutCommandHandler.java` — handles checkout intents
- [ ] `SupplierVoiceSession.java` — multi-turn supplier conversation (Part 11)
- [ ] `ActionExecutor.java` — executes all action types (Part 12)
- [ ] Voice assistant UI (floating button + collapsible panel)
- [ ] Gesture camera UI (FAB + preview + guide)
- [ ] Notification toast component

---

## PART 15 — CONTEXT BEHAVIOR SUMMARY

| Context | When active | Voice handles | Gestures handle |
|---------|-------------|---------------|-----------------|
| `catalog` | Product list page | Search, add, details, open cart | Navigate, add, details 1-5 |
| `cart` | Cart/basket page | Read, increase, decrease, remove, clear, checkout | Read, increase, decrease, clear, checkout |
| `checkout` | Order form page | Name, address, payment mode, promo, confirm | Next field, promo, confirm, read summary |
| `supplier` | Supplier admin page | Create/edit/delete supplier (multi-turn) | Not implemented |
