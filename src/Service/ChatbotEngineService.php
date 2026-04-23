<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChatbotRequest;
use App\Exception\ChatbotEngineException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ChatbotEngineService
{
    private const REQUIRED_RESPONSE_KEYS = [
        'contract_version',
        'query',
        'answer_text',
        'route',
        'sources',
        'confidence_summary',
        'evidence_summary',
        'context_metadata',
        'retrieval_debug',
    ];

    /**
     * @param string $pythonPath Configured executable path or command name.
     * @param string $chatEnginePath Absolute or project-relative path to chat_engine.py.
     */
    public function __construct(
        #[Autowire('%rag.python_path%')] private readonly string $pythonPath,
        #[Autowire('%rag.chat_engine_path%')] private readonly string $chatEnginePath,
        #[Autowire('%rag.default_top_k%')] private readonly int $defaultTopK,
        #[Autowire('%rag.process_timeout%')] private readonly float $processTimeout,
        #[Autowire('%rag.enable_llm%')] private readonly bool $enableLlm,
        #[Autowire('%rag.llm_provider%')] private readonly string $llmProvider,
        #[Autowire('%rag.llm_model%')] private readonly string $llmModel,
        #[Autowire('%rag.llm_timeout_seconds%')] private readonly float $llmTimeoutSeconds,
        #[Autowire('%rag.llm_max_output_tokens%')] private readonly int $llmMaxOutputTokens,
        #[Autowire('%rag.gemini_api_key%')] private readonly string $geminiApiKey,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(ChatbotRequest $request): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit((int) ceil($this->processTimeout) + 20);
        }

        $resolvedPython = $this->resolvePythonPath();
        $resolvedChatEnginePath = $this->resolveChatEnginePath();
        $topK = $request->getTopK() ?? max(1, $this->defaultTopK);

        $command = [
            $resolvedPython,
            $resolvedChatEnginePath,
            '--json',
            '--query',
            $request->getQuery(),
            '--top-k',
            (string) $topK,
        ];

        $minScore = $request->getMinScore();
        if ($minScore !== null) {
            $command[] = '--min-score';
            $command[] = (string) $minScore;
        }

        $routeOverride = $request->getRouteOverride();
        if ($routeOverride !== null) {
            $command[] = '--route';
            $command[] = $routeOverride;
        }

        if ($request->isDisableRouting()) {
            $command[] = '--disable-routing';
        }

        $rerankPoolSize = $request->getRerankPoolSize();
        if ($rerankPoolSize !== null) {
            $command[] = '--rerank-pool-size';
            $command[] = (string) $rerankPoolSize;
        }

        if ($request->isDebug()) {
            $command[] = '--include-context-items';
            $command[] = '--include-prompt-payload';
        }

        if ($this->shouldEnableLlm()) {
            $command[] = '--enable-llm';
        }

        $this->appendFilterArguments($command, $request);

        $process = new Process($command, $this->projectDir, $this->buildPythonProcessEnvironment());
        $process->setTimeout($this->processTimeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new ChatbotEngineException(
                'python_timeout',
                'Python chat engine process timed out.',
                Response::HTTP_GATEWAY_TIMEOUT,
                ['timeout_seconds' => $this->processTimeout],
                $exception
            );
        }

        if (!$process->isSuccessful()) {
            $stderr = $this->trimForError($process->getErrorOutput());
            $stdout = $this->trimForError($process->getOutput());
            $missingPython = $this->isMissingPythonExecutableError($stderr . "\n" . $stdout);

            throw new ChatbotEngineException(
                $missingPython ? 'missing_python_executable' : 'python_execution_error',
                $missingPython
                    ? 'Python executable could not be started.'
                    : 'Python chat engine failed to execute successfully.',
                $missingPython ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_BAD_GATEWAY,
                [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $stderr,
                    'stdout' => $stdout,
                ]
            );
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            throw new ChatbotEngineException(
                'empty_answer_payload',
                'Python chat engine returned an empty payload.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        try {
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ChatbotEngineException(
                'python_output_parse_error',
                'Unable to parse Python chat engine JSON response.',
                Response::HTTP_BAD_GATEWAY,
                ['stdout_excerpt' => $this->trimForError($output)],
                $exception
            );
        }

        if (!\is_array($payload)) {
            throw new ChatbotEngineException(
                'python_output_parse_error',
                'Python chat engine returned an invalid JSON structure.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        $this->assertValidResponseContract($payload);

        return $payload;
    }

    /**
     * @param list<string> $command
     */
    private function appendFilterArguments(array &$command, ChatbotRequest $request): void
    {
        $filterOptionMap = [
            'domain' => '--domain',
            'document_type' => '--document-type',
            'confidence' => '--confidence',
            'language' => '--language',
            'evidence_scope' => '--evidence-scope',
        ];

        foreach ($request->getFilters() as $key => $values) {
            if (!isset($filterOptionMap[$key])) {
                continue;
            }

            foreach ($values as $value) {
                $command[] = $filterOptionMap[$key];
                $command[] = $value;
            }
        }
    }

    private function resolvePythonPath(): string
    {
        $configuredPath = trim($this->pythonPath);
        if ($configuredPath === '') {
            throw new ChatbotEngineException(
                'missing_python_executable',
                'Python executable path is not configured.',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if (!$this->looksLikePath($configuredPath)) {
            return $configuredPath;
        }

        $resolvedPath = $this->toAbsolutePath($configuredPath);
        if (!is_file($resolvedPath)) {
            throw new ChatbotEngineException(
                'missing_python_executable',
                'Configured Python executable was not found.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['python_path' => $resolvedPath]
            );
        }

        return $resolvedPath;
    }

    private function resolveChatEnginePath(): string
    {
        $configuredPath = trim($this->chatEnginePath);
        if ($configuredPath === '') {
            throw new ChatbotEngineException(
                'missing_chat_engine_script',
                'Python chat engine path is not configured.',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $resolvedPath = $this->toAbsolutePath($configuredPath);
        if (!is_file($resolvedPath)) {
            throw new ChatbotEngineException(
                'missing_chat_engine_script',
                'Configured Python chat engine script was not found.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['chat_engine_path' => $resolvedPath]
            );
        }

        return $resolvedPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertValidResponseContract(array $payload): void
    {
        foreach (self::REQUIRED_RESPONSE_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new ChatbotEngineException(
                    'upstream_contract_error',
                    'Python response is missing required contract fields.',
                    Response::HTTP_BAD_GATEWAY,
                    ['missing_field' => $key]
                );
            }
        }

        if (!\is_string($payload['contract_version']) || trim($payload['contract_version']) === '') {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response did not contain a valid contract_version.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (!\is_string($payload['query']) || trim($payload['query']) === '') {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response did not contain a valid query.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (!\is_string($payload['answer_text']) || trim($payload['answer_text']) === '') {
            throw new ChatbotEngineException(
                'empty_answer_payload',
                'Python response did not contain a valid answer_text.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (!\is_string($payload['route']) || trim($payload['route']) === '') {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response did not contain a valid route.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (!\is_array($payload['sources'])) {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response did not contain a valid sources array.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        $this->assertSourceContract($payload['sources']);

        foreach (['confidence_summary', 'evidence_summary', 'context_metadata', 'retrieval_debug'] as $objectField) {
            if (!\is_array($payload[$objectField])) {
                throw new ChatbotEngineException(
                    'upstream_contract_error',
                    sprintf('Python response field %s must be an object.', $objectField),
                    Response::HTTP_BAD_GATEWAY
                );
            }
        }

        if (array_key_exists('llm', $payload) && !\is_array($payload['llm'])) {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response field llm must be an object when present.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (array_key_exists('context_items', $payload) && !\is_array($payload['context_items'])) {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response field context_items must be an array when present.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (array_key_exists('context_block', $payload) && !\is_string($payload['context_block'])) {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response field context_block must be a string when present.',
                Response::HTTP_BAD_GATEWAY
            );
        }

        if (array_key_exists('prompt_payload', $payload) && !\is_array($payload['prompt_payload'])) {
            throw new ChatbotEngineException(
                'upstream_contract_error',
                'Python response field prompt_payload must be an object when present.',
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    /**
     * @param list<mixed> $sources
     */
    private function assertSourceContract(array $sources): void
    {
        $requiredSourceFields = ['source_file', 'section', 'document_type', 'confidence', 'score'];

        foreach ($sources as $index => $source) {
            if (!\is_array($source)) {
                throw new ChatbotEngineException(
                    'upstream_contract_error',
                    'Python response source item must be an object.',
                    Response::HTTP_BAD_GATEWAY,
                    ['source_index' => $index]
                );
            }

            foreach ($requiredSourceFields as $field) {
                if (!array_key_exists($field, $source)) {
                    throw new ChatbotEngineException(
                        'upstream_contract_error',
                        'Python response source item is missing a required field.',
                        Response::HTTP_BAD_GATEWAY,
                        [
                            'source_index' => $index,
                            'missing_field' => $field,
                        ]
                    );
                }
            }

            foreach (['source_file', 'section', 'document_type', 'confidence'] as $stringField) {
                if (!\is_string($source[$stringField]) || trim($source[$stringField]) === '') {
                    throw new ChatbotEngineException(
                        'upstream_contract_error',
                        sprintf('Python response source field %s must be a non-empty string.', $stringField),
                        Response::HTTP_BAD_GATEWAY,
                        ['source_index' => $index]
                    );
                }
            }

            if (!\is_int($source['score']) && !\is_float($source['score'])) {
                throw new ChatbotEngineException(
                    'upstream_contract_error',
                    'Python response source field score must be numeric.',
                    Response::HTTP_BAD_GATEWAY,
                    ['source_index' => $index]
                );
            }
        }
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '\\') || str_ends_with(strtolower($value), '.exe');
    }

    private function toAbsolutePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($this->projectDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\');
    }

    private function trimForError(string $value, int $maxLength = 800): string
    {
        $trimmed = trim($value);
        if (strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLength) . '...';
    }

    private function isMissingPythonExecutableError(string $errorOutput): bool
    {
        $haystack = strtolower($errorOutput);

        $markers = [
            'is not recognized as an internal or external command',
            'the system cannot find the file specified',
            'no such file or directory',
            'command not found',
            'could not be found',
        ];

        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function shouldEnableLlm(): bool
    {
        if (!$this->enableLlm) {
            return false;
        }

        $provider = trim($this->llmProvider);
        if ($provider === '') {
            return false;
        }

        return trim($this->geminiApiKey) !== ''
            || $this->readRuntimeEnv('RAG_GEMINI_API_KEY') !== null;
    }

    private function readRuntimeEnv(string $name): ?string
    {
        $candidates = [
            getenv($name),
            $_ENV[$name] ?? null,
            $_SERVER[$name] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool|string>
     */
    private function buildPythonProcessEnvironment(): array
    {
        $username = trim((string) (getenv('USERNAME') ?: getenv('USER') ?: 'chatbot'));
        if ($username === '') {
            $username = 'chatbot';
        }

        $environment = [
            // Prevent inherited shell/web variables from forcing a broken Python module path.
            'PYTHONHOME' => false,
            'PYTHONPATH' => false,
            'PYTHONNOUSERSITE' => false,
            // Force UTF-8 stdio so JSON payloads remain valid with accented/non-ASCII text.
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
            // Keep getpass.getuser() from falling back to Unix-only pwd module on Windows web runtimes.
            'USERNAME' => $username,
            'USER' => $username,
            'RAG_ENABLE_LLM' => $this->enableLlm ? '1' : '0',
        ];

        $provider = trim($this->llmProvider);
        if ($provider !== '') {
            $environment['RAG_LLM_PROVIDER'] = $provider;
        }

        $model = trim($this->llmModel);
        if ($model !== '') {
            $environment['RAG_LLM_MODEL'] = $model;
        }

        if ($this->llmTimeoutSeconds > 0) {
            $environment['RAG_LLM_TIMEOUT_SECONDS'] = (string) $this->llmTimeoutSeconds;
        }

        if ($this->llmMaxOutputTokens > 0) {
            $environment['RAG_LLM_MAX_OUTPUT_TOKENS'] = (string) $this->llmMaxOutputTokens;
        }

        $apiKey = trim($this->geminiApiKey);
        if ($apiKey !== '') {
            $environment['GEMINI_API_KEY'] = $apiKey;
        }

        foreach ([
            'RAG_GEMINI_API_KEY',
            'GEMINI_MODEL',
        ] as $envName) {
            $value = $this->readRuntimeEnv($envName);
            if ($value !== null) {
                $environment[$envName] = $value;
            }
        }

        return $environment;
    }
}
