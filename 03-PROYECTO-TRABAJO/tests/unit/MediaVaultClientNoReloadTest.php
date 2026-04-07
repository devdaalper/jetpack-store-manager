<?php

use PHPUnit\Framework\TestCase;

final class MediaVaultClientNoReloadTest extends TestCase
{
    public function testMediaVaultClientDoesNotProgrammaticallyReloadPage(): void
    {
        $path = __DIR__ . '/../../includes/modules/mediavault/assets/js/mediavault-client.js';
        $js = file_get_contents($path);

        $this->assertIsString($js);
        $this->assertNotFalse($js, 'Failed to read mediavault-client.js');

        // Reloading the page cancels in-progress downloads; MediaVault navigation must be AJAX-only.
        $this->assertStringNotContainsString('location.reload', $js);
    }

    public function testMediaVaultClientUsesStickyToolbarHostForLoaderVisibility(): void
    {
        $path = __DIR__ . '/../../includes/modules/mediavault/assets/js/mediavault-client.js';
        $js = file_get_contents($path);

        $this->assertIsString($js);
        $this->assertNotFalse($js, 'Failed to read mediavault-client.js');

        // Loader must remain visible even when the user scrolls; use the sticky toolbar host.
        $this->assertStringContainsString('mv-toolbar-status', $js);
        $this->assertStringContainsString('mv-nav-home', $js);
    }

    public function testMediaVaultTemplateDoesNotUseLocationReload(): void
    {
        $path = __DIR__ . '/../../includes/modules/mediavault/template-vault.php';
        $php = file_get_contents($path);

        $this->assertIsString($php);
        $this->assertNotFalse($php, 'Failed to read template-vault.php');

        $this->assertStringNotContainsString('location.reload', $php);
    }

    public function testMediaVaultTemplateHasInAppNavigationControls(): void
    {
        $path = __DIR__ . '/../../includes/modules/mediavault/template-vault.php';
        $php = file_get_contents($path);

        $this->assertIsString($php);
        $this->assertNotFalse($php, 'Failed to read template-vault.php');

        // Provide in-app Back/Forward to keep users off browser back/refresh.
        $this->assertStringContainsString('id="mv-nav-back"', $php);
        $this->assertStringContainsString('id="mv-nav-forward"', $php);
        $this->assertStringContainsString('id="mv-nav-home"', $php);
        $this->assertStringContainsString('id="mv-toolbar-status"', $php);
    }
}
