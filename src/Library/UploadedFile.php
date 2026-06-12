<?php

declare(strict_types=1);

namespace Kuyash\Library;

/**
 * Value object for one uploaded file. Built from $_FILES by the controller
 * (which also performs the SAPI-only is_uploaded_file() check); everything
 * below the controller works on plain paths and stays CLI-testable.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $tmpPath,
        public readonly int $sizeBytes,
        public readonly int $errorCode,
    ) {
    }

    /**
     * Non-scalar fields (a multipart field named file[] makes every entry an
     * array) degrade to "no file" instead of crashing to a 500 (audit fix).
     *
     * @param array{name?: mixed, tmp_name?: mixed, size?: mixed, error?: mixed} $file
     */
    public static function fromArray(array $file): self
    {
        $scalar = static fn (mixed $v): bool => is_scalar($v);

        if (!$scalar($file['name'] ?? '') || !$scalar($file['tmp_name'] ?? '')
            || !$scalar($file['size'] ?? 0) || !$scalar($file['error'] ?? 0)) {
            return new self('', '', 0, UPLOAD_ERR_NO_FILE);
        }

        return new self(
            (string) ($file['name'] ?? ''),
            (string) ($file['tmp_name'] ?? ''),
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /** Extension from the ORIGINAL name — used for allowlisting only, never for paths. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }
}
