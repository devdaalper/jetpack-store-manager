<?php

use PHPUnit\Framework\TestCase;

require_once jpsm_test_plugin_root() . '/includes/class-access-manager.php';

final class AccessManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__wp_options'] = array();
    }

    public function testDefaultAllowedTiersIncludesDemoAndFull(): void
    {
        $allowed = JPSM_Access_Manager::get_folder_allowed_tiers('Music/');
        $this->assertIsArray($allowed);
        $this->assertContains(JPSM_Access_Manager::TIER_DEMO, $allowed);
        $this->assertContains(JPSM_Access_Manager::TIER_FULL, $allowed);
    }

    public function testExactFolderPermissionsAreEnforced(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'Music/' => array(3, 4, 5),
        ));

        $this->assertSame(array(3, 4, 5), JPSM_Access_Manager::get_folder_allowed_tiers('Music/'));
        $this->assertFalse(JPSM_Access_Manager::user_can_access('Music/', 1));
        $this->assertTrue(JPSM_Access_Manager::user_can_access('Music/', 3));
    }

    public function testParentFolderPermissionsApplyRecursively(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'Music/' => array(3, 5),
        ));

        $allowed = JPSM_Access_Manager::get_folder_allowed_tiers('Music/Reggaeton/');
        $this->assertSame(array(3, 5), $allowed);
        $this->assertFalse(JPSM_Access_Manager::user_can_access('Music/Reggaeton/', 2));
        $this->assertTrue(JPSM_Access_Manager::user_can_access('Music/Reggaeton/', 3));
    }

    public function testLegacyNumericPermissionExpandsToRange(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'Videos/' => 3,
        ));

        $allowed = JPSM_Access_Manager::get_folder_allowed_tiers('Videos/');
        $this->assertSame(array(3, 4, 5), $allowed);
    }

    public function testSingletonArrayPermissionExpandsToRange(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'VIP/' => array(2),
        ));

        $allowed = JPSM_Access_Manager::get_folder_allowed_tiers('VIP/');
        $this->assertSame(array(2, 3, 4, 5), $allowed);
    }

    public function testExactMatchWithoutTrailingSlashIsHonored(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'Music' => array(1, 3, 5),
        ));

        $allowed = JPSM_Access_Manager::get_folder_allowed_tiers('Music/');
        $this->assertSame(array(1, 3, 5), $allowed);
    }

    public function testFullTierAlwaysHasAccess(): void
    {
        update_option(JPSM_Access_Manager::OPT_FOLDER_PERMS, array(
            'Locked/' => array(3),
        ));

        $this->assertTrue(JPSM_Access_Manager::user_can_access('Locked/', JPSM_Access_Manager::TIER_FULL));
    }
}
