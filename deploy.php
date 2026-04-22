<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/laravel.php';

function deploy_env(string $key, string $default): string
{
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

function deploy_env_nullable(string $key): ?string
{
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : null;
}

set('application', 'zkteco-adms');
set('repository', deploy_env('DEPLOY_REPOSITORY', 'https://github.com/Mazbaul-kamal/ZKTeco_ADMS.git'));
set('branch', deploy_env('DEPLOY_BRANCH', 'master'));
set('keep_releases', (int) deploy_env('DEPLOY_KEEP_RELEASES', '5'));
set('default_timeout', 900);
set('allow_anonymous_stats', false);
set('git_tty', true);
set('ssh_multiplexing', true);
set('bin/php', deploy_env('DEPLOY_PHP_BIN', '/usr/bin/php8.3'));
set('http_user', deploy_env('DEPLOY_HTTP_USER', 'www-data'));
set('composer_options', '--no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader');
set('writable_mode', 'chmod');
set('writable_recursive', true);
set('writable_use_sudo', false);

$stage = deploy_env('DEPLOY_STAGE', 'production');

$production = host($stage)
    ->setHostname(deploy_env('DEPLOY_HOST', 'attendance.khelaghor.store'))
    ->setRemoteUser(deploy_env('DEPLOY_REMOTE_USER', 'deploy'))
    ->setDeployPath(deploy_env('DEPLOY_PATH', '/var/www/attendance.khelaghor.store'))
    ->set('branch', deploy_env('DEPLOY_BRANCH', 'master'))
    ->setForwardAgent(deploy_env('DEPLOY_FORWARD_AGENT', '1') !== '0')
    ->setSshMultiplexing(true)
    ->setLabels([
        'stage' => $stage,
        'application' => get('application'),
    ]);

if ($port = deploy_env_nullable('DEPLOY_PORT')) {
    $production->setPort((int) $port);
}

if ($identityFile = deploy_env_nullable('DEPLOY_IDENTITY_FILE')) {
    $production->setIdentityFile($identityFile);
}

desc('Restart Laravel queue workers');
task('deploy:queue_restart', artisan('queue:restart', ['skipIfNoEnv']));

after('deploy:success', 'deploy:queue_restart');
after('deploy:failed', 'deploy:unlock');
