<?php

namespace App\AI;

/**
 * Lightweight multinomial Naive Bayes intent model trained from local dataset.
 * No external AI API is used.
 */
final class IntentModel
{
    /** @var array<string,float> */
    private array $priors = [];

    /** @var array<string,array<string,float>> */
    private array $logLikelihoods = [];

    /** @var array<string,int> */
    private array $classTotals = [];

    /** @var array<string,bool> */
    private array $vocabulary = [];

    /** @var list<string> */
    private array $classes = [];

    private function __construct()
    {
    }

    /**
     * @param array<string,list<string>> $trainingSet
     */
    public static function train(array $trainingSet): self
    {
        $model = new self();

        $docCounts = [];
        $tokenCountsByClass = [];
        $globalVocab = [];
        $totalDocs = 0;

        foreach ($trainingSet as $class => $examples) {
            $docCounts[$class] = count($examples);
            $totalDocs += $docCounts[$class];
            $tokenCountsByClass[$class] = [];

            foreach ($examples as $sentence) {
                $tokens = self::tokenize($sentence);
                foreach ($tokens as $token) {
                    $globalVocab[$token] = true;
                    $tokenCountsByClass[$class][$token] = ($tokenCountsByClass[$class][$token] ?? 0) + 1;
                }
            }
        }

        $vocabSize = max(1, count($globalVocab));
        $model->vocabulary = $globalVocab;

        foreach ($docCounts as $class => $count) {
            $model->classes[] = $class;
            $prior = ($count + 1) / ($totalDocs + count($docCounts));
            $model->priors[$class] = log($prior);

            $classTotal = array_sum($tokenCountsByClass[$class]);
            $model->classTotals[$class] = $classTotal;
            $model->logLikelihoods[$class] = [];

            foreach ($globalVocab as $token => $_) {
                $freq = (int) ($tokenCountsByClass[$class][$token] ?? 0);
                $prob = ($freq + 1) / ($classTotal + $vocabSize);
                $model->logLikelihoods[$class][$token] = log($prob);
            }
        }

        return $model;
    }

    /**
     * @return array{intent:string,confidence:float,scores:array<string,float>}
     */
    public function predict(string $text): array
    {
        $tokens = self::tokenize($text);
        $scores = [];

        foreach ($this->classes as $class) {
            $score = $this->priors[$class] ?? -INF;
            $classTotal = max(1, $this->classTotals[$class] ?? 1);
            $unknown = log(1 / ($classTotal + max(1, count($this->vocabulary))));

            foreach ($tokens as $token) {
                $score += $this->logLikelihoods[$class][$token] ?? $unknown;
            }

            $scores[$class] = $score;
        }

        arsort($scores);
        $bestIntent = (string) array_key_first($scores);

        $confidence = 0.0;
        if ($bestIntent !== '') {
            $values = array_values($scores);
            if (count($values) > 1) {
                $gap = $values[0] - $values[1];
                $confidence = 1 / (1 + exp(-$gap));
            } else {
                $confidence = 1.0;
            }
        }

        return [
            'intent' => $bestIntent,
            'confidence' => round($confidence, 4),
            'scores' => $scores,
        ];
    }

    /**
     * @return array{priors:array<string,float>,logLikelihoods:array<string,array<string,float>>,classTotals:array<string,int>,vocabulary:array<string,bool>,classes:list<string>}
     */
    public function toArray(): array
    {
        return [
            'priors' => $this->priors,
            'logLikelihoods' => $this->logLikelihoods,
            'classTotals' => $this->classTotals,
            'vocabulary' => $this->vocabulary,
            'classes' => $this->classes,
        ];
    }

    /**
     * @param array{priors:array<string,float>,logLikelihoods:array<string,array<string,float>>,classTotals:array<string,int>,vocabulary:array<string,bool>,classes:list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        $model = new self();
        $model->priors = $data['priors'];
        $model->logLikelihoods = $data['logLikelihoods'];
        $model->classTotals = $data['classTotals'];
        $model->vocabulary = $data['vocabulary'];
        $model->classes = $data['classes'];

        return $model;
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $value = mb_strtolower(trim($text));
        if ($value === '') {
            return [];
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $tokens = array_filter(explode(' ', trim($value)), static fn (string $t): bool => mb_strlen($t) >= 2);

        return array_values($tokens);
    }
}
