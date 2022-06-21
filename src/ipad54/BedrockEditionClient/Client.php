<?php

namespace ipad54\BedrockEditionClient;

use ipad54\BedrockEditionClient\player\LoginInfo;
use ipad54\BedrockEditionClient\network\NetworkSession;
use ipad54\BedrockEditionClient\utils\Logger;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\utils\Timezone;
use pocketmine\utils\Utils;
use raklib\utils\InternetAddress;

class Client{

	private ?NetworkSession $networkSession = null;

	private \Logger $logger;

	private int $id;

	private array $dataPacketHandlers = [];

	public function __construct(private InternetAddress $serverAddress, private LoginInfo $loginInfo, bool $logDebug = false, ?int $clientId = null){
		Timezone::init();

		$this->logger = new Logger(true, new \DateTimeZone(Timezone::get()), $logDebug);
		$this->id = $clientId ?? rand();
	}

	public function getServerAddress() : InternetAddress{
		return $this->serverAddress;
	}

	public function getLoginInfo() : LoginInfo{
		return $this->loginInfo;
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	public function getNetworkSession() : ?NetworkSession{
		return $this->networkSession;
	}

	public function getId() : int{
		return $this->id;
	}

	public function isConnected() : bool{
		return $this->networkSession !== null;
	}

	public function update() : void{
		if($this->isConnected()){
			$this->networkSession->update();
		}
	}

	public function handleDataPacket(\Closure $callable) : void{
		Utils::validateCallableSignature(function(Packet $packet) : void{ }, $callable);
		$this->dataPacketHandlers[] = $callable;
	}

	public function getDataPacketHandlers() : array{
		return $this->dataPacketHandlers;
	}

	public function connect() : void{
		if($this->isConnected()){
			throw new \LogicException("Client is already connected!");
		}

		$this->networkSession = new NetworkSession($this->serverAddress, $this->loginInfo, $this);
		$this->networkSession->actuallyConnect();
	}
}