<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Admin;

use IndianOpinion\GenQueue\Controls;
use IndianOpinion\GenQueue\Days;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\Validate;

/**
 * Tools > Generation queue: state, spend, counts, cooldowns, holds, recent
 * failures and the operator's actions. Every action needs the capability
 * from gen_queue_capability and a nonce. All output is escaped.
 */
final class StatusPage
{
    public const SLUG = 'gen-queue';
    public const ACTION = 'gen_queue_action';
    public const NONCE = 'gen_queue_action';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public static function capability(): string
    {
        $cap = apply_filters('gen_queue_capability', 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public function hook(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handlePost']);
    }

    public function menu(): void
    {
        add_management_page('Generation queue', 'Generation queue', self::capability(), self::SLUG, [$this, 'render']);
    }

    /** admin-post.php entry point. */
    public function handlePost(): void
    {
        $notice = $this->handle(wp_unslash($_POST));
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'gen_queue_notice' => rawurlencode($notice)], admin_url('tools.php')));
        exit;
    }

    /**
     * Apply one action. Dies on a missing capability or nonce.
     *
     * @param array<string, mixed> $request
     * @return string a short message for the operator
     */
    public function handle(array $request): string
    {
        if (!current_user_can(self::capability())) {
            wp_die('You do not have permission to change the generation queue.', 'Forbidden', ['response' => 403]);
        }
        $nonce = isset($request['_wpnonce']) && is_string($request['_wpnonce']) ? $request['_wpnonce'] : '';
        if (wp_verify_nonce($nonce, self::NONCE) === false) {
            wp_die('The link has expired. Go back, reload the page and try again.', 'Expired', ['response' => 403]);
        }
        $op = isset($request['op']) && is_string($request['op']) ? $request['op'] : '';
        $queue = $this->plugin->queue;
        try {
            switch ($op) {
                case 'pause':
                case 'resume':
                    $type = isset($request['type']) && is_string($request['type']) && $request['type'] !== '' ? Validate::typeName($request['type']) : null;
                    $op === 'pause' ? $queue->pause($type) : $queue->resume($type);
                    return ($type ?? 'Queue') . ($op === 'pause' ? ' paused.' : ' resumed.');
                case 'cap':
                    $amount = isset($request['cap']) && is_string($request['cap']) ? trim($request['cap']) : '';
                    $queue->setCap($amount === '' ? null : $amount);
                    if (isset($request['currency']) && is_string($request['currency'])) {
                        $this->plugin->controls->setCurrency(sanitize_text_field($request['currency']));
                    }
                    return $amount === '' ? 'Cap removed. Nothing runs until a cap is set.' : 'Daily cap saved.';
                case 'retry':
                    $id = absint($request['id'] ?? 0);
                    return $queue->retry($id) ? sprintf('Job %d queued again.', $id) : sprintf('Job %d cannot be retried.', $id);
                case 'cancel':
                    $id = absint($request['id'] ?? 0);
                    return $queue->cancel($id) ? sprintf('Job %d cancelled.', $id) : sprintf('Job %d cannot be cancelled.', $id);
                case 'release':
                    $group = isset($request['group']) && is_string($request['group']) ? Validate::label($request['group'], 64, 'group') : '';
                    $this->plugin->breakers->release($group);
                    return sprintf('Hold on %s released.', $group);
                case 'settings':
                    $values = [];
                    foreach (array_keys(Controls::DEFAULTS) as $key) {
                        if (isset($request[$key]) && is_string($request[$key]) && $request[$key] !== '') {
                            $values[$key] = $request[$key];
                        }
                    }
                    $this->plugin->controls->updateSettings($values);
                    return 'Settings saved.';
                default:
                    return 'Unknown action.';
            }
        } catch (\InvalidArgumentException $e) {
            return 'Not saved: ' . $e->getMessage();
        }
    }

    public function render(): void
    {
        if (!current_user_can(self::capability())) {
            wp_die('You do not have permission to view the generation queue.', 'Forbidden', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>Generation queue</h1>';
        if (isset($_GET['gen_queue_notice']) && is_string($_GET['gen_queue_notice'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(rawurldecode(wp_unslash($_GET['gen_queue_notice'])))) . '</p></div>';
        }
        $jobId = isset($_GET['job']) ? absint($_GET['job']) : 0;
        if ($jobId > 0) {
            $this->renderJob($jobId);
        } else {
            $this->renderOverview();
        }
        echo '</div>';
    }

    private function renderOverview(): void
    {
        $s = $this->plugin->status->snapshot();
        $currency = (string) $s['currency'];
        $money = static fn (?string $v): string => $v === null ? 'not set' : trim($v . ' ' . $currency);

        if ($s['install_error'] !== null) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $s['install_error']) . '</p></div>';
        }
        echo '<h2>State: ' . esc_html((string) $s['state']) . '</h2>';
        if ($s['cap'] === null) {
            echo '<p><strong>' . esc_html('No daily cap is set, so nothing runs. Set one below or run: wp gen-queue cap <amount>') . '</strong></p>';
        }
        echo '<table class="widefat striped" style="max-width:48em"><tbody>';
        $rows = [
            'Kill switch' => ($s['enabled'] ? 'on (jobs may run)' : 'off (nothing runs)') . ', set by ' . $s['enabled_source'],
            'Daily cap' => $money($s['cap']) . ($s['cap'] !== null ? ', set by ' . $s['cap_source'] : ''),
            'Spent today (' . $s['day'] . ')' => $money($s['spent_today']) . ', from ' . $s['spend_source'],
            'Reserved by running jobs' => $money($s['reserved']),
            'Left today' => $money($s['cap_remaining']),
            'spend-meter' => $s['spend_meter'] ? 'present' : 'not present',
            'Last tick' => $s['last_tick'] === null ? 'never' : ($s['last_tick']['at'] . ' UTC, stopped: ' . $s['last_tick']['stop_reason']),
        ];
        if ($s['last_meter_denial'] !== null) {
            $rows['Last spend-meter denial'] = $s['last_meter_denial']['reason'] . ' at ' . $s['last_meter_denial']['at'] . ' UTC';
        }
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';

        $this->actions($s);

        echo '<h2>Jobs by type</h2><table class="widefat striped"><thead><tr><th>Type</th>';
        $statuses = ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'expired'];
        foreach ($statuses as $status) {
            echo '<th>' . esc_html($status) . '</th>';
        }
        echo '<th>Note</th></tr></thead><tbody>';
        foreach ($s['by_type'] as $type => $counts) {
            $note = [];
            if (in_array($type, $s['paused_types'], true)) {
                $note[] = 'paused';
            }
            if (isset($s['no_handler'][$type])) {
                $note[] = 'no handler';
            }
            echo '<tr><td>' . esc_html((string) $type) . '</td>';
            foreach ($statuses as $status) {
                echo '<td>' . esc_html((string) ($counts[$status] ?? 0)) . '</td>';
            }
            echo '<td>' . esc_html(implode(', ', $note)) . '</td></tr>';
        }
        echo '</tbody></table>';

        $this->listTable('Cooling resources', ['resource_key' => 'Resource', 'until' => 'Until (UTC)'], $s['cooling']);
        $this->listTable('Account holds', ['group' => 'Group', 'until' => 'Until (UTC)', 'message' => 'Message'], $s['holds']);
        $this->listTable('Types often over their timeout (7 days)', ['type' => 'Type', 'attempts' => 'Attempts', 'timeout' => 'Timeout (s)'], $s['slow_types']);
        $this->listTable(
            'Cost per day and type (7 days)',
            ['day' => 'Day', 'type' => 'Type', 'attempts' => 'Attempts', 'succeeded' => 'Succeeded', 'failed' => 'Failed', 'cost' => 'Cost'],
            $s['stats']
        );

        echo '<h2>Recent failures</h2><table class="widefat striped"><thead><tr><th>Job</th><th>Type</th><th>Class</th><th>Error</th><th>Finished (UTC)</th><th></th></tr></thead><tbody>';
        foreach ($s['recent_failures'] as $f) {
            $url = add_query_arg(['page' => self::SLUG, 'job' => (int) $f['id']], admin_url('tools.php'));
            echo '<tr><td><a href="' . esc_url($url) . '">' . esc_html((string) $f['id']) . '</a></td>';
            echo '<td>' . esc_html((string) $f['type']) . '</td><td>' . esc_html((string) $f['error_class']) . '</td>';
            echo '<td>' . esc_html((string) $f['error']) . '</td><td>' . esc_html((string) $f['finished_at']) . '</td><td>';
            $this->button('retry', 'Retry', ['id' => (string) $f['id']]);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $s */
    private function actions(array $s): void
    {
        echo '<h2>Actions</h2>';
        $this->button($s['enabled'] ? 'pause' : 'resume', $s['enabled'] ? 'Switch off (pause everything)' : 'Switch on');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0">';
        $this->hidden('cap');
        echo '<label>Daily cap <input type="text" name="cap" value="' . esc_attr((string) ($s['cap'] ?? '')) . '" placeholder="e.g. 5.00" size="10"></label> ';
        echo '<label>Currency label <input type="text" name="currency" value="' . esc_attr((string) $s['currency']) . '" size="6"></label> ';
        echo '<button class="button">Save cap</button> <span class="description">Empty means no cap, and with no cap nothing runs.</span></form>';
        foreach ($s['registered_types'] as $type) {
            $paused = in_array($type, $s['paused_types'], true);
            $this->button($paused ? 'resume' : 'pause', ($paused ? 'Resume ' : 'Pause ') . $type, ['type' => (string) $type]);
        }
        foreach ($s['holds'] as $hold) {
            $this->button('release', 'Release hold on ' . $hold['group'], ['group' => (string) $hold['group']]);
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0">';
        $this->hidden('settings');
        foreach ($s['settings'] as $key => $value) {
            echo '<label>' . esc_html(str_replace('_', ' ', (string) $key)) . ' <input type="number" name="' . esc_attr((string) $key) . '"'
                . ' value="' . esc_attr((string) $value) . '" style="width:6em"></label> ';
        }
        echo '<button class="button">Save settings</button></form>';
    }

    private function renderJob(int $id): void
    {
        $job = $this->plugin->queue->get($id);
        echo '<p><a href="' . esc_url(add_query_arg(['page' => self::SLUG], admin_url('tools.php'))) . '">Back to the queue</a></p>';
        if ($job === null) {
            echo '<p>' . esc_html(sprintf('Job %d was not found.', $id)) . '</p>';
            return;
        }
        echo '<h2>' . esc_html(sprintf('Job %d', $job->id)) . '</h2><table class="widefat striped" style="max-width:60em"><tbody>';
        foreach ($job->toArray() as $key => $value) {
            echo '<tr><th scope="row">' . esc_html((string) $key) . '</th><td>' . esc_html(is_scalar($value) ? (string) $value : '') . '</td></tr>';
        }
        $json = (string) json_encode($job->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (apply_filters('gen_queue_show_payload', false, $job) === true) {
            echo '<tr><th scope="row">payload</th><td><pre>' . esc_html($json) . '</pre></td></tr>';
        } else {
            echo '<tr><th scope="row">payload</th><td>' . esc_html(sprintf('hidden: %d bytes, sha256 %s', strlen($json), hash('sha256', $json))) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($job->status === 'queued' || $job->status === 'running') {
            $this->button('cancel', 'Cancel job', ['id' => (string) $job->id]);
        } elseif (in_array($job->status, ['failed', 'cancelled', 'expired'], true)) {
            $this->button('retry', 'Retry job', ['id' => (string) $job->id]);
        }
        $attempts = [];
        foreach ($this->plugin->store->attemptsForJob($job->id) as $a) {
            $attempts[] = [
                'attempt_no' => $a['attempt_no'],
                'outcome' => $a['outcome'],
                'resource_key' => $a['resource_key'],
                'cost' => \IndianOpinion\GenQueue\Money::format((int) $a['cost_nanos']),
                'duration_ms' => $a['duration_ms'],
                'started_at' => $a['started_at'] === null ? '' : Days::utc((int) $a['started_at']),
                'error' => $a['error'],
                'log' => $a['log'],
            ];
        }
        $columns = [
            'attempt_no' => '#',
            'outcome' => 'Outcome',
            'resource_key' => 'Resource',
            'cost' => 'Cost',
            'duration_ms' => 'ms',
            'started_at' => 'Started (UTC)',
            'error' => 'Error',
            'log' => 'Log',
        ];
        $this->listTable('Attempts', $columns, $attempts);
    }

    /**
     * @param array<string, string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function listTable(string $title, array $columns, array $rows): void
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($rows === []) {
            echo '<p>None.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach (array_keys($columns) as $key) {
                $value = $row[$key] ?? '';
                echo '<td>' . esc_html(is_scalar($value) ? (string) $value : '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function hidden(string $op): void
    {
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="op" value="' . esc_attr($op) . '">';
        wp_nonce_field(self::NONCE);
    }

    /** @param array<string, string> $fields */
    private function button(string $op, string $label, array $fields = []): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 .5em .5em 0">';
        $this->hidden($op);
        foreach ($fields as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        echo '<button class="button">' . esc_html($label) . '</button></form>';
    }
}
