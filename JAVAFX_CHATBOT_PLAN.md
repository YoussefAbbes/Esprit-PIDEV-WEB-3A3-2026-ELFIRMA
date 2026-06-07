# JavaFX Chatbot Integration Plan — ELFIRMA Agriculture Platform

> **Scope**: Two chatbot features for a JavaFX desktop app sharing the MySQL `personne`
> database already used by the Symfony web platform.
>
> - **Client pages** → LM Studio chatbot (local LLM, conversational assistant)
> - **Admin pages** → RAG chatbot (database-grounded Q&A over farm data)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                      JavaFX Desktop App                         │
│                                                                 │
│  ┌──────────────────────┐    ┌──────────────────────────────┐  │
│  │   Client Pages       │    │   Admin Pages                │  │
│  │  (ChatbotClient.fxml)│    │  (ChatbotAdmin.fxml)         │  │
│  │                      │    │                              │  │
│  │  LM Studio Chatbot   │    │  RAG Chatbot                 │  │
│  │  • Natural language  │    │  • Retrieves live DB data    │  │
│  │  • Farm Q&A          │    │  • Embeds context into       │  │
│  │  • Product orders    │    │    LM Studio prompt          │  │
│  │  • General help      │    │  • Admin analytics & alerts  │  │
│  └──────────┬───────────┘    └──────────────┬───────────────┘  │
│             │                               │                   │
│             ▼                               ▼                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              LmStudioService (singleton)                  │  │
│  │   POST http://localhost:1234/v1/chat/completions          │  │
│  │   OpenAI-compatible REST API                             │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              DatabaseService (JDBC → MySQL)               │  │
│  │   jdbc:mysql://127.0.0.1:3306/personne                   │  │
│  │   Queries: Produits, Commandes, Cultures, Animaux, …     │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                        ▲
                        │  shared database
                        ▼
             MySQL  ·  personne  ·  root/@localhost
```

---

## 2. Technology Stack

| Layer | Choice | Notes |
|---|---|---|
| UI framework | JavaFX 21 | FXML + CSS |
| Build tool | Maven 3.9+ | `pom.xml` |
| LLM API | LM Studio REST (`/v1/chat/completions`) | OpenAI-compatible, runs locally |
| HTTP client | Java 11 `HttpClient` (built-in) | No extra dependency |
| JSON | Gson 2.10 | Lightweight |
| Database | JDBC + MySQL Connector/J 8 | Same DB as Symfony |
| Embeddings (RAG) | LM Studio embedding endpoint | `/v1/embeddings` (if model supports it) or cosine-sim over TF-IDF in-memory |
| CSS | JavaFX CSS | Match ELFIRMA dark-green palette |

---

## 3. Maven Dependencies

Add to `pom.xml`:

```xml
<!-- JavaFX -->
<dependency>
  <groupId>org.openjfx</groupId>
  <artifactId>javafx-controls</artifactId>
  <version>21</version>
</dependency>
<dependency>
  <groupId>org.openjfx</groupId>
  <artifactId>javafx-fxml</artifactId>
  <version>21</version>
</dependency>

<!-- MySQL JDBC -->
<dependency>
  <groupId>mysql</groupId>
  <artifactId>mysql-connector-java</artifactId>
  <version>8.0.33</version>
</dependency>

<!-- JSON -->
<dependency>
  <groupId>com.google.code.gson</groupId>
  <artifactId>gson</artifactId>
  <version>2.10.1</version>
</dependency>
```

---

## 4. Project File Structure

```
src/main/
├── java/com/elfirma/
│   ├── MainApp.java
│   ├── config/
│   │   └── AppConfig.java              ← DB URL, LM Studio URL, model name
│   ├── db/
│   │   └── DatabaseService.java        ← JDBC singleton, query helpers
│   ├── llm/
│   │   ├── LmStudioService.java        ← HTTP POST to LM Studio, stream support
│   │   └── ChatMessage.java            ← {role, content} POJO
│   ├── rag/
│   │   ├── RagService.java             ← orchestrates retrieval + LM Studio
│   │   ├── DocumentChunk.java          ← {text, source, embedding[]}
│   │   └── EmbeddingStore.java         ← in-memory vector store
│   ├── controller/
│   │   ├── client/
│   │   │   └── ChatbotClientController.java
│   │   └── admin/
│   │       └── ChatbotAdminController.java
│   └── util/
│       └── MarkdownRenderer.java       ← strips ** / * for plain JavaFX labels
├── resources/
│   ├── fxml/
│   │   ├── client/ChatbotClient.fxml
│   │   └── admin/ChatbotAdmin.fxml
│   ├── css/
│   │   └── chatbot.css
│   └── prompts/
│       ├── system_client.txt           ← system prompt for client bot
│       └── system_admin.txt            ← system prompt for admin RAG bot
```

---

## 5. Configuration (`AppConfig.java`)

```java
public class AppConfig {
    // LM Studio
    public static final String LM_STUDIO_URL   = "http://localhost:1234/v1/chat/completions";
    public static final String LM_EMBED_URL    = "http://localhost:1234/v1/embeddings";
    public static final String LM_MODEL        = "local-model"; // matches model loaded in LM Studio

