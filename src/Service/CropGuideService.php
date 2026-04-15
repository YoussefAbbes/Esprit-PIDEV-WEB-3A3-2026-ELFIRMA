<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fetches crop growing-guide data from two external APIs:
 *
 *   1. Trefle API  (https://trefle.io) – botanical / agronomic data
 *      Needs a token via the TREFLE_API_KEY environment variable.
 *
 *   2. Wikipedia REST API (https://en.wikipedia.org/api/rest_v1/) – free-text
 *      description + thumbnail.  No key required.
 *
 * Usage:
 *   $guide = $cropGuideService->buildCropGuide('Tomato', 'Roma');
 */
class CropGuideService
{
    private string $trefleToken;

    public function __construct(?string $trefleApiKey = null)
    {
        // DI binding may give empty string if the env var wasn't resolved
        // (common on Windows with certain PHP/Symfony Runtime setups).
        // Fall back to reading the env var directly from the PHP environment.
        $this->trefleToken =
            (string) ((isset($trefleApiKey) && $trefleApiKey !== ""
                ? $trefleApiKey
                : null) ??
                ((getenv("TREFLE_API_KEY") ?: null) ??
                    ($_ENV["TREFLE_API_KEY"] ??
                        null ??
                        ($_SERVER["TREFLE_API_KEY"] ?? ""))));
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build a comprehensive crop guide by combining Trefle + Wikipedia data.
     *
     * @param string $cropName  e.g. "Tomato"
     * @param string $variety   e.g. "Roma"  (optional)
     *
     * @return array{
     *     cropName: string,
     *     variety: string,
     *     trefle: array<string,mixed>|null,
     *     wikipedia: array<string,mixed>|null
     * }
     */
    public function buildCropGuide(
        string $cropName,
        string $variety = "",
    ): array {
        $guide = [
            "cropName" => $cropName,
            "variety" => $variety,
            "trefle" => null,
            "wikipedia" => null,
        ];

        // ── 1. Trefle ─────────────────────────────────────────────────────────
        if (!empty($this->trefleToken)) {
            // Try "Tomato Roma" first, fall back to just "Tomato"
            $hasVariety =
                !empty($variety) && strtolower($variety) !== "unknown";
            $query = $hasVariety ? trim($cropName . " " . $variety) : $cropName;

            $guide["trefle"] = $this->fetchTrefleGuide($query);

            if ($guide["trefle"] === null && $hasVariety) {
                $guide["trefle"] = $this->fetchTrefleGuide($cropName);
            }
        }

        // ── 2. Wikipedia ──────────────────────────────────────────────────────
        $guide["wikipedia"] = $this->fetchWikipediaSummary($cropName);

        // Retry with "(crop)" disambiguation suffix when nothing came back
        if ($guide["wikipedia"] === null) {
            $guide["wikipedia"] = $this->fetchWikipediaSummary(
                $cropName . " (crop)",
            );
        }

        return $guide;
    }

    // -------------------------------------------------------------------------
    // Trefle helpers
    // -------------------------------------------------------------------------

    /**
     * Search Trefle for $query and return a normalised growing-guide array,
     * or null on any failure.
     */
    public function fetchTrefleGuide(string $query): ?array
    {
        $searchUrl = sprintf(
            "https://trefle.io/api/v1/plants/search?token=%s&q=%s",
            urlencode($this->trefleToken),
            urlencode($query),
        );

        $ctx = $this->buildStreamContext();

        $json = @file_get_contents($searchUrl, false, $ctx);
        if ($json === false || $json === "") {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data["data"])) {
            return null;
        }

        // Take the best match (first result)
        $plant = $data["data"][0];

        // Optionally deep-fetch species detail for growth data
        $growth = $this->fetchTrefleSpeciesGrowth($plant, $ctx);

        return $this->normaliseTrefleResult($plant, $growth);
    }

    /**
     * Given a search-result plant entry, try to fetch full species data so
     * we get the `growth` sub-object.  Returns null if unavailable.
     */
    private function fetchTrefleSpeciesGrowth(array $plant, $ctx): ?array
    {
        // Trefle search results sometimes include a `links.self` pointing to
        // the species endpoint, or we can build the URL from `slug`.
        $selfLink = $plant["links"]["self"] ?? null;
        if (empty($selfLink)) {
            return null;
        }

        // `links.self` may be relative ("/api/v1/species/…") or absolute
        if (!str_starts_with($selfLink, "http")) {
            $selfLink = "https://trefle.io" . $selfLink;
        }

        $detailUrl = $selfLink . "?token=" . urlencode($this->trefleToken);
        $detailJson = @file_get_contents($detailUrl, false, $ctx);

        if ($detailJson === false || $detailJson === "") {
            return null;
        }

        $detail = json_decode($detailJson, true);

        // Species endpoint: data.growth
        if (!empty($detail["data"]["growth"])) {
            return $detail["data"]["growth"];
        }

        // Plant endpoint: data.main_species.growth
        if (!empty($detail["data"]["main_species"]["growth"])) {
            return $detail["data"]["main_species"]["growth"];
        }

        return null;
    }

    /**
     * Map raw Trefle API fields to a clean, frontend-friendly structure.
     */
    private function normaliseTrefleResult(array $plant, ?array $growth): array
    {
        $result = [
            "common_name" => $plant["common_name"] ?? null,
            "scientific_name" => $plant["scientific_name"] ?? null,
            "family" => $plant["family"] ?? null,
            "genus" => $plant["genus"] ?? null,
            "image_url" => $plant["image_url"] ?? null,
            "slug" => $plant["slug"] ?? null,
            "trefle_url" => !empty($plant["slug"])
                ? "https://trefle.io/plants/" . $plant["slug"]
                : null,
            // Growing conditions — all may be null
            "growth" => [
                "light" => $growth["light"] ?? null, // 0-10
                "atmospheric_humidity" =>
                    $growth["atmospheric_humidity"] ?? null, // 0-10
                "soil_humidity" => $growth["soil_humidity"] ?? null, // 0-10
                "soil_nutriments" => $growth["soil_nutriments"] ?? null, // 0-10
                "ph_minimum" => $growth["ph_minimum"] ?? null,
                "ph_maximum" => $growth["ph_maximum"] ?? null,
                "temp_min_c" => $growth["minimum_temperature"]["deg_c"] ?? null,
                "temp_max_c" => $growth["maximum_temperature"]["deg_c"] ?? null,
                "days_to_harvest" => $growth["days_to_harvest"] ?? null,
                "row_spacing_cm" => $growth["row_spacing"]["cm"] ?? null,
                "spread_cm" => $growth["spread"]["cm"] ?? null,
                "description" => $growth["description"] ?? null,
                "sowing" => $growth["sowing"] ?? null,
            ],
        ];

        return $result;
    }

    // -------------------------------------------------------------------------
    // Wikipedia helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the Wikipedia REST API page summary for $title.
     * Returns null if the page is not found or on network error.
     */
    public function fetchWikipediaSummary(string $title): ?array
    {
        // Spaces → underscores (Wikipedia convention)
        $wikiTitle = str_replace(" ", "_", trim($title));
        $url =
            "https://en.wikipedia.org/api/rest_v1/page/summary/" .
            urlencode($wikiTitle);

        $ctx = $this->buildStreamContext(
            "SymfonyAgriApp/1.0 (farm-management-app)",
        );

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false || $json === "") {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        // Wikipedia returns a type field for error responses
        if (
            !empty($data["type"]) &&
            str_contains((string) $data["type"], "not_found")
        ) {
            return null;
        }

        // Normalise to a clean structure
        return [
            "title" => $data["title"] ?? $title,
            "description" => $data["description"] ?? null,
            "extract" => $data["extract"] ?? null,
            "thumbnail" => $data["thumbnail"]["source"] ?? null,
            "wiki_url" => $data["content_urls"]["desktop"]["page"] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function buildStreamContext(
        string $userAgent = "SymfonyAgriApp/1.0",
    ): mixed {
        return stream_context_create([
            "http" => [
                "timeout" => 8,
                "ignore_errors" => true,
                "user_agent" => $userAgent,
                "header" => "Accept: application/json",
            ],
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true,
            ],
        ]);
    }
}
