<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Installer;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\Storage\Store;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\MemoryStore;
use IndianOpinion\GenQueue\Tests\Support\TestCase;
use IndianOpinion\GenQueue\Uninstaller;

final class InstallUninstallTest extends TestCase
{
    /** Test 74, section 6. */
    public function testUninstallDropsTablesAndOptionsAndDeactivationKeepsThem(): void
    {
        $this->setCap('1');
        $this->type('demo.task');
        gen_queue_enqueue('demo.task', []);
        $this->plugin->breakers->failure('acme/m', $this->store->clock);
        $this->store->setOption('unrelated_option', 'stay');
        Plugin::activate();

        Plugin::deactivate();
        $this->assertFalse(wp_next_scheduled(Plugin::TICK_HOOK));
        $this->assertCount(1, $this->store->jobs);
        $this->assertArrayHasKey('gen_queue_daily_cap', $this->store->options);

        Plugin::activate();
        Uninstaller::run($this->store);
        $this->assertFalse($this->store->schemaInstalled);
        $this->assertSame([], $this->store->jobs);
        $this->assertSame(['unrelated_option'], array_keys($this->store->options));
        $this->assertFalse(wp_next_scheduled(Plugin::TICK_HOOK));
    }

    /** Test 75, rule 14.9. */
    public function testInstallerVerifiesColumnsAndReportsFailure(): void
    {
        $store = new MemoryStore();
        $plugin = Plugin::boot($store);
        $this->assertTrue($plugin->installer->needed());
        $store->columnsOverride = [
            'jobs' => array_values(array_diff(Store::JOB_COLUMNS, ['cost_nanos'])),
            'attempts' => Store::ATTEMPT_COLUMNS,
            'locks' => Store::LOCK_COLUMNS,
        ];
        $missing = $plugin->installer->install();
        $this->assertSame(['jobs.cost_nanos'], $missing);
        $this->assertTrue($plugin->installer->needed());
        $this->assertNotNull($plugin->installer->error());
        $this->assertSame(1, $this->logged('missing column(s): jobs.cost_nanos'));

        // Nothing runs while the schema is wrong.
        $plugin->queue->setCap('5');
        $this->assertSame('disabled', $plugin->run(['time' => 5])->stopReason);
        $this->assertStringContainsString('database check failed', (new \IndianOpinion\GenQueue\Admin\Notices($plugin))->messages()[0]);

        $store->columnsOverride = null;
        $this->assertSame([], $plugin->installer->install());
        $this->assertFalse($plugin->installer->needed());
        $this->assertNull($plugin->installer->error());
        $this->assertSame(Installer::DB_VERSION, $store->options['gen_queue_db_version']);
        $this->assertSame('1', $store->options['gen_queue_enabled']);
    }

    /** As a must-use plugin the tables are created on first load. */
    public function testMaybeInstallOnFirstLoad(): void
    {
        $store = new MemoryStore();
        $plugin = Plugin::boot($store);
        $plugin->maybeInstall();
        $this->assertTrue($store->schemaInstalled);
        $this->assertFalse($plugin->installer->needed());
    }

    public function testHookWiresWordPress(): void
    {
        WpState::$isAdmin = true;
        $this->plugin->hook(__FILE__);
        $this->assertTrue(has_action('init'));
        $this->assertTrue(has_action(Plugin::TICK_HOOK));
        $this->assertTrue(has_action('admin_menu'));
        $this->assertTrue(has_action('admin_post_gen_queue_action'));
        $this->assertTrue(has_action('admin_notices'));
        $this->assertTrue(has_filter('wp_privacy_personal_data_exporters'));
    }
}
