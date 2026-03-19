<?php

declare(strict_types=1);

namespace NChat\Service;

use Nette\Http\FileUpload;

/**
 * File uploader for chat attachments.
 *
 * Validates MIME type and size, stores with hash-based filename.
 */
class FileUploader
{
	/** @var list<string> */
	private array $allowedTypes;

	private int $maxSize;

	private string $uploadDir;


	/**
	 * @param list<string> $allowedTypes
	 */
	public function __construct(
		string $uploadDir,
		int $maxSize = 10_485_760,
		array $allowedTypes = [],
	) {
		$this->uploadDir = rtrim($uploadDir, '/');
		$this->maxSize = $maxSize;
		$this->allowedTypes = $allowedTypes ?: [
			'image/jpeg', 'image/png', 'image/gif', 'image/webp',
			'application/pdf',
			'application/zip', 'application/x-rar-compressed',
			'text/plain',
		];
	}


	/**
	 * Upload a file and return attachment metadata.
	 *
	 * @return array{path: string, name: string, size: int, type: string}
	 * @throws \RuntimeException on validation failure
	 */
	public function upload(FileUpload $file): array
	{
		if (!$file->isOk()) {
			throw new \RuntimeException('Upload failed: ' . $file->getError());
		}

		if ($file->getSize() > $this->maxSize) {
			throw new \RuntimeException('File too large (max ' . round($this->maxSize / 1_048_576, 1) . ' MB)');
		}

		$mime = $file->getContentType() ?? 'application/octet-stream';
		if (!in_array($mime, $this->allowedTypes, true)) {
			throw new \RuntimeException('File type not allowed: ' . $mime);
		}

		$originalName = $file->getSanitizedName();
		$ext = pathinfo($originalName, PATHINFO_EXTENSION);
		$hash = bin2hex(random_bytes(16));
		$subdir = date('Y/m');
		$relativePath = $subdir . '/' . $hash . ($ext ? '.' . $ext : '');

		$fullDir = $this->uploadDir . '/' . $subdir;
		if (!is_dir($fullDir)) {
			mkdir($fullDir, 0755, true);
		}

		$file->move($this->uploadDir . '/' . $relativePath);

		return [
			'path' => $relativePath,
			'name' => $originalName,
			'size' => $file->getSize(),
			'type' => $mime,
		];
	}


	/**
	 * Get absolute file path for download.
	 */
	public function getAbsolutePath(string $relativePath): string
	{
		return $this->uploadDir . '/' . $relativePath;
	}


	/**
	 * Check if a file exists.
	 */
	public function fileExists(string $relativePath): bool
	{
		return is_file($this->uploadDir . '/' . $relativePath);
	}
}
