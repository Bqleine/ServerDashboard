<?php


namespace Baleine\ServerDashboard\tasks;


use Baleine\ServerDashboard\Main;
use pocketmine\scheduler\Task;

class MainStatsTask extends Task {

    public function onRun($currentTick) : void{
        $server = Main::$instance->getServer();
        $playerCount = count($server->getOnlinePlayers());
        $tps = $server->getTicksPerSecondAverage();
        $loadedChunks = 0;
        foreach ($server->getLevels() as $level) {
            $loadedChunks += count($level->getChunks());
        }
        Main::$instance->sendMainStats($playerCount, $tps, $loadedChunks);
    }
}