<?php

namespace App\Services\Admin;

use App\Enums\EmailAccountStatus;
use App\Enums\InvoiceStatus;
use App\Models\EmailAccount;
use App\Models\Invoice;
use App\Models\SendingDomain;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Compliance\BlacklistChecker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is the platform healthy? Database, cron, queues, cache, disk, config and
 * the mail estate, each as ok / warning / danger with a sentence.
 */
class SystemHealth
{
    public const OK = 'success';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->scheduler(),
            ...$this->queue(),
            $this->cache(),
            $this->storage(),
            $this->debug(),
            $this->mailboxes(),
            $this->blacklists(),
            $this->billing(),
            ['label' => 'Versions', 'status' => self::OK, 'detail' => 'PHP '.PHP_VERSION.', Laravel '.app()->version().'.'],
        ];
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function database(): array
    {
        try {
            DB::select('select 1');

            return $this->check('Database', self::OK, DB::connection()->getDriverName().', connected.');
        } catch (Throwable $exception) {
            return $this->check('Database', self::DANGER, 'Can\'t connect: '.$exception->getMessage());
        }
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function scheduler(): array
    {
        $beat = Cache::get('system:scheduler_heartbeat');

        if (! $beat) {
            return $this->check('Cron (scheduler)', self::DANGER, 'No heartbeat yet. Add the every-minute cron job (php artisan schedule:run).');
        }

        $age = CarbonImmutable::parse($beat)->diffInMinutes(now(), true);

        return $age <= 3
            ? $this->check('Cron (scheduler)', self::OK, 'Ran '.CarbonImmutable::parse($beat)->diffForHumans().'.')
            : $this->check('Cron (scheduler)', self::DANGER, 'Last ran '.CarbonImmutable::parse($beat)->diffForHumans().'. Campaigns aren\'t sending: check the cron job.');
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    protected function queue(): array
    {
        $driver = (string) config('queue.default');
        $checks = [];

        if ($driver === 'database' && Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->whereNull('reserved_at')->count();
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
            $waiting = $oldest ? (int) floor((now()->getTimestamp() - (int) $oldest) / 60) : 0;

            $checks[] = $waiting > 5
                ? $this->check('Queue', self::DANGER, "{$pending} jobs waiting, the oldest for {$waiting} minutes. The worker isn't keeping up or isn't running.")
                : $this->check('Queue', self::OK, "database driver, {$pending} jobs waiting.");
        } else {
            $checks[] = $this->check('Queue', self::OK, "{$driver} driver (see Horizon for details).");
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            $checks[] = $this->check('Failed jobs (24h)', $failed > 0 ? self::WARNING : self::OK, $failed > 0 ? "{$failed} failed. Inspect with php artisan queue:failed." : 'None.');
        }

        return $checks;
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function cache(): array
    {
        try {
            Cache::put('system:health-probe', 'ok', 60);

            return Cache::get('system:health-probe') === 'ok'
                ? $this->check('Cache', self::OK, config('cache.default').' driver, working.')
                : $this->check('Cache', self::DANGER, 'Values written to the cache can\'t be read back.');
        } catch (Throwable $exception) {
            return $this->check('Cache', self::DANGER, $exception->getMessage());
        }
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function storage(): array
    {
        if (! is_writable(storage_path('app')) || ! is_writable(storage_path('logs'))) {
            return $this->check('Storage', self::DANGER, 'storage/ isn\'t writable by PHP. Fix the folder permissions.');
        }

        $free = @disk_free_space(storage_path());

        if ($free !== false && $free < 1024 ** 3) {
            return $this->check('Storage', self::WARNING, 'Less than 1 GB of disk space left ('.round($free / 1024 ** 2).' MB).');
        }

        return $this->check('Storage', self::OK, 'Writable'.($free !== false ? ', '.round($free / 1024 ** 3, 1).' GB free.' : '.'));
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function debug(): array
    {
        return app()->isProduction() && config('app.debug')
            ? $this->check('Debug mode', self::DANGER, 'APP_DEBUG is on in production: error pages can leak secrets. Set APP_DEBUG=false.')
            : $this->check('Debug mode', self::OK, config('app.debug') ? 'On (not production).' : 'Off.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function mailboxes(): array
    {
        $errors = EmailAccount::query()->where('status', EmailAccountStatus::Error->value)->count();
        $autoPaused = EmailAccount::query()->whereNotNull('paused_reason')->count();
        $total = EmailAccount::query()->count();

        return $this->check(
            'Mailboxes',
            $errors > 0 || $autoPaused > 0 ? self::WARNING : self::OK,
            "{$total} connected, {$errors} with connection errors, {$autoPaused} auto-paused for bounces or complaints.",
        );
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function blacklists(): array
    {
        $listed = SendingDomain::query()->where('blacklist_status', BlacklistChecker::LISTED)->pluck('name');

        return $listed->isEmpty()
            ? $this->check('Blacklists', self::OK, 'No sending domain is listed.')
            : $this->check('Blacklists', self::DANGER, 'Listed: '.$listed->take(10)->implode(', ').($listed->count() > 10 ? '…' : '').'.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function billing(): array
    {
        if (! config('billing.enabled')) {
            return $this->check('Billing', self::OK, 'Off (self-hosted mode: no plans or limits).');
        }

        $gateways = app(GatewayManager::class)->options();
        $waiting = Invoice::query()->where('status', InvoiceStatus::Open->value)->where('gateway', 'manual')->count();

        if ($gateways === []) {
            return $this->check('Billing', self::DANGER, 'On, but no payment method is configured: customers can\'t pay.');
        }

        return $this->check('Billing', $waiting > 0 ? self::WARNING : self::OK,
            'Payment methods: '.implode(', ', $gateways).'.'.($waiting > 0 ? " {$waiting} bank transfers to confirm (Invoices)." : ''));
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function check(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
