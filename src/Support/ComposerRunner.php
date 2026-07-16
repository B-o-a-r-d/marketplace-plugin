<?php

namespace Board\Marketplace\Support;

use Board\Marketplace\PluginInstallException;
use Symfony\Component\Process\Process;

/**
 * Runs the composer binary inside the plugins project, hardened for untrusted
 * packages: scripts and composer-plugins are disabled (the install step is
 * inert — plugin code only ever executes when the PluginLoader boots it), the
 * process gets its own COMPOSER_HOME on the persistent volume, and a hard
 * timeout keeps a wedged resolution from starving PHP-FPM.
 */
class ComposerRunner
{
    private const TIMEOUT_SECONDS = 240;

    /**
     * @param  array<int, string>  $arguments  e.g. ['require', 'board/plugin-gitlab']
     *
     * @throws PluginInstallException when composer exits non-zero
     */
    public function run(array $arguments, string $workingDirectory): string
    {
        $home = $workingDirectory.'/.composer';

        $process = new Process(
            [$this->binary(), ...$arguments, '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'],
            $workingDirectory,
            [
                'COMPOSER_HOME' => $home,
                'COMPOSER_CACHE_DIR' => $home.'/cache',
                'HOME' => $home,
                'COMPOSER_MEMORY_LIMIT' => '512M',
            ],
            null,
            self::TIMEOUT_SECONDS,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            // Composer writes diagnostics to stderr; keep it short but useful.
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new PluginInstallException(
                __('Échec de composer :output', ['output' => mb_substr($output, -600)]),
            );
        }

        return $process->getOutput();
    }

    private function binary(): string
    {
        return (string) config('board-marketplace.composer_binary', 'composer');
    }
}
