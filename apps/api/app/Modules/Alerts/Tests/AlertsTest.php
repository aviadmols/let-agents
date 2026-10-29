<?php

namespace App\Modules\Alerts\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Alerts\Actions\FindProblems;
use App\Modules\Alerts\Mail\SystemAlertMail;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
        $this->shop = Shop::factory()->create(['name' => 'אבני חן']);
        User::factory()->create(['email' => 'team@lets.test', 'is_operator' => true]);
        User::factory()->create(['email' => 'owner@shop.test', 'is_operator' => false]);
        Settings::set('ai.monthly_spend_cap_usd', 100);
        Settings::set('ai.shop_monthly_cap_usd', 1000, $this->shop->id);
        Mail::fake();
    }

    public function test_a_budget_near_its_end_is_told_once_and_a_spent_one_again_with_what_stops(): void
    {
        $this->record('taxonomy.assign', cost: 70, shopId: $this->shop->id);
        $this->sweep();
        Mail::assertNothingSent(); // 70%: under the warning

        $this->record('taxonomy.assign', cost: 15, shopId: null);
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 1);
        Mail::assertSent(SystemAlertMail::class, function (SystemAlertMail $mail): bool {
            $this->assertTrue($mail->hasTo('team@lets.test'));
            $this->assertFalse($mail->hasTo('owner@shop.test'), 'operators only');
            $this->assertCount(1, $mail->problems);
            $this->assertStringContainsString('85%', $mail->problems[0]->title);
            $this->assertStringContainsString('85.00', $mail->problems[0]->what);
            $this->assertFalse($mail->problems[0]->urgent);

            return true;
        });

        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 1); // told once

        // a call the cap refuses, though the sum is still under it: told at once by the next sweep, as urgent
        try {
            app(SpendGuard::class)->assertCanSpend(20);
            $this->fail('the call should have been refused');
        } catch (SpendCapReached) {
        }
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 2);
        $mail = Mail::sent(SystemAlertMail::class)->last();
        $this->assertTrue($mail->problems[0]->urgent);
        $this->assertStringContainsString('נגמר', $mail->problems[0]->title);
        $this->assertStringContainsString('1.11', $mail->problems[0]->next, 'the block lifts on the first of next month');
        $this->assertStringStartsWith('דחוף', $mail->envelope()->subject);
        $this->assertStringContainsString('התקציב', $mail->render());

        // a raised cap is a new cap: nearing it is news again
        Settings::set('ai.monthly_spend_cap_usd', 200);
        $this->record('taxonomy.assign', cost: 80, shopId: null);
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 3);
        $this->assertStringContainsString('82%', Mail::sent(SystemAlertMail::class)->last()->problems[0]->title);

        // the shop's own allowance, refused in its name (shoppers still answered: the shop's cap does not stop them)
        Settings::set('ai.shop_monthly_cap_usd', 75, $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            try {
                app(SpendGuard::class)->assertCanSpend(10);
                $this->fail('the shop\'s call should have been refused');
            } catch (SpendCapReached) {
            }
        });
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 4);
        $shop = Mail::sent(SystemAlertMail::class)->last()->problems[0];
        $this->assertStringContainsString('אבני חן', $shop->title);
        $this->assertTrue($shop->urgent);
        $this->assertStringContainsString('עדיין נענות', $shop->what);
    }

    public function test_work_failing_again_and_again_is_told_with_its_reason_and_a_refused_call_is_not_a_failure(): void
    {
        foreach (range(1, 3) as $i) {
            $this->record('assistant.answer_site', error: 'Request rate limit has been exceeded.', shopId: $this->shop->id);
        }
        $this->record('assistant.answer_site', error: 'הקריאה למודל נחסמה: החודש הוצאו 102.00 דולר, ההערכה לקריאה הזו 0.07 דולר, והתקרה החודשית היא 100.00 דולר.', shopId: $this->shop->id);
        $this->record('assistant.answer_site', error: 'הקריאה למודל נחסמה: החודש הוצאו 102.00 דולר, ההערכה לקריאה הזו 0.07 דולר, והתקרה החודשית היא 100.00 דולר.', shopId: $this->shop->id);
        $this->record('search.write_families', error: 'not_json', shopId: $this->shop->id); // once: not yet a pattern
        $this->record('taxonomy.read_rule', error: 'no_key'); // a missing key: told at once
        $this->record('catalog.read_pages', error: 'old', shopId: $this->shop->id, at: now()->subHours(3));

        $problems = app(FindProblems::class)->handle();
        $this->assertSame(['runs:assistant.answer_site:rate_limited', 'runs:taxonomy.read_rule:no_key'], array_map(fn ($p): string => $p->key, $problems));
        $this->assertStringContainsString('3', $problems[0]->title);
        $this->assertStringContainsString('אבני חן', $problems[0]->what);
        $this->assertStringContainsString('להאט', $problems[0]->what);
        $this->assertStringContainsString('מסך ספקי ה-AI', $problems[1]->next);

        // background work that stopped, and runs that began yesterday and never ended
        DB::table('failed_jobs')->insert(['uuid' => 'a1', 'connection' => 'database', 'queue' => 'default', 'failed_at' => now()->subMinutes(5),
            'payload' => json_encode(['displayName' => 'App\\Modules\\Search\\Jobs\\ScanPicturesJob']),
            'exception' => "Illuminate\\Queue\\MaxAttemptsExceededException: App\\Modules\\Search\\Jobs\\ScanPicturesJob has been attempted too many times.\n#0 trace"]);
        $this->record('search.build_index', status: 'running', at: now()->subHours(8), shopId: $this->shop->id);
        $this->record('retrieval.caption_images', status: 'running', at: now()->subDays(9), shopId: $this->shop->id); // left from long ago

        $keys = array_map(fn ($p): string => $p->key, app(FindProblems::class)->handle());
        $this->assertContains('job:ScanPicturesJob', $keys);
        $this->assertContains('stuck:search.build_index', $keys);
        $this->assertNotContains('stuck:retrieval.caption_images', $keys);

        $this->sweep();
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 1);
        $this->assertCount(4, Mail::sent(SystemAlertMail::class)->first()->problems);

        // a day on, what still fails is told again
        $this->travel(25)->hours();
        foreach (range(1, 3) as $i) {
            $this->record('assistant.answer_site', error: 'Request rate limit has been exceeded.', shopId: $this->shop->id);
        }
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 2);
    }

    public function test_a_queue_nobody_takes_from_and_a_store_not_synced_are_urgent(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(45)->getTimestamp(), 'created_at' => now()->subMinutes(45)->getTimestamp()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->addMinutes(10)->getTimestamp(), 'created_at' => now()->getTimestamp()]); // delayed: not late

        $this->record('catalog.sync', status: 'succeeded', at: now()->subHours(40), shopId: $this->shop->id);
        $this->record('catalog.sync', error: 'ConnectionException: cURL error 28: Operation timed out', at: now()->subHours(16), shopId: $this->shop->id);
        Shop::factory()->create(['name' => 'חנות בהקמה']); // never synced: still being set up
        $fine = Shop::factory()->create(['name' => 'חנות תקינה']);
        $this->record('catalog.sync', status: 'succeeded', at: now()->subHours(20), shopId: $fine->id);
        $paused = Shop::factory()->create(['name' => 'חנות מושהית', 'status' => ShopStatus::Paused]);
        $this->record('catalog.sync', status: 'succeeded', at: now()->subDays(5), shopId: $paused->id);

        $problems = app(FindProblems::class)->handle();
        $this->assertSame(['queue:stalled', 'sync:'.$this->shop->id.':'.now()->subHours(40)->getTimestamp()], array_map(fn ($p): string => $p->key, $problems));
        $this->assertTrue($problems[0]->urgent && $problems[1]->urgent);
        $this->assertStringContainsString('1 עבודות', $problems[0]->what);
        $this->assertStringContainsString('cURL error 28', $problems[1]->what);
        $this->assertStringContainsString('40', $problems[1]->what);
    }

    public function test_an_email_that_could_not_be_sent_is_tried_again_and_the_switch_stops_them_all(): void
    {
        $this->record('taxonomy.read_rule', error: 'no_key');
        // a mail server that refuses: nothing is remembered as told
        config(['mail.mailers.down' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 2], 'mail.default' => 'down']);
        Mail::swap(new MailManager(app()));
        $this->sweep();

        Mail::fake();
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 1);

        Features::override('alerts.email', false);
        $this->record('taxonomy.assign', error: 'no_key');
        $this->sweep();
        Mail::assertSent(SystemAlertMail::class, 1);
    }

    private function sweep(): void
    {
        $this->artisan('alerts:sweep')->assertSuccessful();
    }

    private function record(string $action, ?float $cost = null, ?string $error = null, ?string $shopId = null, string $status = '', ?Carbon $at = null): void
    {
        $at ??= now()->subMinutes(10);
        $status = $status !== '' ? $status : ($error !== null ? 'failed' : 'succeeded');
        Run::query()->create([
            'shop_id' => $shopId, 'agent' => 'test', 'action' => $action, 'status' => $status, 'trigger' => 'system',
            'cost_usd' => $cost, 'error' => $error, 'provider' => $cost === null ? null : 'openai',
            'started_at' => $at, 'finished_at' => $status === 'running' ? null : $at->copy()->addMinute(), 'created_at' => $at,
        ]);
    }
}
