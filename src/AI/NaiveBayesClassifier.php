<?php

namespace App\AI;

/**
 * Naive Bayes Text Classifier — built from scratch.
 *
 * Based on Bayes' theorem:
 *   P(class | words) ∝ P(class) × ∏ P(word | class)
 *
 * Uses Laplace smoothing to handle unseen words,
 * and log-probabilities to prevent floating point underflow.
 */
class NaiveBayesClassifier
{
    // Number of documents seen per class
    private array $classCounts = [];

    // Word frequencies per class: [class][word] = count
    private array $wordCounts = [];

    // Total unique words across all classes (vocabulary size)
    private array $vocabulary = [];

    // Total number of training documents
    private int $totalDocs = 0;

    // ── Training ──────────────────────────────────────────────────────────

    /**
     * Train the model with one example.
     *
     * @param string $label The class label (e.g. "create_supplier")
     * @param string $text  The training sentence
     */
    public function train(string $label, string $text): void
    {
        $tokens = $this->tokenize($text);

        // Initialise class if first time seeing it
        if (!isset($this->classCounts[$label])) {
            $this->classCounts[$label] = 0;
            $this->wordCounts[$label]  = [];
        }

        $this->classCounts[$label]++;
        $this->totalDocs++;

        foreach ($tokens as $word) {
            // Count word in this class
            $this->wordCounts[$label][$word] =
                ($this->wordCounts[$label][$word] ?? 0) + 1;

            // Add to global vocabulary
            $this->vocabulary[$word] = true;
        }
    }

    // ── Prediction ────────────────────────────────────────────────────────

    /**
     * Predict the most likely class for a given input text.
     */
    public function predict(string $text): string
    {
        $scores = $this->computeScores($text);

        // Return the class with the highest log-probability
        arsort($scores);
        return array_key_first($scores);
    }

    /**
     * Predict with confidence scores (probabilities) for each class.
     *
     * @return array ['label' => string, 'scores' => [...], 'probability' => float]
     */
    public function predictWithConfidence(string $text): array
    {
        $scores = $this->computeScores($text);
        arsort($scores);

        $topLabel = array_key_first($scores);

        // Convert log-scores to actual probabilities via softmax
        $probabilities = $this->softmax($scores);

        return [
            'label'       => $topLabel,
            'scores'      => $scores,
            'probability' => $probabilities[$topLabel],
        ];
    }

    // ── Core math ─────────────────────────────────────────────────────────

    /**
     * Compute log-probability score for each class.
     *
     * Formula (log form of Naive Bayes):
     *   score(class) = log P(class) + Σ log P(word | class)
     *
     * P(class)      = classDocs / totalDocs          (prior)
     * P(word|class) = (wordCount + 1)                (Laplace smoothed)
     *               / (totalWordsInClass + |V|)
     */
    private function computeScores(string $text): array
    {
        $tokens    = $this->tokenize($text);
        $vocabSize = count($this->vocabulary);
        $scores    = [];

        foreach ($this->classCounts as $label => $docCount) {
            // Log prior: log P(class)
            $logPrior = log($docCount / $this->totalDocs);

            // Total words seen in this class
            $totalWordsInClass = array_sum($this->wordCounts[$label]);

            // Sum of log likelihoods for each token
            $logLikelihood = 0.0;

            foreach ($tokens as $word) {
                // Word count in this class (0 if never seen)
                $wordCount = $this->wordCounts[$label][$word] ?? 0;

                // Laplace smoothing: +1 numerator, +|V| denominator
                // Prevents log(0) for unseen words
                $pWordGivenClass = ($wordCount + 1) / ($totalWordsInClass + $vocabSize);

                $logLikelihood += log($pWordGivenClass);
            }

            $scores[$label] = $logPrior + $logLikelihood;
        }

        return $scores;
    }

    /**
     * Softmax: convert raw log-scores to probabilities that sum to 1.
     */
    private function softmax(array $scores): array
    {
        // Shift by max for numerical stability
        $max    = max($scores);
        $expSum = 0.0;
        $exps   = [];

        foreach ($scores as $label => $score) {
            $exps[$label] = exp($score - $max);
            $expSum      += $exps[$label];
        }

        $probabilities = [];
        foreach ($exps as $label => $exp) {
            $probabilities[$label] = $exp / $expSum;
        }

        return $probabilities;
    }

    // ── Tokenizer ─────────────────────────────────────────────────────────

    /**
     * Convert raw text into an array of normalised tokens.
     *
     * Steps:
     *  1. Lowercase
     *  2. Remove punctuation
     *  3. Split on whitespace
     *  4. Remove stop words
     *  5. Basic stemming
     *
     * NOTE: hello, hi, hey, howdy are intentionally NOT in stop words
     * because they are meaningful for the greeting intent classifier.
     */
    private function tokenize(string $text): array
    {
        // 1. Lowercase
        $text = strtolower($text);

        // 2. Remove punctuation
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);

        // 3. Split
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        // 4. Remove stop words
        // Deliberately excluded: hello, hi, hey, how, okay, ok
        // because they carry meaning for greeting/how_are_you intents
        $stopWords = [
            'a', 'an', 'the', 'is', 'it', 'in', 'on', 'at', 'to', 'for',
            'of', 'and', 'or', 'but', 'i', 'me', 'my', 'you', 'we', 'this',
            'that', 'with', 'please', 'can', 'could', 'would', 'like', 'want',
            'need',
        ];

        $words = array_filter($words, fn($w) => !in_array($w, $stopWords));

        // 5. Basic stemming — strip common suffixes
        $words = array_map(fn($w) => $this->stem($w), $words);

        return array_values($words);
    }

    /**
     * Lightweight suffix-stripping stemmer.
     *
     * Examples:
     *   "creating"  → "creat"
     *   "suppliers" → "supplier"
     *   "adding"    → "add"
     */
    private function stem(string $word): string
    {
        $suffixes = ['ing', 'ion', 'ers', 'ed', 'es', 'ly', 's'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) > strlen($suffix) + 2) {
                return substr($word, 0, -strlen($suffix));
            }
        }
        return $word;
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    public function getClasses(): array
    {
        return array_keys($this->classCounts);
    }

    public function getTrainingSize(): int
    {
        return $this->totalDocs;
    }
}