<?php
namespace App\Sync\Task;

use App\Entity;
use App\Radio\Adapters;
use App\Settings;
use Azura\Logger;
use Doctrine\ORM\EntityManager;
use studio24\Rotate;
use Supervisor\Supervisor;
use Symfony\Component\Finder\Finder;

class RotateLogs extends AbstractTask
{
    /** @var Adapters */
    protected $adapters;

    /** @var Supervisor */
    protected $supervisor;

    /**
     * @param EntityManager $em
     * @param Entity\Repository\SettingsRepository $settingsRepo
     * @param Adapters $adapters
     * @param Supervisor $supervisor
     */
    public function __construct(
        EntityManager $em,
        Entity\Repository\SettingsRepository $settingsRepo,
        Adapters $adapters,
        Supervisor $supervisor
    ) {
        parent::__construct($em, $settingsRepo);

        $this->adapters = $adapters;
        $this->supervisor = $supervisor;
    }

    public function run($force = false): void
    {
        // Rotate logs for individual stations.
        $station_repo = $this->em->getRepository(Entity\Station::class);

        $stations = $station_repo->findAll();
        if (!empty($stations)) {
            foreach ($stations as $station) {
                /** @var Entity\Station $station */
                Logger::getInstance()->info('Processing logs for station.',
                    ['id' => $station->getId(), 'name' => $station->getName()]);

                $this->rotateStationLogs($station);
            }
        }

        // Rotate the main AzuraCast log.
        $rotate = new Rotate\Rotate(Settings::getInstance()->getTempDirectory() . '/app.log');
        $rotate->keep(5);
        $rotate->size('5MB');
        $rotate->run();

        // Rotate the automated backups.
        $backups_to_keep = (int)$this->settingsRepo->getSetting(Entity\Settings::BACKUP_KEEP_COPIES, 0);

        if ($backups_to_keep > 0) {
            $rotate = new Rotate\Rotate(Backup::BASE_DIR . '/automatic_backup.zip');
            $rotate->keep($backups_to_keep);
            $rotate->run();
        }
    }

    /**
     * Rotate logs that are not automatically rotated (currently Liquidsoap only).
     *
     * @param Entity\Station $station
     *
     */
    public function rotateStationLogs(Entity\Station $station): void
    {
        $this->_cleanUpIcecastLog($station);
    }

    protected function _cleanUpIcecastLog(Entity\Station $station): void
    {
        $config_path = $station->getRadioConfigDir();

        $finder = new Finder();

        $finder
            ->files()
            ->in($config_path)
            ->name('icecast_*.log.*')
            ->date('before 1 month ago');

        foreach ($finder as $file) {
            $file_path = $file->getRealPath();
            @unlink($file_path);
        }
    }
}
