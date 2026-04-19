<?php

namespace App\Controller;

use App\AI\SupplierAssistantAI;
use App\Entity\Fournisseur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;

class VoiceAssistantController extends AbstractController
{
    private const FIELDS = [
        'type'        => 'What is the supplier type? For example: equipment, seeds, or fertilizer.',
        'description' => 'Give a brief description of the supplier.',
        'adresse'     => 'What is the supplier address? Include the city name.',
        'tel'         => 'What is the phone number? Exactly 8 digits.',
        'email'       => 'What is the email address?',
        'statut'      => 'What is the status? Say: active, inactive, or suspended.',
    ];

    private SupplierAssistantAI $ai;

    public function __construct()
    {
        $this->ai = new SupplierAssistantAI();
    }

    // ── Reset & greet ─────────────────────────────────────────────────────

    #[Route('/voice-assistant/greet', name: 'voice_assistant_greet', methods: ['POST'])]
    public function greet(SessionInterface $session): JsonResponse
    {
        $session->remove('va_state');
        $session->remove('va_data');
        $session->remove('va_current_field');
        $session->remove('va_supplier_id');
        $session->remove('va_edit_field');

        return $this->json(['reply' => 'Hello! How can I help you?', 'status' => 'idle']);
    }

    // ── Main entry point ──────────────────────────────────────────────────

