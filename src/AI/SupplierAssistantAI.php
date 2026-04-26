<?php

namespace App\AI;

/**
 * Trains the Naive Bayes classifier with domain-specific examples
 * and exposes helper methods for the voice assistant.
 */
class SupplierAssistantAI
{
    private NaiveBayesClassifier $intentClassifier;
    private NaiveBayesClassifier $confirmClassifier;

    public function __construct()
    {
        $this->intentClassifier  = new NaiveBayesClassifier();
        $this->confirmClassifier = new NaiveBayesClassifier();

        $this->trainIntentClassifier();
        $this->trainConfirmClassifier();
    }

    // ── Intent detection ──────────────────────────────────────────────────

    public function detectIntent(string $input): string
    {
        $result = $this->intentClassifier->predictWithConfidence($input);

        // If confidence is too low, fall back to unknown
        if ($result['probability'] < 0.55) {
            return 'unknown';
        }

        return $result['label'];
    }

    // ── Get spoken response for a given intent ────────────────────────────

    public function getIntentResponse(string $intent): string
    {
        return match ($intent) {
            'greeting'       => 'Hello! How can I help you?',
            'how_are_you'    => "I'm doing well, thanks for asking! How can I help you?",
            'what_can_you_do' => 'I can help you create a new supplier, edit an existing one, or delete a supplier. What would you like to do?',
            'delete_supplier' => 'I can help you delete a supplier. Which supplier would you like to delete? Please say the supplier name.',
            'edit_supplier'   => 'I can help you edit a supplier. Which supplier would you like to edit? Please say the supplier name.',
            default           => 'Hello! How can I help you?',
        };
    }

    // ── Check if intent should trigger an offer to start a task ──────────
    // Returns true for intents where the assistant asks a yes/no question
    // before starting (currently none, but kept for future use)

    public function intentOffersTask(string $intent): bool
    {
        return false;
    }

    // ── Confirmation detection ────────────────────────────────────────────

    public function isConfirmed(string $input): bool
    {
        return $this->confirmClassifier->predict($input) === 'yes';
    }

    public function isCancelled(string $input): bool
    {
        return $this->confirmClassifier->predict($input) === 'no';
    }

    // ── Supplier name extraction ──────────────────────────────────────────

    public function extractSupplierName(string $input): array
    {
        $name = trim($input);

        if (strlen($name) < 2 || strlen($name) > 100) {
            return [
                'valid' => false,
                'error_message' => 'Please provide a supplier name between 2 and 100 characters.',
            ];
        }

        return [
            'valid' => true,
            'value' => ucfirst(strtolower($name)),
        ];
    }

    // ── Field name extraction ─────────────────────────────────────────────

    public function extractFieldName(string $input): array
    {
        $lower = strtolower(trim($input));
        $validFields = ['type', 'description', 'address', 'phone', 'email', 'status'];

        // Map common variations to field names
        $fieldMap = [
            'type' => ['type', 'supplier type'],
            'description' => ['description', 'desc'],
            'address' => ['address', 'adresse', 'location'],
            'phone' => ['phone', 'telephone', 'tel', 'number'],
            'email' => ['email'],
            'status' => ['status', 'statut', 'state'],
        ];

        foreach ($fieldMap as $field => $variations) {
            foreach ($variations as $variation) {
                if (str_contains($lower, $variation)) {
                    return ['valid' => true, 'value' => $field];
                }
            }
        }

        return [
            'valid' => false,
            'error_message' => 'Please say which field to edit: type, description, address, phone, email, or status.',
        ];
    }


