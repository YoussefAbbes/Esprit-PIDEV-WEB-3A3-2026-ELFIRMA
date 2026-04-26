<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\FingerprintClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

// Template size that ZKFPService expects for Base64ToBlob / DBAdd.
// All stored blobs must be exactly this many bytes (zero-padded).

#[Route("/fingerprint")]
final class FingerprintController extends AbstractController
{
    private const TEMPLATE_SIZE = 2048;

    public function __construct(private FingerprintClient $fingerprintClient) {}

    /**
     * POST /fingerprint/status
     * Returns the Java bridge / device status.
     */
    #[Route("/status", name: "app_fingerprint_status", methods: ["POST"])]
    public function status(): JsonResponse
    {
        $result = $this->fingerprintClient->status();
        return new JsonResponse($result);
    }

    /**
     * POST /fingerprint/enroll/start
     * Tells the Java bridge to begin a new enrollment session.
     */
    #[
        Route(
            "/enroll/start",
            name: "app_fingerprint_enroll_start",
            methods: ["POST"],
        ),
    ]
    public function enrollStart(): JsonResponse
    {
        $result = $this->fingerprintClient->enrollStart();
        return new JsonResponse($result);
    }

    /**
     * POST /fingerprint/enroll/capture
     * Captures one fingerprint scan (call 3 times to complete enrollment).
     */
    #[
        Route(
            "/enroll/capture",
            name: "app_fingerprint_enroll_capture",
            methods: ["POST"],
        ),
    ]
    public function enrollCapture(): JsonResponse
    {
        $result = $this->fingerprintClient->enrollCapture();
        return new JsonResponse($result);
    }

    /**
     * POST /fingerprint/enroll/save
     * Body: { "template": "<base64>", "template_length": N, "user_id": N }
     *
     * Decodes the base64 template to binary and persists it on the user entity.
     */
    #[
        Route(
            "/enroll/save",
            name: "app_fingerprint_enroll_save",
            methods: ["POST"],
        ),
    ]
    public function enrollSave(
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid JSON body"],
                400,
            );
        }

        $templateBase64 = $payload["template"] ?? null;
        $templateLength = isset($payload["template_length"])
            ? (int) $payload["template_length"]
            : 0;
        $userId = isset($payload["user_id"]) ? (int) $payload["user_id"] : 0;

        error_log(
            "[FingerprintSave] user_id=" .
                $userId .
                " template_length=" .
                $templateLength .
                " base64_len=" .
                strlen((string) $templateBase64),
        );

        if (empty($templateBase64)) {
            return new JsonResponse(
                ["ok" => false, "error" => "Missing template"],
                400,
            );
        }

        if ($templateLength <= 0) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid template_length"],
                400,
            );
        }

        if ($userId <= 0) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid user_id"],
                400,
            );
        }

        $user = $em->getRepository(Utilisateur::class)->find($userId);
        if (!$user) {
            return new JsonResponse(
                ["ok" => false, "error" => "User not found"],
                404,
            );
        }

        $binary = base64_decode($templateBase64, true);
        if ($binary === false) {
            return new JsonResponse(
                ["ok" => false, "error" => "template is not valid base64"],
                400,
            );
        }

        // Always store exactly TEMPLATE_SIZE bytes (zero-padded) so Base64ToBlob
        // in Java always receives a valid-length buffer and does not return -5.
        $binary = self::padTemplate($binary);

        $user->setFingerprintTemplate($binary);
        $user->setFingerprintLength($templateLength);

        $em->flush();

        error_log(
            "[FingerprintSave] Saved binary length=" .
                strlen($binary) .
                " for user_id=" .
                $userId,
        );

        return new JsonResponse([
            "ok" => true,
            "message" => "Fingerprint saved",
            "binary_length" => strlen($binary),
        ]);
    }

    /**
     * POST /fingerprint/login
     *
     * Loads every user that has a stored fingerprint template, asks the Java
     * bridge to perform 1-to-N identification against a live scan, and — on a
     * match — writes the same session keys used by the regular login flow.
     */
    #[Route("/login", name: "app_fingerprint_login", methods: ["POST"])]
    public function login(
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        // ------------------------------------------------------------------ //
        // 1. Fetch all users that have a stored fingerprint                   //
        // ------------------------------------------------------------------ //
        /** @var Utilisateur[] $users */
        $users = $em
            ->getRepository(Utilisateur::class)
            ->createQueryBuilder("u")
            ->where("u.fingerprintTemplate IS NOT NULL")
            ->andWhere("u.fingerprintLength > 0")
            ->getQuery()
            ->getResult();

        if (empty($users)) {
            return new JsonResponse(
                [
                    "ok" => false,
                    "message" =>
                        "No enrolled fingerprints found in the database",
                ],
                404,
            );
        }

        // ------------------------------------------------------------------ //
        // 2. Build the templates payload                                      //
        // ------------------------------------------------------------------ //
        $templates = [];

        foreach ($users as $u) {
            $raw = $u->getFingerprintTemplate();

            // Doctrine may return a blob as a stream resource on some drivers
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }

            if ($raw === null || $raw === false || $raw === "") {
                continue;
            }

            // Ensure exactly TEMPLATE_SIZE bytes before base64-encoding.
            // Older entries may be shorter; pad so Base64ToBlob does not return -5.
            $raw = self::padTemplate($raw);

            $templates[] = [
                "user_id" => $u->getIdU(),
                "template" => base64_encode($raw),
                "template_length" => $u->getFingerprintLength(),
            ];
        }

        if (empty($templates)) {
            return new JsonResponse(
                [
                    "ok" => false,
                    "message" => "No valid fingerprint templates available",
                ],
                404,
            );
        }

        // ------------------------------------------------------------------ //
        // 3. Ask the Java bridge to identify                                  //
        // ------------------------------------------------------------------ //
        $result = $this->fingerprintClient->identify($templates);

        // Debug: log the full result from the bridge
        error_log("[FingerprintLogin] Templates sent: " . count($templates));
        error_log("[FingerprintLogin] Bridge result: " . json_encode($result));
        foreach ($templates as $t) {
            error_log(
                "[FingerprintLogin] user_id=" .
                    $t["user_id"] .
                    " template_length=" .
                    $t["template_length"] .
                    " base64_len=" .
                    strlen($t["template"]),
            );
        }

        if (!isset($result["matched"]) || $result["matched"] !== true) {
            return new JsonResponse([
                "ok" => false,
                "message" => "Fingerprint not recognized",
                "debug" => $result,
            ]);
        }

        // ------------------------------------------------------------------ //
        // 4. Find the matched user and create the session                     //
        // ------------------------------------------------------------------ //
        $matchedId = isset($result["user_id"]) ? (int) $result["user_id"] : 0;

        if ($matchedId <= 0) {
            return new JsonResponse(
                [
                    "ok" => false,
                    "error" =>
                        "Invalid user_id returned by identification service",
                ],
                500,
            );
        }

        $user = $em->getRepository(Utilisateur::class)->find($matchedId);
        if (!$user) {
            return new JsonResponse(
                [
                    "ok" => false,
                    "error" => "Matched user not found in database",
                ],
                404,
            );
        }

        $session = $request->getSession();
        $session->set("user_id", $user->getIdU());
        $session->set("user_email", $user->getEmailU());
        $session->set("user_role", $user->getRoleU());
        $session->set(
            "user_name",
            $user->getPrenomU() . " " . $user->getNomU(),
        );

        return new JsonResponse([
            "ok" => true,
            "redirect" => $this->generateUrl("app_pages_home"),
        ]);
    }

    // =========================================================================
    // Re-enrollment route — for logged-in users who want to update their
    // fingerprint without creating a new account.
    //
    // Flow:
    //   1. POST /fingerprint/reenroll/start   — start the 3-scan session
    //   2. POST /fingerprint/reenroll/capture — call 3 times to capture scans
    //   3. (on done=true) template is returned by capture; JS calls
    //      POST /fingerprint/reenroll/save with the template to persist it.
    // =========================================================================

    #[
        Route(
            "/reenroll/start",
            name: "app_fingerprint_reenroll_start",
            methods: ["POST"],
        ),
    ]
    public function reenrollStart(): JsonResponse
    {
        $result = $this->fingerprintClient->enrollStart();
        return new JsonResponse($result);
    }

    #[
        Route(
            "/reenroll/capture",
            name: "app_fingerprint_reenroll_capture",
            methods: ["POST"],
        ),
    ]
    public function reenrollCapture(): JsonResponse
    {
        $result = $this->fingerprintClient->enrollCapture();
        return new JsonResponse($result);
    }

    /**
     * POST /fingerprint/reenroll/save
     * Body: { "template": "<base64>", "template_length": N }
     *
     * Uses the currently logged-in user's session ID.
     * No user_id needed in the body — taken from the session.
     */
    #[
        Route(
            "/reenroll/save",
            name: "app_fingerprint_reenroll_save",
            methods: ["POST"],
        ),
    ]
    public function reenrollSave(
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $userId = $request->getSession()->get("user_id");

        if (!$userId) {
            return new JsonResponse(
                [
                    "ok" => false,
                    "error" => "You must be logged in to re-enroll",
                ],
                401,
            );
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid JSON body"],
                400,
            );
        }

        $templateBase64 = $payload["template"] ?? null;
        $templateLength = isset($payload["template_length"])
            ? (int) $payload["template_length"]
            : 0;

        if (empty($templateBase64)) {
            return new JsonResponse(
                ["ok" => false, "error" => "Missing template"],
                400,
            );
        }

        if ($templateLength <= 0) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid template_length"],
                400,
            );
        }

        $user = $em->getRepository(Utilisateur::class)->find((int) $userId);
        if (!$user) {
            return new JsonResponse(
                ["ok" => false, "error" => "User not found"],
                404,
            );
        }

        $binary = base64_decode($templateBase64, true);
        if ($binary === false) {
            return new JsonResponse(
                ["ok" => false, "error" => "Template is not valid base64"],
                400,
            );
        }

        $binary = self::padTemplate($binary);

        $user->setFingerprintTemplate($binary);
        $user->setFingerprintLength($templateLength);
        $em->flush();

        error_log(
            "[FingerprintReenroll] Saved for user_id=" .
                $userId .
                " template_length=" .
                $templateLength .
                " binary_len=" .
                strlen($binary),
        );

        return new JsonResponse([
            "ok" => true,
            "message" => "Fingerprint updated successfully",
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Ensures binary template is exactly TEMPLATE_SIZE bytes (zero-padded or
     * truncated).  The ZKFinger SDK reads the template's internal header to
     * find the valid byte count, so zero padding is harmless.
     */
    private static function padTemplate(string $binary): string
    {
        $len = strlen($binary);
        if ($len < self::TEMPLATE_SIZE) {
            return str_pad($binary, self::TEMPLATE_SIZE, "\x00", STR_PAD_RIGHT);
        }
        if ($len > self::TEMPLATE_SIZE) {
            return substr($binary, 0, self::TEMPLATE_SIZE);
        }
        return $binary;
    }
}
