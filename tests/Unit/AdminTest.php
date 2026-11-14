<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Admin\Notices;
use IndianOpinion\GenQueue\Admin\StatusPage;
use IndianOpinion\GenQueue\PermanentException;
use IndianOpinion\GenQueue\Tests\Stubs\WpDieException;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class AdminTest extends TestCase
{
    private function page(): StatusPage
    {
        return new StatusPage($this->plugin);
    }

    /** Test 73, section 8. */
    public function testActionsRejectAMissingNonce(): void
    {
        $this->expectException(WpDieException::class);
        $this->page()->handle(['op' => 'pause']);
    }

    /** Test 73, section 8. */
    public function testActionsRejectAUserWithoutTheCapability(): void
    {
        WpState::$userCan = false;
        try {
            $this->page()->handle(['op' => 'pause', '_wpnonce' => WpState::$validNonce]);
            $this->fail('Action allowed');
        } catch (WpDieException $e) {
            $this->assertTrue(gen_queue_is_enabled());
        }
        $this->expectException(WpDieException::class);
        $this->page()->render();
    }

    public function testCapabilityIsFilterable(): void
    {
        add_filter('gen_queue_capability', static fn (): string => 'edit_posts');
        $this->assertSame('edit_posts', StatusPage::capability());
    }

    /** Test 73: the actions work with a nonce and the capability. */
    public function testActionsWork(): void
    {
        $page = $this->page();
        $nonce = ['_wpnonce' => WpState::$validNonce];
        $this->type('demo.task');
        $page->handle(['op' => 'cap', 'cap' => '3.5', 'currency' => 'GBP'] + $nonce);
        $this->assertSame(3500000000, $this->plugin->controls->capNanos());
        $page->handle(['op' => 'pause'] + $nonce);
        $this->assertFalse(gen_queue_is_enabled());
        $page->handle(['op' => 'resume'] + $nonce);
        $page->handle(['op' => 'pause', 'type' => 'demo.task'] + $nonce);
        $this->assertFalse(gen_queue_is_enabled('demo.task'));
        $id = gen_queue_enqueue('demo.task', []);
        $this->assertSame(sprintf('Job %d cancelled.', $id), $page->handle(['op' => 'cancel', 'id' => (string) $id] + $nonce));
        $this->assertSame(sprintf('Job %d queued again.', $id), $page->handle(['op' => 'retry', 'id' => (string) $id] + $nonce));
        $this->assertStringStartsWith('Not saved', $page->handle(['op' => 'cap', 'cap' => '-4'] + $nonce));
        $this->assertStringStartsWith('Not saved', $page->handle(['op' => 'pause', 'type' => '<b>'] + $nonce));
        $page->handle(['op' => 'settings', 'account_hold' => '120'] + $nonce);
        $this->assertSame(120, $this->plugin->controls->setting('account_hold'));
        $this->plugin->breakers->hold('acct', $this->store->clock, 'x');
        $page->handle(['op' => 'release', 'group' => 'acct'] + $nonce);
        $this->assertSame([], $this->plugin->breakers->activeHolds($this->store->clock));
        $this->assertSame('Cap removed. Nothing runs until a cap is set.', $page->handle(['op' => 'cap', 'cap' => ''] + $nonce));
        $this->assertSame('Unknown action.', $page->handle(['op' => 'explode'] + $nonce));
    }

    /** Test 73: output is escaped. */
    public function testOutputIsEscaped(): void
    {
        $this->setCap('1');
        $this->type('demo.task', static function (): never {
            throw new PermanentException('<script>alert(1)</script>');
        });
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        ob_start();
        $this->page()->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Generation queue', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Test 73: payloads hidden by default, shown escaped through the filter. */
    public function testPayloadsAreHiddenByDefault(): void
    {
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', ['prompt' => '<i>private text</i>']);
        $_GET['job'] = (string) $id;
        try {
            ob_start();
            $this->page()->render();
            $hidden = (string) ob_get_clean();
            add_filter('gen_queue_show_payload', static fn (): bool => true);
            ob_start();
            $this->page()->render();
            $shown = (string) ob_get_clean();
        } finally {
            unset($_GET['job']);
        }
        $this->assertStringNotContainsString('private text', $hidden);
        $this->assertStringContainsString('sha256', $hidden);
        $this->assertStringContainsString('private text', $shown);
        $this->assertStringNotContainsString('<i>', $shown);
    }

    public function testNotices(): void
    {
        $notices = new Notices($this->plugin);
        $messages = $notices->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('no daily cap', $messages[0]);

        $this->setCap('1');
        gen_queue_pause();
        $this->plugin->breakers->hold('acct', $this->store->clock, 'balance empty');
        $this->plugin->controls->setJsonOption('gen_queue_last_tick', ['at' => $this->store->clock - 700, 'stop_reason' => 'empty']);
        $messages = $notices->messages();
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('switched off', $messages[0]);
        $this->assertStringContainsString('balance empty', $messages[1]);
        $this->assertStringContainsString('not ticked', $messages[2]);

        ob_start();
        $notices->render();
        $this->assertStringContainsString('notice-warning', (string) ob_get_clean());
    }
}
