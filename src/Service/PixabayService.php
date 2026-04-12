<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fetches a photo from the Pixabay API and returns its raw binary content.
 *
 * Usage:
 *   $blob = $pixabayService->fetchImageBlob('tomatoes agriculture');
 *
 * Configure the API key via the PIXABAY_API_KEY environment variable.
 */
class PixabayService
{
    private string $apiKey;

    public function __construct(string $pixabayApiKey)
    {
        $this->apiKey = $pixabayApiKey;
    }

    /**
     * Search Pixabay for the given query and return the first result's
     * webformat image as a binary string.  Returns null on any failure
     * (missing key, network error, no results …).
     *
     * @param string $query  Human-readable search terms, e.g. "wheat field agriculture"
     * @param int    $pick   Which result index to use (0 = first, 1 = second, …)
     */
    public function fetchImageBlob(string $query, int $pick = 0): ?string
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $apiUrl = sprintf(
            "https://pixabay.com/api/?key=%s&q=%s&image_type=photo&category=nature&per_page=5&safesearch=true&lang=en",
            urlencode($this->apiKey),
            urlencode($query),
        );

        $ctx = stream_context_create([
            "http" => [
                "timeout" => 8,
                "ignore_errors" => true,
                "user_agent" => "SymfonyAgriApp/1.0",
            ],
            "ssl" => [
                "verify_peer" => true,
                "verify_peer_name" => true,
            ],
        ]);

        $json = @file_get_contents($apiUrl, false, $ctx);
        if ($json === false || $json === "") {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data["hits"])) {
            return null;
        }

        // Allow caller to pick a specific result; fall back to index 0
        $hit = $data["hits"][$pick] ?? $data["hits"][0];
        $imageUrl = $hit["webformatURL"] ?? null;

        if (empty($imageUrl)) {
            return null;
        }

        $blob = @file_get_contents($imageUrl, false, $ctx);

        return $blob !== false && strlen($blob) > 0 ? $blob : null;
    }

    /**
     * Build a sensible Pixabay search query for a farm parcel.
     *
     * @param string $name     Parcel name, e.g. "North Field"
     * @param string $soilType Soil type, e.g. "Clay"
     */
    public function buildParcelleQuery(
        string $name,
        string $soilType = "",
    ): string {
        $parts = ["farm field", "agriculture"];

        if (!empty($soilType)) {
            $parts[] = strtolower($soilType) . " soil";
        }

        // Append meaningful keywords from the parcel name if it doesn't
        // look like a generic ID (e.g. "Field 3")
        $cleanName = preg_replace(
            "/\b(field|zone|sector|region|area)\b/i",
            "",
            $name,
        );
        $cleanName = trim(preg_replace("/\s+/", " ", $cleanName));
        if (strlen($cleanName) > 2) {
            array_unshift($parts, $cleanName);
        }

        return implode(" ", $parts);
    }

    /**
     * Build a sensible Pixabay search query for a crop.
     *
     * @param string $cropName  Crop common name, e.g. "Tomatoes"
     * @param string $variety   Variety name, e.g. "Roma"
     */
    public function buildCultureQuery(
        string $cropName,
        string $variety = "",
    ): string {
        $parts = [trim($cropName)];

        // Only add variety if it's not a generic placeholder
        if (!empty($variety) && strtolower($variety) !== "unknown") {
            $parts[] = trim($variety);
        }

        $parts[] = "crop";
        $parts[] = "agriculture";

        return implode(" ", $parts);
    }
}
