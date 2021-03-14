<?php

declare(strict_types=1);

namespace Baleine\ServerDashboard;

use Baleine\ServerDashboard\commands\TimingsCommand;
use Baleine\ServerDashboard\tasks\CurlTask;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    /** @var $instance Main */
    public static $instance;

    public $enabledWebhooks;

    public $token;

	public function onEnable() : void{
	    $this->token = strval($this->getConfig()->get("token"));
	    if ($this->token === false) {
	        $this->getLogger()->warning("Couldn't initialise ServerDashboard : missing token in config");
	        return;
        }
	    if (!$this->checkToken($this->token)) {
            $this->getLogger()->warning("Couldn't initialise ServerDashboard : wrong token");
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
		    function (int $currentTick) : void{
		        $server = $this->getServer();
		        $playerCount = count($server->getOnlinePlayers());
		        $tps = $server->getTicksPerSecondAverage();
                $loadedChunks = 0;
                foreach ($this->getServer()->getLevels() as $level) {
                    $loadedChunks += count($level->getChunks());
                }
                $this->sendMainStats($playerCount, $tps, $loadedChunks);
            }
        ), 100);

		$commandMap = $this->getServer()->getCommandMap();

		$commandMap->unregister($commandMap->getCommand("timings"));
		$commandMap->register("ServerDashboard", new TimingsCommand("timings"));

		$this->enabledWebhooks = $this->getEnabledWebhooks();

		$this->sendWebhook("enabled");

		self::$instance = $this;
	}

	public function onDataPacketReceive(DataPacketReceiveEvent $event) {
	    $packet = $event->getPacket();
	    if ($packet instanceof LoginPacket) {
	        $this->sendPlayerStats($packet->username, $packet->clientData["DeviceOS"]);
	    }
    }

    public function getEnabledWebhooks() {
        $defaults = [
            CURLOPT_URL => "localhost:3000/api/v1/server/webhooks?token=" . $this->token . "&list=true",
            CURLOPT_RETURNTRANSFER => true,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, $defaults);

        return explode(";", curl_exec($ch));
    }

    public function checkToken($token) : bool {
        $defaults = [
            CURLOPT_URL => "localhost:3000/api/v1/server/check?token=" . $token,
            CURLOPT_RETURNTRANSFER => true,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, $defaults);

        return curl_exec($ch) === "true";
    }

	public function sendMainStats($playerCount, $tps, $loadedChunks) {
        $params = ["token" => $this->token, "playerCount" => $playerCount, "tps" => $tps, "loadedChunks" => $loadedChunks];

        $defaults = [
            CURLOPT_URL => "localhost:3000/api/v1/server/main-statistics",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ];

        $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
    }

    public function sendPlayerStats($username, $deviceOS) {
        $params = ["token" => $this->token, "username" => $username, "deviceOS" => $deviceOS];

        $defaults = [
            CURLOPT_URL => "localhost:3000/api/v1/server/player-statistics",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ];

        $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
    }

    public function sendWebhook($trigger, $args="") {
        if (in_array($trigger, $this->enabledWebhooks)) {
            $params = ["token" => $this->token, "trigger" => $trigger];
            if ($args !== "") $params["args"] = $args;
            
            $defaults = [
                CURLOPT_URL => "localhost:3000/api/v1/server/webhooks?send=true",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
            ];

            $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
        }
    }

    public function onDisable() {
	    $this->sendWebhook("disabled");
	}

	public function onLowMemory(LowMemoryEvent $event) {
	    $this->sendWebhook("lowMemory");
    }

    public function onPlayerChat(PlayerChatEvent $event) {
	    $this->sendWebhook("playerChat", $event->getFormat());
    }
}