<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Utilisateur;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Exception\Database\DatabaseNotFound;

final class FirebaseMobileService
{
    private const ROOT_PATH = "mobile";
    private const EMPLOYEES_COLLECTION = "agriculteurs";
    private const NFC_LINKS_COLLECTION = "employee_nfc_links";
    private const TASKS_COLLECTION = "tasks";
    private const REPORTS_COLLECTION = "mobile_reclamations";

    public function __construct(
        private readonly Auth $auth,
        private readonly Database $database,
    ) {
    }

    /**
     * @param list<Utilisateur> $employees
     *
     * @return array{processed:int,created:int,updated:int,errors:int,error_messages:list<string>}
     */
    public function syncEmployees(array $employees): array
    {
        $summary = [
            "processed" => 0,
            "created" => 0,
            "updated" => 0,
            "errors" => 0,
            "error_messages" => [],
        ];

        foreach ($employees as $employee) {
            if (!$employee instanceof Utilisateur || !$this->isEmployee($employee)) {
                continue;
            }

            try {
                $result = $this->upsertEmployee($employee, null);
                ++$summary["processed"];

                if ($result["created"] === true) {
                    ++$summary["created"];
                } else {
                    ++$summary["updated"];
                }
            } catch (\Throwable $error) {
                ++$summary["errors"];
                $summary["error_messages"][] = sprintf(
                    'User #%d (%s %s): %s',
                    (int) $employee->getIdU(),
                    (string) $employee->getPrenomU(),
                    (string) $employee->getNomU(),
                    $error->getMessage(),
                );
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function enrollEmployeeNfc(Utilisateur $employee, string $nfcUid): array
    {
        $normalizedNfcUid = trim($nfcUid);

        if ($normalizedNfcUid === "") {
            throw new \InvalidArgumentException("NFC UID cannot be empty.");
        }

        if (!$this->isEmployee($employee)) {
            throw new \InvalidArgumentException("Selected user is not an employee.");
        }

        return $this->upsertEmployee($employee, $normalizedNfcUid);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listTasks(int $limit = 200): array
    {
        $tasks = $this->collectionDocuments(self::TASKS_COLLECTION);

        usort(
            $tasks,
            fn (array $a, array $b): int => strcmp(
                (string) ($b["updated_at"] ?? $b["created_at"] ?? ""),
                (string) ($a["updated_at"] ?? $a["created_at"] ?? ""),
            ),
        );

        return array_slice($tasks, 0, max(1, $limit));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createTask(array $payload): string
    {
        $title = trim((string) ($payload["title"] ?? ""));
        $description = trim((string) ($payload["description"] ?? ""));
        $assignedEmployeeUid = trim((string) ($payload["assigned_employee_uid"] ?? ""));

        if ($title === "" || $description === "" || $assignedEmployeeUid === "") {
            throw new \InvalidArgumentException("Task title, description, and assigned employee are required.");
        }

        $taskId = "task_" . bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $this->reference(self::TASKS_COLLECTION . "/" . $taskId)
            ->set([
                "title" => $title,
                "description" => $description,
                "assigned_employee_uid" => $assignedEmployeeUid,
                "assigned_employee_mysql_id" => (int) ($payload["assigned_employee_mysql_id"] ?? 0),
                "assigned_employee_name" => (string) ($payload["assigned_employee_name"] ?? ""),
                "status" => (string) ($payload["status"] ?? "assigned"),
                "priority" => (string) ($payload["priority"] ?? "normal"),
                "due_date" => (string) ($payload["due_date"] ?? ""),
                "created_by" => (string) ($payload["created_by"] ?? "symfony_admin"),
                "source" => "symfony_admin",
                "created_at" => $now,
                "updated_at" => $now,
            ]);

        return $taskId;
    }

    public function updateTaskStatus(string $taskId, string $status): void
    {
        $normalizedTaskId = trim($taskId);
        $normalizedStatus = trim($status);

        if ($normalizedTaskId === "" || $normalizedStatus === "") {
            throw new \InvalidArgumentException("Task id and status are required.");
        }

        $this->reference(self::TASKS_COLLECTION . "/" . $normalizedTaskId)
            ->update([
                "status" => $normalizedStatus,
                "updated_at" => (new \DateTimeImmutable())->format(DATE_ATOM),
                "last_updated_by" => "symfony_admin",
            ]);
    }

    public function deleteTask(string $taskId): void
    {
        $normalizedTaskId = trim($taskId);

        if ($normalizedTaskId === "") {
            throw new \InvalidArgumentException("Task id is required.");
        }

        $this->reference(self::TASKS_COLLECTION . "/" . $normalizedTaskId)
            ->remove();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listReports(int $limit = 200): array
    {
        $reports = $this->collectionDocuments(self::REPORTS_COLLECTION);

        usort(
            $reports,
            fn (array $a, array $b): int => strcmp(
                (string) ($b["reported_at"] ?? $b["created_at"] ?? ""),
                (string) ($a["reported_at"] ?? $a["created_at"] ?? ""),
            ),
        );

        return array_slice($reports, 0, max(1, $limit));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getEmployeeProfiles(): array
    {
        return $this->collectionDocuments(self::EMPLOYEES_COLLECTION);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getEmployeeProfilesByMysqlId(): array
    {
        $indexed = [];

        foreach ($this->getEmployeeProfiles() as $profile) {
            $mysqlId = (int) ($profile["mysql_user_id"] ?? 0);

            if ($mysqlId > 0) {
                $indexed[(string) $mysqlId] = $profile;
            }
        }

        return $indexed;
    }

    public function isEmployeeCandidate(Utilisateur $user): bool
    {
        return $this->isEmployee($user);
    }

    /**
     * @return array<string,mixed>
     */
    private function upsertEmployee(Utilisateur $employee, ?string $nfcUid): array
    {
        $mysqlUserId = (int) $employee->getIdU();

        if ($mysqlUserId <= 0) {
            throw new \InvalidArgumentException("Employee id is invalid.");
        }

        $firebaseUid = "emp_{$mysqlUserId}";
        $displayName = trim(sprintf("%s %s", (string) $employee->getPrenomU(), (string) $employee->getNomU()));
        $email = strtolower(trim((string) $employee->getEmailU()));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $authUserCreated = false;
        $authSyncSkipped = false;

        try {
            try {
                $this->auth->getUser($firebaseUid);
            } catch (\Throwable) {
                try {
                    $createPayload = [
                        "uid" => $firebaseUid,
                        "displayName" => $displayName,
                        "disabled" => false,
                    ];

                    if ($email !== "") {
                        $createPayload["email"] = $email;
                    }

                    $this->auth->createUser($createPayload);
                    $authUserCreated = true;
                } catch (\Throwable $createError) {
                    if ($email === "") {
                        throw $createError;
                    }

                    try {
                        $existing = $this->auth->getUserByEmail($email);
                        $firebaseUid = $existing->uid;
                    } catch (\Throwable) {
                        throw $createError;
                    }
                }
            }

            $updatePayload = [
                "displayName" => $displayName,
                "disabled" => false,
            ];

            if ($email !== "") {
                $updatePayload["email"] = $email;
            }

            $this->auth->updateUser($firebaseUid, $updatePayload);
        } catch (\Throwable $authError) {
            if ($this->isUnregisteredCallerIdentityError($authError)) {
                $authSyncSkipped = true;
            } else {
                throw $authError;
            }
        }

        $existingProfile = $this->findEmployeeProfileByMysqlId($mysqlUserId);
        $profileExisted = $existingProfile !== null;
        $resolvedNfcUid = $nfcUid ?? (string) ($existingProfile["nfc_uid"] ?? "");
        $resolvedNfcUid = trim($resolvedNfcUid);

        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $this->reference(self::EMPLOYEES_COLLECTION . "/" . $firebaseUid)
            ->update([
                "firebase_uid" => $firebaseUid,
                "mysql_user_id" => $mysqlUserId,
                "full_name" => $displayName,
                "first_name" => (string) $employee->getPrenomU(),
                "last_name" => (string) $employee->getNomU(),
                "email" => $email,
                "role" => (string) $employee->getRoleU(),
                "nfc_uid" => $resolvedNfcUid,
                "auth_sync" => $authSyncSkipped ? "skipped" : "ok",
                "updated_at" => $now,
            ]);

        if ($resolvedNfcUid !== "") {
            $this->reference(self::NFC_LINKS_COLLECTION . "/" . $this->nfcHash($resolvedNfcUid))
                ->update([
                    "nfc_uid" => $resolvedNfcUid,
                    "nfc_hash" => $this->nfcHash($resolvedNfcUid),
                    "firebase_uid" => $firebaseUid,
                    "mysql_user_id" => $mysqlUserId,
                    "employee_name" => $displayName,
                    "email" => $email,
                    "updated_at" => $now,
                ]);
        }

        return [
            "firebase_uid" => $firebaseUid,
            "mysql_user_id" => $mysqlUserId,
            "created" => !$profileExisted,
            "auth_user_created" => $authUserCreated,
            "auth_sync_skipped" => $authSyncSkipped,
            "nfc_uid" => $resolvedNfcUid,
        ];
    }

    private function isUnregisteredCallerIdentityError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'unregistered callers')
            || str_contains($message, 'without established identity')
            || str_contains($message, 'api consumer identity');
    }

    private function isEmployee(Utilisateur $user): bool
    {
        $normalizedRole = $this->normalizeRole((string) $user->getRoleU());

        return in_array($normalizedRole, ["employee", "employe"], true);
    }

    private function normalizeRole(string $role): string
    {
        $normalizedRole = strtolower(trim($role));

        if (str_starts_with($normalizedRole, 'role_')) {
            $normalizedRole = substr($normalizedRole, 5);
        }

        $normalizedRole = strtr($normalizedRole, [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
        ]);

        return str_replace([' ', '-'], '_', $normalizedRole);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findEmployeeProfileByMysqlId(int $mysqlUserId): ?array
    {
        foreach ($this->collectionDocuments(self::EMPLOYEES_COLLECTION) as $profile) {
            if ((int) ($profile["mysql_user_id"] ?? 0) === $mysqlUserId) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function collectionDocuments(string $collectionName): array
    {
        try {
            $raw = $this->reference($collectionName)->getValue();
        } catch (DatabaseNotFound $error) {
            throw new \RuntimeException(
                'Firebase Realtime Database not found. Set FIREBASE_DATABASE_URI to the exact Database URL from Firebase Console > Realtime Database.',
                previous: $error,
            );
        }

        if (!is_array($raw)) {
            return [];
        }

        $records = [];

        foreach ($raw as $id => $record) {
            if (!is_array($record)) {
                continue;
            }

            $normalized = ["id" => (string) $id];

            foreach ($record as $key => $value) {
                $normalized[(string) $key] = $this->normalizeValue($value);
            }

            $records[] = $normalized;
        }

        return $records;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        return $value;
    }

    private function nfcHash(string $nfcUid): string
    {
        return hash("sha256", strtoupper(trim($nfcUid)));
    }

    private function reference(string $path): \Kreait\Firebase\Database\Reference
    {
        $normalizedPath = trim($path, "/");

        return $this->database->getReference(self::ROOT_PATH . "/" . $normalizedPath);
    }
}