    public function extractFieldValue(string $field, string $input): array
    {
        $input = trim($input);

        switch ($field) {
            case 'tel':
                $digits = preg_replace('/\D/', '', $this->wordsToDigits($input));
                if (strlen($digits) !== 8) {
                    return [
                        'valid'         => false,
                        'error_message' => 'The phone number must be exactly 8 digits. '
                            . 'You gave ' . strlen($digits) . ' digits. Please try again.',
                    ];
                }
                return ['valid' => true, 'value' => $digits];

            case 'email':
                preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $input, $matches);
                if (empty($matches)) {
                    return [
                        'valid'         => false,
                        'error_message' => 'I could not find a valid email address. '
                            . 'Please say it clearly, for example: contact at company dot com.',
                    ];
                }
                return ['valid' => true, 'value' => $matches[0]];

            case 'statut':
                $lower = strtolower($input);
                if (str_contains($lower, 'inactive') || str_contains($lower, 'inactif')) {
                    return ['valid' => true, 'value' => 'Inactive'];
                }
                if (str_contains($lower, 'suspend') || str_contains($lower, 'suspendu')) {
                    return ['valid' => true, 'value' => 'Suspended'];
                }
                if (str_contains($lower, 'active') || str_contains($lower, 'actif')) {
                    return ['valid' => true, 'value' => 'Active'];
                }
                return [
                    'valid'         => false,
                    'error_message' => 'Please say active, inactive, or suspended.',
                ];

            case 'adresse':
                if (!preg_match('/[a-zA-Z]/', $input)) {
                    return [
                        'valid'         => false,
                        'error_message' => 'The address must contain at least one letter. Please try again.',
                    ];
                }
                return ['valid' => true, 'value' => ucwords(strtolower($input))];

            case 'type':
                if (strlen($input) < 2 || strlen($input) > 50) {
                    return [
                        'valid'         => false,
                        'error_message' => 'The supplier type must be between 2 and 50 characters.',
                    ];
                }
                return ['valid' => true, 'value' => ucfirst(strtolower($input))];

            case 'description':
                if (strlen($input) < 2 || strlen($input) > 100) {
                    return [
                        'valid'         => false,
                        'error_message' => 'The description must be between 2 and 100 characters.',
                    ];
                }
                return ['valid' => true, 'value' => ucfirst(strtolower($input))];
        }

