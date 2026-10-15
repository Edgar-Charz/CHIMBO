<?php

use PHPUnit\Framework\TestCase;

/** The logo uploaded in the admin (blueprint R-27, §12.9): square PNG icons for the website, admin, PDFs and app. */
final class SiteLogoTest extends TestCase
{
    private const ADMIN_ID = 1;

    private Database $db;
    private SiteLogo $logo;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        SiteLogo::clearCache();
        $this->logo = new SiteLogo($this->db);
        $this->logo->removeLogo(self::ADMIN_ID);
    }

    protected function tearDown(): void
    {
        $this->logo->removeLogo(self::ADMIN_ID);
    }

    public function testWithoutAnUploadEveryoneKeepsTheBuiltInLogo(): void
    {
        $this->assertNull($this->logo->getUrl());
        $this->assertNotContains('logo', array_column((new AppImage($this->db))->getImagesForApps(), 'app_image_slot'));
        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->logo->pdfDataUri(BASE_PATH . '/admin/assets/img/favicon-192.png'));
    }

    public function testAWideLogoBecomesTransparentSquareIconsInEverySize(): void
    {
        $this->logo->setLogoFromFile($this->makeWideLogo(), self::ADMIN_ID);

        foreach (SiteLogo::SIZES as $size_name => $pixels) {
            $file = BASE_PATH . '/' . $this->logo->getPath($size_name);
            $this->assertFileExists($file);
            [$width, $height, $type] = getimagesize($file);
            $this->assertSame([$pixels, $pixels, IMAGETYPE_PNG], [$width, $height, $type], "size {$size_name}");
        }
        // The wide picture is centred: the top-left corner of the square stays transparent
        $icon = imagecreatefrompng(BASE_PATH . '/' . $this->logo->getPath('512'));
        $this->assertSame(127, imagecolorat($icon, 0, 0) >> 24 & 0x7F);

        $for_apps = array_column((new AppImage($this->db))->getImagesForApps(), 'app_image_url', 'app_image_slot');
        $this->assertSame($this->logo->getUrl(), $for_apps['logo']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE audit_log_action = 'logo.changed'"));
    }

    public function testANewUploadReplacesTheOldFilesAndRemovingGoesBack(): void
    {
        $this->logo->setLogoFromFile($this->makeWideLogo(), self::ADMIN_ID);
        $old_file = BASE_PATH . '/' . $this->logo->getPath('32');

        $this->logo->setLogoFromFile($this->makeWideLogo(), self::ADMIN_ID);
        $this->assertFileDoesNotExist($old_file);
        $new_file = BASE_PATH . '/' . $this->logo->getPath('32');
        $this->assertFileExists($new_file);

        $this->logo->removeLogo(self::ADMIN_ID);
        $this->assertNull($this->logo->getUrl());
        $this->assertFileDoesNotExist($new_file);
    }

    public function testSomethingThatIsNotAPictureIsRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'chimbo');
        file_put_contents($file, '<?php echo "not a logo";');

        try {
            $this->logo->setLogoFromFile($file, self::ADMIN_ID);
            $this->fail('Expected INVALID_IMAGE');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_IMAGE', $e->errorCode());
        }
        $this->assertNull($this->logo->getUrl());
    }

    /** An 800 × 400 PNG with a transparent background and a solid shape in the middle. */
    private function makeWideLogo(): string
    {
        $image = imagecreatetruecolor(800, 400);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 100, 100, 700, 300, imagecolorallocate($image, 14, 59, 42));

        $path = tempnam(sys_get_temp_dir(), 'chimbo') . '.png';
        imagepng($image, $path);
        return $path;
    }
}
