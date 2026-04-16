<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CultureRepository;
use App\Repository\ParcelleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route("/api/chatbot")]
final class ChatbotController extends AbstractController
{
    /**
     * LM Studio OpenAI-compatible endpoint.
     * /api/v1/chat requires a different request schema — do NOT use it.
     */
    private const LM_BASE = "http://192.168.56.1:1234";
    private const LM_ENDPOINT = "/v1/chat/completions";
    private const LM_MODEL = "nvidia/nemotron-3-nano-4b";

    /**
     * Nemotron 3 Nano 4B has a ~4096-token context window (input + output).
     * A compact system prompt uses ~400-600 input tokens, leaving ~3400 for
     * completion.  We cap at 1024 so the model always has headroom to reason
     * AND write an answer without hitting the window ceiling.
     */
    private const MAX_TOKENS = 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /* ---------------------------------------------------------------
       GET /api/chatbot/debug
       Sends a tiny probe to LM Studio and returns the raw response.
       Open this URL in your browser to inspect the exact JSON shape.
    --------------------------------------------------------------- */
    #[Route("/debug", name: "app_chatbot_debug", methods: ["GET"])]
    public function debug(): JsonResponse
    {
        $url = self::LM_BASE . self::LM_ENDPOINT;

        try {
            $response = $this->httpClient->request("POST", $url, [
                "headers" => [
                    "Content-Type" => "application/json",
                    "Accept" => "application/json",
                ],
                "json" => [
                    "model" => self::LM_MODEL,
                    "messages" => [
                        [
                            "role" => "user",
                            "content" => "Reply with the single word OK.",
                        ],
                    ],
                    "temperature" => 0.0,
                    "max_tokens" => self::MAX_TOKENS,
                    "stream" => false,
                ],
                "timeout" => 60,
            ]);

            $status = $response->getStatusCode();
            try {
                $body = $response->toArray(false);
            } catch (\Throwable $e) {
                $body = [
                    "json_parse_error" => $e->getMessage(),
                    "raw" => $response->getContent(false),
                ];
            }

            return new JsonResponse([
                "endpoint" => $url,
                "model" => self::LM_MODEL,
                "max_tokens" => self::MAX_TOKENS,
                "http_status" => $status,
                "body" => $body,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(
                [
                    "error" => "Cannot reach LM Studio: " . $e->getMessage(),
                    "endpoint" => $url,
                ],
                503,
            );
        }
    }

    /* ---------------------------------------------------------------
       POST /api/chatbot/chat
       Body: { "messages": [{"role":"user","content":"..."}] }
    --------------------------------------------------------------- */
    #[Route("/chat", name: "app_chatbot_chat", methods: ["POST"])]
    public function chat(
        Request $request,
        ParcelleRepository $parcelleRepository,
        CultureRepository $cultureRepository,
    ): JsonResponse {
        /* 1. Parse incoming messages -------------------------------- */
        $body = json_decode($request->getContent(), true) ?? [];
        $userMessages = $body["messages"] ?? [];

        if (empty($userMessages)) {
            return new JsonResponse(["error" => "No messages provided."], 400);
        }

        /* 2. Build compact system prompt with live DB data ---------- */
        $parcelles = $parcelleRepository->findAllWithCultures();
        $cultures = $cultureRepository->findAllWithParcelle();
        $systemPrompt = $this->buildSystemPrompt($parcelles, $cultures);

        $messages = array_merge(
            [["role" => "system", "content" => $systemPrompt]],
            $userMessages,
        );

        /* 3. Call LM Studio ---------------------------------------- */
        try {
            $response = $this->httpClient->request(
                "POST",
                self::LM_BASE . self::LM_ENDPOINT,
                [
                    "headers" => [
                        "Content-Type" => "application/json",
                        "Accept" => "application/json",
                    ],
                    "json" => [
                        "model" => self::LM_MODEL,
                        "messages" => $messages,
                        "temperature" => 0.7,
                        "max_tokens" => self::MAX_TOKENS,
                        "stream" => false,
                    ],
                    "timeout" => 180,
                ],
            );

            $httpStatus = $response->getStatusCode();

            try {
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                return new JsonResponse(
                    [
                        "error" =>
                            "LM Studio returned non-JSON (HTTP {$httpStatus}): " .
                            $e->getMessage(),
                    ],
                    502,
                );
            }
        } catch (\Throwable $e) {
            return new JsonResponse(
                [
                    "error" =>
                        "Cannot reach LM Studio at " .
                        self::LM_BASE .
                        ". Is it running? " .
                        $e->getMessage(),
                ],
                503,
            );
        }

        /* 4. Extract the reply ------------------------------------- */

        /* Guard: choices array must be a non-empty array */
        if (empty($data["choices"]) || !is_array($data["choices"])) {
            return new JsonResponse(
                [
                    "error" =>
                        "LM Studio returned no choices. The context window may be full — try a shorter question.",
                    "http_status" => $httpStatus,
                    "top_keys" => array_keys($data),
                ],
                502,
            );
        }

        $choice = $data["choices"][0] ?? null;
        $message = $choice["message"] ?? null;

        if ($message === null) {
            return new JsonResponse(
                [
                    "error" => "Unexpected response shape from LM Studio.",
                    "choice_0" => $choice,
                ],
                502,
            );
        }

        /* Primary: standard OpenAI content field */
        $content = isset($message["content"])
            ? trim((string) $message["content"])
            : "";

        /* Fallback: Nemotron reasoning models write their answer
         into reasoning_content when content is left empty.      */
        if ($content === "") {
            $content = isset($message["reasoning_content"])
                ? trim((string) $message["reasoning_content"])
                : "";
        }

        if ($content === "") {
            return new JsonResponse(
                [
                    "error" =>
                        "The model returned an empty answer. Try rephrasing your question.",
                    "http_status" => $httpStatus,
                    "finish_reason" => $choice["finish_reason"] ?? null,
                    "message_keys" => array_keys($message),
                ],
                502,
            );
        }

        /* Append truncation notice when the model was cut off */
        if (($choice["finish_reason"] ?? null) === "length") {
            $content .= "\n\n*(response truncated — ask me to continue)*";
        }

        return new JsonResponse(["reply" => $content]);
    }

    /* ---------------------------------------------------------------
       System-prompt builder
       One compact line per record keeps token usage low and leaves
       more room for the model to reason and answer.
    --------------------------------------------------------------- */
    private function buildSystemPrompt(
        array $parcelles,
        array $cultures,
    ): string {
        $now = new \DateTime();
        $dateStr = $now->format("Y-m-d");

        /* ── Parcelles (one line each) ── */
        $parcelLines = "";
        foreach ($parcelles as $p) {
            $parcelLines .= sprintf(
                "P%d:%s|%s|%.1fha|%s|%s\n",
                $p->getId(),
                $p->getNom(),
                $p->getLocalisation(),
                (float) $p->getSuperficie(),
                $p->getTypeSol() ?? "?",
                $p->getStatut() ?? "?",
            );
        }
        if ($parcelLines === "") {
            $parcelLines = "(none)\n";
        }

        /* ── Cultures (one line each) ── */
        $cultureLines = "";
        foreach ($cultures as $c) {
            $overdue =
                $c->getDateRecoltePrevue() !== null &&
                $c->getDateRecoltePrevue() < $now &&
                $c->getStatut() !== "Harvested";

            $cultureLines .= sprintf(
                "C%d:%s(%s)|P:%s|%s%s|plant:%s|harvest:%s|qty:%.0f/%.0f|yield:%.0f%%\n",
                $c->getId(),
                $c->getNomCulture(),
                $c->getVariete() ?? "?",
                $c->getParcelle()?->getNom() ?? "?",
                $c->getStatut() ?? "?",
                $overdue ? "[OVERDUE]" : "",
                $c->getDatePlantation()?->format("Y-m-d") ?? "?",
                $c->getDateRecoltePrevue()?->format("Y-m-d") ?? "?",
                (float) $c->getQuantitePlantee(),
                (float) $c->getQuantiteRecoltee(),
                (float) ($c->getRendement() ?? 0),
            );
        }
        if ($cultureLines === "") {
            $cultureLines = "(none)\n";
        }

        $np = count($parcelles);
        $nc = count($cultures);

        return "You are EL FIRMA Assistant, an agricultural AI. Today: {$dateStr}. " .
            "DB: {$np} parcels, {$nc} crops. " .
            "Keep reasoning to 1-2 sentences, then answer directly.\n" .
            "PARCELS:\n{$parcelLines}" .
            "CROPS:\n{$cultureLines}" .
            "STRICT RULES — follow every rule on every response:\n" .
            "1. ALWAYS respond in English only, regardless of the language the user writes in.\n" .
            "2. NEVER use Spanish, French, Arabic, or any other language.\n" .
            "3. Structure every answer clearly: use a short opening sentence, then bullet points or numbered steps for details.\n" .
            "4. Use complete, grammatically correct English sentences.\n" .
            "5. Be concise — no unnecessary filler phrases.\n" .
            "6. Always cite the parcel or crop name when referencing specific records.\n" .
            "7. NEVER mention any database IDs, record IDs, primary keys, or any numeric identifiers (e.g. P1, C3, ID 5).\n" .
            "8. NEVER reveal or reference any API endpoints, URLs, server addresses, model names, or technical infrastructure.\n" .
            "9. NEVER expose GPS coordinates, raw latitude/longitude values, or any precise location data.\n" .
            "10. NEVER disclose production costs, internal cost figures, or any financial data from the database.\n" .
            "11. NEVER mention that you have access to a database, system prompt, or any backend data source — simply answer as a knowledgeable assistant.\n" .
            "12. If asked about technical internals, APIs, or sensitive data, politely decline and redirect to farm-related questions.\n";
    }
}
