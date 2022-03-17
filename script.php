<?php
require __DIR__ . '/vendor/autoload.php';
require("settings.php");

use Prometheus\CollectorRegistry;
use Prometheus\PushGateway;

date_default_timezone_set("Pacific/Auckland");
$registry = new CollectorRegistry(new Prometheus\Storage\InMemory());
$gauge = $registry->getOrRegisterGauge('', 'brightness', '');
$pushGateway = new PushGateway($pushgatewayHost);

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function numberToString($number) {
    if(!is_numeric($number)) {
        throw new Exception("Brightness value must be numeric.");
    }

    if($number > 1023) {
        throw new Exception("Brightness value must be from 0-1023");
    }

    $msb = $number >> 8;
    $lsb = $number & 0b11111111;
    $binStr = chr($msb) . chr($lsb);

    return $binStr;
}

function stringToNumber($string) {
    if(strlen($string) !== 2) {
        throw new Exception("Invalid brightness string sent from server.");
    }

    $val = ord($string[0]) << 8;
    $val += ord($string[1]);

    return $val;
}

$clientId = 'php-script' . generateRandomString();

$mqtt = new \PhpMqtt\Client\MqttClient($mqttServer, $mqttPort, $clientId);

$connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
    ->setUsername($mqttUsername)
    ->setPassword($mqttPassword);

$desiredBrightness = 1;

function dateChecker($number){
    return $number >= 6 && $number < 22;
}

$mqtt->connect($connectionSettings, true);

$mqtt->subscribe('moss/511987/currentBrightness', function ($topic, $message) {
    global $desiredBrightness;
    global $mqtt;
    global $gauge;
    global $pushGateway;
    global $registry;

    if(dateChecker(intval(date("G"))))
        $desiredBrightness = intval(file_get_contents("https://moss.nightfish.co/getBrightnessMQTT.php"));
    else
        $desiredBrightness = 0;

    $currentBrightness = stringToNumber($message);
    if($currentBrightness !== $desiredBrightness){
        //echo stringToNumber($message);
        $mqtt->publish('moss/511987/setBrightness', numberToString($desiredBrightness), 0);
    }

    try {
	$gauge->set($currentBrightness);
        $pushGateway->push($registry, 'moss', []);
    } catch(Exception $e) {}
}, 0);
$mqtt->loop(true);

$mqtt->disconnect();
?>
