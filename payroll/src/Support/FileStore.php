<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use finfo;
use RuntimeException;

/**
 * Upload storage outside the document root. Paths are generated, never taken
 * from the client, so a crafted filename cannot escape the upload directory.
 */
final class FileStore
{
    private string $base;

    public function __construct(string $root)
    {
        $this->base = rtrim($root, '/\\') . '/storage/uploads';
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,string> $allowed mime => extension
     * @return array{path:string,original_name:string,size:int,mime:string}
     */
    public function store(array $file, string $folder, array $allowed, int $maxBytes): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'Choose a file to upload.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmp === '' || $size <= 0) {
            throw new HttpException(422, 'The uploaded file is empty.');
        }

        if ($size > $maxBytes) {
            throw new HttpException(422, 'The file is larger than ' . round($maxBytes / 1048576) . ' MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

        if (!is_string($mime) || !isset($allowed[$mime])) {
            throw new HttpException(422, 'That file type is not allowed.');
        }

        $folder = trim($folder, '/');
        $relative = ($folder === '' ? '' : $folder . '/')
            . date('Y/m') . '/'
            . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

        $absolute = $this->base . '/' . $relative;
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        if (!move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        $original = basename((string) ($file['name'] ?? 'upload'));

        return [
            'path' => $relative,
            'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $original) ?? 'upload', 0, 255),
            'size' => $size,
            'mime' => $mime,
        ];
    }

    public function absolute(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }

        $base = realpath($this->base);

        if ($base === false) {
            return null;
        }

        $path = realpath($base . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));

        if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    public function delete(?string $relative): void
    {
        $path = $this->absolute($relative);

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
