<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProfileImageUploadTest extends IsolatedWebTestCase
{
    private array $temporaryFiles = [];
    private array $storedFiles = [];

    public static function formats(): iterable
    {
        yield 'JPEG' => ['jpeg', 'jpg'];
        yield 'PNG' => ['png', 'png'];
        yield 'WebP' => ['webp', 'webp'];
    }

    #[DataProvider('formats')]
    public function testValidatedImageGetsRandomSafeExtensionAndStripsAppendedCode(string $format, string $extension): void
    {
        $user = $this->user('ROLE_VIEWER');
        $this->client->loginUser($user);
        $path = $this->image($format);
        file_put_contents($path, '<?php echo "upload-payload"; ?>', FILE_APPEND);
        $this->submit($user, new UploadedFile($path, 'malicious.php', 'application/x-httpd-php', test: true));
        self::assertResponseRedirects('/admin/profil');
        $saved = $this->em->find(User::class, $user->getId())->getProfileImage();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.'.$extension.'$/', $saved);
        $file = dirname(__DIR__, 2).'/public/uploads/profile/'.$saved;
        $this->storedFiles[] = $file;
        self::assertFileExists($file);
        self::assertStringNotContainsString('upload-payload', file_get_contents($file));
        self::assertSame('image/'.($format === 'jpeg' ? 'jpeg' : $format), mime_content_type($file));
    }

    public static function invalidImages(): iterable
    {
        yield 'executable disguised as PNG' => ['php'];
        yield 'image over 5 MB' => ['size'];
        yield 'dimensions over 4096 pixels' => ['dimensions'];
        yield 'corrupt PNG' => ['corrupt'];
    }

    #[DataProvider('invalidImages')]
    public function testInvalidContentIsNotStored(string $kind): void
    {
        $user = $this->user('ROLE_VIEWER');
        $this->client->loginUser($user);
        $path = $this->image('png', $kind === 'dimensions' ? 4097 : 32);
        if ($kind === 'php') {
            file_put_contents($path, '<?php echo "not-an-image"; ?>');
        } elseif ($kind === 'size') {
            file_put_contents($path, str_repeat('x', 5_000_001), FILE_APPEND);
        } elseif ($kind === 'corrupt') {
            file_put_contents($path, substr(file_get_contents($path), 0, 30));
        }
        $this->submit($user, new UploadedFile($path, 'picture.png', 'image/png', test: true));
        self::assertResponseStatusCodeSame(422);
        $id = $user->getId();
        $candidate = $this->em->find(User::class, $id)->getProfileImage();
        if ($candidate) {
            self::assertFileDoesNotExist(dirname(__DIR__, 2).'/public/uploads/profile/'.$candidate);
        }
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $id)->getProfileImage());
    }

    public function testForeignProfileCannotBeReadOrChanged(): void
    {
        $actor = $this->user();
        $other = $this->user('ROLE_VIEWER');
        $this->client->loginUser($actor);
        $url = static::getContainer()->get('router')->generate('admin_profile_edit', ['entityId' => (string) $other->getId()]);
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, $url, ['User' => ['profileImage' => 'attack.php']]);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertNull($this->em->find(User::class, $other->getId())->getProfileImage());
    }

    private function submit(User $user, UploadedFile $file): void
    {
        $url = static::getContainer()->get('router')->generate('admin_profile_edit', ['entityId' => (string) $user->getId()]);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="User"]')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['User' => ['profileImage' => ['file' => $file]]], ['HTTP_REFERER' => $form->getUri()]);
    }

    private function image(string $format, int $width = 32): string
    {
        $path = tempnam(sys_get_temp_dir(), 'profile-image-');
        $this->temporaryFiles[] = $path;
        $image = imagecreatetruecolor($width, 32);
        match ($format) { 'jpeg' => imagejpeg($image, $path), 'png' => imagepng($image, $path), 'webp' => imagewebp($image, $path) };
        imagedestroy($image);

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->temporaryFiles, $this->storedFiles) as $file) {
            if (is_file($file)) { unlink($file); }
        }
        parent::tearDown();
    }
}