    // Database — same as Symfony .env
    public static final String DB_URL  = "jdbc:mysql://127.0.0.1:3306/personne?useSSL=false&serverTimezone=UTC";
    public static final String DB_USER = "root";
    public static final String DB_PASS = "";

    // RAG
    public static final int    RAG_TOP_K        = 5;
    public static final int    RAG_MAX_TOKENS   = 1800;
    public static final double RAG_TEMPERATURE  = 0.3;   // lower = more factual

    // Client chatbot
    public static final double CLIENT_TEMPERATURE = 0.7;
}
```

---

## 6. Database Service (`DatabaseService.java`)

Single JDBC connection pool. Provides typed query methods used by both chatbots.

```java
// Key methods to implement:
Connection getConnection();

// Used by client chatbot
List<Map<String,Object>> getAvailableProducts();      // produit WHERE statut='Available'
List<Map<String,Object>> getClientOrders(int userId); // commande WHERE utilisateur_id=?
Map<String,Object>       getProductById(int id);

// Used by admin RAG
String getAllCulturesAsCsv();      // id, nomCulture, statut, rendement, parcelle
String getAllAnimalsAsCsv();       // id, type, etat_sante, elevage
String getMaintenanceSummary();    // overdue/pending maintenance
String getStockAlert();            // produit WHERE quantite_stock < 10
String getOrderStatsByStatus();    // COUNT by statut_commande
String getSupplierRatings();       // fournisseur + avg(number_of_stars)
String getIrrigationAlerts();      // irrigation_event WHERE needsWater=1
```

Each CSV method returns a compact multi-line string that fits inside an LLM context window. Columns are pipe-separated to save tokens.

---

## 7. LM Studio Service (`LmStudioService.java`)

```java
public class LmStudioService {

    // Send a list of messages, return assistant reply as String
    public String chat(List<ChatMessage> messages, double temperature, int maxTokens)
        throws IOException, InterruptedException { ... }

    // Streaming variant — calls consumer for each token chunk
    public void chatStream(List<ChatMessage> messages,
                           double temperature,
                           Consumer<String> tokenConsumer)
        throws IOException, InterruptedException { ... }

    // For RAG embeddings
    public double[] embed(String text) throws IOException, InterruptedException { ... }
}
```

**Request body shape (Gson):**

```json
{
  "model": "local-model",
  "messages": [
    {"role": "system", "content": "..."},
    {"role": "user",   "content": "..."}
  ],
  "temperature": 0.7,
  "max_tokens": 1024,
  "stream": false
}
```

Parse `choices[0].message.content` from the response.

---

## 8. Client Chatbot — LM Studio

### 8.1 Purpose

A conversational assistant embedded in client-facing JavaFX pages. It:

- Answers questions about available products, prices, and stock
- Helps clients track their orders
- Answers general farming / crop questions
- Does NOT have direct write access to the DB (read-only for safety)

### 8.2 System Prompt (`system_client.txt`)

```
You are ELFIRMA's friendly farm assistant chatbot.
You help clients with product browsing, order tracking, and general
agriculture questions. Be concise and helpful.

You will receive live data from the database prepended to the user message
in JSON form when relevant. Use that data to answer precisely.
Do not make up stock quantities or prices — only use the provided data.

If a client asks to place an order, tell them to use the Orders section
of the application.
```

### 8.3 `ChatbotClientController.java`

```
State:
  List<ChatMessage> conversationHistory   ← grows with each turn
  String currentUserId                    ← passed in from login session

On user sends message:
  1. Detect intent keywords in message text:
       "order" / "commande"  → prepend getClientOrders(userId) as JSON
       "product" / "stock"   → prepend getAvailableProducts() as JSON
       "price" / "prix"      → same as product
  2. Add enriched user message to conversationHistory
  3. Call LmStudioService.chatStream(conversationHistory, 0.7, 1024)
  4. Stream tokens into ChatBubble as they arrive (Platform.runLater)
  5. Append final assistant message to conversationHistory
  6. Keep last 20 messages max (trim oldest to avoid context overflow)
