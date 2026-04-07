<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/modules/mediavault/class-nav-order.php';

final class MediaVaultNavOrderTest extends TestCase
{
    public function testNormalizeFolderPathCanonicalizesSlashes(): void
    {
        $this->assertSame('', JPSM_MediaVault_Nav_Order::normalize_folder_path(''));
        $this->assertSame('', JPSM_MediaVault_Nav_Order::normalize_folder_path('/'));
        $this->assertSame('a/', JPSM_MediaVault_Nav_Order::normalize_folder_path('a'));
        $this->assertSame('a/', JPSM_MediaVault_Nav_Order::normalize_folder_path('/a/'));
        $this->assertSame('a/b/', JPSM_MediaVault_Nav_Order::normalize_folder_path('///a/b///'));
    }

    public function testApplyOrderUsesSavedOrderThenAppendsUnknownAlphabetically(): void
    {
        $folders = array('b/', 'a/', 'c/');
        $saved = array('c/', 'a/');

        $ordered = JPSM_MediaVault_Nav_Order::apply_order($folders, $saved);
        $this->assertSame(array('c/', 'a/', 'b/'), $ordered);
    }

    public function testApplyOrderDedupesAndNormalizes(): void
    {
        $folders = array('a', 'a/', '/b/', 'b', 'c/');
        $saved = array('/b', 'a/');

        $ordered = JPSM_MediaVault_Nav_Order::apply_order($folders, $saved);
        $this->assertSame(array('b/', 'a/', 'c/'), $ordered);
    }
}

