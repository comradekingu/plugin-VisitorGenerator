<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorGenerator\Commands;

use Piwik\Date;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\VisitorGenerator\Generator\LiveVisitsFromLog;
use Piwik\Site;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateLiveVisits extends ConsoleCommand
{
    /**
     * @var int|null
     */
    private $timeout;

    protected function configure()
    {
        $this->setName('visitorgenerator:generate-live-visits');
        $this->setDescription('Continuously generates visits from a single log file to make it seem like there is real time traffic.');
        $this->addOption('idsite', null, InputOption::VALUE_REQUIRED, '(required) The ID of the site to track to.');
        $this->addOption('stop-after', null, InputOption::VALUE_REQUIRED, 'If supplied, the command will exit after this many seconds.');
        $this->addOption('log-file', null, InputOption::VALUE_REQUIRED,
            '(required) The log file to track visits from. This file MUST have visits in order of time.');
        $this->addOption('day-of-month', null, InputOption::VALUE_REQUIRED,
            'By default this command starts with visits for the current day of the month. Use this option to force an override. '
            . 'Specify 0 to ignore day of the month and use every log.',
            Date::now()->toString('j'));
        $this->addOption('time-of-day', null, InputOption::VALUE_REQUIRED,
            'The time of day to start replaying logs for. Defaults to now, specify a value here to override.',
            time() % LiveVisitsFromLog::SECONDS_IN_DAY);
        $this->addOption('custom-matomo-url', null, InputOption::VALUE_REQUIRED, 'Custom Matomo URL to track to.');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, "Sets how long, in seconds, the timeout should be for the request.", 10);
        $this->addOption('token-auth', null, InputOption::VALUE_REQUIRED, 'Use custom token auth instead of system generated one. If running this '
            . 'command continuously or a cron, this should be used, since the generated token auth will expire after 24 hours.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $piwikUrl = $input->getOption('custom-matomo-url');
        $idSite = $this->getSite($input);
        $stopAfter = $this->getPostiveIntegerOption($input, 'stop-after');
        $logFile = $this->getLogFile($input);
        $dayOfMonth = $this->getPostiveIntegerOption($input, 'day-of-month');
        $timeOfDay = $this->getPostiveIntegerOption($input, 'time-of-day');
        $this->timeout = $this->getPostiveIntegerOption($input, 'timeout');
        $tokenAuth = $input->getOption('token-auth');

        $timeOfDayDelta = $stopAfter ?: LiveVisitsFromLog::SECONDS_IN_DAY;

        $generateLiveVisits = new LiveVisitsFromLog($logFile, $idSite, $timeOfDay, $timeOfDayDelta, $dayOfMonth, $piwikUrl, $this->timeout);
        if (!empty($tokenAuth)) {
            $generateLiveVisits->setTokenAuth($tokenAuth);
        }

        $output->writeln("Generating logs...");

        $startTime = time();
        while (true) {
            list($count, $nextWaitTime) = $generateLiveVisits->tick();
            if ($count === null) {
                $output->writeln("Found no logs to track for day of month / time of day, exiting.");
                return 0;
            }

            $output->writeln("  tracked $count actions.");

            if ($nextWaitTime === null) {
                $output->writeln("Out of logs, exiting.");
                return 0;
            }

            // nextWaitTime can be large if the next visit happens in an hour. no sense in
            // waiting if it'll be after stopAfter
            if ($stopAfter > 0
                && (time() + $nextWaitTime) - $startTime > $stopAfter
            ) {
                $output->writeln("$stopAfter seconds reached, exiting.");
                return 0;
            }

            $output->writeln("  sleeping {$nextWaitTime}s.");
            sleep($nextWaitTime);

            if ($stopAfter > 0
                && time() - $startTime > $stopAfter
            ) {
                $output->writeln("$stopAfter seconds reached, exiting.");
                return 0;
            }
        }

        return 0; // should never occur
    }

    private function getPostiveIntegerOption(InputInterface $input, $optionName)
    {
        $value = $input->getOption($optionName);
        if (!empty($value)
            && (!is_numeric($value)
                || $value < 0)
        ) {
            throw new \Exception("Invalid value for --$optionName option, if supplied, must be positive integer.");
        }
        return (int) $value;
    }

    private function getLogFile(InputInterface $input)
    {
        $path = $input->getOption('log-file');
        if (empty($path)) {
            throw new \Exception("The --log-file option is required.");
        }

        if (!file_exists($path)) {
            throw new \Exception("The '$path' file does not exist.");
        }
        return $path;
    }

    private function getSite(InputInterface $input)
    {
        $idSite = $input->getoption('idsite');
        new Site($idSite); // check it's valid
        return $idSite;
    }
}
