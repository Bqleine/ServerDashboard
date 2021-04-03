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

    /**
     * Is the plugin enabled ?
     * @var bool
     */
    public static $enabled = true;

    /**
     * Api URL
     * @var string
     */
    public static $api = "api.serverdashboard.me";

    /**
     * This plugin version (same as in plugin.yml)
     * @var string
     */
    public static $version = "2.1.0";

    /**
     * List of enabled webhooks queried from the website in {@link getEnabledWebhooks() getEnabledWebhooks()}
     * @var
     */
    public $enabledWebhooks;

    /**
     * Access token
     * @var
     */
    public $token;

    public function onEnable() : void{
        $config = $this->getConfig();
	    $this->token = strval($config->get("token"));
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

	    self::$api = $config->get("api-url", "https://api.serverdashboard.me");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->getScheduler()->scheduleRepeatingTask(new MainStatsTask(), 100);

		$commandMap = $this->getServer()->getCommandMap();

		$commandMap->unregister($commandMap->getCommand("timings"));
		$commandMap->register("ServerDashboard", new TimingsCommand("timings"));

		$this->enabledWebhooks = $this->getEnabledWebhooks();

		$this->sendWebhook("enabled");

		self::$instance = $this;
	}

    /**
     * Used to get the user's operating system
     * @param DataPacketReceiveEvent $event
     */
    public function onDataPacketReceive(DataPacketReceiveEvent $event) {
	    if (!self::$enabled) return;

        $packet = $event->getPacket();
	    if ($packet instanceof LoginPacket) {
	        $this->sendPlayerStats($packet->username, $packet->clientData["DeviceOS"]);
	    }
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
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

    /**
     * Queries enabled webhooks from the api
     * @return array|false|string[]
     */
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

    /**
     * Checks if the token is correct from the api
     * @param $token
     * @return bool
     */
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
            $this->getLogger()->warning("ServerDashboard version " . $json["version"] . " is available. Please download it or some features may work incorrectly");
        }

        return true;
    }

    /**
     * Sends server stats to api (mainly called by {@link MainStatsTask MainStatsTask})
     * @param $playerCount
     * @param $tps
     * @param $loadedChunks
     */
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

    /**
     * Sends a player's stats (called on login) <b>Player is anonymised with {@link password_hash() password_hash}</b>
     * @param $username
     * @param $deviceOS
     */
    public function sendPlayerStats($username, $deviceOS) {
        if (!self::$enabled) return;

        $params = ["token" => $this->token, "username" => hash("md5", $username), "deviceOS" => $deviceOS];

        $defaults = [
            CURLOPT_URL => $this::$api . "/v1/server/player-statistics",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
        ];

        $this->getServer()->getAsyncPool()->submitTask(new CurlTask($defaults));
    }

    /**
     * Sends webhook to api if enabled
     * @param $trigger
     * @param string $args
     */
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
