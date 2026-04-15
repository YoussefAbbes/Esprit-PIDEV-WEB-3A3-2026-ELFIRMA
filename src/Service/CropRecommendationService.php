<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class CropRecommendationService
{
    private const FEATURE_NAMES = [
        "N",
        "P",
        "K",
        "temperature",
        "humidity",
        "ph",
        "rainfall",
    ];

    private const MODEL_PATH = "ml/crop_recommendation/best_model.joblib";
    private const METADATA_PATH = "ml/crop_recommendation/model_metadata.json";
    private const INFER_SCRIPT_PATH = "scripts/ml/crop_recommendation_infer.py";

    private array $metadataCache = [];

    public function __construct(
        #[Autowire("%kernel.project_dir%")]
        private readonly string $projectDir,
        #[Autowire("%env(default::ML_PYTHON_BIN)%")]
        private readonly ?string $mlPythonBin = null,
    ) {
    }

    /**
     * @param array<string,mixed> $rawFeatures
     * @return array<string,mixed>
     */
    public function recommend(array $rawFeatures): array
    {
        $features = $this->normalizeFeatures($rawFeatures);
        $metadata = $this->loadMetadata();

        try {
            $response = $this->recommendUsingPython($features);
            $response["inference_mode"] = "python";

            return $response;
        } catch (\Throwable $exception) {
            $fallback = $this->recommendFromProfiles($features, $metadata);
            $fallback["inference_mode"] = "fallback";
            $fallback["fallback_reason"] = $exception->getMessage();

            return $fallback;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getModelSummary(): array
    {
        $metadata = $this->loadMetadata();

        return [
            "selected_model" => $metadata["selected_model"] ?? null,
            "test_metrics" => $metadata["test_metrics"] ?? null,
            "feature_importance" => array_slice(
                is_array($metadata["feature_importance"] ?? null)
                    ? $metadata["feature_importance"]
                    : [],
                0,
                5,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $rawFeatures
     * @return array<string,float>
     */
    private function normalizeFeatures(array $rawFeatures): array
    {
        $normalized = [];

        foreach (self::FEATURE_NAMES as $feature) {
            if (!array_key_exists($feature, $rawFeatures)) {
                throw new \InvalidArgumentException(
                    sprintf('Missing required feature "%s".', $feature),
                );
            }

            if (!is_numeric($rawFeatures[$feature])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Feature "%s" must be numeric. Received "%s".',
                        $feature,
                        get_debug_type($rawFeatures[$feature]),
                    ),
                );
            }

            $normalized[$feature] = (float) $rawFeatures[$feature];
        }

        return $normalized;
    }

    /**
     * @param array<string,float> $features
     * @return array<string,mixed>
     */
    private function recommendUsingPython(array $features): array
    {
        $scriptPath = $this->absolutePath(self::INFER_SCRIPT_PATH);
        $modelPath = $this->absolutePath(self::MODEL_PATH);
        $metadataPath = $this->absolutePath(self::METADATA_PATH);

        if (!is_file($scriptPath)) {
            throw new \RuntimeException("Inference script not found.");
        }
        if (!is_file($modelPath)) {
            throw new \RuntimeException("Serialized ML model not found.");
        }
        if (!is_file($metadataPath)) {
            throw new \RuntimeException("Model metadata file not found.");
        }

        $inputJson = json_encode($features, JSON_THROW_ON_ERROR);
        $errors = [];

        foreach ($this->buildPythonCommands() as $prefix) {
            $command = [
                ...$prefix,
                $scriptPath,
                "--model",
                $modelPath,
                "--metadata",
                $metadataPath,
                "--input-json",
                $inputJson,
            ];

            $process = new Process($command, $this->projectDir, null, null, 20);
            $process->run();

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                if ($output === "") {
                    throw new \RuntimeException("Inference script returned empty output.");
                }

                /** @var mixed $decoded */
                $decoded = json_decode($output, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException(
                        "Inference script returned invalid JSON payload.",
                    );
                }

                return $decoded;
            }

            $errors[] = sprintf(
                "%s: %s",
                implode(" ", $prefix),
                trim($process->getErrorOutput()) !== ""
                    ? trim($process->getErrorOutput())
                    : trim($process->getOutput()),
            );
        }

        throw new \RuntimeException(
            "Unable to execute ML inference script. " . implode(" | ", $errors),
        );
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function buildPythonCommands(): array
    {
        $commands = [];

        $custom = trim((string) ($this->mlPythonBin ?? ""));
        if ($custom !== "") {
            $commands[] = preg_split('/\s+/', $custom) ?: [$custom];
        }

        $commands[] = ["python3"];
        $commands[] = ["python"];
        $commands[] = ["py", "-3"];

        return $commands;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMetadata(): array
    {
        if ($this->metadataCache !== []) {
            return $this->metadataCache;
        }

        $metadataPath = $this->absolutePath(self::METADATA_PATH);
        if (!is_file($metadataPath)) {
            throw new \RuntimeException(
                sprintf("Model metadata file not found at %s", $metadataPath),
            );
        }

        $json = file_get_contents($metadataPath);
        if ($json === false || trim($json) === "") {
            throw new \RuntimeException("Model metadata file is empty.");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Model metadata file contains invalid JSON.");
        }

        $this->metadataCache = $decoded;

        return $this->metadataCache;
    }

    private function absolutePath(string $relative): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace("/", DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @param array<string,float> $features
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function recommendFromProfiles(array $features, array $metadata): array
    {
        $classProfiles = is_array($metadata["class_profiles"] ?? null)
            ? $metadata["class_profiles"]
            : [];
        if ($classProfiles === []) {
            throw new \RuntimeException(
                "Cannot run fallback inference: no class profiles in metadata.",
            );
        }

        $importanceMap = [];
        $importanceRows = is_array($metadata["feature_importance"] ?? null)
            ? $metadata["feature_importance"]
            : [];
        foreach ($importanceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $feature = isset($row["feature"]) ? (string) $row["feature"] : "";
            $importance = isset($row["importance"])
                ? (float) $row["importance"]
                : 0.0;
            if ($feature !== "") {
                $importanceMap[$feature] = $importance;
            }
        }

        $globalStats = is_array($metadata["global_feature_stats"] ?? null)
            ? $metadata["global_feature_stats"]
            : [];
        $globalStds = is_array($globalStats["stds"] ?? null)
            ? $globalStats["stds"]
            : [];

        $scores = [];
        foreach ($classProfiles as $crop => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $means = is_array($profile["means"] ?? null) ? $profile["means"] : [];
            $stds = is_array($profile["stds"] ?? null) ? $profile["stds"] : [];
            $distance = 0.0;

            foreach (self::FEATURE_NAMES as $feature) {
                $value = $features[$feature];
                $mean = isset($means[$feature]) ? (float) $means[$feature] : $value;
                $std = isset($stds[$feature]) ? (float) $stds[$feature] : 0.0;
                $globalStd = isset($globalStds[$feature])
                    ? (float) $globalStds[$feature]
                    : 1.0;

                $scale = max($std, $globalStd * 0.5, 1e-6);
                $zDistance = abs($value - $mean) / $scale;
                $weight = $importanceMap[$feature] ?? (1.0 / count(self::FEATURE_NAMES));
                $distance += $weight * $zDistance;
            }

            $scores[(string) $crop] = 1.0 / max($distance, 1e-6);
        }

        if ($scores === []) {
            throw new \RuntimeException("Fallback scoring failed to produce results.");
        }

        $sumScores = array_sum($scores);
        arsort($scores);

        $topPredictions = [];
        foreach (array_slice($scores, 0, 3, true) as $crop => $score) {
            $topPredictions[] = [
                "crop" => $crop,
                "probability" => round($score / max($sumScores, 1e-9), 6),
            ];
        }

        $recommendedCrop = (string) $topPredictions[0]["crop"];
        $confidence = (float) $topPredictions[0]["probability"];

        $explanation = $this->buildFallbackExplanation(
            $features,
            $recommendedCrop,
            $classProfiles,
            $importanceMap,
            $globalStds,
        );

        return [
            "recommended_crop" => $recommendedCrop,
            "confidence" => $confidence,
            "top_predictions" => $topPredictions,
            "explanation" => $explanation,
            "feature_importance" => $importanceRows,
            "agronomic_advice" => $this->buildFallbackAdvice(
                $features,
                $recommendedCrop,
                $classProfiles,
            ),
            "model" => [
                "selected_name" => $metadata["selected_model"]["name"] ?? null,
                "selected_class" => $metadata["selected_model"]["model_class"] ?? null,
                "test_accuracy" => $metadata["test_metrics"]["accuracy"] ?? null,
                "test_macro_f1" => $metadata["test_metrics"]["macro_f1"] ?? null,
            ],
            "input" => $features,
        ];
    }

    /**
     * @param array<string,float> $features
     * @param array<string,mixed> $classProfiles
     * @param array<string,float> $importanceMap
     * @param array<string,mixed> $globalStds
     * @return array<string,mixed>
     */
    private function buildFallbackExplanation(
        array $features,
        string $recommendedCrop,
        array $classProfiles,
        array $importanceMap,
        array $globalStds,
    ): array {
        $profile = is_array($classProfiles[$recommendedCrop] ?? null)
            ? $classProfiles[$recommendedCrop]
            : [];
        $means = is_array($profile["means"] ?? null) ? $profile["means"] : [];
        $stds = is_array($profile["stds"] ?? null) ? $profile["stds"] : [];

        $rows = [];
        foreach (self::FEATURE_NAMES as $feature) {
            $value = $features[$feature];
            $mean = isset($means[$feature]) ? (float) $means[$feature] : $value;
            $std = isset($stds[$feature]) ? (float) $stds[$feature] : 0.0;
            $globalStd = isset($globalStds[$feature])
                ? (float) $globalStds[$feature]
                : 1.0;
            $scale = max($std, $globalStd * 0.5, 1e-6);

            $zDistance = abs($value - $mean) / $scale;
            $alignment = max(0.0, 1.0 - min($zDistance, 3.0) / 3.0);
            $weight = $importanceMap[$feature] ?? (1.0 / count(self::FEATURE_NAMES));
            $weightedAlignment = $alignment * $weight;

            $rows[] = [
                "feature" => $feature,
                "value" => round($value, 6),
                "class_mean" => round($mean, 6),
                "alignment" => round($alignment, 6),
                "z_distance" => round($zDistance, 6),
                "weighted_alignment" => round($weightedAlignment, 6),
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int =>
                $right["weighted_alignment"] <=> $left["weighted_alignment"],
        );
        $supporting = array_slice($rows, 0, 3);

        $limitingRows = $rows;
        usort(
            $limitingRows,
            static fn(array $left, array $right): int =>
                $right["z_distance"] <=> $left["z_distance"],
        );
        $limitingRows = array_slice($limitingRows, 0, 2);

        $supportingFactors = array_map(
            static fn(array $row): string => sprintf(
                "%s=%.2f is close to typical %s conditions (mean %.2f).",
                $row["feature"],
                $row["value"],
                $recommendedCrop,
                $row["class_mean"],
            ),
            $supporting,
        );

        $limitingFactors = [];
        foreach ($limitingRows as $row) {
            if ((float) $row["z_distance"] < 1.0) {
                continue;
            }
            $limitingFactors[] = sprintf(
                "%s=%.2f differs from the usual %s profile (mean %.2f); monitoring is advised.",
                $row["feature"],
                $row["value"],
                $recommendedCrop,
                $row["class_mean"],
            );
        }

        return [
            "summary" => sprintf(
                "%s is recommended because the parcel conditions align with learned profiles, especially on %s, %s, and %s.",
                $recommendedCrop,
                $supporting[0]["feature"] ?? "N",
                $supporting[1]["feature"] ?? "P",
                $supporting[2]["feature"] ?? "K",
            ),
            "supporting_factors" => $supportingFactors,
            "limiting_factors" => $limitingFactors,
            "feature_alignment" => $rows,
        ];
    }

    /**
     * @param array<string,float> $features
     * @param array<string,mixed> $classProfiles
     * @return array<int,string>
     */
    private function buildFallbackAdvice(
        array $features,
        string $recommendedCrop,
        array $classProfiles,
    ): array {
        $profile = is_array($classProfiles[$recommendedCrop] ?? null)
            ? $classProfiles[$recommendedCrop]
            : [];
        $means = is_array($profile["means"] ?? null) ? $profile["means"] : [];

        $advice = [];

        $rainfallTarget = isset($means["rainfall"])
            ? (float) $means["rainfall"]
            : $features["rainfall"];
        if ($features["rainfall"] < $rainfallTarget * 0.8) {
            $advice[] =
                "Rainfall is below the typical level for this crop; consider an irrigation plan.";
        } elseif ($features["rainfall"] > $rainfallTarget * 1.25) {
            $advice[] =
                "Rainfall is above the typical level; monitor drainage and root oxygenation.";
        }

        $phTarget = isset($means["ph"]) ? (float) $means["ph"] : $features["ph"];
        if (abs($features["ph"] - $phTarget) > 0.7) {
            $advice[] =
                "Soil pH is outside the usual range for this crop profile; consider a pH correction strategy.";
        }

        foreach (["N", "P", "K"] as $nutrient) {
            $target = isset($means[$nutrient])
                ? (float) $means[$nutrient]
                : $features[$nutrient];
            if ($features[$nutrient] < $target * 0.8) {
                $advice[] = sprintf(
                    "%s is lower than the typical demand; adjust fertilization before planting.",
                    $nutrient,
                );
            }
        }

        if ($advice === []) {
            $advice[] =
                "Current agronomic conditions are close to the target crop profile; maintain standard monitoring.";
        }

        return array_slice($advice, 0, 4);
    }
}
