<?php

namespace Grav\Plugin\Console;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\OAuth2\Console\Settings;
use Grav\Plugin\Login\OAuth2\OAuth2;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * bin/plugin login-oauth2 enforce-oauth2 on|off|status: the "Enforce OAuth2" setting of the site and the admin.
 */
class OAuth2EnforceCommand extends ConsoleCommand
{
    protected function configure()
    {
        $this
            ->setName('enforce-oauth2')
            ->addArgument('action', InputArgument::REQUIRED, 'on, off or status')
            ->addOption('site', null, InputOption::VALUE_NONE, 'Only the site')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Only the admin')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Turn it on even if it wouldn\'t apply yet (no active provider) or a setting it requires off is on')
            ->addOption('sign-out', null, InputOption::VALUE_NONE, 'With on: sign out the users who could have logged in without OAuth2 (everyone for the site, accounts with admin access for the admin)')
            ->setDescription("Turns 'Enforce OAuth2' on or off, e.g. when locked out")
            ->setHelp(
                "The <info>enforce-oauth2</info> command turns 'Enforce OAuth2' (only OAuth2 logins) on or off, for the site and the admin.\n\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 off</info>            locked out: password logins work again on both sides\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --admin</info>     only OAuth2 logins in the admin\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 on --sign-out</info>  and sign everyone out, to log in again with OAuth2\n" .
                "  <info>bin/plugin login-oauth2 enforce-oauth2 status</info>\n\n" .
                "<info>off</info> turns it off in the main configuration and in every environment that turns it on (or only in --env).\n" .
                "<info>on</info> only turns it on where it applies (an active provider) and the settings that let users in without OAuth2\n" .
                "are off: the Login plugin's 'Remember me', and for the admin the API plugin's Session Authentication, in the main\n" .
                "configuration and every environment (or only in --env). A side chosen with --site or --admin is refused otherwise,\n" .
                "of both sides the other one is left as it is. --force turns it on anyway.\n" .
                "<info>--sign-out</info> then ends the open sessions, 'remember me' cookies and Admin2 tokens of everyone if the site is\n" .
                "on, else of the accounts with admin access, with the Login plugin's sign-out (Login 3.13.0 or newer). Also if it was on\n" .
                "already. Not with a change of one environment's file (--env): the sign-out can't be limited to one environment.\n" .
                "<info>status</info> shows both sides, why one doesn't apply, and those settings."
            );
    }

    protected function serve()
    {
        include __DIR__ . '/../vendor/autoload.php';

        $io = new SymfonyStyle($this->input, $this->output);
        $action = strtolower((string)$this->input->getArgument('action'));
        if (!in_array($action, ['on', 'off', 'status'], true)) {
            $io->error("Unknown action '{$action}': use on, off or status.");

            return 1;
        }

        if ($action === 'off') {
            // The way back in when locked out: without the plugins, so it also works if one of them fails.
            $this->initializeGrav();
        } else {
            // With the plugins: satellite plugins (e.g. login-oauth2-extras) add their providers.
            try {
                $this->initializePlugins();
            } catch (Throwable $e) {
                $this->initializeGrav();
                $io->warning('The plugins could not be initialized, providers of other plugins are missing: ' . $e->getMessage());
            }
        }

        $settings = new Settings(Grav::instance(), $this->input);
        $sides = Settings::sides($this->input);
        if ($error = $settings->missingEnvironment($this->input)) {
            $io->error($error);

            return 1;
        }

        if ($action !== 'on' && $this->input->getOption('sign-out')) {
            $io->note("--sign-out only goes with 'on': nobody is signed out.");
        }
        // Accounts and Login's sign-out are shared by all environments: it can't sign out one environment's users only.
        if ($action === 'on' && $this->input->getOption('sign-out') && !$settings->reachesEveryEnvironment()) {
            $io->error([
                "--sign-out can't sign out the users of one environment only: accounts and the Login plugin's sign-out are shared by all of them, also those where 'Enforce OAuth2' stays off. Nothing changed.",
                'Turn it on without --sign-out, then sign users out with: bin/plugin login sign-out',
            ]);

            return 1;
        }
        if ($action === 'status') {
            return $this->status($io, $settings, $sides);
        }

        return $action === 'off' ? $this->off($io, $settings, $sides) : $this->on($io, $settings, $sides);
    }

    /**
     * @param string[] $sides
     */
    protected function status(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $files = $settings->files();
        foreach ($sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            $on = (bool)$settings->get($key);
            $why = $on ? $settings->notApplying($side) : null;
            $io->text(sprintf('%s: <info>%s</info>%s', ucfirst($side), $on ? 'on' : 'off', $why ? ", but doesn't apply: {$why}" : ''));
            foreach ($settings->requirements($side) as $requirement => $where) {
                $io->text(sprintf('  %s: %s', $requirement, Settings::requirementState($where, $on)));
            }

            foreach ($files as $file) {
                $value = $settings->read($file, $key);
                if ($value !== null) {
                    $io->text(sprintf('  %s: %s', Folder::getRelativePath($file), $value ? 'on' : 'off'));
                }
            }
        }

        return 0;
    }

    /**
     * Turns it off in every config file that turns it on, or only in the environment's file with --env. Without --env
     * it then checks the files again: the main one (with the plugin's default) and those of the environments.
     *
     * @param string[] $sides
     */
    protected function off(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $env = $this->input->hasParameterOption('--env');
        $main = $settings->mainFile();
        $changes = [];
        foreach ($env ? [$settings->file()] : array_unique(array_merge([$main], $settings->files())) as $file) {
            $values = [];
            foreach ($sides as $side) {
                $key = Settings::key($side, 'enforce_oauth2');
                // With --env also where only the main configuration turns it on: the environment's file overrides it.
                // In the main file also where only the plugin's default turns it on (an edited login-oauth2.yaml).
                if ($env ? $settings->get($key) : ($file === $main ? $settings->mainValue($key) : $settings->read($file, $key))) {
                    $values[$key] = false;
                }
            }
            if ($values && ($change = $settings->save($file, $values, 'turned Enforce OAuth2 off'))) {
                $changes[] = $change;
            }
        }

        foreach ($changes as $change) {
            $io->text('  ' . $change . ' => off');
        }
        $stillOn = [];
        foreach ($env ? [] : $sides as $side) {
            $key = Settings::key($side, 'enforce_oauth2');
            foreach ($settings->stillOn($key) as $file) {
                $stillOn[] = "{$file}: {$key}";
            }
        }
        if ($stillOn) {
            $io->error(array_merge(["'Enforce OAuth2' is still on in:"], $stillOn, ["Set it to false there by hand, then check with 'bin/plugin login-oauth2 enforce-oauth2 status'."]));

            return 1;
        }

        if (!$changes) {
            $io->success(sprintf("'Enforce OAuth2' is already off (%s).", implode(', ', $sides)));

            return 0;
        }

        $io->success(sprintf(
            "'Enforce OAuth2' is off (%s%s): logins with a password work again.",
            implode(', ', $sides),
            $env ? ', environment ' . $this->input->getOption('env') : ''
        ));

        return 0;
    }

    /**
     * Why a side isn't turned on: settings it requires off that are on, and why it wouldn't apply.
     */
    protected function reasons(?string $why, ?string $requirements): string
    {
        $reasons = [];
        if ($requirements) {
            $reasons[] = "{$requirements} must be off first, otherwise users can still log in without OAuth2.";
        }
        if ($why) {
            $reasons[] = "{$why}, so it wouldn't apply.";
        }

        return implode(' ', $reasons);
    }

    /**
     * Turns it on in the config file (of the environment with --env), only for sides where it applies, unless --force:
     * a side chosen with --site or --admin is refused then, of both sides only the ones where it applies are turned on.
     * Names the environments whose files override the change.
     *
     * @param string[] $sides
     */
    protected function on(SymfonyStyle $io, Settings $settings, array $sides): int
    {
        $force = (bool)$this->input->getOption('force');
        $values = [];
        $skipped = [];
        $notApplying = [];
        $unmet = [];
        foreach ($sides as $side) {
            $why = $settings->notApplying($side);
            $requirements = $settings->unmetRequirements($side);
            if (($why || $requirements) && !$force) {
                $skipped[$side] = $this->reasons($why, $requirements);
                continue;
            }
            $values[Settings::key($side, 'enforce_oauth2')] = true;
            if ($why) {
                $notApplying[$side] = $why;
            }
            if ($requirements) {
                $unmet[$side] = $requirements;
            }
        }
        // Every side skipped: a side chosen with --site or --admin, or both.
        if (!$values) {
            $refused = [];
            foreach ($skipped as $side => $reasons) {
                $refused[] = ucfirst($side) . ': ' . $reasons;
            }
            $refused[] = 'Nothing changed' . ($this->input->getOption('sign-out') ? ', nobody was signed out' : '')
                . '. Fix that first, or add --force to turn it on anyway'
                . (count($sides) === 1 ? '' : ' (with --site or --admin for one side only)') . '.';
            $io->error($refused);

            return 1;
        }

        $file = $settings->file();
        $on = array_values(array_diff($sides, array_keys($skipped)));
        $change = $settings->save($file, $values, 'turned Enforce OAuth2 on');
        if ($change) {
            $io->text('  ' . $change . ' => on');
        }
        foreach ($skipped as $side => $reasons) {
            $io->note(ucfirst($side) . ': ' . $reasons . ' Left as it is.');
        }
        foreach ($notApplying as $side => $why) {
            $io->warning(ucfirst($side) . ": it's on, but doesn't apply yet: {$why}.");
        }
        foreach ($unmet as $side => $requirements) {
            $io->warning(ucfirst($side) . ": it's on, but users can still log in without OAuth2 while these are on: {$requirements}.");
        }
        if ($overrides = $settings->overrides($file, $values)) {
            $io->warning(array_merge(['These environments override it (change them with --env=<environment>):'], $overrides));
        }

        $applying = array_values(array_diff($on, array_keys($notApplying), array_keys($unmet)));
        $signOut = (bool)$this->input->getOption('sign-out');
        if (!$change) {
            $io->success(sprintf("'Enforce OAuth2' is already on (%s).", implode(', ', $on)));
        } else {
            if ($applying && !$signOut) {
                $io->note("It doesn't sign anyone out: open sessions, 'remember me' cookies and Admin2's tokens keep working until they expire. "
                    . ($this->login() ? 'To end them, add --sign-out.' : "To end them, see 'Enforce OAuth2' in the README."));
            }
            $io->success(sprintf(
                "'Enforce OAuth2' is on (%s)%s. To undo: bin/plugin login-oauth2 enforce-oauth2 off",
                implode(', ', $on),
                $applying ? ': only OAuth2 logins work' . ($applying !== $on ? ' (' . implode(', ', $applying) . ')' : '') . ($overrides ? ', except in the environments above' : '') : ''
            ));
        }

        // Where it applies: elsewhere password logins still work, so signing out wouldn't make anyone use OAuth2.
        return $signOut ? $this->signOut($io, array_values(array_diff($on, array_keys($notApplying)))) : 0;
    }

    /**
     * The Login plugin's service if it can sign accounts out (Login::signOutAccounts(), signOutEveryone(), and
     * getAccounts() to find the ones with admin access), else null.
     *
     * @return Login|null
     */
    protected function login(): ?Login
    {
        $grav = Grav::instance();
        $login = isset($grav['login']) ? $grav['login'] : null;

        return $login instanceof Login && method_exists($login, 'signOutAccounts') && method_exists($login, 'signOutEveryone') && method_exists($login, 'getAccounts') ? $login : null;
    }

    /**
     * --sign-out: signs out the users who could have logged in without OAuth2 on the sides where it's on and applies,
     * with the Login plugin's sign-out: everyone if the site is one of them (also the users without an account file),
     * else only the accounts with admin access (OAuth2::hasAdminAccess()). Their sessions, "remember me" cookies and
     * Admin2 tokens end; API keys keep working. 1 if it could sign out nobody or not everyone.
     *
     * @param string[] $sides
     */
    protected function signOut(SymfonyStyle $io, array $sides): int
    {
        if (!$sides) {
            $io->note("Nobody was signed out: 'Enforce OAuth2' doesn't apply yet, so logins with a password still work.");

            return 0;
        }
        $login = $this->login();
        if (!$login) {
            $grav = Grav::instance();
            $io->error(sprintf(
                "Nobody was signed out: %s. To end open sessions, 'remember me' cookies and Admin2's tokens, see 'Enforce OAuth2' in the README.",
                isset($grav['login']) ? "the Login plugin can't sign users out (it needs Login 3.13.0 or newer)" : "the Login plugin isn't loaded (disabled, or the plugins couldn't be initialized)"
            ));

            return 1;
        }

        $by = 'from the command line: login-oauth2 enforce-oauth2 on --sign-out';
        $everyone = in_array('site', $sides, true);
        if ($everyone) {
            $result = $login->signOutEveryone($by);
        } else {
            $result = $login->signOutAccounts($this->adminAccounts($login), $by);
            foreach ($result['signed_out'] as $username) {
                $io->text(sprintf('  Signed out "%s"', $username));
            }
        }

        $failed = 0;
        foreach ($result['failed'] as $failure) {
            $io->error(sprintf('Could not sign out "%s": %s', $failure['username'], $failure['error']));
            $failed++;
        }
        // Listed with admin access, but gone when Login read it again (e.g. removed meanwhile).
        foreach ($result['missing'] ?? [] as $username) {
            $io->error(sprintf('Could not sign out "%s": the account could not be read anymore.', $username));
            $failed++;
        }
        $count = count($result['signed_out']);
        if ($count) {
            // Signed-in web processes may hold the accounts in Grav's cache (Flex accounts).
            $this->invalidateCache();
        }
        if ($everyone && empty($result['sessions_ended'])) {
            $io->error('The sessions of users without an account file could not be ended: user/data/login/sign-out-all.yaml could not be written (see the Grav log).');
            $failed++;
        }

        if (!$everyone && !$count && !$failed) {
            $io->note('Nobody was signed out: no account has admin access.');

            return 0;
        }
        $message = sprintf(
            '%sSigned %d account%s%s out everywhere%s',
            $everyone && !empty($result['sessions_ended']) ? 'Every session that logged in before now ends on its next request. ' : '',
            $count,
            $count === 1 ? '' : 's',
            $everyone ? '' : ' with admin access',
            $failed ? ', but not everyone (see above).' : ': they log in again with OAuth2.'
        );
        $failed ? $io->warning($message) : $io->success($message);
        $io->note('API keys keep working: revoke them in Admin2 if needed.');

        return $failed ? 1 : 0;
    }

    /**
     * The usernames of the accounts with admin access (OAuth2::hasAdminAccess()), also disabled ones (their cookies and
     * tokens would work again once enabled), from the Login plugin's list of accounts (Login::getAccounts(), which
     * skips account files it can't read).
     *
     * @param Login $login The Login plugin's service (login())
     * @return string[]
     */
    protected function adminAccounts(Login $login): array
    {
        $admins = [];
        foreach ($login->getAccounts() as $account) {
            if (OAuth2::hasAdminAccess($account, true)) {
                $admins[] = (string)$account->get('username');
            }
        }

        return $admins;
    }
}