    #[Route('/voice-assistant/process', name: 'voice_assistant_process', methods: ['POST'])]
    public function process(Request $request, SessionInterface $session, EntityManagerInterface $entityManager): JsonResponse
    {
        $body      = json_decode($request->getContent(), true);
        $userInput = trim($body['text'] ?? '');

        $state        = $session->get('va_state', 'IDLE');
        $data         = $session->get('va_data', []);
        $currentField = $session->get('va_current_field', null);
        $supplierId   = $session->get('va_supplier_id', null);
        $editField    = $session->get('va_edit_field', null);
        $fieldKeys    = array_keys(self::FIELDS);

        // ── IDLE ──────────────────────────────────────────────────────────
        if ($state === 'IDLE') {
            $intent = $this->ai->detectIntent($userInput);

            // Directly start creating a supplier
            if ($intent === 'create_supplier') {
                return $this->startCollection($session, $fieldKeys);
            }

            // Start deleting a supplier
            if ($intent === 'delete_supplier') {
                return $this->startDeleting($session);
            }

            // Start editing a supplier
            if ($intent === 'edit_supplier') {
                return $this->startEditing($session);
            }

            // Intents that first ask "want to add a supplier?" before starting
            if ($this->ai->intentOffersTask($intent)) {
                $session->set('va_state', 'OFFERING');
                return $this->json([
                    'reply'  => $this->ai->getIntentResponse($intent),
                    'status' => 'offering',
                ]);
            }

            // Simple response (greeting, unknown, etc.)
            return $this->json([
                'reply'  => $this->ai->getIntentResponse($intent),
                'status' => 'idle',
            ]);
        }

        // ── OFFERING ──────────────────────────────────────────────────────
        // Assistant asked "Would you like to add a supplier?" — wait for yes/no
        if ($state === 'OFFERING') {
            if ($this->ai->isConfirmed($userInput)) {
                $lastIntent = $session->get('va_last_intent', 'create_supplier');

                if ($lastIntent === 'delete_supplier') {
                    return $this->startDeleting($session);
                }
                if ($lastIntent === 'edit_supplier') {
                    return $this->startEditing($session);
                }
                return $this->startCollection($session, $fieldKeys);
            }

            $this->resetSession($session);
            return $this->json([
                'reply'  => 'No problem! Just say create new supplier, edit supplier, or delete supplier whenever you are ready.',
                'status' => 'idle',
            ]);
        }

        // ── DELETING ──────────────────────────────────────────────────────
        if ($state === 'DELETING') {
            if ($this->ai->isCancelled($userInput)) {
                $this->resetSession($session);
                return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
            }

            $extracted = $this->ai->extractSupplierName($userInput);
            if (!$extracted['valid']) {
                return $this->json([
                    'reply'  => $extracted['error_message'],
                    'status' => 'deleting',
                ]);
            }

            $supplierName = $extracted['value'];
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $supplier = $fournisseurRepo->findOneBy(['type_f' => $supplierName]);

            if (!$supplier) {
                return $this->json([
                    'reply'  => 'I could not find a supplier with the name ' . $supplierName . '. Please try again or say the exact name.',
                    'status' => 'deleting',
                ]);
            }

            // Move to confirmation state
            $session->set('va_state', 'DELETING_CONFIRM');
            $session->set('va_supplier_id', $supplier->getIdF());
            return $this->json([
                'reply'  => 'I found ' . $supplierName . '. Are you sure you want to delete this supplier? Say yes to confirm or no to cancel.',
                'status' => 'deleting_confirm',
            ]);
        }

        // ── DELETING_CONFIRM ──────────────────────────────────────────────
        if ($state === 'DELETING_CONFIRM') {
            if ($this->ai->isConfirmed($userInput)) {
                $supplier = $entityManager->getRepository(Fournisseur::class)->find($supplierId);
                if ($supplier) {
                    $supplierName = $supplier->getTypeF();
                    $entityManager->remove($supplier);
                    $entityManager->flush();
                    $this->resetSession($session);
                    return $this->json([
                        'reply'  => 'Supplier ' . $supplierName . ' has been deleted successfully. How can I help you next?',
                        'status' => 'idle',
                    ]);
                }
            }

            $this->resetSession($session);
            return $this->json([
                'reply'  => 'Deletion cancelled. How can I help you?',
                'status' => 'idle',
            ]);
        }

        // ── EDITING ───────────────────────────────────────────────────────
        if ($state === 'EDITING') {
            if ($this->ai->isCancelled($userInput)) {
                $this->resetSession($session);
                return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
            }

            $extracted = $this->ai->extractSupplierName($userInput);
            if (!$extracted['valid']) {
                return $this->json([
                    'reply'  => $extracted['error_message'],
                    'status' => 'editing',
                ]);
            }

            $supplierName = $extracted['value'];
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $supplier = $fournisseurRepo->findOneBy(['type_f' => $supplierName]);

            if (!$supplier) {
                return $this->json([
                    'reply'  => 'I could not find a supplier with the name ' . $supplierName . '. Please try again or say the exact name.',
                    'status' => 'editing',
                ]);
            }

            // Move to selecting field to edit
            $session->set('va_state', 'EDITING_FIELD');
            $session->set('va_supplier_id', $supplier->getIdF());
            return $this->json([
                'reply'  => 'Great! I found ' . $supplierName . '. Which field would you like to edit? You can say: type, description, address, phone, email, or status.',
                'status' => 'editing_field',
            ]);
        }

        // ── EDITING_FIELD ─────────────────────────────────────────────────
        if ($state === 'EDITING_FIELD') {
            if ($this->ai->isCancelled($userInput)) {
                $this->resetSession($session);
                return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
            }

            $extracted = $this->ai->extractFieldName($userInput);
            if (!$extracted['valid']) {
                return $this->json([
                    'reply'  => $extracted['error_message'],
                    'status' => 'editing_field',
                ]);
            }

            $fieldName = $extracted['value'];
            $session->set('va_state', 'EDITING_VALUE');
            $session->set('va_edit_field', $fieldName);

            $prompt = $this->getEditFieldPrompt($fieldName);
            return $this->json([
                'reply'  => $prompt,
                'status' => 'editing_value',
            ]);
        }

        // ── EDITING_VALUE ─────────────────────────────────────────────────
        if ($state === 'EDITING_VALUE') {
            if ($this->ai->isCancelled($userInput)) {
                $this->resetSession($session);
                return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
            }

            // Extract and validate the new field value
            $extracted = $this->ai->extractFieldValue($editField, $userInput);
            if (!$extracted['valid']) {
                return $this->json([
                    'reply'  => $extracted['error_message'],
                    'status' => 'editing_value',
                ]);
            }

            $newValue = $extracted['value'];
            $supplier = $entityManager->getRepository(Fournisseur::class)->find($supplierId);

            if (!$supplier) {
                $this->resetSession($session);
                return $this->json([
                    'reply'  => 'Supplier not found. Please try again.',
                    'status' => 'idle',
                ]);
            }

            // Update the field
            $this->updateSupplierField($supplier, $editField, $newValue);
            $entityManager->flush();

            $fieldLabel = $this->getFieldLabel($editField);
            $this->resetSession($session);
            return $this->json([
                'reply'  => 'Great! I have updated the ' . $fieldLabel . ' to ' . $newValue . '. How can I help you next?',
                'status' => 'idle',
            ]);
        }

        // ── COLLECTING ────────────────────────────────────────────────────
        if ($state === 'COLLECTING' && $currentField !== null) {

            if ($this->ai->isCancelled($userInput)) {
                $this->resetSession($session);
                return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
            }

            $extracted = $this->ai->extractFieldValue($currentField, $userInput);

            if (!$extracted['valid']) {
                return $this->json([
                    'reply'  => $extracted['error_message'],
                    'status' => 'collecting',
                    'field'  => $currentField,
                ]);
            }

            $data[$currentField] = $extracted['value'];
            $session->set('va_data', $data);

            $currentIndex = array_search($currentField, $fieldKeys);
            $nextIndex    = $currentIndex + 1;

            if ($nextIndex < count($fieldKeys)) {
                $nextField = $fieldKeys[$nextIndex];
                $session->set('va_current_field', $nextField);
                return $this->json([
                    'reply'  => 'Got it. ' . self::FIELDS[$nextField],
                    'status' => 'collecting',
                    'field'  => $nextField,
                ]);
            }

            // All fields collected — move to confirmation
            $session->set('va_state', 'CONFIRMING');
            return $this->json([
                'reply'  => $this->buildSummary($data),
                'status' => 'confirming',
            ]);
        }

        // ── CONFIRMING ────────────────────────────────────────────────────
        if ($state === 'CONFIRMING') {
            if ($this->ai->isConfirmed($userInput)) {
                $saveData = $data;
                $this->resetSession($session);
                return $this->json([
                    'reply'   => 'Saving the supplier now.',
                    'status'  => 'save',
                    'payload' => $saveData,
                ]);
            }

            $this->resetSession($session);
            return $this->json(['reply' => 'Cancelled. How can I help you?', 'status' => 'idle']);
        }

        // Fallback
        $this->resetSession($session);
        return $this->json(['reply' => 'Something went wrong. How can I help you?', 'status' => 'idle']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function startCollection(SessionInterface $session, array $fieldKeys): JsonResponse
    {
        $firstField = $fieldKeys[0];
        $session->set('va_state', 'COLLECTING');
        $session->set('va_data', []);
        $session->set('va_current_field', $firstField);

        return $this->json([
            'reply'  => "Great! Let's create a new supplier. " . self::FIELDS[$firstField],
            'status' => 'collecting',
            'field'  => $firstField,
        ]);
    }

    private function startDeleting(SessionInterface $session): JsonResponse
    {
        $session->set('va_state', 'DELETING');
        $session->set('va_last_intent', 'delete_supplier');
        return $this->json([
            'reply'  => 'I can help you delete a supplier. What is the name of the supplier you want to delete?',
            'status' => 'deleting',
        ]);
    }

    private function startEditing(SessionInterface $session): JsonResponse
    {
        $session->set('va_state', 'EDITING');
        $session->set('va_last_intent', 'edit_supplier');
        return $this->json([
            'reply'  => 'I can help you edit a supplier. Which supplier would you like to edit? Please say the supplier name.',
            'status' => 'editing',
        ]);
    }

    private function getEditFieldPrompt(string $field): string
    {
        return match ($field) {
            'type'        => 'What is the new supplier type? For example: equipment, seeds, or fertilizer.',
            'description' => 'What is the new description for this supplier?',
            'address'     => 'What is the new address? Include the city name.',
            'phone'       => 'What is the new phone number? Exactly 8 digits.',
            'email'       => 'What is the new email address?',
            'status'      => 'What is the new status? Say: active, inactive, or suspended.',
            default       => 'Please provide the new value.',
        };
    }

    private function getFieldLabel(string $field): string
    {
        return match ($field) {
            'type'        => 'supplier type',
            'description' => 'description',
            'address'     => 'address',
            'phone'       => 'phone number',
            'email'       => 'email address',
            'status'      => 'status',
            default       => 'field',
        };
    }

    private function updateSupplierField(Fournisseur $supplier, string $field, string $value): void
    {
        // Map field names to entity methods
        match ($field) {
            'type'        => $supplier->setTypeF($value),
            'description' => $supplier->setDescriptionF($value),
            'address'     => $supplier->setAdresseF($value),
            'phone'       => $supplier->setTelF($value),
            'email'       => $supplier->setEmailF($value),
            'status'      => $supplier->setStatutF($value),
            default       => null,
        };
    }

    private function buildSummary(array $data): string
    {
        return sprintf(
            'Here is the summary. Type: %s. Description: %s. Address: %s. '
            . 'Phone: %s. Email: %s. Status: %s. '
            . 'Should I save this supplier? Say yes to confirm or no to cancel.',
            $data['type']        ?? 'not set',
            $data['description'] ?? 'not set',
            $data['adresse']     ?? 'not set',
            $data['tel']         ?? 'not set',
            $data['email']       ?? 'not set',
            $data['statut']      ?? 'not set'
        );
    }

    private function resetSession(SessionInterface $session): void
    {
        $session->remove('va_state');
        $session->remove('va_data');
        $session->remove('va_current_field');
        $session->remove('va_supplier_id');
        $session->remove('va_edit_field');
        $session->remove('va_last_intent');
    }
}