```

### 8.4 UI — `ChatbotClient.fxml`

```
┌──────────────────────────────────────────────┐
│  🌿 ELFIRMA Assistant          [×] [─]       │
├──────────────────────────────────────────────┤
│                                              │
│   ┌─────────────────────────────────────┐   │
│   │ Hello! I'm your farm assistant.     │   │  ← bot bubble (left, green)
│   │ How can I help you today?           │   │
│   └─────────────────────────────────────┘   │
│                                              │
│             ┌──────────────────────────────┐ │
│             │ What products are available? │ │  ← user bubble (right, dark)
│             └──────────────────────────────┘ │
│                                              │
│   ┌───────────────────────────────────────┐  │
│   │ Here are the available products: …   │  │
│   └───────────────────────────────────────┘  │
│                                              │
├──────────────────────────────────────────────┤
│  [Type your message…        ] [Send ▶]       │
└──────────────────────────────────────────────┘
```

- Bot bubbles: left-aligned, `#e8f5e2` background
- User bubbles: right-aligned, `#1a3a0a` background, white text
- Scrollable `VBox` inside `ScrollPane` with `fitToWidth=true`
- `TextField` → Enter key fires send
- Typing indicator (animated dots) shown during LM Studio call

---

## 9. Admin Chatbot — RAG

### 9.1 Purpose

An analytics assistant for admins. It:

- Answers questions grounded in live database data (crops, animals, orders, stock, maintenance)
- Can summarize farm status, highlight problems (low stock, sick animals, overdue maintenance)
- Provides factual, cited answers ("According to the database, 3 parcelles are resting…")
- Does NOT hallucinate — retrieval gates the answer

### 9.2 RAG Pipeline

```
User question
     │
     ▼
┌─────────────────────────────────────────────┐
│  Step 1 — Intent Router                     │
│  Classify question into domain category:    │
│  cultures | animals | products | orders |   │
│  maintenance | irrigation | suppliers |     │
│  general                                    │
└─────────────────────┬───────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────┐
│  Step 2 — Retrieval                         │
│  Run domain-specific DB query → CSV string  │
│  (from DatabaseService methods above)       │
└─────────────────────┬───────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────┐
│  Step 3 — Context Assembly                  │
│  Build system prompt:                       │
│    system_admin.txt +                       │
│    "=== LIVE DATABASE SNAPSHOT ===" +       │
│    retrieved CSV data                       │
└─────────────────────┬───────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────┐
│  Step 4 — LM Studio generation              │
│  temperature=0.3, max_tokens=1800           │
│  Returns grounded answer                   │
└─────────────────────────────────────────────┘
```

### 9.3 Intent Router

Simple keyword-based router (no external model needed):

```java
public class IntentRouter {
    public enum Domain {
        CULTURES, ANIMALS, PRODUCTS, ORDERS,
        MAINTENANCE, IRRIGATION, SUPPLIERS, GENERAL
    }

    public Domain classify(String question) {
        String q = question.toLowerCase();
        if (q.matches(".*(culture|crop|harvest|récolte|parcell).*"))  return CULTURES;
        if (q.matches(".*(animal|livestock|élevage|santé|vaccin).*")) return ANIMALS;
        if (q.matches(".*(produit|stock|product|inventory|expir).*")) return PRODUCTS;
        if (q.matches(".*(commande|order|paiement|payment|client).*"))return ORDERS;
        if (q.matches(".*(maintenance|équipement|equipment|repair).*"))return MAINTENANCE;
        if (q.matches(".*(irrigation|water|eau|humidity|soil).*"))    return IRRIGATION;
        if (q.matches(".*(fournisseur|supplier|contrat|contract).*")) return SUPPLIERS;
        return GENERAL;
    }
}
```

### 9.4 System Prompt (`system_admin.txt`)

```
You are ELFIRMA's admin data analyst. You answer questions about the farm
using ONLY the database snapshot provided after "=== LIVE DATABASE SNAPSHOT ===".

Rules:
- Never invent numbers, dates, or names not in the snapshot.
- Cite the source table name when giving specific figures.
- Be concise. Use bullet points for lists.
- If the snapshot doesn't have enough data to answer, say so clearly.
- Answer in the same language the user writes in (French or English).
- Flag critical issues (sick animals, expired products, overdue maintenance)
  with ⚠️ prefix.
```

### 9.5 `ChatbotAdminController.java`

