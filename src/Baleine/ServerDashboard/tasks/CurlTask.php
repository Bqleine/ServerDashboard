<?php

namespace Baleine\ServerDashboard\tasks;

use pocketmine\scheduler\AsyncTask;
use Volatile;

class CurlTask extends AsyncTask {

    /** @var Volatile $defaults */
    private $defaults;

    /**
     * CurlTask constructor.
     * @param $defaults
     */
    public function __construct(array $defaults) {
        $this->defaults = $defaults;
    }


    public function onRun() {
        $ch = curl_init();
        
        curl_setopt_array($ch, (array) $this->defaults);

        return curl_exec($ch);
    }
}