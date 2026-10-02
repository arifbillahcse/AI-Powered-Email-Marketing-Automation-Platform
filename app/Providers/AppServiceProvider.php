<?php

namespace App\Providers;

use App\Services\Dns\DnsResolver;
use App\Services\Dns\DomainHealthChecker;
use App\Services\Dns\NativeDnsResolver;
use App\Services\Dns\TrackingDomainVerifier;
use App\Services\Mail\HostGuard;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Mail\MailboxTransportFactory;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);

        $this->app->bind(HostGuard::class, fn (): HostGuard => new HostGuard(
            allowPrivateHosts: (bool) config('outreach.mailboxes.allow_private_hosts'),
        ));

        $this->app->bind(MailboxTransportFactory::class, fn (Application $app): MailboxTransportFactory => new MailboxTransportFactory(
            $app->make(HostGuard::class),
            timeout: (int) config('outreach.mailboxes.connect_timeout'),
        ));

        $this->app->bind(ImapConnector::class, fn (): ImapConnector => new ImapConnector(
            timeout: (int) config('outreach.mailboxes.connect_timeout'),
        ));

        $this->app->bind(DomainHealthChecker::class, fn (Application $app): DomainHealthChecker => new DomainHealthChecker(
            $app->make(DnsResolver::class),
            dkimSelectors: config('outreach.dns.dkim_selectors', []),
        ));

        $this->app->bind(TrackingDomainVerifier::class, fn (Application $app): TrackingDomainVerifier => new TrackingDomainVerifier(
            $app->make(DnsResolver::class),
            cnameTarget: (string) config('outreach.tracking.cname_target'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as exceptions outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
