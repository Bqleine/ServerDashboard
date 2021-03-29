<?php

declare(strict_types=1);

namespace Baleine\ServerDashboard;

use Baleine\ServerDashboard\commands\TimingsCommand;
use Baleine\ServerDashboard\tasks\CurlTask;
use Baleine\ServerDashboard\tasks\MainStatsTask;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerTransferEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

    /** @var $instance Main */
    public static $instance;

    public static $enabled = true;

    public static $api = "api.serverdashboard.me";

    public static $version = "2.0.3";

    public $enabledWebhooks;

    public $token;

	public function onEnable() : void{
	    $this->token = strval($this->getConfig()->get("token"));
	    if ($this->token === false) {
	        $this->getLogger()->error("Couldn't initialise ServerDashboard : missing token");
	        $this::$enabled = false;
	        return;
        }
	    if (!$this->checkToken($this->token)) {
            $this->getLogger()->error("Couldn't initialise ServerDashboard : wrong token");
            $this::$enabled = false;
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getScheduler()->scheduleRepeatingTask(new MainStatsTask(), 100);

		$commandMap = $this->getServer()->getCommandMap();

		$commandMap->unregister($commandMap->getCommand("timings"));
		$commandMap->register("ServerDashboard", new TimingsCommand("timings"));

		$this->enabledWebhooks = $this->getEnabledWebhooks();

		$this->sendWebhook("enabled");

		self::$instance = $this;
	}

	public function onDataPacketReceive(DataPacketReceiveEvent $event) {
	    if (!self::$enabled) return;

        $packet = $event->getPacket();
	    if ($packet instanceof LoginPacket) {
	        $this->sendPlayerStats($packet->username, $packet->clientData["DeviceOS"]);
	    }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
	    if ($command->getName() === "sd-toggle") {
	        if (count($args) === 0) {
	            // Toggle
                self::$enabled = !self::$enabled;
                $sender->sendMessage(self::$enabled ? TextFormat::GREEN . "Enabled ServerDashboard" : TextFormat::RED . "Disabled ServerDashboard");
            } else {
	            if (strtolower($args[0]) === "on") {
                    self::$enabled = true;
                    $sender->sendMessage(TextFormat::GREEN . "Enabled ServerDashboard");
                } else if (strtolower($args[0]) === "off") {
                    self::$enabled = false;
                    $sender->sendMessage(TextFormat::RED . "Disabled ServerDashboard");
                }
            }
        }
        return true;
    }

    public function getEnabledWebhooks() {
        if (!self::$enabled) return [];

        $defaults = [
            CURLOPT_URL => $this::$api . "/v1/server/webhooks?token=" . $this->token . "&list=true",
            CURLOPT_RETURNTRANSFER => true,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, $defaults);

        return explode(";", curl_exec($ch));
    }

    public function checkToken($token) : bool {
        $defaults = [
            CURLOPT_URL => $this::$api . "/v1/server/check?token=" . $token,
            CURLOPT_RETURNTRANSFER => true,
        ];

        $ch = curl_init();

        curl_setopt_array($ch, $defaults);

        $result = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            return false;
        }

        $json = json_decode($result, true);

        foreach ($json["messages"] as $message) {
            $this->getLogger()->warning($message);
        }

        if (self::$version !== $json["version"]) {
            $this->getLogger()->warning("A new version of ServerDashboard is available. Please download it or updated features may work incorrectly");
        }

        return true;
    }

	public function sendMainStats($playerCount, $tps, $loadedChunks) {
        if (!self::$enabled) return ;

        $params = ["token" => $this->token, "playerCount" => $playerCount, "tps" => $tps, "loadedChunks" => $loadedChunks];

        $defaults = [
            CURLOPT_URL => $this::$api . "/v1/server/main-statistics",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ];

        $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
    }

    public function sendPlayerStats($username, $deviceOS) {
        if (!self::$enabled) return;

        $params = ["token" => $this->token, "username" => $username, "deviceOS" => $deviceOS];

        $defaults = [
            CURLOPT_URL => $this::$api . "/v1/server/player-statistics",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ];

        $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
    }

    public function sendWebhook($trigger, $args="") {
        if (!self::$enabled) return ;

        if (in_array($trigger, $this->enabledWebhooks)) {
            $params = ["token" => $this->token, "trigger" => $trigger];
            if ($args !== "") $params["args"] = $args;
            
            $defaults = [
                CURLOPT_URL => $this::$api . "/v1/server/webhooks?send=true",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
            ];

            $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
        }
    }

    public function onDisable() {
        if (!self::$enabled) return ;

        $this->sendWebhook("disabled");
	}

	public function onLowMemory(LowMemoryEvent $event) {
        if (!self::$enabled) return ;

        $this->sendWebhook("lowMemory");
    }

    public function onPlayerChat(PlayerChatEvent $event) {
        if (!self::$enabled) return ;

        $this->sendWebhook("playerChat", $event->getFormat());
    }

    public function onPlayerJoin(PlayerJoinEvent $event) {
        if (!self::$enabled) return ;

        $this->sendWebhook("playerJoin", $event->getPlayer()->getName());
    }

    public function onPlayerQuit(PlayerQuitEvent $event) {
        if (!self::$enabled) return ;

        $this->sendWebhook("playerQuit", $event->getPlayer()->getName());
    }

    public function onPlayerTransfer(PlayerTransferEvent $event) {
        if (!self::$enabled) return ;

        $this->sendWebhook("playerTransfer", $event->getAddress());
    }
}