```
State:
  List<ChatMessage> conversationHistory
  IntentRouter router

On admin sends message:
  1. Domain = router.classify(message)
  2. Fetch relevant data from DatabaseService based on Domain
     (may call multiple query methods for GENERAL domain)
  3. Build system prompt = system_admin.txt + "\n=== LIVE DATABASE SNAPSHOT ===\n" + data
  4. Build message list:
       [{role:"system", content: systemPrompt},
        ...last 6 turns of conversationHistory,
        {role:"user", content: message}]
  5. Call LmStudioService.chat(messages, 0.3, 1800)   ← no streaming for admin (simpler)
  6. Display response in chat panel
  7. Append user+assistant to conversationHistory (keep last 12)
```

### 9.6 Quick-Action Buttons

Admin panel has preset buttons that send a pre-written question instantly:

| Button | Pre-written question sent to RAG |
|---|---|
| Farm Status | "Give me a complete status summary of the farm right now." |
| Low Stock | "Which products are critically low in stock or expired?" |
| Sick Animals | "List all animals with health issues or pending vaccinations." |
| Overdue Maintenance | "Show all overdue or high-priority maintenance tasks." |
| Order Summary | "Summarize today's orders by status and payment." |
| Irrigation Alerts | "Are there any parcelles that need watering right now?" |

### 9.7 UI — `ChatbotAdmin.fxml`

```
┌──────────────────────────────────────────────────────────────┐
│  📊 ELFIRMA Admin Analyst                       [×] [─]      │
├──────────────────────────────────────────────────────────────┤
│  Quick actions:                                              │
│  [Farm Status] [Low Stock] [Sick Animals] [Maintenance] …    │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│   ┌──────────────────────────────────────────────────────┐  │
│   │ Welcome, Admin. Ask me anything about your farm.    │  │
│   └──────────────────────────────────────────────────────┘  │
│                                                              │
│              ┌───────────────────────────────────────────┐  │
│              │ Which parcelles are resting?              │  │
│              └───────────────────────────────────────────┘  │
│                                                              │
│   ┌──────────────────────────────────────────────────────┐  │
│   │ According to parcelle table, 2 parcelles have       │  │
│   │ statut = "Resting":                                 │  │
│   │  • Parcelle Nord (id=3)                             │  │
│   │  • Parcelle Sud-Ouest (id=7)                        │  │
│   └──────────────────────────────────────────────────────┘  │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  [Ask anything about the farm…               ] [Send ▶]     │
└──────────────────────────────────────────────────────────────┘
```

- Quick-action buttons: pill-shaped, `#43682b` green
- Bot bubbles: left, `#f0f4ed`
- User bubbles: right, `#0a2200` dark green, white text
- A "Sources" label below each bot answer listing which DB tables were queried
- Spinner while waiting (admin RAG is slightly slower due to DB query)

---

## 10. CSS (`chatbot.css`) — ELFIRMA palette

```css
.chat-root { -fx-background-color: #f5f5f0; }

.bubble-bot {
    -fx-background-color: #e8f5e2;
    -fx-background-radius: 12 12 12 0;
    -fx-padding: 10 14;
    -fx-max-width: 420;
}

.bubble-user {
    -fx-background-color: #1a3a0a;
    -fx-background-radius: 12 12 0 12;
    -fx-text-fill: white;
    -fx-padding: 10 14;
    -fx-max-width: 420;
}

.quick-action-btn {
    -fx-background-color: #43682b;
    -fx-text-fill: white;
    -fx-background-radius: 20;
    -fx-padding: 6 14;
    -fx-cursor: hand;
}

.quick-action-btn:hover { -fx-background-color: #2e4d1c; }

.chat-input {
    -fx-background-radius: 20;
    -fx-border-radius: 20;
    -fx-padding: 8 14;
}

.source-label {
    -fx-text-fill: #888;
    -fx-font-size: 10px;
    -fx-padding: 2 14;
}

.typing-indicator { -fx-text-fill: #43682b; -fx-font-style: italic; }
```

---

## 11. Implementation Steps

### Phase 1 — Foundation (2–3 days)

- [ ] 1.1 Create Maven project with JavaFX + Gson + MySQL Connector dependencies
- [ ] 1.2 Implement `AppConfig.java` with all constants
- [ ] 1.3 Implement `DatabaseService.java`:
  - JDBC connection (test against `personne` DB)
  - All query methods listed in Section 6
- [ ] 1.4 Implement `LmStudioService.java`:
  - `chat()` method with Gson request/response
  - Verify with a simple "hello" test in `main()`
