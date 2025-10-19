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

        $meta = $res['meta'] ?? [];
        $vars = $meta['vars'] ?? [];
        $this->assertSame('LIFETIME 100,000 STEPS', $vars['bannerText'] ?? null);
        $this->assertSame('100,000 Steps', $vars['milestone'] ?? null);
        $this->assertSame('Lifetime Steps', $vars['awardLabel'] ?? null);
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

    public function testAttendanceAwardUsesDaysUnit(): void
    {
        $uid = 9998;
        $res = ai_image_generate([
            'user_id' => $uid,
            'user_name' => 'Attendance Ace',
            'award_kind' => 'attendance_days',
            'milestone_value' => 35,
            'force' => true,
        ]);
        $this->assertTrue($res['ok'] ?? false, 'Generation should succeed for attendance');

        $meta = $res['meta'] ?? [];
        $vars = $meta['vars'] ?? [];
        $this->assertSame('LIFETIME 35 DAYS', $vars['bannerText'] ?? null);
        $this->assertSame('35 Days', $vars['milestone'] ?? null);
        $this->assertSame('Lifetime Attendance', $vars['awardLabel'] ?? null);
        $this->assertSame('Days', $vars['unitTitle'] ?? null);
        $this->assertSame('DAYS', $vars['unitUpper'] ?? null);
    }

    public function testAttendanceAliasNormalizesToDays(): void
    {
        $uid = 9997;
        $res = ai_image_generate([
            'user_id' => $uid,
            'user_name' => 'Alias User',
            'award_kind' => 'attendance',
            'milestone_value' => 42,
            'force' => true,
        ]);
        $this->assertTrue($res['ok'] ?? false);
        $meta = $res['meta'] ?? [];
        $vars = $meta['vars'] ?? [];
        $this->assertSame('Lifetime Attendance', $vars['awardLabel'] ?? null);
        $this->assertSame('LIFETIME 42 DAYS', $vars['bannerText'] ?? null);
    }
}
