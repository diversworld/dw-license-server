<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProfileImageUpload
{
    private const array FORMATS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly ValidatorInterface $validator, private readonly Filesystem $filesystem)
    {
    }

    public function constraint(): Image
    {
        return new Image(maxSize: 5_000_000, mimeTypes: array_keys(self::FORMATS), minWidth: 16, minHeight: 16,
            maxWidth: 4096, maxHeight: 4096, maxPixels: 16_000_000, detectCorrupted: true);
    }

    public function filename(UploadedFile $file): string
    {
        // Invalid uploads get a harmless extension and are rejected by the active form constraint.
        return bin2hex(random_bytes(16)).'.'.(self::FORMATS[$file->getMimeType()] ?? 'bin');
    }

    public function store(UploadedFile $file, string $directory, string $filename): void
    {
        if (count($this->validator->validate($file, $this->constraint())) !== 0
            || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $filename)
            || pathinfo($filename, PATHINFO_EXTENSION) !== (self::FORMATS[$file->getMimeType()] ?? null)) {
            throw new TransformationFailedException('Invalid profile image.');
        }
        $image = imagecreatefromstring(file_get_contents($file->getPathname()));
        if ($image === false) {
            throw new TransformationFailedException('Invalid profile image.');
        }
        $this->filesystem->mkdir($directory, 0755);
        $temporary = tempnam($directory, '.image-');
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $saved = match (pathinfo($filename, PATHINFO_EXTENSION)) {
                'jpg' => imagejpeg($image, $temporary, 90),
                'png' => imagepng($image, $temporary),
                'webp' => imagewebp($image, $temporary, 90),
            };
            if (!$saved) {
                throw new \RuntimeException('Cannot encode profile image.');
            }
            $this->filesystem->chmod($temporary, 0644);
            $this->filesystem->rename($temporary, $directory.'/'.$filename);
        } finally {
            imagedestroy($image);
            $this->filesystem->remove($temporary);
        }
    }
}