- [ ] 1.5 Load and verify LM Studio is running with a suitable model
  - Recommended: `mistral-7b-instruct` or `llama-3.1-8b-instruct`
  - Enable server in LM Studio → Local Server → Start

### Phase 2 — Client Chatbot (2 days)

- [ ] 2.1 Design `ChatbotClient.fxml` with VBox message list, TextField, Send button
- [ ] 2.2 Implement `ChatbotClientController.java`:
  - Keyword-based context injection
  - Streaming response with `Platform.runLater`
  - Typing indicator (animated "..." label)
  - History trimming to 20 messages
- [ ] 2.3 Apply `chatbot.css` to the scene
- [ ] 2.4 Wire chatbot panel into existing client page layout
  - Add as a floating VBox overlay or as a sidebar tab
- [ ] 2.5 Pass logged-in userId from session/login controller

### Phase 3 — Admin RAG Chatbot (3 days)

- [ ] 3.1 Implement `IntentRouter.java` with keyword classifier
- [ ] 3.2 Implement `RagService.java`:
  - Calls router → calls DB queries → assembles system prompt → calls LM Studio
  - Returns `{answer: String, sourceTables: List<String>}`
- [ ] 3.3 Design `ChatbotAdmin.fxml`:
  - Quick-action button row (HBox with wrap)
  - Chat scroll pane
  - Source label below each bot response
- [ ] 3.4 Implement `ChatbotAdminController.java`:
  - Wire quick-action buttons to pre-written questions
  - Loading spinner during RAG call (run in `Task<String>`)
  - Source table display
- [ ] 3.5 Wire into admin dashboard page

### Phase 4 — Polish & Testing (1–2 days)

- [ ] 4.1 Error handling: LM Studio not running → show "AI service offline" message
- [ ] 4.2 Error handling: DB connection failure → show "Database unavailable"
- [ ] 4.3 Async: All LM Studio calls in `Task<>` / `Service<>` — never block UI thread
- [ ] 4.4 Memory safety: cap conversation history, cap CSV size (max 3000 chars per chunk)
- [ ] 4.5 Language detection: if user writes in French, system prompt appended with "Respond in French."
- [ ] 4.6 Test against live `personne` DB with real data

---

## 12. LM Studio Setup Checklist

Before running the JavaFX app:

1. Open **LM Studio** desktop app
2. Download a model (recommended for 8 GB+ RAM): `mistral-7b-instruct-v0.3-GGUF`
3. Go to **Local Server** tab → click **Start Server**
4. Verify: `curl http://localhost:1234/v1/models` returns model list
5. Note the exact model identifier and set it in `AppConfig.LM_MODEL`
6. Leave LM Studio running in the background while using the JavaFX app

---

## 13. Security & Safety Notes

- The client chatbot is **read-only** — it never writes to the DB
- The admin RAG chatbot is also **read-only** — it only queries, never mutates
- DB credentials are in `AppConfig.java` (hardcoded for now — externalize to a `.properties` file before production)
- CSV data sent to LM Studio stays local (LM Studio runs entirely on-device — no data leaves the machine)
- Trim large text fields (e.g., `description_f`, `observations`) to 200 chars before including in CSV to stay within the context window

---

## 14. Recommended LM Studio Models

| Model | VRAM needed | Good for |
|---|---|---|
| `mistral-7b-instruct-v0.3` Q4_K_M | 5 GB | Good balance of speed and quality |
| `llama-3.1-8b-instruct` Q4_K_M | 6 GB | Better French support |
| `qwen2.5-7b-instruct` Q4_K_M | 5 GB | Excellent at structured data / CSV |
| `phi-3.5-mini-instruct` Q4_K_M | 3 GB | Low RAM machines |

For the admin RAG, prefer `qwen2.5` or `mistral` as they follow structured data instructions well.

---

## 15. Deliverables Summary

| File | Description |
|---|---|
| `AppConfig.java` | All config constants |
| `DatabaseService.java` | JDBC singleton + all query methods |
| `LmStudioService.java` | HTTP client for LM Studio API |
| `IntentRouter.java` | Keyword-based domain classifier (RAG) |
| `RagService.java` | RAG orchestrator (router → DB → LLM) |
| `ChatbotClientController.java` | Client chatbot controller |
| `ChatbotAdminController.java` | Admin RAG controller |
| `ChatbotClient.fxml` | Client chatbot UI |
| `ChatbotAdmin.fxml` | Admin RAG UI |
| `chatbot.css` | ELFIRMA-themed styles |
| `system_client.txt` | Client system prompt |
| `system_admin.txt` | Admin RAG system prompt |
