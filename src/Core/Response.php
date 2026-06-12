<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Minimal HTTP response value object. Controllers return one of these;
 * only the front controller calls send().
 *
 * Bodies are strings EXCEPT file responses: Response::file() streams from
 * disk in 512KB chunks — loading a 150MB video into a string would blow
 * memory_limit.
 */
final class Response
{
    private const CHUNK_BYTES = 524288;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
        private readonly ?string $filePath = null,
        private readonly int $fileOffset = 0,
        private readonly ?int $fileLength = null,
    ) {
    }

    /**
     * Stream (part of) a file. The caller owns ALL headers (Content-Type,
     * Content-Length, Content-Range, …) — this only moves bytes.
     *
     * @param array<string, string> $headers
     */
    public static function file(
        string $path,
        int $status = 200,
        array $headers = [],
        int $offset = 0,
        ?int $length = null,
    ): self {
        return new self('', $status, $headers, $path, $offset, $length);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \JsonException encoding failures surface at the central handler
     *                        instead of being masked by a silent fallback
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * Redirect via a single Location header (302 for GET guards,
     * pass 303 after successful POSTs). Cookies must NOT go through this
     * header map — use PHP's native session/cookie APIs.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        // the SAPI discards HEAD bodies anyway — don't read a 200MB file for it
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'HEAD') {
            return;
        }

        if ($this->filePath === null) {
            echo $this->body;

            return;
        }

        $this->streamFile();
    }

    private function streamFile(): void
    {
        $handle = @fopen((string) $this->filePath, 'rb');
        if ($handle === false) {
            // headers are already out — nothing safer to do than log and stop
            error_log('Kuyash: media stream failed to open ' . $this->filePath);

            return;
        }

        if ($this->fileOffset > 0) {
            fseek($handle, $this->fileOffset);
        }

        $remaining = $this->fileLength;
        while (!feof($handle)) {
            $want = $remaining === null ? self::CHUNK_BYTES : min(self::CHUNK_BYTES, $remaining);
            if ($want <= 0) {
                break;
            }
            $chunk = fread($handle, $want);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
        }

        fclose($handle);
    }
}
