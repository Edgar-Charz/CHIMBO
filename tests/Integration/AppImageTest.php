<?php

use PHPUnit\Framework\TestCase;

/** Pictures of the app's fixed screens, replaced from the admin. */
final class AppImageTest extends TestCase
{
    private const ADMIN_ID = 1;

    private Database $db;
    private AppImage $app_image_model;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->app_image_model = new AppImage($this->db);
        foreach (array_keys(AppImage::SLOTS) as $slot) {
            $this->app_image_model->removeImage($slot, self::ADMIN_ID);
        }
    }

    public function testSlotsWithoutUploadUseTheAppsOwnPicture(): void
    {
        $this->assertSame([], $this->app_image_model->getImagesForApps());
        $this->assertCount(count(AppImage::SLOTS), $this->app_image_model->listForAdmin());
    }

    public function testUploadKeepsOneSizeAndReplacingRemovesTheOldFile(): void
    {
        $this->app_image_model->setImageFromFile('onboarding_products', $this->makePicture(), self::ADMIN_ID);
        $first = $this->urlsBySlot()['onboarding_products'];

        $this->app_image_model->setImageFromFile('onboarding_products', $this->makePicture(), self::ADMIN_ID);
        $second = $this->urlsBySlot()['onboarding_products'];

        $this->assertStringEndsWith('-large.webp', $second);
        $this->assertNotSame($first, $second);                       // a new URL, so caches pick it up
        $this->assertFileDoesNotExist($this->fileOf($first));
        $this->assertFileExists($this->fileOf($second));
        $this->assertFileDoesNotExist(str_replace('-large.webp', '-thumb.webp', $this->fileOf($second)));
    }

    public function testRemovingGoesBackToTheAppsPictureAndDeletesTheFile(): void
    {
        $this->app_image_model->setImageFromFile('auth_welcome', $this->makePicture(), self::ADMIN_ID);
        $url = $this->urlsBySlot()['auth_welcome'];

        $this->app_image_model->removeImage('auth_welcome', self::ADMIN_ID);

        $this->assertArrayNotHasKey('auth_welcome', $this->urlsBySlot());
        $this->assertFileDoesNotExist($this->fileOf($url));
    }

    public function testUnknownSlotIsRefused(): void
    {
        $this->expectExceptionObject(ApiException::notFound('Unknown picture slot.'));
        $this->app_image_model->setImageFromFile('../../etc', $this->makePicture(), self::ADMIN_ID);
    }

    private function urlsBySlot(): array
    {
        return array_column($this->app_image_model->getImagesForApps(), 'app_image_url', 'app_image_slot');
    }

    private function makePicture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chimbo') . '.jpg';
        imagejpeg(imagecreatetruecolor(1080, 1350), $path);
        return $path;
    }

    /** A media URL → the file on disk. */
    private function fileOf(string $url): string
    {
        return BASE_PATH . '/' . substr($url, strpos($url, 'media/'));
    }
}
