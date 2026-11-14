<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Admin\Notices;
use IndianOpinion\GenQueue\Admin\StatusPage;
use IndianOpinion\GenQueue\Cli\Command;
use IndianOpinion\GenQueue\Storage\Store;
use IndianOpinion\GenQueue\Storage\WpdbStore;

/**
 * Wires the services together and into WordPress.
 */
final class Plugin
{
    public const TICK_HOOK = 'gen_queue_tick';
    public const SCHEDULE = 'gen_queue_minute';

    private static ?Plugin $instance = null;

    public readonly Registry $registry;
    public readonly Controls $controls;
    public readonly Breakers $breakers;
    public readonly SpendMeter $meter;
    public readonly Queue $queue;
    public readonly Housekeeping $housekeeping;
    public readonly Runner $runner;
    public readonly Installer $installer;
    public readonly Status $status;

    public function __construct(public readonly Store $store)
    {
        $this->registry = new Registry();
        $this->controls = new Controls($store);
        $this->breakers = new Breakers($store, $this->controls);
        $this->meter = new SpendMeter();
        $this->queue = new Queue($store, $this->registry, $this->controls);
        $this->housekeeping = new Housekeeping($store, $this->registry, $this->controls);
        $this->runner = new Runner($store, $this->registry, $this->controls, $this->breakers, $this->meter, $this->housekeeping);
        $this->installer = new Installer($store);
        $this->status = new Status($store, $this->registry, $this->controls, $this->breakers, $this->installer);
    }

    /** Build the plugin on a store and make it the current instance. */
    public static function boot(Store $store): self
    {
        self::$instance = new self($store);
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new GenQueueException('gen-queue has not loaded yet. Call gen-queue functions on or after the gen_queue_loaded action.');
        }
        return self::$instance;
    }

    public static function loaded(): bool
    {
        return self::$instance !== null;
    }

    /** Production entry point, called from the main plugin file. */
    public static function load(string $file): self
    {
        global $wpdb;
        $plugin = self::boot(new WpdbStore($wpdb));
        $plugin->hook($file);
        do_action('gen_queue_loaded');
        return $plugin;
    }

    public function hook(string $file): void
    {
        add_action('init', [$this, 'maybeInstall'], 1);
        add_action('init', [$this, 'openRegistration'], 20);
        add_action('init', [$this, 'ensureScheduled'], 30);
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(self::TICK_HOOK, [$this, 'tick']);
        add_filter('gen_queue_enqueue_job', [$this, 'softEnqueue'], 10, 4);
        add_filter('wp_privacy_personal_data_exporters', [new Privacy($this->store), 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [new Privacy($this->store), 'registerEraser']);

        if (function_exists('register_activation_hook')) {
            register_activation_hook($file, [self::class, 'activate']);
            register_deactivation_hook($file, [self::class, 'deactivate']);
        }

        if (is_admin()) {
            (new StatusPage($this))->hook();
            (new Notices($this))->hook();
        }

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('gen-queue', new Command($this));
        }
    }

    /** As a must-use plugin there is no activation, so install on first load. */
    public function maybeInstall(): void
    {
        try {
            // The cached read keeps ordinary page loads free of an extra query.
            if (get_option(Installer::OPT_DB_VERSION) === Installer::DB_VERSION && $this->installer->error() === null) {
                return;
            }
            if ($this->installer->needed()) {
                $this->installer->install();
            }
        } catch (StorageException $e) {
            Log::warning('Install failed: ' . $e->getMessage());
        }
    }

    public function openRegistration(): void
    {
        $this->registry->open();
        do_action('gen_queue_register_types');
        $this->registry->close();
    }

    public function ensureScheduled(): void
    {
        if (wp_next_scheduled(self::TICK_HOOK) === false) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::TICK_HOOK);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function schedules(array $schedules): array
    {
        $schedules[self::SCHEDULE] = ['interval' => 60, 'display' => 'Every minute (gen-queue)'];
        return $schedules;
    }

    public static function activate(bool $networkWide = false): void
    {
        add_filter('cron_schedules', [self::class, 'schedules']);
        if ($networkWide && function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                global $wpdb;
                self::installSite(new self(new WpdbStore($wpdb)));
                restore_current_blog();
            }
            return;
        }
        self::installSite(self::instance());
    }

    private static function installSite(self $plugin): void
    {
        $plugin->installer->install();
        $plugin->ensureScheduled();
    }

    /** Deactivation clears the schedule only; every job and setting stays. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::TICK_HOOK);
    }

    /** The WP-Cron tick: housekeeping always, jobs unless the constant says not to. */
    public function tick(): ?RunSummary
    {
        if (defined('GEN_QUEUE_DISABLE_CRON_RUNNER') && constant('GEN_QUEUE_DISABLE_CRON_RUNNER')) {
            $this->housekeeping->run(Runner::workerId());
            $this->controls->setJsonOption(Controls::OPT_LAST_TICK, ['at' => $this->store->now(), 'stop_reason' => 'housekeeping']);
            return null;
        }
        $summary = $this->run(['time' => $this->controls->tickSeconds()]);
        do_action('gen_queue_tick_finished', $summary);
        return $summary;
    }

    /**
     * @param array{time?: int, max?: int, type?: string, worker?: string} $opts
     */
    public function run(array $opts = []): RunSummary
    {
        if ($this->installer->error() !== null) {
            Log::warning('Not running: the schema check failed. See the status page.');
            $summary = new RunSummary();
            $summary->stopReason = 'disabled';
            return $summary;
        }
        $summary = $this->runner->run($opts);
        $at = $this->runner->lastFinishedAt() ?? $this->store->now();
        $this->controls->setJsonOption(Controls::OPT_LAST_TICK, ['at' => $at, 'stop_reason' => $summary->stopReason]);
        return $summary;
    }

    /**
     * The soft enqueue filter: returns a job id, or a WP_Error instead of throwing.
     *
     * @param array<mixed> $payload
     * @param array<string, mixed> $args
     * @return int|\WP_Error
     */
    public function softEnqueue(mixed $pre, mixed $type, mixed $payload, mixed $args = []): int|\WP_Error
    {
        try {
            if (!is_string($type) || !is_array($payload) || !is_array($args)) {
                throw new \InvalidArgumentException('gen_queue_enqueue_job takes a type string, a payload array and an args array.');
            }
            return $this->queue->enqueue($type, $payload, $args);
        } catch (\Throwable $e) {
            return new \WP_Error('gen_queue_enqueue_failed', $e->getMessage(), ['exception' => get_class($e)]);
        }
    }
}
