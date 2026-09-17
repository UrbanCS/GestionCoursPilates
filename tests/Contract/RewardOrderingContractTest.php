<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class RewardOrderingContractTest extends TestCase
{
    public function testClientRewardsSortByPointsBeforeManualOrder(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2)
            . '/packages/com_memipilates/site/src/View/Dashboard/HtmlView.php');

        self::assertStringContainsString("->order('r.points_cost ASC, r.ordering ASC, r.id ASC')", $source);
        self::assertStringContainsString("->where('r.published = 1')", $source);
        self::assertStringContainsString("->where('r.archived_at IS NULL')", $source);
    }

    public function testStudioAndJoomlaShareThePointsOrdering(): void
    {
        $base = dirname(__DIR__, 2) . '/packages/com_memipilates';
        $admin = (string) file_get_contents($base . '/admin/src/View/Offers/HtmlView.php');
        $site = (string) file_get_contents($base . '/site/src/View/Offers/HtmlView.php');

        self::assertStringContainsString("->order('r.points_cost ASC, r.ordering ASC, r.title ASC, r.id ASC')", $admin);
        self::assertStringContainsString('extends AdministratorOffersView', $site);
    }
}
