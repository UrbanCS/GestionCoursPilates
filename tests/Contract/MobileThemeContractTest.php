<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class MobileThemeContractTest extends TestCase
{
    public function testOverridesCoverTheTabletBreakpointAndSharedFooter(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2)
            . '/deployment/templates/selixo/css/custom.css');

        self::assertStringContainsString('@media (max-width: 991.98px)', $css);
        self::assertStringNotContainsString('@media (max-width: 575.98px)', $css);
        self::assertStringContainsString('#btn-30ba37df-a3f5-47d7-b853-d50a992406f9', $css);
        self::assertStringContainsString('#sppb-addon-13917c3e-a320-4b68-a532-81a1a3e3528e img', $css);
        self::assertStringContainsString('body.itemid-882 #sppb-addon-wNTdqAoaKaJXUDn0LRcoU', $css);
        self::assertStringContainsString('@media (min-width: 768px) and (max-width: 991.98px)', $css);
        self::assertStringContainsString('flex-basis: 50% !important', $css);
    }
}
