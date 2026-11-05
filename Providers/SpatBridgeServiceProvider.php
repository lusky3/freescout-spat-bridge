<?php

namespace Modules\SpatBridge\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Modules\SpatBridge\Console\PruneEventsCommand;
use Modules\SpatBridge\Features\ETransfers\ETransfersFeature;
use Modules\SpatBridge\Features\FeatureRegistry;
use Modules\SpatBridge\Features\PlayerCard\PlayerCardFeature;
use Modules\SpatBridge\Features\Waitlist\WaitlistFeature;
use Modules\SpatBridge\Services\ActionPermissions;
use Modules\SpatBridge\Services\ClientResult;
use Modules\SpatBridge\Services\DatabaseEventStore;
use Modules\SpatBridge\Services\EventCatalog;
use Modules\SpatBridge\Services\EventSettings;
use Modules\SpatBridge\Services\EventStoreInterface;
use Modules\SpatBridge\Services\LegacyWaitlistCheck;
use Modules\SpatBridge\Services\MailboxScope;
use Modules\SpatBridge\Services\SidebarBuilder;
use Modules\SpatBridge\Services\SpatClient;
use Modules\SpatBridge\Services\TagService;
use Modules\SpatBridge\Services\TicketCreator;
use Modules\SpatBridge\Services\Support\CustomerEmails;
use Modules\SpatBridge\Services\Support\LaravelOptionStore;
use Modules\SpatBridge\Services\Support\OptionBool;
use Modules\SpatBridge\Services\Support\OptionStoreInterface;
use Psr\Log\LoggerInterface;

class SpatBridgeServiceProvider extends ServiceProvider
{
    // @codeCoverageIgnoreStart
    //
    // register()/boot() wire this module into FreeScout's real DI container,
    // Blade view resolver and router. Faking those well enough to unit-test
    // meaningfully isn't worth building; the wiring is integration-tested
    // against a real, running FreeScout instance by the e2e environment.
    public function register()
    {
        $this->app->bind(OptionStoreInterface::class, LaravelOptionStore::class);

        $this->app->singleton(FeatureRegistry::class, function () {
            return new FeatureRegistry();
        });

        $this->app->bind(LoggerInterface::class, function ($app) {
            return $app['log'];
        });

        $this->app->bind(\GuzzleHttp\ClientInterface::class, function () {
            return new Client();
        });

        $this->app->bind(EventStoreInterface::class, DatabaseEventStore::class);

        $this->app->bind(TagService::class, function ($app) {
            return new TagService(null, TagService::TAG_CLASS, $app->make(LoggerInterface::class));
        });

        $this->commands([PruneEventsCommand::class]);
    }

