<?php

declare(strict_types=1);

namespace MemiPwa;

final class Api
{
    public static function handle(callable $callback): never
    {
        try {
            $result = $callback();
            self::json(['ok' => true, 'data' => $result]);
        } catch (HttpError $error) {
            self::json([
                'ok' => false,
                'error' => $error->errorCode(),
                'message' => $error->getMessage(),
            ], $error->httpStatus());
        } catch (\Throwable $error) {
            self::recordFailure($error);
            self::json([
                'ok' => false,
                'error' => 'server_error',
                'message' => 'Une erreur temporaire est survenue. Réessayez dans quelques instants.',
            ], 500);
        }
    }

    private static function recordFailure(\Throwable $error): void
    {
        $line = '[' . gmdate('c') . '] ' . get_debug_type($error) . ': '
            . preg_replace('/[\r\n]+/', ' ', mb_substr($error->getMessage(), 0, 1200)) . PHP_EOL;
        error_log(trim($line));
        if (!defined('MEMI_PWA_ROOT')) {
            return;
        }
        $directory = MEMI_PWA_ROOT . '/.data';
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        if (is_dir($directory)) {
            @file_put_contents($directory . '/error.log', $line, FILE_APPEND | LOCK_EX);
        }
    }

    public static function requireMethod(string $method): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
            throw new HttpError('Méthode non permise.', 405, 'method_not_allowed');
        }
    }

    public static function requireUser(Context $context): int
    {
        if (!$context->isAuthenticated()) {
            throw new HttpError('Connectez-vous pour continuer.', 401, 'authentication_required');
        }

        return $context->userId();
    }

    public static function requireAdministrator(Context $context): int
    {
        $userId = self::requireUser($context);
        if (!$context->isAdministrator()) {
            throw new HttpError('Accès réservé à la gestion du studio.', 403, 'administrator_required');
        }

        return $userId;
    }

    /** @return array<string,mixed> */
    public static function input(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    public static function text(mixed $value, int $maximum = 500): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, $maximum);
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
