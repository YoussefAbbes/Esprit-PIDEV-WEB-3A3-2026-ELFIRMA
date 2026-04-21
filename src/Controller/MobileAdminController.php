<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\FirebaseMobileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/mobile')]
final class MobileAdminController extends AbstractController
{
    #[Route('', name: 'admin_mobile_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('admin_mobile_employees');
    }

    #[Route('/employees', name: 'admin_mobile_employees', methods: ['GET', 'POST'])]
    public function employees(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        FirebaseMobileService $firebaseMobileService,
    ): Response {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        $employees = $this->resolveEmployees($utilisateurRepository, $firebaseMobileService);

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            try {
                if ($action === 'sync_all') {
                    $summary = $firebaseMobileService->syncEmployees($employees);

                    $this->addFlash(
                        'success',
                        sprintf(
                            'Employee sync completed. Processed: %d, Created: %d, Updated: %d, Errors: %d.',
                            $summary['processed'],
                            $summary['created'],
                            $summary['updated'],
                            $summary['errors'],
                        ),
                    );
                } elseif ($action === 'enroll_nfc') {
                    $userId = (int) $request->request->get('user_id', 0);
                    $nfcUid = trim((string) $request->request->get('nfc_uid', ''));

                    $employee = $utilisateurRepository->find($userId);

                    if ($employee === null) {
                        throw new \RuntimeException('Employee not found.');
                    }

                    $result = $firebaseMobileService->enrollEmployeeNfc($employee, $nfcUid);

                    $this->addFlash(
                        'success',
                        sprintf(
                            'NFC card saved for %s.',
                            (string) $employee->getPrenomU() . ' ' . (string) $employee->getNomU(),
                        ),
                    );
                }
            } catch (\Throwable $error) {
                $this->addFlash('error', 'Operation failed: ' . $error->getMessage());
            }

            return $this->redirectToRoute('admin_mobile_employees');
        }

        $profilesByMysqlId = [];

        try {
            $profilesByMysqlId = $firebaseMobileService->getEmployeeProfilesByMysqlId();
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Connection error: ' . $error->getMessage());
        }

        return $this->render('admin/mobile/employees.html.twig', [
            'employees' => $employees,
            'profiles_by_mysql_id' => $profilesByMysqlId,
        ]);
    }

    #[Route('/tasks', name: 'admin_mobile_tasks', methods: ['GET'])]
    public function tasks(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        FirebaseMobileService $firebaseMobileService,
    ): Response {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        $tasks = [];

        try {
            $tasks = $firebaseMobileService->listTasks();
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Connection error: ' . $error->getMessage());
        }

        return $this->render('admin/mobile/tasks.html.twig', [
            'employees' => $this->resolveEmployees($utilisateurRepository, $firebaseMobileService),
            'tasks' => $tasks,
        ]);
    }

    #[Route('/tasks/create', name: 'admin_mobile_task_create', methods: ['POST'])]
    public function createTask(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        FirebaseMobileService $firebaseMobileService,
    ): RedirectResponse {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        $assigneeId = (int) $request->request->get('assignee_id', 0);
        $assignee = $utilisateurRepository->find($assigneeId);

        if ($assignee === null) {
            $this->addFlash('error', 'Assignee not found.');

            return $this->redirectToRoute('admin_mobile_tasks');
        }

        $assigneeProfile = $firebaseMobileService->getEmployeeProfilesByMysqlId()[(string) $assigneeId] ?? null;
        $assigneeFirebaseUid = (string) ($assigneeProfile['firebase_uid'] ?? '');

        if ($assigneeFirebaseUid === '') {
            $this->addFlash('error', 'Assignee profile is not ready yet. Please sync team members first.');

            return $this->redirectToRoute('admin_mobile_tasks');
        }

        try {
            $taskId = $firebaseMobileService->createTask([
                'title' => (string) $request->request->get('title', ''),
                'description' => (string) $request->request->get('description', ''),
                'assigned_employee_uid' => $assigneeFirebaseUid,
                'assigned_employee_mysql_id' => $assigneeId,
                'assigned_employee_name' => trim((string) $assignee->getPrenomU() . ' ' . (string) $assignee->getNomU()),
                'status' => (string) $request->request->get('status', 'assigned'),
                'priority' => (string) $request->request->get('priority', 'normal'),
                'due_date' => (string) $request->request->get('due_date', ''),
                'created_by' => (string) $request->getSession()->get('user_name', 'symfony_admin'),
            ]);

            $this->addFlash('success', 'Task created successfully.');
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Unable to create task: ' . $error->getMessage());
        }

        return $this->redirectToRoute('admin_mobile_tasks');
    }

    #[Route('/tasks/{taskId}/status', name: 'admin_mobile_task_status', methods: ['POST'])]
    public function updateTaskStatus(
        Request $request,
        FirebaseMobileService $firebaseMobileService,
        string $taskId,
    ): RedirectResponse {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        try {
            $status = (string) $request->request->get('status', 'assigned');
            $firebaseMobileService->updateTaskStatus($taskId, $status);
            $this->addFlash('success', 'Task status updated.');
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Unable to update task: ' . $error->getMessage());
        }

        return $this->redirectToRoute('admin_mobile_tasks');
    }

    #[Route('/tasks/{taskId}/delete', name: 'admin_mobile_task_delete', methods: ['POST'])]
    public function deleteTask(
        Request $request,
        FirebaseMobileService $firebaseMobileService,
        string $taskId,
    ): RedirectResponse {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        try {
            $firebaseMobileService->deleteTask($taskId);
            $this->addFlash('success', 'Task deleted successfully.');
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Unable to delete task: ' . $error->getMessage());
        }

        return $this->redirectToRoute('admin_mobile_tasks');
    }

    #[Route('/reports', name: 'admin_mobile_reports', methods: ['GET'])]
    public function reports(Request $request, FirebaseMobileService $firebaseMobileService): Response
    {
        if (($guardRedirect = $this->assertAdmin($request)) instanceof RedirectResponse) {
            return $guardRedirect;
        }

        $reports = [];

        try {
            $reports = $firebaseMobileService->listReports();
        } catch (\Throwable $error) {
            $this->addFlash('error', 'Connection error: ' . $error->getMessage());
        }

        return $this->render('admin/mobile/reports.html.twig', [
            'reports' => $reports,
        ]);
    }

    /**
     * @return list<Utilisateur>
     */
    private function resolveEmployees(
        UtilisateurRepository $utilisateurRepository,
        FirebaseMobileService $firebaseMobileService,
    ): array {
        return array_values(array_filter(
            $utilisateurRepository->findAll(),
            static fn (mixed $user): bool => $user instanceof Utilisateur && $firebaseMobileService->isEmployeeCandidate($user),
        ));
    }

    private function assertAdmin(Request $request): ?RedirectResponse
    {
        $session = $request->getSession();
        $userId = (int) $session->get('user_id', 0);

        if ($userId <= 0) {
            $this->addFlash('error', 'Please sign in to access the mobile admin area.');

            return $this->redirectToRoute('app_login');
        }

        $role = strtolower(trim((string) $session->get('user_role', '')));

        if (str_starts_with($role, 'role_')) {
            $role = substr($role, 5);
        }

        if (!in_array($role, ['admin', 'administrateur'], true)) {
            $this->addFlash('error', 'Only admins can access the mobile admin area.');

            return $this->redirectToRoute('app_pages_home');
        }

        return null;
    }
}