    public function boot()
    {
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/spatbridge';
        }, \Config::get('view.paths')), [__DIR__ . '/../Resources/views']), 'spatbridge');

        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->app->make(FeatureRegistry::class)->register(new PlayerCardFeature());
        $this->app->make(FeatureRegistry::class)->register(new ETransfersFeature());
        $this->app->make(FeatureRegistry::class)->register(new WaitlistFeature());

        $this->registerSettingsSection();
        $this->registerSidebarPanel();
        $this->registerEvents();

        // Feeds Resources/views/settings/index.blade.php so the view stays a
        // pure template.
        \View::composer('spatbridge::settings.index', function ($view) {
            $options = $this->app->make(OptionStoreInterface::class);
            $registry = $this->app->make(FeatureRegistry::class);

            $permissions = new ActionPermissions($options);

            $features = [];
            foreach ($registry->all() as $feature) {
                $features[$feature->key()] = [
                    'key' => $feature->key(),
                    'label' => $feature->label(),
                    'providerKeys' => $feature->providerKeys(),
                    'actorMode' => $permissions->mode($feature->key()),
                    'actorUserIds' => $permissions->selectedUserIds($feature->key()),
                    'enabled' => OptionBool::from(
                        $options->get('spatbridge.features.' . $feature->key() . '.enabled', true),
                        true
                    ),
                ];
            }

            // Saved order first, then anything not yet in it.
            $savedOrder = $options->get('spatbridge.feature_order', []);
            $featureOrder = [];
            foreach (is_array($savedOrder) ? $savedOrder : [] as $key) {
                if (is_string($key) && isset($features[$key])) {
                    $featureOrder[$key] = $features[$key]['label'];
                }
            }
            foreach ($features as $key => $feature) {
                $featureOrder[$key] = $feature['label'];
            }

            $mailboxes = [];
            foreach (\App\Mailbox::all() as $mailbox) {
                $mailboxes[] = ['id' => (int) $mailbox->id, 'name' => (string) $mailbox->name];
            }

            $users = [];
            if (class_exists(\App\User::class)) {
                $activeUsers = \App\User::nonDeleted()->where('status', 1)->where('type', 1)
                    ->get(['id', 'first_name', 'last_name', 'email']);
                foreach ($activeUsers as $user) {
                    $name = trim($user->first_name . ' ' . $user->last_name);
                    $users[] = [
                        'id' => (int) $user->id,
                        'name' => $name === '' ? (string) $user->email : $name . ' (' . $user->email . ')',
                    ];
                }
            }

            $selected = $options->get('spatbridge.mailboxes', []);

            $view->with('wpBaseUrl', (string) $options->get('spatbridge.wp_base_url', ''));
            $view->with('hasSecret', (string) $options->get('spatbridge.shared_secret', '') !== '');
            $view->with(
                'layoutDefault',
                $options->get('spatbridge.layout_default', 'stacked') === 'tabbed' ? 'tabbed' : 'stacked'
            );
            $view->with('allowSwitch', OptionBool::from($options->get('spatbridge.allow_agent_switch', true), true));
            $view->with('users', $users);
            $view->with('mailboxes', $mailboxes);
            $view->with('selectedMailboxes', is_array($selected) ? array_map('intval', $selected) : []);
            $view->with('features', array_values($features));
            $view->with('featureOrder', $featureOrder);

            $view->with('oldWaitlistActive', (new LegacyWaitlistCheck())->duplicatePanels(
                isset($features['waitlist']) && $features['waitlist']['enabled']
            ));
            $view->with('eventsUrl', route('spatbridge.events'));
            $view->with('events', (new EventSettings(
                $options,
                new EventCatalog(),
                $this->app->make(TagService::class),
                $this->app->make(EventStoreInterface::class),
                $this->app->make(LoggerInterface::class)
            ))->build());
        });
    }

    /**
     * Same three-filter shape as ModuleManager: FreeScout's
     * SettingsController@view 404s if settings.section_settings returns an
     * empty array for the section, so we return at least one key.
     */
    protected function registerSettingsSection(): void
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['spatbridge'] = [
                'title' => __('ARL League'),
                'icon' => 'flag',
                'view' => 'spatbridge::settings',
                'order' => 210,
            ];

            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'spatbridge') {
                return $settings;
            }

            $options = $this->app->make(OptionStoreInterface::class);
            $settings['spatbridge.wp_base_url'] = $options->get('spatbridge.wp_base_url', '');

            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'spatbridge') {
                return $view;
            }

            return 'spatbridge::settings.index';
        }, 20, 2);
    }

    /**
     * Opening a ticket for a WordPress event doesn't mail the customer (an
     * agent's later reply does go out), and the event log is pruned daily.
     */
    protected function registerEvents(): void
    {
        \Eventy::addFilter('conversation.skip_send_reply_to_customer', function ($skip, $conversation, $replies) {
            return TicketCreator::skipSend($skip, $conversation, $replies);
        }, 20, 3);

        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('spatbridge:prune-events')->daily();

            return $schedule;
        });
    }

    /**
     * conversation.after_prev_convs fires as ($customer, $conversation,
     * $mailbox) and the callback must echo its own output. Fail open: a bug
     * here must never break the conversation page.
     */
    protected function registerSidebarPanel(): void
    {
        // Same filter and path convention FreeScout's layout and its modules
        // use: \Module::getPublicPath(alias) is the module's public symlink.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            // No ?v= suffix: Minify resolves these as files on disk, and its
            // build name already changes with each file's mtime.
            $javascripts[] = \Module::getPublicPath('spatbridge') . '/js/sidebar.js';
            $javascripts[] = \Module::getPublicPath('spatbridge') . '/js/actions.js';

            return $javascripts;
        });

        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            try {
                if (!is_object($mailbox) || !$this->shouldShowForMailbox((int) $mailbox->id)) {
                    return;
                }

                $emails = CustomerEmails::of($customer, $conversation);
                if ($emails === []) {
                    return;
                }

                $options = $this->app->make(OptionStoreInterface::class);
                $builder = new SidebarBuilder(
                    $this->app->make(LoggerInterface::class),
                    new ActionPermissions($options)
                );
                $viewModel = $builder->build(
                    $this->cachedResult($emails),
                    $this->app->make(FeatureRegistry::class)->enabled($options),
                    auth()->user()
                );
                if ($viewModel['state'] === 'empty') {
                    return;
                }

                $viewModel['conversationId'] = is_object($conversation) && isset($conversation->id)
                    ? (int) $conversation->id
                    : 0;

                $viewModel['layoutDefault'] = $options->get('spatbridge.layout_default', 'stacked') === 'tabbed'
                    ? 'tabbed'
                    : 'stacked';
                $viewModel['allowSwitch'] = OptionBool::from(
                    $options->get('spatbridge.allow_agent_switch', true),
                    true
                );

                echo view('spatbridge::sidebar', $viewModel)->render();
            } catch (\Throwable $e) {
                \Log::error('[SpatBridge] sidebar render failed: ' . $e->getMessage());
            }
        }, 20, 3);
    }
    // @codeCoverageIgnoreEnd

    private function shouldShowForMailbox(int $mailboxId): bool
    {
        return MailboxScope::allows($this->app->make(OptionStoreInterface::class), $mailboxId);
    }

    /**
     * One-minute cache (Laravel 5.5 TTLs are minutes) of the decoded data
     * only: a layout switch never refetches, and a WordPress outage isn't
     * pinned for a minute after recovery because failures aren't cached.
     * Keyed by the address list, which ActionController clears after an
     * action.
     *
     * @param string[] $emails From CustomerEmails::of().
     */
    private function cachedResult(array $emails): ClientResult
    {
        $cacheKey = CustomerEmails::cacheKey($emails);

        $cached = \Cache::get($cacheKey);
        if (is_array($cached)) {
            return ClientResult::success(200, $cached, 0);
        }

        $result = $this->app->make(SpatClient::class)->customer($emails);
        if ($result->ok() && is_array($result->data())) {
            \Cache::put($cacheKey, $result->data(), 1);
        }

        return $result;
    }
}