        return ['valid' => true, 'value' => $input];
    }

    // ── Convert spoken digits to numeric string ───────────────────────────

    private function wordsToDigits(string $input): string
    {
        $map = [
            'zero'  => '0', 'one'   => '1', 'two'   => '2', 'three' => '3',
            'four'  => '4', 'five'  => '5', 'six'   => '6', 'seven' => '7',
            'eight' => '8', 'nine'  => '9',
        ];

        $lower = strtolower($input);

        // Handle "double X" → "XX" and "triple X" → "XXX"
        $lower = preg_replace_callback(
            '/double\s+(\w+)/',
            fn($m) => ($map[$m[1]] ?? $m[1]) . ($map[$m[1]] ?? $m[1]),
            $lower
        );
        $lower = preg_replace_callback(
            '/triple\s+(\w+)/',
            fn($m) => str_repeat($map[$m[1]] ?? $m[1], 3),
            $lower
        );

        foreach ($map as $word => $digit) {
            $lower = str_replace($word, $digit, $lower);
        }

        return $lower;
    }

    // ── Training data ─────────────────────────────────────────────────────

    private function trainIntentClassifier(): void
    {
        // ── create_supplier ───────────────────────────────────────────────
        $createExamples = [
            'create new supplier',
            'add a supplier',
            'add new supplier',
            'I want to add a supplier',
            'create a supplier',
            'register a new supplier',
            'new supplier',
            'add supplier to the system',
            'I need to create a supplier',
            'let me add a new supplier',
            'insert new supplier',
            'create vendor',
            'add vendor',
            'new vendor please',
            'I would like to add a new supplier',
            'can you create a supplier',
            'please add a supplier',
            'make a new supplier',
            'fournisseur nouveau',
            'ajouter fournisseur',
            'créer un fournisseur',
            'supplier creation',
            'register vendor',
            'add a new fournisseur',
            'I want to register a supplier',
            'create supplier record',
        ];
        foreach ($createExamples as $example) {
            $this->intentClassifier->train('create_supplier', $example);
        }

        // ── delete_supplier ───────────────────────────────────────────────
        $deleteExamples = [
            'delete supplier',
            'remove supplier',
            'delete a supplier',
            'remove a supplier',
            'I want to delete a supplier',
            'can you delete a supplier',
            'please delete a supplier',
            'I need to delete a supplier',
            'delete vendor',
            'remove vendor',
            'supprimer fournisseur',
            'supprimer un fournisseur',
            'effacer fournisseur',
            'remove an old supplier',
            'delete the supplier',
        ];
        foreach ($deleteExamples as $example) {
            $this->intentClassifier->train('delete_supplier', $example);
        }

        // ── edit_supplier ─────────────────────────────────────────────────
        $editExamples = [
            'edit supplier',
            'update supplier',
            'modify supplier',
            'edit a supplier',
            'update a supplier',
            'modify a supplier',
            'I want to edit a supplier',
            'can you edit a supplier',
            'please edit a supplier',
            'I need to update a supplier',
            'edit vendor',
            'update vendor',
            'modifier fournisseur',
            'modifier un fournisseur',
            'éditer fournisseur',
            'update the supplier',
            'change supplier details',
        ];
        foreach ($editExamples as $example) {
            $this->intentClassifier->train('edit_supplier', $example);
        }

        // ── what_can_you_do ───────────────────────────────────────────────
        $whatCanYouDoExamples = [
            'what can you do',
            'what can I do',
            'what features do you have',
            'what are your capabilities',
            'tell me what you can do',
            'help me',
            'what options are available',
            'show me what you can do',
            'what are you able to do',
            'what is possible',
            'que peux tu faire',
            'que pouvez vous faire',
            'quelles sont tes capacites',
            'quelles fonctionnalites',
        ];
        foreach ($whatCanYouDoExamples as $example) {
            $this->intentClassifier->train('what_can_you_do', $example);
        }

        // ── greeting ──────────────────────────────────────────────────────
        $greetingExamples = [
            'hello',
            'hi',
            'hey',
            'hi there',
            'hello there',
            'good morning',
            'good afternoon',
            'good evening',
            'greetings',
            'hey there',
            'salut',
            'bonjour',
            'bonsoir',
            'yo',
            'howdy',
            'hey assistant',
            'hello assistant',
            'hi assistant',
        ];
        foreach ($greetingExamples as $example) {
            $this->intentClassifier->train('greeting', $example);
        }

        // ── how_are_you ───────────────────────────────────────────────────
        $howAreYouExamples = [
            'how are you',
            'how are you doing',
            'how do you do',
            'how is it going',
            'how are things',
            'are you okay',
            'you okay',
            'how are you today',
            'what is up',
            'whats up',
            "what's up",
            'comment vas tu',
            'comment allez vous',
            'ca va',
            'ça va',
            'tu vas bien',
            'how have you been',
            'how is everything',
        ];
        foreach ($howAreYouExamples as $example) {
            $this->intentClassifier->train('how_are_you', $example);
        }

        // ── unknown ───────────────────────────────────────────────────────
        $unknownExamples = [
            'tell me a joke',
            'what is the weather',
            'show me the contracts',
            'list all suppliers',
            'never mind',
            'nothing',
            'what time is it',
            'open the map',
            'show statistics',
            'help me with something else',
            'I need something different',
        ];
        foreach ($unknownExamples as $example) {
            $this->intentClassifier->train('unknown', $example);
        }
    }

    private function trainConfirmClassifier(): void
    {
        // ── yes / confirm ─────────────────────────────────────────────────
        $yesExamples = [
            'yes', 'yes please', 'yeah', 'yep', 'correct', 'confirm',
            'save it', 'go ahead', 'that is correct', 'looks good',
            'save', 'ok save', 'perfect save it', 'sure', 'absolutely',
            'affirmative', 'yes save', 'do it', 'proceed',
            'oui', 'oui enregistrer', 'confirmer', 'sauvegarder',
            'of course', 'definitely', 'alright', 'fine', 'let us go',
        ];
        foreach ($yesExamples as $example) {
            $this->confirmClassifier->train('yes', $example);
        }

        // ── no / cancel ───────────────────────────────────────────────────
        $noExamples = [
            'no', 'nope', 'cancel', 'stop', 'do not save', "don't save",
            'abort', 'nevermind', 'never mind', 'start over', 'no thank you',
            'incorrect', 'wrong', 'that is wrong', 'no cancel it',
            'non', 'annuler', 'non merci', 'arreter',
            'not now', 'maybe later', 'no not now', 'skip it',
        ];
        foreach ($noExamples as $example) {
            $this->confirmClassifier->train('no', $example);
        }
    }
}