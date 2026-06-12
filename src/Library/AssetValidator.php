<?php

declare(strict_types=1);

namespace Kuyash\Library;

/**
 * Layered upload validation (security rule: strict allowlist validation).
 * Order: PHP error code → size caps → extension allowlist → finfo content
 * sniff → extension↔MIME consistency → kind-specific checks.
 * Every rejection throws InvalidUploadException with a distinct message key;
 * nothing is written to disk or DB on rejection (caller guarantee).
 */
final class AssetValidator
{
    /**
     * @param array<string, array{0: string, 1: string}> $allowed ext => [mime, kind]
     */
    public function __construct(
        private readonly array $allowed,
        private readonly int $maxVideoBytes,
        private readonly int $maxPhotoBytes,
    ) {
    }

    /**
     * @return array{kind: string, mime: string, ext: string}
     *
     * @throws InvalidUploadException
     */
    public function validate(UploadedFile $file): array
    {
        if ($file->errorCode !== UPLOAD_ERR_OK) {
            throw new InvalidUploadException(match ($file->errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'upload.too_large',
                UPLOAD_ERR_NO_FILE => 'upload.no_file',
                default => 'upload.failed',
            });
        }

        if ($file->sizeBytes <= 0 || !is_file($file->tmpPath)) {
            throw new InvalidUploadException('upload.empty');
        }

        $ext = $file->extension();
        if (!isset($this->allowed[$ext])) {
            throw new InvalidUploadException('upload.extension_not_allowed');
        }

        [$expectedMime, $kind] = $this->allowed[$ext];

        $cap = $kind === 'video' ? $this->maxVideoBytes : $this->maxPhotoBytes;
        if ($file->sizeBytes > $cap) {
            throw new InvalidUploadException(
                $kind === 'video' ? 'upload.video_too_large' : 'upload.photo_too_large'
            );
        }

        // content sniff — the extension says nothing about the actual bytes
        $sniffed = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file->tmpPath);
        if ($sniffed === false || $sniffed !== $expectedMime) {
            throw new InvalidUploadException('upload.content_mismatch');
        }

        if ($kind === 'photo' && @getimagesize($file->tmpPath) === false) {
            throw new InvalidUploadException('upload.broken_image');
        }

        return ['kind' => $kind, 'mime' => $expectedMime, 'ext' => $ext];
    }
}
