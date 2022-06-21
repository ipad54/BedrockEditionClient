### Creating a simple client that writes something to the chat after joining to the server

```php
<?php

use ipad54\BedrockEditionClient\address\ServerAddress;
use ipad54\BedrockEditionClient\Client;
use ipad54\BedrockEditionClient\player\LoginInfo;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\TextPacket;

require_once "vendor/autoload.php";

$client = new Client(ServerAddress::create("127.0.0.1", 19132), new LoginInfo("Shoghicp"));
$client->handleDataPacket(function(Packet $packet) use($client) : void{
	if($packet instanceof PlayStatusPacket && $packet->status === PlayStatusPacket::PLAYER_SPAWN){
		$pk = new TextPacket();
		$pk->message = "A Shoghi has fallen into the river in Lego Mojang";
		$pk->type = TextPacket::TYPE_CHAT;
		$pk->sourceName = $client->getLoginInfo()->getUsername();

		$client->getNetworkSession()->sendDataPacket($pk);
	}
});

$client->connect();

while(true){
	$client->update();
}
```

![Screenshot_180](https://user-images.githubusercontent.com/63200545/174803523-0281e5c8-dc2b-414e-a524-b761754f7957.png)


### Creating spam bots

```php
<?php

use ipad54\BedrockEditionClient\address\ServerAddress;
use ipad54\BedrockEditionClient\Client;
use ipad54\BedrockEditionClient\player\LoginInfo;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\TextPacket;

require_once "vendor/autoload.php";

/** @var Client[] $clients */
$clients = [];

for($i = 0; $i <= 50; $i++){
	$client = new Client(ServerAddress::create("127.0.0.1", 19132), new LoginInfo("PMMP" . mt_rand(1, 10000)));

	$client->handleDataPacket(function(Packet $packet) use ($client) : void{
		if($packet instanceof DisconnectPacket){
			$client->getLogger()->warning($client->getLoginInfo()->getUsername() . ": disconnected from server, reason " . $packet->message);
		}elseif($packet instanceof PlayStatusPacket && $packet->status === PlayStatusPacket::PLAYER_SPAWN){
			sendSpam($client);
		}
	});

	$clients[] = $client;
}

$lastConnect = time();
/** @var array<int, int> $lastSpam */
$lastSpam = [];

while(true){
	foreach($clients as $client){
		if(!$client->isConnected()){
			if(time() - $lastConnect > 2){
				$lastConnect = time();
				$client->connect();

				$client->getLogger()->info($client->getLoginInfo()->getUsername() . " connected");
			}
		}else{
			$client->update();

			if($client->getNetworkSession()->getPlayer()?->isSpawned()){
				$time = $lastSpam[$client->getId()] ?? time() - 1;

				if(time() - $time >= 1){
					$lastSpam[$client->getId()] = time();
					sendSpam($client);
				}
			}
		}
	}
}

function sendSpam(Client $client) : void{
	$pk = new TextPacket();
	$pk->type = TextPacket::TYPE_CHAT;
	$pk->sourceName = $client->getLoginInfo()->getUsername();
	$pk->message = "test.pmmp.io is the best server in minecraft";

	$client->getNetworkSession()->sendDataPacket($pk);
}
```

![Screenshot_181](https://user-images.githubusercontent.com/63200545/174803741-96e8c519-397d-46d8-8b28-8b49d4f74bb8.png)
