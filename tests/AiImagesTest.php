<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiImagesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        require_once $this->root . '/vendor/autoload.php';
        \App\Core\Env::bootstrap($this->root);
        require_once $this->root . '/api/lib/settings.php';
        require_once $this->root . '/api/lib/ai_images.php';
        // Ensure toggles ON by default for tests
        setting_set('ai.enabled', '1');
        setting_set('ai.award.enabled', '1');
    }

    public function testLocalSvgGenerationPath(): void
    {
        $uid = 9999;
        $res = ai_image_generate([
            'user_id' => $uid,
            'user_name' => 'Test User',
            'award_kind' => 'lifetime_steps',
            'milestone_value' => 100000,
            'force' => true,
        ]);
        $this->assertIsArray($res);
        $this->assertTrue($res['ok'] ?? false, 'Generation should succeed');
        $this->assertArrayHasKey('path', $res);
        $path = (string)$res['path'];
        $this->assertStringStartsWith('assets/awards/' . $uid . '/', $path, 'Path should be under user awards');
        // Check file exists on disk (under site/)
        $rel = preg_replace('#^assets/#', 'site/assets/', $path);
        $abs = $this->root . '/' . $rel;
        $this->assertFileExists($abs);
    }

    public function testCanGenerateDisabledFlags(): void
    {
        setting_set('ai.enabled', '0');
        $this->assertFalse(ai_image_can_generate());
        setting_set('ai.enabled', '1'); // restore
        setting_set('ai.award.enabled', '0');
        $this->assertFalse(ai_image_can_generate());
        setting_set('ai.award.enabled', '1'); // restore
    }
}
