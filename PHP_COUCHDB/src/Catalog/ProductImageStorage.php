<?php

declare(strict_types=1);

namespace App\Catalog;

use RuntimeException;

final class ProductImageStorage
{
    private const MAX_BYTES = 5_242_880;
    private const MAX_DIMENSION = 10_000;
    private const MAX_PIXELS = 40_000_000;
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed>|null $upload @return array{filename:string,path:string}|null */
    public function store(?array $upload): ?array
    {
        if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ảnh vượt quá giới hạn tải lên.',
                UPLOAD_ERR_PARTIAL => 'Ảnh tải lên chưa hoàn tất. Hãy thử lại.',
                default => 'Không thể nhận ảnh tải lên. Hãy chọn lại tệp.',
            };
            throw new RuntimeException($message);
        }
        $temporaryPath = $upload['tmp_name'] ?? null;
        if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('Tệp ảnh tải lên không hợp lệ.');
        }
        $size = filesize($temporaryPath);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Ảnh phải có dung lượng từ 1 byte đến 5 MB.');

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) throw new RuntimeException('Chỉ nhận ảnh JPEG, PNG hoặc WebP.');
        $dimensions = @getimagesize($temporaryPath);
        if (!is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime) throw new RuntimeException('Nội dung tệp không phải ảnh hợp lệ.');
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('Kích thước ảnh vượt giới hạn 10.000 × 10.000 pixel và 40 megapixel.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Không thể tạo vùng lưu ảnh.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $target = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($temporaryPath, $target)) throw new RuntimeException('Không thể lưu ảnh tải lên.');
        @chmod($target, 0640);

        return ['filename' => $filename, 'path' => 'media/' . $filename];
    }

    public function remove(string $filename): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) return;
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) @unlink($path);
    }

    /** @return array{body:string,mime:string}|null */
    public function read(string $filename): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) return null;
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) return null;
        $realPath = realpath($path);
        $realDirectory = realpath($this->directory);
        if ($realPath === false || $realDirectory === false || dirname($realPath) !== $realDirectory) return null;
        $body = file_get_contents($realPath);
        if (!is_string($body)) return null;
        $mime = match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => null,
        };
        return $mime === null ? null : ['body' => $body, 'mime' => $mime];
    }
}
